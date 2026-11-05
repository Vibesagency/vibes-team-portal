# Vibes Portal Modules 0.2.0

This companion plugin integrates with the existing Vibes Team Portal installation. It is not a replacement portal or a separate application.

## Scope in this build

- Vibes Connect: authorized general, direct, group, project and task conversations; incremental message polling; replies, reactions, read markers, private file uploads, and URL attachments.
- Vibes Analytics: own/team task metrics derived from existing task rows plus immutable task events; configurable complexity points; historical point records; no surveillance.
- Vibes Finance: restricted dashboard, partial-payment ledger, expenses, salary history, salary payments schema, and historical FX rate schema. Existing quotations/invoices remain the source records.
- Vibes CRM: stages, manual leads, duplicate detection, notes/activities, review queue, lead-to-inquiry conversion, and Lead Hunter sources/scans.
- Lead Hunter limited mode: configured public RSS/Atom feeds and official JSON endpoints using WordPress HTTP APIs. It verifies evidence URLs, records reasons, rejects public-sector/expired/irrelevant/duplicate items, and separates verified demand from potential opportunities.

## Installation (staging only)

1. Back up the WordPress database and files.
2. Copy this directory to `wp-content/plugins/vibes-portal-modules/`.
3. Activate it only on staging first.
4. Open `/team-portal/` as an administrator and verify the module navigation.
5. Grant `vpm_manage_finance`, `vpm_manage_crm`, or `vpm_run_lead_hunter` only to explicitly authorized users.
6. Configure public sources under Vibes CRM → Lead Hunter. Do not add sources that require authentication, CAPTCHA bypass, paywall access, or scraping.

The plugin adds tables with the existing `vtp_` prefix. It never drops or resets core portal tables and does not seed live leads.

## Known limitations / next staging work

- Private Connect uploads are stored outside the public upload route and streamed through a membership-checked endpoint; a production staging review should still verify the host's Apache/IIS deny rules.
- Finance summaries keep source currencies explicit. Historical FX conversion is stored but not silently applied.
- Existing core invoice statuses are preserved; the module adds `partial` in the finance ledger and can be reconciled into the core invoice view during the next integration pass.
- Secure authenticated CSV exports are available for finance and analytics, plus a secure summary PDF. A fully branded finance PDF still needs the approved finance template.
- Lead Hunter does not provide internet-wide discovery without a compliant search API. Adding such an adapter is optional and must disclose quotas/costs.
- The live `vibes-video-library` source was not available in the local snapshot and was not modified.

