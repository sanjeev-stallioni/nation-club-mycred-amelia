# Nation Club myCRED Amelia

Custom WordPress plugin that integrates [myCRED](https://mycred.me/) with [Amelia Pro](https://wpamelia.com/) to run a vendor-funded loyalty-points program for Nation Club.

- **Author:** Stallioni Net Solutions — <https://www.stallioni.com/>
- **Version:** 1.0.0
- **Requires:** WordPress, myCRED, Amelia Pro, PHP ≥ 7.4

---

## What it does

1. **Vendor-funded rewards.** When an Amelia appointment is approved, the customer earns a percentage of the **full invoice amount** as myCRED points. The serving vendor's pool is debited by the same amount as a liability.
2. **Redemption flow.** On the customer's next booking, held points are applied against the new invoice. The original issuing vendor's liability is cleared; the new serving vendor is credited.
3. **Vendor pool (admin-verified).** Vendors top up via Wise offline, submit proof in the portal, and admin approves → system creates a proper ledger entry and credits the pool. No direct balance editing.
4. **Vendor withdrawals.** Vendors can request to withdraw surplus above SGD 1,000. Admin reviews → approves → marks paid after Wise payout. Withdrawals are locked until the previous month's statement is finalized.
5. **Monthly statements.** Per-vendor, per-month snapshot computed strictly from the points log: opening balance, accepted, earn/redeem liability, top-ups, withdrawals, expired, closing, required reload, surplus. Full status workflow: Draft → Finalized → Sent → Viewed → Completed.
6. **PDF + email + vendor portal.** Statements render to PDF via dompdf, emailed to vendors (WYSIWYG-editable template with tokens), and downloadable by vendors from a portal shortcode. CSV export for admin finance.
7. **Role-based expiry.** Customer points expire on a rolling half-year schedule (Jan–Jun → 31 Dec; Jul–Dec → 30 Jun of next year). Expired points are informational only on the statement — not credited back to the vendor.
8. **Employee-panel visibility.** In Amelia's employee panel, opening a customer row shows their myCRED balance and last service *with the current vendor*.
9. **Log columns & export.** Adds Vendor Name, Username, Service, Transaction ID, and Origin Vendor columns to the myCRED log. One-click CSV download of the full ledger.

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
5. Configure **WP Mail SMTP** (or equivalent) so statement emails deliver reliably.

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
| `earn_liability` | − vendor | Serving vendor issuing new points |
| `redeem_accept` | + vendor | Serving vendor accepting redemption |
| `redeem_liability` | − vendor | Origin vendor settling old points |
| `vendor_topup` | + vendor | Admin-approved Wise top-up |
| `vendor_withdrawal` | − vendor | Admin-processed Wise payout |
| `points_expiry` | − customer | Customer balance expired |

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
| `[nc_vendor_withdrawal]` | Vendor-facing withdrawal UI (surplus view, request form, history) |
| `[nc_vendor_statements]` | Vendor-facing monthly statement list with PDF download. Auto-marks "viewed" on first open |
| `[wp_now]` | Prints WP-timezone "now" (debug helper) |

---

## Admin pages

Top-level menu: **Nation Club** (`dashicons-bank`).

| Submenu | Purpose |
|---------|---------|
| Top-up Requests | Review pending vendor top-up submissions; view payment proof; approve & credit or reject |
| Withdrawal Requests | Review withdrawal requests; approve → mark paid with Wise reference |
| Monthly Statements | Generate/regenerate statements; view detail; export PDF/CSV; send email; manage status flow |
| Email Template | WYSIWYG editor for the statement email subject and body with token replacement |

Additional admin page:
- **myCRED → Export Log** — one-click CSV of the full myCRED log with resolved vendor/customer/service/transaction columns.

---

## Key rules enforced

- **Reward = % of FULL invoice** (not post-redemption net)
- **Vendor pool minimum: SGD 1,000** (1 SGD = 1 point)
- **Withdrawals locked** until previous month's statement is Finalized (NON-NEGOTIABLE spec rule)
- **Settlement grouped by `vendor_id`** — never by vendor name
- **Admin-only** payment proofs (streamed via guarded handler, filenames hashed)
- **No direct balance editing** — everything flows through the points log for full audit trail
- **Statement idempotency** — once Finalized, numbers are frozen (detail rows snapshotted)
- **Booking idempotency** — `amelia_after_appointment_updated` cannot double-credit or double-debit

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

The statement finalization lock is implemented via this same filter.

---

## File layout

```
nation-club-mycred-amelia/
├── nation-club-mycred-amelia.php   # Plugin bootstrap + composer autoloader
├── composer.json / composer.lock   # dompdf dependency
├── README.md / CLAUDE.md
├── assets/
│   ├── common.css                  # Shared UI primitives (.nc-box, .nc-btn, etc.)
│   ├── custom-tab.js               # Amelia employee-panel injection
│   ├── vendor-topup.css / .js      # Top-up form UI
│   ├── vendor-withdrawal.css / .js # Withdrawal form UI
│   └── vendor-transactions.css / .js
├── includes/
│   ├── nc_log.php                  # Debug logging helpers
│   ├── mycred-hooks.php            # Reward / redeem / expiry / log columns / CSV export
│   ├── vendor-transactions.php     # [nc_vendor_history] shortcode
│   ├── vendor-pool.php             # Top-up + withdrawal flows (shortcodes + admin pages)
│   └── vendor-statements.php       # Monthly statements (compute + PDF + email + portal)
└── vendor/                         # Composer-managed (not in git)
```

---

## Debug logs

| File | Source |
|------|--------|
| `wp-content/mycred-debug.log` | `mycred_process_appointment` |
| `wp-content/uploads/nc-debug.log` | `nc_debug()` |
| `wp-content/uploads/nc-expiry-debug.log` | `nc_expiry_debug()` |

For troubleshooting only. Rotate or disable in production.

---

## Roadmap

**Pending:**
- Admin-initiated top-up (to seed new vendors with initial SGD 1,000 via proper ledger entry)
- Email notifications on top-up/withdrawal status changes (admin + vendor)
- Real-time reconciliation dashboard (Total Vendor Pool + Total Customer Points vs Expected, Balanced/Mismatch indicator)
- Month-end locked reconciliation snapshot for official settlement

---

## License

Proprietary — Stallioni Net Solutions. Internal use for Nation Club only.
