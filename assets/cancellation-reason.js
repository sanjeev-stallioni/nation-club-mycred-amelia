/**
 * Cancellation / Rejection Reason — modal injection for Amelia employee panel.
 *
 * Watches for the booking status dropdown reaching "Canceled" or "Rejected".
 * When that happens we:
 *   1. Block the change visually until the vendor enters a reason
 *   2. POST the reason to our AJAX endpoint
 *   3. Allow Amelia's normal save to proceed (which will fire the
 *      `amelia_after_appointment_status_updated` hook our PHP listens on)
 *
 * Amelia's panel is a Vue SPA — DOM elements appear/disappear dynamically,
 * so we use a MutationObserver and event delegation rather than direct binds.
 */
(function ($) {
    'use strict';

    if (typeof NC_CANCEL === 'undefined') {
        return;
    }

    var TRACKED_STATUSES = ['Canceled', 'Rejected'];

    /* -------------------------------------------------------------------- */
    /* Modal HTML — injected once on first need                             */
    /* -------------------------------------------------------------------- */

    function ensureModal() {
        if (document.getElementById('nc-cancel-reason-modal')) {
            return;
        }
        var html = ''
            + '<div id="nc-cancel-reason-modal" class="nc-cr-overlay" style="display:none">'
            + '  <div class="nc-cr-modal" role="dialog" aria-modal="true">'
            + '    <div class="nc-cr-header">'
            + '      <span class="nc-cr-title">Reason required</span>'
            + '      <button type="button" class="nc-cr-close" aria-label="Close">&times;</button>'
            + '    </div>'
            + '    <div class="nc-cr-body">'
            + '      <p class="nc-cr-prompt">Please provide a reason for <strong class="nc-cr-status-label"></strong> this booking. The customer will be notified by email.</p>'
            + '      <textarea id="nc-cr-reason" rows="5" placeholder="e.g. Equipment unavailable on this date, customer no-show, etc."></textarea>'
            + '      <div class="nc-cr-error" style="display:none"></div>'
            + '    </div>'
            + '    <div class="nc-cr-footer">'
            + '      <button type="button" class="nc-cr-btn nc-cr-btn--cancel">Cancel</button>'
            + '      <button type="button" class="nc-cr-btn nc-cr-btn--submit">Save reason &amp; continue</button>'
            + '    </div>'
            + '  </div>'
            + '</div>';
        document.body.insertAdjacentHTML('beforeend', html);
    }

    /* -------------------------------------------------------------------- */
    /* Status detection                                                     */
    /* -------------------------------------------------------------------- */

    /**
     * Resolve booking context from a status badge that just changed.
     *
     * Amelia's panel doesn't expose booking IDs in the DOM, so we collect
     * enough surrounding context (date, time, service, customer name where
     * visible) for the server to look up the booking.
     *
     * Tries to read Vue's internal component instance first (works on most
     * Amelia versions); falls back to scraping visible text.
     */
    function resolveBookingContext($statusEl) {
        // Try Vue instance — both Vue 2 (__vue__) and Vue 3 (__vueParentComponent)
        var vueData = null;
        var node    = $statusEl[0];
        while (node && node !== document.body) {
            if (node.__vue__ && node.__vue__.$data) {
                vueData = node.__vue__.$data;
                break;
            }
            if (node.__vueParentComponent && node.__vueParentComponent.proxy) {
                vueData = node.__vueParentComponent.proxy;
                break;
            }
            node = node.parentNode;
        }

        var customerBookingId = 0;
        var appointmentId     = 0;
        if (vueData) {
            // Common property names across Amelia versions
            customerBookingId = parseInt(vueData.customerBookingId || vueData.bookingId || (vueData.booking && vueData.booking.id) || '0', 10);
            appointmentId     = parseInt(vueData.appointmentId || (vueData.appointment && vueData.appointment.id) || '0', 10);
        }

        // Surrounding context for server-side fallback resolution. Two layouts:
        //   (a) Appointment list — .am-capa wraps .am-cc with .am-capa__date,
        //       .am-cc__time, .am-cc__name, .am-cc__customer.
        //   (b) Edit Appointment modal — .am-capai-cuf__card holds the customer
        //       block, while date/service/time live in the Details tab elsewhere
        //       in the modal (separate panes, but still in DOM).
        var dateStr = '', timeStr = '', service = '', customer = '';

        // Try layout (a) first
        var $listCard    = $statusEl.closest('.am-cc');
        var $listWrapper = $statusEl.closest('.am-capa');
        if ($listCard.length) {
            dateStr  = $listWrapper.find('.am-capa__date').first().text().trim();
            timeStr  = $listCard.find('.am-cc__time').first().text().trim();
            service  = $listCard.find('.am-cc__name').first().text().trim();
            customer = $listCard.find('.am-cc__customer').first().text().trim();
        }

        // Fall back to layout (b) — Edit Appointment modal
        if (!dateStr || !service) {
            var $modal = $statusEl.closest('.am-capai, .el-dialog, .el-overlay');
            if (!$modal.length) { $modal = $(document); }

            var $modalCard = $statusEl.closest('.am-capai-cuf__card');
            if ($modalCard.length && !customer) {
                customer = $modalCard.find('.am-capai-customer__name').first().text().trim();
            }
            if (!dateStr) {
                dateStr = $modal.find('.am-date-picker__input-date').first().text().trim();
            }
            if (!service) {
                // Service select is disabled in this view — its placeholder still holds the name
                service = $modal.find('.am-select--disabled .el-select__placeholder span').first().text().trim();
            }
            if (!timeStr) {
                // Time select placeholder (HH:MM 24-hour in modal vs 9:00 AM in list — server tolerates both)
                $modal.find('.el-select__placeholder span').each(function () {
                    var t = $(this).text().trim();
                    if (/^\d{1,2}:\d{2}/.test(t)) { timeStr = t; return false; }
                });
            }
        }

        return {
            appointmentId: appointmentId,
            customerBookingId: customerBookingId,
            // Server-side resolution hints
            dateStr: dateStr,
            timeStr: timeStr,
            service: service,
            customer: customer,
        };
    }

    /* -------------------------------------------------------------------- */
    /* Modal open / close / submit                                          */
    /* -------------------------------------------------------------------- */

    var pendingContext = null;

    function openModal(status, context) {
        ensureModal();
        pendingContext = $.extend({}, context, { status: status });

        var $modal = $('#nc-cancel-reason-modal');
        $modal.find('.nc-cr-status-label').text(status.toLowerCase() === 'rejected' ? 'rejecting' : 'canceling');
        $modal.find('#nc-cr-reason').val('');
        $modal.find('.nc-cr-error').hide().text('');
        // Reset the submit button (in case a prior submit left it disabled / in "Saving..." state)
        $modal.find('.nc-cr-btn--submit').prop('disabled', false).text('Save reason & continue');
        $modal.show();
        setTimeout(function () { $modal.find('#nc-cr-reason').trigger('focus'); }, 50);
    }

    function closeModal() {
        $('#nc-cancel-reason-modal').hide();
        pendingContext = null;
    }

    function submitReason() {
        if (!pendingContext) {
            closeModal();
            return;
        }
        var reason = ($('#nc-cr-reason').val() || '').trim();
        var $err   = $('.nc-cr-error');
        if (reason.length < 3) {
            $err.show().text('Please enter at least a few words explaining the reason.');
            return;
        }

        var $submitBtn = $('.nc-cr-btn--submit');
        $submitBtn.prop('disabled', true).text('Saving…');

        $.ajax({
            url: NC_CANCEL.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'nc_save_cancellation_reason',
                nonce: NC_CANCEL.nonce,
                appointment_id: pendingContext.appointmentId,
                customer_booking_id: pendingContext.customerBookingId,
                status: pendingContext.status,
                reason: reason,
                // Server uses these to resolve the booking when Vue didn't expose IDs
                ctx_date: pendingContext.dateStr || '',
                ctx_time: pendingContext.timeStr || '',
                ctx_service: pendingContext.service || '',
                ctx_customer: pendingContext.customer || ''
            }
        }).done(function (resp) {
            if (resp && resp.success) {
                // Stash the reason on the card so Amelia's save serialization
                // doesn't need to know about it — our server-side hook will
                // pull from our table via customer_booking_id.
                closeModal();
            } else {
                var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Could not save reason. Please try again.';
                $err.show().text(msg);
                $submitBtn.prop('disabled', false).text('Save reason & continue');
            }
        }).fail(function () {
            $err.show().text('Network error — please try again.');
            $submitBtn.prop('disabled', false).text('Save reason & continue');
        });
    }

    /* -------------------------------------------------------------------- */
    /* Event hooks                                                          */
    /* -------------------------------------------------------------------- */

    /**
     * Cancel-button handler — Amelia commits the status change immediately on
     * dropdown selection (before our modal opens), so we can't undo it from
     * here. Warn the vendor before closing so they understand the booking
     * status will remain changed and no email will fire.
     */
    function handleCancelClick() {
        var status = pendingContext && pendingContext.status ? pendingContext.status : 'changed';
        var msg = 'The booking is already marked as ' + status + ' in Amelia. ' +
                  'Closing this without a reason means no notification email will be sent to the customer. ' +
                  'To revert the status, change the dropdown back manually after closing.\n\n' +
                  'Continue closing?';
        if (window.confirm(msg)) {
            closeModal();
        }
    }

    $(document).on('click', '.nc-cr-close, .nc-cr-btn--cancel', handleCancelClick);
    $(document).on('click', '.nc-cr-btn--submit', submitReason);
    $(document).on('keydown', '#nc-cancel-reason-modal', function (e) {
        if (e.key === 'Escape') { closeModal(); }
        if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) { submitReason(); }
    });

    /**
     * DETECTION STRATEGY — MutationObserver-based.
     *
     * Amelia's Vue components call stopPropagation on the dropdown clicks, so
     * a document-level click handler never sees them. Instead we watch the
     * DOM for the status badge text changing to "Canceled" or "Rejected".
     * When we detect that change, we fire the modal.
     *
     * Set window.NC_CANCEL_DEBUG = true to log every status change observed.
     */

    // Map of element → last seen status text. Used to detect transitions
    // from any status → Canceled/Rejected (avoids re-firing on initial render).
    var lastSeenStatus = new WeakMap();

    function scanForStatusElements(root) {
        // Amelia 7.x uses Element UI selects everywhere. Status dropdowns
        // appear in two places: (1) the appointment list (.am-cc__status),
        // and (2) the Edit Appointment modal under Customers tab
        // (.am-capai-cuf__card-booking). Both render the visible value in
        // .el-select__placeholder span.
        //
        // We scan ALL such spans and rely on the TRACKED_STATUSES filter to
        // ignore non-status dropdowns (Service / Time / Customer text never
        // matches "Canceled" / "Rejected").
        if (!root.querySelectorAll) { return []; }
        var spans = root.querySelectorAll('.el-select__placeholder span');
        var out = [];
        for (var i = 0; i < spans.length; i++) {
            out.push(spans[i]);
        }
        return out;
    }

    function checkStatusChange(el) {
        var current = (el.textContent || '').trim();
        var prev    = lastSeenStatus.get(el);

        if (window.NC_CANCEL_DEBUG && current !== prev) {
            console.log('[NC-CANCEL] status text changed', { prev: prev, current: current, el: el });
        }

        lastSeenStatus.set(el, current);

        // Only fire modal on a TRANSITION into Canceled/Rejected (not on initial render)
        if (prev === undefined) { return; }
        if (current === prev) { return; }
        if (TRACKED_STATUSES.indexOf(current) === -1) { return; }

        var context = resolveBookingContext($(el));

        if (window.NC_CANCEL_DEBUG) {
            console.log('[NC-CANCEL] opening modal via observer', { status: current, context: context });
        }

        openModal(current, context);
    }

    // Initial seed: record current status of every visible badge so we don't
    // fire modal on first paint. Run after a short delay to let Amelia render.
    function seedInitialStatuses() {
        var els = scanForStatusElements(document);
        for (var i = 0; i < els.length; i++) {
            lastSeenStatus.set(els[i], (els[i].textContent || '').trim());
        }
        if (window.NC_CANCEL_DEBUG) {
            console.log('[NC-CANCEL] seeded ' + els.length + ' status elements');
        }
    }

    // Also re-scan whenever the DOM changes — Amelia rerenders cards, swaps
    // children, etc. The observer below fires us in.
    function scanAndCheck(root) {
        var els = scanForStatusElements(root || document);
        for (var i = 0; i < els.length; i++) {
            checkStatusChange(els[i]);
        }
    }

    if (window.MutationObserver) {
        var mo = new MutationObserver(function (mutations) {
            // Light-touch: just rescan whole document on any mutation.
            // (Amelia panels are small enough that this is fine perf-wise.)
            scanAndCheck(document);
        });

        function seedFromDom() {
            var found = scanForStatusElements(document);
            for (var i = 0; i < found.length; i++) {
                if (!lastSeenStatus.has(found[i])) {
                    lastSeenStatus.set(found[i], (found[i].textContent || '').trim());
                }
            }
            return found.length;
        }

        function startObserver() {
            seedFromDom();
            mo.observe(document.body, {
                childList: true,
                subtree: true,
                characterData: true,
            });
            console.log('[NC-CANCEL] ready — observer attached');

            // Amelia is a Vue SPA — mounts AFTER document ready. Poll a few
            // times so the user can see the count grow as Vue renders.
            // Log the count on every poll so it's obvious whether the panel
            // is being detected or not.
            var attempts = 0;
            var lastReported = -1;
            var pollId = setInterval(function () {
                attempts++;
                var total = seedFromDom();
                if (total !== lastReported) {
                    console.log('[NC-CANCEL] poll #' + attempts + ' — tracking ' + total + ' status element(s)');
                    lastReported = total;
                }
                if (attempts >= 15) {
                    clearInterval(pollId);
                    if (total === 0) {
                        console.warn('[NC-CANCEL] no status elements found after 15s — selector may need adjusting. Run document.querySelectorAll(".am-cc__status .el-select__placeholder span") in console to verify.');
                    } else {
                        console.log('[NC-CANCEL] poll done — final tracking ' + total + ' status element(s)');
                    }
                }
            }, 1000);
        }

        if (document.body) {
            startObserver();
        } else {
            $(document).ready(startObserver);
        }
    }

    // Diagnostic: log EVERY document click so we can verify clicks are flowing.
    // (Not used for modal triggering — that's the observer above. This is
    // purely so the user can see in console whether Amelia is swallowing
    // clicks in the area where the dropdown lives.)
    $(document).on('click', function (e) {
        if (window.NC_CANCEL_DEBUG) {
            console.log('[NC-CANCEL] document click', { tag: e.target.tagName, className: e.target.className, text: (e.target.textContent || '').trim().slice(0, 40) });
        }
    });

})(jQuery);
