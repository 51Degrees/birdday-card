<?php
// The place for the visitor's card as JSON: the point given, or without one
// the point the visitor's IP address gives. 404 when there is none, so the
// page shows only the map.

require "functions.php";
require_resource_key();

header("Content-Type: application/json");
header("Cache-Control: no-store");

if (isset($_GET["lat"], $_GET["lon"])) {
    list($lat, $lon) = validate();
    $source = "gps";
} else {
    $point = ip_location(visitor_ip());
    if ($point === null) {
        http_response_code(404);
        echo json_encode(array("error" => "No location for this address."));
        exit;
    }
    list($lat, $lon) = $point;
    $source = "ip";
}

echo json_encode(array(
    "lat" => $lat,
    "lon" => $lon,
    "place" => place_name($lat, $lon),
    "source" => $source
));
