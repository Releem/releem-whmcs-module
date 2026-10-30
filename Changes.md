# Changes

All notable changes to the Releem WHMCS module. The `Releem Setup` addon version (shown in
`Addons -> Releem Setup`) is the module release number. Releases before 1.3.1 were not tracked here.

## 1.3.3 - 2026-10-30

### Security

- API error bodies are redacted before truncation, including escaped JSON keys/values, so cutting an error cannot
  leave most of a partner token or public API key in the Module Log. Non-JSON error bodies are omitted; the HTTP
  status remains available for diagnosis.
- Empty or invalid customer lookup responses no longer count as proof that a customer is absent. Valid empty lists,
  exact matches and empty successful PATCH responses remain supported.

### Fixed

- The installation wizard rejects memory limits that cannot be represented as safe whole numbers.
- Support links stay within 2000 characters after Unicode encoding and show a notice when the message is shortened.
- Windows commands clear a previously enabled query-optimization environment variable when optimization is disabled.
- The Services table scrolls within its container on narrow screens.
- The server form preserves original labels, row visibility, tooltips and placeholders, and adapts when WHMCS
  replaces the table or module selector dynamically.

### Added

- API response safety tests and dependency-free browser regressions for the wizard and server form.

## 1.3.2 - 2026-10-29

Security and reliability fixes since 1.3.1. No Releem API contract changes.

### Security

- Email adoption and adopted-customer API key visibility use the WHMCS 8 account owner's verification status and
  require the verified user email to match the client email. The legacy client verification flag is used only on
  WHMCS versions without separate user accounts.
- Adopted customers retain a hash of the original matched email. Changing the profile to a different verified email
  never reveals the original customer's key, including when reusing that customer for a sibling service.
- The public API key is cached in an admin-only custom field and displayed only after the original adoption email
  is verified. Verification makes it available immediately without another sync. Missing customer metadata or
  adoption provenance denies access. Adoption stops if its identity cannot be saved before caching the key.
- Provisioning reads the partner token from the server record with strict decryption. An undecryptable
  `serverpassword` parameter is not sent as the token. Test Connection still accepts an unsaved plain-text token.
- Legacy API endpoints now reject malformed DNS hostnames and non-root paths, and are rebuilt as canonical HTTPS
  origins from validated hostnames and ports.

### Fixed

- A successfully provisioned active service keeps ownership regardless of service ID. A later lower-ID service
  cannot take over its subscription, and unprovisioned duplicates cannot block its sync or reconciliation.
- A previously provisioned owner retains priority after suspension, so an unprovisioned lower-ID duplicate cannot
  block unsuspend or restoration. A different live provisioned owner still takes priority over a former owner.
- Suspending or terminating the owner sends `deleted` despite unprovisioned Active duplicates. Ending a duplicate
  preserves the provisioned owner's subscription. Ending an unlinked service never adopts a sibling's customer.
- Daily reconciliation calculates Free and One Time `valid_to` from the registration date in the same way as sync.
  It performs ownership queries only after detecting status, billing-window or server-count drift.
- Sync stops if the MySQL client lock is unavailable, instead of running without serialization. Acquired locks are
  released after both successful and failed syncs.
- The adoption error uses the exact addon setting name: **Link existing Releem customers by email**.

### Added

- README procedure for an administrator to restore adopted-key access after independently confirming customer
  ownership, including a guarded update of the approved email hash and recovery of an empty key cache.
- `mod_releem_service_meta.adopted_email_hash`, added automatically on first use, stores the original adoption
  identity without keeping the email itself in metadata.
- Regression coverage for service ownership and lifecycle changes, client locking, reconciliation windows, WHMCS 8
  verification, adoption provenance, key visibility and caching, and endpoint validation.

### Upgrade notes

1. Copy the whole `modules/` tree over the existing install. The Releem Setup addon now reports **1.3.2**.
2. The metadata table gains `adopted_email_hash` automatically on first use; the WHMCS database user needs permission
   to alter that table.
3. Existing adopted rows without the original email hash keep their keys hidden. The module deliberately does not
   infer adoption provenance from the current profile email.
4. The WHMCS database connection must support the MySQL named lock used by the module. If acquiring the lock fails,
   sync stops and records the error in the Module Log.

