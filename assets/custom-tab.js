( function( $ ) {
    console.log('NC Amelia Plugin: Script Initialized');

    // Use MutationObserver to detect modal opening (when .el-tabs.el-tabs--top is added)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                const modal = $('.el-tabs.el-tabs--top');
                if (modal.length) {
                    addCustomData(modal);
                }
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    // A group appointment renders ONE customer card per customer inside
    // #pane-customers. Each card must be looked up and filled INDIVIDUALLY —
    // reading a single email and stamping it onto every card made every
    // customer show the first customer's points (the original bug). We iterate
    // each card, read its own email, and inject its own balance back into it.
    function addCustomData(modal) {
        modal.find('#pane-customers .am-capai-cuf__card-info').each(function() {
            const card = $(this);

            // Per-card guard so re-fired mutations don't double-inject, while
            // still allowing late-rendered cards to be processed on a later tick.
            if (card.hasClass('nc-points-added')) {
                return;
            }
            card.addClass('nc-points-added');

            // Look for this card's email inside the card-info first; if Amelia
            // renders it just outside, fall back to the surrounding card block.
            let emailAnchor = card.find('a[href^="mailto:"]').first();
            if (!emailAnchor.length) {
                emailAnchor = card.closest('.am-capai-cuf__card, .am-capai-customer, li, .am-appointment-customer')
                    .find('a[href^="mailto:"]').first();
            }
            const customerEmail = emailAnchor.length
                ? emailAnchor.attr('href').replace('mailto:', '').trim()
                : '';

            if (!customerEmail) {
                card.append('<div class="am-capai-customer__data">No customer email found for myCred points.</div>');
                return;
            }

            fetchCardPoints(card, customerEmail);
        });
    }

    function fetchCardPoints(card, customerEmail) {
        $.ajax({
            url: ameliaAjax.ajaxurl,
            type: 'POST',
            data: {
                action: 'get_mycred_points',
                email: customerEmail,
                nonce: ameliaAjax.nonce
            },
            success: function(response) {
                if (response.success) {
                    const d = response.data;
                    const rowStyle = 'font-weight: 600; font-size: 14px';
                    const iconSpan = '<span style="font-weight: 400;" class="am-icon-circle-info"></span> ';

                    let html = '';
                    html += '<div class="am-capai-customer__data" style="' + rowStyle + '">' + iconSpan + 'Points holding: <strong>' + d.points + '</strong></div>';
                    html += '<div class="am-capai-customer__data" style="' + rowStyle + '">' + iconSpan + 'Last service: <strong>' + d.last_service + '</strong></div>';

                    if (d.service_date) {
                        html += '<div class="am-capai-customer__data" style="' + rowStyle + '">' + iconSpan + 'Service date: <strong>' + d.service_date + '</strong></div>';
                    }
                    if (d.last_invoice !== null && d.last_invoice !== undefined) {
                        html += '<div class="am-capai-customer__data" style="' + rowStyle + '">' + iconSpan + 'Last invoice: <strong>SGD ' + d.last_invoice + '</strong></div>';
                    }
                    if (d.total_completed && parseInt(d.total_completed, 10) > 0) {
                        html += '<div class="am-capai-customer__data" style="' + rowStyle + '">' + iconSpan + 'Total completed jobs with you: <strong>' + d.total_completed + '</strong></div>';
                    }

                    card.append($(html));
                } else {
                    card.append($('<div class="am-capai-customer__data">Error loading myCred points: ' + (response.data || 'Unknown error') + '</div>'));
                }
            },
            error: function() {
                card.append($('<div class="am-capai-customer__data">Failed to load myCred points.</div>'));
            }
        });
    }

} )( jQuery );