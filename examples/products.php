<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Product Examples ---\n";

    // 1. Search for products by name
    echo "Searching for 'SMS' products...\n";
    $products = $app->products->search('SMS');
    
    foreach ($products as $product) {
        echo "- [ID: {$product->id}] {$product->name} (Ref: {$product->defaultCode}) - Price: {$product->listPrice}\n";
    }

    // 2. Get products by category
    echo "\nFetching products in 'SMS Pack' category...\n";
    $packs = $app->products->getByCategory('SMS Pack');
    
    foreach ($packs as $pack) {
        echo "- {$pack->name} (ID: {$pack->id})\n";
    }

    // 3. Get single product details
    if (!empty($products)) {
        $firstId = $products[0]->id;
        $details = $app->products->get($firstId);
        echo "\nDetails for product [{$firstId}]: {$details->name}, Type: {$details->type}\n";
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
