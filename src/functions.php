<?php
require(__DIR__ . "/../vendor/autoload.php");
use fiftyone\pipeline\cloudrequestengine\CloudRequestEngine;
use fiftyone\pipeline\core\PipelineBuilder;
use fiftyone\pipeline\geolocation\GeoLocationPipelineBuilder;

// Wikimedia refuses requests that do not say which client sent them.
const USER_AGENT = "Which3Birdies/1.0 (https://which-three-birdies.51degrees.com/)";

function http_context() {
    return stream_context_create(["http" => ["user_agent" => USER_AGENT]]);
}

// Without a Resource Key no place can be named, so the request is answered
// with a message in place of a card.
function require_resource_key() {
    if ((string) getenv("RESOURCEKEY") !== "") {
        return;
    }
    http_response_code(503);
    header("Content-Type: text/plain; charset=utf-8");
    header("Cache-Control: no-store");
    echo "Which Three Birdies cannot make cards at the moment. Please try again later.\n";
    exit;
}

$first_debug_print = TRUE;
function decho($s) {
    global $first_debug_print;
    if (isset($_GET["debug"])) {
        if ($first_debug_print) {
            echo "<pre>";
            $first_debug_print = FALSE;
        }
        echo htmlentities($s) . "\n";
    }
}

function get_filename($lat, $lon) {
    $fn = __DIR__ . "/../images/img_" . $lat . "__" . $lon . ".jpg";
    decho("Filename from $lat and $lon is $fn");
    return $fn;
}

function get_bird_image_filename($species) {
    $fn = __DIR__ . "/../images/bird_" . md5($species) . ".jpg";
    decho("Bird image cache filename from $species is $fn");
    return $fn;
}

function get_cache_filename($key) {
    $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9-]+/', '=', $key)));
    if (strlen($slug) > 100) {
        $nslug = substr($slug, 0, 100) . "-hash-" . md5($slug);
        decho("Slug $slug is too long; shortening with hash to $nslug");
        $slug = $nslug;
    }
    $fn = __DIR__ . "/../cache/$slug";
    return $fn;
}

function get_cache_key($key) {
    decho("Checking cache for key $key");
    if (isset($_GET["skipcache"])) {
        decho("Skipping cache as commanded");
        return null;
    }
    $fn = get_cache_filename($key);
    if (file_exists($fn)) {
        decho("Returning cached data for $key");
        return file_get_contents($fn);
    }
    decho("No cache for key $key");
    return null;
}

function store_cache_key($key, $data) {
    decho("Storing data in cache for key $key");
    $fn = get_cache_filename($key);
    file_put_contents($fn, $data);
}

function cached_json($url) {
    $data = get_cache_key($url);
    if (!$data) {
        $data = file_get_contents($url, false, http_context());
        if ($data === false) {
            return null;
        }
        store_cache_key($url, $data);
    }
    $as_json = json_decode($data, true);
    return $as_json;
}

