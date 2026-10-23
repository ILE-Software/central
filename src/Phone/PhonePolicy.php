<?php

namespace Central\Phone;

use Central\Support\Config;

class PhonePolicy
{
    public function config(): array
    {
        return Config::phone();
    }

    public function outboundBlocker(): ?string
    {
        $phone = Config::phone();
        $openai = Config::openai();
        $netgsm = Config::netgsm();
        $asterisk = Config::asterisk();

        if (!$phone['outbound_enabled']) {
            return 'Dış arama kapalı.';
        }
        if ($openai['api_key'] === '' || $openai['webhook_secret'] === '') {
            return 'OpenAI telefon bağlantı bilgileri eksik.';
        }
        if (($phone['outbound_driver'] ?? 'netgsm') === 'asterisk') {
            if ($asterisk['secret'] === '') {
                return 'Asterisk AMI şifresi eksik.';
            }
        } else {
            foreach (['subscriber', 'username', 'pbx_name'] as $key) {
                if ($netgsm[$key] === '') {
                    return 'Netgsm santral bilgileri eksik.';
                }
            }
            if ($phone['ai_extension'] === '' || $netgsm['password'] === '') {
                return 'Netgsm API şifresi veya AI dahili eksik.';
            }
        }
        if (!$phone['outbound_verified']) {
            return 'Gerçek hat testi henüz doğrulanmadı (OUTBOUND_VERIFIED=false).';
        }

        $timezone = new \DateTimeZone(Config::timezone());
        $time = (new \DateTimeImmutable('now', $timezone))->format('H:i');
        if ($phone['outbound_hours_enabled'] && ($time < $phone['outbound_start'] || $time >= $phone['outbound_end'])) {
            return 'İzin verilen dış arama saatlerinin dışındayız.';
        }

        return null;
    }
}
