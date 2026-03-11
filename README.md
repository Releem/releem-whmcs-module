# Releem WHMCS Module (Standalone Product)

This integration is now designed for a **standalone WHMCS product** using a **server/provisioning module** (`modules/servers/releem`), not a Product Addon module.

## What to Upload

Required module file:

- `modules/servers/releem/releem.php`

Optional legacy addon files still exist in this repo, but they are not required for standalone product flow.

## WHMCS Setup

## 1) Create Product

1. Go to `Setup -> Products/Services -> Products/Services`.
2. Create product: `Releem Database Optimization`.
3. Product Type: use your preferred type (commonly `Other`).
4. Module tab: select module `Releem`.

## 2) Configure Module Settings (Product -> Module Settings)

Set these values:

- `partner_api_key`: your Releem Partner Secret Key
- `api_endpoint`: `https://api.releem.com`
- `plan_config_option_id`: the WHMCS configurable option ID used for tier selection
- `plan_mapping`: JSON map of **configurable option value ID** -> **Releem plan_id**

Example:

```json
{"11":1,"12":2,"13":3}
```

Meaning:

- option value ID `11` -> Releem Starter (`plan_id=1`)
- option value ID `12` -> Releem Scale (`plan_id=2`)
- option value ID `13` -> Releem Business (`plan_id=3`)

## 3) Create Configurable Option for Tier

1. Go to `Setup -> Products/Services -> Configurable Options`.
2. Create a group (example: `Releem Plans`).
3. Add one dropdown/radio option (example: `Plan`).
4. Add values:
- `Starter (1 server)`
- `Scale (up to 5 servers)`
- `Business (up to 10 servers)`
5. Assign this configurable option group to the Releem product.
6. Note IDs of the option values from database/UI tooling and place them into `plan_mapping`.

Important: this module maps by **ID**, not by text label.

## 4) Product Pricing

In product pricing, set recurring prices for the base product.

For tiered pricing, use configurable option pricing per value.

## Sync Behavior

Module functions:

- `releem_CreateAccount()` -> creates/updates Releem customer
- `releem_SuspendAccount()` -> sends `status=suspended`
- `releem_UnsuspendAccount()` -> sends `status=active`
- `releem_TerminateAccount()` -> sends `status=cancelled` (no delete)
- `releem_ChangePackage()` -> updates plan/status

All sync paths send:

- numeric `plan_id`
- `status`

## Custom Fields Used (Product Custom Fields)

Stored on service custom fields:

- `releem_customer_id`
- `releem_api_key`
- `releem_plan_id`
- `releem_status`

If missing, module creates these product custom fields automatically.

## API Endpoints Used

- `POST /v1/customers` for create
- `PATCH /v1/customers/{id}` for updates

No delete operation is used.

## Testing Checklist

1. Place module file in `modules/servers/releem/releem.php`.
2. Create standalone Releem product and attach module.
3. Configure module settings (`partner_api_key`, `plan_config_option_id`, `plan_mapping`).
4. Order product with each tier and confirm correct `plan_id` in Releem.
5. Suspend/unsuspend product and confirm status updates.
6. Terminate product and confirm `status=cancelled`.
7. Check `Utilities -> Logs -> Module Log` for request flow.

## Troubleshooting

- `Partner API key not configured`
: set `partner_api_key` in product `Module Settings`.

- `Unable to resolve selected configurable option value ID`
: set `plan_config_option_id` to the correct configurable option ID and ensure the customer selected a value.

- `No plan mapping found for selected option ID`
: add that option value ID to `plan_mapping` JSON.

- `Cannot redeclare releem_ClientArea`
: resolved in latest server module by removing server-side `ClientArea` function.

## Support

- Email: `hello@releem.com`
- Docs: <https://docs.releem.com>
