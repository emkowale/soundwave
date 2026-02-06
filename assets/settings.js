/*
 * File: assets/settings.js
 * Description: Handles “Test API Connection” on Settings; posts nonce; shows inline ✅/❌ with spinner.
 * Plugin: Soundwave
 * Last Updated: 2025-10-27 09:10 EDT
 */

(function($){
  function els(){
    var $btn    = $(SOUNDWAVE_SETTINGS.buttonSel || '#sw-test-conn-btn');
    var $status = $(SOUNDWAVE_SETTINGS.statusSel || '#sw-test-conn-status');
    var $spin   = $(SOUNDWAVE_SETTINGS.spinSel   || '#sw-test-conn-status .sw-spin');
    var $res    = $(SOUNDWAVE_SETTINGS.resultSel || '#sw-test-conn-status .sw-result');
    return {$btn:$btn,$status:$status,$spin:$spin,$res:$res};
  }

  function setState($spin,$res, state, text){
    if (!$spin.length || !$res.length) return;
    if (state === 'spin'){
      $spin.show();
      $res.text('').css('color','');
    } else {
      $spin.hide();
      $res.text(text || '');
      $res.css('color', state === 'ok' ? '#22863a' : '#d63638');
    }
  }

  $(document).on('click', SOUNDWAVE_SETTINGS.buttonSel || '#sw-test-conn-btn', function(e){
    e.preventDefault();

    var {$btn,$spin,$res} = els();
    $btn.prop('disabled', true).attr('aria-busy','true');
    setState($spin,$res,'spin');

    $.ajax({
      url: SOUNDWAVE_SETTINGS.ajax_url,
      method: 'POST',
      dataType: 'json',
      data: {
        action: SOUNDWAVE_SETTINGS.action,
        _ajax_nonce: SOUNDWAVE_SETTINGS.nonce
      }
    })
    .done(function(resp){
      if (resp && resp.success){
        var msg = (resp.data && resp.data.message) ? resp.data.message : 'Success.';
        setState($spin,$res,'ok','✅ ' + msg);
      } else {
        var m = (resp && resp.data && resp.data.message) ? resp.data.message : 'Failed.';
        setState($spin,$res,'fail','❌ ' + m);
      }
    })
    .fail(function(xhr){
      var msg = 'HTTP ' + (xhr && xhr.status ? xhr.status : 'error') + ' — ' +
                (xhr && xhr.responseText ? xhr.responseText : 'No response');
      setState($spin,$res,'fail','❌ ' + msg);
    })
    .always(function(){
      $btn.prop('disabled', false).removeAttr('aria-busy');
    });
  });
})(jQuery);
