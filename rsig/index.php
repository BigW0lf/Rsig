<?php
require 'flight/Flight.php';
require 'config.php';
require 'app/helpers.php';
require 'app/dynamics.php';
require 'app/routes/pages.php';
require 'app/routes/api.php';

ini_set('display_errors', 0);
ini_set('memory_limit', '256M');
ini_set('max_execution_time', '30');

Flight::set('flight.views.path', __DIR__ . '/views');
Flight::start();
