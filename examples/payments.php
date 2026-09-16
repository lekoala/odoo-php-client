<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\Inputs\PaymentInput;
use LeKoala\OdooClient\OdooException;

try {
    echo "--- Payment Examples ---\n";

    // 1. List payment journals
    echo "Fetching bank/cash journals...\n";
    $journals = $app->payments->getJournals();
    foreach ($journals as $journal) {
        echo "- [ID: {$journal['id']}] {$journal['name']} (Type: {$journal['type']})\n";
    }

    // 2. Register a payment for an invoice
    /*
    if (!empty($journals)) {
        $bankJournalId = $journals[0]['id'];
        $invoiceId = 123; // Replace with actual posted invoice ID

        echo "\nRegistering payment of 50.0 for invoice $invoiceId...\n";
        $input = new PaymentInput(
            invoiceIds: $invoiceId,
            amount: 50.0,
            journalId: $bankJournalId,
            communication: 'Payment for Invoice #123'
        );

        $result = $app->payments->registerPayment($input);
        echo 'Payments: ' . implode(', ', $result->paymentIds) . "\n";
        echo 'Resolution: ' . $result->resolution->value . "\n";
    }
    */

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
