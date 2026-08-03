<?php

/**
 * A read-only Craft filesystem backed by the Dash DAM.
 *
 * Craft 5 has no first-class read-only concept, so the eight mutating methods of
 * BaseFsInterface throw and the volume's permissions are expected to withhold
 * `saveAssets` as well.
 *
 * Serving files is all this does. Asset elements are created and kept in step by
 * DashSync, which reconciles on the Dash UUID — Craft's AssetIndexer must never run
 * against this volume.
 */

namespace lameco\dash\fs;

use Craft;
use craft\base\Fs;
use craft\errors\FsException;
use craft\models\FsListing;
use Generator;
use lameco\dash\helpers\CanonicalFolder;
use lameco\dash\Plugin;
use lameco\dash\services\DashApi;

class DashFs extends Fs
{
    private const CACHE_KEY = 'dashFs.listing';
    private const CACHE_TTL = 300;

    /** @var array{files: array<string, array>, folders: array<string, true>}|null */
    private ?array $index = null;

    public static function displayName(): string
    {
        return 'Dash';
    }

    /**
     * Drop the cached listing. Needed after anything that makes it describe a library this
     * environment no longer talks to — pointing at a different Dash tenant, most obviously.
     */
    public static function clearCache(): void
    {
        Craft::$app->getCache()->delete(self::CACHE_KEY);
    }

    public function getShowHasUrlSetting(): bool
    {
        // Public URLs would need Dash embeddable links — a possible future
        // feature, out of scope for v1. Craft generates transforms from bytes
        // instead.
        return false;
    }

    public function getShowUrlSetting(): bool
    {
        return false;
    }

    public function getRootUrl(): ?string
    {
        return null;
    }

    /**
     * Dash keys assets by UUID and permits duplicate filenames in a folder; Craft keys
     * by path and cannot. So the Dash id is folded into the filename to guarantee a
     * unique, stable path.
     *
     * The suffix must be derived from the id and nothing else — an order-dependent
     * scheme like "file(2).jpg" would shift whenever assets are added or removed,
     * changing paths and re-orphaning every reference.
     */
    public static function craftFilename(string $filename, string $dashId): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $base = pathinfo($filename, PATHINFO_FILENAME);
        $suffix = substr(str_replace('-', '', $dashId), 0, 8);

