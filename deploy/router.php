<?php
// PHP built-in development server router; never a production server configuration.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$root = realpath(__DIR__ . '/../.runtime/wordpress');
header('X-Atlas-Local-Runtime: ' . substr(hash('sha256', realpath(dirname(__DIR__))), 0, 16));
$file = realpath($root . $path);
$owned = realpath(__DIR__ . '/../wp-content');
if ($path !== '/' && $file && is_file($file) &&
    (str_starts_with($file, $root . DIRECTORY_SEPARATOR) || str_starts_with($file, $owned . DIRECTORY_SEPARATOR))) {
    return false;
}
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$_SERVER['PHP_SELF'] = '/index.php';
require $root . '/index.php';
