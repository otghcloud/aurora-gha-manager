# Release notes

## Unreleased

### Build operations

- Cloud Image builds use detached guest execution, per-build callback credentials,
  SSH result reconciliation, and restart recovery. Configure `BUILD_API_URL` when
  build guests cannot reach the manager through `APP_URL`.
- Failed build VMs can be retained for investigation, with a per-build override.
- Completed build log files are pruned only after their contents are stored in the
  manager database; workflow-job log files keep their separate retention policy.

### Build and settings views

- The Builds page supports template, Proxmox node, and status filters, plus
  succeeded, failed, and in-progress totals.
- The Settings overview reports retained-record usage, installed builder plugins,
  and configured template source locations.

### Templates and builders

- The Templates page presents configured runner templates as cards. Administrators can import
  public GitHub repository snapshots, codeload archive URLs, or uploaded `.tar.gz` bundles;
  imports are validated and remain inactive until explicitly activated.
- Third-party catalogs can be checked with `php artisan templates:validate`; archive limits and
  layout requirements are documented in the template-source guide.
- Runner installation directories are declared in template metadata and used by
  core runner launch and image-build setup.
- The Packer plugin supports Proxmox API-token and username/password authentication.
  The updated plugin package must be published and consumed before the change is
  available in deployments.
