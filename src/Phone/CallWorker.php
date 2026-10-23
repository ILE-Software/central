<?php

namespace Central\Phone;

use Central\OpenAi\CallControl;
use Central\OpenAi\RealtimeSocket;
use Central\Support\Config;
use Central\Support\Json;
use Central\Support\Store;

class CallWorker
{
    public function __construct(private Store $store)
    {
    }

    public function handle(string $eventId): void
    {
        $event = $this->store->get('queue', $eventId);
        if (!$event || ($event['status'] ?? '') !== 'queued') {
            return;
        }

        $gateway = new CallControl();
        $callId = $event['external_id'];
        $phone = Config::phone();
        $openai = Config::openai();
        $headers = $event['payload']['data']['sip_headers'] ?? [];
        $binding = new PhoneOutboundBinding();
        $outbound = $binding->find($headers, $this->store->all('calls'));
        $hasBinding = false;
        foreach ($headers as $header) {
            if (strtolower($header['name'] ?? '') === 'x-central-call-key') {
                $hasBinding = true;
            }
        }

        if ($hasBinding && !$outbound) {
            $gateway->send($callId, 'reject', ['status_code' => 403]);
            $this->store->update('queue', $eventId, ['status' => 'rejected', 'error_code' => 'invalid_outbound_binding']);

            return;
        }
        if ((!$outbound && !$phone['incoming_enabled']) || ($outbound && !$phone['outbound_enabled'])) {
            $gateway->send($callId, 'reject', ['status_code' => 486]);
            $this->store->update('queue', $eventId, ['status' => 'rejected', 'error_code' => 'call_direction_disabled']);

            return;
        }

        $from = '';
        foreach ($headers as $header) {
            if (strtolower($header['name'] ?? '') === 'from') {
                $from = $header['value'] ?? '';
            }
        }
        preg_match('/sips?:([+0-9]+)@/i', $from, $match);
        $record = [
            'request_key' => $outbound['request_key'] ?? $callId,
            'external_id' => $callId,
            'contact_phone' => $outbound['contact_phone'] ?? ContactPhone::normalize($match[1] ?? null),
            'direction' => $outbound ? 'outbound' : 'inbound',
            'status' => 'accepting',
            'purpose' => $outbound['purpose'] ?? null,
        ];
        $this->store->put('calls', $callId, $record);
        $this->store->update('queue', $eventId, ['status' => 'accepting']);

        if (!$gateway->send($callId, 'accept', $gateway->session())) {
            $gateway->send($callId, 'hangup');
            $this->store->update('queue', $eventId, ['status' => 'failed', 'error_code' => 'accept_failed']);
            $this->store->update('calls', $callId, ['status' => 'failed']);

            return;
        }

        $this->store->update('queue', $eventId, ['status' => 'accepted']);
        $this->store->update('calls', $callId, ['status' => 'active']);
        $this->listen($callId, $record, $gateway, $openai['api_key']);
    }

