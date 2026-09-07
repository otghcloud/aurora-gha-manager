<?php

namespace App\Services\Provisioning;

use App\Exceptions\RemoteException;
use App\Models\Credentials\Credential;
use App\Models\Pools\Pool;
use App\Services\Ssh\SshConnection;
use Illuminate\Support\Str;

/**
 * Installs and launches the GitHub runner process over SSH.
 */
class SshRunnerLauncher
{
    /**
     * Start the GitHub runner inside a freshly booted guest.
     *
     * The JIT blob is kept out of the SSH command line and shell history. phpseclib offers no way
     * to signal channel EOF,
     * so a reader like `cat` would block forever. Instead the blob is written to a private file
     * and removed by the launch command before the runner starts — the same upload-then-execute
     * pattern aurora-manage uses for its remote scripts.
     */
    public function launch(Credential $credential, Pool $pool, string $host, string $encodedJitConfig, string $runnerName): void
    {
        $ssh = new SshConnection(
            host: $host,
            port: $pool->runnerTemplate->os->remotePort(),
            username: $credential->resolvedUsername(),
            password: $credential->password,
            privateKey: $credential->private_key,
        );

        $this->assertGuestIdentity($ssh, $host, $runnerName);

        $directory = rtrim($pool->runnerDirectory(), '/');
        // Named per runner: if two guests ever answer on one address, they cannot silently
        // consume or delete each other's config.
        $jitFile = '.jitconfig-'.$runnerName;
        $jitPath = $directory.'/'.$jitFile;

        $ssh->putString($jitPath, $encodedJitConfig)->chmod(0600, $jitPath);

        // Temporary until the templates ship with the SSH user already in the docker group.
        $ssh->run('sudo -n usermod -aG docker '.escapeshellarg($credential->resolvedUsername()));

        $ssh->run('sudo -n hostnamectl set-hostname '.escapeshellarg($runnerName));

        // Each SSH exec is its own logind session; without lingering, systemd kills that
        // session's whole cgroup scope - including a nohup/setsid'd child - the moment this
        // channel closes, so run.sh dies silently before it can write to its own log. Cloud
        // image guests hit this in practice; Packer-built ones have not, but enabling lingering
        // is harmless either way.
        $ssh->run('sudo -n loginctl enable-linger '.escapeshellarg($credential->resolvedUsername()));

        $output = $ssh->run($this->launchCommand($directory, $jitFile));

        if ($ssh->exitCode() !== 0) {
            $ssh->delete($jitPath);
            $ssh->disconnect();

            throw new RemoteException("Runner launch failed on {$host}: ".trim($output));
        }

        $ssh->disconnect();
    }

    /**
     * Refuse to configure a guest that is really a different runner.
     *
     * Proxmox gives each clone its own MAC, but a template sealed with a shared DHCP identity
     * hands every clone the same lease, so several runners can answer on one address and
     * overwrite each other's setup. The guest hostname comes from the VM name via cloud-init,
     * so a hostname carrying our runner-name prefix but a different suffix means we have
     * reached somebody else's VM. Any other hostname is left alone, since a guest that has not
     * applied its cloud-init hostname yet is not evidence of a collision.
     */
    private function assertGuestIdentity(SshConnection $ssh, string $host, string $runnerName): void
    {
        $hostname = strtolower(trim($ssh->run('hostname -s')));
        $expected = strtolower($runnerName);

        if ($hostname === '' || $hostname === $expected) {
            return;
        }

        $prefix = Str::beforeLast($expected, '-');

        if ($prefix === '' || ! str_starts_with($hostname, $prefix.'-')) {
            return;
        }

        $ssh->disconnect();

        throw new RemoteException(
            "Expected runner {$runnerName} at {$host} but the guest identifies as {$hostname}. "
            .'Two VMs are answering on the same address - check that the template is sealed with '
            .'a unique DHCP identity.'
        );
    }

    private function launchCommand(string $directory, string $jitFile): string
    {
        return sprintf(
            'cd %s && JITCONFIG="$(cat %s)" && rm -f %s && '
                .'(setsid nohup ./run.sh --jitconfig "$JITCONFIG" > runner-startup.log 2>&1 < /dev/null &) && echo started',
            escapeshellarg($directory),
            escapeshellarg($jitFile),
            escapeshellarg($jitFile),
        );
    }
}
