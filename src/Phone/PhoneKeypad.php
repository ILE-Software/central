<?php

namespace Central\Phone;

class PhoneKeypad
{
    public function text(string $digit): ?string
    {
        return match ($digit) {
            '1' => 'Bilgi almak istiyorum.',
            '2' => 'Randevu veya işlem yapmak istiyorum.',
            '0' => 'Bir personelle görüşmek istiyorum.',
            default => null,
        };
    }

    public function menu(): string
    {
        return 'Bilgi için 1, işlem için 2, personel için 0 tuşuna basabilir veya konuşarak devam edebilirsiniz.';
    }
}
