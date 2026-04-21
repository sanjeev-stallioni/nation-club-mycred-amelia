(function(){
    document.addEventListener('click', function(e){
        var link = e.target.closest('.nc-txn-view');
        if (!link) return;
        e.preventDefault();

        var root     = link.closest('.nc-vendor-history');
        var row      = link.closest('tr');
        var txnId    = link.dataset.txn;
        var existing = row.nextElementSibling;

        // collapse if already open for same txn
        if (existing && existing.classList.contains('nc-txn-breakdown-row') && existing.dataset.txn === txnId) {
            existing.remove();
            return;
        }
        // remove any other expanded row in the table
        var all = root.querySelectorAll('.nc-txn-breakdown-row');
        all.forEach(function(el){ el.remove(); });

        // show loading row
        var loadRow = document.createElement('tr');
        loadRow.className = 'nc-txn-breakdown-row';
        loadRow.dataset.txn = txnId;
        loadRow.innerHTML = '<td colspan="6" class="nc-txn-loading">Loading breakdown…</td>';
        row.parentNode.insertBefore(loadRow, row.nextSibling);

        var body = new FormData();
        body.append('action', 'nc_get_txn_breakdown');
        body.append('nonce', root.dataset.nonce);
        body.append('txn_id', txnId);

        fetch(root.dataset.ajaxurl, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(function(json){
                if (json && json.success) {
                    loadRow.innerHTML = '<td colspan="6">' + json.data.html + '</td>';
                } else {
                    loadRow.innerHTML = '<td colspan="6" class="nc-txn-error">Unable to load breakdown.</td>';
                }
            })
            .catch(function(){
                loadRow.innerHTML = '<td colspan="6" class="nc-txn-error">Network error.</td>';
            });
    });
})();
