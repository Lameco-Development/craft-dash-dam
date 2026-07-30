<?php

namespace lameco\dash\services;

use Craft;
use craft\helpers\App;
use Generator;
use GuzzleHttp\Client;
use lameco\dash\errors\DashApiException;
use yii\base\Component;

/**
 * Authenticated access to the Dash (dash.app) REST API.
 *
 * Dash supports neither the client-credentials nor the password grant, so the stored
 * refresh token is traded for an access token per process. Dash does not rotate refresh
 * tokens — the refresh response carries a new access token and nothing else — so the
 * stored secret is write-once and there is no write path to maintain.
 *
 * Access tokens live 30 days, which means a revoked refresh token breaks nothing at all
 * until it breaks everything at once. Every failure here therefore throws rather than
 * degrading quietly; see DashSync for why an empty result set is the dangerous outcome.
 */
class DashApi extends Component
{
    public const TOKEN_URL = 'https://login.dash.app/oauth/token';
    public const AUTHORIZE_URL = 'https://login.dash.app/authorize';
    public const AUDIENCE = 'https://assetplatform.io';
    public const API_BASE = 'https://api-v2.dash.app';

    /**
     * Where Dash sends the browser back to after authorising. Nothing serves this route —
     * it only has to exactly match one of the URLs registered on the Dash API client.
     * Dash's identity layer is Auth0 (see the `auth0|...` id on GET /current-user), which
     * matches callback URLs exactly, path included, not by origin — so this is deliberately
     * the bare per-environment origin with no path, matching what's registered on Dash's
     * side for each of local/staging/production. Override with DASH_REDIRECT_URI.
     */
    public const DEFAULT_REDIRECT_URI = 'https://fivoor-website.test';

    private ?Client $client = null;
    private ?string $accessToken = null;

    public function get(string $path): array
    {
        return $this->request('GET', $path);
    }

    public function post(string $path, array $payload): array
    {
        return $this->request('POST', $path, $payload);
    }

    /**
     * `criterion` and `sorts` are both mandatory even when empty; omitting either
     * returns "400 Failed to read request", which reads like a permissions problem.
     */
    public function assetSearch(array $criterion, int $from = 0, int $pageSize = 1000, array $sorts = []): array
    {
        return $this->post('/asset-searches', [
            'from' => $from,
            'pageSize' => $pageSize,
            'criterion' => $criterion,
            'sorts' => $sorts,
        ]);
    }

    /**
     * Callers must never hand-roll this: a single `assetSearch()` silently caps at one
     * page, which reads as "the library only has 1000 assets" rather than as an error.
     *
     * @return Generator<array>
     */
    public function allAssets(int $pageSize = 1000): Generator
    {
        $from = 0;

        do {
            $page = $this->assetSearch(['type' => 'MATCH_ALL'], $from, $pageSize);
            $rows = $page['results'] ?? [];
            $total = (int)($page['totalResults'] ?? 0);

            foreach ($rows as $row) {
                yield $row['result'] ?? $row;
            }

            $from += $pageSize;
        } while ($from < $total && $rows !== []);
    }

    /**
     * A `pageSize: 0` search answers with `totalResults` alone — 49 bytes on the wire
     * regardless of library size, which is what makes polling for changes affordable.
     */
    public function countAssets(array $criterion): int
    {
        return (int)($this->assetSearch($criterion, 0, 0)['totalResults'] ?? 0);
    }

    public function folderFieldId(): string
    {
        $fieldId = $this->get('/folder-settings')['result']['fieldId'] ?? null;

        if ($fieldId === null) {
            throw new DashApiException('Could not determine the Dash Folders field id');
        }

        return (string)$fieldId;
    }

    /**
     * @return array<string, string> lower-cased name => field id
     */
    public function fields(): array
    {
        $fields = [];

        // Unlike every other endpoint, /fields answers with a bare array rather than a
        // {result: …} envelope. Unwrapping it wrongly yields an empty map, not an error.
        foreach ($this->get('/fields') as $row) {
            $field = $row['result'] ?? $row;
            $fields[strtolower(trim((string)($field['name'] ?? '')))] = $field['id'];
        }

        return $fields;
    }

    /**
     * Dash folders are FieldOptions on a hierarchical, multiValue field — not paths.
     * Rebuilding the tree needs one PARENT_ID search per non-leaf node, because the flat
     * FIELD_ID search reports every option with a null `parent`.
     *
     * @return array<string, string> option id => full path
     */
    public function folderPaths(?string $fieldId = null): array
    {
        $fieldId ??= $this->folderFieldId();
        $paths = [];

        $descend = function(?string $parentId, string $prefix) use (&$descend, $fieldId, &$paths): void {
            $criteria = [['type' => 'FIELD_EQUALS', 'field' => 'FIELD_ID', 'value' => $fieldId]];
            $criteria[] = $parentId === null
                ? ['type' => 'FIELD_IS_EMPTY', 'field' => 'PARENT_ID']
                : ['type' => 'FIELD_EQUALS', 'field' => 'PARENT_ID', 'value' => $parentId];

            $response = $this->post('/field-option-searches', [
                'from' => 0,
                'pageSize' => 200,
                'criterion' => ['type' => 'AND', 'criteria' => $criteria],
                'sorts' => [['field' => 'POSITION', 'order' => 'ASC']],
            ]);

            foreach ($response['results'] ?? [] as $row) {
                $option = $row['result'] ?? $row;
                $segment = self::sanitiseSegment((string)($option['value'] ?? ''));
                $path = $prefix === '' ? $segment : "{$prefix}/{$segment}";
                $paths[$option['id']] = $path;

                if (($option['numberOfChildren'] ?? 0) > 0) {
                    $descend($option['id'], $path);
                }
            }
        };

        $descend(null, '');

        return $paths;
    }

