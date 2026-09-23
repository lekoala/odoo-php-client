<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

class Subscription
{
    public ?int $id;
    public string $name;
    public int $partnerId;
    public string $partnerName;
    public ?int $planId; // Previously template_id
    public ?string $planName;
    public string $state;
    public ?string $subscriptionState;
    public ?string $startDate;
    public ?string $endDate;
    public ?string $nextInvoiceDate;
    public float $amountTotal;
    public ?int $currencyId;
    /** @var array<int> */
    public array $lineIds;
    public ?string $paymentStatus; // no_invoice, not_paid, in_payment, paid, partial
    public bool $hasPaymentError;  // Maps to payment_exception or failure tags
    public ?string $accessToken;
    public ?string $accessUrl;

    // Sync timestamps
    public ?string $writeDate;
    public ?string $createDate;

    /** @var array<string, mixed> */
    public array $rawData;

    /**
     * Decode an Odoo `sale.order` subscription row.
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
        $this->rawData = $data;
        $this->id = OdooValue::id($data['id'] ?? null);
        $this->name = OdooValue::stringOr($data['name'] ?? null);

        // Partner mapping
        $this->partnerId = OdooValue::many2oneIdOr($data['partner_id'] ?? null, 0);
        $this->partnerName = OdooValue::many2oneName($data['partner_id'] ?? null) ?? '';

        $this->planId = OdooValue::many2oneId($data['plan_id'] ?? null);
        $this->planName = OdooValue::many2oneName($data['plan_id'] ?? null);

        // sale.order states: draft, sent, sale, cancel
        $this->state = OdooValue::stringOr($data['state'] ?? null, 'draft');
        $this->subscriptionState = OdooValue::nullableString($data['subscription_state'] ?? null);

        // Date mappings for sale.order (subscriptions)
        $this->startDate = OdooValue::nullableString($data['start_date'] ?? null)
            ?? OdooValue::nullableString($data['date_start'] ?? null);
        $this->endDate = OdooValue::nullableString($data['end_date'] ?? null)
            ?? OdooValue::nullableString($data['date_end'] ?? null);
        $this->nextInvoiceDate = OdooValue::nullableString($data['next_invoice_date'] ?? null)
            ?? OdooValue::nullableString($data['recurring_next_date'] ?? null);

        $this->amountTotal = OdooValue::floatOr($data['amount_total'] ?? null);
        $this->currencyId = OdooValue::many2oneId($data['currency_id'] ?? null);

        $lineIds = isset($data['order_line']) && is_array($data['order_line'])
            ? $data['order_line']
            : ($data['recurring_invoice_line_ids'] ?? null);
        $this->lineIds = OdooValue::ids($lineIds);

        // Payment Status (Odoo 17+)
        $this->paymentStatus = OdooValue::nullableString($data['payment_status'] ?? null);
        $this->hasPaymentError = OdooValue::bool($data['payment_exception'] ?? null);

        // Portal Access
        $this->accessToken = OdooValue::nullableString($data['access_token'] ?? null);
        $this->accessUrl = OdooValue::nullableString($data['access_url'] ?? null);

        // Sync timestamps
        $this->writeDate = OdooValue::nullableString($data['write_date'] ?? null);
        $this->createDate = OdooValue::nullableString($data['create_date'] ?? null);
    }

    /**
     * Check if the subscription is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->paymentStatus === 'paid';
    }

    /**
     * Check if the subscription has a payment error.
     */
    public function hasPaymentError(): bool
    {
        return $this->hasPaymentError;
    }

    /**
     * Get a human-readable payment status.
     */
    public function getPaymentStatusLabel(): string
    {
        return match ($this->paymentStatus) {
            'no_invoice' => 'No Invoice',
            'not_paid' => 'Not Paid',
            'in_payment' => 'In Payment',
            'paid' => 'Paid',
            'partial' => 'Partially Paid',
            'over_paid' => 'Over Paid',
            default => 'Unknown',
        };
    }
}
