<?php

namespace App\Services\ServerMachine;

use phpseclib3\Common\Functions\Strings;
use phpseclib3\Net\SSH2;

class InteractiveSshConnection extends SSH2
{
    public function resizePty(int $columns, int $rows): void
    {
        $this->setWindowSize($columns, $rows);
        if ($this->isShellOpen()) {
            // phpseclib 3's setter only affects new PTYs. RFC 4254 section 6.7
            // requires a window-change request to resize an existing shell.
            $this->send_binary_packet(Strings::packSSH2('CNsbN4', NET_SSH2_MSG_CHANNEL_REQUEST,
                $this->server_channels[self::CHANNEL_SHELL], 'window-change', false, $columns, $rows, 0, 0));
        }
    }
}