## 1.3.1 - 2026-10-25

Security and reliability release following a full code audit. No Releem API contract changes.

### Security

- **Account linking by email is now gated.** A Releem customer found by email is only linked to a WHMCS client when the
  client's email is verified in WHMCS (`tblclients.email_verified`) or an administrator enables the new addon setting
  **Link existing Releem customers by email** (`Configuration -> System Settings -> Addon Modules -> Releem Setup`).
  Previously anyone able to set a profile email could be linked to another party's Releem customer, receive its
  public API key and overwrite its name.
- Ending a subscription (suspend, cancel, terminate) never looks a customer up by email. It only acts on a customer the
  service was already linked to.
- Customers that were linked by email are flagged `adopted` in `mod_releem_service_meta`; the module never sends a
  `name` update for them. Customers linked before this release are not flagged.
- **Partner API token is only sent to Releem.** The server hostname (and the legacy product endpoint) must be
  `releem.com` or a subdomain such as `api2.releem.com` or `api2.dev.releem.com`. IP addresses, `localhost` and foreign
  hosts are refused. A trailing dot in the hostname is accepted.
- A server password that cannot be decrypted (changed encryption hash, corrupted value) is treated as not configured
  instead of sending the ciphertext to Releem as the token.
- **Setup Product requires the `Edit Products/Services` admin permission** and is read-only without it. It is denied
  when WHMCS's permission API is unavailable.
- The Setup Product form now carries WHMCS's own admin CSRF `token`, in addition to the addon session token.
- The Module Log no longer contains customer email addresses or names (keys `email`, `name`, `subscription_email`
  and similar are redacted, and email addresses inside free text become `[EMAIL]`). The duplicate-customer error no
  longer includes the email.
- Generated install commands use `curl -fsSL --proto '=https' --tlsv1.2`, so an HTTP error page is never executed as
  root and the download cannot fall back to plain HTTP. Removed the unused `releem_server_build_whm_install_command`.

### Fixed

- **Subscription ID is always the WHMCS service ID.** Numeric gateway `subscriptionid` values are no longer used: they
  live in another number space and could equal an unrelated service's ID. Subscription IDs already sent to Releem keep
  their value, and a sync is refused if another service already sent the same ID.
- **Support-ticket link keeps the message.** `submitticket.php?step=2` needs a department, so the link now includes the
  first non-hidden department ID (falling back to the department chooser when none can be found). The message is
  limited to 1500 characters to stay within URL length limits.
- **Customer lookup no longer risks duplicate customers.** A full page (10 or more) of results without an exact email
  match is refused instead of being read as "customer does not exist", in case the list endpoint is paginated.
- The per-client sync lock name is now scoped by database, so several WHMCS installs sharing one MySQL server no longer
  block each other.
- The daily reconcile skips active services that lost to a lower-ID active service of the same client, instead of
  retrying them every day and using up its run limit.
- `Setup Product` now also creates zero-price `tblpricing` rows (every currency) for the `Servers` option values 1-10.
  Existing priced rows are never changed.
- The Services page shows the newest 2000 services and tells you when the list is truncated (use the Product filter).
- Deactivating the `Releem Setup` addon now warns that the renewal (`InvoicePaid`) and daily reconcile hooks stop
  until it is activated again.

### Added

- Addon setting **Link existing Releem customers by email** (default off).
- `mod_releem_service_meta.adopted` column, added automatically on first use.
- `tests/test_security_hardening.php` covering the host allowlist, log redaction, strict decryption and the customer
  lookup guard. `tests/test_subscription_id_fallback.php` now asserts the service-ID-only behaviour.

### Upgrade notes

1. Copy the whole `modules/` tree over the existing install (the release includes `hooks.php` and `assets/`).
2. Deactivate and reactivate `Releem Setup` once so the new setting appears.
3. If clients with an existing Releem customer start failing with "email is not verified in WHMCS", verify the email or
   enable the new setting.
4. The Releem server record hostname must be a `releem.com` host.

### Known limitation

- `subscription.amount` is sent in the client's WHMCS currency without a currency code, because the Releem API
  contract has no currency field. Multi-currency installs should keep this in mind.
