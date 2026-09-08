<?php

namespace App\Services\Ssh;

use App\Exceptions\RemoteException;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use phpseclib3\Net\SSH2;

/**
 * Authenticated SSH/SFTP connection wrapper for runner provisioning tasks.
 */
class SshConnection
{
    private ?SSH2 $ssh = null;

    private ?SFTP $sftp = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly ?string $password = null,
        private readonly ?string $privateKey = null,
        private readonly ?string $passphrase = null,
        private readonly int $timeout = 300,
    ) {}

    /**
     * Write string content to a remote path.
     */
    public function putString(string $remotePath, string $content): self
    {
        $this->sftpWithRetry(
            fn (SFTP $sftp): bool => $sftp->put($remotePath, $content) !== false,
            "Could not write {$remotePath} on {$this->host}"
        );

        return $this;
    }

    public function putFile(string $remotePath, string $localPath): self
    {
        if (! is_readable($localPath)) {
            throw new RemoteException("Could not upload {$localPath} to {$this->host}:{$remotePath}");
        }

        $this->sftpWithRetry(
            fn (SFTP $sftp): bool => $sftp->put($remotePath, $localPath, SFTP::SOURCE_LOCAL_FILE) !== false,
            "Could not upload {$localPath} to {$this->host}:{$remotePath}"
        );

        return $this;
    }

    /**
     * Run an SFTP operation, reconnecting once if the guest drops the session.
     *
     * A guest that is still settling (cloud-init regenerating host keys, a package upgrade
     * restarting services) closes established sessions, which surfaces here rather than at login.
     * Mirrors the reconnect-and-retry that exec() already does for its own channel errors.
     *
     * @param  callable(SFTP): bool  $operation
     */
    private function sftpWithRetry(callable $operation, string $failureMessage): void
    {
        try {
            if ($operation($this->sftp())) {
                return;
            }
        } catch (\RuntimeException $e) {
            $this->disconnect();

            if (! $operation($this->sftp())) {
                throw new RemoteException($failureMessage);
            }

            return;
        }

        throw new RemoteException($failureMessage);
    }

    public function chmod(int $mode, string $remotePath): self
    {
        $this->sftp()->chmod($mode, $remotePath);

        return $this;
    }

    public function delete(string $remotePath): self
    {
        $this->sftp()->delete($remotePath, false);

        return $this;
    }

    /**
     * Run one or more commands in order, returning their combined output.
     *
     * phpseclib's SSH2 can get its internal channel bookkeeping out of sync after a command
     * leaves a lingering process attached to the session (e.g. apt triggering a service restart),
     * surfacing as "Please close the channel (1) before trying to open it again" on the *next*
     * exec() rather than the command that actually caused it. Reconnecting once and retrying is
     * the standard workaround; a second failure is a real problem and should present itself.
     *
     * @param  array<int, string>|string  $commands
     * @param  (callable(string): mixed)|null  $onOutput  Called with each chunk as it arrives,
     *                                                    e.g. to stream a build log live instead
     *                                                    of only writing it once the command ends.
     */
    public function run(array|string $commands, ?callable $onOutput = null): string
    {
        $output = [];

        foreach ((array) $commands as $command) {
            $output[] = trim($this->execWithRetry($command, $onOutput));
        }

        return implode("\n", $output);
    }

    private function execWithRetry(string $command, ?callable $onOutput): string
    {
        try {
            return (string) $this->ssh()->exec($command, $onOutput);
        } catch (\RuntimeException|\ErrorException $e) {
            $channelWasLeftOpen = str_contains($e->getMessage(), 'close the channel');
            $channelMapWasCleared = $e instanceof \ErrorException
                && str_contains($e->getMessage(), 'Undefined array key '.SSH2::CHANNEL_EXEC);

            if (! $channelWasLeftOpen && ! $channelMapWasCleared) {
                throw $e;
            }

            $this->disconnect();

            return (string) $this->ssh()->exec($command, $onOutput);
        }
    }

    /**
     * Exit status of the most recently executed command.
     */
    public function exitCode(): int|false
    {
        return $this->ssh?->getExitStatus() ?? false;
    }

    public function disconnect(): void
    {
        $this->ssh?->disconnect();
        $this->sftp?->disconnect();

        $this->ssh = null;
        $this->sftp = null;
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    private function ssh(): SSH2
    {
        return $this->ssh ??= $this->authenticate(new SSH2($this->host, $this->port, $this->timeout));
    }

    private function sftp(): SFTP
    {
        return $this->sftp ??= $this->authenticate(new SFTP($this->host, $this->port, $this->timeout));
    }

    private function authenticate(SSH2 $connection): SSH2
    {
        $credential = $this->privateKey !== null && $this->privateKey !== ''
            ? PublicKeyLoader::load($this->privateKey, $this->passphrase ?: false)
            : $this->password;

        if (! $connection->login($this->username, $credential)) {
            throw new RemoteException("SSH authentication failed for {$this->username}@{$this->host}");
        }

        $connection->setTimeout($this->timeout);

        return $connection;
    }
}
