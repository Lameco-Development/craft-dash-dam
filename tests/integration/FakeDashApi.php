<?php

namespace lameco\dash\tests\integration;

use lameco\dash\errors\DashApiException;
use lameco\dash\services\DashApi;
use RuntimeException;

/**
 * An in-memory Dash tenant behind the real DashApi surface.
 *
 * The fake sits at the transport seam — get(), post() and fetch() — so everything above
 * it runs for real: pagination in allAssets(), the bare-array quirk of /fields, the
 * recursive PARENT_ID walks of folderPaths(). Tests mutate the tenant through the helper
 * methods and reconcile again, which is exactly the lifecycle a cron tick sees.
 */
final class FakeDashApi extends DashApi
{
    public const FIELD_TITLE = 'f-titel';
    public const FIELD_ALT = 'f-alt';
    public const FIELD_FOLDERS = 'f-folders';

    public int $fetchCalls = 0;

    /** @var array<string, mixed> */
    private array $tenant = [];

    /** @var array<string, array<string, mixed>> removed assets, kept for restore() */
    private array $removed = [];

    public static function withDefaultTenant(): self
    {
        $path = dirname(__DIR__) . '/fixtures/dash-tenant.json';
        $tenant = json_decode((string)file_get_contents($path), true);

        if (!is_array($tenant)) {
            throw new RuntimeException("Could not parse the Dash tenant fixture at {$path}");
        }

        $api = new self();
        $api->tenant = $tenant;

        return $api;
    }


    public function get(string $path): array
    {
        return match ($path) {
            '/folder-settings' => ['result' => ['fieldId' => self::FIELD_FOLDERS]],
            // Unlike every other endpoint the real /fields answers with a bare array; the
            // fake mirrors that so the envelope-unwrapping in fields() stays under test.
            '/fields' => $this->tenant['fields'],
            default => throw new DashApiException("FakeDashApi has no handler for GET {$path}"),
        };
    }

    public function post(string $path, array $payload): array
    {
        return match ($path) {
            '/asset-searches' => $this->handleAssetSearch($payload),
            '/field-option-searches' => $this->handleFolderOptionSearch($payload),
            default => throw new DashApiException("FakeDashApi has no handler for POST {$path}"),
        };
    }

    public function fetch(string $url): string
    {
        $this->fetchCalls++;

        return self::bodyFor($url);
    }

    /** Exposed so a test can state what the checksum of the served bytes ought to be. */
    public static function bodyFor(string $url): string
    {
        return "fake-bytes-for-{$url}";
    }


    public function remove(string $dashId): void
    {
        $this->removed[$dashId] = $this->takeAsset($dashId);
    }

    public function restore(string $dashId): void
    {
        if (!isset($this->removed[$dashId])) {
            throw new RuntimeException("Asset {$dashId} was never removed");
        }

        $this->tenant['assets'][] = $this->removed[$dashId];
        unset($this->removed[$dashId]);
    }

    /**
     * @param string[] $folderOptionIds
     */
    public function moveToFolders(string $dashId, array $folderOptionIds): void
    {
        $this->setMetadata($dashId, self::FIELD_FOLDERS, $folderOptionIds);
    }

    public function retitle(string $dashId, string $title): void
    {
        $this->setMetadata($dashId, self::FIELD_TITLE, [$title]);
    }

    public function setAlt(string $dashId, ?string $alt): void
    {
        $this->setMetadata($dashId, self::FIELD_ALT, $alt === null ? [] : [$alt]);
    }

    /**
     * A same-name version replacement: the path stays identical and only the file's
     * facts change, which is the case nothing but the checksum can detect.
     */
    public function replaceFile(string $dashId, string $checksum, ?int $size = null): void
    {
        $asset = &$this->findAsset($dashId);
        $asset['currentAssetFile']['checksum'] = $checksum;

        if ($size !== null) {
            $asset['currentAssetFile']['size'] = $size;
        }
    }

    /**
     * @param array<string, mixed> $wireAsset an asset in Dash's search-result shape
     */
    public function addAsset(array $wireAsset): void
    {
        $this->tenant['assets'][] = $wireAsset;
    }

    public function dropField(string $name): void
    {
        $this->tenant['fields'] = array_values(array_filter(
            $this->tenant['fields'],
            static fn(array $field) => strcasecmp($field['name'], $name) !== 0,
        ));
    }


    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleAssetSearch(array $payload): array
    {
        $matching = array_values(array_filter(
            $this->tenant['assets'],
            fn(array $asset) => $this->matches($payload['criterion'], $asset),
        ));

        return [
            'totalResults' => count($matching),
            'results' => array_map(
                static fn(array $asset) => ['result' => $asset],
                array_slice($matching, $payload['from'], $payload['pageSize']),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $criterion
     * @param array<string, mixed> $asset
     */
    private function matches(array $criterion, array $asset): bool
    {
        if ($criterion['type'] === 'MATCH_ALL') {
            return true;
        }

        if ($criterion['type'] === 'FIELD_MATCHES'
            && ($criterion['field']['fieldName'] ?? null) === 'DATE_LAST_MODIFIED'
            && preg_match('/^\[(.+) TO \*\]$/', $criterion['value'], $window)
        ) {
            return strtotime($asset['dateLastModified']) >= strtotime($window[1]);
        }

        throw new DashApiException('FakeDashApi cannot evaluate criterion: ' . json_encode($criterion));
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function handleFolderOptionSearch(array $payload): array
    {
        $parentId = null;

        foreach ($payload['criterion']['criteria'] as $criterion) {
            if ($criterion['type'] === 'FIELD_EQUALS' && $criterion['field'] === 'FIELD_ID'
                && $criterion['value'] !== self::FIELD_FOLDERS
            ) {
                throw new DashApiException("Unknown folder field '{$criterion['value']}'");
            }

            if ($criterion['field'] === 'PARENT_ID') {
                $parentId = $criterion['type'] === 'FIELD_IS_EMPTY' ? null : $criterion['value'];
            }
        }

        $children = array_values(array_filter(
            $this->tenant['folderOptions'],
            static fn(array $option) => $option['parent'] === $parentId,
        ));

        return [
            'totalResults' => count($children),
            'results' => array_map(fn(array $option) => ['result' => [
                'id' => $option['id'],
                'value' => $option['value'],
                'numberOfChildren' => count(array_filter(
                    $this->tenant['folderOptions'],
                    static fn(array $candidate) => $candidate['parent'] === $option['id'],
                )),
            ]], $children),
        ];
    }

    /**
     * @param mixed[] $values
     */
    private function setMetadata(string $dashId, string $fieldId, array $values): void
    {
        $asset = &$this->findAsset($dashId);
        $asset['metadata']['values'][$fieldId] = $values;
        // Any metadata edit gets a current stamp in Dash, which is what the probe's date
        // window keys on.
        $asset['dateLastModified'] = gmdate('Y-m-d\TH:i:s\Z');
    }

    /**
     * @return array<string, mixed>
     */
    private function &findAsset(string $dashId): array
    {
        foreach ($this->tenant['assets'] as &$asset) {
            if ($asset['id'] === $dashId) {
                return $asset;
            }
        }

        throw new RuntimeException("No asset {$dashId} in the fake tenant");
    }

    /**
     * @return array<string, mixed>
     */
    private function takeAsset(string $dashId): array
    {
        foreach ($this->tenant['assets'] as $index => $asset) {
            if ($asset['id'] === $dashId) {
                array_splice($this->tenant['assets'], $index, 1);

                return $asset;
            }
        }

        throw new RuntimeException("No asset {$dashId} in the fake tenant");
    }
}
