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
10. **Real-time Reconciliation Dashboard.** Live "System Health Check" — Total Vendor Pool, Total Customer Points, System Total, Expected Total (top-ups − withdrawals), Balanced/Mismatch banner with delta. Auto-refreshes every 30 seconds. Per-vendor balance breakdown (paginated) highlights vendors below minimum or at zero. Includes a "This Month" rolling check section that compares this month's activity against the prior month's snapshot to help pinpoint when a discrepancy started.
11. **Month-end locked snapshots.** Auto-captured on the 1st of each month into an immutable history table (paginated), providing the official frozen accounting record separate from the live view. Past-month captures back-calculate from current state minus post-cutoff log entries so historical snapshots reflect month-end state, not current state.
12. **Per-batch expiry with origin-vendor refund.** Each customer earn becomes its own batch, tracked with its own expiry date and source vendor. New earns from one vendor never extend the expiry of points from another vendor. Redemptions consume FIFO (oldest batches first). When a batch expires: customer is debited (`points_expiry`) AND the origin vendor is refunded the equivalent points (`expired_refund`) — net system effect zero, vendor's funded value released back to their pool. The FIFO consumer also excludes expired batches the moment their `expiry_ts` passes, even before the daily cron runs (no "free 30-minute extension" loophole). Expiry windows are configurable from **Nation Club → Expiry Rules** (date-only inputs; auto-rolls each January). Master switch can disable expiry entirely.
13. **Outstanding Points card on vendor portal.** `[nc_my_points]` shows two side-by-side cards for vendors: **Your Current Points** (pool balance) and **Outstanding Points** (sum of `remaining_amount` across active batches where the vendor is the liability). Always visible for Amelia providers — even at 0 — so vendors get explicit "no liability hanging" confirmation. Hidden for non-provider customers.
14. **Cancellation / Rejection reasons (Amelia enhancement).** When a vendor changes a customer booking's status to Canceled or Rejected in the Amelia employee panel, a modal appears requiring them to enter a reason before the save proceeds. The customer is then emailed (template editable in Email Templates admin) with the reason embedded. To avoid duplicate emails, **disable Amelia's default Customer Cancelled / Customer Rejected notifications** in Amelia → Notifications. The canceled booking row in `wp_amelia_customer_bookings` is auto-deleted after the reason is saved — this prevents Amelia's "Maximum capacity reached" 409 errors when the customer rebooks the same time slot (the original cancellation history is preserved in our log table). All reasons are logged at **Nation Club → Cancellation Log** for admin review; re-cancellation of the same booking refreshes the row's timestamp and re-fires the email.
15. **Vendor exit flow (Proposal 5).** Two-month managed offboarding. Admin starts an exit notice → vendor continues operating normally during the notice → after 2 months admin clicks "Hide Listing" (gated by SGD 1,000 minimum balance check on the last day, per client requirement) which removes the vendor from the booking page while their account stays active for statement access → outstanding points clear via redemption or expiry → admin clicks "Final Settlement" with a Wise reference and the remaining pool balance is refunded via a `vendor_exit_settlement` ledger entry. Withdrawals are blocked once listing is hidden (both at form-submit time via filter AND at form-render time, so the vendor sees a clear "Withdrawals locked" notice instead of the form) — only Final Settlement can move money out post-hide. **Rejoin** button on settled rows restores Amelia visibility in one click; vendor still tops up to SGD 1,000 via the normal portal flow before resuming bookings. All flows on **Nation Club → Vendor Exits**.
16. **Employee-panel visibility.** In Amelia's employee panel, opening a customer row shows their myCRED balance and last service *with the current vendor* (not global).
17. **Log columns & export.** Adds Vendor Name, Username, Service, Transaction ID, and Origin Vendor columns to the myCRED log. One-click CSV download of the full ledger. Batch-level entries (`points_expiry`, `expired_refund`, `vendor_topup`, `vendor_withdrawal`, `vendor_exit_settlement`) display "—" for service/vendor columns instead of "Unknown Service" / "N/A" since they're not tied to a service. `points_expiry` and `expired_refund` rows resolve the vendor name from `liability_vendor_id` so the customer can see which vendor's points expired.

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
| `points_expiry` | − customer | Customer batch expired — paired with `expired_refund` on the origin vendor (net system effect zero) |
| `expired_refund` | + vendor | Origin vendor refunded when a customer's points batch expires unredeemed |
| `vendor_exit_settlement` | − vendor | Final pool refund on managed vendor exit (Wise reference recorded in the JSON `data` payload) |

