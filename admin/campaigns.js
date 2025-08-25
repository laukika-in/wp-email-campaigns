// admin/campaigns.js
jQuery(function ($) {
  // Only on Email Campaign edit screens
  if (!$("body").hasClass("post-type-email_campaign")) return;

  $(document).on("click", "#wpec-test-send", function (e) {
    e.preventDefault();
    var $btn = $(this),
      $msg = $("#wpec-test-msg"),
      $ldr = $("#wpec-test-loader");

    var data = {
      action: "wpec_campaign_test_send",
      nonce: (window.WPEC && WPEC.nonce) || "",
      post_id: $("#post_ID").val(),
      to: $("#wpec_test_email").val(),
      from_name: $("#wpec_from_name").val(),
      from_email: $("#wpec_from_email").val(),
      subject: $("#wpec_subject").val(),
      body: $("#wpec_body").val(),
    };

    $msg.text("");
    $btn.prop("disabled", true);
    $ldr.show();

    $.post((window.WPEC && WPEC.ajaxUrl) || ajaxurl, data, null, "json")
      .done(function (resp) {
        if (resp && resp.success) {
          $msg.css("color", "#0a0").text("Test email sent.");
        } else {
          var err = (resp && resp.data && resp.data.message) || "Send failed.";
          $msg.css("color", "#a00").text(err);
        }
      })
      .fail(function () {
        $msg.css("color", "#a00").text("Request failed.");
      })
      .always(function () {
        $ldr.hide();
        $btn.prop("disabled", false);
      });
  });
});
