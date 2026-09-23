<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

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
     * Decode an Odoo `account.move` row.
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
        $this->partnerId = OdooValue::many2oneIdOr($data['partner_id'] ?? null, 0);
        $this->partnerName = OdooValue::many2oneName($data['partner_id'] ?? null) ?? '';
        $state = OdooValue::nullableString($data['state'] ?? null);
        $this->state = ($state === null || $state === '') ? 'draft' : $state;
        $moveType = OdooValue::nullableString($data['move_type'] ?? null);
        $this->moveType = ($moveType === null || $moveType === '') ? 'out_invoice' : $moveType;
        $this->invoiceDate = OdooValue::nullableString($data['invoice_date'] ?? null);
        $this->invoiceDateDue = OdooValue::nullableString($data['invoice_date_due'] ?? null);
        $this->amountTotal = OdooValue::floatOr($data['amount_total'] ?? null);
        $this->amountUntaxed = OdooValue::floatOr($data['amount_untaxed'] ?? null);
        $this->amountTax = OdooValue::floatOr($data['amount_tax'] ?? null);
        $this->amountResidual = OdooValue::floatOr($data['amount_residual'] ?? null);
        $paymentState = OdooValue::nullableString($data['payment_state'] ?? null);
        $this->paymentState = ($paymentState === null || $paymentState === '') ? 'not_paid' : $paymentState;

        $this->currencyId = OdooValue::many2oneId($data['currency_id'] ?? null);
        $this->currencyName = OdooValue::many2oneName($data['currency_id'] ?? null);

        $this->ref = OdooValue::nullableString($data['ref'] ?? null);
        $this->narration = OdooValue::nullableString($data['narration'] ?? null);

        // PEPPOL fields
        $this->peppolMoveState = OdooValue::nullableString($data['peppol_move_state'] ?? null);
        $this->peppolError = OdooValue::nullableString($data['edi_error_message'] ?? null);

        // Portal Access
        $this->accessToken = OdooValue::nullableString($data['access_token'] ?? null);
        $this->accessUrl = OdooValue::nullableString($data['access_url'] ?? null);

        // Sync timestamps
        $this->writeDate = OdooValue::nullableString($data['write_date'] ?? null);
        $this->createDate = OdooValue::nullableString($data['create_date'] ?? null);
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
