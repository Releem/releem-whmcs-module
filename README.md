# Releem WHMCS Module — Administrator Runbook

The Releem WHMCS module allows Releem partners to resell Releem as a standalone product through WHMCS. It uses a server/provisioning module (`modules/servers/releem`).

## Prerequisites

Before starting, confirm that you have:

- access to an existing WHMCS installation and administrator account;
- file access to the WHMCS installation root;
- PHP with the cURL extension enabled;
- outbound HTTPS access from WHMCS to the Releem API; and
- a Releem partner API key.

The partner API key is a secret used by WHMCS for customer-management API calls. Keep it in product Module Settings and do not publish it, put it in customer-facing instructions, or paste it into logs or support tickets. The per-customer public API key is different: it is returned after provisioning and may be shown to that customer in the client area.

## 1. Upload the module files

From this repository, copy all three paths below relative to the WHMCS installation root:

- `modules/servers/releem/releem.php`
- `modules/servers/releem/clientarea.tpl`
- `modules/addons/releem_setup/releem_setup.php`

No other files are required for the recommended standalone-product flow.

## 2. Activate the setup addon

In WHMCS, go to `Configuration -> System Settings -> Addon Modules`, find `Releem Setup`, and activate it. Grant access to the administrator roles that should be able to configure products.

This runbook uses WHMCS 9 navigation. Older WHMCS versions may use `Setup` and `Utilities` menu labels instead of `Configuration`.

## 3. Create the Releem product

1. Go to `Configuration -> System Settings -> Products/Services` and create `Releem Database Optimization`.
2. Choose a product type (commonly `Other`).
3. Open `Module Settings`, select `Releem`, and save the product.

The product must have server type `releem` before it appears in `Addons -> Releem Setup`.

## 4. Configure module settings

On the product's `Module Settings`, set:

- `partner_api_key` — required secret partner key.
- `api_endpoint` — use `https://api2.releem.com` in production, or `https://api2.dev.releem.com` for development/testing.
- `server_count_option_id` — optional numeric override for the configurable option that represents server count. The recommended setup fills this automatically.

For compatibility, older installations may use `plan_config_option_id` as a fallback. Do not expose the secret partner API key in customer-facing content or logs; the per-customer public API key is intentionally shown to that customer.

## 5. Run the recommended product setup

Go to `Addons -> Releem Setup`, select the product, and click `Setup Product`. The operation is idempotent: it reuses an existing linked `Servers` option and its group. If none is linked, it creates or reuses the `Releem Servers` group and creates or reuses its `Servers` configurable option. In both cases, it ensures values `1` through `10` and the `releem_public_api_key` product custom field exist. It also stores the detected numeric option ID in `server_count_option_id`. If the field is still missing, a successful customer sync creates it lazily when the API returns a public key.

This setup prepares the WHMCS structure only. It does not set prices or enable configurable-option upgrades.

After `Setup Product` completes, deactivating the `Releem Setup` addon does not remove the product configuration it created. Keep the Releem server/provisioning module installed and assigned to the product for provisioning and lifecycle sync.

### Manual fallback

If the setup addon is unavailable, create and link a `Servers` configurable option and its option group under `Configuration -> System Settings -> Configurable Options`. Add numeric values such as `1` through `10`, then copy the numeric option ID into `server_count_option_id` in Module Settings. Configure pricing yourself. The selected value is sent to Releem as `subscription.number_servers`.

## 6. Set pricing and upgrades

Set the product's recurring base price. Set recurring/configurable-option prices for each server-count value as needed. Add values above `10` if you sell larger deployments. On the product's `Upgrades` tab, select the `Configurable Options` checkbox so customers can change server count after ordering.

## 7. Verify the first order

For initial testing, enable WHMCS Module Log temporarily if it is not already enabled. Module logs may contain sensitive module parameters: restrict access, never share raw entries, and redact any exports.

1. Place a test order with a known server count.
2. Accept the order or run the module's `Create` action and confirm it succeeds.
3. Confirm the Releem subscription received the selected `number_servers` value.
4. Open the customer's client area and confirm the per-customer public API key and WHM installer command are displayed.
5. Change the server count and run/trigger `ChangePackage`; confirm the new value reaches Releem.
6. Test suspend, unsuspend, and terminate/cancel transitions and confirm the expected Releem status changes (`deleted` for suspended/cancelled/terminated, `active` after unsuspend).
7. Review `Configuration -> System Logs -> Module Log` for request traces, then disable module logging immediately after testing according to your site policy.

