# Central

OpenAI **Realtime SIP** ile santral (Netgsm / Asterisk) arasında açık köprü. Telefon konuşması için; işletme paneli, WhatsApp ve müşteri CRM’i yok.

Gelen çağrı: santral → SIP/TLS köprü → `sip:PROJECT_ID@sip.api.openai.com` → webhook `realtime.call.incoming` → accept + sideband WebSocket.

Dış arama: Netgsm originate veya Asterisk AMI. Santral, imzalı `X-Central-Call-Key` / `X-Central-Call-Token` başlıklarını taşımalı. Numaraya bakarak dış arama eşlenmez. Originate HTTP 200 = konuşma başladı demek değil (`submitted`).

## Yerel (XAMPP)

```
cd C:\xampp\htdocs\central
copy .env.example .env
```

`.env` içine kendi OpenAI anahtarı, webhook secret ve (varsa) Netgsm bilgilerini yaz. Adres:

- http://localhost/central/
- http://localhost/central/health

Açık HTTPS webhook gerekir (ngrok / canlı sunucu). OpenAI projesinde `realtime.call.incoming` aboneliği şart.

## HTTP

| Metod | Yol | İş |
| --- | --- | --- |
| POST | `/api/openai/webhook` | Realtime gelen çağrı |
| POST | `/api/netgsm/webhook` | Santral olay kaydı |
| POST | `/api/netgsm/custom` | Netsipp bağlantı testi |
| POST | `/api/originate` | Dış arama (`X-Central-Token`) |
| GET | `/health` | Anahtar var/yok (değer basılmaz) |

```
curl -X POST http://localhost/central/api/originate ^
  -H "Content-Type: application/json" ^
  -H "X-Central-Token: TOKEN" ^
  -d "{\"phone\":\"05551112233\",\"purpose\":\"callback\"}"
```

Worker: webhook kuyruğa yazar, `bin/worker.php` çağrıyı kabul eder.

## Asterisk örneği

`examples/asterisk/` — `PROJECT_ID` ve SIP şifrelerini kendin yaz. Örnek dosyalarda gerçek hesap yok.

## DTMF

1 bilgi, 2 işlem, 0 personel (SIP REFER). `#` bu kitte işletme onayı değildir.

## Güvenlik

`.env`, çağrı kayıtları ve gerçek SIP şifreleri repoya girmez. Ham Netgsm URL loglanmaz (şifre query’de).

## Lisans

MIT
