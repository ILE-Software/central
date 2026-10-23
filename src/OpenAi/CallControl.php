<?php

namespace Central\OpenAi;

use Central\Support\Config;
use Central\Support\Json;

class CallControl
{
    public function send(string $callId, string $action, array $payload = []): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{1,190}$/', $callId) || !in_array($action, ['accept', 'reject', 'refer', 'hangup'], true)) {
            throw new \InvalidArgumentException('invalid_call_action');
        }
        $key = Config::openai()['api_key'];
        if ($key === '') {
            return false;
        }
        $url = 'https://api.openai.com/v1/realtime/calls/'.rawurlencode($callId).'/'.$action;
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Authorization: Bearer {$key}\r\nContent-Type: application/json\r\n",
                'content' => Json::encode($payload),
                'timeout' => 15,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $result = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/^HTTP\/\S+\s+(\d+)/', $line, $match)) {
                $status = (int) $match[1];
            }
        }

        return $result !== false && $status >= 200 && $status < 300;
    }

    public function session(): array
    {
        $openai = Config::openai();
        $phone = Config::phone();
        $ending = new \Central\Phone\PhoneCallEnding();
        $keypad = new \Central\Phone\PhoneKeypad();

        return [
            'type' => 'realtime',
            'model' => $openai['model'],
            'instructions' => 'Telefon görüşmesindesin. Türkçe ve doğal konuş. Yanıtlarını JSON olarak okuma. Kısa cümleler kullan. Yapay zeka olduğunu söyle. '.$keypad->menu(),
            'tools' => array_merge($ending->definitions(), [$ending->transferDefinition()]),
            'tool_choice' => 'auto',
            'audio' => [
                'input' => [
                    'noise_reduction' => ['type' => 'near_field'],
                    'transcription' => ['model' => 'gpt-4o-mini-transcribe', 'language' => 'tr'],
                    'turn_detection' => null,
                ],
                'output' => ['voice' => $openai['voice']],
            ],
        ];
    }

    public function turnDetection(): array
    {
        return [
            'type' => 'server_vad',
            'threshold' => 0.7,
            'prefix_padding_ms' => 300,
            'silence_duration_ms' => 650,
            'create_response' => false,
            'interrupt_response' => true,
        ];
    }
}
