(function($){
  function collectVisibleOrderIds(){
    var ids = [];
    $('table.wp-list-table tbody tr').each(function(){
      var $tr = $(this), id = 0, cb = $tr.find('th.check-column input[type=checkbox]');
      if (cb.length && cb.val()) id = parseInt(cb.val(),10);
      if (!id){ var m = ($tr.attr('id')||'').match(/(post|order)-(\d+)/); if (m) id = parseInt(m[2],10); }
      if (!id){ var d = $tr.data('id') || $tr.data('orderId') || $tr.data('order-id'); if (d) id = parseInt(d,10); }
      if (id) ids.push(id);
    }); return ids;
  }
  function renderUnsynced($cell, id){
    if (!$cell.find('.soundwave-sync-btn').length){
      var html = '<div class="soundwave-actions">'
        + '<button class="button soundwave-sync-btn" data-order="'+id+'">Sync</button>'
        + '<a class="button button-link-delete soundwave-fix-btn" style="display:none" href="#" target="_blank">Fix errors</a>'
        + '<span class="spinner" style="margin-left:6px;display:none;"></span>'
        + '</div>';
      $cell.html(html);
    }
  }
  function poll(){
    var ids = collectVisibleOrderIds(); if (!ids.length) return;
    $.post((window.SoundwaveAjax?SoundwaveAjax.ajaxurl:ajaxurl), {action:'soundwave_check_status', order_ids: ids}, function(resp){
      if (!resp || !resp.success) return;
      var results = (resp.data && resp.data.results) ? resp.data.results : {};
      $('table.wp-list-table tbody tr').each(function(){
        var $tr = $(this), id = 0, cb = $tr.find('th.check-column input[type=checkbox]');
        if (cb.length && cb.val()) id = parseInt(cb.val(),10);
        if (!id){ var m = ($tr.attr('id')||'').match(/(post|order)-(\d+)/); if (m) id = parseInt(m[2],10); }
        if (!id){ var d = $tr.data('id') || $tr.data('orderId') || $tr.data('order-id'); if (d) id = parseInt(d,10); }
        if (!id) return;
        var state = results[id], $cell = $tr.find('td.column-soundwave_sync, td.soundwave_sync');
        if (!$cell.length) return;
        if (state === 'synced'){ $cell.html('<span class="soundwave-synced-text">Synced</span>'); }
        else if (state === 'unsynced'){ renderUnsynced($cell, id); }
      });
    });
  }
  $(document).on('click', '.soundwave-sync-btn', function(e){
    e.preventDefault();
    var $btn = $(this), $wrap = $btn.closest('.soundwave-actions'), $fix = $wrap.find('.soundwave-fix-btn'), $spinner = $wrap.find('.spinner'), id = parseInt($btn.data('order'),10), rowNonce = $btn.data('nonce');
    $btn.prop('disabled', true).text('Syncing...'); $spinner.show().addClass('is-active');
    $.post((window.SoundwaveAjax?SoundwaveAjax.ajaxurl:ajaxurl), {action:'soundwave_sync_order', order_id:id, row_nonce:rowNonce||'', nonce:(window.SoundwaveAjax&&SoundwaveAjax.nonce_global)?SoundwaveAjax.nonce_global:''}, function(resp){
      if (resp && resp.success){ $wrap.html('<span class="soundwave-synced-text">Synced</span>'); }
      else { var fix = (resp&&resp.data&&resp.data.fix_url)?resp.data.fix_url:($fix.attr('href')||'#'); $fix.attr('href', fix).show().text('Fix errors'); $btn.prop('disabled', false).text('Sync'); $spinner.hide().removeClass('is-active'); }
    }).fail(function(){ $btn.prop('disabled', false).text('Sync'); $spinner.hide().removeClass('is-active'); $fix.show().text('Fix errors'); });
  });
  $(function(){ poll(); setInterval(poll, 25000); });
})(jQuery);
