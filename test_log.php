<?php
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/test_php.log');
error_reporting(E_ALL);

trigger_error('TEST LOG PHP', E_USER_WARNING);
echo 'ok';