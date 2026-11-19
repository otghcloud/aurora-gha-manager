# Third-party template sources

Aurora accepts template catalogs from a public GitHub repository, a GitHub codeload archive URL,
or an administrator-uploaded `.tar.gz` archive. Imports are validated and installed as
**inactive** bundles. An administrator must review and activate a bundle from Settings → Templates;
import never rebuilds an image or provisions a runner.

## Catalog schema

The catalog is `templates.json` at the source root. The JSON Schema in
[`template-catalog.schema.json`](template-catalog.schema.json) describes the version 2 catalog
shape. For compatibility, an entry must have a unique lowercase `id`, `name`, `metadata.version`,
`platform.type` (`linux` or `windows`), and at least one builder. Every builder must declare
relative `path` and `build_manifest` paths. Referenced directories and JSON files must exist inside
the source; catalog versions and paths are validated before install or activation.
Builder names must be safe lowercase identifiers, and referenced JSON documents are limited to
1 MiB each.

For each template, `template_json_path` may point to additional metadata JSON and `template_path`
may point to the template's source directory. Use POSIX `/` separators. Absolute paths, parent
traversal, symlinks, duplicate IDs, malformed JSON, and missing references are rejected. The schema
does not make build scripts safe: only import catalogs from authors you trust.

## Archive format

Upload one gzip-compressed ustar archive (`.tar.gz` or `.tgz`) with exactly one top-level directory.
That directory contains `templates.json` and all files referenced by it. For example:

```text
acme-runner-templates/
├── templates.json
└── templates/
    └── ubuntu/
        └── ubuntu2404/
            ├── template.json
            └── cloudimage/
                └── build.json
```

Archives may contain only ordinary files and directories. Symlinks, hard links, special files,
multiple roots, and path traversal are rejected. The compressed archive is limited to 25 MiB,
extracted content to 200 MiB, and the archive to 2,000 entries. Keep file names within standard
ustar header limits and do not use PAX/GNU extension entries.

## Import sources

* **Git repository:** provide `https://github.com/OWNER/REPOSITORY` and a branch, tag, or commit
  ref. Aurora downloads a codeload snapshot; it does not run `git`, build hooks, or other commands.
* **Archive URL:** provide a direct HTTPS codeload URL such as
  `https://codeload.github.com/OWNER/REPOSITORY/tar.gz/refs/heads/main`. Arbitrary hosts and
  redirects are not accepted, preventing source imports from becoming server-side request forgery.
* **Upload:** select a `.tar.gz`/`.tgz` archive in Settings → Templates.

An import is schema-checked and stored inactive. Use the existing activation control to switch the
catalog after review. The previous active bundle is not changed by a failed validation or import.

## Validate before publishing

From the core application root, validate a source directory or archive without importing it:

```sh
php artisan templates:validate /path/to/acme-runner-templates
php artisan templates:validate /path/to/acme-runner-templates.tar.gz
```

Use the JSON Schema in CI or an editor for fast authoring feedback, then run Aurora's validator
before publishing. The application validator additionally checks that referenced files exist and
that the archive can be safely extracted.
