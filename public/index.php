<?php

use Central\Http\Kernel;
use Central\Http\Request;

$root = require dirname(__DIR__).'/src/bootstrap.php';
(new Kernel($root))->handle(Request::capture())->send();
