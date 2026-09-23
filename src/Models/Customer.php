<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

/**
 * Represents a Customer (Partner) in Odoo.
 *
 * Maps to the `res.partner` model in Odoo.
 */
class Customer
{
    public ?int $id;
    public string $name;
    public ?string $email;
    public ?string $phone;
    public ?string $mobile;
    public ?string $vat;  // Tax ID / VAT number
    public ?string $street;
    public ?string $street2;
    public ?string $city;
    public ?string $zip;
    public ?int $countryId;
    public ?string $countryName;
    public ?int $stateId;
    public ?string $stateName;
    public bool $isCompany;
    public bool $active;
    public ?string $ref;  // Internal reference
    public ?string $website;
    public ?string $lang;
    public ?string $comment; // Internal notes

    // PEPPOL / Electronic Invoicing fields
    public ?string $peppolEas;           // EAS code (e.g., '0208' for Belgium)
    public ?string $peppolEndpoint;      // Endpoint identifier (usually VAT or company registry)
    public ?string $invoiceEdiFormat;    // Preferred e-invoice format (e.g., 'peppol_bis3')
    public ?string $peppolVerificationState; // e.g., 'valid', 'not_verified'
    public int $paymentTokenCount;       // Number of saved payment methods

    // Sync timestamps
    public ?string $writeDate;
    public ?string $createDate;

    /** @var array<string, mixed> */
    public array $rawData;

    /**
     * Decode an Odoo `res.partner` row.
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
        $this->email = OdooValue::nullableString($data['email'] ?? null);
        $this->phone = OdooValue::nullableString($data['phone'] ?? null);
        $this->mobile = OdooValue::nullableString($data['mobile'] ?? null);
        $this->vat = OdooValue::nullableString($data['vat'] ?? null);
        $this->street = OdooValue::nullableString($data['street'] ?? null);
        $this->street2 = OdooValue::nullableString($data['street2'] ?? null);
        $this->city = OdooValue::nullableString($data['city'] ?? null);
        $this->zip = OdooValue::nullableString($data['zip'] ?? null);
        $this->countryId = OdooValue::many2oneId($data['country_id'] ?? null);
        $this->countryName = OdooValue::many2oneName($data['country_id'] ?? null);
        $this->stateId = OdooValue::many2oneId($data['state_id'] ?? null);
        $this->stateName = OdooValue::many2oneName($data['state_id'] ?? null);
        $this->isCompany = OdooValue::bool($data['is_company'] ?? null);
        $this->active = !isset($data['active']) || !empty($data['active']);
        $this->ref = OdooValue::nullableString($data['ref'] ?? null);
        $this->website = OdooValue::nullableString($data['website'] ?? null);
        $this->lang = OdooValue::nullableString($data['lang'] ?? null);
        $this->comment = OdooValue::nullableString($data['comment'] ?? null);

        // PEPPOL fields
        $this->peppolEas = OdooValue::nullableString($data['peppol_eas'] ?? null);
        $this->peppolEndpoint = OdooValue::nullableString($data['peppol_endpoint'] ?? null);
        $this->invoiceEdiFormat = OdooValue::nullableString($data['invoice_edi_format'] ?? null);
        $this->peppolVerificationState = OdooValue::nullableString($data['peppol_verification_state'] ?? null);

        // Payment Methods
        $this->paymentTokenCount = isset($data['payment_token_count']) && is_numeric($data['payment_token_count']) ? (int) $data['payment_token_count'] : 0;

        // Sync timestamps
        $this->writeDate = OdooValue::nullableString($data['write_date'] ?? null);
        $this->createDate = OdooValue::nullableString($data['create_date'] ?? null);
    }

    /**
     * Check if the customer has any saved payment methods.
     */
    public function hasSavedPaymentMethod(): bool
    {
        return $this->paymentTokenCount > 0;
    }

    /**
     * Get the full address as a formatted string.
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->street,
            $this->street2,
            trim(($this->zip ?? '') . ' ' . ($this->city ?? '')),
            $this->stateName,
            $this->countryName,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if the customer has PEPPOL configuration.
     */
    public function hasPeppolConfig(): bool
    {
        return !empty($this->peppolEas) && !empty($this->peppolEndpoint);
    }

    /**
     * Check if the customer is configured to receive invoices via PEPPOL.
     */
    public function isPeppolEnabled(): bool
    {
        // Odoo 17+ uses verification state as source of truth.
        if ($this->peppolVerificationState === 'valid') {
            return true;
        }

        if (!$this->hasPeppolConfig()) {
            return false;
        }

        return is_string($this->invoiceEdiFormat)
            && stripos($this->invoiceEdiFormat, 'peppol') !== false;
    }
}
