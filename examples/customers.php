<?php

/** @var \LeKoala\OdooClient\Odoo $app */
$app = require_once __DIR__ . '/bootstrap.php';

use LeKoala\OdooClient\OdooException;

try {
    echo "--- Customer Examples ---\n";

    // 1. List the first 10 customers (lists have no hidden default limit)
    echo "Listing first 10 customers...\n";
    $customers = $app->customers->list([], 10);
    
    foreach ($customers as $customer) {
        echo "- [ID: {$customer->id}] {$customer->name} ({$customer->email})\n";
        echo "  Address: " . $customer->getFullAddress() . "\n";
    }

    // 2. Search for customers by name or email
    $query = 'Agrolait';
    echo "\nSearching for '{$query}'...\n";
    $searchResults = $app->customers->search($query);
    
    foreach ($searchResults as $result) {
        echo "- found: {$result->name} (VAT: {$result->vat})\n";
    }

    // 3. Find by exact email
    $email = 'it@agrolait.com';
    echo "\nFinding customer with email '{$email}'...\n";
    $matches = $app->customers->findByEmail($email);
    if (!empty($matches)) {
        echo "- Match found: {$matches[0]->name} (ID: {$matches[0]->id})\n";
    } else {
        echo "- No match found for email.\n";
    }

    // 4. List Company vs Individual
    echo "\nListing first 5 Belgian companies...\n";
    $belgianCompanies = $app->customers->getByCountry('BE');
    $count = 0;
    foreach ($belgianCompanies as $company) {
        if ($company->isCompany) {
            echo "- Company: {$company->name} (City: {$company->city})\n";
            if (++$count >= 5) break;
        }
    }

} catch (OdooException $e) {
    echo "Odoo Error: " . $e->getMessage() . "\n";
}
