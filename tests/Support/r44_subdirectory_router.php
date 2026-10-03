<?php

// Local qualification router only. No remote hosting or deployment behavior is changed.
$prefix = '/qualification/task-management';
$public = realpath(__DIR__.'/../../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (! str_starts_with($path, $prefix.'/')) {
    http_response_code(404);
    exit;
}
$relative = substr($path, strlen($prefix));
$file = realpath($public.$relative);
if ($file && str_starts_with($file, $public.DIRECTORY_SEPARATOR) && is_file($file)) {
    $mime = match (pathinfo($file, PATHINFO_EXTENSION)) {
        'js' => 'application/javascript', 'css' => 'text/css', 'png' => 'image/png',
        'webmanifest' => 'application/manifest+json', 'html' => 'text/html',
        default => null,
    };
    if ($mime) {
        header('Content-Type: '.$mime);
        readfile($file);
        exit;
    }
}
$_SERVER['SCRIPT_NAME'] = $prefix.'/index.php';
$_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
$_SERVER['SCRIPT_FILENAME'] = $public.'/index.php';
require $public.'/index.php';
