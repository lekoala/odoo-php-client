<?php

namespace LeKoala\OdooClient\Models;

class Invoice
{
    /** @var array<string, mixed> */
    public array $rawData;
    public ?int $id;
    public string $name;
    public int $partnerId;
    public string $partnerName;
    public string $state;
    public string $moveType; // out_invoice, out_refund, etc.
    public ?string $invoiceDate;
    public ?string $invoiceDateDue;
    public float $amountTotal;
    public float $amountUntaxed;
    public float $amountTax;
    public float $amountResidual;
    public string $paymentState; // not_paid, in_payment, paid, partial
    public ?int $currencyId;
    public ?string $currencyName;
    public ?string $ref;
    public ?string $narration;

    // PEPPOL / Electronic Invoicing fields
    public ?string $peppolMoveState;  // to_send, processing, done, error, canceled
    public ?string $peppolError;      // mapped from edi_error_message

    // Portal Access
    public ?string $accessToken;
    public ?string $accessUrl;

    // Sync timestamps
    public ?string $writeDate;
    public ?string $createDate;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->rawData = $data;
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : null;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->partnerId = (isset($data['partner_id']) && is_array($data['partner_id']) && isset($data['partner_id'][0]) && is_int($data['partner_id'][0])) ? $data['partner_id'][0] : (isset($data['partner_id']) && is_int($data['partner_id']) ? $data['partner_id'] : 0);
        $this->partnerName = (isset($data['partner_id']) && is_array($data['partner_id']) && isset($data['partner_id'][1]) && is_string($data['partner_id'][1])) ? $data['partner_id'][1] : '';
        $this->state = isset($data['state']) && is_string($data['state']) && $data['state'] !== '' ? $data['state'] : 'draft';
        $this->moveType = isset($data['move_type']) && is_string($data['move_type']) && $data['move_type'] !== '' ? $data['move_type'] : 'out_invoice';
        $this->invoiceDate = isset($data['invoice_date']) && is_string($data['invoice_date']) ? $data['invoice_date'] : null;
        $this->invoiceDateDue = isset($data['invoice_date_due']) && is_string($data['invoice_date_due']) ? $data['invoice_date_due'] : null;
        $this->amountTotal = isset($data['amount_total']) && is_numeric($data['amount_total']) ? (float)$data['amount_total'] : 0.0;
        $this->amountUntaxed = isset($data['amount_untaxed']) && is_numeric($data['amount_untaxed']) ? (float)$data['amount_untaxed'] : 0.0;
        $this->amountTax = isset($data['amount_tax']) && is_numeric($data['amount_tax']) ? (float)$data['amount_tax'] : 0.0;
        $this->amountResidual = isset($data['amount_residual']) && is_numeric($data['amount_residual']) ? (float)$data['amount_residual'] : 0.0;
        $this->paymentState = isset($data['payment_state']) && is_string($data['payment_state']) && $data['payment_state'] !== '' ? $data['payment_state'] : 'not_paid';

        $this->currencyId = (isset($data['currency_id']) && is_array($data['currency_id']) && isset($data['currency_id'][0]) && is_int($data['currency_id'][0])) ? $data['currency_id'][0] : (isset($data['currency_id']) && is_int($data['currency_id']) ? $data['currency_id'] : null);
        $this->currencyName = (isset($data['currency_id']) && is_array($data['currency_id']) && isset($data['currency_id'][1]) && is_string($data['currency_id'][1])) ? $data['currency_id'][1] : null;

        $this->ref = isset($data['ref']) && is_string($data['ref']) ? $data['ref'] : null;
        $this->narration = isset($data['narration']) && is_string($data['narration']) ? $data['narration'] : null;

        // PEPPOL fields
        $this->peppolMoveState = isset($data['peppol_move_state']) && is_string($data['peppol_move_state']) ? $data['peppol_move_state'] : null;
        $this->peppolError = isset($data['edi_error_message']) && is_string($data['edi_error_message']) ? $data['edi_error_message'] : null;

        // Portal Access
        $this->accessToken = isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : null;
        $this->accessUrl = isset($data['access_url']) && is_string($data['access_url']) ? $data['access_url'] : null;

        // Sync timestamps
        $this->writeDate = isset($data['write_date']) && is_string($data['write_date']) ? $data['write_date'] : null;
        $this->createDate = isset($data['create_date']) && is_string($data['create_date']) ? $data['create_date'] : null;
    }

    /**
     * Check if the invoice is fully paid.
     */
    public function isPaid(): bool
    {
        return $this->state === 'posted' && $this->paymentState === 'paid';
    }

    /**
     * Check if the invoice is partially paid.
     */
    public function isPartial(): bool
    {
        return $this->state === 'posted' && $this->paymentState === 'partial';
    }

    /**
     * Check if the invoice is not paid at all.
     */
    public function isNotPaid(): bool
    {
        return $this->state === 'posted' && $this->paymentState === 'not_paid';
    }

    /**
     * Get the remaining amount to be paid.
     */
    public function getAmountDue(): float
    {
        return $this->amountResidual;
    }

    /**
     * Check if the invoice is ready to be sent via PEPPOL.
     */
    public function isPeppolReady(): bool
    {
        return $this->state === 'posted' && $this->peppolMoveState === 'to_send';
    }

    /**
     * Check if the invoice was successfully sent via PEPPOL.
     */
    public function isPeppolSent(): bool
    {
        return $this->peppolMoveState === 'done';
    }

    /**
     * Check if there was an error sending the invoice via PEPPOL.
     */
    public function hasPeppolError(): bool
    {
        return $this->peppolMoveState === 'error';
    }

    /**
     * Get a human-readable PEPPOL status.
     */
    public function getPeppolStatusLabel(): string
    {
        return match ($this->peppolMoveState) {
            'to_send' => 'Ready to Send',
            'processing' => 'Processing',
            'done' => 'Sent',
            'error' => 'Error',
            'canceled' => 'Canceled',
            default => 'N/A',
        };
    }
}
