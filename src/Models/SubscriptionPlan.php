<?php

namespace LeKoala\OdooClient\Models;

use LeKoala\OdooClient\OdooValue;

/**
 * Represents a Subscription Plan (Recurring Plan) in Odoo.
 *
 * Maps to the `sale.subscription.plan` model in Odoo.
 */
class SubscriptionPlan
{
    public int $id;
    public string $name;
    public int $billingPeriodValue;   // Number of units (e.g., 1)
    public string $billingPeriodUnit; // unit (e.g., 'month', 'year')

    /**
     * Decode an Odoo `sale.subscription.plan` row.
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
        $this->billingPeriodValue = isset($data['billing_period_value']) && is_numeric($data['billing_period_value']) ? (int) $data['billing_period_value'] : 1;
        $this->billingPeriodUnit = OdooValue::stringOr($data['billing_period_unit'] ?? null, 'month');
    }

    /**
     * Helper to check if the plan is monthly.
     */
    public function isMonthly(): bool
    {
        return $this->billingPeriodValue === 1 && $this->billingPeriodUnit === 'month';
    }

    /**
     * Helper to check if the plan is annual.
     */
    public function isAnnual(): bool
    {
        return $this->billingPeriodValue === 1 && $this->billingPeriodUnit === 'year';
    }
}
