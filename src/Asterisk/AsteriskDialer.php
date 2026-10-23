<?php

namespace Central\Asterisk;

use Central\Phone\PhoneOutboundBinding;
use Central\Support\Config;

class AsteriskDialer
{
    public function __construct(private ?AsteriskManager $manager = null)
    {
        $this->manager ??= new AsteriskManager();
    }

    public function dial(array $call): array
    {
        $secret = Config::asterisk()['secret'];
        $phone = Config::phone();
        if (!preg_match('/^90[1-9][0-9]{9}$/', $call['contact_phone'] ?? '') || !preg_match('/^[a-f0-9-]{36}$/i', $call['request_key'] ?? '')) {
            return ['status' => 'failed', 'error_code' => 'unsupported_destination'];
        }
        if (!$secret || preg_match('/[\r\n]/', $secret)) {
            return ['status' => 'failed', 'error_code' => 'voice_bridge_authentication'];
        }
        $signed = (new PhoneOutboundBinding())->headers($call);
        $endpoint = Config::asterisk()['outbound_endpoint'];
        $headers = [
            ['Action', 'Originate'],
            ['ActionID', 'originate'],
            ['ChannelId', $call['request_key']],
            ['Channel', 'PJSIP/0'.substr($call['contact_phone'], 2).'@'.$endpoint],
            ['Context', 'central-outbound'],
            ['Exten', 's'],
            ['Priority', '1'],
            ['Timeout', '30000'],
            ['Async', 'false'],
            ['Codecs', 'ulaw,alaw'],
            ['Variable', '__CENTRAL_CALL_KEY='.$signed['X-Central-Call-Key']],
            ['Variable', '__CENTRAL_CALL_TOKEN='.$signed['X-Central-Call-Token']],
            ['Variable', '__CENTRAL_DESTINATION='.$call['contact_phone']],
            ['Variable', 'TIMEOUT(absolute)='.(60 * (int) $phone['max_minutes'])],
        ];

        return $this->manager->originate($headers, $secret);
    }
}
