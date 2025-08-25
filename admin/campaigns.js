(function ($) {
  function getBodyHtml() {
    try {
      if (window.tinymce && tinymce.get("wpec-campaign-body")) {
        return tinymce.get("wpec-campaign-body").getContent();
      }
    } catch (e) {}
    return $("#wpec-campaign-body").val() || "";
  }

  $(document).on("click", "#wpec-send-test", function (e) {
    e.preventDefault();
    var to = ($("#wpec-test-to").val() || "").trim();
    var subject = ($("#wpec-campaign-subject").val() || "").trim();
    var fromName = ($("#wpec-from-name").val() || "").trim();
    var fromEmail = ($("#wpec-from-email").val() || "").trim();
    var body = getBodyHtml();

    if (!to || !subject || !body) {
      alert("Please enter test address, subject, and message.");
      return;
    }

    $("#wpec-send-test").prop("disabled", true);
    $("#wpec-send-loader").show();

    $.post(WPEC.ajaxUrl, {
      action: "wpec_send_test",
      nonce: WPEC.nonce,
      to: to,
      subject: subject,
      body: body,
      from_name: fromName,
      from_email: fromEmail,
    })
      .done(function (res) {
        if (res && res.success) {
          alert("Test sent.");
        } else {
          alert((res && res.data && res.data.message) || "Send failed.");
        }
      })
      .fail(function () {
        alert("Request failed.");
      })
      .always(function () {
        $("#wpec-send-test").prop("disabled", false);
        $("#wpec-send-loader").hide();
      });
  });
})(jQuery);
