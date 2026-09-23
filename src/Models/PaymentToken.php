<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

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
    public ?string $providerRef; // Odoo customer reference (e.g. Stripe cus_...)
    public int $partnerId;
    public int $providerId;
    public bool $active;
    public ?int $paymentMethodId;
    public ?string $paymentMethodCode; // e.g. card, sepa_direct_debit
    public ?string $stripePaymentMethod; // Stripe PaymentMethod id (e.g. pm_...)
    public ?string $stripeMandate; // Stripe mandate reference when present

    /**
     * Decode an Odoo `payment.token` row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromOdoo(array $row): self
    {
        return new self($row);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = OdooValue::id($data['id'] ?? null) ?? 0;
        $this->name = OdooValue::stringOr($data['name'] ?? null);
        $this->paymentDetails = OdooValue::nullableString($data['payment_details'] ?? null);
        $this->providerRef = OdooValue::nullableString($data['provider_ref'] ?? null)
            ?? OdooValue::nullableString($data['acquirer_ref'] ?? null);
        $this->partnerId = OdooValue::many2oneIdOr($data['partner_id'] ?? null, 0);
        $this->providerId = OdooValue::many2oneId($data['provider_id'] ?? null)
            ?? OdooValue::many2oneId($data['acquirer_id'] ?? null)
            ?? 0;
        $this->active = !isset($data['active']) || !empty($data['active']);
        $this->paymentMethodId = OdooValue::many2oneId($data['payment_method_id'] ?? null);
        $code = OdooValue::nullableString($data['payment_method_code'] ?? null);
        $this->paymentMethodCode = ($code === null || $code === '') ? null : $code;
        $stripeMethod = OdooValue::nullableString($data['stripe_payment_method'] ?? null);
        $this->stripePaymentMethod = ($stripeMethod === null || $stripeMethod === '') ? null : $stripeMethod;
        $mandate = OdooValue::nullableString($data['stripe_mandate'] ?? null);
        $this->stripeMandate = ($mandate === null || $mandate === '') ? null : $mandate;
    }
}
