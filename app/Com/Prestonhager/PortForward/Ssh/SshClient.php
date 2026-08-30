<?php

namespace Pterodactyl\BlueprintFramework\Extensions\portforward\Com\Prestonhager\PortForward\Ssh;

use Pterodactyl\BlueprintFramework\Extensions\portforward\Com\Prestonhager\PortForward\Support\Config;
use Pterodactyl\BlueprintFramework\Extensions\portforward\Compatibility\PluginException;
use Symfony\Component\Process\Process;

class SshClient
{
    public function __construct(
        private readonly Config $config,
    ) {
    }

    /**
     * @param string[] $commands
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function exec(array $commands): array
    {
        if ($this->config->dryRun()) {
            return [
                'stdout' => "DRY-RUN: would execute:\n" . implode("\n", $commands),
                'stderr' => '',
                'exitCode' => 0,
            ];
        }

        $keyPath = $this->config->sshKeyPath();
        if ($keyPath === '') {
            throw new PluginException('Router SSH private key is not configured.');
        }

        // Use LF-only line endings for Cisco IOS compatibility
        $remoteScript = implode("\n", $commands) . "\n";
        $sshConfig = $this->config->sshConfigPath();
        $connectHost = $this->config->sshConnectHost();
        $connectUser = $this->config->routerSshUser();
        $useAlias = $connectHost !== $this->config->routerHost();

        $base = ['ssh', '-T', '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new'];

        if ($sshConfig !== '') {
            $base[] = '-F';
            $base[] = $sshConfig;
        }

        if (!$useAlias || $sshConfig === '') {
            $base[] = '-i';
            $base[] = $keyPath;
        }

        foreach ($this->config->sshOptions() as $option) {
            $base[] = '-o';
            $base[] = $option;
        }

        $base[] = $useAlias ? $connectHost : sprintf('%s@%s', $connectUser, $connectHost);
        // Pass script via stdin to ensure LF-only line endings
        $base[] = 'bash -s';

        $process = new Process($base, timeout: 60, input: $remoteScript);
        $process->run();

        return [
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'exitCode' => $process->getExitCode() ?? 1,
        ];
    }

    public function testConnection(): array
    {
        return $this->exec(['show ip interface brief']);
    }
}