<?php
// The key for the CARTO map tiles, which the website passes in as it does
// for its own maps.
header('Content-Type: text/javascript; charset=utf-8');
header('Cache-Control: public, max-age=3600');
echo 'window.cartoApiKey = ' . json_encode((string) getenv('CARTO_API_KEY')) . ";\n";
