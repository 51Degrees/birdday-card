<?php
// Router for PHP's built-in server. A file that exists below the document
// root, or a folder with an index page, is served as the server serves it.
// Anything else answers 404, where the server would otherwise answer with
// the site's home page.
$root = realpath($_SERVER['DOCUMENT_ROOT']);
$path = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$file = realpath($root . $path);
if ($file !== false
    && ($file === $root || str_starts_with($file, $root . DIRECTORY_SEPARATOR))
    && (is_file($file)
        || is_file($file . '/index.php')
        || is_file($file . '/index.html'))) {
    return false;
}
http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo "Not found.\n";
