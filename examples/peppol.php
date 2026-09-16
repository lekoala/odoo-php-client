<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- PEPPOL Electronic Invoicing Examples ---\n";

    // 1. List customers configured for PEPPOL
    echo "Listing customers with PEPPOL configuration...\n";
    $peppolCustomers = $app->peppol->listEnabledCustomers();
    
    if (empty($peppolCustomers)) {
        echo "No PEPPOL-enabled customers found.\n";
    } else {
        foreach ($peppolCustomers as $customer) {
            echo "- [ID: {$customer->id}] {$customer->name}\n";
            echo "  EAS:      {$customer->peppolEas}\n";
            echo "  Endpoint: {$customer->peppolEndpoint}\n";
            echo "  Format:   {$customer->invoiceEdiFormat}\n";
            echo "  Status:   " . ($customer->isPeppolEnabled() ? "PEPPOL ENABLED" : "Configuration incomplete") . "\n";
        }
    }

    // 2. Find invoices ready for PEPPOL transmission
    echo "\nListing invoices ready for PEPPOL transmission...\n";
    $readyInvoices = $app->peppol->listReadyInvoices();
    
    if (empty($readyInvoices)) {
        echo "No invoices currently ready for PEPPOL.\n";
    } else {
        foreach ($readyInvoices as $invoice) {
            echo "- Invoice: {$invoice->name} (ID: {$invoice->id})\n";
            echo "  Customer ID: {$invoice->partnerId}\n";
            echo "  Status:      " . $invoice->getPeppolStatusLabel() . "\n";
        }
    }

    // 3. Check for PEPPOL transmission errors
    echo "\nChecking for invoices with PEPPOL transmission errors...\n";
    $errorInvoices = $app->peppol->listInvoicesByStatus('error');
    
    if (empty($errorInvoices)) {
        echo "No PEPPOL errors found.\n";
    } else {
        foreach ($errorInvoices as $invoice) {
            echo "- Error in Invoice: {$invoice->name}\n";
            // In a real scenario, you might want to look at the 'message' field 
            // of the account.edi.document linked to this move for details.
        }
    }

    // 4. Checking a specific customer's PEPPOL readiness
    echo "\nHow to check if a specific customer can receive PEPPOL invoices:\n";
    $customer = $app->customers->get(1); // Replace with a real ID
    if ($customer) {
        if ($customer->isPeppolEnabled()) {
            echo "Customer '{$customer->name}' is ready to receive PEPPOL invoices.\n";
        } else {
            echo "Customer '{$customer->name}' is NOT configured for PEPPOL.\n";
        }
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
