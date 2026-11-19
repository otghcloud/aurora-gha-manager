<?php

namespace App\Services\Templates;

use RuntimeException;

/**
 * Extracts plain gzip-compressed ustar archives without following links.
 */
class TemplateArchiveExtractor
{
    private const BLOCK_SIZE = 512;

    private const MAX_ENTRIES = 2000;

    private const MAX_UNCOMPRESSED_BYTES = 209715200;

    /** Extract a single-root .tar.gz archive into a new directory and return its root. */
    public function extract(string $archivePath, string $destination): string
    {
        $stream = @gzopen($archivePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('The source is not a readable gzip archive.');
        }

        if (! is_dir($destination) && ! mkdir($destination, 0700, true) && ! is_dir($destination)) {
            gzclose($stream);
            throw new RuntimeException('Could not create the archive staging directory.');
        }

        $root = null;
        $seen = [];
        $entries = 0;
        $totalBytes = 0;

        try {
            while (($header = $this->readBlock($stream)) !== null) {
                if ($header === str_repeat("\0", self::BLOCK_SIZE)) {
                    break;
                }

                if (++$entries > self::MAX_ENTRIES) {
                    throw new RuntimeException('The archive contains too many entries.');
                }

                $this->verifyChecksum($header);

                $name = $this->field($header, 0, 100);
                $prefix = $this->field($header, 345, 155);
                $path = $prefix === '' ? $name : $prefix.'/'.$name;
                $type = $header[156] === "\0" ? '0' : $header[156];
                $size = $this->octal($header, 124, 12);

                if (! in_array($type, ['0', '5'], true)) {
                    throw new RuntimeException('The archive contains a link or unsupported file type.');
                }

                if ($type === '5' && $size !== 0) {
                    throw new RuntimeException('The archive contains an invalid directory entry.');
                }

                $path = $this->safePath($path);
                $parts = explode('/', $path);
                $archiveRoot = array_shift($parts);

                if ($archiveRoot === null || $archiveRoot === '') {
                    throw new RuntimeException('The archive must contain files under one top-level directory.');
                }

                if ($root !== null && $root !== $archiveRoot) {
                    throw new RuntimeException('The archive must contain exactly one top-level directory.');
                }
                $root = $archiveRoot;

                if ($parts === []) {
                    if ($type !== '5' || isset($seen[$path])) {
                        throw new RuntimeException('The archive must contain files under one top-level directory.');
                    }
                    $seen[$path] = $type;
                    $this->ensureDirectories($destination.'/'.$root, []);

                    continue;
                }

                $relative = implode('/', $parts);
                $target = $destination.'/'.$root.'/'.$relative;
                $this->ensureDirectories($destination.'/'.$root, array_slice($parts, 0, -1));

                if (isset($seen[$path])) {
                    throw new RuntimeException('The archive contains duplicate paths.');
                }
                $seen[$path] = $type;

                if ($type === '5') {
                    if (isset($seen[$path.'/file'])) {
                        throw new RuntimeException('The archive has conflicting file and directory paths.');
                    }

                    if (! is_dir($target) && ! mkdir($target, 0700) && ! is_dir($target)) {
                        throw new RuntimeException('Could not create an archive directory.');
                    }

                    continue;
                }

                if (isset($seen[$path.'/child']) || is_dir($target)) {
                    throw new RuntimeException('The archive has conflicting file and directory paths.');
                }

                $totalBytes += $size;
                if ($size > self::MAX_UNCOMPRESSED_BYTES || $totalBytes > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException('The archive expands beyond the 200 MiB limit.');
                }

                $output = @fopen($target, 'xb');
                if ($output === false) {
                    throw new RuntimeException('Could not create an archive file safely.');
                }
                chmod($target, 0600);

                try {
                    $remaining = $size;
                    while ($remaining > 0) {
                        $chunk = gzread($stream, min(8192, $remaining));
                        if ($chunk === false || $chunk === '') {
                            throw new RuntimeException('The archive ended unexpectedly.');
                        }

                        $written = fwrite($output, $chunk);
                        if ($written !== strlen($chunk)) {
                            throw new RuntimeException('Could not write an extracted archive file.');
                        }
                        $remaining -= strlen($chunk);
                    }
                } finally {
                    fclose($output);
                }

                $padding = (self::BLOCK_SIZE - ($size % self::BLOCK_SIZE)) % self::BLOCK_SIZE;
                if ($padding > 0) {
                    $this->readExact($stream, $padding);
                }
            }
        } finally {
            gzclose($stream);
        }

        if ($root === null || ! is_file($destination.'/'.$root.'/templates.json')) {
            throw new RuntimeException('The archive must contain templates.json under its top-level directory.');
        }

        return $destination.'/'.$root;
    }

    /** @param resource $stream */
    private function readBlock($stream): ?string
    {
        $block = $this->readExact($stream, self::BLOCK_SIZE, allowEof: true);

        if ($block === null || $block === str_repeat("\0", self::BLOCK_SIZE)) {
            return $block;
        }

        return $block;
    }

    /** @param resource $stream */
    private function readExact($stream, int $length, bool $allowEof = false): ?string
    {
        $buffer = '';

        while (strlen($buffer) < $length) {
            $chunk = gzread($stream, $length - strlen($buffer));

            if ($chunk === false) {
                throw new RuntimeException('The archive could not be read.');
            }

            if ($chunk === '') {
                if ($allowEof && $buffer === '') {
                    return null;
                }

                throw new RuntimeException('The archive ended unexpectedly.');
            }

            $buffer .= $chunk;
        }

        return $buffer;
    }

    private function field(string $header, int $offset, int $length): string
    {
        $value = substr($header, $offset, $length);
        $terminator = strpos($value, "\0");

        return trim($terminator === false ? $value : substr($value, 0, $terminator));
    }

    private function octal(string $header, int $offset, int $length): int
    {
        $value = trim(str_replace("\0", '', substr($header, $offset, $length)));

        if ($value === '') {
            return 0;
        }

        if (preg_match('/^[0-7]+$/', $value) !== 1) {
            throw new RuntimeException('The archive contains an invalid file size.');
        }

        return intval($value, 8);
    }

    private function verifyChecksum(string $header): void
    {
        $expected = $this->octal($header, 148, 8);
        $checksumHeader = substr_replace($header, str_repeat(' ', 8), 148, 8);

        if (array_sum(array_map('ord', str_split($checksumHeader))) !== $expected) {
            throw new RuntimeException('The archive contains an invalid tar header.');
        }
    }

    private function safePath(string $path): string
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException('The archive contains an unsafe path.');
        }

        $path = rtrim($path, '/');
        $parts = explode('/', $path);

        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException('The archive contains an unsafe path.');
            }
        }

        return $path;
    }

    /** Ensure only ordinary directories created beneath staging are traversed. */
    private function ensureDirectories(string $root, array $parts): void
    {
        if (! is_dir($root) && ! mkdir($root, 0700, true) && ! is_dir($root)) {
            throw new RuntimeException('Could not create the archive root directory.');
        }
        if (is_link($root)) {
            throw new RuntimeException('The archive contains a symlink path.');
        }

        $current = $root;
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $current .= '/'.$part;
            if (is_link($current)) {
                throw new RuntimeException('The archive contains a symlink path.');
            }
            if (! is_dir($current) && ! mkdir($current, 0700) && ! is_dir($current)) {
                throw new RuntimeException('Could not create an archive directory.');
            }
            chmod($current, 0700);
        }
    }
}
