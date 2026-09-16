<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\Inputs\SubscriptionInput;
use LeKoala\OdooClient\OdooException;
use LeKoala\OdooClient\QueryOptions;

try {
    echo "--- Subscription Examples ---\n";

    // 1. List active subscriptions
    echo "Fetching active subscriptions...\n";
    $subscriptions = $app->subscriptions->list(
        [['state', '=', 'open']],
        new QueryOptions(limit: 5),
    );
    
    foreach ($subscriptions as $sub) {
        echo "- [ID: {$sub->id}] {$sub->name} (Partner: {$sub->partnerName})\n";
    }

    // 2. Create a new subscription
    /*
    echo "\nCreating a new subscription...\n";
    $input = new SubscriptionInput(
        partnerId: 10, // Replace with a valid partner ID
        planId: 1,     // Replace with a valid recurring plan ID
        startDate: date('Y-m-d'),
        orderLines: [
            [0, 0, ['product_id' => 5, 'product_uom_qty' => 1, 'price_unit' => 100]],
        ],
    );

    $subId = $app->subscriptions->create($input);
    echo "Created subscription with ID: $subId\n";
    */

    // 3. Cancel a subscription
    /*
    echo "\nCanceling subscription ID 123...\n";
    $app->subscriptions->cancel(123);
    echo "Subscription canceled.\n";
    */

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
