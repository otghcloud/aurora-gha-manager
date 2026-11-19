<?php

namespace App\Services\Templates;

use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports third-party template sources as validated, inactive catalog versions.
 */
class TemplateSourceService
{
    private const MAX_ARCHIVE_BYTES = 26214400;

    public function __construct(
        private readonly TemplateArchiveExtractor $extractor,
        private readonly TemplateCatalogValidator $validator,
    ) {}

    /** Download a direct GitHub codeload .tar.gz URL and import its validated bundle. */
    public function importUrl(string $url): array
    {
        $this->assertCodeloadUrl($url);

        return $this->downloadAndImport($url);
    }

    /** Download a public GitHub repository snapshot without invoking git or a host command. */
    public function importRepository(string $repository, string $ref = 'main', bool $replaceExisting = false): array
    {
        [$owner, $name] = $this->parseRepository($repository);
        $ref = $this->safeRef($ref);
        $urlRef = implode('/', array_map('rawurlencode', explode('/', $ref)));

        return $this->downloadAndImport("https://codeload.github.com/{$owner}/{$name}/tar.gz/{$urlRef}", $replaceExisting);
    }

    /** Import an uploaded gzip-compressed tar archive. */
    public function importUpload(UploadedFile $archive): array
    {
        $path = $archive->getRealPath();
        if (! is_string($path) || ! is_file($path) || filesize($path) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The uploaded archive is missing or larger than 25 MiB.');
        }

        return $this->importArchiveFile($path);
    }

    /** Validate a local source directory without importing it. */
    public function validateDirectory(string $path): array
    {
        return $this->validator->validate($path);
    }

    /** Validate an archive file without importing it. */
    public function validateArchiveFile(string $path): array
    {
        return $this->withLock('template-source-validation', function () use ($path): array {
            $root = $this->installRoot();
            File::ensureDirectoryExists($root);
            $staging = $root.'/.validate-'.Str::uuid();

            try {
                $extracted = $this->extractor->extract($path, $staging);

                return $this->validator->validate($extracted);
            } finally {
                File::deleteDirectory($staging);
            }
        });
    }

    private function downloadAndImport(string $url, bool $replaceExisting = false): array
    {
        $root = $this->installRoot();
        File::ensureDirectoryExists($root);
        $archive = $root.'/.source-'.Str::uuid().'.tar.gz';

        try {
            $this->download($url, $archive);

            return $this->importArchiveFile($archive, $replaceExisting);
        } finally {
            File::delete($archive);
        }
    }

    private function importArchiveFile(string $archive, bool $replaceExisting = false): array
    {
        if (! is_file($archive) || filesize($archive) > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The archive is missing or larger than 25 MiB.');
        }

        return $this->withLock('template-source-import', function () use ($archive, $replaceExisting): array {
            $root = $this->installRoot();
            File::ensureDirectoryExists($root);
            $staging = $root.'/.source-'.Str::uuid();

            try {
                $extracted = $this->extractor->extract($archive, $staging);
                $catalog = $this->validator->validate($extracted);
                $destination = $root.'/'.$catalog['version'];

                $backup = null;
                if (file_exists($destination) || is_link($destination)) {
                    if (! $replaceExisting) {
                        throw new RuntimeException("Template bundle v{$catalog['version']} is already installed; choose a new bundle version.");
                    }
                    if (is_link($destination) || ! is_dir($destination)) {
                        throw new RuntimeException('The existing template bundle path is not a regular directory.');
                    }

                    $backup = $root.'/.backup-'.Str::uuid();
                    if (! rename($destination, $backup)) {
                        throw new RuntimeException('Could not safely replace the existing template bundle.');
                    }
                }

                if (! rename($extracted, $destination)) {
                    if ($backup !== null) {
                        rename($backup, $destination);
                    }
                    throw new RuntimeException('Could not install the validated template bundle.');
                }
                if ($backup !== null) {
                    File::deleteDirectory($backup);
                }

                return ['version' => $catalog['version'], 'path' => $destination, 'templates' => $catalog['templates']];
            } finally {
                File::deleteDirectory($staging);
            }
        });
    }

