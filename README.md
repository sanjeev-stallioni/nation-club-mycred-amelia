# Nation Club myCRED Amelia

Custom WordPress plugin that integrates [myCRED](https://mycred.me/) with [Amelia Pro](https://wpamelia.com/) to run a vendor-funded loyalty-points program for Nation Club.

- **Author:** Stallioni Net Solutions — <https://www.stallioni.com/>
- **Version:** 1.0.0
- **Requires:** WordPress, myCRED, Amelia Pro, PHP ≥ 7.4

---

## What it does

1. **Vendor-funded rewards.** When an Amelia appointment is approved, the customer earns a percentage of the **full invoice amount** as myCRED points. The serving vendor's pool is debited by the same amount up-front (`earn_liability`). That single debit is the entire cost the vendor pays for that customer's reward.
2. **Redemption flow.** On the customer's next booking, held points are applied against the new invoice. The serving vendor (which may or may not be the original issuing vendor) receives a credit (`redeem_accept`). The origin vendor is **NOT** debited again — they already paid at earn time. Net vendor pool change per redemption pair: **0**.
3. **Vendor pool (admin-verified).** Vendors top up via Wise offline, submit proof in the portal, admin verifies the Wise payment and approves → system creates a proper `vendor_topup` ledger entry and credits the pool. No direct balance editing.
4. **Vendor withdrawals.** Vendors can request to withdraw surplus above SGD 1,000 only during the configurable monthly window (default 2nd–5th) AND only after last month's statement is Finalized & Sent. Admin reviews → approves → marks paid after Wise payout.
5. **Monthly statements.** Auto-generated on the 1st of each month for every vendor. Per-vendor, per-month snapshot computed strictly from the points log: opening balance, accepted, earn liability, top-ups, withdrawals, expired, shared costs, closing, required reload, surplus. Simplified status flow: **Draft → Finalized & Sent** (one-click "Finalize & Send Email" combines locking the numbers with sending the PDF). Admin can revert to Draft if a fix is needed.
6. **PDF + email + vendor portal.** Statements render to PDF via dompdf, emailed to vendors with the PDF attached, and downloadable by vendors from a portal shortcode.
7. **Email notification system.** Eight customizable templates (statement, top-up submitted/approved/rejected, withdrawal submitted/approved-rejected/paid, top-up reminder, low balance alert) with token replacement, plus a global CC field that copies every email to admin/accounts.
8. **Top-up reminder cron.** On day 6 and 7 of each month, the system emails any vendor whose balance is below SGD 1,000 (skipping vendors whose pending top-up already covers the shortfall).
9. **Event-based low-balance alert.** When a vendor's balance crosses below a configurable threshold (default SGD 300) — e.g. after a customer redemption settlement — they receive a one-time alert. The flag clears the moment they recover above the threshold.
10. **Real-time Reconciliation Dashboard.** Live "System Health Check" — Total Vendor Pool, Total Customer Points, System Total, Expected Total (top-ups − withdrawals − expired), Balanced/Mismatch banner with delta. Auto-refreshes every 30 seconds. Per-vendor balance breakdown highlights vendors below minimum or at zero.
11. **Month-end locked snapshots.** Auto-captured on the 1st of each month into an immutable history table, providing the official frozen accounting record separate from the live view.
12. **Role-based expiry.** Customer points expire on a rolling half-year schedule (Jan–Jun → 31 Dec; Jul–Dec → 30 Jun of next year). Expired points are informational only on the statement — not credited back to the vendor.
13. **Employee-panel visibility.** In Amelia's employee panel, opening a customer row shows their myCRED balance and last service *with the current vendor* (not global).
14. **Log columns & export.** Adds Vendor Name, Username, Service, Transaction ID, and Origin Vendor columns to the myCRED log. One-click CSV download of the full ledger.

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/nation-club-mycred-amelia/`.
2. Install composer dependencies (required for PDF generation):
   ```bash
   cd wp-content/plugins/nation-club-mycred-amelia
   composer install --no-dev --ignore-platform-reqs
   ```
   Alternatively, include the `vendor/` folder in your deployment zip.
3. Activate **Nation Club myCRED Amelia** in **Plugins → Installed Plugins**.
4. Ensure **myCRED** and **Amelia Pro** are active.
5. Configure **WP Mail SMTP** (or equivalent) so statement emails deliver reliably. The plugin uses the `wp_mail_content_type` filter so HTML emails render correctly through SMTP overrides.
6. Visit **Nation Club → Settings** to configure the withdrawal window, admin notification recipients, Global CC, and low-balance alert threshold.
7. (Optional) Set a real cron job hitting `wp-cron.php` for reliable monthly fire — WP pseudo-cron only runs when the site has traffic.

---

## How rewards are calculated

- **Always from the FULL invoice amount** — never the net after redemption.
- Invoice amount is read from an Amelia booking custom field whose label contains the word `invoice`.
- Reward % per service ID lives in the `$service_rewards` array in [includes/mycred-hooks.php](includes/mycred-hooks.php):

| Rate | Services (IDs) |
|------|----------------|
| 10%  | 8, 49 |
| 5%   | 6, 7, 9–15, 23, 24, 27, 30, 40–48, 51, 53, 54, 58–78 (default) |
| 2%   | 5, 55, 56, 57 |

Services not listed fall back to **5%**.

---

## myCRED reference types

| Ref | Direction | Meaning |
|---|---|---|
| `booking_reward` | + customer | Points earned from a booking |
| `booking_redeem` | − customer | Points spent at next booking |
| `earn_liability` | − vendor | Origin vendor pool drained at customer's earn time. **The sole vendor debit for a customer-points lifecycle.** |
| `redeem_accept` | + vendor | Serving vendor accepting the customer's redeemed points |
| `redeem_liability` | − vendor | **DEPRECATED.** Old origin-vendor settlement debit. No longer created — historical entries remain for audit. |
| `vendor_topup` | + vendor | Admin-approved Wise top-up |
| `vendor_withdrawal` | − vendor | Admin-processed Wise payout |
| `points_expiry` | − customer | Customer balance expired (NOT credited back to vendor) |

Each entry stores a JSON `data` payload with `service_id`, `vendor_id`, `origin_vendor_id`, `liability_vendor_id`, `booking_id`, `transaction_id`, `customer_id` for audit and reporting.

**Transaction ID formats:**
- `NC0001` — booking (Amelia booking ID, padded)
- `TU-00001` — vendor top-up
- `WD-00001` — vendor withdrawal

---

## Shortcodes

| Shortcode | Purpose |
|-----------|---------|
| `[mycred_expiring_points]` | Banner showing current balance and expiry date for logged-in customer |
| `[nc_vendor_topup_form]` | Vendor-facing top-up submission form (amount, transfer date, Wise reference, proof upload) |
| `[nc_vendor_withdrawal]` | Vendor-facing withdrawal UI (surplus view, request form, history, calendar window status) |
| `[nc_vendor_statements]` | Vendor-facing monthly statement list with PDF download |
| `[wp_now]` | Prints WP-timezone "now" (debug helper) |

---

## Admin pages

Top-level menu: **Nation Club** (`dashicons-bank`).

| Submenu | Slug | Purpose |
|---------|------|---------|
| Top-up Requests | `nation-club` | Review pending vendor top-up submissions; view payment proof; bulk approve/reject/delete |
| Withdrawal Requests | `nc-withdrawals` | Review withdrawal requests; approve → mark paid with Wise reference; bulk actions |
| Monthly Statements | `nc-statements` | Generate/regenerate statements; view detail; one-click Finalize & Send Email; manage Shared Costs; bulk actions; cron test buttons |
| Email Templates | `nc-email-templates` | Tabbed WYSIWYG editor for all 8 email templates (statement, top-up flow, withdrawal flow, top-up reminder, low balance) |
| Dashboard | `nc-reconciliation` | Live System Health Check + per-vendor breakdown + month-end snapshot history |
| Settings | `nc-settings` | Withdrawal window, admin notification recipients, Global CC, low-balance threshold |
| Test Reset | `nc-test-reset` | **TESTING ONLY.** Truncate plugin tables + reset user balances. Remove or gate before production. |

Additional admin page:
- **myCRED → Export Log** — one-click CSV of the full myCRED log with resolved vendor/customer/service/transaction columns.

---

## Key rules enforced

- **Reward = % of FULL invoice** (not post-redemption net)
- **Vendor pool minimum: SGD 1,000** (1 SGD = 1 point)
- **Vendor pays for loyalty cost ONCE** — at customer's earn time. Cross-vendor redemptions do not double-debit the origin vendor.
- **Withdrawals locked** until previous month's statement is Finalized & Sent (NON-NEGOTIABLE spec rule) AND today is within the configured calendar window
- **Top-ups always available** — no date restriction so vendors can recover dipping balances any time
- **Statements immutable once Finalized & Sent** — `detail_data` JSON-snapshotted; only Drafts can be regenerated via "Update & Regenerate" on the Shared Costs row
- **Settlement grouped by `vendor_id`** — never by vendor name
- **Admin-only payment proofs** (streamed via guarded handler, filenames hashed `nc-proof-<24-hex>.ext`)
- **No direct balance editing** — everything flows through the points log for full audit trail
- **Booking idempotency** — `amelia_after_appointment_updated` cannot double-credit or double-debit
- **Email content-type via filter** — uses `wp_mail_content_type` (not headers array) so WP Mail SMTP doesn't strip it

---

## Withdrawal extension point

Other modules can plug additional blockers into withdrawals via a filter:

```php
add_filter( 'nc_vendor_can_withdraw', function ( $result, $vendor_id, $amount ) {
    // $result = [ 'ok' => bool, 'reason' => string ]
    // Flip ok to false with a reason if withdrawal should be blocked.
    return $result;
}, 10, 3 );
```

The statement-finalization lock and the calendar-window check are both implemented via this filter (priorities 10 and 9 respectively).

---

## File layout

```
nation-club-mycred-amelia/
├── nation-club-mycred-amelia.php   # Plugin bootstrap + composer autoloader
├── composer.json / composer.lock   # dompdf dependency
├── README.md / CLAUDE.md
├── .gitignore                      # vendor/ excluded
├── assets/
│   ├── common.css                  # Shared UI primitives (.nc-box, .nc-btn, .nc-pts, etc.)
│   ├── custom-tab.js               # Amelia employee-panel injection
│   ├── vendor-topup.css / .js      # Top-up form UI
│   ├── vendor-withdrawal.css / .js # Withdrawal form UI
│   └── vendor-transactions.css / .js
├── includes/
│   ├── nc_log.php                  # Debug logging helpers (incl. nc_statement_cron_log)
│   ├── mycred-hooks.php            # Reward / redeem / expiry / log columns / CSV export
│   ├── vendor-transactions.php     # [nc_vendor_history] shortcode
│   ├── vendor-pool.php             # Top-up + withdrawal flows + bulk + emails + low-balance check
│   ├── vendor-statements.php       # Statements + Email Templates + Settings + cron + reminder
│   ├── reconciliation.php          # Live dashboard + month-end locked snapshots
│   └── test-reset.php              # FOR TESTING ONLY — truncate tables + reset balances
└── vendor/                         # Composer-managed (not in git)
```

---

## Database tables

| Table | Purpose |
|-------|---------|
| `wp_nc_topup_requests` | Vendor top-up submissions (pending/approved/rejected) |
| `wp_nc_withdrawal_requests` | Vendor withdrawal requests (pending/approved/paid/rejected) |
| `wp_nc_statements` | Monthly statement snapshots per vendor (Draft / Finalized & Sent) |
| `wp_nc_reconciliation_snapshots` | Immutable month-end captures of system health numbers |
| `wp_myCRED_log` | (myCRED's own table) The single source of truth for all balance changes |

All tables are auto-created via `dbDelta` on `plugins_loaded` with versioned options (`nc_vendor_pool_db_version`, `nc_statements_db_version`, `nc_reconciliation_db_version`).

---

## Debug logs

| File | Source |
|------|--------|
| `wp-content/mycred-debug.log` | `mycred_process_appointment` |
| `wp-content/uploads/nc-debug.log` | `nc_debug()` |
| `wp-content/uploads/nc-expiry-debug.log` | `nc_expiry_debug()` |
| `wp-content/uploads/nc-statement-cron.log` | Daily cron — statement generation, top-up reminders, snapshot captures |

For troubleshooting only. Rotate or disable in production.

---

## Cron schedule

A single daily cron event `nc_statement_daily_cron` (registered on `init`, scheduled for 00:30 site time) drives three handlers:

1. **Statement generation** — runs on day 1, generates Draft statements for the previous month for every vendor.
2. **Top-up reminder** — runs on day 6 and day 7, emails vendors below SGD 1,000.
3. **Reconciliation snapshot** — runs on day 1, captures a frozen snapshot of the previous month into `wp_nc_reconciliation_snapshots`.

Manual test buttons for #1 and #2 are on the Monthly Statements page; #3 has a manual capture form on the Reconciliation page.

> **Note:** WP pseudo-cron only fires when the site receives traffic. For reliable monthly execution, configure a real system cron hitting `wp-cron.php`. WP Engine has built-in real cron support.

---

## Roadmap

**Pending:**
- **Vendor exit flow (2-month notice)** — *blocked on client clarifications.* Vendor submits exit notice, status flips to Non-Participating, no new points issued for their services, final settlement after points expiry cycle, refund within 30 business days. Awaiting answers on submission UX, 6-month minimum enforcement, withdrawal during notice, final-settlement page location, Wise fees entry, cancel-notice window, and account closure handling.

After that: polish/edge cases only.

---

## License

Proprietary — Stallioni Net Solutions. Internal use for Nation Club only.
