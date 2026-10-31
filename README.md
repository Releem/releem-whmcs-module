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

From this repository, copy the whole `modules/` tree into the WHMCS installation root (keep the directory structure):

- `modules/servers/releem/` (`releem.php`, `clientarea.tpl`, `assets/logos/`)
- `modules/addons/releem_setup/` (`releem_setup.php`, `hooks.php`, `assets/`)

`hooks.php` keeps Releem subscriptions in sync on renewals and runs the daily reconcile; it is only loaded while the `Releem Setup` addon is active. See `Changes.md` for release notes and upgrade steps.

## 2. Activate the setup addon

In WHMCS, go to `Configuration -> System Settings -> Addon Modules`, find `Releem Setup`, and activate it. Grant access to the administrator roles that should be able to configure products. Product Setup additionally requires the `Edit Products/Services` admin permission.

The addon has one setting, **Link existing Releem customers by email** (off by default). With it off, an existing Releem customer is only linked to a WHMCS client whose email is verified in WHMCS. On WHMCS 8, the account owner's verified user email must match the client's email used for linking. A verified user with a different email does not qualify. Enable the setting only if you trust your clients' email addresses; it does not bypass verification when displaying an adopted customer's public API key.

Keep the addon active: deactivating it stops the renewal and daily reconcile hooks.

This runbook uses WHMCS 9 navigation. Older WHMCS versions may use `Setup` and `Utilities` menu labels instead of `Configuration`.

## 3. Create the Releem product

1. Go to `Configuration -> System Settings -> Products/Services` and create `Releem Database Optimization`.
2. Choose a product type (commonly `Other`).
3. Open `Module Settings`, select `Releem`, and save the product.

The product must have server type `releem` before it appears in `Addons -> Releem Setup`.

## 4. Configure module settings

On the product's `Module Settings`, set:

The partner API token and endpoint come from a WHMCS server record (`Configuration -> System Settings -> Servers`, type `Releem`): Hostname `api2.releem.com` (or `api2.dev.releem.com` for testing), Port `443`, Secure enabled, and the partner API token as the Password. The hostname must be a `releem.com` host; the token is never sent anywhere else. Assign the server to the product through a server group.

On the product's `Module Settings`:

- `Number of Database Servers` — optional numeric override for the configurable option that represents server count. The recommended setup fills this automatically.

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

- `name` (not for customers linked by email; see Troubleshooting)
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

