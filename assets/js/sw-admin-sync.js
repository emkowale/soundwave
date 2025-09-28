/*
 * File: assets/js/sw-admin-sync.js
 * Description: One-click "Sync" + on-load batch status check for visible orders.
 * Plugin: Soundwave (WooCommerce Order Sync)
 * Version: 1.2.0
 * Last Updated: 2025-09-27 22:05 EDT
 */
jQuery(function ($) {
  function setBusy($btn, $status, busy) {
    $btn.prop('disabled', !!busy);
    if ($status && window.SW_SYNC && SW_SYNC.i18n) $status.text(busy ? SW_SYNC.i18n.busy : '');
  }

  // --- On-load: check statuses for orders on THIS page only (those showing a Sync button) ---
  (function batchCheck() {
    const $btns = $('.sw-sync-btn');
    if (!$btns.length) return;
    const ids = [...new Set($btns.map((i, el) => $(el).data('order')).get())];
    const nonce = $btns.first().data('nonce'); // reuse the per-row nonce

    $.post(SW_SYNC.ajax, { action: 'sw_check_order_sync_status', order_ids: ids, nonce })
      .done(res => {
        if (!res || !res.success || !res.data || !res.data.statuses) return;
        const statuses = res.data.statuses;
        Object.keys(statuses).forEach(id => {
          if (statuses[id]) {
            const $b = $('.sw-sync-btn[data-order="' + id + '"]');
            const $cell = $b.closest('td');
            $cell.find('.sw-sync-actions, .sw-sync-status').remove();
            $cell.append('<span style="color:#1a7f37;font-weight:600;">' + (SW_SYNC.i18n ? SW_SYNC.i18n.ok : 'Synced') + '</span>');
          }
        });
      })
      .fail(err => console.error('Soundwave status check failed:', err));
  })();

  // --- Click: manual Sync from Orders list ---
  $(document).on('click', '.sw-sync-btn', async function (e) {
    e.preventDefault();
    const $btn = $(this);
    const $wrap = $btn.closest('.sw-sync-actions');
    const $status = $wrap.siblings('.sw-sync-status');
    setBusy($btn, $status, true);

    try {
      const res = await $.post(SW_SYNC.ajax, {
        action: 'sw_sync_order',
        order_id: $btn.data('order'),
        nonce: $btn.data('nonce')
      });
      if (res && res.success) {
        if ($status && SW_SYNC && SW_SYNC.i18n) $status.html('<span style="color:#1a7f37;font-weight:600;">' + SW_SYNC.i18n.ok + '</span>');
        $wrap.remove();
      } else {
        console.error('Soundwave sync error:', res);
        if ($status && SW_SYNC && SW_SYNC.i18n) $status.text(SW_SYNC.i18n.err);
        setBusy($btn, $status, false);
      }
    } catch (err) {
      console.error('Soundwave sync exception:', err);
      if ($status && SW_SYNC && SW_SYNC.i18n) $status.text(SW_SYNC.i18n.err);
      setBusy($btn, $status, false);
    }
  });
});
