<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Customer Payment Method & Stripe Token Import Example ---\n";

    $partnerId = 1; // Example partner ID

    // 1. Check if customer has saved payment methods
    echo "Checking payment methods for Partner ID: {$partnerId}...\n";
    $customer = $app->customers->get($partnerId);
    
    if (!$customer) {
        die("Customer not found.\n");
    }

    if ($customer->hasSavedPaymentMethod()) {
        echo "✅ Customer has {$customer->paymentTokenCount} saved payment method(s).\n";
        
        // List the actual tokens
        $tokens = $app->customers->getPaymentTokens($partnerId);
        foreach ($tokens as $token) {
            echo "- [ID: {$token->id}] {$token->name} (Ref: {$token->providerRef})\n";
        }
    } else {
        echo "❌ Customer has no saved payment methods.\n";
    }

    // 2. Importing a Stripe Token
    echo "\nDemonstrating Stripe Token Import...\n";
    
    // First, resolve the single active Stripe provider in Odoo
    $stripeProviderId = $app->stripe->requireStripeProviderId();
    
    if (!$stripeProviderId) {
        echo "Stripe provider not found or not configured in Odoo. Skipping import demo.\n";
    } else {
        echo "Found Stripe Provider ID: {$stripeProviderId}\n";
        
        // Mocking external data
        $externalStripeCustomerId = "cus_123456789"; // From your external system
        $externalStripePmId = "pm_1Hh7eX2eZvKYlo2C5v1v1v1v"; // From your external system
        $maskedCard = "•••• 4242";
        
        echo "Importing token {$externalStripePmId} for customer {$customer->name}...\n";
        
        // Uncomment to actually create:
        // $newTokenId = $app->stripe->importStripeToken(
        //     $partnerId,
        //     $externalStripeCustomerId,
        //     $externalStripePmId,
        //     $maskedCard,
        //     $stripeProviderId
        // );
        // echo "✅ Successfully imported token. New Odoo Token ID: {$newTokenId}\n";
        
        echo "(Import call is commented out in this example for safety)\n";
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
