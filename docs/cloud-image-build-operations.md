# Cloud Image build operations

## Cloud image source storage

Cloud image downloads use the node's configured ISO storage. The manager reads the
storage configuration from Proxmox's `/storage/{storage}` API to resolve the
node-local filesystem path needed for disk import. Directory storage, including
the default `local` storage, uses its configured path (normally `/var/lib/vz` for
`local`); custom directory paths are supported. CIFS/NFS storage remains supported.
Image files are resolved beneath `<storage-path>/template/iso/`.

The source storage must expose a filesystem path and support ISO content.
Block-only storage such as LVM-thin is not a cloud image download source; it can
still be used as the separate Template Storage destination for imported VM disks.

## Template build notes

After a successful build, the common core finalizer writes an **Aurora GHARM build**
block to the template VM's Proxmox **Notes** (`description`). This applies to both
Packer and Cloud Image, including builds completed through restart reconciliation.
The block records the original queued image version, template name/catalog ID,
builder, build ID, node, final VMID, and UTC start/completion dates. Legacy unknown
versions remain explicitly `unknown`; no credentials or callback tokens are included.

Existing Packer/operator notes outside the managed block are retained. The API write
uses the configuration digest when present to avoid overwriting concurrent edits.
Proxmox credentials must be permitted to update the VM's description. Read/write
failures, permission errors, and digest conflicts are reported as application-log
warnings; they do not invalidate the completed image or stop the next build in a
sequential batch. These requests use the node's ordinary API timeout.

This applies to future successful finalizations, not existing completed builds or
failed/cancelled builds. A Proxmox backup preserves the notes, so the recorded
`image_version` can be used when registering a restored template on another node.

## Node timeouts

Configure these on **Nodes > Create/Edit > Timeouts**. Values are integer seconds
from 1 to 43,200 inclusive. The database migration applies these defaults to existing
nodes as well as new ones; saving other settings through older clients that omit
timeout fields preserves the current values.

| Setting | Field | Default | Scope |
| --- | --- | --- | --- |
| Proxmox API request | `proxmox_api_timeout` | 120 (2 minutes) | Ordinary core API requests, password authentication, and the node form's storage lookup. |
| Cloud image disk import | `proxmox_import_timeout` | 3,600 (1 hour) | The synchronous VM configuration request that imports a cloud image into Template Storage. |
| Proxmox task wait | `proxmox_task_timeout` | 3,600 (1 hour) | Each asynchronous core Proxmox task, including downloads, clones, shutdown and template conversion. |
| Guest shutdown grace period | `proxmox_shutdown_timeout` | 300 (5 minutes) | Graceful shutdown before Proxmox's `forceStop` takes effect; explicit caller overrides still take precedence. |
| Cloud image guest IP discovery | `cloudimage_guest_ip_timeout` | 600 (10 minutes) | Waiting for the QEMU guest agent to report the build VM's IPv4 address. |
| Cloud image SSH operation | `cloudimage_ssh_timeout` | 600 (10 minutes) | SSH/SFTP operations for bootstrap, result/log polling, and restart recovery; not the detached guest script's runtime. |
| Cloud image guest build wait | `cloudimage_build_timeout` | 43,200 (12 hours) | Waiting for the detached guest build result after bootstrap. |

The HTTP API and disk-import timeouts previously shared a 30-second limit. Import
uses `PUT /nodes/{node}/qemu/{vmid}/config` with `import-from`; it can remain busy
copying a disk without returning an asynchronous task identifier. Increasing only
the task wait therefore does not fix a 30-second HTTP timeout during this operation.
Ordinary requests retain their own, shorter limit after import completes.

A client timeout does not cancel Proxmox's operation. Check the VM and task/daemon
logs before retrying or deleting it: an import may still hold the VM configuration
lock, so immediate cleanup can fail with a lock timeout. These settings do not
change Proxmox's own lock-acquisition limits or provide automatic import retries.

Keep the task wait comfortably above the shutdown grace period and size all waits
for the node's storage performance. Polling deadlines are checked between operations:
an in-flight HTTP or SSH request can run up to its own timeout before a surrounding
wait exits. Settings are read when clients/waits are created; they do not interrupt
already-running requests. Restart long-running workers when deploying the code.

Cloud Image finalization ownership uses a lease long enough for both shutdown and
template-conversion task waits, HTTP submissions/polling, and authentication:
`max(900, 2 * proxmox_task_timeout + 5 * proxmox_api_timeout + 30)` seconds.
This prevents a reconciler taking over while a worker is legitimately waiting on
slow storage. A genuinely interrupted finalizer may consequently take longer to
be reclaimed.

### Independent limits and deployment

The per-node settings do not replace the build job's 43,200-second total queue
limit, the Packer plugin's 43,200-second process limits, or Packer template-level
SSH/WinRM/reboot limits. Guest setup/import/finalization consume part of the total
job budget; a 12-hour guest wait does not guarantee 12 hours of guest execution.
Queue `retry_after` remains 44,000 seconds and the build worker's supervisor
shutdown allowance remains 43,200 seconds. Raising those deployment-level limits
requires coordinated changes, not merely editing a node.

Guest callback delivery (10 seconds), cancellation grace (15 seconds), and polling
intervals remain internal safety/transport settings, not node-editable fields.

The IP/SSH/guest-build settings and finalization lease require the corresponding
Cloud Image plugin changes. Release that package, update the manager's Composer
lock to the released version, then deploy the manager with its migration and
restart workers. Editing a sibling plugin checkout does not update the installed
Composer package. The core API/import/task/shutdown settings apply independently;
they do not reconfigure HTTP calls or timeouts owned by external Packer processes.

## Build API reachability

Cloud Image guests must be able to reach the manager's Build API over the network.
Set `BUILD_API_URL` to the guest-reachable base URL when the manager's `APP_URL` is
not reachable from the build network. An explicit manager-side build API URL
override takes precedence; otherwise, if `BUILD_API_URL` is unset, the manager
falls back to `APP_URL`. The callback endpoint is generated per build; do not
configure or copy callback tokens manually.

The guest reports progress and its terminal result to
`/api/builds/{build}/events`. Callback credentials are unique to each build, stored
as a hash by the manager, and sent only inside that build's runtime bundle. Configure
TLS and network rules so the guest can reach the endpoint without exposing it to
untrusted networks.

## Callback fallback and manager recovery

The guest runner is started detached from its SSH bootstrap session. It reports
ordered events and retries callback delivery. The manager rejects invalid
credentials and ignores duplicate or out-of-order sequence numbers.

Callbacks are not the only source of build results. The manager also reconciles the
guest result and log files over SSH when callback delivery is unavailable. This
allows a build to be finalized after a manager restart, provided the guest VM and
its result files are still available. Keep the manager's SSH credentials and
network path to the build VM operational until reconciliation completes.

## Cancellation and failed VMs

Force-killing a Cloud Image build requests guest cancellation and cleans up the
intermediate build VM. Failed-build VM retention is controlled by the global
retention preference and may be overridden for an individual build. Retained
failed VMs are useful for diagnosis; remove them after investigation to release
Proxmox resources.

## Log retention

Completed image-build log files are pruned only after their contents have been
persisted to the manager database. Database log records remain available with
their owning records. Workflow-job log files use the separate configured
workflow-job retention period and are not pruned by the image-build log command.