function geoloc51d($lat, $lon) {
    /* Given a latitude and longitude, return a string suitable for looking up this location in wikidata */

    $cache_key = "51d," . $lat . "," . $lon;
    $cached_locations = get_cache_key($cache_key);
    if ($cached_locations) {
        return json_decode($cached_locations);
    }

    $resourceKey = (string) getenv("RESOURCEKEY");
    $settings = array("resourceKey" => $resourceKey, "locationProvider" => "fiftyonedegrees");
    $builder = new GeoLocationPipelineBuilder($settings);
    $pipeline = $builder->build();
    $flowData = $pipeline->createFlowData();
    $flowData->evidence->set('query.51D_Pos_latitude', $lat);
    $flowData->evidence->set('query.51D_Pos_longitude', $lon);

    $result = $flowData->process();

    $town = null; $region = null; $state = null; $country = null;
    try { $town = $flowData->location->town; } catch(Exception $e) {}
    try { $region = $flowData->location->region; } catch(Exception $e) {}
    try { $state = $flowData->location->state; } catch(Exception $e) {}
    try { $country = $flowData->location->country; } catch(Exception $e) {}

    if (!$town || !$town->hasValue) $town = null;
    if (!$region || !$region->hasValue) $region = null;
    if (!$state || !$state->hasValue) $state = null;
    if (!$country || !$country->hasValue) $country = null;

    // names we use to look for imagery, falling back to the next one if one has no image or no search result
    // only include a name if all parts of it are present
    // town+region+state+country, town+region+country, town+state+country, region+state+country,
    // town+country, region+country, state+country, country
    $names = array();

    if ($town && $region && $state && $country) {
        $names[] = [join(", ", [$town->value, $region->value, $state->value, $country->value]), "town+region+state+country"];
    }
    if ($town && $region && $country) {
        $names[] = [join(", ", [$town->value, $region->value, $country->value]), "town+region+country"];
    }
    if ($town && $state && $country) {
        $names[] = [join(", ", [$town->value, $state->value, $country->value]), "town+state+country"];
    }
    if ($region && $state && $country) {
        $names[] = [join(", ", [$region->value, $state->value, $country->value]), "region+state+country"];
    }
    if ($town && $country) {
        $names[] = [join(", ", [$town->value, $country->value]), "town+country"];
    }
    if ($region && $country) {
        $names[] = [join(", ", [$region->value, $country->value]), "region+country"];
    }
    if ($state && $country) {
        $names[] = [join(", ", [$state->value, $country->value]), "state+country"];
    }
    if ($country) {
        $names[] = [$country->value, "country"];
    }
    store_cache_key($cache_key, json_encode($names));
    return $names;
}

// A short name for the place at a point, being its town and country where
// both are known, or an empty string.
function place_name($lat, $lon) {
    $names = geoloc51d($lat, $lon);
    foreach ($names as $name) {
        if ($name[1] === "town+country") {
            return $name[0];
        }
    }
    return count($names) > 0 ? $names[0][0] : "";
}

// The visitor's address. The website forwards it in X-Forwarded-For, and
// only a request from this machine, which is the website, is trusted to.
function visitor_ip() {
    $remote = (string) ($_SERVER["REMOTE_ADDR"] ?? "");
    if ($remote === "127.0.0.1" || $remote === "::1") {
        return trim((string) ($_SERVER["HTTP_X_FORWARDED_FOR"] ?? ""));
    }
    return $remote;
}

// Places within about a kilometer share a card, which is also how far the
// IP location is moved at random.
const IP_POINT_DECIMALS = 2;

// The point 51Degrees IP Intelligence gives for a public address, as
// [lat, lon], or null for an address that is not global (RFC 6890), a
// failed lookup, or a location that is unknown or at 0, 0. The address
// itself is not stored.
function ip_location($ip) {
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
        decho("No IP location for a private or unknown address");
        return null;
    }
    try {
        $pipeline = (new PipelineBuilder())
            ->add(new CloudRequestEngine(array("resourceKey" => (string) getenv("RESOURCEKEY"))))
            ->build();
        $flowData = $pipeline->createFlowData();
        $flowData->evidence->set("server.client-ip", $ip);
        $flowData->process();
        $answer = json_decode((string) $flowData->get("cloud")->get("cloud"), true);
    } catch (Throwable $e) {
        // The message goes to the log, which the website keeps keys out
        // of, and never to the page.
        error_log("PHP Warning: IP location failed: " . $e->getMessage());
        decho("IP location failed");
        return null;
    }
    $found = array_change_key_case((array) ($answer["ip"] ?? array()), CASE_LOWER);
    $confidence = (string) ($found["locationconfidence"] ?? "");
    if (!is_numeric($found["latitude"] ?? null) || !is_numeric($found["longitude"] ?? null)
        || $confidence === "" || strcasecmp($confidence, "Unknown") === 0) {
        decho("No IP location with a known confidence");
        return null;
    }
    $point = array(round((float) $found["latitude"], IP_POINT_DECIMALS), round((float) $found["longitude"], IP_POINT_DECIMALS));
    if ($point[0] == 0 && $point[1] == 0) {
        decho("No IP location");
        return null;
    }
    return $point;
}

