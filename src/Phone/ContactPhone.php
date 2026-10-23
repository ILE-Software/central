<?php

namespace Central\Phone;

class ContactPhone
{
    public static function normalize(?string $value): ?string
    {
        $v = preg_replace('/\D/', '', $value ?? '');
        if (str_starts_with($v, '00')) {
            $v = substr($v, 2);
        }
        if (strlen($v) === 11 && $v[0] === '0') {
            $v = '90'.substr($v, 1);
        }
        if (strlen($v) === 10) {
            $v = '90'.$v;
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $v) ? $v : null;
    }
}
