(function($){
  $(document).on('click', '.ebts-del-cert,[data-ebts-action="del-cert"],.button-delete-cert', function(e){
    e.preventDefault();
    var $btn = $(this);
    var userId = $btn.data('user') || $btn.data('user-id') || $btn.attr('data-user') || $btn.attr('data-user-id');
    var certId = $btn.data('cert') || $btn.data('cert-id') || $btn.attr('data-cert') || $btn.attr('data-cert-id');

    if(!userId || !certId){
      alert('Parametri mancanti (user/cert).');
      return;
    }
    if(!confirm('Eliminare definitivamente questo attestato?')) return;

    var url = (window.EBTSAJ && EBTSAJ.ajax) ? EBTSAJ.ajax : (window.EBTS_DEL ? EBTS_DEL.ajax : (typeof ajaxurl!=='undefined'? ajaxurl : 'admin-ajax.php'));
    var nonce = (window.EBTSAJ && EBTSAJ.nonce) ? EBTSAJ.nonce : (window.EBTS_DEL ? EBTS_DEL.nonce : '');

    $btn.prop('disabled', true).addClass('disabled');

    $.post(url, {
      action: 'ebts_delete_attestato',
      user_id: userId,
      cert_id: certId,
      nonce: nonce
    }).done(function(resp){
      if(resp && resp.success){
        var removed = false;
        var $row = $btn.closest('.ebts-cert-row');
        if($row.length){ $row.remove(); removed = true; }
        if(!removed){ $row = $btn.closest('tr'); if($row.length){ $row.remove(); removed = true; } }
        if(!removed){ $row = $btn.closest('li'); if($row.length){ $row.remove(); removed = true; } }
        if(!removed){ location.reload(); }
      } else {
        var msg = (resp && resp.data && resp.data.message) ? resp.data.message : 'Errore sconosciuto';
        alert('Errore: ' + msg);
        $btn.prop('disabled', false).removeClass('disabled');
      }
    }).fail(function(xhr){
      var msg = (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) ? xhr.responseJSON.data.message : 'Errore di rete';
      alert('Errore: ' + msg);
      $btn.prop('disabled', false).removeClass('disabled');
    });
  });
})(jQuery);
