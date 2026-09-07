<?php

namespace App\Services\Provisioning;

use App\Exceptions\ProvisioningException;
use App\Models\Infrastructure\Environment;
use App\Models\Infrastructure\ProxmoxTarget;
use App\Services\GitHub\GitHubClient;
use App\Services\Proxmox\ProxmoxClient;

/**
 * Builds the per-environment service graph.
 *
 * Every client is bound to one environment's credentials, so they cannot be resolved from
 * the container as singletons.
 */
/**
 * Builds the environment-scoped service graph used by runner operations.
 */
class EnvironmentServices
{
    /** Build a Proxmox client for the environment's default target. */
    public function proxmox(Environment $environment): ProxmoxClient
    {
        $target = (new TargetSelector)->selectFor(['self-hosted']);

        if ($target === null) {
            throw new ProvisioningException('No Proxmox target is configured.');
        }

        return new ProxmoxClient($target);
    }

    /** Build a GitHub client for the environment account. */
    public function github(Environment $environment): GitHubClient
    {
        return new GitHubClient($environment->githubAccount);
    }

    /** Build a provisioner using the environment's default target. */
    public function provisioner(Environment $environment): Provisioner
    {
        $target = $this->target($environment);
        $proxmox = new ProxmoxClient($target);

        return new Provisioner(
            $environment,
            $target,
            $proxmox,
            $this->github($environment),
            new VmidAllocator($proxmox),
            new SshRunnerLauncher,
            new TargetSelector,
        );
    }

    /** Build a reaper for one environment and target pair. */
    public function reaper(Environment $environment, ProxmoxTarget $target): Reaper
    {
        return new Reaper(
            $environment,
            $target,
            new ProxmoxClient($target),
            $this->github($environment),
            $this->provisionerForTarget($environment, $target),
        );
    }

    /** Build a provisioner pinned to a specific target. */
    public function provisionerForTarget(Environment $environment, ProxmoxTarget $target): Provisioner
    {
        $proxmox = new ProxmoxClient($target);

        return new Provisioner(
            $environment,
            $target,
            $proxmox,
            $this->github($environment),
            new VmidAllocator($proxmox),
            new SshRunnerLauncher,
            new TargetSelector,
        );
    }

    /** Resolve the environment's default enabled target. */
    public function target(Environment $environment): ProxmoxTarget
    {
        $target = (new TargetSelector)->selectFor(['self-hosted']);

        if ($target === null) {
            throw new ProvisioningException('No eligible Proxmox target is configured.');
        }

        return $target;
    }
}