    private function listen(string $callId, array $record, CallControl $gateway, string $key): void
    {
        $socket = new RealtimeSocket();
        $clock = new PhoneClock();
        $ending = new PhoneCallEnding();
        $keypad = new PhoneKeypad();
        $responses = new PhoneResponseFlow();
        $phone = Config::phone();
        $deadline = $clock->now() + 60 * (int) $phone['max_minutes'];
        $greeting = $phone['greeting'];
        if ($record['direction'] === 'outbound') {
            $greeting = 'Merhaba, ben yapay zeka santral asistanıyım. Sizi arıyorum. Görüşmek için uygun musunuz?';
        }

        try {
            $socket->connect($callId, $key);
            $socket->send([
                'type' => 'session.update',
                'session' => [
                    'type' => 'realtime',
                    'instructions' => $gateway->session()['instructions'].($record['purpose'] ? ' Görüşme amacı: '.$record['purpose'] : ''),
                    'tools' => $gateway->session()['tools'],
                ],
            ]);
            $greetingPlaying = true;
            $responses->request($socket, '', 'Yalnızca şu karşılama metnini aynen söyle: '.Json::encode($greeting), 'greeting');
            $lastMessage = '';
            $lastId = '';
            $seenKeys = [];
            $seenTools = [];
            $lastActivity = $clock->now();
            $silenceWarned = false;

            while ($clock->now() < $deadline) {
                $event = $socket->receive();
                if (!$event) {
                    $idle = $clock->now() - $lastActivity;
                    if ($idle >= (int) $phone['idle_seconds'] + 20) {
                        $gateway->send($callId, 'hangup');
                        break;
                    }
                    if ($idle >= (int) $phone['idle_seconds'] && !$silenceWarned && !$responses->active()) {
                        $responses->request($socket, '', 'Müşteriye hâlâ hatta olup olmadığını kısa şekilde sor.');
                        $silenceWarned = true;
                    }
                    continue;
                }
                $type = $event['type'] ?? '';
                if ($type === 'response.created') {
                    $responses->created($event);
                    continue;
                }
                if ($type === 'response.done') {
                    $responses->done($socket, $event);
                    continue;
                }
                if ($type === 'output_audio_buffer.stopped') {
                    $lastActivity = $clock->now();
                    $tag = $responses->tag($event);
                    if ($tag === 'greeting' && $greetingPlaying) {
                        $socket->send(['type' => 'input_audio_buffer.clear']);
                        $socket->send(['type' => 'session.update', 'session' => ['type' => 'realtime', 'audio' => ['input' => ['turn_detection' => $gateway->turnDetection()]]]]);
                        $greetingPlaying = false;
                        continue;
                    }
                    if ($tag === 'end_call') {
                        $gateway->send($callId, 'hangup');
                        break;
                    }
                    continue;
                }
                if ($greetingPlaying && in_array($type, ['input_audio_buffer.speech_started', 'conversation.item.input_audio_transcription.completed'], true)) {
                    continue;
                }
                if ($type === 'input_audio_buffer.speech_started') {
                    $responses->customerStarted();
                    $lastActivity = $clock->now();
                    $silenceWarned = false;
                    $lastId = '';
                    $lastMessage = '';
                    continue;
                }
                if ($type === 'input_audio_buffer.dtmf_event_received') {
                    if (!$phone['dtmf_enabled']) {
                        continue;
                    }
                    $digit = (string) ($event['event'] ?? '');
                    $dtmfKey = $event['event_id'] ?? ($digit.':'.($event['received_at'] ?? ''));
                    if (!$dtmfKey || isset($seenKeys[$dtmfKey])) {
                        continue;
                    }
                    $seenKeys[$dtmfKey] = true;
                    $text = $keypad->text($digit);
                    if (!$text) {
                        continue;
                    }
                    $socket->send(['type' => 'conversation.item.create', 'item' => ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => $text]]]]);
                    $event = ['type' => 'conversation.item.input_audio_transcription.completed', 'item_id' => 'dtmf:'.$dtmfKey, 'transcript' => $text, 'dtmf' => $digit];
                    $type = $event['type'];
                }
                if ($type === 'conversation.item.input_audio_transcription.completed') {
                    $text = $event['transcript'] ?? '';
                    if (!$text) {
                        continue;
                    }
                    $lastMessage = $text;
                    $lastId = (string) ($event['item_id'] ?? uniqid('turn', true));
                    $lastActivity = $clock->now();
                    $silenceWarned = false;
                    $responses->request($socket, $lastId);
                } elseif ($type === 'response.function_call_arguments.done') {
                    if (isset($seenTools[$event['call_id'] ?? ''])) {
                        continue;
                    }
                    $seenTools[$event['call_id'] ?? ''] = true;
                    $name = $event['name'] ?? '';
                    $result = ['ok' => false, 'error' => 'Araç çağrısı geçersiz.'];
                    if ($lastId && $responses->permits($event, $lastId)) {
                        if ($name === 'end_phone_call') {
                            $result = ['ok' => $ending->requested($lastMessage), 'end_call' => true];
                        } elseif ($name === 'transfer_to_extension') {
                            $target = 'sip:'.$phone['transfer_extension'].'@pbx.local';
                            $ok = $gateway->send($callId, 'refer', ['target' => $target]);
                            $result = ['ok' => $ok, 'transferred' => $ok];
                            if ($ok) {
                                $this->store->update('calls', $callId, ['status' => 'transfer_requested']);
                            }
                        }
                    }
                    $socket->send([
                        'type' => 'conversation.item.create',
                        'item' => ['type' => 'function_call_output', 'call_id' => $event['call_id'], 'output' => Json::encode($result)],
                    ]);
                    if (($result['ok'] ?? false) && ($result['end_call'] ?? false)) {
                        $responses->request($socket, $lastId, 'Müşteriye kısa bir veda et. Soru sorma.', 'end_call');
                    } elseif (!($result['transferred'] ?? false)) {
                        $responses->request($socket, $lastId);
                    }
                } elseif ($type === 'session.closed') {
                    break;
                }
            }
        } finally {
            $socket->close();
            $current = $this->store->get('calls', $callId);
            if ($current && ($current['status'] ?? '') !== 'transfer_requested') {
                $this->store->update('calls', $callId, ['status' => 'ended']);
            }
        }
    }
}