Each entry stores a JSON `data` payload with `service_id`, `vendor_id`, `origin_vendor_id`, `liability_vendor_id`, `booking_id`, `transaction_id`, `customer_id` for audit and reporting. `expired_refund` entries additionally carry `batch_id`, `earned_ts`, and `expiry_ts`.

**Transaction ID formats:**
- `NC0001` — booking (Amelia booking ID, padded)
- `TU-00001` — vendor top-up
- `WD-00001` — vendor withdrawal
- `BATCH-N` — synthetic ID on `expired_refund` rows so vendor history can group them
- `EXIT-00001` — vendor exit final settlement

---

## Shortcodes

| Shortcode | Purpose |
|-----------|---------|
| `[mycred_expiring_points]` | Banner showing current balance and earliest batch expiry date for logged-in customer |
| `[nc_my_points]` | Customer-facing points page — balance card + per-batch breakdown (earned date, source vendor, earned/remaining amount, expiry date) with "expires soon" indicator for batches within 30 days |
| `[nc_vendor_topup_form]` | Vendor-facing top-up submission form (amount, transfer date, Wise reference, proof upload) |
| `[nc_vendor_withdrawal]` | Vendor-facing withdrawal UI (surplus view, request form, history, calendar window status) |
| `[nc_vendor_statements]` | Vendor-facing monthly statement list with PDF download |
| `[nc_vendor_history]` | Vendor-facing transaction history with breakdown popups (includes `expired_refund` rows with refund-context block) |
| `[wp_now]` | Prints WP-timezone "now" (debug helper) |

---

## Admin pages

Top-level menu: **Nation Club** (`dashicons-bank`).

Menu order (top to bottom):

| Submenu | Slug | Purpose |
|---------|------|---------|
| Dashboard | `nc-reconciliation` | Live System Health Check + "This Month" rolling check + per-vendor breakdown + month-end snapshot history (paginated) |
| Top-up Requests | `nation-club` | Review pending vendor top-up submissions; view payment proof; bulk approve/reject/delete |
| Withdrawal Requests | `nc-withdrawals` | Review withdrawal requests; approve → mark paid with Wise reference; bulk actions |
| Monthly Statements | `nc-statements` | Generate/regenerate statements; view detail; one-click Finalize & Send Email; manage Shared Costs; bulk actions; cron test buttons (incl. force-run per-batch expiry) |
| Email Templates | `nc-email-templates` | Tabbed WYSIWYG editor for all 8 email templates (statement, top-up flow, withdrawal flow, top-up reminder, low balance) |
| Expiry Rules | `nc-expiry-rules` | Configure customer points expiry windows (date-only From/To/Expire fields, auto-rolls each January). Master switch to disable expiry entirely. |
| Settings | `nc-settings` | Withdrawal window, admin notification recipients, Global CC, low-balance threshold |
| Log | `nc-log` | View / download / clear `wp-content/mycred-debug.log` (efficient reverse-seek tail of the last 10,000 lines) |
| Cancellation Log | `nc-cancellation-log` | All canceled / rejected bookings with the vendor-supplied reason and customer-email status. Paginated. Each entry preserves customer + service + appointment + reason even after the underlying Amelia booking row is auto-deleted. |
| Vendor Exits | `nc-vendor-exits` | Two-month managed offboarding: start exit notice, hide listing once minimum balance is met, finalize settlement with Wise reference once outstanding points clear. Includes per-row **Rejoin** action on settled rows to restore Amelia visibility in one click. |
| Test Reset | `nc-test-reset` | **TESTING ONLY.** Truncate plugin tables (incl. customer point batches and reconciliation snapshots) + reset user balances. Remove or gate before production. |

Additional admin page:
- **myCRED → Export Log** — one-click CSV of the full myCRED log with resolved vendor/customer/service/transaction columns.

---

## Key rules enforced

