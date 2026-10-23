<?php

$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/health';
$_SERVER['SCRIPT_NAME'] = '/central/index.php';
require dirname(__DIR__).'/public/index.php';
