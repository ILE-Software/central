<?php

namespace Central\Http;

use Central\Support\Json;

class Response
{
    public function __construct(private mixed $body, private int $status = 200, private array $headers = [])
    {
    }

    public static function json(mixed $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($body, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name.': '.$value);
        }
        echo is_string($this->body) ? $this->body : Json::encode($this->body);
    }
}
