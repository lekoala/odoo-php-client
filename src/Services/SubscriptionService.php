<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Models\Subscription;
use LeKoala\OdooClient\Models\SubscriptionPlan;
use LeKoala\OdooClient\Inputs\SubscriptionInput;
use LeKoala\OdooClient\Events\SubscriptionCancelledEvent;
use LeKoala\OdooClient\OdooException;
use LeKoala\OdooClient\QueryOptions;

/**
 * Service for managing Odoo Subscriptions.
 *
 * In Odoo 17+, subscriptions are managed via the `sale.order` model.
 *
 * @link https://www.odoo.com/documentation/master/applications/sales/subscriptions.html
 * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html
 */
class SubscriptionService
{
    private Client $client;
    private string $model = 'sale.order';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * List subscriptions based on a domain filter.
     *
     * @param array<int, mixed> $domain Odoo search domain, e.g., [['state', '=', 'open']]
     * @param int|QueryOptions|null $options Detailed options, an explicit legacy limit, or null for all rows
     * @return Subscription[]
     * @throws \LeKoala\OdooClient\OdooException
     * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html#search-and-read
     */
    public function list(array $domain = [], int|QueryOptions|null $options = null): array
    {
        // For Odoo 17+, we filter for recurring orders
        $defaultDomain = [['is_subscription', '=', true]];
        $finalDomain = array_merge($defaultDomain, $domain);

        $options = QueryOptions::from($options);
        $safeFields = $this->queryFields($options);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            $this->model,
            $finalDomain,
            $safeFields,
            $options->searchReadLimit(),
            $options->offset,
            $options->order,
            $options->context,
        );
        return array_map(fn(array $data) => Subscription::fromOdoo($data), $records);
    }

    /**
     * Iterate over all matching subscriptions in deterministic pages.
     *
     * @param array<int, mixed> $domain
     * @return \Generator<int, Subscription>
     */
    public function iterate(array $domain = [], ?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        $finalDomain = array_merge([['is_subscription', '=', true]], $domain);
        foreach ($this->client->iterateSearchRead(
            $this->model,
            $finalDomain,
            $this->queryFields($options),
            $options,
        ) as $record) {
            yield Subscription::fromOdoo($record);
        }
    }

    /** @return array<int, string> */
    private function queryFields(QueryOptions $options): array
    {
        $fields = $options->fields !== [] ? $options->fields : [
            'id',
            'name',
            'partner_id',
            'state',
            'plan_id',
            'is_subscription',
            'subscription_state',
            'start_date',
            'end_date',
            'amount_total',
            'currency_id',
            'next_invoice_date',
            'order_line',
            'payment_exception',
            'payment_status',
            'access_token',
            'access_url',
            'write_date',
            'create_date'
        ];
        return $this->client->getSafeFields($this->model, $fields, $options->context);
    }

    /**
     * Get a single subscription by ID.
     *
     * @param int $id The Odoo record ID
     * @return Subscription|null Returns null if not found
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function get(int $id): ?Subscription
    {
        $fields = [
            'id',
            'name',
            'partner_id',
            'state',
            'plan_id',
            'is_subscription',
            'subscription_state',
            'start_date',
            'end_date',
            'amount_total',
            'currency_id',
            'next_invoice_date',
            'order_line',
            'payment_exception',
            'payment_status',
            'access_token',
            'access_url'
        ];
        $safeFields = $this->client->getSafeFields($this->model, $fields);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->read($this->model, [$id], $safeFields);
        return !empty($records) ? Subscription::fromOdoo($records[0]) : null;
    }

    /**
     * Create a new subscription record.
     *
     * @param SubscriptionInput $input Typed input data
     * @return int The ID of the newly created record
     * @throws \LeKoala\OdooClient\OdooException
     * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html#create-records
     */
    public function create(SubscriptionInput $input): int
    {
        return $this->client->create($this->model, $input->toArray());
    }

    /**
     * Update an existing subscription record.
     *
     * @param int $id The Odoo record ID
     * @param SubscriptionInput $input Typed input data
     * @return bool True on success
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function update(int $id, SubscriptionInput $input): bool
    {
        return $this->client->write($this->model, [$id], $input->toArray());
    }

    /**
     * Partially update a subscription without resending creation defaults.
     *
     * @param array<string, mixed> $values
     */
    public function updateFields(int $id, array $values): bool
    {
        return $this->client->write($this->model, [$id], $values);
    }

    /**
     * Cancel a subscription through Odoo's own workflow.
     *
     * Uses `action_cancel` rather than writing `state` directly, so Odoo
     * applies its cancellation rules. When `$verifyState` is true (the
     * default), the record is re-read and `state = cancel` is required.
     *
     * @param int $id The Odoo record ID
     * @param bool $verifyState Re-read the record and require state = cancel
     * @return bool True on success
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function cancel(int $id, bool $verifyState = true): bool
    {
        $this->client->call($this->model, 'action_cancel', ['ids' => [$id]]);

        if ($verifyState) {
            $subscription = $this->get($id);
            if ($subscription === null || $subscription->state !== 'cancel') {
                $actualState = $subscription === null ? 'unknown' : $subscription->state;
                throw new OdooException(
                    "Subscription #{$id} was not cancelled after action_cancel. Current state: {$actualState}"
                );
            }
        }

        $this->client->dispatchEvent(new SubscriptionCancelledEvent($id));
        return true;
    }

    /**
     * Get subscriptions for a specific partner (customer).
     *
     * @param int $partnerId The Odoo res.partner ID
     * @return Subscription[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getByPartner(int $partnerId, int|QueryOptions|null $options = null): array
    {
        return $this->list([['partner_id', '=', $partnerId]], $options);
    }

    /** @return \Generator<int, Subscription> */
    public function iterateByPartner(int $partnerId, ?QueryOptions $options = null): \Generator
    {
        yield from $this->iterate([['partner_id', '=', $partnerId]], $options);
    }

    /**
     * List available subscription plans (recurring plans).
     *
     * This allows discovering monthly, annual, etc. options.
     *
     * @return SubscriptionPlan[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listPlans(int|QueryOptions|null $options = null): array
    {
        $options = QueryOptions::from($options);
        $fields = $options->fields === []
            ? []
            : $this->client->getSafeFields('sale.subscription.plan', $options->fields, $options->context);
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead(
            'sale.subscription.plan',
            [],
            $fields,
            $options->searchReadLimit(),
            $options->offset,
            $options->order,
            $options->context,
        );
        return array_map(fn(array $data) => SubscriptionPlan::fromOdoo($data), $records);
    }

    /** @return \Generator<int, SubscriptionPlan> */
    public function iteratePlans(?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        $fields = $options->fields === []
            ? []
            : $this->client->getSafeFields('sale.subscription.plan', $options->fields, $options->context);
        foreach ($this->client->iterateSearchRead('sale.subscription.plan', [], $fields, $options) as $record) {
            yield SubscriptionPlan::fromOdoo($record);
        }
    }

    /**
     * List subscriptions that have payment errors (exceptions).
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Subscription[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listPaymentErrors(int|QueryOptions|null $options = null): array
    {
        return $this->list([['payment_exception', '=', true]], $options);
    }

    /**
     * Generate the absolute portal URL for a subscription.
     *
     * @param int $id Subscription ID
     * @return string|null
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getPortalUrl(int $id): ?string
    {
        $sub = $this->get($id);
        if (!$sub || !$sub->accessToken || !$sub->accessUrl) {
            return null;
        }

        $baseUrl = rtrim($this->client->getBaseUrl(), '/');
        // accessUrl usually starts with /my/orders/... or /my/subscriptions/...
        return "{$baseUrl}{$sub->accessUrl}?access_token={$sub->accessToken}";
    }
}
