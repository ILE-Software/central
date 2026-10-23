<?php

namespace Central\OpenAi;

class RealtimeSocket
{
    private $stream;
    private string $fragments = '';
    private int $fragmentOpcode = 0;

    public function connect(string $callId, string $key): void
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,190}$/', $callId)) {
            throw new \RuntimeException('invalid_call');
        }
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => 'api.openai.com',
                'SNI_enabled' => true,
            ],
        ]);
        $this->stream = @stream_socket_client('tls://api.openai.com:443', $errno, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$this->stream) {
            throw new \RuntimeException('socket_connection');
        }
        stream_set_timeout($this->stream, 15);
        $nonce = base64_encode(random_bytes(16));
        $request = "GET /v1/realtime?call_id=".rawurlencode($callId)." HTTP/1.1\r\nHost: api.openai.com\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: $nonce\r\nSec-WebSocket-Version: 13\r\nAuthorization: Bearer $key\r\n\r\n";
        $this->write($request);
        $headers = '';
        while (!str_ends_with($headers, "\r\n\r\n")) {
            if (strlen($headers) > 16384) {
                throw new \RuntimeException('socket_handshake');
            }
            $headers .= $this->read(1);
        }
        $accept = base64_encode(sha1($nonce.'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (!preg_match('/^HTTP\/1\.[01] 101\b/', $headers) || !preg_match('/Sec-WebSocket-Accept:\s*'.preg_quote($accept, '/').'\s*\r\n/i', $headers)) {
            throw new \RuntimeException('socket_handshake');
        }
    }

    public function send(array $event): void
    {
        $this->frame(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 1);
    }

    public function receive(): ?array
    {
        $read = [$this->stream];
        $write = $except = [];
        if (stream_select($read, $write, $except, 1) === 0) {
            return null;
        }
        $header = $this->read(2);
        $fin = (ord($header[0]) & 128) !== 0;
        $opcode = ord($header[0]) & 15;
        $length = ord($header[1]) & 127;
        if (ord($header[1]) & 128) {
            throw new \RuntimeException('masked_server_frame');
        }
        if ($length === 126) {
            $length = unpack('n', $this->read(2))[1];
        } elseif ($length === 127) {
            $parts = unpack('Nhigh/Nlow', $this->read(8));
            if ($parts['high']) {
                throw new \RuntimeException('frame_limit');
            }
            $length = $parts['low'];
        }
        if ($length > 2097152) {
            throw new \RuntimeException('frame_limit');
        }
        $payload = $length ? $this->read($length) : '';
        if ($opcode === 8) {
            throw new \RuntimeException('socket_closed');
        }
        if ($opcode === 9) {
            $this->frame($payload, 10);

            return null;
        }
        if ($opcode === 10) {
            return null;
        }
        if ($opcode === 0) {
            $this->fragments .= $payload;
            if (strlen($this->fragments) > 2097152) {
                throw new \RuntimeException('frame_limit');
            }
            if (!$fin) {
                return null;
            }
            $payload = $this->fragments;
            $opcode = $this->fragmentOpcode;
            $this->fragments = '';
            $this->fragmentOpcode = 0;
        } elseif (!$fin) {
            $this->fragments = $payload;
            $this->fragmentOpcode = $opcode;

            return null;
        }
        if ($opcode !== 1) {
            return null;
        }
        $event = json_decode($payload, true);

        return is_array($event) ? $event : null;
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function write(string $bytes): void
    {
        while (strlen($bytes)) {
            $written = fwrite($this->stream, $bytes);
            if (!$written) {
                throw new \RuntimeException('socket_write');
            }
            $bytes = substr($bytes, $written);
        }
    }

    private function read(int $length): string
    {
        $buffer = '';
        while (strlen($buffer) < $length) {
            $part = fread($this->stream, $length - strlen($buffer));
            if ($part === false || $part === '') {
                throw new \RuntimeException(feof($this->stream) ? 'socket_closed' : 'socket_timeout');
            }
            $buffer .= $part;
        }

        return $buffer;
    }

    private function frame(string $payload, int $opcode): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        $header .= $length < 126
            ? chr(0x80 | $length)
            : ($length <= 65535 ? chr(0x80 | 126).pack('n', $length) : chr(0x80 | 127).pack('NN', 0, $length));
        $mask = random_bytes(4);
        $masked = $payload;
        for ($i = 0; $i < $length; $i++) {
            $masked[$i] = $payload[$i] ^ $mask[$i % 4];
        }
        $this->write($header.$mask.$masked);
    }
}
