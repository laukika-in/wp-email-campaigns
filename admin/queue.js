(function($){
  function post(action, cid){
    return $.post(WPECQUEUE.ajaxUrl, { action, nonce: WPECQUEUE.nonce, campaign_id: cid });
  }
  $(document).on('click', '.wpec-q-pause', function(){
    var id = $(this).data('id');
    post('wpec_campaign_pause', id).always(function(){ location.reload(); });
  });
  $(document).on('click', '.wpec-q-resume', function(){
    var id = $(this).data('id');
    post('wpec_campaign_resume', id).always(function(){ location.reload(); });
  });
  $(document).on('click', '.wpec-q-cancel', function(){
    if(!confirm('Cancel this job?')) return;
    var id = $(this).data('id');
    post('wpec_campaign_cancel', id).always(function(){ location.reload(); });
  });
})(jQuery);
