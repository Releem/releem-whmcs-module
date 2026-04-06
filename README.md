# Releem WHMCS Module (Standalone Product)

This integration is now designed for a **standalone WHMCS product** using a **server/provisioning module** (`modules/servers/releem`), not a Product Addon module.

API contract note: the source of truth for customer payloads and responses is `../releem_api/apiary.apib`.

## What to Upload

Required module file:

- `modules/servers/releem/releem.php`
- `modules/servers/releem/clientarea.tpl`
- `modules/addons/releem_setup/releem_setup.php`

Optional legacy addon files still exist in this repo, but they are not required for standalone product flow.

## WHMCS Setup

## 1) Create Product

1. Go to `Setup -> Products/Services -> Products/Services`.
2. Create product: `Releem Database Optimization`.
3. Product Type: use your preferred type (commonly `Other`).
4. Module tab: select module `Releem`.

## 2) Configure Module Settings (Product -> Module Settings)

Set these values:

- `partner_api_key`: your Releem secret key used for customer-management endpoints
- `api_endpoint`: `https://api2.releem.com`
  You can switch to `https://api2.dev.releem.com` for development/testing.
- `server_count_option_id`: optional override for the WHMCS configurable option ID used for server count selection

Compatibility note: existing installs using `plan_config_option_id` continue to work as a fallback.

## 3) Create Configurable Option for Server Count

Recommended automation:

1. Upload `modules/addons/releem_setup/releem_setup.php`.
2. In WHMCS admin, activate the addon in `Configuration -> System Settings -> Addon Modules`.
3. Open `Addons -> Releem Setup`.
4. Click `Setup Product` for the target Releem product.
4. The action will:
   - create or reuse the `Servers` configurable option
   - add values `1` through `10`
   - link the option group to the product
   - create `releem_public_api_key`
   - save the detected configurable option ID into `server_count_option_id`

Manual alternative:

1. Go to `Setup -> Products/Services -> Configurable Options`.
2. Create a configurable option named `Servers` and attach it to the product.
3. Put that configurable option ID into `server_count_option_id`.
4. Add the values you want to offer, for example `1` through `10`.

The module reads the selected server count and sends it as `subscription.number_servers`.
You still need to set pricing for the option values in WHMCS if you charge different amounts by server count.

## 4) Product Pricing

In product pricing, set recurring prices for the base product.

For per-server pricing, use configurable option pricing per value.
If you need counts above `10`, add more values to the `Servers` configurable option in WHMCS.

## 5) Allow Customer Upgrades

In the product configuration, enable WHMCS upgrades for configurable options.

This is the intended path for increasing server count after the initial order.

## Sync Behavior

Module functions:

- `releem_CreateAccount()` -> creates Releem customer, then syncs subscription
- `releem_SuspendAccount()` -> sends `status=deleted`
- `releem_UnsuspendAccount()` -> sends `status=active`
- `releem_TerminateAccount()` -> sends `status=deleted` (no delete)
- `releem_ChangePackage()` -> updates server-count subscription/status
- `releem_ClientArea()` -> shows agent installation details in the client area

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

- `subscription.id` -> numeric service `subscriptionid`
- `subscription.started_at` -> service `regdate` at start of day UTC
- `subscription.valid_to` -> service `nextduedate` at end of day UTC
- `subscription.number_servers` -> selected `Servers` configurable option value
- `subscription.amount` -> service `recurringamount` (fallback `amount`)
- `subscription.invoiceid` -> WHMCS `invoiceid` when present, otherwise `0`
- `subscription.payment_method` -> human-friendly WHMCS payment method label
- `subscription.status` -> `active` for active services, `deleted` for suspended/cancelled/terminated services

## Custom Fields Used (Product Custom Fields)

Stored on service custom fields:

- `releem_public_api_key`

`releem_public_api_key` is created lazily on the first successful sync that returns the public key.

## Internal Metadata

The module stores internal linkage in its own table:

- `mod_releem_service_meta`

Stored there:

- `customer_id`
- `plan_id` (legacy compatibility value)
- `subscription_id`
- `number_servers`
- `status`

## Client Area Installation Instructions

After Releem customer creation, the module stores `releem_public_api_key` and shows:

- the public API key
- the WHM installer command for cPanel/WHM servers
- links to the official Releem installation and troubleshooting guides

This content is rendered by `modules/servers/releem/clientarea.tpl`.

## API Endpoints Used

- `POST /v1/customers` for create
- `GET /v1/customers?filter={email}` for idempotent lookup
- `PATCH /v1/customers/{id}` for subscription/status updates

No delete operation is used. Service lifecycle changes are sent through `subscription.status`.

## Testing Checklist

1. Place module file in `modules/servers/releem/releem.php`.
2. Place template file in `modules/servers/releem/clientarea.tpl`.
3. Place addon module file in `modules/addons/releem_setup/releem_setup.php`.
4. Activate the addon module in WHMCS admin.
5. Create standalone Releem product and attach module.
6. Use `Addons -> Releem Setup` to set up the product, or create the `Servers` configurable option manually.
7. Configure module settings (`partner_api_key`, `server_count_option_id`).
8. Set pricing for the server-count values in WHMCS.
9. Enable WHMCS configurable-option upgrades for the product.
10. Order product with different server counts and confirm correct `subscription.number_servers` in Releem.
11. Confirm the client area shows the Releem public API key and agent installation instructions.
12. Upgrade the server count and confirm Releem receives the new `subscription.number_servers`.
13. Suspend/unsuspend product and confirm status updates.
14. Terminate product and confirm `status=deleted`.
15. Check `Utilities -> Logs -> Module Log` for request flow.

## Troubleshooting

- `Releem secret key is not configured`
: set `partner_api_key` in product `Module Settings`.

- `Unable to resolve selected server count`
: set `server_count_option_id` to the correct configurable option ID and ensure the customer selected a numeric value.

- `Service subscription ID is missing or invalid`
: ensure the WHMCS service has a numeric `subscriptionid`.

- `Service registration date is missing or invalid`
: ensure the WHMCS service `regdate` is populated.

- `Service next due date is missing or invalid`
: ensure the WHMCS service `nextduedate` is populated.

- `Service payment method is missing`
: ensure the WHMCS service has a payment method assigned.

- `Multiple customers found for email ...`
: resolve the duplicate customer records in Releem before re-running sync.

- `Cannot redeclare releem_ClientArea`
: resolved in latest server module by removing server-side `ClientArea` function.

## Support

- Email: `hello@releem.com`
- Docs: <https://docs.releem.com>
