<?php

namespace Central\OpenAi;

class WebhookVerifier
{
    public function verify(string $id, string $timestamp, string $signatures, string $body, string $secret): void
    {
        if ($secret === '' || $id === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            throw new \RuntimeException('unauthorized');
        }
        $raw = str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret;
        $key = base64_decode($raw, true);
        if ($key === false) {
            $key = $raw;
        }
        $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
        $valid = false;
        foreach (explode(' ', $signatures) as $signature) {
            if (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3))) {
                $valid = true;
            }
        }
        if (!$valid) {
            throw new \RuntimeException('unauthorized');
        }
    }
}