function wikidata_image($text_array, $width) {
    // Search wikidata for our query text and get the first matching entity ID
    if (count($text_array) == 0) {
        decho("No wikidata search terms left. Bailing.");
        return null;
    }
    $item = $text_array[0];
    if (is_array($item)) {
        $itemtype = $item[1];
        $item = $item[0];
    } else {
        $itemtype = "unspecified";
    }
    decho("Wikidata search for $item of type $itemtype");
    $qsl = array(
        "action" => "query",
        "list" => "search",
        "srsearch" => $item,
        "format" => "json"
    );
    $url = "https://www.wikidata.org/w/api.php?" . http_build_query($qsl);
    decho("wd search for $item as $url");
    try {
        $wdo = cached_json($url);
    } catch(Exception $e) {
        decho("No JSON search for $item so trying with next one");
        \array_splice($text_array, 0, 1);
        return wikidata_image($text_array, $width);
    }
    if (empty($wdo["query"]["search"])) {
        decho("No wikidata search results for $item so trying with next one");
        \array_splice($text_array, 0, 1);
        return wikidata_image($text_array, $width);
    }
    $entityid = $wdo["query"]["search"][0]["title"];
    decho("Wikidata entity: $entityid");

    // get the data for that entity ID (in theory this could be done with SPARQL but life's too short)
    $entity_url = "https://www.wikidata.org/wiki/Special:EntityData/$entityid.json";
    $entitypublicurl = "https://www.wikidata.org/wiki/Special:EntityData/$entityid";
    decho("Get entity details from $entity_url");
    try {
        $wdo = cached_json($entity_url);
    } catch(Exception $e) {
        decho("Couldn't find an image for $item so trying with next one");
        \array_splice($text_array, 0, 1);
        return wikidata_image($text_array, $width);
    }

    // an image is claim type P18, in wikidata language
    // a banner image is claim type P948, which we check for as well
    try {
        @$imgname = $wdo["entities"][$entityid]["claims"]["P18"][0]["mainsnak"]["datavalue"]["value"];
    } catch(Exception $e) {
        decho("Couldn't find an image entry for $item so trying with next one");
        \array_splice($text_array, 0, 1);
        return wikidata_image($text_array, $width);
    }
    if (!$imgname) {
        try {
            decho("Looking for page banner image because no image was found");
            @$imgname = $wdo["entities"][$entityid]["claims"]["P948"][0]["mainsnak"]["datavalue"]["value"];
        } catch(Exception $e) {
            decho("Couldn't find an image entry for $item so trying with next one");
            \array_splice($text_array, 0, 1);
            return wikidata_image($text_array, $width);
        }
    }
    if (!$imgname || strlen($imgname) == 0 || !stripos($imgname, ".jpg")) {
        decho("Couldn't find a .jpg image for $item so trying with next one");
        \array_splice($text_array, 0, 1);
        return wikidata_image($text_array, $width);
    }
    $picurl = "http://commons.wikimedia.org/wiki/Special:FilePath/" . rawurlencode($imgname) . "?width=" . $width;
    return array($picurl, $entitypublicurl);
}

function xeno_canto($lat, $lon) {
    $publicurl = "https://www.xeno-canto.org/explore?query=lat%3A$lat%20lon%3A$lon";
    $key = (string) getenv("XENO_CANTO_API_KEY");
    if ($key === "") {
        decho("No xeno-canto key, so no recordings");
        return array(array(), $publicurl);
    }
    // The ranked species are cached rather than the pages, which are large.
    $cache_key = "xc3," . $lat . "," . $lon;
    $cached = get_cache_key($cache_key);
    if ($cached) {
        $ranked = json_decode($cached, true);
    } else {
        $ranked = xeno_canto_species($lat, $lon, $key);
        if ($ranked === null) {
            decho("No answer from xeno-canto");
            return array(array(), $publicurl);
        }
        store_cache_key($cache_key, json_encode($ranked));
    }
    $birds = array();
    foreach ($ranked as $entry) {
        $birdspec = $entry["species"];
        decho("Bird is $birdspec, with " . $entry["recordings"] . " recordings");
        list($birdimg, $birdpublic) = wikidata_image([$birdspec], 400);
        if ($birdimg) {
            decho("Got bird image $birdimg");
            $birds[] = array(
                "image" => $birdimg,
                "wikidata" => $birdpublic,
                "species" => $birdspec,
                "sound_url" => $entry["file"],
                "cache_image" => get_bird_image_filename($birdspec)
            );
            if (count($birds) == 3) break;
        } else {
            decho("No image for $birdspec");
        }
    }
    return array($birds, $publicurl);
}

