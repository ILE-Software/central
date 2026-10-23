<?php

namespace Central\Phone;

use Central\OpenAi\RealtimeSocket;

class PhoneResponseFlow
{
    private bool $active = false;
    private ?array $pending = null;
    private string $nextTurn = '';
    private array $turns = [];
    private ?string $nextTag = null;
    private array $tags = [];
    private array $completed = [];

    public function active(): bool
    {
        return $this->active;
    }

    public function customerStarted(): void
    {
        $this->pending = null;
    }

    public function request(RealtimeSocket $socket, string $turn, ?string $instructions = null, ?string $tag = null): void
    {
        if ($this->active) {
            $this->pending = [$turn, $instructions, $tag];

            return;
        }
        $event = ['type' => 'response.create'];
        if ($instructions) {
            $event['response'] = ['instructions' => $instructions, 'tool_choice' => 'none'];
        }
        $socket->send($event);
        $this->active = true;
        $this->nextTurn = $turn;
        $this->nextTag = $tag;
    }

    public function created(array $event): void
    {
        $id = $event['response']['id'] ?? null;
        if ($id) {
            $this->turns[$id] = $this->nextTurn;
            $this->tags[$id] = $this->nextTag;
        }
    }

    public function tag(array $event): ?string
    {
        $id = $event['response_id'] ?? '';

        return ($this->completed[$id] ?? false) ? ($this->tags[$id] ?? null) : null;
    }

    public function done(RealtimeSocket $socket, array $event = []): void
    {
        $id = $event['response']['id'] ?? null;
        if ($id) {
            $this->completed[$id] = ($event['response']['status'] ?? '') === 'completed';
        }
        $this->active = false;
        if ($this->pending) {
            [$turn, $instructions, $tag] = $this->pending;
            $this->pending = null;
            $this->request($socket, $turn, $instructions, $tag);
        }
    }

    public function permits(array $event, string $turn): bool
    {
        return !isset($event['response_id']) || (($this->turns[$event['response_id']] ?? null) === $turn);
    }
}
