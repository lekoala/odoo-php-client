<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Payment Status & Collection Examples ---\n";

    // 1. Finding unpaid invoices (global)
    echo "Checking for unpaid or partially paid invoices...\n";
    $unpaidInvoices = $app->invoices->listUnpaid(50);
    
    foreach ($unpaidInvoices as $invoice) {
        $status = $invoice->paymentState === 'partial' ? "PARTIAL" : "UNPAID";
        echo "- {$invoice->name} ({$status}): " . 
             "Total: {$invoice->amountTotal}, Due: {$invoice->getAmountDue()} " .
             "(Partner: {$invoice->partnerName})\n";
    }

    // 2. Finding subscription payment errors
    echo "\nChecking for subscriptions with payment transaction errors...\n";
    $failedSubscriptions = $app->subscriptions->listPaymentErrors();
    
    if (empty($failedSubscriptions)) {
        echo "Perfect: No subscription payment errors found.\n";
    } else {
        foreach ($failedSubscriptions as $sub) {
            echo "- ALERT: Payment error in subscription {$sub->name} (Customer: {$sub->partnerName})\n";
            echo "  Current Status: {$sub->getPaymentStatusLabel()}\n";
        }
    }

    // 3. Checking a specific subscription's status
    echo "\nHow to check a specific subscription's payment state:\n";
    $subId = 1; // Example ID
    $subscription = $app->subscriptions->get($subId);
    
    if ($subscription) {
        if ($subscription->isPaid()) {
            echo "Subscription {$subscription->name} is fully paid.\n";
        } elseif ($subscription->hasPaymentError()) {
            echo "Subscription {$subscription->name} HAS A PAYMENT ERROR!\n";
        } else {
            echo "Subscription {$subscription->name} status: {$subscription->getPaymentStatusLabel()}\n";
        }
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
