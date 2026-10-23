<?php

namespace Central\Http\Controllers;

use Central\Http\Request;
use Central\Http\Response;
use Central\Phone\ContactPhone;
use Central\Phone\OutboundDispatcher;
use Central\Phone\PhoneOutboundBinding;
use Central\Support\Config;
use Central\Support\Store;

class OriginateController
{
    public function __construct(private Store $store)
    {
    }

    public function create(Request $request): Response
    {
        $token = Config::phone()['central_token'];
        if (!$token || !hash_equals($token, $request->header('x-central-token'))) {
            return Response::json(['error' => 'unauthorized'], 401);
        }
        $data = $request->json();
        $phone = ContactPhone::normalize($data['phone'] ?? null);
        if (!$phone) {
            return Response::json(['error' => 'invalid_phone'], 422);
        }
        $id = $data['request_key'] ?? $this->uuid();
        $call = [
            'id' => $id,
            'request_key' => $id,
            'contact_phone' => $phone,
            'purpose' => mb_substr((string) ($data['purpose'] ?? 'callback'), 0, 200),
            'status' => 'draft',
        ];
        $this->store->put('calls', $id, $call);
        try {
            $started = (new OutboundDispatcher($this->store))->start($call);
        } catch (\RuntimeException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $started['sip_headers'] = (new PhoneOutboundBinding())->headers($started);
        unset($started['sip_headers']); // do not echo tokens in HTTP by default
        return Response::json(['call' => [
            'id' => $started['id'],
            'status' => $started['status'],
            'provider_id' => $started['provider_id'] ?? null,
            'error_code' => $started['error_code'] ?? null,
        ]]);
    }

    public function show(string $id): Response
    {
        $call = $this->store->get('calls', $id);
        if (!$call) {
            return Response::json(['error' => 'not_found'], 404);
        }
        unset($call['sip_headers']);

        return Response::json(['call' => $call]);
    }

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
