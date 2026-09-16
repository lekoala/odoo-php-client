<?php

namespace LeKoala\OdooClient\Models;

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
     * @param array<string, mixed> $data
     */
    public function __construct(array $data)
    {
        $this->id = isset($data['id']) && is_int($data['id']) ? $data['id'] : 0;
        $this->name = isset($data['name']) && is_string($data['name']) ? $data['name'] : '';
        $this->billingPeriodValue = isset($data['billing_period_value']) && is_numeric($data['billing_period_value']) ? (int)$data['billing_period_value'] : 1;
        $this->billingPeriodUnit = isset($data['billing_period_unit']) && is_string($data['billing_period_unit']) ? $data['billing_period_unit'] : 'month';
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
