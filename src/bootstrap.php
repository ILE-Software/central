<?php

use Central\Support\Env;

$root = dirname(__DIR__);
require $root.'/src/autoload.php';
Env::load($root.'/.env');
date_default_timezone_set(\Central\Support\Config::timezone());

return $root;
