<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Portal Links & PDF Retrieval Example ---\n";

    // 1. Fetching Taxes (for invoice creation)
    echo "\nFetching available Sales Taxes:\n";
    $taxes = $app->products->listTaxes();
    foreach ($taxes as $tax) {
        echo "- [ID: {$tax->id}] {$tax->name}: {$tax->amount}% ({$tax->amountType})\n";
    }

    // 2. Portal Links for Invoices
    echo "\nChecking recent Invoices for Portal Links:\n";
    $invoices = $app->invoices->list([], 5);
    foreach ($invoices as $invoice) {
        $portalUrl = $app->invoices->getPortalUrl($invoice->id);
        echo "- Invoice #{$invoice->id} ({$invoice->name}):\n";
        echo "  - Portal URL: " . ($portalUrl ?: 'N/A') . "\n";
        echo "  - Total: {$invoice->amountTotal}\n";
    }

    // 3. Portal Links for Subscriptions
    echo "\nChecking recent Subscriptions for Portal Links:\n";
    $subs = $app->subscriptions->list([], 5);
    foreach ($subs as $sub) {
        $portalUrl = $app->subscriptions->getPortalUrl($sub->id);
        echo "- Subscription #{$sub->id} ({$sub->name}):\n";
        echo "  - Portal URL: " . ($portalUrl ?: 'N/A') . "\n";
    }

    // 4. Download PDF (First invoice found)
    if (!empty($invoices)) {
        $invoiceId = $invoices[0]->id;
        echo "\nDownloading PDF for Invoice #{$invoiceId}...\n";
        
        $pdfData = $app->invoices->downloadPdf($invoiceId);
        $filename = __DIR__ . "/invoice_{$invoiceId}.pdf";
        
        file_put_contents($filename, $pdfData);
        echo "✅ PDF saved to: {$filename}\n";
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