// The areas tried around the point, each given as half the side of a box in
// degrees, nearest first. 0.05 degrees of latitude is about 5.5 km.
const XC_BOX_HALF_SIDES = [0.05, 0.1, 0.25, 0.5, 1.0];

// The most recordings API v3 returns on one page.
const XC_PER_PAGE = 500;

// The most xeno-canto requests one card makes.
const XC_MAX_CALLS = 6;

// Recordings enough to say which birds are most common in an area.
const XC_ENOUGH_RECORDINGS = 100;

// The bird species recorded near the point, most recorded first, each with
// its best rated recording, or null when xeno-canto does not answer. API v3
// lists recordings in taxonomic order with no other order offered, so the
// counts come from an area whose recordings are all read: the nearest that
// holds XC_ENOUGH_RECORDINGS, within XC_MAX_CALLS requests.
function xeno_canto_species($lat, $lon, $key) {
    $calls = 0;
    $best = null;
    foreach (XC_BOX_HALF_SIDES as $half) {
        if ($calls >= XC_MAX_CALLS) {
            break;
        }
        $first = xeno_canto_page($lat, $lon, $half, 1, $key);
        $calls += 1;
        if ($first === null) {
            break;
        }
        $found = array("recordings" => $first["recordings"], "species" => (int) $first["numSpecies"]);
        $pages = (int) $first["numPages"];
        if ($pages > 1) {
            // All of a nearer area with three species or more is better than
            // part of a wider one.
            if ($best !== null && $best["species"] >= 3) {
                break;
            }
            for ($page = 2; $page <= $pages && $calls < XC_MAX_CALLS; $page++) {
                $more = xeno_canto_page($lat, $lon, $half, $page, $key);
                $calls += 1;
                if ($more === null) {
                    break;
                }
                $found["recordings"] = array_merge($found["recordings"], $more["recordings"]);
            }
            $best = $found;
            break;
        }
        $best = $found;
        if (count($found["recordings"]) >= XC_ENOUGH_RECORDINGS && $found["species"] >= 3) {
            break;
        }
    }
    decho("Asked xeno-canto $calls times");
    if ($best === null) {
        return null;
    }

    $counts = array();
    $sounds = array();
    foreach ($best["recordings"] as $recording) {
        // xeno-canto files a recording nobody has identified as "Mystery
        // mystery".
        if (empty($recording["gen"]) || empty($recording["sp"])
            || strcasecmp($recording["gen"], "Mystery") === 0) {
            continue;
        }
        $name = $recording["gen"] . " " . $recording["sp"];
        $counts[$name] = ($counts[$name] ?? 0) + 1;
        // A restricted species has its file withheld. Quality runs from A,
        // the best, to E, and "no score" sorts after them.
        $file = (string) ($recording["file"] ?? "");
        $quality = (string) ($recording["q"] ?? "");
        if (str_contains($file, "xeno-canto.org/")
            && (!isset($sounds[$name]) || strcmp($quality, $sounds[$name][1]) < 0)) {
            $sounds[$name] = array($file, $quality);
        }
    }
    $species = array_keys(array_intersect_key($counts, $sounds));
    usort($species, function ($a, $b) use ($counts) {
        return ($counts[$b] <=> $counts[$a]) ?: strcmp($a, $b);
    });
    return array_map(function ($name) use ($counts, $sounds) {
        return array("species" => $name, "recordings" => $counts[$name], "file" => $sounds[$name][0]);
    }, $species);
}

