<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\Domain;
use LeKoala\OdooClient\OdooException;

try {
    echo "--- Domain Builder Example ---\n";

    // Build a domain for companies OR specific names
    $domain = Domain::build()
        ->where('is_company', '=', true)
        ->orWhere('name', 'ilike', 'Azure')
        ->get();

    echo "Generated Domain: " . json_encode($domain) . "\n\n";

    $customers = $app->customers->list($domain, 5);
    foreach ($customers as $customer) {
        echo "- {$customer->name} (ID: {$customer->id})\n";
    }

    // Example 2: Filtering products with multiple conditions
    echo "\nFiltering products (Services OR recurring items):\n";
    $productDomain = Domain::build()
        ->where('type', '=', 'service')
        ->orWhere('recurring_invoice', '=', true)
        ->get();
        
    $products = $app->products->list($productDomain, 5);
    foreach ($products as $product) {
        echo "- {$product->name}\n";
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
