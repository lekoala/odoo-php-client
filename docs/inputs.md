# Input DTOs

Input Data Transfer Objects (DTOs) are used to construct the payload for creating or updating records in Odoo via the specific Service classes.

All Input DTOs provide a `toArray()` method that formats the properties for Odoo's External JSON-2 API.

---

## CustomerInput

Used with `CustomerService::create()` or for a complete `update()` of a `res.partner` record. For partial updates, use `CustomerService::updateFields()` so omitted values are not replaced by creation defaults.

### CustomerInput Properties

| Name               | Type                   | Default      | Description                                            |
|--------------------|------------------------|--------------|--------------------------------------------------------|
| `name`             | `string`               | **Required** | The customer's full name or company name.              |
| `isCompany`        | `bool`                 | `false`      | Set to true if this is a company record.               |
| `email`            | `string`               | `null`       | Customer email address.                                |
| `phone`            | `string`               | `null`       | Customer phone number.                                 |
| `mobile`           | `string`               | `null`       | Customer mobile number.                                |
| `vat`              | `string`               | `null`       | Tax ID or VAT number.                                  |
| `street`           | `string`               | `null`       | Address line 1.                                        |
| `street2`          | `string`               | `null`       | Address line 2.                                        |
| `city`             | `string`               | `null`       | City name.                                             |
| `zip`              | `string`               | `null`       | Postal/ZIP code.                                       |
| `countryId`        | `int`                  | `null`       | Odoo country ID (`res.country`).                       |
| `stateId`          | `int`                  | `null`       | Odoo state ID (`res.country.state`).                   |
| `ref`              | `string`               | `null`       | Internal reference (e.g., your system's User ID).      |
| `website`          | `string`               | `null`       | Customer website URL.                                  |
| `lang`             | `string`               | `null`       | Customer language code (e.g., `en_US`).                |
| `peppolEas`        | `string`               | `null`       | PEPPOL EAS code (e.g., `0208`).                        |
| `peppolEndpoint`   | `string`               | `null`       | PEPPOL endpoint ID (usually VAT).                      |
| `invoiceEdiFormat` | `string`               | `null`       | Preferred EDI format (e.g., `peppol_bis3`).            |
| `extraData`        | `array<string, mixed>` | `[]`         | Any additional fields to pass to the creation payload. |

---

## InvoiceInput

Used with `InvoiceService::create()` to generate new draft invoices.

### Properties

| Name        | Type                   | Default         | Description                                                           |
|-------------|------------------------|-----------------|-----------------------------------------------------------------------|
| `partnerId` | `int`                  | **Required**    | The Odoo ID of the customer (`res.partner`).                          |
| `lines`     | `InvoiceLineInput[]`   | **Required**    | Array of invoice line items.                                          |
| `moveType`  | `string`               | `'out_invoice'` | The type of the move.                                                 |
| `extraData` | `array<string, mixed>` | `[]`            | Any additional fields to pass to the `account.move` creation payload. |

### Usage Example

```php
use LeKoala\OdooClient\Inputs\InvoiceInput;
use LeKoala\OdooClient\Inputs\InvoiceLineInput;

$input = new InvoiceInput(
    partnerId: 104,
    lines: [
        new InvoiceLineInput(productId: 25, quantity: 2)
    ],
    moveType: 'out_invoice',
    extraData: ['ref' => 'PO-12345']
);

$id = $app->invoices->create($input);
```

---

## InvoiceLineInput

Used exclusively inside the `lines` array of an `InvoiceInput`.

### Input Properties

| Name        | Type          | Default      | Description                                                                        |
|-------------|---------------|--------------|------------------------------------------------------------------------------------|
| `productId` | `int`         | **Required** | The Odoo ID of the product (`product.product`).                                    |
| `quantity`  | `float`       | `1.0`        | Quantity of the item.                                                              |
| `priceUnit` | `float|null`  | `null`       | Unit price. If null, Odoo will use the product's default list price.               |
| `name`      | `string|null` | `null`       | Custom description for the line item. If null, the product's default name is used. |

---

## SubscriptionInput

Used with `SubscriptionService::create()` or for a complete `update()` of an Odoo 19 `sale.order` subscription. For partial updates, use `SubscriptionService::updateFields()`.

### Input Properties

| Name        | Type                   | Default      | Description                                                                 |
|-------------|------------------------|--------------|-----------------------------------------------------------------------------|
| `partnerId` | `int`                  | **Required** | The Odoo ID of the customer (`res.partner`).                                |
| `planId`    | `int|null`             | `null`       | The Recurring Plan or Template ID.                                          |
| `state`     | `string|null`          | `null`       | Subscription state (e.g., `draft`, `sale`).                                 |
| `startDate` | `string|null`          | `null`       | Start date (YYYY-MM-DD format), mapped to `start_date`.                    |
| `endDate`   | `string|null`          | `null`       | Contract termination date, mapped to `end_date`.                           |
| `extraData` | `array<string, mixed>` | `[]`         | Any additional fields to pass to the creation payload.                      |

---

## PaymentInput

Used with `PaymentService::registerPayment()` to automatically reconcile payments against invoices.
The method returns a `PaymentResult`; inspect all `paymentIds` and check whether
`resolution` is `explicit`, `reconciled`, or `ambiguous` before continuing a
payment workflow.

### PaymentInput Properties

| Name                  | Type                   | Default      | Description                                                             |
|-----------------------|------------------------|--------------|-------------------------------------------------------------------------|
| `invoiceIds`          | `int|int[]`            | **Required** | The ID or array of IDs of the `account.move` invoices to pay.           |
| `amount`              | `float`                | **Required** | The payment amount.                                                     |
| `journalId`           | `int|null`             | `null`       | The bank or cash journal ID (`account.journal`).                        |
| `paymentDate`         | `string|null`          | `null`       | Date of payment (YYYY-MM-DD). If null, today's date is used by Odoo.    |
| `paymentMethodLineId` | `int|null`             | `null`       | The specific payment method line ID (e.g., Stripe, Manual).             |
| `communication`       | `string|null`          | `null`       | Payment reference or memo.                                              |
| `extraData`           | `array<string, mixed>` | `[]`         | Any additional fields to pass to the `account.payment.register` wizard. |
