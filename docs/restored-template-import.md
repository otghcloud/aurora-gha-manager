# Import an already-restored Proxmox template

Use this to reuse a runner template on another node without building it again.
It registers an existing Proxmox QEMU template against a configured runner
template definition. It does **not** restore a backup archive, import a raw disk,
copy disks between storage pools, convert an ordinary VM into a template, or
create a successful build-history entry.

## Workflow

For example, after building Ubuntu Slim or Ubuntu 26.04 on node A:

1. Back up the template through Proxmox to your shared backup storage.
2. Restore the backup through Proxmox onto node B. Choose a free VMID inside node
   B's configured **template** VMID range. Restore its disks to storage accessible
   from node B and ensure that the restored VM is marked as a template.
3. Wait for the restore to finish. Leave the template stopped and unlocked.
4. In the manager, open **Templates > Import restored template**. The same action
   is available from template details and node details.
5. Select node B and choose **Load storage options**.
6. Select the configured runner template, source storage, and restored VMID.
   The runner template definition must already exist; its original node mapping
   is not changed.
7. Optionally enter the image's original version, then select **Import template**.

New manager-built images record `image_version`, build dates and other provenance
in the **Aurora GHARM build** block of Proxmox Notes. Backups preserve those notes;
use that recorded version when filling the optional version field. Registration
does not rewrite the original build notes or infer a version automatically.

**Source storage means the restored template's disk storage on node B**, not the
shared storage containing the backup archive. For a multi-storage template, select
a storage containing an attached non-CD-ROM disk. The manager does not move or
rewrite any disk.

The template detail page shows its VMID, available status, imported storage, and
import time. An omitted version displays as **Unknown (imported)**; the current
catalog version is not substituted or attributed to an older restored image.

## Preconditions and checks

The manager reads Proxmox's cluster resource list, VM configuration, and the node's
image storage list. Its credentials need permission to read these resources.
Password-mode connections may also authenticate through the ticket API; the
import itself does not mutate any Proxmox VM or storage.

Registration requires:

- The VMID belongs to a QEMU VM on the selected node, inside its template VMID range.
- The VM configuration has `template=1`, no active lock, and the VM is stopped.
- The selected image storage is active/enabled and holds an attached disk.
  CD-ROM/cloud-init drives and unused/EFI/TPM disk entries alone do not qualify.
- The runner template has a usable credential for its operating system.
- No existing physical mapping is overwritten, even if it is currently unavailable.
  An existing mapping with no VMID can be filled, preserving its build settings.
- The VMID is not already assigned to another template or active runner, retained
  for pruning as a superseded template, or reserved by a queued/running build.
- This runner template has no queued/running build on the selected node.

Validation errors leave mappings unchanged. A failed Proxmox connection or permission
check is reported rather than treating an unverified VM as available. Repeating an
import does not create another generation or overwrite the first registration.

The manager cannot prove the guest's software identity, image version, SSH key, or
runner installation directory without booting/accessing it; registration does not
boot it. Use the same template definition as the source image, retain a matching
runner credential, and ensure the runner software and QEMU guest agent are present.
The installed catalog's runner directory must match the restored image. Normal
runner provisioning still performs its usual boot, network, credential, and launch
checks.

## Runner routing, rebuilds, and ownership

An import records an available node mapping and its first generation, along with
`imported_storage`, `imported_at`, and the optional supplied version. It does not set
a fabricated `last_built_at`, require an installation ISO, enqueue a build, or alter
the node's default Template Storage. Future builds still use that node default.

Pool routing uses this mapping exactly as it uses a successfully built mapping.
Add the destination node to the appropriate pool if it is not already assigned.
Normal health, drain, capacity, and pool-preference rules still apply. Runner clones
use **Runner VM Storage** when configured (full clones), otherwise Proxmox's existing
linked-clone behavior on the template storage. The source storage chosen during
registration is not a new runner destination setting.

A later successful rebuild promotes a fresh VMID through the normal generation
lifecycle, clears the current mapping's import provenance, and retires the imported
VMID. The imported VM can then be pruned according to template-retention settings
once no runners use it. Import therefore opts this VM into the manager's normal
template lifecycle; retain an external backup if it must remain recoverable.

Replacing an existing physical mapping through this UI is deliberately unsupported.
Do not detach a live mapping or reuse a retained VMID simply to bypass the checks.
Use the established rebuild lifecycle when replacing a managed template.

## Deployment and scope

Deploy core with the `add_template_import_metadata` migration before using the UI.
This feature requires no new builder plugin release and works with compatible
templates originally produced by either Packer or Cloud Image.

This is not the deferred general-purpose Prebuilt artifact provider: there is no
new archive format, artifact downloader, checksum verifier, or runtime provisioner.
The existing Proxmox clone-and-launch runtime remains responsible for runners.
