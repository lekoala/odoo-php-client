<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\Inputs\InvoiceInput;
use LeKoala\OdooClient\Inputs\InvoiceLineInput;
use LeKoala\OdooClient\OdooException;

try {
    echo "--- Invoice Examples ---\n";

    // 1. List outstanding invoices for a partner
    $partnerId = 10; // Replace with actual partner ID
    echo "Fetching outstanding invoices for partner $partnerId...\n";
    $invoices = $app->invoices->getOutstanding($partnerId);
    
    foreach ($invoices as $inv) {
        echo "- [ID: {$inv->id}] {$inv->name} - Amount: {$inv->amountTotal} - Residual: {$inv->amountResidual}\n";
    }

    // 2. Create a draft invoice
    /*
    echo "\nCreating a draft invoice...\n";
    $input = new InvoiceInput(
        partnerId: 10,
        lines: [
            new InvoiceLineInput(
                productId: 456, // Replace with valid product ID
                quantity: 2,
                priceUnit: 25.0,
                name: 'Consulting Service'
            )
        ]
    );

    $invoiceId = $app->invoices->create($input);
    echo "Draft invoice created with ID: $invoiceId\n";

    // 3. Confirm (Post) the invoice
    echo "Confirming invoice...\n";
    $app->invoices->confirm($invoiceId);
    echo "Invoice confirmed/posted.\n";
    */

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
