<?php

require_once __DIR__ . '/../vendor/autoload.php';

use LeKoala\OdooClient\Config;
use LeKoala\OdooClient\Odoo;

/**
 * Common configuration for examples.
 * Update these credentials with your actual Odoo instance details.
 */
$config = new Config(
    url: 'https://your-instance.odoo.com',
    db: 'your-database',
    apiKey: 'your-api-key' // Generate via User Profile -> Account Security -> New API Key
);

$app = new Odoo($config);

return $app;