// One page of the birds recorded in a box around the point, or null when
// xeno-canto does not answer. The key goes on the request only, never into
// the debug output.
function xeno_canto_page($lat, $lon, $half, $page, $key) {
    $box = sprintf("%.4F,%.4F,%.4F,%.4F",
        max(-90, $lat - $half), max(-180, $lon - $half),
        min(90, $lat + $half), min(180, $lon + $half));
    $url = "https://xeno-canto.org/api/3/recordings?" . http_build_query(
        array("query" => "box:$box grp:birds", "per_page" => XC_PER_PAGE, "page" => $page),
        "", "&", PHP_QUERY_RFC3986);
    decho("Asking xeno-canto for page $page of box $box");
    $data = file_get_contents($url . "&key=" . rawurlencode($key), false, http_context());
    if ($data === false) {
        return null;
    }
    $json = json_decode($data, true);
    return is_array($json) && isset($json["recordings"]) ? $json : null;
}



function scaler($im, $w, $h) {
    // scales and centre crops to the required size
    $origw = imagesx($im);
    $origh = imagesy($im);
    $scalew = $origw / $w;
    $scaleh = $origh / $h;
    if ($scalew > $scaleh) {
        $nw = ceil($origw / $scaleh);
        $nh = ceil($origh / $scaleh);
    } else {
        $nw = ceil($origw / $scalew);
        $nh = ceil($origh / $scalew);
    }
    decho("Scaling image from $origw $origh to $nw $nh to be bigger than $w $h");
    $n = imagescale($im, $nw, $nh);
    decho("Scaled image to new handle");
    if ($n === FALSE) {
        decho("Image scale failed!");
    }
    if ($nw > $w) {
        $cx = ceil(($nw - $w) / 2);
        $cy = 0;
    } else if ($nh > $h) {
        $cx = 0;
        $cy = ceil(($nh - $h) / 2);
    } else {
        $cx = 0;
        $cy = 0;
    }
    decho("Cropping image of size $nw $nh to be size $w $h from point $cx $cy");
    $n = imagecrop($n, array("x" => $cx, "y" => $cy, "width" => $w, "height" => $h));
    return $n;
}

function add_bird($base, $bird, $cache_image, $w, $h, $x, $y, $border) {
    decho("Loading bird image: " . $bird);
    decho("Checking bird cache image $cache_image");
    $b1 = FALSE;
    if (file_exists($cache_image)) {
        $b1 = imagecreatefromjpeg($cache_image);
        if ($b1 === FALSE) {
            decho("Couldn't load cached bird image $bird!");
        }
    }

    if ($b1 === FALSE) {
        $b1 = @imagecreatefromstring(file_get_contents($bird, false, http_context()));
    }
    if ($b1 === FALSE) {
        decho("Couldn't load bird image $bird!");
        return;
    }
    $b1 = scaler($b1, $w, $h);
    imagecopymerge($base, $b1, $x, $y, 0, 0, $w, $h, 100);
    $borderimg = @imagecreatefrompng($border);
    imagecopy($base, $borderimg, $x, $y, 0, 0, $w, $h);
    if (!file_exists($cache_image)) {
        imagejpeg($b1, $cache_image);
    }
    decho("Saved bird cache image as $cache_image");
}

