(function ($) {
  function post(action, cid) {
    return $.post(WPECQUEUE.ajaxUrl, {
      action,
      nonce: WPECQUEUE.nonce,
      campaign_id: cid,
    });
  }
})(jQuery);
