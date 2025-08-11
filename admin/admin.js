(function($){
  $(document).on('click', '.wpec-pause, .wpec-resume, .wpec-cancel', function(e){
    e.preventDefault();
    var $btn = $(this);
    var id = $btn.data('id');
    var action = $btn.hasClass('wpec-pause') ? 'wpec_pause' : ($btn.hasClass('wpec-resume') ? 'wpec_resume' : 'wpec_cancel');
    $btn.prop('disabled', true);
    $.post(ajaxurl, { action: action, id: id, nonce: WPEC.nonce }, function(resp){
      $btn.prop('disabled', false);
      if(resp && resp.success){
        alert(resp.data.message || 'OK');
        location.reload();
      } else {
        alert('Failed');
      }
    });
  });

  // Confirm on publish
  $(function(){
    var $form = $('#post');
    if ($('body').hasClass('post-type-email_campaign')) {
      $form.on('submit', function(){
        if ($('#publish').length) {
          var ok = confirm('Are you sure you want to publish and start sending this campaign?');
          if (!ok) return false;
        }
      });
    }
  });
})(jQuery);