## Sync Behavior

Module functions:

- `releem_CreateAccount()` -> creates or finds the Releem customer, then syncs an active subscription
- `releem_SuspendAccount()` -> sends `status=deleted`
- `releem_UnsuspendAccount()` -> sends `status=active`
- `releem_TerminateAccount()` -> sends `status=deleted`; it does not delete the Releem customer
- `releem_ChangePackage()` -> sends the current server count and active status
- `releem_ClientArea()` -> shows the public API key and WHM instructions

Create sends:

- `email`
- `name`

Update sync sends:

- `name`
- `subscription.id`
- `subscription.started_at`
- `subscription.valid_to`
- `subscription.status`
- `subscription.subscription_email`
- `subscription.number_servers`
- `subscription.amount`
- `subscription.invoiceid`
- `subscription.payment_method`

WHMCS field mapping:

- `subscription.id` -> numeric service `subscriptionid`, with fallback to WHMCS service ID when `subscriptionid` is empty or nonnumeric
- `subscription.started_at` -> service `regdate` at start of day UTC
- `subscription.valid_to` -> service `nextduedate` at end of day UTC
- `subscription.number_servers` -> selected `Servers` configurable option value
- `subscription.amount` -> service `recurringamount` (fallback `amount`)
- `subscription.invoiceid` -> WHMCS `invoiceid` when present, otherwise `0`
- `subscription.payment_method` -> human-friendly WHMCS payment method label
- `subscription.status` -> `active` for active services, `deleted` for suspended/cancelled/terminated services

## Custom Fields Used (Product Custom Fields)

`releem_public_api_key` is the only product custom field managed automatically by this module. `Setup Product` creates or reuses it; if it is still missing, a successful sync creates it lazily when the API returns a public key.

The value is stored on the service's product custom-field record.

## Internal Metadata

The module stores internal linkage in its own table:

- `mod_releem_service_meta`

Stored there:

- `customer_id`
- `plan_id` (legacy compatibility value)
- `subscription_id`
- `number_servers`
- `status`
- `updated_at`

The module creates the `mod_releem_service_meta` table lazily during sync if it does not already exist.

## Customer-Agent Flow

After successful provisioning, the customer sees the public API key and a generated WHM installer command in the client area. The customer should:

1. Run the generated command as `root` on the target cPanel/WHM server.
2. Open `WHM -> Plugins -> Releem Database Advisor`.

The client-area template also links to the official Releem installation and troubleshooting guides. For help, contact `hello@releem.com`.

This content is rendered by `modules/servers/releem/clientarea.tpl`.

## API Endpoints Used

Developer note: the source of truth for customer payloads and responses is `../releem_api/apiary.apib`.

- `POST /v1/customers` for create
- `GET /v1/customers?filter={email}` for idempotent lookup
- `PATCH /v1/customers/{id}` for subscription/status updates

No delete operation is used. Service lifecycle changes are sent through `subscription.status`.

## Troubleshooting

- **`Releem secret key is not configured`**
  - Action: set `partner_api_key` in product `Module Settings`.

- **`Unable to resolve selected server count`**
  - Action: set `server_count_option_id` to the correct configurable option ID and ensure the customer selected a numeric value.

- **`Service subscription ID is missing or invalid`**
  - Action: ensure the WHMCS service exists and has either a numeric `subscriptionid` or a valid numeric service ID.

- **`Service registration date is missing or invalid`**
  - Action: ensure the WHMCS service `regdate` is populated with a valid date.

- **`Service next due date is missing or invalid`**
  - Action: ensure the WHMCS service `nextduedate` is populated with a valid date.

- **`Service payment method is missing`**
  - Action: ensure the WHMCS service has a payment method assigned.

- **`Multiple customers found for email ...`**
  - Action: identify the intended customer record, resolve or merge the duplicate records with Releem support, then re-run sync so the service links to one customer.

- **`Releem sync failed. Check module log.`**
  - Action: open `Configuration -> System Logs -> Module Log`, inspect the matching `sync`, `createCustomer`, or `updateCustomer` entry, correct the reported API or WHMCS data issue, and retry the module action.

## Support

- Email: `hello@releem.com`
- Docs: <https://docs.releem.com>
