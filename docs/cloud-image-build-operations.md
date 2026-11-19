# Cloud Image build operations

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
