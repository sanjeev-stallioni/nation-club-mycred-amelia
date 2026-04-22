/* Nation Club — Vendor Withdrawal Form
 * Client-side guards: max amount enforcement, confirm dialog.
 */
(function () {
    'use strict';

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    ready(function () {
        var marker = document.querySelector('form[action*="admin-post.php"] input[name="action"][value="nc_vendor_submit_withdrawal"]');
        if (!marker) return;
        var form = marker.closest('form');

        var amountInput = form.querySelector('input[name="amount"]');
        var submitBtn   = form.querySelector('.nc-btn--primary');
        var maxAttr     = amountInput ? parseFloat(amountInput.getAttribute('max')) : 0;

        if (amountInput && maxAttr > 0) {
            amountInput.addEventListener('input', function () {
                var v = parseFloat(this.value);
                if (!isNaN(v) && v > maxAttr) {
                    this.value = maxAttr;
                }
            });
        }

        form.addEventListener('submit', function (e) {
            var v = parseFloat(amountInput.value);
            if (!v || v <= 0) {
                e.preventDefault();
                alert('Please enter a valid withdrawal amount.');
                return;
            }
            if (maxAttr > 0 && v > maxAttr) {
                e.preventDefault();
                alert('Maximum withdrawable amount is ' + maxAttr.toFixed(2) + '.');
                return;
            }
            var ok = confirm('Submit withdrawal request for ' + v.toFixed(2) + ' points?\n\nAdmin will review and process the Wise payout.');
            if (!ok) {
                e.preventDefault();
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting…';
            }
        });
    });
})();