function create_save_image($fn, $lat, $lon, $data_cache_key) {
    $loc_descriptions = geoloc51d($lat, $lon);
    $loc_descriptions[] = ["Area 51", "last fallback"]; // ultimate fallback if we have no location images
    $loc_descriptions_combined = [];
    foreach ($loc_descriptions as $tn) {
        $loc_descriptions_combined[] = $tn[0];
    }
    $loc_descriptions_combined = join(" | ", $loc_descriptions_combined);
    decho("Location descriptions are $loc_descriptions_combined");
    list($image_url, $townurl) = wikidata_image($loc_descriptions, 1000);
    decho("Base image URL is $image_url");
    list($birds, $xenourl) = xeno_canto($lat, $lon);
    decho("Got birds: " . json_encode($birds));#

    if (count($birds) == 0) {
        decho("No birds, so adding the Three Fictional Birds");
        $birds = array(
            array(
                "image" => "/about-images/dodo.jpg",
                "wikidata" => "https://en.wikipedia.org/wiki/Dodo",
                "species" => "Raphus cucullatus (dodo)",
                "sound_url" => "/about-images/dodo.mp3",
                "cache_image" => __DIR__ . "/about-images/dodo.jpg"
            ),
            array(
                "image" => "/about-images/charlie_parker.jpg",
                "wikidata" => "https://en.wikipedia.org/wiki/Charlie_Parker",
                "species" => 'Charlie “Bird” Parker',
                "sound_url" => "/about-images/charlie_parker.mp3",
                "cache_image" => __DIR__ . "/about-images/charlie_parker.jpg"
            ),
            array(
                "image" => "/about-images/thunderbird_2.jpg",
                "wikidata" => "https://en.wikipedia.org/wiki/Thunderbirds_machines#Thunderbird_2",
                "species" => "Thunderbird 2",
                "sound_url" => "/about-images/Sound_of_AN-24_airplane_not_actually_thunderbird_2.mp3",
                "cache_image" => __DIR__ . "/about-images/thunderbird_2.jpg"
            )
        );
    }

    store_cache_key($data_cache_key, json_encode(array(
        "birds" => $birds,
        "xenourl" => $xenourl,
        "location" => $loc_descriptions[0],
        "town" => $townurl
    )));

    // required size for output image
    $outw = 800;
    $outh = 540;

    // Get base image into a GD image object
    decho("Loading base image $image_url");
    $base = imagecreatefromstring(file_get_contents($image_url, false, http_context()));
    if ($base === FALSE) {
        decho("Couldn't load $image_url");
        die();
    }

    // Scale base image to our desired size
    decho("Scaling base image $image_url to size $outw $outh");
    $base = scaler($base, $outw, $outh);

    // composite birds onto base image
    switch (count($birds)) {
        case 3:
            add_bird($base, $birds[2]["image"], $birds[2]["cache_image"], 200, 120, 466, 180, "decoration/wonderfulcelcticdragons.png");
        case 2:
            add_bird($base, $birds[1]["image"], $birds[1]["cache_image"], 200, 120, 133, 180, "decoration/simpleoldornateframe.png");
        case 1:
            add_bird($base, $birds[0]["image"], $birds[0]["cache_image"], 200, 120, 300, 30, "decoration/coolasianframe.png");
        default:
            break;
    }

    // slogan
    $slogan = "HAPPY BIRDDAY";
    $font = dirname(__FILE__) . "/bettynoir.ttf";
    $distance_from_bottom = 0;
    $fontsize = 80;
    $col = imagecolorallocate ($base, 255, 255, 0);
    $tb = imagettfbbox($fontsize, 0, $font, $slogan);
    $tw = $tb[4] - $tb[6];
    $th = $tb[1] - $tb[7];
    $x = ($outw - $tw) / 2;
    $y = $outh - $th - $distance_from_bottom;
    decho("Slogan bounding box is: " . json_encode($tb) . " (i.e., $tw x $th in size)");
    decho("So positioning at $x $y");
    imagettftext ($base, $fontsize, 0, $x, $y, $col, $font, $slogan);

    // write final image to disk
    imagejpeg($base, $fn);
}

function validate() {
    /* Validate parameters */
    $lat_options = array('options' => array('default' => 0, 'min_range' => -90, 'max_range' => 90));
    $lon_options = array('options' => array('default' => 0, 'min_range' => -180, 'max_range' => 180));
    $lat = filter_var($_GET["lat"], FILTER_VALIDATE_FLOAT, $lat_options);
    $lon = filter_var($_GET["lon"], FILTER_VALIDATE_FLOAT, $lon_options);
    $data_cache_key = "data," . $lat . "," . $lon;
    return array($lat, $lon, $data_cache_key);
}