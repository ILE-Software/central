<?php

namespace Central\Support;

class Store
{
    public function __construct(private string $root)
    {
        foreach (['calls', 'queue', 'logs'] as $dir) {
            $path = $this->root.DIRECTORY_SEPARATOR.$dir;
            if (!is_dir($path)) {
                mkdir($path, 0775, true);
            }
        }
    }

    public function put(string $group, string $id, array $data): array
    {
        $data['id'] = $id;
        $data['updated_at'] = gmdate('c');
        $path = $this->path($group, $id);
        $this->withLock($path, function ($handle) use ($data) {
            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, Json::encode($data));
        });

        return $data;
    }

    public function get(string $group, string $id): ?array
    {
        $path = $this->path($group, $id);
        if (!is_file($path)) {
            return null;
        }

        return Json::decode((string) file_get_contents($path));
    }

    public function update(string $group, string $id, array $patch): ?array
    {
        $current = $this->get($group, $id);
        if (!$current) {
            return null;
        }

        return $this->put($group, $id, array_replace($current, $patch));
    }

    public function all(string $group): array
    {
        $items = [];
        foreach (glob($this->root.DIRECTORY_SEPARATOR.$group.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            $items[] = Json::decode((string) file_get_contents($file));
        }

        return $items;
    }

    private function path(string $group, string $id): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]/', '', $id) ?: 'invalid';

        return $this->root.DIRECTORY_SEPARATOR.$group.DIRECTORY_SEPARATOR.$safe.'.json';
    }

    private function withLock(string $path, callable $callback): void
    {
        $handle = fopen($path, 'c+');
        if (!$handle) {
            throw new \RuntimeException('store_write');
        }
        flock($handle, LOCK_EX);
        try {
            $callback($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
