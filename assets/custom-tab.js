( function( $ ) {
    console.log('NC Amelia Plugin: Script Initialized');

    // Use MutationObserver to detect modal opening (when .el-tabs.el-tabs--top is added)
    const observer = new MutationObserver(function(mutations) {
        mutations.forEach(function(mutation) {
            if (mutation.addedNodes.length > 0) {
                const modal = $('.el-tabs.el-tabs--top');
                if (modal.length && !modal.hasClass('custom-data-added')) {
                    addCustomData(modal);
                    modal.addClass('custom-data-added');
                }
            }
        });
    });
    observer.observe(document.body, { childList: true, subtree: true });

    function addCustomData(modal) {
        // Fetch customer email from Customers pane
        const emailAnchor = modal.find(
            '#pane-customers a[href^="mailto:"]'
        );

        const customerEmail = emailAnchor.length
            ? emailAnchor.attr('href').replace('mailto:', '').trim()
            : '';

        if (customerEmail) {
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
                        // Inject the points into the customer info section
                        const pointsDisplay = $('<div class="am-capai-customer__data" style="font-weight: 600; font-size: 14px"><span style="font-weight: 400;" class="am-icon-circle-info"></span> Points holding: <strong>' + response.data.points + '</strong></div><div class="am-capai-customer__data" style="font-weight: 600; font-size: 14px"><span style="font-weight: 400;" class="am-icon-circle-info"></span> Last service: <strong>' + response.data.last_service + '</strong></div>');
                        modal.find('#pane-customers .am-capai-cuf__card-info').append(pointsDisplay);
                    } else {
                        // Optional: Inject error message if needed
                        const errorDisplay = $('<div class="am-capai-customer__data">Error loading myCred points: ' + (response.data || 'Unknown error') + '</div>');
                        modal.find('#pane-customers .am-capai-cuf__card-info').append(errorDisplay);
                    }
                },
                error: function() {
                    // Optional: Inject failure message
                    const failDisplay = $('<div class="am-capai-customer__data">Failed to load myCred points.</div>');
                    modal.find('#pane-customers .am-capai-cuf__card-info').append(failDisplay);
                }
            });
        } else {
            // Optional: Handle no email case
            const noEmailDisplay = $('<div class="am-capai-customer__data">No customer email found for myCred points.</div>');
            modal.find('#pane-customers .am-capai-cuf__card-info').append(noEmailDisplay);
        }
    }

} )( jQuery );