<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->rawData = $data;
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : null;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->email = isset($data['email']) && is_string($data['email']) ? $data['email'] : null;
        $this->phone = isset($data['phone']) && is_string($data['phone']) ? $data['phone'] : null;
        $this->mobile = isset($data['mobile']) && is_string($data['mobile']) ? $data['mobile'] : null;
        $this->vat = isset($data['vat']) && is_string($data['vat']) ? $data['vat'] : null;
        $this->street = isset($data['street']) && is_string($data['street']) ? $data['street'] : null;
        $this->street2 = isset($data['street2']) && is_string($data['street2']) ? $data['street2'] : null;
        $this->city = isset($data['city']) && is_string($data['city']) ? $data['city'] : null;
        $this->zip = isset($data['zip']) && is_string($data['zip']) ? $data['zip'] : null;
        $this->countryId = (isset($data['country_id']) && is_array($data['country_id']) && isset($data['country_id'][0]) && is_int($data['country_id'][0])) ? $data['country_id'][0] : (isset($data['country_id']) && is_int($data['country_id']) ? $data['country_id'] : null);
        $this->countryName = (isset($data['country_id']) && is_array($data['country_id']) && isset($data['country_id'][1]) && is_string($data['country_id'][1])) ? $data['country_id'][1] : null;
        $this->stateId = (isset($data['state_id']) && is_array($data['state_id']) && isset($data['state_id'][0]) && is_int($data['state_id'][0])) ? $data['state_id'][0] : (isset($data['state_id']) && is_int($data['state_id']) ? $data['state_id'] : null);
        $this->stateName = (isset($data['state_id']) && is_array($data['state_id']) && isset($data['state_id'][1]) && is_string($data['state_id'][1])) ? $data['state_id'][1] : null;
        $this->isCompany = !empty($data['is_company']);
        $this->active = !isset($data['active']) || !empty($data['active']);
        $this->ref = isset($data['ref']) && is_string($data['ref']) ? $data['ref'] : null;
        $this->website = isset($data['website']) && is_string($data['website']) ? $data['website'] : null;
        $this->lang = isset($data['lang']) && is_string($data['lang']) ? $data['lang'] : null;
        $this->comment = isset($data['comment']) && is_string($data['comment']) ? $data['comment'] : null;

        // PEPPOL fields
        $this->peppolEas = isset($data['peppol_eas']) && is_string($data['peppol_eas']) ? $data['peppol_eas'] : null;
        $this->peppolEndpoint = isset($data['peppol_endpoint']) && is_string($data['peppol_endpoint']) ? $data['peppol_endpoint'] : null;
        $this->invoiceEdiFormat = isset($data['invoice_edi_format']) && is_string($data['invoice_edi_format']) ? $data['invoice_edi_format'] : null;
        $this->peppolVerificationState = isset($data['peppol_verification_state']) && is_string($data['peppol_verification_state']) ? $data['peppol_verification_state'] : null;

        // Payment Methods
        $this->paymentTokenCount = isset($data['payment_token_count']) && is_numeric($data['payment_token_count']) ? (int)$data['payment_token_count'] : 0;

        // Sync timestamps
        $this->writeDate = isset($data['write_date']) && is_string($data['write_date']) ? $data['write_date'] : null;
        $this->createDate = isset($data['create_date']) && is_string($data['create_date']) ? $data['create_date'] : null;
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