- `subscription.id` -> WHMCS service ID (gateway `subscriptionid` values are not used; they could collide with another service's ID)
- `subscription.started_at` -> service `regdate` at start of day UTC
- `subscription.valid_to` -> service `nextduedate` at end of day UTC
- `subscription.number_servers` -> selected `Servers` configurable option value
- `subscription.amount` -> service `amount`, in the client's WHMCS currency (no currency code is sent)
- `subscription.invoiceid` -> WHMCS `invoiceid` when present, otherwise `0`
- `subscription.payment_method` -> human-friendly WHMCS payment method label
- `subscription.status` -> `active` for active services, `deleted` for suspended/cancelled/terminated services

## Custom Fields Used (Product Custom Fields)

`releem_public_api_key` is the only product custom field managed automatically by this module. `Setup Product` creates or reuses it; if it is still missing, a successful sync creates it lazily when the API returns a public key.

The value is stored on the service's admin-only product custom-field record. For an adopted customer, the module caches
the key before email verification but only displays it after the original adoption email is verified. No additional
sync is needed after verification. Changing the profile to a different verified email does not reveal that key.

## Internal Metadata

The module stores internal linkage in its own table:

- `mod_releem_service_meta`

Stored there:

- `customer_id`
- `plan_id` (legacy compatibility value)
- `subscription_id`
- `number_servers`
- `status`
- `valid_to`
- `adopted` (customer was found by email rather than created by this module)
- `adopted_email_hash` (hash of the original email used for adoption; never replaced by later profile email changes)
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

### Restore access to an adopted customer's public API key

An adopted service can keep its key hidden when its metadata predates `adopted_email_hash`, or when the legitimate
owner changes their WHMCS email. Verifying the new address alone does not restore access: the module deliberately
requires the original adoption email. There is currently no addon action to approve a replacement address.

An administrator can approve the current address manually:

1. Independently confirm that the WHMCS client owns the **existing Releem customer ID** recorded for this service,
   using the authenticated Releem account or Releem support. WHMCS email verification alone does not prove ownership
   of that Releem customer. Record the approval in your support/change records.
2. On WHMCS 8, have the account owner verify their user email and ensure it matches the client's profile email.
   Confirm the intended service is Active. Back up its `mod_releem_service_meta` row before editing it.
3. Generate the hash with the same normalization as the module. Replace the example with the approved current email:

   ```sh
   php -r 'echo hash("sha256", strtolower(trim($argv[1]))), PHP_EOL;' 'verified@example.com'
   ```

4. In one database session, start a transaction and inspect the exact service row. Replace `12345` with its WHMCS
   service ID. Check the client ID, email, Releem customer ID and `adopted=1` against the ownership confirmation:

   ```sql
   START TRANSACTION;

   SELECT h.id AS service_id, h.userid AS client_id, c.email,
          m.customer_id, m.adopted, m.adopted_email_hash
   FROM mod_releem_service_meta AS m
   JOIN tblhosting AS h ON h.id = m.service_id
   JOIN tblclients AS c ON c.id = h.userid
   WHERE m.service_id = 12345
   FOR UPDATE;
   ```

5. Update only this service's hash. Replace all example values below, including the hash from step 3. The email
   condition must match the stored profile email exactly; it prevents applying approval to a changed address.

   ```sql
   UPDATE mod_releem_service_meta AS m
   JOIN tblhosting AS h ON h.id = m.service_id
   JOIN tblclients AS c ON c.id = h.userid
   SET m.adopted_email_hash = '<64-character-hash-from-step-3>',
       m.updated_at = UTC_TIMESTAMP()
   WHERE m.service_id = 12345
     AND h.userid = 678
     AND m.customer_id = 'confirmed-releem-customer-id'
     AND m.adopted = 1
     AND BINARY c.email = BINARY 'verified@example.com';

   SELECT ROW_COUNT() AS updated_rows;
   ```

   Expect one updated row. If the identity does not match or the update count is not one, run `ROLLBACK;` and
   investigate. Otherwise run `COMMIT;`. Keep `adopted=1`, `customer_id` and `subscription_id` unchanged. Do not
   bulk-update other services linked to the customer without separately confirming their ownership.
6. Reload the customer's service page. If the admin-only `releem_public_api_key` field already contains the key, it
   becomes visible immediately. If it is empty, run **Sync with Releem** on the existing service in the WHMCS admin
   area to refill it from the API, then reload the page. If sync succeeds but the field remains empty, contact Releem
   support. Do not remove the customer link or create a replacement customer to recover the key.

### Other errors

- **`Releem secret key is not configured or could not be decrypted`**
  - Action: add a Releem server record with the partner token as its password and assign it to the product. Re-enter the password if the WHMCS encryption hash changed.

- **`Releem server address must be a releem.com host`**
  - Action: set the server Hostname to `api2.releem.com` (or `api2.dev.releem.com`). IP addresses and other hosts are refused.

- **`A Releem customer already exists for this client email, but the email is not verified in WHMCS`**
  - Action: have the client verify their email, or enable **Link existing Releem customers by email** in the `Releem Setup` addon settings if you trust the address.

- **`Releem subscription ID ... is already used by another service`**
  - Action: an older sync used a gateway subscription ID that equals this service's ID. Contact Releem support to remap one of the subscriptions.

- **`Unable to resolve selected server count`**
  - Action: set `server_count_option_id` to the correct configurable option ID and ensure the customer selected a numeric value.

- **`Service subscription ID is missing or invalid`**
  - Action: ensure the WHMCS service exists and has a valid numeric service ID (at most 9 digits).

- **`Service registration date is missing or invalid`**
  - Action: ensure the WHMCS service `regdate` is populated with a valid date.

- **`Service next due date is missing or invalid`**
  - Action: ensure the WHMCS service `nextduedate` is populated with a valid date.

- **`Service payment method is missing`**
  - Action: ensure the WHMCS service has a payment method assigned.

- **`Customer lookup returned a full page without an exact match`**
  - Action: the Releem list endpoint returned many results and none matched the client email exactly. Ask Releem support to confirm whether the customer exists, then re-run sync.

- **`Multiple Releem customers found for the client email`**
  - Action: identify the intended customer record, resolve or merge the duplicate records with Releem support, then re-run sync so the service links to one customer.

- **`Releem sync failed. Check module log.`**
  - Action: open `Configuration -> System Logs -> Module Log`, inspect the matching `sync`, `createCustomer`, or `updateCustomer` entry, correct the reported API or WHMCS data issue, and retry the module action.

## Automated regression tests

Run each `tests/test_*.php` script with PHP 7.4 or later. They use synthetic transports and do not contact Releem or
require a WHMCS installation. For example:

```sh
php tests/test_api_response_safety.php
php tests/test_security_hardening.php
```

Browser regressions require Node.js 22 or later and Chrome/Chromium, without npm packages:

```sh
node tests/test_frontend.mjs
# For another browser executable:
CHROME_BIN=chromium node tests/test_frontend.mjs
```

These run the actual template/hook JavaScript in isolated desktop and mobile DOM fixtures. They make no API calls,
execute no installer commands, and do not replace the manual verification of WHMCS billing and theme integration.

## Support

- Email: `hello@releem.com`
- Docs: <https://docs.releem.com>
