# Runner-pool lifecycle specification

## Current behavior

Only ephemeral pools are supported. Each runner is cloned from its template, registered with
GitHub using a just-in-time (JIT) configuration, and reaped after its job or when it otherwise
becomes eligible for cleanup. JIT is a single-use runner configuration. The existing
`RunnerTemplate`/`Pool` `runner_directory` contract is independent of lifecycle type and must remain
unchanged.

## Intended distinction

- **Ephemeral** remains the default and preserves clone-per-job behavior.
- **Static** would keep the VM and its GitHub registration across jobs. A completed job would return
  the runner to idle; idleness, maximum age, and template rebuilds alone would not reap it.
- Disabling a static pool must prevent GitHub from assigning new jobs before cleanup begins. An
  already-running job must finish; then its registration and VM can be removed. Deleting a pool
  must be refused while any runner VM or registration remains.
- Persist the lifecycle type on each runner as a snapshot so later pool edits or deletion cannot
  make a static VM look ephemeral to the reaper. Existing rows migrate as ephemeral.

## Blocker

The manager currently creates JIT runners and can list or delete registrations. GitHub documents
registration-token creation for configuring persistent runners, but its
[self-hosted runner REST API](https://docs.github.com/en/rest/actions/self-hosted-runners) does not
provide an enable/disable operation that atomically stops a registered runner from receiving new
jobs. Deleting a registration is not a safe substitute: it does not provide a drain guarantee for
a runner that may be busy, and a local stop has a scheduling race. An undocumented `PATCH` or
best-effort process kill would therefore risk interrupting a job or continuing to accept work after
a pool is disabled.

Until GitHub provides a supported drain/disable control (or the manager gains a separately
validated scheduling gate), static pools must not be exposed as a supported pool type. No type
migration, form option, registration flow, or reaper exception has been added; the existing
ephemeral lifecycle remains the only supported behavior.
