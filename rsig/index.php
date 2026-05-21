<?php
ini_set('display_errors', 0);
ini_set('memory_limit', '512M');
ini_set('max_execution_time', '60');

require 'flight/Flight.php';
require 'config.php';
require 'app/helpers.php';
require 'app/auth.php';
require 'app/dynamics.php';
require 'app/routes/pages.php';
require 'app/routes/api.php';

Flight::set('flight.views.path', __DIR__ . '/views');
Flight::start();
