<?php

namespace LeKoala\OdooClient\Exceptions;

use LeKoala\OdooClient\OdooException;

/**
 * The remote payment action may or may not have run.
 *
 * Thrown only when the transport fails during `action_create_payments()`,
 * after the point where Odoo may already have created a payment or charged
 * the instrument. It deliberately extends OdooException directly (not
 * OdooNetworkException) so a generic network-retry handler cannot replay it.
 */
class PaymentOutcomeUnknownException extends OdooException
{
    /** @var list<int> */
    private array $invoiceIds;

    private int $wizardId;

    private ?string $communication;

    private ?int $tokenId;

    /**
     * @param list<int> $invoiceIds
     */
    public function __construct(
        array $invoiceIds,
        int $wizardId,
        ?string $communication = null,
        ?int $tokenId = null,
        ?\Throwable $previous = null
    ) {
        $this->invoiceIds = array_values(array_filter(
            $invoiceIds,
            static fn (int $id): bool => $id > 0
        ));
        $this->wizardId = $wizardId;
        $this->communication = $communication;
        $this->tokenId = $tokenId !== null && $tokenId > 0 ? $tokenId : null;

        parent::__construct(
            'The payment action did not answer: the payment may already exist. '
            . 'Do not retry blindly; look it up via findPaymentsForInvoice() / findTransactionsForInvoice().',
            0,
            $previous
        );
    }

    /**
     * @return list<int>
     */
    public function getInvoiceIds(): array
    {
        return $this->invoiceIds;
    }

    public function getWizardId(): int
    {
        return $this->wizardId;
    }

    public function getCommunication(): ?string
    {
        return $this->communication;
    }

    public function getTokenId(): ?int
    {
        return $this->tokenId;
    }
}
