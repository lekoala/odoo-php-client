<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->rawData = $data;
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : null;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';

        // Partner mapping
        $this->partnerId = (isset($data['partner_id']) && is_array($data['partner_id']) && isset($data['partner_id'][0]) && is_int($data['partner_id'][0])) ? $data['partner_id'][0] : (isset($data['partner_id']) && is_int($data['partner_id']) ? $data['partner_id'] : 0);
        $this->partnerName = (isset($data['partner_id']) && is_array($data['partner_id']) && isset($data['partner_id'][1]) && is_string($data['partner_id'][1])) ? $data['partner_id'][1] : '';

        $this->planId = (isset($data['plan_id']) && is_array($data['plan_id']) && isset($data['plan_id'][0]) && is_int($data['plan_id'][0])) ? $data['plan_id'][0] : (isset($data['plan_id']) && is_int($data['plan_id']) ? $data['plan_id'] : null);
        $this->planName = (isset($data['plan_id']) && is_array($data['plan_id']) && isset($data['plan_id'][1]) && is_string($data['plan_id'][1])) ? $data['plan_id'][1] : null;

        // sale.order states: draft, sent, sale, cancel
        $this->state = isset($data['state']) && is_string($data['state']) ? $data['state'] : 'draft';
        $this->subscriptionState = isset($data['subscription_state']) && is_string($data['subscription_state'])
            ? $data['subscription_state']
            : null;

        // Date mappings for sale.order (subscriptions)
        $this->startDate = isset($data['start_date']) && is_string($data['start_date']) ? $data['start_date'] : (isset($data['date_start']) && is_string($data['date_start']) ? $data['date_start'] : null);
        $this->endDate = isset($data['end_date']) && is_string($data['end_date']) ? $data['end_date'] : (isset($data['date_end']) && is_string($data['date_end']) ? $data['date_end'] : null);
        $this->nextInvoiceDate = isset($data['next_invoice_date']) && is_string($data['next_invoice_date']) ? $data['next_invoice_date'] : (isset($data['recurring_next_date']) && is_string($data['recurring_next_date']) ? $data['recurring_next_date'] : null);

        $this->amountTotal = isset($data['amount_total']) && is_numeric($data['amount_total']) ? (float)$data['amount_total'] : 0.0;
        $this->currencyId = (isset($data['currency_id']) && is_array($data['currency_id']) && isset($data['currency_id'][0]) && is_int($data['currency_id'][0])) ? $data['currency_id'][0] : (isset($data['currency_id']) && is_int($data['currency_id']) ? $data['currency_id'] : null);

        $lineIds = isset($data['order_line']) && is_array($data['order_line']) ? $data['order_line'] : (isset($data['recurring_invoice_line_ids']) && is_array($data['recurring_invoice_line_ids']) ? $data['recurring_invoice_line_ids'] : []);
        $typed = [];
        foreach ($lineIds as $lid) {
            if (is_int($lid)) $typed[] = $lid;
        }
        $this->lineIds = $typed;

        // Payment Status (Odoo 17+)
        $this->paymentStatus = isset($data['payment_status']) && is_string($data['payment_status']) ? $data['payment_status'] : null;
        $this->hasPaymentError = !empty($data['payment_exception']);

        // Portal Access
        $this->accessToken = isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : null;
        $this->accessUrl = isset($data['access_url']) && is_string($data['access_url']) ? $data['access_url'] : null;

        // Sync timestamps
        $this->writeDate = isset($data['write_date']) && is_string($data['write_date']) ? $data['write_date'] : null;
        $this->createDate = isset($data['create_date']) && is_string($data['create_date']) ? $data['create_date'] : null;
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
