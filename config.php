<?php

define('APP_NAME', 'CRM FLASHEDUCA');
define('DB_HOST', getenv('DB_HOST') ?: '186.209.113.107');
define('DB_NAME', getenv('DB_NAME') ?: 'crmf5894_atlanticus');
define('DB_USER', getenv('DB_USER') ?: 'crmf5894_atlanticus');
define('DB_PASS', getenv('DB_PASS') ?: '520741/8aY');
define('DB_CHARSET', 'utf8mb4');

define('SESSION_NAME', 'crm_prefeituras_session');
define('ITEMS_PER_PAGE', 20);
define('DEFAULT_ADMIN_EMAIL', '');
define('DEFAULT_ADMIN_PASSWORD', '');
define('DEFAULT_ADMIN_NAME', '');

date_default_timezone_set('America/Cuiaba');

error_reporting(E_ALL);
ini_set('display_errors', '0');







