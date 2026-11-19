# Which Three Birdies?

Pick a point on a map and Which Three Birdies names the three birds most
recorded near it, then makes a "birdday" card from them with their pictures
and their songs.
[51Degrees](https://51degrees.com/?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=intro-51degrees)
reverse geocoding names the place on the card. Try it at
https://which-three-birdies.51degrees.com/.

## Who made it

[Stuart Langridge](https://kryogenix.org/) and
[Bruce Lawson](https://brucelawson.co.uk) made Which Three Birdies in 2021,
and explain how it works in the blog post
[Which Three Birdies](https://51degrees.com/blog/which-three-birdies?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=credits-blog-post).
Their credit is in every page's footer, with the note that the code is
licensed under the
[Peaceful Open Source License](https://web.archive.org/web/20140924010836/http://wiseearthpublishers.com/sites/wiseearthpublishers.com/files/PeacefulOSL.txt).
Both stay in the footers.

This repository is a fork of https://github.com/stuartlangridge/birdday-card.
Version 2 is on the `v2` branch, which starts from the `stylin'` branch the
authors' README names. The authors' version is still on `stylin'`, with the
SVG sources of the card frames in `source-images/`.

## Running it

You need PHP 8.3 or later with the GD extension, and
[Composer](https://getcomposer.org/). From the repository folder:

```sh
composer install
mkdir -p cache images
export RESOURCEKEY='<your Resource Key>'
export XENO_CANTO_API_KEY='<your xeno-canto API key>'
export CARTO_API_KEY='<your CARTO API key, if you have one>'
export PHP_CLI_SERVER_WORKERS=4
php -d display_errors=0 -d log_errors=1 -S 127.0.0.1:8080 -t src router.php
```

Then open http://127.0.0.1:8080/ and click the map.

`display_errors=0` keeps PHP's error messages off the pages, because the
message for a failed request to the 51Degrees cloud includes the Resource
Key, and PHP shows errors on the page when no `php.ini` says otherwise.
`log_errors=1` writes them to the terminal instead.

PHP needs a certificate bundle to make HTTPS requests. If yours has none,
set `curl.cainfo` and `openssl.cafile` in `php.ini`.

`router.php` answers 404 for an address that is not a file in `src`, where
PHP's built-in server would otherwise answer with the home page. `cache`
keeps the answers from the services the app asks, and `images` keeps the
cards.

PHP's manual says its built-in server
[should not be used on a public network](https://www.php.net/manual/en/features.commandline.webserver.php),
so keep it on a loopback address with a web server or proxy in front of it.

### The environment variables

`RESOURCEKEY` is a 51Degrees Resource Key, which you can
[create here](https://51degrees.com/configure?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=running-create-resource-key).
The app asks for these properties:

| Property | What the app uses it for | Plans |
|---|---|---|
| `location.Country` | Naming the place on the card | All plans |
| `location.Town` | Naming the place on the card | Essentials and above |
| `location.Region` | Naming the place on the card | Pro and above |
| `location.State` | Naming the place on the card | Pro and above |
| `ip.Latitude` | The visitor's card from their IP address | Essentials and above |
| `ip.Longitude` | The visitor's card from their IP address | Essentials and above |
| `ip.LocationConfidence` | Leaving out a location of unknown confidence | All plans |

The plans are on the
[pricing page](https://51degrees.com/pricing?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=running-pricing).
With a key that carries only some of the properties, the card names the
place with what the key has, and without the `ip` properties the home page
shows only the map. Without `RESOURCEKEY` the card pages answer `503` with a
short message, and the map still shows.

`XENO_CANTO_API_KEY` is a key for
[xeno-canto's API version 3](https://xeno-canto.org/explore/api), which comes
with a xeno-canto account. Without it the app does not ask xeno-canto, and
every card shows the three fictional birds the authors made for a place with
no recordings, being a dodo, Charlie "Bird" Parker and Thunderbird 2.

`CARTO_API_KEY` goes on the requests for the [CARTO](https://carto.com/) map
tiles. The tiles load without it.

`PHP_CLI_SERVER_WORKERS` is how many requests PHP's built-in server handles
at once. A new card can take ten seconds to make, so give it more than one.
PHP does not support more than one worker on Windows.

### The visitor's card

The home page opens with a card for the visitor. Where the browser already
lets the page use the device's location, the card is for that point.
Otherwise `place.php` places the visitor by IP address, the page says that IP
location is approximate, and a **Share my location** button offers a closer
card. Private and reserved addresses get no card, IP points are rounded to
two decimal places so nearby visitors share a card, and the address is not
stored.

`place.php` reads the visitor's address from the `X-Forwarded-For` header
when the request comes from `127.0.0.1` or `::1`, meaning a proxy on the same
machine, and otherwise from the connection itself. So the proxy in front of
the app must set `X-Forwarded-For` to the visitor's address alone, replacing
any value the request arrived with. A visit straight to `127.0.0.1`, as with
the commands above, has no public address, so the home page shows only the
map.

## What version 2 changes

Version 2 keeps the authors' pages, card designs and credits, and changes
these things.

1. The recordings come from xeno-canto's API version 3, because version 2 no
   longer answers. Version 3 needs a key, which stays out of the cache's file
   names and the debug output.
2. The three birds are the species with the most recordings near the point.
   Version 3 lists recordings in taxonomic order, so taking the first three
   species, as version 1 did, gave three geese for a point in London. The app
   reads every recording in the nearest box around the point that holds 100
   or more, from 0.05 to 1 degree each way (`XC_BOX_HALF_SIDES`), within six
   requests of 500 recordings (`XC_MAX_CALLS`, `XC_PER_PAGE`). It leaves out
   recordings nobody has identified, ranks the species by count and caches
   the ranking. A species with no picture on Wikidata is passed over, as
   before.
3. The home page opens with a card for the visitor, from the device's
   location where the browser already allows it, or otherwise from the
   visitor's IP address with 51Degrees IP Intelligence and an offer to share
   the device's location for a closer card. `place.php` is new for this.
4. The map uses CARTO tiles in place of Mapbox tiles from a personal account.
5. Every request to Wikidata and Wikimedia Commons names the app in its
   User-Agent, because Wikimedia refuses requests that do not, and a failed
   lookup falls back to the next name rather than stopping with an error.
6. The card pages answer with a short message when there is no Resource Key.
7. `details.php` and `audios.php` make a card that has not been made yet, so
   a card's page works on a server that did not make the card.
8. `img.php` clears the app's own `cache` and `images` folders wherever the
   app is installed, rather than fixed folders below `/var/www`.
9. The 51Degrees packages move from version 4.1 to 4.5, and Composer installs
   them from `composer.lock`, so the `vendor` folder is no longer in the
   repository.
10. The app runs on PHP's built-in server with the new `router.php`, so the
    Dockerfile and `buildorrun.sh` are gone.
11. Every page's footer says "Version 2."

## Find out more

- [Which Three Birdies](https://51degrees.com/blog/which-three-birdies?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-blog-post),
  the blog post in which the authors explain the app
- [Geolocation and reverse geocoding](https://51degrees.com/geolocation?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-geolocation)
- [The reverse geocoding quick start](https://51degrees.com/documentation/_reverse_geocoding__quickstart.html?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-reverse-geocoding-docs)
- [IP Intelligence for location and network data](https://51degrees.com/ip-intelligence?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-ip-intelligence)
- [Create a Resource Key](https://51degrees.com/configure?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-create-resource-key)
- [Pricing](https://51degrees.com/pricing?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-pricing)
- [Contact us](https://51degrees.com/contact-us?utm_source=github&utm_medium=readme&utm_campaign=birdday-card&utm_content=readme&utm_term=find-out-more-contact-us)

The 51Degrees packages the app uses:

- https://github.com/51Degrees/location-php
- https://github.com/51Degrees/pipeline-php-cloudrequestengine
- https://github.com/51Degrees/pipeline-php-core
- https://github.com/51Degrees/pipeline-php-engines
