<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Subscription Plan Examples ---\n";

    // 1. List available subscription plans
    echo "Fetching available subscription plans (recurrence options)...\n";
    $plans = $app->subscriptions->listPlans();
    
    foreach ($plans as $plan) {
        $type = $plan->isMonthly() ? "Monthly" : ($plan->isAnnual() ? "Annual" : "Custom");
        echo "- [ID: {$plan->id}] {$plan->name} ({$type}: {$plan->billingPeriodValue} {$plan->billingPeriodUnit})\n";
    }

    // 2. List subscriptions with their plan names
    echo "\nListing current subscriptions and their billing cycles...\n";
    $subscriptions = $app->subscriptions->list([], 10);
    
    foreach ($subscriptions as $sub) {
        echo "- Subscription: {$sub->name}\n";
        echo "  Plan: {$sub->planName} (ID: {$sub->planId})\n";
        echo "  State: {$sub->state}, Next Invoice: {$sub->nextInvoiceDate}\n";
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
