(function($){
  function toggleQuick($btn, uid){
    var $tr = $btn.closest('tr');
    var $row = $tr.next('.ebts-quick-row');
    if ($row.is(':visible')) { $row.hide(); return; }
    $row.show();
    var $a = $row.find('.a'), $b = $row.find('.b'), $c = $row.find('.c');
    if ($a.data('loaded')) return;
    $a.data('loaded', true);

    $.post(EBTSAJ.ajax, {action:'ebts_user_get', _nonce:EBTSAJ.nonce, user_id:uid}, function(resp){
      if(!resp || !resp.success){ $a.html('<em>Errore caricamento</em>'); return; }
      var d = resp.data;
      $a.html(
        '<h3>Anagrafica</h3>' +
        '<p><label>Nome<br><input type="text" class="widefat" name="first_name" value="'+esc(d.first_name)+'"></label></p>' +
        '<p><label>Cognome<br><input type="text" class="widefat" name="last_name" value="'+esc(d.last_name)+'"></label></p>' +
        '<p><label>Email<br><input type="email" class="widefat" name="email" value="'+esc(d.email)+'"></label></p>' +
        '<p><label>Telefono<br><input type="text" class="widefat" name="telefono" value="'+esc(d.telefono||'')+'"></label></p>' +
        '<p><label>Codice Fiscale<br><input type="text" class="widefat" name="cfiscale" value="'+esc(d.cfiscale||'')+'"></label></p>' +
        '<p><button class="button button-primary save-anag">Salva</button> <a class="button" target="_blank" href="'+EBTSAJ.detail_url+uid+'">Dettagli (EBTS)</a></p>'
      ).on('click','.save-anag', function(){
        var payload = { action:'ebts_user_save', _nonce:EBTSAJ.nonce, user_id:uid };
        $a.find('input').each(function(){ payload[this.name] = $(this).val(); });
        $.post(EBTSAJ.ajax, payload, function(r){
          if(r && r.success){ alert('Salvato'); } else { alert('Errore salvataggio'); }
        });
      });

      var dl = (EBTSAJ.download_action && d.has_payslip) ? (EBTSAJ.ajax + '?action='+EBTSAJ.download_action+'&kind=busta&user_id='+uid+'&_wpnonce='+EBTSAJ.nonce) : '';
      $b.html('<h3>Busta paga</h3>' + (dl?'<p><a class="button" target="_blank" href="'+dl+'">Scarica</a></p>':'<p><em>Non presente</em></p>') +
        '<p><input type="file" name="busta_paga" accept="application/pdf"> <button class="button upload-payslip">Carica / Sostituisci</button></p>')
        .on('click','.upload-payslip', function(e){
          e.preventDefault();
          var f = $b.find('input[type=file]')[0]; if(!f.files.length){ alert('Seleziona un PDF'); return; }
          var fd = new FormData(); fd.append('action','ebts_user_upload_payslip'); fd.append('_nonce',EBTSAJ.nonce); fd.append('user_id', uid); fd.append('busta_paga', f.files[0]);
          $.ajax({url:EBTSAJ.ajax, method:'POST', data:fd, processData:false, contentType:false}).done(function(r){
            if (r && r.success) alert('Busta paga aggiornata'); else alert('Errore upload');
          });
        });

      $c.html('<h3>Attestati</h3><div class="certs"></div><hr><p><strong>Carica nuovo</strong></p><p><label>ID Corso<br><input type="number" class="small-text" name="course_id"></label></p><p><label>Titolo<br><input type="text" class="widefat" name="title" value="Attestato"></label></p><p><label>Data<br><input type="date" name="date"></label></p><p><input type="file" name="cert_pdf" accept="application/pdf"> <button class="button upload-cert">Carica</button></p>');
      var reload = function(){
        $.post(EBTSAJ.ajax, {action:'ebts_user_list_certs', _nonce:EBTSAJ.nonce, user_id:uid}, function(r){
          var $wrap = $c.find('.certs').empty();
          if(!r || !r.success){ $wrap.text('Errore'); return; }
          if(!r.data.items.length){ $wrap.html('<em>Nessun attestato</em>'); return; }
          var html = '<ul>';
          r.data.items.forEach(function(it){
            var link = EBTSAJ.download_action ? '<a class="button button-small" target="_blank" href="'+(EBTSAJ.ajax+'?action='+EBTSAJ.download_action+'&kind=attestato&user_id='+uid+'&cert_id='+encodeURIComponent(it.id)+'&_wpnonce='+EBTSAJ.nonce)+'">Scarica</a>' : '';
            html += '<li>['+esc(it.date)+'] '+esc(it.title)+' — corso #'+it.course_id+' '+link+' <a href="#" data-id="'+it.id+'" class="button-link del">Elimina</a></li>';
          });
          html += '</ul>'; $wrap.html(html).on('click','.del', function(e){
            e.preventDefault();
            var id=$(this).data('id');
            $.post(EBTSAJ.ajax, {action:'ebts_user_delete_cert', _nonce:EBTSAJ.nonce, user_id:uid, cert_id:id}, function(r2){ if(r2 && r2.success) reload(); else alert('Errore eliminazione'); });
          });
        });
      };
      reload();
      $c.on('click','.upload-cert', function(e){
        e.preventDefault();
        var cid=parseInt($c.find('[name=course_id]').val(),10);
        var title=$c.find('[name=title]').val();
        var date=$c.find('[name=date]').val();
        var f=$c.find('input[type=file]')[0]; if(!cid || !f.files.length){ alert('Seleziona corso e PDF'); return; }
        var fd=new FormData(); fd.append('action','ebts_user_upload_cert'); fd.append('_nonce',EBTSAJ.nonce); fd.append('user_id',uid); fd.append('course_id',cid); fd.append('title',title); fd.append('date',date); fd.append('cert_pdf',f.files[0]);
        $.ajax({url:EBTSAJ.ajax, method:'POST', data:fd, processData:false, contentType:false}).done(function(r3){ if(r3 && r3.success){ alert('Attestato caricato'); reload(); } else alert('Errore upload'); });
      });
    });
  }

  $(document).on('click','.ebts-quick', function(e){
    e.preventDefault();
    toggleQuick($(this), $(this).data('user'));
  });

  function esc(s){ return (s||'').toString().replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]); }); }
})(jQuery);
