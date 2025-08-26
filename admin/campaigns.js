(function ($) {
  function val(id) {
    return $(id).val() || "";
  }
  function listVals() {
    return $("#wpec-list-ids").val() || [];
  }

  // Ensure Select2 if you want (you already load it elsewhere)
  if ($.fn.select2 && $("#wpec-list-ids").length) {
    $("#wpec-list-ids").select2({
      width: "resolve",
      placeholder: "Select lists…",
    });
  }

  function ensureSelect2(cb) {
    if ($.fn.select2) {
      cb && cb();
      return;
    }
    var CFG = window.WPECCAMPAIGN || {};
    // CSS
    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = CFG.select2LocalCss || CFG.select2CdnCss;
    css.onerror = function () {
      css.href = CFG.select2CdnCss;
    };
    document.head.appendChild(css);
    // JS
    var s = document.createElement("script");
    s.src = CFG.select2LocalJs || CFG.select2CdnJs;
    s.onload = function () {
      cb && cb();
    };
    s.onerror = function () {
      this.src = CFG.select2CdnJs;
      this.onerror = null;
    };
    document.head.appendChild(s);
  }

  function initSendSelects() {
    var $lists = $("#wpec-list-ids");
    if ($lists.length) {
      $lists.select2({
        width: "resolve",
        placeholder: "Select recipient lists…",
        allowClear: true,
      });
    }
  }

  ensureSelect2(initSendSelects);

  function post(action, data) {
    data = data || {};
    data.action = action;
    data.nonce =
      (window.WPECCAMPAIGN && WPECCAMPAIGN.nonce) ||
      (window.WPEC && WPEC.nonce);
    return $.post(
      (window.WPECCAMPAIGN && WPECCAMPAIGN.ajaxUrl) || (WPEC && WPEC.ajaxUrl),
      data,
      null,
      "json"
    );
  }

  $("#wpec-send-test").on("click", function (e) {
    e.preventDefault();
    $(this).prop("disabled", true);
    $("#wpec-test-loader").show();
    post("wpec_send_test", {
      to: val("#wpec-test-to"),
      subject: val("#wpec-subject"),
      body:
        tinyMCE && tinyMCE.get("wpec_camp_html")
          ? tinyMCE.get("wpec_camp_html").getContent()
          : val("#wpec_camp_html"),
      from_name: val("#wpec-from-name"),
      from_email: val("#wpec-from-email"),
    })
      .always(function () {
        $("#wpec-test-loader").hide();
        $("#wpec-send-test").prop("disabled", false);
      })
      .done(function (resp) {
        alert(
          resp && resp.success
            ? "Test sent"
            : (resp && resp.data && resp.data.message) || "Failed"
        );
      });
  });

  function collectPayload(saveOnly) {
    return {
      campaign_id: parseInt(val("#wpec-campaign-id"), 10) || 0,
      name: val("#wpec-name"),
      subject: val("#wpec-subject"),
      from_name: val("#wpec-from-name"),
      from_email: val("#wpec-from-email"),
      body:
        tinyMCE && tinyMCE.get("wpec_camp_html")
          ? tinyMCE.get("wpec_camp_html").getContent()
          : val("#wpec_camp_html"),
      list_ids: listVals(),
      save_only: saveOnly ? "1" : "0",
    };
  }

  $("#wpec-save-draft").on("click", function (e) {
    e.preventDefault();
    var $btn = $(this);
    $btn.prop("disabled", true);
    post("wpec_campaign_queue", collectPayload(true))
      .always(function () {
        $btn.prop("disabled", false);
      })
      .done(function (r) {
        if (r && r.success) {
          $("#wpec-campaign-id").val(
            r.data && r.data.campaign_id ? r.data.campaign_id : ""
          );
          alert("Draft saved");
        } else {
          alert((r && r.data && r.data.message) || "Failed to save draft");
        }
      });
  });

  $("#wpec-queue-campaign").on("click", function (e) {
    e.preventDefault();
    var $btn = $(this);
    $btn.prop("disabled", true);
    post("wpec_campaign_queue", collectPayload(false))
      .always(function () {
        $btn.prop("disabled", false);
      })
      .done(function (r) {
        if (!r || !r.success) {
          alert((r && r.data && r.data.message) || "Failed to queue.");
          return;
        }

        // store id, hide buttons to prevent re-queue, show quick status
        currentCampaignId = r.data.campaign_id || currentCampaignId;
        $("#wpec-queue-campaign, #wpec-save-draft")
          .prop("disabled", true)
          .hide();
        $("#wpec-send-status").text(
          "Queued " +
            (r.data.queued || 0) +
            " recipients for background sending."
        );

        // brief pause, then go to Queue page
        var dest = (window.WPECCAMPAIGN && WPECCAMPAIGN.queueUrl) || "";
        setTimeout(function () {
          if (dest) {
            window.location.href = dest;
          } else {
            // fallback: build it
            var u = new URL(
              (window.WPECCAMPAIGN && WPECCAMPAIGN.adminBase) ||
                (window.WPEC && WPEC.adminBase)
            );
            u.searchParams.set("post_type", "email_campaign");
            u.searchParams.set("page", "wpec-queue");
            window.location.href = u.toString();
          }
        }, 600);
      });
  });
})(jQuery);
