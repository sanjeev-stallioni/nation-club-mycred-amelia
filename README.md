# Nation Club myCRED Amelia

Custom WordPress plugin that integrates [myCRED](https://mycred.me/) with [Amelia Pro](https://wpamelia.com/) to run a vendor-funded loyalty-points program for Nation Club.

- **Author:** Stallioni Net Solutions — <https://www.stallioni.com/>
- **Version:** 1.0.0
- **Requires:** WordPress, myCRED, Amelia Pro

---

## What it does

1. **Vendor-funded rewards.** When an Amelia appointment is approved, the customer earns a percentage of the invoice amount as myCRED points, and the same number of points is deducted from the vendor (provider) who delivered the service.
2. **Auto-redemption.** On the customer's next booking, any existing point balance is automatically redeemed against the new invoice (skipped on the first-ever booking and when the originating vendor is the same as the current vendor).
3. **Role-based expiry.** Customer points expire on a rolling half-year schedule:
   - Earned **Jan–Jun** -> expires **31 Dec** of the same year
   - Earned **Jul–Dec** -> expires **30 Jun** of the following year
4. **Employee-panel visibility.** In the Amelia employee panel, opening a customer row shows their current myCRED balance and last booked service via an AJAX call.
5. **Log columns & export.** Adds `Vendor Name`, `Username`, `Category`, `Points Origin Vendor`, and `Transaction ID` columns to the myCRED log, plus a **myCRED -> Export Log** admin page that downloads the full log as CSV.
6. **Expiring-points notice.** `[mycred_expiring_points]` shortcode renders a styled banner showing the logged-in user's current balance and expiry date.

---

## Installation

1. Copy the plugin folder to `wp-content/plugins/nation-club-mycred-amelia/`.
2. Activate **Nation Club myCRED Amelia** in **Plugins -> Installed Plugins**.
3. Ensure **myCRED** and **Amelia Pro** are active.

---

## How rewards are calculated

The invoice amount is read from an Amelia booking custom field whose label contains the word `invoice`.

Reward % per `serviceId` is defined in the `$service_rewards` array in [includes/mycred-hooks.php](includes/mycred-hooks.php):

| Rate | Services (IDs) |
|------|----------------|
| 10%  | 8, 49 |
| 5%   | 6, 7, 9–15, 23, 24, 27, 30, 40–48, 51, 53, 54, 58–78 (default) |
| 2%   | 5, 55, 56, 57 |

Services not listed fall back to **5%**.

Update this map when services are added/renumbered in Amelia.

---

## Hooks & triggers

| WordPress / Amelia hook | Handler | Purpose |
|-------------------------|---------|---------|
| `amelia_after_appointment_status_updated` | `mycred_appointment_status_changed` | Fires reward flow when status becomes `approved` |
| `amelia_after_appointment_updated` | `mycred_appointment_updated` | Re-runs reward flow if invoice is edited post-approval |
| `wp_loaded` | `mycred_maybe_expire_user_points` | Expires points for the logged-in user if the expiry timestamp has passed |
| `wp_ajax_get_mycred_points` / `wp_ajax_nopriv_get_mycred_points` | `get_customer_mycred_points` | Employee-panel AJAX lookup |
| `admin_post_export_mycred_log` | `export_mycred_log_to_csv` | CSV export endpoint |

### myCRED reference types used

- `booking_reward` — customer earns points
- `booking_redeem` — customer redeems points
- `vendor_deduct` — vendor pays for reward
- `vendor_accept_redeem` — vendor absorbs redemption (skipped on same-vendor bookings)
- `points_expiry` — balance expired

Each entry stores a JSON payload containing `service_id`, `vendor_id`, `origin_vendor_id`, `booking_id`, `transaction_id`, and `customer_id` in the `data` column — used by the log columns and CSV export.

---

## Shortcodes

| Shortcode | Output |
|-----------|--------|
| `[mycred_expiring_points]` | Banner showing current balance and expiry date for the logged-in user |
| `[wp_now]` | Prints the WordPress-timezone "now" value (debugging helper) |

---

## Admin pages

- **myCRED -> Export Log** — one-click CSV download of `{prefix}myCRED_log` with resolved user, category, vendor, and transaction columns.
- **Expiry Rules** (defined in `includes/admin.php`, currently not loaded from the main plugin file — enable the `require_once` in [nation-club-mycred-amelia.php](nation-club-mycred-amelia.php) if you want the date-range expiry UI instead of the hard-coded rolling schedule).

---

## Debug logs

The plugin writes to three files during operation:

| File | Source |
|------|--------|
| `wp-content/mycred-debug.log` | `mycred_process_appointment` |
| `wp-content/uploads/nc-debug.log` | `nc_debug()` |
| `wp-content/uploads/nc-expiry-debug.log` | `nc_expiry_debug()` |

These are for troubleshooting only. Rotate or disable in production.

---

## File layout

```
nation-club-mycred-amelia/
├── nation-club-mycred-amelia.php   # Plugin bootstrap, constants, hook for JS
├── assets/
│   └── custom-tab.js               # Employee-panel injection (MutationObserver + AJAX)
└── includes/
    ├── mycred-hooks.php            # Reward / redeem / expiry / log columns / CSV export
    ├── admin.php                   # (Optional) date-range Expiry Rules admin page
    └── nc_log.php                  # Debug logging helpers + [wp_now] shortcode
```

---

## License

Proprietary — Stallioni Net Solutions. Internal use for Nation Club only.
