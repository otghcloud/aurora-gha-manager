<?php

namespace App\Services\Templates;

use RuntimeException;

/**
 * Validates template catalogs and every filesystem path the catalog can cause core to read.
 */
class TemplateCatalogValidator
{
    private const MAX_MANIFEST_BYTES = 1048576;

    /**
     * @return array{version: string, templates: int}
     */
    public function validate(string $root): array
    {
        $this->assertNoSymlinks($root);
        $manifestPath = $root.'/templates.json';

        if (! is_file($manifestPath) || ! is_readable($manifestPath) || filesize($manifestPath) > self::MAX_MANIFEST_BYTES) {
            throw new RuntimeException('The source must contain a readable templates.json no larger than 1 MiB.');
        }

        try {
            $catalog = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('templates.json is not valid JSON.');
        }

        if (! is_array($catalog) || ($catalog['schema_version'] ?? null) !== 2) {
            throw new RuntimeException('templates.json must use schema_version 2.');
        }

        if (array_key_exists('generated_at', $catalog)
            && (! is_string($catalog['generated_at']) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/', $catalog['generated_at']) !== 1)) {
            throw new RuntimeException('templates.json generated_at must be an ISO 8601 date-time.');
        }

        if (array_key_exists('runner_images_commit', $catalog)
            && (! is_string($catalog['runner_images_commit']) || preg_match('/^[a-f0-9]{40}$/', $catalog['runner_images_commit']) !== 1)) {
            throw new RuntimeException('templates.json runner_images_commit must be a 40-character lowercase commit hash.');
        }

        $version = $catalog['image_builder_version'] ?? null;
        if (! is_string($version) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,63}$/', $version) !== 1) {
            throw new RuntimeException('templates.json must declare a safe image_builder_version.');
        }

        $templates = $catalog['templates'] ?? null;
        if (! is_array($templates) || ! array_is_list($templates)) {
            throw new RuntimeException('templates.json must contain a templates array.');
        }

        $ids = [];

        foreach ($templates as $index => $template) {
            if (! is_array($template)) {
                throw new RuntimeException("Template entry {$index} must be an object.");
            }

            $id = $template['id'] ?? null;
            if (! is_string($id) || preg_match('/^[a-z0-9][a-z0-9._-]{0,127}$/', $id) !== 1 || isset($ids[$id])) {
                throw new RuntimeException("Template entry {$index} must have a unique, safe id.");
            }
            $ids[$id] = true;

            if (! is_string($template['name'] ?? null) || trim($template['name']) === '' || strlen($template['name']) > 255) {
                throw new RuntimeException("Template {$id} must have a name no longer than 255 characters.");
            }

            if (array_key_exists('schema_version', $template) && $template['schema_version'] !== 2) {
                throw new RuntimeException("Template {$id} must use schema_version 2.");
            }

            if (array_key_exists('description', $template) && ! is_string($template['description'])) {
                throw new RuntimeException("Template {$id} description must be a string.");
            }

            if (! is_array($template['metadata'] ?? null) || ! is_string($template['metadata']['version'] ?? null) || $template['metadata']['version'] === '') {
                throw new RuntimeException("Template {$id} must declare metadata.version.");
            }

            if (! is_array($template['platform'] ?? null) || ! in_array($template['platform']['type'] ?? null, ['linux', 'windows'], true)) {
                throw new RuntimeException("Template {$id} must declare a supported platform.type.");
            }

            $builders = $template['builders'] ?? null;
            if (! is_array($builders) || $builders === []) {
                throw new RuntimeException("Template {$id} must declare at least one builder.");
            }

            foreach ($builders as $builderName => $builder) {
                if (! is_string($builderName)
                    || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $builderName) !== 1
                    || ! is_array($builder)) {
                    throw new RuntimeException("Template {$id} has an invalid builder.");
                }

                foreach (['path', 'build_manifest'] as $field) {
                    $path = $builder[$field] ?? null;
                    $this->assertReference($root, $path, "Template {$id} builder {$builderName} {$field}", $field === 'path');
                }
            }

            foreach (['template_path' => true, 'template_json_path' => false] as $field => $directory) {
                if (array_key_exists($field, $template)) {
                    $this->assertReference($root, $template[$field], "Template {$id} {$field}", $directory);
                }
            }
        }

        return ['version' => $version, 'templates' => count($templates)];
    }

    private function assertReference(string $root, mixed $path, string $label, bool $directory): void
    {
        if (! is_string($path) || $path === '' || str_contains($path, '\\') || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)) {
            throw new RuntimeException("{$label} must be a relative path.");
        }

        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                throw new RuntimeException("{$label} contains an unsafe path.");
            }
        }

        $resolved = $root.'/'.$path;
        if (! $this->isWithinRoot($root, $resolved) || is_link($resolved)) {
            throw new RuntimeException("{$label} points outside the source or through a symlink.");
        }

        $exists = $directory ? is_dir($resolved) : is_file($resolved);
        if (! $exists) {
            throw new RuntimeException("{$label} does not exist in the source.");
        }

        if (! $directory && str_ends_with($path, '.json')) {
            $size = filesize($resolved);

            if (! is_int($size) || $size > self::MAX_MANIFEST_BYTES) {
                throw new RuntimeException("{$label} exceeds the 1 MiB JSON file limit.");
            }

            try {
                json_decode((string) file_get_contents($resolved), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException("{$label} is not valid JSON.");
            }
        }
    }

    private function isWithinRoot(string $root, string $path): bool
    {
        $realRoot = realpath($root);
        $realPath = realpath($path);

        return $realRoot !== false && $realPath !== false && str_starts_with($realPath, rtrim($realRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR);
    }

    private function assertNoSymlinks(string $root): void
    {
        if (! is_dir($root) || is_link($root)) {
            throw new RuntimeException('The template source must be a regular directory.');
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if ($entry->isLink()) {
                throw new RuntimeException('The template source contains a symlink.');
            }
        }
    }
}
