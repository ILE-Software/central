<?php

namespace Central\Support;

class Config
{
    public static function appKey(): string
    {
        return (string) Env::get('APP_KEY', 'central-dev-key-change-me');
    }

    public static function timezone(): string
    {
        return (string) Env::get('TIMEZONE', 'Europe/Istanbul');
    }

    public static function openai(): array
    {
        return [
            'api_key' => Env::get('OPENAI_API_KEY', ''),
            'project_id' => Env::get('OPENAI_PROJECT_ID', ''),
            'webhook_secret' => Env::get('OPENAI_WEBHOOK_SECRET', ''),
            'model' => Env::get('OPENAI_REALTIME_MODEL', 'gpt-realtime'),
            'voice' => Env::get('OPENAI_VOICE', 'marin'),
        ];
    }

    public static function phone(): array
    {
        return [
            'incoming_enabled' => Env::bool('INCOMING_ENABLED', true),
            'outbound_enabled' => Env::bool('OUTBOUND_ENABLED', false),
            'outbound_driver' => Env::get('OUTBOUND_DRIVER', 'netgsm'),
            'outbound_verified' => Env::bool('OUTBOUND_VERIFIED', false),
            'dtmf_enabled' => Env::bool('DTMF_ENABLED', true),
            'idle_seconds' => Env::int('IDLE_SECONDS', 90),
            'max_minutes' => Env::int('MAX_MINUTES', 15),
            'daily_limit' => Env::int('DAILY_LIMIT', 20),
            'outbound_hours_enabled' => Env::bool('OUTBOUND_HOURS_ENABLED', true),
            'outbound_start' => Env::get('OUTBOUND_START', '09:00'),
            'outbound_end' => Env::get('OUTBOUND_END', '18:00'),
            'greeting' => Env::get('GREETING', 'Merhaba, ben yapay zeka santral asistanıyım.'),
            'transfer_extension' => Env::get('TRANSFER_EXTENSION', '101'),
            'ai_extension' => Env::get('AI_EXTENSION', '100'),
            'central_token' => Env::get('CENTRAL_TOKEN', ''),
        ];
    }

    public static function netgsm(): array
    {
        return [
            'subscriber' => Env::get('NETGSM_SUBSCRIBER', ''),
            'username' => Env::get('NETGSM_USERNAME', ''),
            'password' => Env::get('NETGSM_PASSWORD', ''),
            'pbx_name' => Env::get('NETGSM_PBX_NAME', ''),
            'webhook_token' => Env::get('NETGSM_WEBHOOK_TOKEN', ''),
        ];
    }

    public static function asterisk(): array
    {
        return [
            'host' => Env::get('ASTERISK_AMI_HOST', '127.0.0.1'),
            'port' => Env::int('ASTERISK_AMI_PORT', 5038),
            'user' => Env::get('ASTERISK_AMI_USER', 'central'),
            'secret' => Env::get('ASTERISK_AMI_SECRET', ''),
            'outbound_endpoint' => Env::get('ASTERISK_OUTBOUND_ENDPOINT', 'netgsm-outbound'),
        ];
    }
}
