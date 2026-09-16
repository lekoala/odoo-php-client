<?php

namespace LeKoala\OdooClient\Addon\Peppol;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Models\Customer;
use LeKoala\OdooClient\Models\Invoice;
use LeKoala\OdooClient\QueryOptions;
use LeKoala\OdooClient\Services\CustomerService;
use LeKoala\OdooClient\Services\InvoiceService;

/**
 * PEPPOL electronic invoicing operations.
 *
 * Covers both sides: customers configured for PEPPOL (res.partner) and
 * invoices ready to be sent via PEPPOL (account.move).
 */
class PeppolService
{
    private Client $client;
    private CustomerService $customers;
    private InvoiceService $invoices;

    public function __construct(Client $client)
    {
        $this->client = $client;
        $this->customers = new CustomerService($client);
        $this->invoices = new InvoiceService($client);
    }

    /**
     * List customers that have PEPPOL configured (have both EAS and endpoint set).
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Customer[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listEnabledCustomers(int|QueryOptions|null $options = null): array
    {
        return $this->customers->list([
            ['peppol_eas', '!=', false],
            ['peppol_endpoint', '!=', false],
        ], $options);
    }

    /**
     * List invoices that are ready to be sent via PEPPOL.
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listReadyInvoices(int|QueryOptions|null $options = null): array
    {
        return $this->invoices->list([
            ['peppol_move_state', '=', 'to_send'],
            ['state', '=', 'posted'],
        ], $options);
    }

    /**
     * List invoices by their PEPPOL status.
     *
     * @param string $status One of: to_send, processing, done, error, canceled
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listInvoicesByStatus(string $status, int|QueryOptions|null $options = null): array
    {
        return $this->invoices->list([['peppol_move_state', '=', $status]], $options);
    }

    /**
     * Trigger the sending of the invoice via PEPPOL (Electronic Invoicing).
     *
     * This relies on the standard Odoo action to process EDI web services.
     *
     * @param int $id The Odoo record ID of the posted invoice
     * @throws \LeKoala\OdooClient\OdooException If the action cannot be triggered
     */
    public function sendInvoice(int $id): void
    {
        $this->client->call('account.move', 'action_process_edi_web_services', ['ids' => [$id]]);
    }
}
