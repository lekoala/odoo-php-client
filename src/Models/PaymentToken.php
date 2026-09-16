<?php

namespace LeKoala\OdooClient\Models;

/**
 * Represents a saved payment method (token) in Odoo.
 *
 * Maps to the `payment.token` model.
 */
class PaymentToken
{
    public int $id;
    public string $name;         // Usually masked details like "•••• 1234"
    public ?string $paymentDetails; // Odoo 17+ field replacing name for display
    public ?string $providerRef; // external reference (e.g. Stripe pm_...)
    public int $partnerId;
    public int $providerId;
    public bool $active;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : 0;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->paymentDetails = isset($data['payment_details']) && is_string($data['payment_details']) ? $data['payment_details'] : null;
        $this->providerRef = isset($data['provider_ref']) && is_string($data['provider_ref']) ? $data['provider_ref'] : (isset($data['acquirer_ref']) && is_string($data['acquirer_ref']) ? $data['acquirer_ref'] : null);
        $this->partnerId = (isset($data['partner_id']) && is_array($data['partner_id']) && isset($data['partner_id'][0]) && is_int($data['partner_id'][0])) ? $data['partner_id'][0] : (isset($data['partner_id']) && is_int($data['partner_id']) ? $data['partner_id'] : 0);
        $this->providerId = (isset($data['provider_id']) && is_array($data['provider_id']) && isset($data['provider_id'][0]) && is_int($data['provider_id'][0])) ? $data['provider_id'][0] : (isset($data['acquirer_id']) && is_array($data['acquirer_id']) && isset($data['acquirer_id'][0]) && is_int($data['acquirer_id'][0]) ? $data['acquirer_id'][0] : (isset($data['provider_id']) && is_int($data['provider_id']) ? $data['provider_id'] : (isset($data['acquirer_id']) && is_int($data['acquirer_id']) ? $data['acquirer_id'] : 0)));
        $this->active = !isset($data['active']) || !empty($data['active']);
    }
}
