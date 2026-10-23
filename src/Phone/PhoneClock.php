<?php

namespace Central\Phone;

class PhoneClock
{
    public function now(): float
    {
        return hrtime(true) / 1e9;
    }
}
