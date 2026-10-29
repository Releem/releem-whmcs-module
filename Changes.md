# Changes

All notable changes to the Releem WHMCS module. The `Releem Setup` addon version (shown in
`Addons -> Releem Setup`) is the module release number. Releases before 1.3.1 were not tracked here.

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
  `name` update for them.
- The public API key of an adopted customer is stored and shown only after the client email is verified in WHMCS.
  Turning on **Link existing Releem customers by email** can still attach the subscription, but it does not reveal
  the key while the email is unverified.
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
  root and the download cannot fall back to plain HTTP. The Windows command forces TLS 1.2 and an `https://` installer
  URL before `Invoke-WebRequest`. Removed the unused `releem_server_build_whm_install_command`.

### Fixed

- Ending an unlinked service no longer reuses a customer linked to a sibling service. It returns success without
  calling the Releem API or creating a new customer link.
- Email adoption and adopted-customer API key visibility now use the WHMCS 8 account owner's verification status,
  and require the owner's verified email to match the client email used for adoption. The legacy client flag is
  used only on WHMCS versions without separate user accounts.
- Daily reconciliation uses the same registration-based `valid_to` calculation as provisioning for Free and One Time
  services, so an incorrect subscription window is corrected without repeated syncs once it matches.
- Legacy API endpoint validation now rejects malformed DNS hostnames and non-root URL paths, and rebuilds a canonical
  HTTPS origin from the validated hostname and port instead of retaining the original URL.
- Suspending or terminating the subscription-owning service now sends `deleted` even when a higher-ID Active
  duplicate exists. Only a lower-ID service with a confirmed active Releem link can suppress deactivation;
  unprovisioned duplicates no longer leave a subscription active.
- Provisioning now reads the partner token from the assigned server record with strict decryption. An undecryptable
  `serverpassword` parameter is rejected instead of being sent as the token. The administrator's Test Connection
  action still accepts an unsaved plain-text token.

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
