<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Models\Customer;
use LeKoala\OdooClient\Models\PaymentToken;
use LeKoala\OdooClient\Inputs\CustomerInput;
use LeKoala\OdooClient\QueryOptions;
use LeKoala\OdooClient\Addon\Peppol\PeppolService;

/**
 * Service for managing Customers (Partners) in Odoo.
 *
 * This service interacts with the `res.partner` model.
 *
 * @link https://www.odoo.com/documentation/master/applications/sales/crm/contacts.html
 * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html
 */
class CustomerService
{
    private Client $client;
    private string $model = 'res.partner';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * List customers based on a domain filter.
     *
     * By default, only returns actual customers (partners with customer_rank > 0).
     *
     * @param array<int, mixed> $domain Odoo search domain
     * @param int|QueryOptions|null $options Detailed options, an explicit legacy limit, or null for all rows
     * @param bool $customersOnly If true, only return partners with customer_rank > 0
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function list(
        array $domain = [],
        int|QueryOptions|null $options = null,
        bool $customersOnly = true,
    ): array
    {
        if ($customersOnly) {
            $domain = array_merge([['customer_rank', '>', 0]], $domain);
        }

        $options = QueryOptions::from($options);
        $safeFields = $this->queryFields($options);

        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            $this->model,
            $domain,
            $safeFields,
            $options->searchReadLimit(),
            $options->offset,
            $options->order,
            $options->context,
        );
        return array_map(fn(array $data) => Customer::fromOdoo($data), $records);
    }

    /**
     * Iterate over all matching customers in deterministic pages.
     *
     * @param array<int, mixed> $domain
     * @return \Generator<int, Customer>
     */
    public function iterate(
        array $domain = [],
        ?QueryOptions $options = null,
        bool $customersOnly = true,
    ): \Generator {
        $options ??= new QueryOptions();
        if ($customersOnly) {
            $domain = array_merge([['customer_rank', '>', 0]], $domain);
        }

        foreach ($this->client->iterateSearchRead(
            $this->model,
            $domain,
            $this->queryFields($options),
            $options,
        ) as $record) {
            yield Customer::fromOdoo($record);
        }
    }

    /** @return array<int, string> */
    private function queryFields(QueryOptions $options): array
    {
        $fields = $options->fields !== [] ? $options->fields : [
            'id', 'name', 'email', 'phone', 'mobile', 'vat', 'company_type',
            'is_company', 'active', 'ref', 'comment', 'website', 'lang',
            'payment_token_count', 'peppol_eas', 'peppol_endpoint',
            'invoice_edi_format', 'peppol_verification_state', 'write_date', 'create_date',
        ];

        return $this->client->getSafeFields($this->model, $fields, $options->context);
    }

    /**
     * Get a single customer by ID.
     *
     * @param int $id The Odoo record ID
     * @return Customer|null Returns null if not found
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function get(int $id): ?Customer
    {
        $fields = [
            'id',
            'name',
            'email',
            'phone',
            'mobile',
            'vat',
            'company_type',
            'is_company',
            'active',
            'ref',
            'street',
            'street2',
            'city',
            'zip',
            'country_id',
            'state_id',
            'website',
            'lang',
            'peppol_eas',
            'peppol_endpoint',
            'invoice_edi_format',
            'peppol_verification_state',
            'payment_token_count',
            'comment',
            'write_date',
            'create_date'
        ];
        $safeFields = $this->client->getSafeFields($this->model, $fields);

        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->read($this->model, [$id], $safeFields);
        return !empty($records) ? Customer::fromOdoo($records[0]) : null;
    }

    /**
     * Create a new customer record.
     *
     * @param CustomerInput $input Typed input data
     * @return int The ID of the newly created customer
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function create(CustomerInput $input): int
    {
        return $this->client->create($this->model, $this->filterExistingFields($input->toArray()));
    }

    /**
     * Update an existing customer record from a full input object.
     *
     * `CustomerInput::toArray()` always carries `name` and `is_company`, so this
     * rewrites both. To change a single field without resending creation
     * defaults, use {@see self::updateFields()} instead.
     *
     * @param int $id The Odoo record ID
     * @param CustomerInput $input Typed input data
     * @return bool True on success
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function update(int $id, CustomerInput $input): bool
    {
        return $this->client->write($this->model, [$id], $this->filterExistingFields($input->toArray()));
    }

    /**
     * Partially update a partner without resending creation defaults.
     *
     * Use `false` as an Odoo field value when a field must be explicitly
     * cleared; omit a key to leave the remote value untouched.
     *
     * @param array<string, mixed> $values
     */
    public function updateFields(int $id, array $values): bool
    {
        return $this->client->write($this->model, [$id], $this->filterExistingFields($values));
    }

    /**
     * Drop values whose field does not exist on the instance, so a write never
     * fails on a field absent from this Odoo version or installed modules
     * (e.g. `mobile` on Odoo 19 partners). Omitted keys leave the remote value
     * untouched; an explicit `false`/`''` still clears a field.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function filterExistingFields(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $existing = $this->client->getSafeFields($this->model, array_keys($values));

        return array_intersect_key($values, array_flip($existing));
    }

    /**
     * Search customers by name, email, or reference using a case-insensitive LIKE search.
     *
     * @param string $query The search term
     * @param bool $customersOnly If true, only return partners with customer_rank > 0
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function search(
        string $query,
        bool $customersOnly = true,
        int|QueryOptions|null $options = null,
    ): array
    {
        $domain = [
            '|',
            '|',
            ['name', 'ilike', $query],
            ['email', 'ilike', $query],
            ['ref', 'ilike', $query]
        ];

        return $this->list($domain, $options, $customersOnly);
    }

    /**
     * Find customers by name.
     *
     * @param string $name The name to search for
     * @param bool $exact If true, performs an exact match. If false, performs a case-insensitive LIKE search.
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function findByName(
        string $name,
        bool $exact = false,
        int|QueryOptions|null $options = null,
    ): array
    {
        $operator = $exact ? '=' : 'ilike';
        return $this->list([['name', $operator, $name]], $options);
    }

    /**
     * Find customers by email address (exact match).
     *
     * @param string $email The email address to search for
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function findByEmail(string $email, int|QueryOptions|null $options = null): array
    {
        return $this->list([['email', '=', $email]], $options);
    }

    /**
     * Find customers by VAT number (exact match).
     *
     * @param string $vat The VAT number to search for
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function findByVat(string $vat, int|QueryOptions|null $options = null): array
    {
        return $this->list([['vat', '=', $vat]], $options);
    }

    /**
     * List only company customers (not individuals).
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listCompanies(int|QueryOptions|null $options = null): array
    {
        return $this->list([['is_company', '=', true]], $options);
    }

    /**
     * List only individual customers (not companies).
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listIndividuals(int|QueryOptions|null $options = null): array
    {
        return $this->list([['is_company', '=', false]], $options);
    }

    /**
     * Get customers from a specific country.
     *
     * @param string $countryCode ISO 2-letter country code (e.g., 'BE', 'NL')
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getByCountry(string $countryCode, int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        // First find the country ID
        /** @var array<int, array<string, mixed>> $countries */
        $countries = $this->client->searchRead(
            'res.country',
            [['code', '=', strtoupper($countryCode)]],
            ['id'],
            1,
            0,
            'id asc',
            $options->context,
        );
        if (empty($countries) || !isset($countries[0]['id']) || !is_numeric($countries[0]['id'])) {
            return [];
        }

        $countryId = (int)$countries[0]['id'];
        return $this->list([['country_id', '=', $countryId]], $options);
    }

    /**
     * List customers that have PEPPOL configured (have both EAS and endpoint set).
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Peppol\PeppolService via Odoo::$peppol.
     */
    public function listPeppolEnabled(int|QueryOptions|null $options = null): array
    {
        return (new PeppolService($this->client))->listEnabledCustomers($options);
    }

    /**
     * Get all saved payment methods (tokens) for a customer.
     * Tokens are returned in descending order by ID, so the most recent token is first.
     *
     * @param int $partnerId Odoo res.partner ID
     * @return PaymentToken[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getPaymentTokens(int $partnerId, int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        $fields = $options->fields === []
            ? []
            : $this->client->getSafeFields('payment.token', $options->fields, $options->context);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            'payment.token',
            [['partner_id', '=', $partnerId]],
            $fields,
            $options->searchReadLimit(),
            $options->offset,
            $options->order ?? 'id desc',
            $options->context,
        );
        return array_map(fn(array $data) => PaymentToken::fromOdoo($data), $records);
    }

    /** @return \Generator<int, PaymentToken> */
    public function iteratePaymentTokens(int $partnerId, ?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        if ($options->order === null) {
            $options = $options->withOrder('id desc');
        }
        $fields = $options->fields === []
            ? []
            : $this->client->getSafeFields('payment.token', $options->fields, $options->context);

        foreach ($this->client->iterateSearchRead(
            'payment.token',
            [['partner_id', '=', $partnerId]],
            $fields,
            $options,
        ) as $record) {
            yield PaymentToken::fromOdoo($record);
        }
    }
}
