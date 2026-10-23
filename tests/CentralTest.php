<?php

use Central\Netgsm\NetgsmDialer;
use Central\OpenAi\RealtimeSocket;
use Central\OpenAi\WebhookVerifier;
use Central\Phone\ContactPhone;
use Central\Phone\PhoneCallEnding;
use Central\Phone\PhoneKeypad;
use Central\Phone\PhoneOutboundBinding;
use Central\Support\Env;
use PHPUnit\Framework\TestCase;

class CentralTest extends TestCase
{
    protected function setUp(): void
    {
        putenv('APP_KEY=test-app-key');
        $_ENV['APP_KEY'] = 'test-app-key';
        putenv('OUTBOUND_ENABLED=true');
        $_ENV['OUTBOUND_ENABLED'] = 'true';
        putenv('OUTBOUND_VERIFIED=true');
        $_ENV['OUTBOUND_VERIFIED'] = 'true';
        putenv('OUTBOUND_HOURS_ENABLED=false');
        $_ENV['OUTBOUND_HOURS_ENABLED'] = 'false';
        putenv('OPENAI_API_KEY=sk-test');
        $_ENV['OPENAI_API_KEY'] = 'sk-test';
        putenv('OPENAI_WEBHOOK_SECRET=test-secret');
        $_ENV['OPENAI_WEBHOOK_SECRET'] = 'test-secret';
        putenv('NETGSM_SUBSCRIBER=8501234567');
        $_ENV['NETGSM_SUBSCRIBER'] = '8501234567';
        putenv('NETGSM_USERNAME=8501234567');
        $_ENV['NETGSM_USERNAME'] = '8501234567';
        putenv('NETGSM_PASSWORD=private-password');
        $_ENV['NETGSM_PASSWORD'] = 'private-password';
        putenv('NETGSM_PBX_NAME=8501234567');
        $_ENV['NETGSM_PBX_NAME'] = '8501234567';
        putenv('AI_EXTENSION=100');
        $_ENV['AI_EXTENSION'] = '100';
        putenv('OUTBOUND_DRIVER=netgsm');
        $_ENV['OUTBOUND_DRIVER'] = 'netgsm';
    }

    public function test_phone_normalize(): void
    {
        $this->assertSame('905551112233', ContactPhone::normalize('+90 (555) 111 22 33'));
        $this->assertSame('905551112233', ContactPhone::normalize('05551112233'));
    }

    public function test_keypad_and_ending(): void
    {
        $pad = new PhoneKeypad();
        $this->assertSame('Bir personelle görüşmek istiyorum.', $pad->text('0'));
        $this->assertNull($pad->text('9'));
        $this->assertTrue((new PhoneCallEnding())->requested('kapatabiliriz'));
        $this->assertFalse((new PhoneCallEnding())->requested('bilgi almak istiyorum'));
    }

    public function test_outbound_binding_requires_hmac(): void
    {
        $call = ['request_key' => 'abc', 'contact_phone' => '905551112233', 'status' => 'submitted'];
        $headers = (new PhoneOutboundBinding())->headers($call);
        $found = (new PhoneOutboundBinding())->find([
            ['name' => 'X-Central-Call-Key', 'value' => $headers['X-Central-Call-Key']],
            ['name' => 'X-Central-Call-Token', 'value' => $headers['X-Central-Call-Token']],
        ], [$call]);
        $this->assertSame('abc', $found['request_key']);
        $bad = (new PhoneOutboundBinding())->find([
            ['name' => 'X-Central-Call-Key', 'value' => 'abc'],
            ['name' => 'X-Central-Call-Token', 'value' => 'wrong'],
        ], [$call]);
        $this->assertNull($bad);
    }

    public function test_webhook_signature(): void
    {
        $id = 'wh_1';
        $timestamp = (string) time();
        $body = '{"type":"realtime.call.incoming"}';
        $secret = 'test-secret';
        $sig = 'v1,'.base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $secret, true));
        (new WebhookVerifier())->verify($id, $timestamp, $sig, $body, $secret);
        $this->expectException(\RuntimeException::class);
        (new WebhookVerifier())->verify($id, $timestamp, 'v1,aaaa', $body, $secret);
    }

    public function test_netgsm_originate_is_submitted_not_answered(): void
    {
        $call = ['request_key' => 'crm-1', 'contact_phone' => '905551112233'];
        $dialer = new NetgsmDialer(function (string $url) use ($call) {
            $this->assertStringContainsString('customer_num=05551112233', $url);
            $this->assertStringContainsString('wait_response=0', $url);
            $this->assertStringContainsString('originate_order=of', $url);
            $this->assertStringContainsString('internal_num=100', $url);

            return ['response' => 'originate', 'crm_id' => $call['request_key'], 'status' => 'Success', 'unique_id' => '123.456'];
        });
        $result = $dialer->dial($call);
        $this->assertSame('submitted', $result['status']);
        $this->assertSame('123.456', $result['provider_id']);
    }

    public function test_realtime_socket_masks_payload(): void
    {
        $listener = stream_socket_server('tcp://127.0.0.1:0');
        $client = stream_socket_client('tcp://'.stream_socket_get_name($listener, false));
        $peer = stream_socket_accept($listener, 2);
        fclose($listener);
        $socket = new RealtimeSocket();
        (new ReflectionProperty($socket, 'stream'))->setValue($socket, $client);
        try {
            $event = ['type' => 'test', 'text' => 'Türkçe'];
            $socket->send($event);
            $h = fread($peer, 2);
            $this->assertSame(129, ord($h[0]));
            $this->assertSame(0x80 | strlen(json_encode($event, JSON_UNESCAPED_UNICODE)), ord($h[1]));
        } finally {
            $socket->close();
            fclose($peer);
        }
    }
}
