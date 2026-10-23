<?php

namespace Central\Netgsm;

use Central\Phone\PhonePolicy;
use Central\Support\Config;
use Central\Support\Json;

class NetgsmDialer
{
    public function __construct(private $http = null)
    {
    }

    public function dial(array $call): array
    {
        $policy = new PhonePolicy();
        if ($policy->outboundBlocker()) {
            return ['status' => 'failed', 'error_code' => 'phone_policy_blocked'];
        }
        if ((Config::phone()['outbound_driver'] ?? 'netgsm') === 'asterisk') {
            return (new \Central\Asterisk\AsteriskDialer())->dial($call);
        }

        $phone = Config::phone();
        $netgsm = Config::netgsm();
        $number = $netgsm['subscriber'];
        if (!preg_match('/^[1-9][0-9]{9}$/', $number) || !preg_match('/^[0-9]{1,10}$/', $phone['ai_extension'])) {
            return ['status' => 'failed', 'error_code' => 'invalid_pbx_config'];
        }
        $destination = $call['contact_phone'] ?? '';
        if (!preg_match('/^90[1-9][0-9]{9}$/', $destination)) {
            return ['status' => 'failed', 'error_code' => 'unsupported_destination'];
        }

        $query = http_build_query([
            'username' => $netgsm['username'],
            'password' => $netgsm['password'],
            'customer_num' => '0'.substr($destination, 2),
            'pbxnum' => $netgsm['pbx_name'],
            'internal_num' => $phone['ai_extension'],
            'ring_timeout' => 30,
            'crm_id' => $call['request_key'],
            'wait_response' => 0,
            'originate_order' => 'of',
            'trunk' => $number,
            'call_time' => 60 * (int) $phone['max_minutes'],
        ]);
        $url = 'https://crmsntrl.netgsm.com.tr/'.$number.'/originate?'.$query;

        try {
            $body = $this->get($url);
        } catch (\Throwable) {
            return ['status' => 'unknown', 'error_code' => 'provider_result_uncertain'];
        }
        if (!is_array($body)) {
            return ['status' => 'unknown', 'error_code' => 'provider_response_uncertain'];
        }
        if (isset($body['crm_id']) && (string) $body['crm_id'] !== $call['request_key']) {
            return ['status' => 'unknown', 'error_code' => 'provider_reference_mismatch'];
        }
        if (strtolower((string) ($body['status'] ?? '')) === 'error') {
            return ['status' => 'failed', 'error_code' => 'provider_rejected'];
        }
        if (strtolower((string) ($body['status'] ?? '')) !== 'success' || ($body['response'] ?? '') !== 'originate') {
            return ['status' => 'unknown', 'error_code' => 'provider_response_uncertain'];
        }
        $id = $body['unique_id'] ?? null;

        return [
            'status' => 'submitted',
            'provider_id' => is_scalar($id) ? mb_substr((string) $id, 0, 190) : null,
            'error_code' => null,
        ];
    }

    private function get(string $url): mixed
    {
        if (is_callable($this->http)) {
            return ($this->http)($url);
        }
        $context = stream_context_create([
            'http' => ['method' => 'GET', 'timeout' => 20, 'follow_location' => 0, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            throw new \RuntimeException('provider_http');
        }

        return Json::decode($raw);
    }
}
