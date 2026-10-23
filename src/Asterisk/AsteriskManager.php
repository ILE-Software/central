<?php

namespace Central\Asterisk;

use Central\Support\Config;

class AsteriskManager
{
    public function originate(array $headers, string $secret): array
    {
        $cfg = Config::asterisk();
        $socket = @stream_socket_client('tcp://'.$cfg['host'].':'.$cfg['port'], $errno, $error, 3, STREAM_CLIENT_CONNECT);
        if (!$socket) {
            return ['status' => 'failed', 'error_code' => 'voice_bridge_unavailable'];
        }
        $sent = false;
        try {
            stream_set_timeout($socket, 38);
            if (!str_starts_with((string) fgets($socket, 1024), 'Asterisk Call Manager/')) {
                throw new \RuntimeException('ami_protocol');
            }
            $this->write($socket, [
                ['Action', 'Login'],
                ['ActionID', 'login'],
                ['Username', $cfg['user']],
                ['Secret', $secret],
                ['Events', 'off'],
            ]);
            if (($this->response($socket, 'login')['Response'] ?? '') !== 'Success') {
                return ['status' => 'failed', 'error_code' => 'voice_bridge_authentication'];
            }
            $sent = true;
            $this->write($socket, $headers);
            $result = $this->response($socket, 'originate');

            return ($result['Response'] ?? '') === 'Success'
                ? ['status' => 'submitted', 'provider_id' => 'asterisk:'.$headers[2][1], 'error_code' => null]
                : ['status' => 'failed', 'error_code' => 'ami_originate_failed'];
        } catch (\Throwable) {
            return ['status' => $sent ? 'unknown' : 'failed', 'error_code' => $sent ? 'provider_result_uncertain' : 'voice_bridge_unavailable'];
        } finally {
            fclose($socket);
        }
    }

    private function write($socket, array $headers): void
    {
        $data = '';
        foreach ($headers as [$name, $value]) {
            if (preg_match('/[\r\n]/', (string) $value)) {
                throw new \InvalidArgumentException('invalid_ami_value');
            }
            $data .= $name.': '.$value."\r\n";
        }
        $data .= "\r\n";
        while ($data !== '') {
            $count = fwrite($socket, $data);
            if (!$count) {
                throw new \RuntimeException('ami_write');
            }
            $data = substr($data, $count);
        }
    }

    private function response($socket, string $id): array
    {
        $deadline = microtime(true) + 38;
        $frame = [];
        $bytes = 0;
        while (microtime(true) < $deadline) {
            $line = fgets($socket, 8192);
            if ($line === false) {
                throw new \RuntimeException('ami_read');
            }
            if (($bytes += strlen($line)) > 65536) {
                throw new \RuntimeException('ami_limit');
            }
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                if (($frame['ActionID'] ?? '') === $id && isset($frame['Response'])) {
                    return $frame;
                }
                $frame = [];
                continue;
            }
            if (str_contains($line, ':')) {
                [$key, $value] = explode(':', $line, 2);
                $frame[$key] = ltrim($value);
            }
        }
        throw new \RuntimeException('ami_timeout');
    }
}
