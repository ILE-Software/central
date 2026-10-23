<?php

use Central\Phone\CallWorker;
use Central\Support\Store;

$root = require dirname(__DIR__).'/src/bootstrap.php';
$event = '';
foreach ($argv ?? [] as $arg) {
    if (str_starts_with($arg, '--event=')) {
        $event = substr($arg, 8);
    }
}
if ($event === '') {
    fwrite(STDERR, "usage: php bin/worker.php --event=CALL_ID\n");
    exit(1);
}

$store = new Store($root.DIRECTORY_SEPARATOR.'storage');
(new CallWorker($store))->handle($event);