        return $extension === '' ? "{$base}~{$suffix}" : "{$base}~{$suffix}.{$extension}";
    }

    /**
     * @return array{files: array<string, array>, folders: array<string, true>}
     */
    private function index(): array
    {
        if ($this->index !== null) {
            return $this->index;
        }

        $cached = Craft::$app->getCache()->get(self::CACHE_KEY);

        if (is_array($cached)) {
            return $this->index = $cached;
        }

        $api = $this->api();
        $config = Plugin::getInstance()->getDashConfig();
        $folderFieldId = $api->folderFieldId();
        $folderPaths = $api->folderPaths($folderFieldId);

        $files = [];
        $folders = [];

        // Every Dash folder becomes a Craft folder even when empty, so the CP tree
        // mirrors Dash rather than only showing folders that happen to hold assets.
        foreach ($folderPaths as $path) {
            foreach ($this->ancestors($path) as $ancestor) {
                $folders[$ancestor] = true;
            }
        }

        $folders[CanonicalFolder::UNFILED] = true;

        foreach ($api->allAssets() as $asset) {
            $file = $asset['currentAssetFile'] ?? null;

            if ($file === null || empty($file['filename'])) {
                continue;
            }

            $assigned = $asset['metadata']['values'][$folderFieldId] ?? [];
            $candidates = array_values(array_filter(array_map(
                static fn(string $id) => $folderPaths[$id] ?? null,
                $assigned,
            )));

            $dirname = CanonicalFolder::pick($candidates, $config->includesFolder(...));

            if (count($candidates) > 1) {
                Craft::warning(sprintf(
                    "Dash asset '%s' is in %d folders (%s); using '%s' and ignoring the rest.",
                    $file['filename'],
                    count($candidates),
                    implode(', ', $candidates),
                    $dirname,
                ), __METHOD__);
            }

            $path = "{$dirname}/" . self::craftFilename($file['filename'], (string)$asset['id']);

            if (isset($files[$path])) {
                Craft::warning("Path collision on '{$path}' — one asset is hidden.", __METHOD__);
                continue;
            }

            $files[$path] = [
                'assetId' => $asset['id'] ?? null,
                'size' => (int)($file['size'] ?? 0),
                'checksum' => $file['checksum'] ?? null,
                'previewUrl' => $file['previewUrl'] ?? null,
                'dateModified' => strtotime($asset['dateLastModified'] ?? $file['dateAdded'] ?? 'now'),
                'folderCount' => count($candidates),
            ];
        }

        $index = ['files' => $files, 'folders' => $folders];
        Craft::$app->getCache()->set(self::CACHE_KEY, $index, self::CACHE_TTL);

        return $this->index = $index;
    }

    /** @return string[] every path prefix, so parents exist before their children */
    private function ancestors(string $path): array
    {
        $segments = explode('/', $path);
        $paths = [];

        for ($i = 1; $i <= count($segments); $i++) {
            $paths[] = implode('/', array_slice($segments, 0, $i));
        }

        return $paths;
    }

    private function entry(string $uri): array
    {
        $entry = $this->index()['files'][ltrim($uri, '/')] ?? null;

        if ($entry === null) {
            throw new FsException("No Dash asset at '{$uri}'");
        }

        return $entry;
    }

    public function getFileList(string $directory = '', bool $recursive = true): Generator
    {
        $index = $this->index();
        $directory = trim($directory, '/');

        $within = function(string $path) use ($directory, $recursive): bool {
            $dirname = str_contains($path, '/') ? dirname($path) : '';

            if ($recursive) {
                return $directory === '' || str_starts_with($path, "{$directory}/");
            }

            return $dirname === $directory;
        };

        // Folders first: Craft needs the parent volumefolder row before it indexes a file.
        foreach (array_keys($index['folders']) as $path) {
            if ($within($path)) {
                yield new FsListing([
                    'dirname' => str_contains($path, '/') ? dirname($path) : '',
                    'basename' => basename($path),
                    'type' => 'dir',
                    'dateModified' => 0,
                    'fileSize' => null,
                ]);
            }
        }

        foreach ($index['files'] as $path => $entry) {
            if ($within($path)) {
                yield new FsListing([
                    'dirname' => dirname($path),
                    'basename' => basename($path),
                    'type' => 'file',
                    'dateModified' => $entry['dateModified'],
                    'fileSize' => $entry['size'],
                ]);
            }
        }
    }

    public function getFileSize(string $uri): int
    {
        return $this->entry($uri)['size'];
    }

    public function getDateModified(string $uri): int
    {
        return $this->entry($uri)['dateModified'];
    }

    public function fileExists(string $path): bool
    {
        return isset($this->index()['files'][ltrim($path, '/')]);
    }

    public function directoryExists(string $path): bool
    {
        $path = trim($path, '/');

        return $path === '' || isset($this->index()['folders'][$path]);
    }

    public function read(string $path): string
    {
        $entry = $this->entry($path);

        if ($entry['previewUrl'] === null) {
            throw new FsException("No preview URL for '{$path}'");
        }

        $contents = $this->api()->fetch($entry['previewUrl']);
        $this->verifyOriginal($path, $entry['checksum'] ?? null, $contents);

        // Opt-in transfer log. Keeping this is deliberate: how much a sync actually
        // pulls is the difference between a viable integration and an unviable one at
        // library scale, and it is not otherwise observable.
        if (($log = getenv('DASH_BYTE_LOG')) !== false) {
            file_put_contents($log, strlen($contents) . " read {$path}\n", FILE_APPEND);
        }

        return $contents;
    }

    /**
     * Dash names this endpoint a preview, and for images it serves the original byte for
     * byte — checked against this checksum on the whole library, up to 20 MB. Its own docs
     * describe an animated preview for video, so for some file type that will stop being
     * true, and the failure is otherwise silent: bytes still arrive, and Craft stores them
     * under the right filename as if they were the source.
     *
     * Enforced only for a plain 32-character md5, which is what Dash returns today. Were it
     * to move to another digest — a multipart ETag, say — that has to read as "cannot
     * verify" rather than take every image on the site down.
     */
    private function verifyOriginal(string $path, ?string $checksum, string $contents): void
    {
        if ($checksum === null || preg_match('/^[a-f0-9]{32}$/i', $checksum) !== 1) {
            return;
        }

        if (strcasecmp(md5($contents), $checksum) === 0) {
            return;
        }

        throw new FsException(sprintf(
            "Dash served %d bytes for '%s' that are not the original: the checksum does not match.",
            strlen($contents),
            $path,
        ));
    }

    private function api(): DashApi
    {
        return Plugin::getInstance()->getDashApi();
    }

    public function getFileStream(string $uriPath)
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $this->read($uriPath));
        rewind($stream);

        return $stream;
    }

    // --- The eight mutating methods. Craft has no read-only flag, so this is the only
    // --- way to express it at the filesystem layer.

    private function readOnly(string $operation): never
    {
        throw new FsException("The Dash filesystem is read-only ({$operation} refused).");
    }

    public function write(string $path, string $contents, array $config = []): void
    {
        $this->readOnly('write');
    }

    public function writeFileFromStream(string $path, $stream, array $config = []): void
    {
        $this->readOnly('writeFileFromStream');
    }

    public function deleteFile(string $path): void
    {
        // Craft calls this speculatively while cleaning up transforms; throwing here
        // aborts index views, so it is a deliberate no-op.
        Craft::info("Ignored deleteFile('{$path}') on read-only Dash filesystem.", __METHOD__);
    }

    public function renameFile(string $path, string $newPath, array $config = []): void
    {
        $this->readOnly('renameFile');
    }

    public function copyFile(string $path, string $newPath, array $config = []): void
    {
        $this->readOnly('copyFile');
    }

    public function createDirectory(string $path, array $config = []): void
    {
        $this->readOnly('createDirectory');
    }

    public function deleteDirectory(string $path): void
    {
        $this->readOnly('deleteDirectory');
    }

    public function renameDirectory(string $path, string $newName): void
    {
        $this->readOnly('renameDirectory');
    }
}
