<?php

namespace Central\Phone;

use Central\Netgsm\NetgsmDialer;
use Central\Support\Store;

class OutboundDispatcher
{
    public function __construct(private Store $store, private ?NetgsmDialer $dialer = null)
    {
        $this->dialer ??= new NetgsmDialer();
    }

    public function start(array $call): array
    {
        if ($error = (new PhonePolicy())->outboundBlocker()) {
            throw new \RuntimeException($error);
        }
        if (($call['status'] ?? '') !== 'draft') {
            return $call;
        }
        $phone = ContactPhone::normalize($call['contact_phone'] ?? '');
        if (!$phone) {
            throw new \RuntimeException('Geçersiz numara.');
        }
        foreach ($this->store->all('calls') as $existing) {
            if (($existing['contact_phone'] ?? '') === $phone && in_array($existing['status'] ?? '', ['dispatching', 'submitted', 'active', 'unknown'], true) && ($existing['id'] ?? '') !== ($call['id'] ?? '')) {
                throw new \RuntimeException('Bu numara için sonuçlanmamış bir arama var.');
            }
        }
        $call['status'] = 'dispatching';
        $call['contact_phone'] = $phone;
        $call['submitted_at'] = gmdate('c');
        $this->store->put('calls', $call['id'], $call);
        $result = $this->dialer->dial($call);
        $call = array_replace($call, $result);
        $this->store->put('calls', $call['id'], $call);

        return $call;
    }
}
