<?php

namespace LeKoala\OdooClient\Services;

use LeKoala\OdooClient\Client;
use LeKoala\OdooClient\Models\Invoice;
use LeKoala\OdooClient\Inputs\InvoiceInput;
use LeKoala\OdooClient\Inputs\InvoiceLineInput;
use LeKoala\OdooClient\Events\InvoiceCreatedEvent;
use LeKoala\OdooClient\Events\InvoiceStateChangedEvent;
use LeKoala\OdooClient\Exceptions\OdooValidationException;
use LeKoala\OdooClient\Exceptions\OdooNetworkException;
use LeKoala\OdooClient\Http\HttpRequest;
use LeKoala\OdooClient\QueryOptions;
use LeKoala\OdooClient\Addon\Peppol\PeppolService;

/**
 * Service for managing Odoo Invoices (Customer Invoices).
 *
 * This service interacts with the `account.move` model (Journal Entries).
 *
 * @link https://www.odoo.com/documentation/master/applications/finance/accounting/customer_invoices.html
 * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html
 */
class InvoiceService
{
    private Client $client;
    private string $model = 'account.move';

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    /**
     * List customer invoices based on a domain filter.
     *
     * Automatically filters by `move_type = out_invoice`.
     *
     * @param array<int, mixed> $domain Additional Odoo search domain filters
     * @param int|QueryOptions|null $options Detailed options, an explicit legacy limit, or null for all rows
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function list(array $domain = [], int|QueryOptions|null $options = null): array
    {
        // For invoices, we usually want move_type = 'out_invoice'
        $defaultDomain = [['move_type', '=', 'out_invoice']];
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
        return array_map(fn(array $data) => new Invoice($data), $records);
    }

    /**
     * Iterate over all matching invoices in deterministic pages.
     *
     * @param array<int, mixed> $domain
     * @return \Generator<int, Invoice>
     */
    public function iterate(array $domain = [], ?QueryOptions $options = null): \Generator
    {
        $options ??= new QueryOptions();
        $finalDomain = array_merge([['move_type', '=', 'out_invoice']], $domain);
        foreach ($this->client->iterateSearchRead(
            $this->model,
            $finalDomain,
            $this->queryFields($options),
            $options,
        ) as $record) {
            yield new Invoice($record);
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
            'move_type',
            'invoice_date',
            'invoice_date_due',
            'amount_total',
            'amount_untaxed',
            'amount_tax',
            'amount_residual',
            'currency_id',
            'payment_state',
            'ref',
            'narration',
            'peppol_move_state',
            'edi_error_message',
            'access_token',
            'access_url',
            'write_date',
            'create_date'
        ];
        return $this->client->getSafeFields($this->model, $fields, $options->context);
    }

