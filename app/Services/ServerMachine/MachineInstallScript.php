<?php
namespace App\Services\ServerMachine;

use App\Models\ServerMachine;

class MachineInstallScript
{
    public function render(string $baseURL, ServerMachine $machine, string $agent): string
    {
        if (!in_array($agent, ['kelinode', 'kelinode-rs'], true)) throw new \InvalidArgumentException('invalid_agent');
        $root = base_path('resources/server-machine');
        $distribution = app(MachineReleaseDistributionService::class);
        return strtr((string) file_get_contents($root . '/install-script.sh'), [
            '{{APT_DEPENDENCY_HELPER}}' => (string) file_get_contents($root . '/apt-dependencies.sh'),
            '{{AGENT_TYPE}}' => $this->bash($agent), '{{MACHINE_URL}}' => $this->bash($baseURL),
            '{{MACHINE_ID}}' => $this->bash((string) (int) $machine->id), '{{MACHINE_TOKEN}}' => $this->bash((string) $machine->token),
            '{{MACHINE_NAME}}' => $this->bash($machine->name ?: ('machine-' . $machine->id)),
            '{{KELINODE_INSTALL_SCRIPT_URL}}' => $this->bash('https://raw.githubusercontent.com/keli-123456/kelinode/main/script/install.sh'),
            '{{KELINODE_RS_INSTALL_SCRIPT_URL}}' => $this->bash($distribution->installScriptUrl($baseURL)),
            '{{KELINODE_RS_RELEASE_BASE_URL}}' => $this->bash($distribution->releaseBaseUrl($baseURL)),
        ]);
    }

    private function bash(string $value): string
    {
        return '"' . str_replace(["\\", '"', '$', '`', "\r", "\n"], ["\\\\", '\\"', '\\$', '\\`', '', ''], $value) . '"';
    }
}
