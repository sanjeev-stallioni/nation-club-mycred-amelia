/* Nation Club — Vendor Top-Up Form
 * Client-side validation for file size/type and submit guards.
 */
(function () {
    'use strict';

    var MAX_BYTES = 8 * 1024 * 1024;
    var ALLOWED_EXT = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];

    function ready(fn) {
        if (document.readyState !== 'loading') { fn(); }
        else { document.addEventListener('DOMContentLoaded', fn); }
    }

    function formatBytes(bytes) {
        if (bytes < 1024) return bytes + ' B';
        if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
        return (bytes / (1024 * 1024)).toFixed(2) + ' MB';
    }

    ready(function () {
        var form = document.querySelector('form[action*="admin-post.php"] input[name="action"][value="nc_vendor_submit_topup"]');
        if (!form) return;
        form = form.closest('form');

        var fileInput = form.querySelector('input[type="file"][name="payment_proof"]');
        var fileWrap  = form.querySelector('.nc-topup-file');
        var filePrim  = form.querySelector('.nc-topup-file__primary');
        var fileSec   = form.querySelector('.nc-topup-file__secondary');
        var submitBtn = form.querySelector('.nc-btn--primary');
        var amountInput = form.querySelector('input[name="amount"]');

        // Live Shared Cost / Pool Top-Up breakdown (only present for vendors
        // below the minimum with an outstanding shared cost).
        var sharedDue   = amountInput ? parseFloat(amountInput.getAttribute('data-shared-due')) : NaN;
        var poolVal     = document.getElementById('nc-topup-pool-val');
        var underpayMsg = document.getElementById('nc-topup-underpay');
        var consentBox  = document.getElementById('nc_topup_consent');

        function money(n) {
            return 'SGD ' + n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        }

        function recalcBreakdown() {
            if (!poolVal || isNaN(sharedDue)) return;
            var total = parseFloat(amountInput.value) || 0;
            var pool  = total - sharedDue;
            if (pool < 0) { pool = 0; }
            poolVal.textContent = money(pool);
            if (underpayMsg) {
                underpayMsg.style.display = (total > 0 && total < sharedDue) ? '' : 'none';
            }
        }

        if (amountInput && poolVal && !isNaN(sharedDue)) {
            amountInput.addEventListener('input', recalcBreakdown);
            recalcBreakdown();
        }

        if (fileInput && fileWrap) {
            fileInput.addEventListener('change', function () {
                var file = this.files && this.files[0];
                if (!file) {
                    fileWrap.classList.remove('is-selected');
                    if (filePrim) filePrim.textContent = 'Click to choose a file';
                    if (fileSec)  fileSec.textContent  = 'PDF, DOC, DOCX, JPG, PNG — max 8 MB';
                    return;
                }

                var ext = file.name.split('.').pop().toLowerCase();
                if (ALLOWED_EXT.indexOf(ext) === -1) {
                    alert('Unsupported file type. Allowed: ' + ALLOWED_EXT.join(', ').toUpperCase());
                    fileInput.value = '';
                    return;
                }
                if (file.size > MAX_BYTES) {
                    alert('File too large (' + formatBytes(file.size) + '). Max 8 MB.');
                    fileInput.value = '';
                    return;
                }

                fileWrap.classList.add('is-selected');
                if (filePrim) filePrim.textContent = file.name;
                if (fileSec)  fileSec.textContent  = formatBytes(file.size);
            });
        }

        form.addEventListener('submit', function (e) {
            var amount = parseFloat(form.querySelector('input[name="amount"]').value);
            if (!amount || amount <= 0) {
                e.preventDefault();
                alert('Please enter a valid amount greater than 0.');
                return;
            }
            if (!fileInput || !fileInput.files || !fileInput.files[0]) {
                e.preventDefault();
                alert('Payment proof is required.');
                return;
            }
            if (consentBox && !consentBox.checked) {
                e.preventDefault();
                alert('Please agree to the shared cost being deducted first.');
                return;
            }
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'Submitting…';
            }
        });
    });
})();
