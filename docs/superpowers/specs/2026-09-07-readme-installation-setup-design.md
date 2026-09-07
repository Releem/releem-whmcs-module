# README Installation and Setup Design

## Goal

Turn `README.md` into an operator-first installation and setup guide for the standalone Releem WHMCS server module. An administrator should be able to install the files, configure the product, run the setup addon, and verify provisioning without reading the implementation.

## Audience

WHMCS administrators who can upload module files, activate addon modules, create products, configure pricing, and inspect the WHMCS module log.

## Structure

The README will lead with this sequence:

1. Prerequisites.
2. Copy the server-module, client-area template, and setup-addon files into an existing WHMCS installation while preserving their paths.
3. Activate the `Releem Setup` addon.
4. Create a standalone product and select the `Releem` provisioning module.
5. Configure `partner_api_key`, `api_endpoint`, and the optional `server_count_option_id` override.
6. Run `Addons -> Releem Setup -> Setup Product` and explain the idempotent resources it creates or reuses.
7. Set base and server-count pricing and enable configurable-option upgrades.
8. Place a test order and verify API synchronization, client-area output, lifecycle actions, and module logs.
9. Diagnose common configuration errors and explain that deactivation does not remove product configuration.

Existing API endpoint, field-mapping, internal-metadata, and lifecycle details will remain after the runbook as reference material. Repeated installation and testing lists will be consolidated.

## Safety and Accuracy

- Do not include a real partner API key or instruct readers to expose it in logs.
- Clearly distinguish the secret partner API key from the customer-facing public API key.
- State that production uses `https://api2.releem.com`; describe the development endpoint as testing-only.
- Do not imply that the addon sets prices or enables upgrades automatically.
- Preserve the supported manual configurable-option alternative.
- Do not add unsupported WHMCS or PHP version requirements. State only prerequisites demonstrated by the code: an existing WHMCS installation, PHP cURL support, outbound HTTPS access, a Releem partner API key, and appropriate WHMCS admin/file access.

## Verification

- Check all documented paths, setting names, menu labels, generated option values, and lifecycle behavior against the PHP and template files.
- Run PHP syntax checks in a container against both PHP module files.
- Re-read the completed README and compare it with the source files before finalizing.
- Review the diff for accidental code changes or loss of useful troubleshooting/reference information.

## Acceptance Criteria

- A new administrator can follow one ordered path from file upload to a successful test order.
- Automated setup is presented as the recommended path; manual setup is an explicit fallback.
- Pricing and upgrade configuration remain administrator-owned steps.
- The guide explains where to confirm successful provisioning and how to diagnose common failures.
- No module behavior is changed.
