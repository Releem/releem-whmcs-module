# Releem WHMCS Module: Agent Guide

This repository contains a WHMCS integration for selling Releem as a standalone product.

Use this file as the first orientation point before changing code.

## Product Model

- This is a WHMCS server/provisioning module, not a WHMCS Product Addon.
- Customers should have one Releem service and change the number of servers through WHMCS configurable-option upgrades.
- The business-facing selection is `server count`.
- The source of truth for the customer contract is `../releem_api/apiary.apib`.
- The module sends the selected server count to the Releem API as `subscription.number_servers`.

## Repo Layout

- `modules/servers/releem/releem.php`
  Main provisioning module. Most logic lives here.
- `modules/servers/releem/clientarea.tpl`
  Client-area output for installation instructions and public API key display.
- `modules/addons/releem_setup/releem_setup.php`
  Admin addon used to set up the WHMCS product cleanly.
- `README.md`
  Human-facing setup and operational notes.

## Current Architecture

### Server module

`modules/servers/releem/releem.php` contains:

- WHMCS module entry points:
  - `releem_CreateAccount()`
  - `releem_SuspendAccount()`
  - `releem_UnsuspendAccount()`
  - `releem_TerminateAccount()`
  - `releem_ChangePackage()`
  - `releem_ClientArea()`
- Releem API wrapper:
  - `ReleemServerApi::createCustomer()` -> `POST /v1/customers`
  - `ReleemServerApi::getCustomerByEmail()` -> `GET /v1/customers?filter={email}`
  - `ReleemServerApi::updateCustomer()` -> `PATCH /v1/customers/{id}`
- Setup helpers:
  - `releem_server_setup_product()`
  - `releem_server_ensure_server_count_option()`
  - `releem_server_ensure_public_api_key_field()`

### Addon module

`modules/addons/releem_setup/releem_setup.php` exists because product setup needs explicit admin UX.

It should:

- list WHMCS products using server type `releem`
- run explicit setup for a product
- create or reuse the `Servers` configurable option
- create or reuse server-count values
- create the `releem_public_api_key` product custom field
- store the detected option ID back into product module settings

Do not replace this with loose files in `includes/hooks/` unless there is a strong reason. Keeping setup packaged as a proper addon module is the preferred design.

## Configuration Model

Important module settings:

- `partner_api_key`
- `api_endpoint`
- `server_count_option_id`

### Why `server_count_option_id` exists

WHMCS products may have multiple configurable options. The module needs a stable way to know which option represents server count. Do not remove this unless you are also changing runtime detection rules carefully.

The addon module is expected to fill this automatically during setup.

## Data Model

### Product custom fields

Only one product custom field should be managed automatically:

- `releem_public_api_key`

Do not auto-create unrelated product custom fields unless requirements change.

### Internal service metadata

Internal linkage is stored in:

- `mod_releem_service_meta`

Current fields:

- `customer_id`
- `plan_id` for legacy compatibility
- `subscription_id`
- `number_servers`
- `status`
- `updated_at`

This table is used instead of product custom fields for internal linkage.

## Runtime Flow

### Provisioning and sync

All module actions funnel into `releem_server_sync_service()`.

Typical flow:

1. Read product, client, service, and selected server count.
2. Resolve Releem customer:
   - use stored `customer_id` from `mod_releem_service_meta`
   - otherwise look up by email through `GET /v1/customers?filter={email}`
   - otherwise create new customer
3. Build update payload with:
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
4. Save internal metadata.
5. Save `releem_public_api_key` from `CustomerResponse.api_key` if returned by API.

WHMCS field mapping:

- `subscription.id` -> numeric service `subscriptionid`
- `subscription.started_at` -> service `regdate` at start of day UTC
- `subscription.valid_to` -> service `nextduedate` at end of day UTC
- `subscription.number_servers` -> selected `Servers` configurable option value
- `subscription.amount` -> service `recurringamount` with fallback to `amount`
- `subscription.invoiceid` -> WHMCS `invoiceid` when present, otherwise `0`
- `subscription.payment_method` -> human-friendly WHMCS payment method label
- `subscription.status` -> `active` for active services, `deleted` for suspended, cancelled, and terminated services

### Client area

The client area should show:

- public API key
- WHM installer command for the Releem agent
- links or instructions relevant to agent installation

This is rendered by `modules/servers/releem/clientarea.tpl`.

## API Contract

Current Releem endpoints used:

- `POST /v1/customers`
- `GET /v1/customers?filter={email}`
- `PATCH /v1/customers/{id}`

Expected update payload shape:

```json
{
  "name": "Acme Corp",
  "subscription": {
    "id": 12345,
    "started_at": "2026-04-01T00:00:00Z",
    "valid_to": "2026-05-01T23:59:59Z",
    "status": "active",
    "subscription_email": "customer@example.com",
    "number_servers": 3,
    "amount": 100,
    "invoiceid": 0,
    "payment_method": "Credit Card"
  }
}
```

## Important Constraints

- Keep `releem_ConfigOptions()` read-only. It should not mutate WHMCS state on page render.
- Product setup should happen through explicit admin action in the addon module.
- Avoid adding required logic via loose WHMCS hooks in `includes/hooks/`.
- Keep the module idempotent:
  - setup should reuse existing options and fields
  - sync should not create duplicate customers when a known customer already exists
- Preserve compatibility where reasonable:
  - older installs may still have `plan_config_option_id`
  - older installs may still have `releem_customer_id` in custom fields
  - older internal metadata may still use `plan_id`

## Known Design Decisions

- Server count is the customer-facing control, not a separate plan selector.
- Releem status is derived from WHMCS service status.
- Suspend, cancel, and terminate all map to `deleted` in the API payload.
- The module does not call `DELETE /v1/customers/{id}`.
- `CustomerResponse.api_key` is the public API key shown to customers.

## Editing Guidance

When making changes:

- start in `modules/servers/releem/releem.php`
- keep the addon self-contained in `modules/addons/releem_setup/releem_setup.php`
- prefer schema-aware inserts for WHMCS tables because column sets can vary by WHMCS version
- protect admin POST actions with CSRF validation
- keep logging through `releem_server_log()`

## Safe Areas To Extend

- Better addon UX for product setup
- More robust server-count parsing
- Better client-area onboarding copy
- Better validation and error messages around missing `server_count_option_id`

## Risky Areas

- Anything that changes how `server_count_option_id` is resolved
- Anything that changes WHMCS table writes for configurable options
- Anything that changes create-vs-update customer identity logic
- Anything that moves internal metadata back into custom fields

## Manual Verification Checklist

After meaningful changes, verify:

1. Addon module activates in WHMCS.
2. `Setup Product` works once and is idempotent.
3. `Servers` configurable option is linked to the product.
4. `releem_public_api_key` field exists after setup or first successful sync.
5. Order with different server counts sends the expected `subscription.number_servers`.
6. Upgrade and downgrade server count updates Releem through `ChangePackage`.
7. Suspend, unsuspend, and terminate send expected status changes.
8. Client area still shows the install command and public key.
9. `Utilities -> Logs -> Module Log` contains useful request traces.

## If You Need To Simplify

Prefer simplifications in this order:

1. Make setup more explicit, not more magical.
2. Keep runtime sync deterministic, not heuristic.
3. Use the addon module for admin UX rather than hidden side effects.
4. Keep customer-facing choices aligned to business meaning: server count.
