<?php

namespace Tests\Unit;

use App\Exceptions\RemoteException;
use App\Services\Ssh\SshConnection;
use Mockery;
use phpseclib4\Crypt\Common\PrivateKey;
use phpseclib4\Crypt\RSA;
use phpseclib4\Net\SFTP;
use phpseclib4\Net\SSH2;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class SshConnectionTest extends TestCase
{
    public function test_sftp_uploads_and_permissions_use_the_updated_api(): void
    {
        $sftp = Mockery::mock(SFTP::class);
        $sftp->shouldReceive('put')->once()->with('/remote/text', 'content');
        $sftp->shouldReceive('put')->once()->with('/remote/file', __FILE__, SFTP::SOURCE_LOCAL_FILE);
        $sftp->shouldReceive('chmod')->once()->with('/remote/text', 0600);
        $sftp->shouldReceive('disconnect')->zeroOrMoreTimes();

        $connection = new SshConnection('example.test', 22, 'runner');
        (new ReflectionProperty(SshConnection::class, 'sftp'))->setValue($connection, $sftp);

        $connection->putString('/remote/text', 'content')
            ->putFile('/remote/file', __FILE__)
            ->chmod(0600, '/remote/text')
            ->disconnect();
    }

    public function test_ssh_output_callback_accepts_non_closure_callables(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('exec')->once()->with('echo test', Mockery::type(\Closure::class))
            ->andReturnUsing(function (string $command, \Closure $callback): ?string {
                $callback('test');

                return null;
            });
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();

        $connection = new SshConnection('example.test', 22, 'runner');
        (new ReflectionProperty(SshConnection::class, 'ssh'))->setValue($connection, $ssh);
        $output = new class
        {
            public string $value = '';

            public function append(string $chunk): void
            {
                $this->value .= $chunk;
            }
        };

        $connection->run('echo test', [$output, 'append']);

        $this->assertSame('test', $output->value);
        $connection->disconnect();
    }

    public function test_authentication_loads_a_private_key_without_a_passphrase(): void
    {
        $key = RSA::createKey(1024)->toString('PKCS8');
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('login')->once()->with('runner', Mockery::type(PrivateKey::class))->andReturnTrue();
        $ssh->shouldReceive('setTimeout')->once()->with(300);
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();

        $connection = new SshConnection('example.test', 22, 'runner', privateKey: $key);
        $authenticated = (new ReflectionMethod(SshConnection::class, 'authenticate'))->invoke($connection, $ssh);

        $this->assertSame($ssh, $authenticated);
    }

    public function test_authentication_reports_missing_credentials(): void
    {
        $ssh = Mockery::mock(SSH2::class);
        $ssh->shouldReceive('disconnect')->zeroOrMoreTimes();
        $connection = new SshConnection('example.test', 22, 'runner');

        $this->expectException(RemoteException::class);
        $this->expectExceptionMessage('SSH authentication material is missing');

        (new ReflectionMethod(SshConnection::class, 'authenticate'))->invoke($connection, $ssh);
    }
}
