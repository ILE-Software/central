<?php

namespace Central\Phone;

class PhoneCallEnding
{
    public function definitions(): array
    {
        return [[
            'type' => 'function',
            'name' => 'end_phone_call',
            'description' => 'Müşteri açıkça görüşmeyi bitirmek istediğinde kısa vedanın ardından çağrıyı kapat.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
                'additionalProperties' => false,
            ],
        ]];
    }

    public function transferDefinition(): array
    {
        return [
            'type' => 'function',
            'name' => 'transfer_to_extension',
            'description' => 'Müşteri personel istediğinde SIP REFER ile dahiliye aktar.',
            'parameters' => [
                'type' => 'object',
                'properties' => (object) [],
                'required' => [],
                'additionalProperties' => false,
            ],
        ];
    }

    public function requested(string $text): bool
    {
        if (preg_match('/(?:ama|fakat|ancak|bilgi|sorum|sormak|öğrenmek|ogrenmek)/iu', $text)) {
            return false;
        }

        return (bool) preg_match('/(?:görüşmeyi|gorusmeyi|telefonu|aramayı|aramayi).{0,15}(?:kapat|bitir|sonlandır|sonlandir)|(?:görüşürüz|gorusuruz|hoşça kal|hosca kal|beni arama)|(?:kapatabiliriz|kapatalım|kapatalim)/iu', $text);
    }
}
