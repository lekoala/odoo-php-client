<?php

namespace LeKoala\OdooClient;

use LeKoala\OdooClient\Services\ApiKeyService;
use LeKoala\OdooClient\Services\SubscriptionService;
use LeKoala\OdooClient\Services\ProductService;
use LeKoala\OdooClient\Services\InvoiceService;
use LeKoala\OdooClient\Services\PaymentService;
use LeKoala\OdooClient\Services\CustomerService;
use LeKoala\OdooClient\Addon\Stripe\StripeService;
use LeKoala\OdooClient\Addon\Peppol\PeppolService;

class Odoo
{
    private Client $client;

    public SubscriptionService $subscriptions;
    public ProductService $products;
    public InvoiceService $invoices;
    public PaymentService $payments;
    public CustomerService $customers;
    public ApiKeyService $apiKeys;
    public StripeService $stripe;
    public PeppolService $peppol;

    public function __construct(Config|Client $config)
    {
        $this->client = $config instanceof Client ? $config : new Client($config);

        $this->subscriptions = new SubscriptionService($this->client);
        $this->products = new ProductService($this->client);
        $this->invoices = new InvoiceService($this->client);
        $this->payments = new PaymentService($this->client);
        $this->customers = new CustomerService($this->client);
        $this->apiKeys = new ApiKeyService($this->client);
        $this->stripe = new StripeService($this->client);
        $this->peppol = new PeppolService($this->client);
    }

    /**
     * Get the underlying Odoo client
     */
    public function getClient(): Client
    {
        return $this->client;
    }
}
