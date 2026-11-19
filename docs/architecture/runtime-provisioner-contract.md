# Runtime provisioner and imported artifacts

The current `BuilderInterface` is an image-build contract: it builds a catalog
entry and returns a `BuildResult`. Runner provisioning is a separate core
responsibility handled by the runner `Provisioner`, which clones a VM, boots it,
registers a runner, starts the runner process, and later destroys the VM.

An imported artifact is not a runtime provisioner. Importing or validating a
template can establish that an artifact is present and structurally usable, but
does not establish that the manager can create a VM from it or manage that VM's
runner lifecycle.

Already-restored Proxmox templates can be [registered](../restored-template-import.md)
against existing runner template definitions. That flow uses the existing Proxmox
clone-and-launch runtime and requires a prepared runner image and matching
credentials. It does not enable the general-purpose Prebuilt artifact provider
described below or claim to verify guest contents through a storage import.

## Required separation

- **Artifact source** resolves an immutable artifact and its manifest. It validates
  the declared schema, checksum, format, architecture, operating-system identity,
  and required runtime metadata before making the artifact available to the
  catalog. It does not create VMs or register runners.
- **Runtime provisioner** advertises the runtime capabilities it supports and
  provisions a runner from a validated artifact. It owns VM creation, runner
  registration and startup, health/readiness reporting, and idempotent cleanup on
  success, failure, cancellation, and manager recovery.
- **Build plugin** continues to create images through `BuilderInterface`; it is not
  required to implement runtime provisioning.

Before adding a Prebuilt runtime provider, define its capability selection,
provisioning result and error contract, cancellation behavior, cleanup guarantees,
and recovery behavior. Do not mark an imported artifact buildable or provisionable
until those runtime capabilities and lifecycle guarantees are implemented and
validated. Keep artifact checksum/format validation independent from VM
provisioning so that either capability can be tested without implying the other.