    public function authorizeUrl(): string
    {
        return self::AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $this->env('DASH_CLIENT_ID', 'copy it from Admin → Integrations → REST API'),
            'redirect_uri' => $this->redirectUri(),
            'audience' => self::AUDIENCE,
            // offline_access is what makes Dash return a refresh token at all.
            'scope' => 'subdomain:' . $this->env('DASH_SUBDOMAIN', 'the tenant part of your Dash URL, e.g. "fivoor" from fivoor.dash.app')
                . ' offline_access',
        ]);
    }

    public function exchangeAuthorizationCode(string $code): array
    {
        $response = $this->client()->post(self::TOKEN_URL, [
            'json' => [
                'grant_type' => 'authorization_code',
                'client_id' => $this->env('DASH_CLIENT_ID', 'copy it from Admin → Integrations → REST API'),
                'client_secret' => $this->env('DASH_CLIENT_SECRET', 'copy it from Admin → Integrations → REST API'),
                'code' => $code,
                'redirect_uri' => $this->redirectUri(),
            ],
        ]);

        $tokens = json_decode((string)$response->getBody(), true);

        if (!is_array($tokens)) {
            throw new DashApiException("Expected JSON from the token endpoint, got: {$response->getBody()}");
        }

        if ($response->getStatusCode() !== 200) {
            throw new DashApiException(sprintf(
                "Token exchange failed (HTTP %d):\n%s\n\nAuthorization codes are single-use and short-lived — if you already ran this, start over.",
                $response->getStatusCode(),
                json_encode($tokens, JSON_PRETTY_PRINT),
            ));
        }

        if (!isset($tokens['refresh_token'])) {
            throw new DashApiException(sprintf(
                "Got an access token but NO refresh token, which means the offline_access scope wasn't granted.\nScopes returned: %s",
                $tokens['scope'] ?? '(none reported)',
            ));
        }

        return $tokens;
    }

    public function redirectUri(): string
    {
        return (string)App::env('DASH_REDIRECT_URI') ?: self::DEFAULT_REDIRECT_URI;
    }

    /**
     * Fetch a URL Dash handed us — a preview or download link, which is pre-signed and
     * must not carry the Authorization header. These 302 before serving.
     */
    public function fetch(string $url): string
    {
        $response = $this->client()->get($url, ['allow_redirects' => true]);

        if ($response->getStatusCode() !== 200) {
            throw new DashApiException("Dash returned HTTP {$response->getStatusCode()} for {$url}");
        }

        return (string)$response->getBody();
    }

    /**
     * Turn a Dash folder name into a path segment.
     *
     * Leading underscores make a directory unindexable — AssetIndexer::indexFileByEntry()
     * throws AssetNotIndexableException for any segment starting with one.
     */
    public static function sanitiseSegment(string $name): string
    {
        $name = ltrim(str_replace(['/', '\\'], '-', trim($name)), '_');

        return $name === '' ? 'Untitled' : $name;
    }

    private function request(string $method, string $path, ?array $payload = null): array
    {
        $options = ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken()]];

        if ($payload !== null) {
            $options['json'] = $payload;
        }

        $response = $this->client()->request($method, self::API_BASE . $path, $options);
        $body = (string)$response->getBody();

        if ($response->getStatusCode() !== 200) {
            throw new DashApiException(sprintf(
                'Dash %s %s failed (HTTP %d): %s',
                $method,
                $path,
                $response->getStatusCode(),
                $body,
            ));
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new DashApiException("Expected JSON from Dash {$method} {$path}, got: {$body}");
        }

        return $decoded;
    }

    private function accessToken(): string
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $response = $this->client()->post(self::TOKEN_URL, [
            'json' => [
                'grant_type' => 'refresh_token',
                'client_id' => $this->env('DASH_CLIENT_ID', 'see Admin → Integrations → REST API'),
                'client_secret' => $this->env('DASH_CLIENT_SECRET', 'see Admin → Integrations → REST API'),
                'refresh_token' => $this->env('DASH_REFRESH_TOKEN', 'run `php craft dash/auth` first'),
            ],
        ]);

        $payload = json_decode((string)$response->getBody(), true);

        if ($response->getStatusCode() !== 200 || !isset($payload['access_token'])) {
            throw new DashApiException(sprintf(
                'Dash token refresh failed (HTTP %d): %s',
                $response->getStatusCode(),
                (string)$response->getBody(),
            ));
        }

        return $this->accessToken = $payload['access_token'];
    }

    private function env(string $key, string $hint): string
    {
        $value = (string)App::env($key);

        if ($value === '') {
            throw new DashApiException("Missing {$key} in .env — {$hint}");
        }

        return $value;
    }

    private function client(): Client
    {
        return $this->client ??= Craft::createGuzzleClient([
            'http_errors' => false,
            'timeout' => 120,
        ]);
    }
}
