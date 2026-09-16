# Model DTOs

Model Data Transfer Objects (DTOs) represent typed records returned from Odoo's API via the Service classes.

This guide outlines the most commonly used properties for the primary models.

---

## Field Population (`list()` vs `get()`)

> [!NOTE]
> For performance reasons, the `list()` methods on services (like `CustomerService::list()`) often request a **compact set of fields** from Odoo compared to `get(int $id)`.
>
> As a result, when iterating over list results, some extended properties on the model DTOs (such as a customer's full address or PEPPOL configuration) may default to `null` or empty values. If you need complete data for a specific record, use the `$service->get($id)` method.

---

## Customer (`LeKoala\OdooClient\Models\Customer`)

Represents an Odoo `res.partner` record.

### Model Properties

| Property            | Type          | Description                                            |
|---------------------|---------------|--------------------------------------------------------|
| `id`                | `int|null`    | The unique Odoo ID.                                    |
| `name`              | `string`      | The customer's full name or company name.              |
| `email`             | `string|null` | Email address.                                         |
| `countryId`         | `int|null`    | Odoo country ID.                                       |
| `countryName`       | `string|null` | Country name string.                                   |
| `isCompany`         | `bool`        | True if this is a company record.                      |
| `paymentTokenCount` | `int`         | Number of saved payment methods (e.g., Stripe tokens). |
| `rawData`           | `array`       | Complete record payload returned by Odoo.              |

### Electronic Invoicing (PEPPOL) Fields

| Property           | Type          | Description                                    |
|--------------------|---------------|------------------------------------------------|
| `peppolEas`        | `string|null` | EAS code (e.g., `0208` for Belgium).           |
| `peppolEndpoint`   | `string|null` | Endpoint identifier (usually VAT or registry). |
| `invoiceEdiFormat` | `string|null` | Expected EDI format (e.g., `peppol_bis3`).     |

### Helper Methods

- `hasSavedPaymentMethod(): bool`
- `getFullAddress(): string`
- `isPeppolEnabled(): bool`

---

## Invoice (`LeKoala\OdooClient\Models\Invoice`)

Represents an Odoo `account.move` record.

### Model Properties

| Property         | Type          | Description                                       |
|------------------|---------------|---------------------------------------------------|
| `id`             | `int|null`    | The unique Odoo invoice ID.                       |
| `name`           | `string`      | Invoice reference number (e.g., `INV/2023/0001`). |
| `partnerId`      | `int`         | ID of the Customer (`res.partner`).               |
| `state`          | `string`      | Status: `draft`, `posted`, `cancel`.              |
| `moveType`       | `string`      | E.g., `out_invoice` (Customer Invoice).           |
| `amountTotal`    | `float`       | Total amount with taxes.                          |
| `amountResidual` | `float`       | Amount remaining to be paid.                      |
| `paymentState`   | `string`      | `not_paid`, `in_payment`, `paid`, `partial`.      |
| `accessUrl`      | `string|null` | Customer portal URL.                              |
| `rawData`        | `array`       | Complete record payload returned by Odoo.         |

### Helper Methods

- `isPaid(): bool`
- `isPartial(): bool`
- `getAmountDue(): float`
- `isPeppolReady(): bool`

---

## Subscription (`LeKoala\OdooClient\Models\Subscription`)

Represents an Odoo 19 subscription backed by `sale.order` with a recurring plan.

### Model Properties

| Property          | Type          | Description                                         |
|-------------------|---------------|-----------------------------------------------------|
| `id`              | `int|null`    | The unique Odoo ID.                                 |
| `partnerId`       | `int`         | Associated Customer ID.                             |
| `planId`          | `int|null`    | Template or Recurring Plan ID.                      |
| `planName`        | `string|null` | Human-readable plan name (e.g., 'Monthly Premium'). |
| `state`           | `string`      | Status: `draft`, `sale` (active), etc.              |
| `subscriptionState` | `string|null` | Subscription lifecycle state such as `3_progress`. |
| `startDate`       | `string|null` | When the subscription begins.                       |
| `nextInvoiceDate` | `string|null` | The upcoming billing date.                          |
| `paymentStatus`   | `string|null` | Payment status when that field was returned.        |
| `hasPaymentError` | `bool`        | True if the last automatic transaction failed.      |
| `rawData`         | `array`       | Complete record payload returned by Odoo.           |

### Helper Methods

- `isPaid(): bool`
- `hasPaymentError(): bool`
