<?php

namespace LeKoala\OdooClient\Addon\Stripe;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\OdooException;

/**
 * Stripe payment provider and token operations.
 *
 * This addon represents the Stripe configuration and objects as seen by Odoo
 * (providers, payment tokens). It is not a Stripe API client and it does not
 * handle Stripe webhooks.
 *
 * Provider resolution is explicit: company, test/live state and ambiguity
 * are never guessed. The common target is a single-company Odoo with a
 * single active Stripe provider, so disabled providers are excluded unless
 * an expected state is explicitly requested.
 */
class StripeService
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Get Stripe payment providers with enough metadata to assess test/live state.
     *
     * Odoo commonly exposes `state` as `enabled`, `test`, or `disabled`.
     * The payload stays loose because available fields vary slightly across
     * Odoo versions and installed payment modules.
     *
     * @return array<int, array<string, mixed>>
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getStripeProviders(): array
    {
        /** @var array<int, array<string, mixed>> $providers */
        $providers = $this->client->searchRead(
            'payment.provider',
            [['code', '=', 'stripe']],
            ['id', 'name', 'code', 'state', 'company_id', 'is_published']
        );

        return $providers;
    }

    /**
     * Resolve the single usable Stripe provider row.
     *
     * @param int|null $companyId Only consider providers of this Odoo company.
     * @param string|null $expectedState Only consider providers in this state (e.g. 'test', 'enabled').
     *   When null, disabled providers are excluded.
     * @return array<string, mixed> The provider row.
     * @throws NoStripeProviderException When no provider matches.
     * @throws AmbiguousStripeProviderException When several providers match.
     */
    public function requireStripeProvider(?int $companyId = null, ?string $expectedState = null): array
    {
        $candidates = $this->getStripeProviders();

        if ($companyId !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $row): bool => self::companyIdOf($row['company_id'] ?? null) === $companyId
            ));
        }

        if ($expectedState !== null) {
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $row): bool => ($row['state'] ?? null) === $expectedState
            ));
        } else {
            $candidates = array_values(array_filter(
                $candidates,
                static fn(array $row): bool => ($row['state'] ?? null) !== 'disabled'
            ));
        }

        if ($candidates === []) {
            throw new NoStripeProviderException(sprintf(
                'No Stripe payment provider found%s%s.',
                $companyId !== null ? " for company {$companyId}" : '',
                $expectedState !== null ? " in state '{$expectedState}'" : ' (disabled providers excluded)'
            ));
        }

        if (count($candidates) > 1) {
            throw new AmbiguousStripeProviderException(sprintf(
                'Several Stripe payment providers match%s%s, refusing to pick the first one: %s. Pass an explicit company and test/live state.',
                $companyId !== null ? " for company {$companyId}" : '',
                $expectedState !== null ? " in state '{$expectedState}'" : '',
                implode('; ', array_map(static fn(array $row): string => self::describeProvider($row), $candidates))
            ));
        }

        /** @var array<string, mixed> $provider */
        $provider = $candidates[0];

        return $provider;
    }

    /**
     * Resolve the single usable Stripe provider ID.
     *
     * @throws NoStripeProviderException
     * @throws AmbiguousStripeProviderException
     */
    public function requireStripeProviderId(?int $companyId = null, ?string $expectedState = null): int
    {
        $provider = $this->requireStripeProvider($companyId, $expectedState);
        $id = $provider['id'] ?? 0;

        return is_numeric($id) ? (int) $id : 0;
    }

    /**
     * Find the Stripe payment provider ID in Odoo.
     *
     * @param int|null $companyId Only consider providers of this Odoo company.
     * @param string|null $expectedState Only consider providers in this state. When null, disabled providers are excluded.
     * @return int|null The record ID of the single matching Stripe provider, null when none matches.
     * @throws AmbiguousStripeProviderException When several providers match: pass company and test/live state explicitly.
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Prefer {@see self::requireStripeProviderId()} which throws when none matches.
     */
    public function getStripeProviderId(?int $companyId = null, ?string $expectedState = null): ?int
    {
        try {
            return $this->requireStripeProviderId($companyId, $expectedState);
        } catch (NoStripeProviderException) {
            return null;
        }
    }

    /**
     * Resolve the Odoo payment method id for a provider and method code.
     *
     * @param string $methodCode Odoo payment method code (e.g. 'card', 'sepa_direct_debit').
     */
    public function getPaymentMethodId(int $providerId, string $methodCode): ?int
    {
        if ($providerId <= 0 || $methodCode === '') {
            return null;
        }

        /** @var array<int, array<string, mixed>> $methods */
        $methods = $this->client->searchRead(
            'payment.method',
            [
                ['code', '=', $methodCode],
                ['active', '=', true],
                ['provider_ids', 'in', [$providerId]],
            ],
            ['id'],
            1
        );

        if (empty($methods) || !isset($methods[0]['id']) || !is_numeric($methods[0]['id'])) {
            return null;
        }

        return (int) $methods[0]['id'];
    }

    public function getCardPaymentMethodId(int $providerId): ?int
    {
        return $this->getPaymentMethodId($providerId, 'card');
    }

    /**
     * Import an existing Stripe payment instrument (card, SEPA, ...) from an external system.
     *
     * A Stripe PaymentMethod keeps a `pm_...` id whatever its type; the Odoo
     * method code (`card`, `sepa_direct_debit`, ...) selects the Odoo payment
     * method. Odoo 19 stores `stripe_payment_method` and `stripe_mandate`
     * separately.
     *
     * @param int $partnerId Odoo res.partner ID
     * @param string $stripeCustomerId The Stripe Customer ID (cus_...)
     * @param string $stripePaymentMethodId The Stripe Payment Method ID (pm_...)
     * @param string $methodCode Odoo payment method code (e.g. 'card', 'sepa_direct_debit')
     * @param string $display Human-readable instrument label (e.g. "•••• 4242", "BE•• •••• 1234")
     * @param int $providerId Odoo payment.provider ID (resolve it via {@see self::requireStripeProviderId()})
     * @param string|null $stripeMandateId Optional Stripe mandate reference (e.g. 'mandate_...')
     * @return int The ID of the existing or newly created payment.token
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function importPaymentInstrument(
        int $partnerId,
        string $stripeCustomerId,
        string $stripePaymentMethodId,
        string $methodCode,
        string $display,
        int $providerId,
        ?string $stripeMandateId = null
    ): int {
        if (!str_starts_with($stripeCustomerId, 'cus_')) {
            throw new \InvalidArgumentException(
                "Stripe customer id expected for payment.token.provider_ref, got '{$stripeCustomerId}'."
            );
        }

        if (!str_starts_with($stripePaymentMethodId, 'pm_')) {
            throw new \InvalidArgumentException(
                "Stripe payment method id expected, got '{$stripePaymentMethodId}'."
            );
        }

        if ($methodCode === '') {
            throw new \InvalidArgumentException('Odoo payment method code must not be empty.');
        }

        if ($stripeMandateId !== null && $stripeMandateId !== '' && !str_starts_with($stripeMandateId, 'mandate_')) {
            throw new \InvalidArgumentException(
                "Stripe mandate id expected, got '{$stripeMandateId}'."
            );
        }

        $existingId = $this->findStripeToken($partnerId, $stripeCustomerId, $stripePaymentMethodId, $providerId, $stripeMandateId);
        if ($existingId > 0) {
            return $existingId;
        }

        $paymentMethodId = $this->getPaymentMethodId($providerId, $methodCode);
        if ($paymentMethodId === null) {
            throw new \RuntimeException(
                "Odoo payment provider {$providerId} has no active '{$methodCode}' payment method, "
                . 'so this instrument cannot be imported. Enable it on the provider in Odoo.'
            );
        }

        $data = [
            'payment_details' => $display,
            'partner_id' => $partnerId,
            'provider_id' => $providerId,
            'payment_method_id' => $paymentMethodId,
            'provider_ref' => $stripeCustomerId,
            'stripe_payment_method' => $stripePaymentMethodId,
            'active' => true,
        ];
        if ($stripeMandateId !== null && $stripeMandateId !== '') {
            $data['stripe_mandate'] = $stripeMandateId;
        }

        $safeKeys = $this->client->getSafeFields('payment.token', array_keys($data));
        $data = array_intersect_key($data, array_flip($safeKeys));

        if (!array_key_exists('stripe_payment_method', $data)) {
            throw new \RuntimeException(
                'payment.token has no stripe_payment_method field on this Odoo instance: a token whose '
                . 'provider_ref is the customer would leave Odoo no instrument to charge.'
            );
        }

        if ($stripeMandateId !== null && $stripeMandateId !== '' && !array_key_exists('stripe_mandate', $data)) {
            throw new \RuntimeException(
                'payment.token has no stripe_mandate field on this Odoo instance: a mandate-bound '
                . 'instrument cannot be imported safely.'
            );
        }

        $tokenId = $this->client->create('payment.token', $data);
        if ($tokenId <= 0) {
            return 0;
        }

        $stored = $this->client->read('payment.token', [$tokenId], [
            'provider_ref',
            'stripe_payment_method',
            'stripe_mandate',
        ]);
        /** @var array<string, mixed> $row */
        $row = isset($stored[0]) && is_array($stored[0]) ? $stored[0] : [];
        $providerRef = $row['provider_ref'] ?? null;
        $paymentMethod = $row['stripe_payment_method'] ?? null;
        $storedProviderRef = is_string($providerRef) ? $providerRef : '';
        $storedPaymentMethod = is_string($paymentMethod) ? $paymentMethod : '';
        $storedMandate = $row['stripe_mandate'] ?? null;
        $storedMandate = is_string($storedMandate) ? $storedMandate : '';

        $expectedMandate = $stripeMandateId ?? '';
        if ($storedProviderRef !== $stripeCustomerId
            || $storedPaymentMethod !== $stripePaymentMethodId
            || ($expectedMandate !== '' && $storedMandate !== $expectedMandate)
        ) {
            throw new \RuntimeException(sprintf(
                'payment.token %d was created as provider_ref=%s / stripe_payment_method=%s / stripe_mandate=%s instead of '
                . '%s / %s / %s. Odoo refused the values, and a token in that state makes it guess an instrument.',
                $tokenId,
                var_export($row['provider_ref'] ?? null, true),
                var_export($row['stripe_payment_method'] ?? null, true),
                var_export($row['stripe_mandate'] ?? null, true),
                $stripeCustomerId,
                $stripePaymentMethodId,
                $expectedMandate === '' ? '(none)' : $expectedMandate
            ));
        }

        return $tokenId;
    }

    /**
     * Import an existing Stripe token (Payment Method ID) from an external system.
     *
     * @param int $partnerId Odoo res.partner ID
     * @param string $stripeCustomerId The Stripe Customer ID (cus_...)
     * @param string $stripePaymentMethodId The Stripe Payment Method ID (pm_...)
     * @param string $maskedCard Masked card to display (e.g. "•••• 4242")
     * @param int $providerId Odoo payment.provider ID (resolve it via {@see self::requireStripeProviderId()})
     * @return int The ID of the existing or newly created payment.token
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function importStripeToken(
        int $partnerId,
        string $stripeCustomerId,
        string $stripePaymentMethodId,
        string $maskedCard,
        int $providerId
    ): int {
        return $this->importPaymentInstrument(
            $partnerId,
            $stripeCustomerId,
            $stripePaymentMethodId,
            'card',
            $maskedCard,
            $providerId
        );
    }

    public function findStripeToken(
        int $partnerId,
        string $stripeCustomerId,
        string $stripePaymentMethodId,
        int $providerId,
        ?string $stripeMandateId = null
    ): int {
        if ($partnerId <= 0 || $stripeCustomerId === '' || $stripePaymentMethodId === '' || $providerId <= 0) {
            return 0;
        }

        $domain = [
            ['partner_id', '=', $partnerId],
            ['provider_id', '=', $providerId],
            ['provider_ref', '=', $stripeCustomerId],
            ['stripe_payment_method', '=', $stripePaymentMethodId],
            ['active', '=', true],
        ];
        if ($stripeMandateId !== null && $stripeMandateId !== '') {
            $domain[] = ['stripe_mandate', '=', $stripeMandateId];
        }

        $ids = $this->client->search('payment.token', $domain, ['limit' => 1]);

        return isset($ids[0]) && is_numeric($ids[0]) ? (int) $ids[0] : 0;
    }

    /**
     * @param mixed $value Odoo company_id payload: [id, name], int, or false.
     */
    private static function companyIdOf(mixed $value): ?int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if ($value === false || $value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function describeProvider(array $row): string
    {
        $id = self::scalarLabel($row['id'] ?? null);
        $name = self::scalarLabel($row['name'] ?? null);
        $state = self::scalarLabel($row['state'] ?? null);
        $company = $row['company_id'] ?? null;
        $companyLabel = is_array($company)
            ? implode(' ', array_map(static fn(mixed $part): string => self::scalarLabel($part), $company))
            : self::scalarLabel($company);

        return "id={$id} name={$name} state={$state} company={$companyLabel}";
    }

    private static function scalarLabel(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value) || is_bool($value)) {
            return var_export($value, true);
        }

        return '?';
    }
}