    /**
     * Get a single invoice by ID.
     *
     * @param int $id The Odoo record ID
     * @return Invoice|null Returns null if not found
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function get(int $id): ?Invoice
    {
        $fields = [
            'id',
            'name',
            'partner_id',
            'state',
            'move_type',
            'invoice_date',
            'invoice_date_due',
            'amount_total',
            'amount_untaxed',
            'amount_tax',
            'amount_residual',
            'currency_id',
            'payment_state',
            'ref',
            'narration',
            'peppol_move_state',
            'edi_error_message',
            'access_token',
            'access_url'
        ];
        $safeFields = $this->client->getSafeFields($this->model, $fields);

        /** @var array<int, array<string, mixed>> $records */
        $records = $this->client->read($this->model, [$id], $safeFields);
        return !empty($records) ? new Invoice($records[0]) : null;
    }

    /**
     * Create a new draft invoice.
     *
     * @param InvoiceInput $input Typed input containing partner ID and lines
     * @return int The ID of the newly created draft invoice
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function create(InvoiceInput $input): int
    {
        $id = $this->client->create($this->model, $input->toArray());
        if ($id > 0) {
            $this->client->dispatchEvent(new InvoiceCreatedEvent($id));
        }
        return $id;
    }

    /**
     * Create a standalone credit note (refund).
     *
     * @param InvoiceInput $input Typed input containing partner ID and lines
     * @return int The ID of the newly created draft credit note
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function createCreditNote(InvoiceInput $input): int
    {
        $input->moveType = 'out_refund';
        $id = $this->client->create($this->model, $input->toArray());
        if ($id > 0) {
            $this->client->dispatchEvent(new InvoiceCreatedEvent($id));
        }
        return $id;
    }

    /**
     * Reverse a posted invoice using the account.move.reversal wizard.
     *
     * @param int $id The Odoo record ID of the invoice to reverse
     * @param string $reason Optional reason for the reversal
     * @return int The ID of the newly created credit note
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function reverseInvoice(int $id, string $reason = ''): int
    {
        $context = [
            'active_model' => $this->model,
            'active_ids' => [$id]
        ];

        // 1. Create the wizard record
        $wizardData = [];
        if (!empty($reason)) {
            $wizardData['reason'] = $reason;
        }
        $wizardId = $this->client->create('account.move.reversal', $wizardData, $context);

        // 2. Execute the wizard action to reverse the moves
        /** @var array<string, mixed> $action */
        $action = $this->client->call('account.move.reversal', 'reverse_moves', [
            'ids' => [$wizardId],
            'context' => $context,
        ]);

        // In newer Odoo versions, reverse_moves returns an action array with the new move ID(s)
        // e.g. ['res_model' => 'account.move', 'res_id' => 123]
        if (isset($action['res_id']) && is_numeric($action['res_id'])) {
            $newId = (int)$action['res_id'];
            $this->client->dispatchEvent(new InvoiceCreatedEvent($newId));
            return $newId;
        }

        // If 'res_id' is missing but 'domain' is present (e.g., returning a list view)
        if (isset($action['domain']) && is_array($action['domain'])) {
            // Very simplified: look for an ID in the domain like [['id', 'in', [123]]]
            foreach ($action['domain'] as $condition) {
                if (is_array($condition) && count($condition) >= 3 && $condition[0] === 'id' && is_array($condition[2]) && !empty($condition[2])) {
                    if (is_numeric($condition[2][0])) {
                        $newId = (int)$condition[2][0];
                        $this->client->dispatchEvent(new InvoiceCreatedEvent($newId));
                        return $newId;
                    }
                }
            }
        }

        throw new \LeKoala\OdooClient\OdooException("Failed to determine new Credit Note ID from reversal wizard.");
    }

    public function isDraft(int $id): bool
    {
        $invoice = $this->get($id);
        return $invoice !== null && $invoice->state === 'draft';
    }

    /**
     * @param array<InvoiceLineInput> $lines
     */
    public function addLines(int $id, array $lines): bool
    {
        if (!$this->isDraft($id)) {
            throw new \LeKoala\OdooClient\OdooException("Cannot add lines to invoice #{$id}: not in draft state");
        }

        $commands = array_map(fn(InvoiceLineInput $line) => $line->toArray(), $lines);

        $this->client->write($this->model, [$id], ['invoice_line_ids' => $commands]);

        return true;
    }

    /**
     * Post (Confirm) a draft invoice.
     *
     * This moves the invoice from the 'draft' state to 'posted'.
     *
     * @param int $id The Odoo record ID
     * @return bool True on success
     * @throws \LeKoala\OdooClient\OdooException
     * @link https://www.odoo.com/documentation/master/developer/reference/external_api.html#calling-methods
     */
    public function confirm(int $id, bool $verifyState = true): bool
    {
        $this->client->call($this->model, 'action_post', ['ids' => [$id]]);

        if ($verifyState) {
            $invoice = $this->get($id);
            if ($invoice === null || $invoice->state !== 'posted') {
                $actualState = $invoice === null ? 'unknown' : $invoice->state;
                throw new OdooValidationException(
                    "Invoice #{$id} was not posted after action_post. Current state: {$actualState}"
                );
            }
        }

        $this->client->dispatchEvent(new InvoiceStateChangedEvent($id, 'posted'));
        return true;
    }

    /**
     * Trigger the sending of the invoice via PEPPOL (Electronic Invoicing).
     *
     * This relies on the standard Odoo action to process EDI web services or
     * send the invoice. In Odoo 16/17, this is typically handled by
     * `action_process_edi_web_services` or `action_send_and_print`.
     *
     * @param int $id The Odoo record ID of the posted invoice
     * @throws \LeKoala\OdooClient\OdooException If the action cannot be triggered
     * @deprecated Use Addon\Peppol\PeppolService via Odoo::$peppol.
     */
    public function sendViaPeppol(int $id): void
    {
        (new PeppolService($this->client))->sendInvoice($id);
    }

    /**
     * Get unpaid posted invoices for a specific partner.
     *
     * Filters for invoices in 'not_paid' or 'partial' payment state.
     *
     * @param int $partnerId The Odoo res.partner ID
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getOutstanding(int $partnerId, int|QueryOptions|null $options = null): array
    {
        return $this->list([
            ['partner_id', '=', $partnerId],
            ['payment_state', 'in', ['not_paid', 'partial']],
            ['state', '=', 'posted']
        ], $options);
    }

    /**
     * List invoices that are ready to be sent via PEPPOL.
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Peppol\PeppolService via Odoo::$peppol.
     */
    public function listPeppolReady(int|QueryOptions|null $options = null): array
    {
        return (new PeppolService($this->client))->listReadyInvoices($options);
    }

    /**
     * List invoices by their PEPPOL status.
     *
     * @param string $status One of: to_send, processing, done, error, canceled
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     * @deprecated Use Addon\Peppol\PeppolService via Odoo::$peppol.
     */
    public function listByPeppolStatus(string $status, int|QueryOptions|null $options = null): array
    {
        return (new PeppolService($this->client))->listInvoicesByStatus($status, $options);
    }

    /**
     * List all unpaid or partially paid posted invoices.
     *
     * @param int|QueryOptions|null $options Query options or explicit maximum
     * @return Invoice[]
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function listUnpaid(int|QueryOptions|null $options = null): array
    {
        return $this->list([
            ['payment_state', 'in', ['not_paid', 'partial']],
            ['state', '=', 'posted']
        ], $options);
    }

    /**
     * Download the PDF binary content for an invoice.
     *
     * @param int $id Invoice ID
     * @return string Binary PDF data
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function downloadPdf(int $id): string
    {
        $invoice = $this->ensurePortalToken($id);
        if (!$invoice || !$invoice->accessToken || !$invoice->accessUrl) {
            throw new \LeKoala\OdooClient\OdooException("Cannot download PDF: Invoice #{$id} has no portal access token");
        }

        $baseUrl = rtrim($this->client->getBaseUrl(), '/');
        $pdfUrl = "{$baseUrl}{$invoice->accessUrl}?access_token={$invoice->accessToken}&report_type=pdf&download=true";

        $pdfContent = $this->fetchPdfFromUrl($pdfUrl);

        if (empty($pdfContent)) {
            throw new \LeKoala\OdooClient\OdooException("Failed to download PDF for invoice #{$id} from portal URL");
        }

        return $pdfContent;
    }

    private function maskAccessToken(string $url): string
    {
        return (string) preg_replace('/(access_token=)[^&\s]+/i', '$1***', $url);
    }

    private function fetchPdfFromUrl(string $url): string|false
    {
        try {
            $httpResponse = $this->client->getHttpClient()->send(new HttpRequest(
                method: 'GET',
                url: $url,
                headers: [
                    'Accept' => 'application/pdf,application/octet-stream;q=0.9,*/*;q=0.8',
                    'User-Agent' => 'lekoala-odoo-php-client',
                ],
                maxRedirects: $this->client->getConfig()->getMaxRedirects(),
            ));
        } catch (OdooNetworkException $exception) {
            $this->client->getConfig()->getLogger()->warning('PDF Download failed', [
                'url' => $this->maskAccessToken($url),
                'network_error' => $exception->getMessage(),
            ]);
            return false;
        }

        $response = $httpResponse->body;
        $httpCode = $httpResponse->statusCode;
        $contentType = $httpResponse->getHeaderLine('Content-Type');
        $effectiveUrl = $httpResponse->url;

        $safeUrl = $this->maskAccessToken($url);
        $safeEffectiveUrl = $this->maskAccessToken($effectiveUrl);
        $logger = $this->client->getConfig()->getLogger();

        if ($httpCode !== 200) {
            $logger->warning('PDF Download failed', [
                'url' => $safeUrl,
                'effective_url' => $safeEffectiveUrl,
                'http_code' => $httpCode,
            ]);
            return false;
        }

        $isPdf = str_contains($contentType, 'pdf')
            || str_starts_with($response, '%PDF')
            || str_contains($contentType, 'octet-stream')
            || str_contains($contentType, 'application/binary');

        if (!$isPdf) {
            $logger->warning('PDF Download - Response is not a PDF', [
                'url' => $safeUrl,
                'content_type' => $contentType,
                'response_bytes' => strlen($response),
            ]);
            return false;
        }

        return $response;
    }

    /**
     * Generate the absolute portal URL for an invoice.
     *
     * @param int $id Invoice ID
     * @return string|null
     * @throws \LeKoala\OdooClient\OdooException
     */
    public function getPortalUrl(int $id): ?string
    {
        $invoice = $this->ensurePortalToken($id);
        if (!$invoice || !$invoice->accessToken || !$invoice->accessUrl) {
            return null;
        }

        $baseUrl = rtrim($this->client->getBaseUrl(), '/');
        return "{$baseUrl}{$invoice->accessUrl}?access_token={$invoice->accessToken}";
    }

    private function ensurePortalToken(int $id): ?Invoice
    {
        $invoice = $this->get($id);
        if ($invoice && (!$invoice->accessToken || !$invoice->accessUrl)) {
            $this->client->call($this->model, 'get_portal_url', ['ids' => [$id]]);
            $invoice = $this->get($id);
        }
        return $invoice;
    }
}
