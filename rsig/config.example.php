<?php
// Copier ce fichier en config.php et remplir les valeurs
define('DB_DSN',           'pgsql:host=localhost;port=5432;dbname=mabase');
define('DB_USER',          'postgres');
define('DB_PASS',          'votre_mot_de_passe');
define('NIFI_BASE',        'https://localhost:8443');
define('DYN_TENANT_ID',    'votre_tenant_id');
define('DYN_CLIENT_ID',    'votre_client_id');
define('DYN_CLIENT_SECRET','votre_client_secret');
define('DYN_SCOPE',        'https://votre_crm.api.crm4.dynamics.com/.default');
// Authentification obligatoire — mettre false uniquement en dev local
define('AUTH_ENABLED', true);
// Chemin PHP CLI pour le worker sync CRM (Windows/MAMP uniquement, commenter sur Linux)
// define('PHP_CLI_PATH', 'C:\\MAMP\\bin\\php\\php8.3.1\\php.exe');
// define('PHP_INI_PATH', 'C:\\MAMP\\conf\\php8.3.1\\php.ini');
