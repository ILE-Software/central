<?php

namespace Central\Phone;

use Central\Support\Config;

class PhoneOutboundBinding
{
    public function headers(array $call): array
    {
        return [
            'X-Central-Call-Key' => $call['request_key'],
            'X-Central-Call-Token' => hash_hmac('sha256', $call['request_key'].':'.$call['contact_phone'], Config::appKey()),
        ];
    }

    public function find(array $sipHeaders, array $calls): ?array
    {
        $headers = [];
        foreach ($sipHeaders as $header) {
            $headers[strtolower($header['name'] ?? '')] = (string) ($header['value'] ?? '');
        }
        if (!isset($headers['x-central-call-key'], $headers['x-central-call-token'])) {
            return null;
        }

        foreach ($calls as $call) {
            if (($call['request_key'] ?? '') !== $headers['x-central-call-key']) {
                continue;
            }
            if (!in_array($call['status'] ?? '', ['dispatching', 'submitted', 'unknown'], true)) {
                return null;
            }
            $expected = $this->headers($call)['X-Central-Call-Token'];
            if (!hash_equals($expected, $headers['x-central-call-token'])) {
                return null;
            }

            return $call;
        }

        return null;
    }
}
