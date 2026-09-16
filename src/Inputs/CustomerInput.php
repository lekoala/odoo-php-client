<?php

namespace LeKoala\OdooClient\Inputs;

class CustomerInput
{
    /**
     * @param string $name
     * @param bool $isCompany
     * @param string|null $email
     * @param string|null $phone
     * @param string|null $mobile
     * @param string|null $vat
     * @param string|null $street
     * @param string|null $street2
     * @param string|null $city
     * @param string|null $zip
     * @param int|null $countryId
     * @param int|null $stateId
     * @param string|null $ref
     * @param string|null $website
     * @param string|null $lang
     * @param string|null $peppolEas
     * @param string|null $peppolEndpoint
     * @param string|null $invoiceEdiFormat
     * @param array<string, mixed> $extraData
     */
    public function __construct(
        public string $name,
        public bool $isCompany = false,
        public ?string $email = null,
        public ?string $phone = null,
        public ?string $mobile = null,
        public ?string $vat = null,
        public ?string $street = null,
        public ?string $street2 = null,
        public ?string $city = null,
        public ?string $zip = null,
        public ?int $countryId = null,
        public ?int $stateId = null,
        public ?string $ref = null,
        public ?string $website = null,
        public ?string $lang = null,
        public ?string $peppolEas = null,
        public ?string $peppolEndpoint = null,
        public ?string $invoiceEdiFormat = null,
        public array $extraData = []
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = array_merge($this->extraData, [
            'name' => $this->name,
            'is_company' => $this->isCompany,
        ]);

        if ($this->email !== null) $data['email'] = $this->email;
        if ($this->phone !== null) $data['phone'] = $this->phone;
        if ($this->mobile !== null) $data['mobile'] = $this->mobile;
        if ($this->vat !== null) $data['vat'] = $this->vat;
        if ($this->street !== null) $data['street'] = $this->street;
        if ($this->street2 !== null) $data['street2'] = $this->street2;
        if ($this->city !== null) $data['city'] = $this->city;
        if ($this->zip !== null) $data['zip'] = $this->zip;
        if ($this->countryId !== null) $data['country_id'] = $this->countryId;
        if ($this->stateId !== null) $data['state_id'] = $this->stateId;
        if ($this->ref !== null) $data['ref'] = $this->ref;
        if ($this->website !== null) $data['website'] = $this->website;
        if ($this->lang !== null) $data['lang'] = $this->lang;
        if ($this->peppolEas !== null) {
            $data['peppol_eas'] = $this->peppolEas;
        }
        if ($this->peppolEndpoint !== null) {
            $data['peppol_endpoint'] = $this->peppolEndpoint;
        }
        if ($this->invoiceEdiFormat !== null) {
            $data['invoice_edi_format'] = $this->invoiceEdiFormat;
        }

        return $data;
    }
}
