<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Inputs\PaymentInput;
use LeKoala\OdooClient\Events\PaymentRegisteredEvent;
use LeKoala\OdooClient\Enums\PaymentResolution;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Exceptions\PaymentOutcomeUnknownException;
use LeKoala\OdooClient\Exceptions\UnsuitablePaymentTokenException;
use LeKoala\OdooClient\Models\PaymentResult;
use LeKoala\OdooClient\Models\PaymentTransaction;
use LeKoala\OdooClient\Addon\Stripe\StripeService;
use LeKoala\OdooClient\OdooException;

/**
 * Service for registering payments against invoices.
 *
 * This service uses the `account.payment.register` wizard model to facilitate
 * payment registration and reconciliation in a single step.
 *
 * @link https://www.odoo.com/documentation/master/applications/finance/accounting/customer_invoices/payments.html
 * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html
 */
class PaymentService
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * Register a payment against one or more posted invoices.
     *
     * This creates a payment registration wizard, sets its properties, and executes the action.
     * Use `PaymentInput::$communication` as the caller-owned correlation reference:
     * it is the stable key to look the payment up again after a crash.
     *
     * @param PaymentInput $input Typed payment configuration (amount, journal, invoice IDs)
     * @return PaymentResult The resolved payments and their related transactions
     * @throws PaymentOutcomeUnknownException When the transport fails during action_create_payments().
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function registerPayment(PaymentInput $input): PaymentResult
    {
        $context = [
            'active_model' => 'account.move',
            'active_ids' => $input->getInvoiceIds(),
        ];

        $wizardId = $this->client->create('account.payment.register', $input->toArray(), $context);

        if ($wizardId <= 0) {
            return new PaymentResult([], resolution: PaymentResolution::Ambiguous);
        }

        try {
            $action = $this->client->call('account.payment.register', 'action_create_payments', [
                'ids' => [$wizardId],
                'context' => $context,
            ]);
        } catch (OdooNetworkException $exception) {
            throw new PaymentOutcomeUnknownException(
                array_values($input->getInvoiceIds()),
                $wizardId,
                $input->communication,
                $input->paymentTokenId,
                $exception
            );
        }

        return $this->resolvePaymentResult($action, $input->getInvoiceIds());
    }

    /**
     * Get a list of available payment journals (Bank or Cash).
     *
     * @return array<int, array<string, mixed>> Array of journals with id, name, and type
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getJournals(): array
    {
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->searchRead('account.journal', [['type', 'in', ['bank', 'cash']]], ['id', 'name', 'type']);
        return $records;
    }

    /**
     * Get available payment methods for a specific journal.
     *
     * Returns inbound payment methods (suitable for customer payments).
     *
     * @param int $journalId The Odoo account.journal ID
     * @return array<int, array<string, mixed>> Array of payment method lines with id and name
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getPaymentMethods(int $journalId): array
    {
        /** @var array<int, array<string, mixed>> $journal */
        $journal = $this->client->read('account.journal', [$journalId], ['outbound_payment_method_line_ids', 'inbound_payment_method_line_ids']);
        if (empty($journal)) {
            return [];
        }

        // For customer payments, we look at inbound
        $methodIds = $journal[0]['inbound_payment_method_line_ids'];
        if (!is_array($methodIds)) {
            return [];
        }
        /** @var array<int> $methodIdsTyped */
        $methodIdsTyped = [];
        foreach ($methodIds as $mId) {
            if (is_int($mId)) {
                $methodIdsTyped[] = $mId;
            }
        }
        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->read('account.payment.method.line', $methodIdsTyped, ['id', 'name']);
        return $records;
    }

    /**
     * Find the Stripe payment provider ID in Odoo.
     *
     * Company, test/live state and ambiguity are explicit: when several
     * providers match, an AmbiguousStripeProviderException is thrown instead
     * of silently returning the first row.
     *
     * @param int|null $companyId Only consider providers of this Odoo company.
     * @param string|null $expectedState Only consider providers in this state. When null, disabled providers are excluded.
     * @return int|null The record ID of the single matching Stripe provider, null when none matches.
     * @throws \LeKoala\OdooClient\Addon\Stripe\AmbiguousStripeProviderException
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe. Prefer requireStripeProviderId().
     */
    public function getStripeProviderId(?int $companyId = null, ?string $expectedState = null): ?int
    {
        return (new StripeService($this->client))->getStripeProviderId($companyId, $expectedState);
    }

    /**
     * Resolve the single usable Stripe provider ID, throwing when none or several match.
     *
     * @throws \LeKoala\OdooClient\Addon\Stripe\NoStripeProviderException
     * @throws \LeKoala\OdooClient\Addon\Stripe\AmbiguousStripeProviderException
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe.
     */
    public function requireStripeProviderId(?int $companyId = null, ?string $expectedState = null): int
    {
        return (new StripeService($this->client))->requireStripeProviderId($companyId, $expectedState);
    }

    /**
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe.
     */
    public function getCardPaymentMethodId(int $providerId): ?int
    {
        return (new StripeService($this->client))->getCardPaymentMethodId($providerId);
    }

    /**
     * Get Stripe payment providers with enough metadata to assess test/live state.
     *
     * @return array<int, array<string, mixed>>
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe.
     */
    public function getStripeProviders(): array
    {
        return (new StripeService($this->client))->getStripeProviders();
    }

    /**
     * Import an existing Stripe token (Payment Method ID) from an external system.
     *
     * @param int $partnerId Odoo res.partner ID
     * @param string $stripeCustomerId The Stripe Customer ID (cus_...)
     * @param string $stripePaymentMethodId The Stripe Payment Method ID (pm_...)
     * @param string $maskedCard Masked card to display (e.g. "•••• 4242")
     * @param int $providerId Odoo payment.provider ID (resolve it via StripeService::requireStripeProviderId())
     * @return int The ID of the existing or newly created payment.token
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe.
     */
    public function importStripeToken(
        int $partnerId,
        string $stripeCustomerId,
        string $stripePaymentMethodId,
        string $maskedCard,
        int $providerId
    ): int
    {
        return (new StripeService($this->client))->importStripeToken(
            $partnerId,
            $stripeCustomerId,
            $stripePaymentMethodId,
            $maskedCard,
            $providerId
        );
    }

    /**
     * @deprecated Use Addon\Stripe\StripeService via Odoo::$stripe.
     */
    public function findStripeToken(
        int $partnerId,
        string $stripeCustomerId,
        string $stripePaymentMethodId,
        int $providerId
    ): int {
        return (new StripeService($this->client))->findStripeToken(
            $partnerId,
            $stripeCustomerId,
            $stripePaymentMethodId,
            $providerId
        );
    }

    /**
     * @param mixed $action
     * @return list<int>
     */
    private function paymentIdsFromCreateAction(mixed $action): array
    {
        if (!is_array($action)) {
            return [];
        }

        if (isset($action['res_model']) && $action['res_model'] !== 'account.payment') {
            return [];
        }

        if (isset($action['res_ids']) && is_array($action['res_ids'])) {
            $ids = $this->normalizeIds($action['res_ids']);
            if ($ids !== []) {
                return $ids;
            }
        }

        if (isset($action['res_id']) && is_numeric($action['res_id']) && (int) $action['res_id'] > 0) {
            return [(int) $action['res_id']];
        }

        return $this->idsFromActionDomain($action['domain'] ?? null);
    }

    /**
     * @param mixed $domain
     * @return list<int>
     */
    private function idsFromActionDomain(mixed $domain): array
    {
        if (!is_array($domain)) {
            return [];
        }

        foreach ($domain as $leaf) {
            if (!is_array($leaf) || count($leaf) !== 3) {
                continue;
            }

            $leaf = array_values($leaf);

            if ($leaf[0] !== 'id') {
                continue;
            }

            if ($leaf[1] === '=' && is_numeric($leaf[2]) && (int) $leaf[2] > 0) {
                return [(int) $leaf[2]];
            }

            if ($leaf[1] === 'in' && is_array($leaf[2])) {
                return $this->normalizeIds($leaf[2]);
            }
        }

        return [];
    }

    /**
     * @return list<int>
     */
    public function findPaymentsForInvoice(int $invoiceId): array
    {
        $rawIds = $this->client->search('account.payment', [
            ['reconciled_invoice_ids', 'in', [$invoiceId]],
        ], ['order' => 'create_date desc']);

        $paymentIds = [];
        foreach ($rawIds as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $paymentIds[] = (int) $id;
            }
        }

        return $paymentIds;
    }

    /**
     * @return list<PaymentTransaction>
     */
    public function findTransactionsForInvoice(int $invoiceId): array
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $this->client->searchRead(
            'payment.transaction',
            [['invoice_ids', 'in', [$invoiceId]]],
            [
                'id',
                'state',
                'amount',
                'reference',
                'provider_reference',
                'operation',
                'token_id',
                'payment_id',
                'payment_method_code',
                'state_message',
                'last_state_change',
            ],
            0,
            0,
            'id desc'
        );

        $transactions = [];
        foreach ($rows as $row) {
            $transactions[] = PaymentTransaction::fromOdoo($row);
        }

        return $transactions;
    }

    /**
     * @return PaymentResult
     * @throws PaymentOutcomeUnknownException When the transport fails during action_create_payments().
     */
    public function chargeInvoiceWithToken(
        int $invoiceId,
        float $amount,
        int $tokenId,
        int $journalId,
        int $paymentMethodLineId,
        ?float $expectedResidual = null
    ): PaymentResult {
        if ($expectedResidual !== null) {
            $move = $this->client->read('account.move', [$invoiceId], ['state', 'amount_residual']);
            /** @var array<string, mixed> $moveRow */
            $moveRow = isset($move[0]) && is_array($move[0]) ? $move[0] : [];
            $stateValue = $moveRow['state'] ?? '';
            $residualValue = $moveRow['amount_residual'] ?? 0;
            $state = is_string($stateValue) ? $stateValue : '';
            $residual = is_numeric($residualValue) ? (float) $residualValue : 0.0;

            if ($state !== 'posted') {
                throw new UnsuitablePaymentTokenException(sprintf(
                    'Invoice %d is in state "%s": only a posted invoice can be charged.',
                    $invoiceId,
                    $state
                ));
            }

            if (abs($residual - $expectedResidual) > 0.005) {
                throw new UnsuitablePaymentTokenException(sprintf(
                    'Invoice %d has a residual of %.2f but %.2f was expected: something changed the '
                    . 'invoice since the amount was computed, so nothing was charged.',
                    $invoiceId,
                    $residual,
                    $expectedResidual
                ));
            }
        }

        $context = [
            'active_model' => 'account.move',
            'active_ids' => [$invoiceId],
        ];

        $input = new PaymentInput(
            invoiceIds: [$invoiceId],
            amount: $amount,
            journalId: $journalId,
            paymentMethodLineId: $paymentMethodLineId,
            paymentTokenId: $tokenId
        );

        $wizardId = $this->client->create('account.payment.register', $input->toArray(), $context);

        if ($wizardId <= 0) {
            throw new OdooException('account.payment.register did not return a wizard id.');
        }

        $wizardRows = $this->client->read('account.payment.register', [$wizardId], [
            'payment_token_id',
            'suitable_payment_token_ids',
            'use_electronic_payment_method',
            'amount',
        ]);
        /** @var array<string, mixed> $wizard */
        $wizard = isset($wizardRows[0]) && is_array($wizardRows[0]) ? $wizardRows[0] : [];

        $heldToken = $this->many2oneId($wizard['payment_token_id'] ?? null);
        $suitable = [];
        $suitableValues = $wizard['suitable_payment_token_ids'] ?? [];
        if (is_array($suitableValues)) {
            foreach ($suitableValues as $suitableValue) {
                if (is_numeric($suitableValue)) {
                    $suitable[] = (int) $suitableValue;
                }
            }
        }

        if (!in_array($tokenId, $suitable, true)) {
            throw new UnsuitablePaymentTokenException(sprintf(
                'Token %d is not among the suitable tokens for invoice %d (%s). Odoo would have charged '
                . 'another card; nothing was charged.',
                $tokenId,
                $invoiceId,
                $suitable === [] ? 'none' : implode(', ', array_map('strval', $suitable))
            ));
        }

        if ($heldToken !== $tokenId) {
            throw new UnsuitablePaymentTokenException(sprintf(
                'The wizard replaced token %d with %d for invoice %d; nothing was charged.',
                $tokenId,
                $heldToken,
                $invoiceId
            ));
        }

        if (empty($wizard['use_electronic_payment_method'])) {
            throw new UnsuitablePaymentTokenException(sprintf(
                'Payment method line %d is not an electronic method: it cannot carry a token, so posting '
                . 'would register a manual payment for money nobody collected.',
                $paymentMethodLineId
            ));
        }

        try {
            $action = $this->client->call('account.payment.register', 'action_create_payments', [
                'ids' => [$wizardId],
                'context' => $context,
            ]);
        } catch (OdooNetworkException $exception) {
            throw new PaymentOutcomeUnknownException(
                [$invoiceId],
                $wizardId,
                null,
                $tokenId,
                $exception
            );
        }

        return $this->resolvePaymentResult($action, $input->getInvoiceIds());
    }

    /**
     * @param mixed $value
     */
    private function many2oneId($value): int
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Process a saved payment token against an invoice using the standard payment wizard.
     *
     * @param int $invoiceId The Odoo account.move ID
     * @param float $amount The amount to charge
     * @param int $tokenId The Odoo payment.token ID
     * @param int|null $journalId Optional journal ID, if required
     * @param int|null $paymentMethodLineId Optional payment method line ID, often required for token transactions
     * @return PaymentResult The resolved payments and their related transactions
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function processPaymentToken(int $invoiceId, float $amount, int $tokenId, ?int $journalId = null, ?int $paymentMethodLineId = null): PaymentResult
    {
        $input = new PaymentInput(
            invoiceIds: [$invoiceId],
            amount: $amount,
            journalId: $journalId,
            paymentMethodLineId: $paymentMethodLineId,
            paymentTokenId: $tokenId
        );

        return $this->registerPayment($input);
    }

    /**
     * @param mixed $action
     * @param int[] $invoiceIds
     */
    private function resolvePaymentResult(mixed $action, array $invoiceIds): PaymentResult
    {
        $invoiceIds = $this->normalizeIds($invoiceIds);
        $paymentIds = $this->paymentIdsFromCreateAction($action);
        $resolution = PaymentResolution::Explicit;

        if ($paymentIds === []) {
            [$paymentIds, $isUnambiguous] = $this->reconcilePaymentIds($invoiceIds);
            $resolution = $isUnambiguous
                ? PaymentResolution::Reconciled
                : PaymentResolution::Ambiguous;
        }

        $transactionIds = [];
        $tokenIds = [];
        $paymentInvoiceIds = [];

        if ($paymentIds !== []) {
            try {
                $rows = $this->client->read('account.payment', $paymentIds, [
                    'payment_transaction_id',
                    'payment_token_id',
                    'reconciled_invoice_ids',
                ]);

                foreach ($rows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $paymentId = isset($row['id']) && is_numeric($row['id']) ? (int) $row['id'] : 0;
                    $transactionId = $this->many2oneId($row['payment_transaction_id'] ?? null);
                    $tokenId = $this->many2oneId($row['payment_token_id'] ?? null);

                    if ($transactionId > 0) {
                        $transactionIds[] = $transactionId;
                    }
                    if ($tokenId > 0) {
                        $tokenIds[] = $tokenId;
                    }
                    if ($paymentId > 0 && isset($row['reconciled_invoice_ids']) && is_array($row['reconciled_invoice_ids'])) {
                        $paymentInvoiceIds[$paymentId] = $this->normalizeIds($row['reconciled_invoice_ids']);
                    }
                }
            } catch (OdooException $exception) {
                // The payment action has already run. Keep its IDs usable and do not
                // encourage a caller to retry a potentially successful payment.
                $this->client->getConfig()->getLogger()->warning('Payment created but its metadata could not be read.', [
                    'payment_ids' => $paymentIds,
                    'exception' => $exception,
                ]);
            }
        }

        $transactionIds = $this->normalizeIds($transactionIds);
        $tokenIds = $this->normalizeIds($tokenIds);

        if ($resolution !== PaymentResolution::Ambiguous) {
            $this->dispatchPaymentEvents($paymentIds, $invoiceIds, $paymentInvoiceIds);
        }

        return new PaymentResult(
            paymentIds: $paymentIds,
            transactionIds: $transactionIds,
            tokenId: count($tokenIds) === 1 ? $tokenIds[0] : null,
            resolution: $resolution
        );
    }

    /**
     * @param list<int> $invoiceIds
     * @return array{list<int>, bool}
     */
    private function reconcilePaymentIds(array $invoiceIds): array
    {
        if ($invoiceIds === []) {
            return [[], false];
        }

        $paymentIds = [];
        $isUnambiguous = true;

        foreach ($invoiceIds as $invoiceId) {
            try {
                $matches = $this->findPaymentsForInvoice($invoiceId);
            } catch (OdooException $exception) {
                $this->client->getConfig()->getLogger()->warning('Payment created but reconciliation lookup failed.', [
                    'invoice_id' => $invoiceId,
                    'exception' => $exception,
                ]);
                $matches = [];
            }

            if (count($matches) !== 1) {
                $isUnambiguous = false;
            }

            array_push($paymentIds, ...$matches);
        }

        return [$this->normalizeIds($paymentIds), $isUnambiguous];
    }

    /**
     * @param list<int> $paymentIds
     * @param list<int> $invoiceIds
     * @param array<int, list<int>> $paymentInvoiceIds
     */
    private function dispatchPaymentEvents(array $paymentIds, array $invoiceIds, array $paymentInvoiceIds): void
    {
        foreach ($paymentIds as $paymentId) {
            $matchedInvoiceIds = array_values(array_intersect($invoiceIds, $paymentInvoiceIds[$paymentId] ?? []));

            if ($matchedInvoiceIds === [] && count($paymentIds) === 1) {
                $matchedInvoiceIds = $invoiceIds;
            } elseif ($matchedInvoiceIds === [] && count($invoiceIds) === 1) {
                $matchedInvoiceIds = $invoiceIds;
            }

            foreach ($matchedInvoiceIds as $invoiceId) {
                $this->client->dispatchEvent(new PaymentRegisteredEvent($paymentId, $invoiceId));
            }
        }
    }

    /**
     * @param array<mixed> $values
     * @return list<int>
     */
    private function normalizeIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_numeric($value) && (int) $value > 0) {
                $ids[(int) $value] = (int) $value;
            }
        }

        return array_values($ids);
    }
}
