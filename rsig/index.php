<?php
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '60');

header("X-Content-Type-Options: nosniff");
header("X-Frame-Options: SAMEORIGIN");
header("Referrer-Policy: strict-origin-when-cross-origin");
header("Content-Security-Policy: default-src 'self'; "
    . "script-src 'self' 'unsafe-inline' https://unpkg.com; "
    . "style-src 'self' 'unsafe-inline' https://unpkg.com; "
    . "img-src 'self' data: blob: https://data.geopf.fr https://wxs.ign.fr https://tile.openstreetmap.org https://*.tile.openstreetmap.org; "
    . "font-src 'self' https://demotiles.maplibre.org; "
    . "connect-src 'self' https://data.geopf.fr https://wxs.ign.fr https://rtaxes.api.crm4.dynamics.com https://login.microsoftonline.com https://overpass-api.de https://demotiles.maplibre.org https://tile.openstreetmap.org https://*.tile.openstreetmap.org https://nominatim.openstreetmap.org; "
    . "worker-src blob:; "
    . "frame-ancestors 'self'");

require 'flight/Flight.php';
require 'config.php';
require 'app/helpers.php';
require 'app/auth.php';
require 'app/dynamics.php';
require 'app/routes/pages.php';
require 'app/routes/api.php';

Flight::set('flight.views.path', __DIR__ . '/views');
Flight::start();
