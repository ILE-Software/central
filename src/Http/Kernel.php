<?php

namespace Central\Http;

use Central\Http\Controllers\OriginateController;
use Central\Http\Controllers\WebhookController;
use Central\Support\Config;
use Central\Support\Store;

class Kernel
{
    public function __construct(private string $root)
    {
    }

    public function handle(Request $request): Response
    {
        $store = new Store($this->root.DIRECTORY_SEPARATOR.'storage');
        $webhooks = new WebhookController($store, $this->root);
        $originate = new OriginateController($store);
        $path = $request->path === '/' ? '/' : '/'.trim($request->path, '/');

        return match (true) {
            $request->method === 'GET' && $path === '/' => $this->home(),
            $request->method === 'GET' && $path === '/health' => Response::json($this->health()),
            $request->method === 'POST' && $path === '/api/openai/webhook' => $webhooks->openai($request),
            $request->method === 'POST' && $path === '/api/netgsm/webhook' => $webhooks->netgsm($request),
            $request->method === 'POST' && $path === '/api/netgsm/custom' => $webhooks->netsipp($request),
            $request->method === 'POST' && $path === '/api/originate' => $originate->create($request),
            $request->method === 'GET' && preg_match('#^/api/calls/([a-zA-Z0-9._-]+)$#', $path, $m) => $originate->show($m[1]),
            default => Response::json(['error' => 'not_found'], 404),
        };
    }

    private function health(): array
    {
        $openai = Config::openai();
        $phone = Config::phone();
        $netgsm = Config::netgsm();

        $filled = static fn (?string $value): bool => $value !== null && $value !== '' && !str_contains($value, 'your_') && !str_starts_with($value, 'change-this');

        return [
            'ok' => true,
            'service' => 'central',
            'incoming' => $phone['incoming_enabled'],
            'outbound' => $phone['outbound_enabled'],
            'openai_key' => $filled($openai['api_key']),
            'openai_webhook' => $filled($openai['webhook_secret']),
            'netgsm' => $filled($netgsm['subscriber']),
            'project_id' => $filled($openai['project_id']),
        ];
    }

    private function home(): Response
    {
        $health = $this->health();
        $flag = fn (bool $v) => $v ? 'hazır' : 'eksik';
        $html = '<!doctype html><html lang="tr"><head><meta charset="utf-8"><title>Central</title>
<style>body{font-family:Segoe UI,sans-serif;max-width:720px;margin:48px auto;padding:0 20px;color:#111}
code{background:#f3f3f3;padding:2px 6px;border-radius:4px}li{margin:6px 0}</style></head><body>
<h1>Central</h1>
<p>OpenAI Realtime SIP köprüsü. İş paneli, WhatsApp ve müşteri verisi yok — yalnızca telefon / santral.</p>
<ul>
<li>Gelen çağrı webhook: <code>POST /api/openai/webhook</code></li>
<li>Netgsm olay: <code>POST /api/netgsm/webhook</code></li>
<li>Netsipp bağlantı testi: <code>POST /api/netgsm/custom</code></li>
<li>Dış arama: <code>POST /api/originate</code></li>
<li>Sağlık: <code>GET /health</code></li>
</ul>
<p>OpenAI anahtar: <strong>'.$flag($health['openai_key']).'</strong> · Webhook: <strong>'.$flag($health['openai_webhook']).'</strong> · Netgsm: <strong>'.$flag($health['netgsm']).'</strong></p>
<p>SIP hedefi: <code>sip:PROJECT_ID@sip.api.openai.com;transport=tls</code></p>
</body></html>';

        return Response::html($html);
    }
}