- **Reward = % of FULL invoice** (not post-redemption net)
- **Vendor pool minimum: SGD 1,000** (1 SGD = 1 point)
- **Vendor pays for loyalty cost ONCE** — at customer's earn time. Cross-vendor redemptions do not double-debit the origin vendor.
- **Per-batch expiry FIFO** — each earn is a separate batch, expiry tied to source vendor; redemptions consume oldest batches first; expired batches refund the origin vendor (not a permanent loss)
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
│   ├── nc_log.php                  # Debug logging helpers (writes to wp-content/mycred-debug.log)
│   ├── expiry-rules.php            # Expiry Rules admin page + canonical rule storage + auto-roll + master switch
│   ├── customer-point-batches.php  # Per-batch table + FIFO consume + daily expiry cron + vendor refund
│   ├── mycred-hooks.php            # Reward / redeem / batch create / FIFO consume / log columns / CSV export
│   ├── vendor-transactions.php     # [nc_vendor_history] shortcode (incl. expired_refund context popup)
│   ├── vendor-pool.php             # Top-up + withdrawal flows + bulk + emails + low-balance check + menu order
│   ├── vendor-statements.php       # Statements + Email Templates + Settings + cron + reminder
│   ├── reconciliation.php          # Live dashboard + "This Month" check + month-end locked snapshots
│   ├── customer-points-shortcode.php # [nc_my_points] customer/vendor page (balance + outstanding card + per-batch breakdown)
│   ├── log-viewer.php              # Nation Club → Log admin page (mycred-debug.log viewer/download/clear)
│   ├── cancellation-reason.php     # Required-reason modal + customer email + Cancellation Log admin page
│   ├── vendor-exit.php             # Vendor Exit Flow (Proposal 5) — notice → hide listing → final settlement
│   └── test-reset.php              # FOR TESTING ONLY — truncate tables (incl. batches + snapshots) + reset balances
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
| `wp_nc_customer_point_batches` | Per-customer earn batches (active / fully_redeemed / expired) — drives FIFO redemption + per-vendor expiry |
| `wp_nc_appointment_reasons` | Cancellation / rejection reasons captured from the Amelia employee panel modal (one row per booking + status) |
| `wp_nc_vendor_exits` | Vendor exit flow lifecycle (notice_active / listing_hidden / settled) with notice dates, Amelia status snapshot, final settlement amount + Wise reference, plus optional `rejoined_at` / `rejoined_by` stamp if the vendor rejoined later (row stays as `settled` so original exit history is preserved) |
| `wp_myCRED_log` | (myCRED's own table) The single source of truth for all balance changes |

All tables are auto-created via `dbDelta` on `plugins_loaded` with versioned options (`nc_vendor_pool_db_version`, `nc_statements_db_version`, `nc_reconciliation_db_version`, `nc_batches_db_version`, `nc_cancellation_db_version`, `nc_vendor_exit_db_version`).

---

## Debug logs

| File | Source |
|------|--------|
| `wp-content/mycred-debug.log` | `nc_debug()` — booking flow, batch create/consume, vendor refunds. Viewable from **Nation Club → Log** (with download / clear buttons). |
| `wp-content/uploads/nc-expiry-debug.log` | `nc_expiry_debug()` |
| `wp-content/uploads/nc-statement-cron.log` | Daily cron — statement generation, top-up reminders, snapshot captures, per-batch expiry |

For troubleshooting only. Rotate or disable in production.

---

## Cron schedule

A single daily cron event `nc_statement_daily_cron` (registered on `init`, scheduled for 00:30 site time) drives four handlers:

1. **Statement generation** — runs on day 1, generates Draft statements for the previous month for every vendor.
2. **Top-up reminder** — runs on day 6 and day 7, emails vendors below SGD 1,000.
3. **Reconciliation snapshot** — runs on day 1, captures a frozen snapshot of the previous month into `wp_nc_reconciliation_snapshots`.
4. **Per-batch expiry** — runs daily, finds active batches whose `expiry_ts <= now`, debits the customer (`points_expiry`) and refunds the origin vendor (`expired_refund`), marks each batch `expired`.

Manual test buttons for #1, #2, and #4 are on the Monthly Statements page; #3 has a manual capture form on the Reconciliation page.

> **Note:** WP pseudo-cron only fires when the site receives traffic. For reliable monthly execution, configure a real system cron hitting `wp-cron.php`. WP Engine has built-in real cron support.

---

## Roadmap

**Done:**
- Per-batch expiry with origin-vendor refund (replaces the older "expiry is a permanent vendor loss" model)
- Outstanding Points card on vendor portal
- Cancellation / rejection reason capture + customer email + admin log + auto-cleanup of canceled Amelia bookings
- Vendor exit flow (Proposal 5) — three-step status workflow with Rejoin support

**Pending (deferred / not blocking):**
- Admin-initiated top-up — onboard new vendors with the SGD 1,000 seed without requiring vendor login first
- Cleanup tool for legacy stale canceled bookings predating the auto-cleanup feature

---

## License

Proprietary — Stallioni Net Solutions. Internal use for Nation Club only.