    private function download(string $url, string $destination): void
    {
        try {
            $response = Http::timeout(60)
                ->withOptions(['allow_redirects' => false, 'stream' => true])
                ->get($url);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('The template archive could not be downloaded because the remote connection failed.', previous: $exception);
        }

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download the template archive: HTTP {$response->status()}.");
        }

        $contentLength = $response->header('Content-Length');
        if (is_string($contentLength) && ctype_digit($contentLength) && (int) $contentLength > self::MAX_ARCHIVE_BYTES) {
            throw new RuntimeException('The remote archive is larger than 25 MiB.');
        }

        $body = $response->toPsrResponse()->getBody();
        $output = @fopen($destination, 'xb');
        if ($output === false) {
            throw new RuntimeException('Could not create a remote archive staging file.');
        }

        $bytes = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    if (! $body->eof()) {
                        throw new RuntimeException('The remote archive stream stopped unexpectedly.');
                    }

                    continue;
                }
                $bytes += strlen($chunk);
                if ($bytes > self::MAX_ARCHIVE_BYTES) {
                    throw new RuntimeException('The remote archive is larger than 25 MiB.');
                }
                if (fwrite($output, $chunk) !== strlen($chunk)) {
                    throw new RuntimeException('Could not save the remote archive.');
                }
            }
        } finally {
            fclose($output);
        }
    }

    private function assertCodeloadUrl(string $url): void
    {
        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'codeload.github.com'
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#^/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/tar\.gz/(.+)$#', $parts['path'] ?? '', $matches) !== 1) {
            throw new RuntimeException('Archive URLs must be direct HTTPS codeload.github.com repository tarballs.');
        }

        $this->assertSafeRepositoryPart(explode('/', trim($parts['path'], '/'))[0] ?? '');
        $this->assertSafeRepositoryPart(explode('/', trim($parts['path'], '/'))[1] ?? '');
        $this->safeRef(rawurldecode($matches[1]));
    }

    /** @return array{string, string} */
    private function parseRepository(string $repository): array
    {
        $parts = parse_url($repository);
        if (! is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https'
            || strtolower($parts['host'] ?? '') !== 'github.com'
            || isset($parts['port'])
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])
            || preg_match('#^/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $parts['path'] ?? '', $matches) !== 1) {
            throw new RuntimeException('Repository sources must be public HTTPS GitHub repository URLs.');
        }

        $owner = $this->assertSafeRepositoryPart($matches[1]);
        $name = $this->assertSafeRepositoryPart($matches[2]);

        return [$owner, $name];
    }

    private function assertSafeRepositoryPart(string $part): string
    {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, '..') || preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,99}$/', $part) !== 1) {
            throw new RuntimeException('The GitHub repository URL contains an invalid owner or repository name.');
        }

        return $part;
    }

    private function safeRef(string $ref): string
    {
        if ($ref === '' || strlen($ref) > 128 || str_contains($ref, '\\') || str_contains($ref, '..') || preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $ref) !== 1) {
            throw new RuntimeException('The Git repository ref is invalid.');
        }

        return $ref;
    }

    private function installRoot(): string
    {
        $root = rtrim((string) config('builds.templates_install_path'), '/');

        if ($root === '' || preg_match('#(?:^|[/\\\\])\.{1,2}(?:[/\\\\]|$)#', $root) === 1) {
            throw new RuntimeException('The template install directory must be a non-root path without dot segments.');
        }

        return $root;
    }

    private function withLock(string $key, \Closure $callback): mixed
    {
        try {
            return Cache::lock($key, 120)->block(30, $callback);
        } catch (LockTimeoutException $exception) {
            throw new RuntimeException('Another template source operation is already in progress.', previous: $exception);
        }
    }
}
