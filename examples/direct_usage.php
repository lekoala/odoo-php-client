<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\Domain;
use LeKoala\OdooClient\OdooException;

$client = $app->getClient();

try {
    echo "--- Direct Client Usage Example (No Services) ---\n";

    // 1. Version Check
    $version = $client->version();
    echo "Odoo Version: " . ($version['server_version'] ?? 'Unknown') . "\n";

    // 2. Fields Discovery
    echo "\nFields in 'res.partner':\n";
    $fields = $client->fieldsOf('res.partner');
    $sampleFields = array_slice($fields, 0, 5);
    foreach ($sampleFields as $name => $info) {
        echo "- {$name}: {$info['string']} ({$info['type']})\n";
    }

    // 3. Simple Search (IDs only)
    echo "\nSearching for Companies (IDs only):\n";
    $ids = $client->search('res.partner', [['is_company', '=', true]], ['limit' => 3]);
    echo "Found IDs: " . implode(', ', $ids) . "\n";

    // 4. Search & Read with Domain Builder
    echo "\nSearch & Read with Domain Builder:\n";
    $domain = Domain::build()
        ->where('is_company', '=', true)
        ->orWhere('name', 'ilike', 'Azure')
        ->get();

    $records = $client->searchRead('res.partner', $domain, ['name', 'email'], 3);
    foreach ($records as $record) {
        echo "- {$record['name']} <" . ($record['email'] ?: 'no-email') . ">\n";
    }

    // 5. CRUD: Create
    echo "\nCreating a temporary partner...\n";
    $newId = $client->create('res.partner', [
        'name' => 'John Odoo (Direct API Test)',
        'email' => 'john.direct@example.com'
    ]);
    echo "Created Partner ID: {$newId}\n";

    // 6. CRUD: Update
    echo "Updating partner name...\n";
    $client->write('res.partner', [$newId], ['name' => 'John Odoo Updated']);
    
    // 7. CRUD: Delete
    echo "Deleting partner...\n";
    $client->unlink('res.partner', [$newId]);
    echo "✅ CRUD Cycle complete.\n";

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
