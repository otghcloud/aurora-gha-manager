# Release notes

## Unreleased

### PHP support

- PHP 8.3 is no longer tested or supported by first-party PHP packages. Plugin
  CI retains PHP 8.4, and plugin/website manifests require PHP 8.4 or newer.
  Core retains its existing PHP 8.4.1 minimum and PHP 8.5 CI/container runtime.

### Build operations

- Cloud Image disk imports now use Proxmox's asynchronous configuration API and
  wait for its task before resizing or booting the VM. This avoids HTTP 596
  proxy timeouts during slow imports, including copies to HDD Template Storage.
  The existing per-node disk-import timeout now controls task completion; ordinary
  API requests retain their shorter timeout. This is a core-only fix; restart
  build workers after deploying it.
- Successful Packer and Cloud Image builds now write their recorded image version,
  template/catalog identity, builder, build ID, node/VMID, and UTC start/completion
  dates to a managed block in Proxmox Notes. Existing notes are preserved. Failed
  notes updates are logged without discarding the built template; existing builds
  are not backfilled.
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

- Already-restored Proxmox templates can be registered through **Import restored
  template**, selecting a node, configured runner template, source disk storage,
  restored VMID, and optional original image version. Registration validates the
  remote template and makes the node available without rebuilding or modifying the
  VM. Existing physical mappings are not overwritten. See the
  [import workflow and lifecycle notes](restored-template-import.md).
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
