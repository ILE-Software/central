<?php

namespace Central\Http\Controllers;

use Central\Http\Request;
use Central\Http\Response;
use Central\OpenAi\WebhookVerifier;
use Central\Phone\WorkerProcess;
use Central\Support\Config;
use Central\Support\Store;

class WebhookController
{
    public function __construct(private Store $store, private string $root)
    {
    }

    public function openai(Request $request): Response
    {
        if (strlen($request->body) > 262144) {
            return Response::json(['error' => 'payload_too_large'], 413);
        }
        try {
            (new WebhookVerifier())->verify(
                $request->header('webhook-id'),
                $request->header('webhook-timestamp'),
                $request->header('webhook-signature'),
                $request->body,
                Config::openai()['webhook_secret']
            );
        } catch (\RuntimeException) {
            return Response::json(['error' => 'unauthorized'], 403);
        }
        $data = $request->json();
        if (($data['type'] ?? '') !== 'realtime.call.incoming') {
            return Response::json(['received' => true]);
        }
        $id = $data['data']['call_id'] ?? '';
        if (!is_string($id) || !preg_match('/^[a-zA-Z0-9_-]{1,190}$/', $id)) {
            return Response::json(['error' => 'invalid_call'], 422);
        }
        $existing = $this->store->get('queue', $id);
        if (!$existing) {
            $this->store->put('queue', $id, [
                'external_id' => $id,
                'payload' => $data,
                'status' => 'queued',
            ]);
            WorkerProcess::spawn($this->root, $id);
        }

        return Response::json(['received' => true]);
    }

    public function netgsm(Request $request): Response
    {
        $expected = Config::netgsm()['webhook_token'];
        $provided = $request->header('x-telephony-token') ?: (string) ($request->json()['webhook_token'] ?? '');
        if (!$expected || !hash_equals($expected, $provided)) {
            return Response::json(['status' => 'error', 'message' => 'unauthorized'], 401);
        }
        $payload = $request->json() ?: $request->query;
        $arama = (string) ($payload['arama_id'] ?? '');
        if ($arama === '') {
            return Response::json(['error' => 'arama_id required'], 422);
        }
        $external = $arama.':'.($payload['event'] ?? $payload['durum'] ?? 'call');
        $this->store->put('queue', substr(hash('sha256', $external), 0, 24), [
            'external_id' => $external,
            'channel' => 'netgsm',
            'payload' => $payload,
            'status' => 'completed',
        ]);

        return Response::json(['received' => true]);
    }

    public function netsipp(Request $request): Response
    {
        $expected = Config::netgsm()['webhook_token'];
        $provided = $request->header('x-telephony-token');
        if (!$expected || !hash_equals($expected, $provided)) {
            return Response::json(['status' => 'error', 'message' => 'unauthorized'], 401);
        }

        return Response::json(['status' => 'success']);
    }
}
