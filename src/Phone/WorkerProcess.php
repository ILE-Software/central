<?php

namespace Central\Phone;

class WorkerProcess
{
    public static function spawn(string $root, string $eventId): void
    {
        $php = PHP_BINARY;
        $script = $root.DIRECTORY_SEPARATOR.'bin'.DIRECTORY_SEPARATOR.'worker.php';
        $eventId = preg_replace('/[^a-zA-Z0-9._-]/', '', $eventId);
        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B "" '.escapeshellarg($php).' '.escapeshellarg($script).' --event='.escapeshellarg($eventId), 'r'));
        } else {
            exec(escapeshellarg($php).' '.escapeshellarg($script).' --event='.escapeshellarg($eventId).' > /dev/null 2>&1 &');
        }
    }
}
