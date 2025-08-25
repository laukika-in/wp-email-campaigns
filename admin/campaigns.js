(function ($) {
  function onSendPage() {
    const sp = new URLSearchParams(location.search);
    return (
      sp.get("post_type") === "email_campaign" && sp.get("page") === "wpec-send"
    );
  }

  function val(id) {
    return $(id).val() || "";
  }

  // Send test
  $(document).on("click", "#wpec-send-test", function (e) {
    e.preventDefault();
    $("#wpec-test-loader").show();

    $.post(WPECCAMPAIGN.ajaxUrl, {
      action: "wpec_send_test",
      nonce: WPECCAMPAIGN.nonce,
      to: val("#wpec-test-to"),
      subject: val("#wpec-subject"),
      body: val("#wpec-body"),
      from_name: val("#wpec-from-name"),
      from_email: val("#wpec-from-email"),
    })
      .done(function (res) {
        alert(
          res && res.success
            ? "Test sent."
            : (res && res.data && res.data.message) || "Failed."
        );
      })
      .always(function () {
        $("#wpec-test-loader").hide();
      });
  });

  // Queue campaign
  let currentCampaignId = 0;
  let pollTimer = null;

  function startPolling(cid) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      $.post(WPECCAMPAIGN.ajaxUrl, {
        action: "wpec_campaign_status",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: cid,
      }).done(function (res) {
        if (!res || !res.success) return;
        const d = res.data || {};
        $("#wpec-send-status").text(
          "Queued: " +
            (d.queued || 0) +
            " | Sent: " +
            (d.sent || 0) +
            " | Failed: " +
            (d.failed || 0) +
            " | Total: " +
            (d.total || 0)
        );
        if ((d.queued || 0) === 0) {
          $("#wpec-cancel-campaign").prop("disabled", true);
          clearInterval(pollTimer);
          pollTimer = null;
        }
      });
    }, 4000);
  }

  $(document).on("click", "#wpec-queue-campaign", function (e) {
    e.preventDefault();
    const listIds = $("#wpec-list-ids").val() || [];
    if (!listIds.length) {
      alert("Pick at least one list");
      return;
    }

    $("#wpec-send-status").text("Queuing...");
    $.post(WPECCAMPAIGN.ajaxUrl, {
      action: "wpec_campaign_queue",
      nonce: WPECCAMPAIGN.nonce,
      subject: val("#wpec-subject"),
      body: val("#wpec-body"),
      from_name: val("#wpec-from-name"),
      from_email: val("#wpec-from-email"),
      list_ids: listIds,
    }).done(function (res) {
      if (!res || !res.success) {
        alert((res && res.data && res.data.message) || "Queue failed");
        $("#wpec-send-status").text("");
        return;
      }
      currentCampaignId = res.data.campaign_id;
      $("#wpec-send-status").text(
        "Queued " +
          (res.data.queued || 0) +
          " recipients. Sending in background…"
      );
      $("#wpec-cancel-campaign").prop("disabled", false);
      startPolling(currentCampaignId);
    });
  });

  // Cancel
  $(document).on("click", "#wpec-cancel-campaign", function (e) {
    e.preventDefault();
    if (!currentCampaignId) return;
    if (!confirm("Cancel the current job?")) return;

    $.post(WPECCAMPAIGN.ajaxUrl, {
      action: "wpec_campaign_cancel",
      nonce: WPECCAMPAIGN.nonce,
      campaign_id: currentCampaignId,
    }).done(function () {
      $("#wpec-send-status").text("Job cancelled.");
      $("#wpec-cancel-campaign").prop("disabled", true);
    });
  });

  $(function () {
    if (!onSendPage()) return;
    // (Optional) enhance selects later with Select2 if you like.
  });

  function ensureSelect2(cb) {
    if ($.fn.select2) {
      cb && cb();
      return;
    }
    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = (window.WPECCAMPAIGN && WPECCAMPAIGN.select2LocalCss) || "";
    css.onerror = function () {
      this.href = WPECCAMPAIGN.select2CdnCss;
    };
    document.head.appendChild(css);

    var s = document.createElement("script");
    s.src = (window.WPECCAMPAIGN && WPECCAMPAIGN.select2LocalJs) || "";
    s.onload = function () {
      cb && cb();
    };
    s.onerror = function () {
      this.onerror = null;
      this.src = WPECCAMPAIGN.select2CdnJs;
    };
    document.head.appendChild(s);
  }

  $(function () {
    // Only run on our Send screen
    if (!$("#wpec-send-app").length) return;

    ensureSelect2(function () {
      // Single or multi — both become searchable
      $("#wpec-send-list").addClass("wpec-s2").select2({
        width: "resolve",
        placeholder: "Select list…",
        allowClear: true,
      });
    });
  });
  function getHtmlBody() {
    if (window.tinymce && tinymce.get("wpec_camp_html")) {
      return tinymce.get("wpec_camp_html").getContent();
    }
    return $("#wpec_camp_html").val() || "";
  }

  // Example usage:
  var html = getHtmlBody();
  // send `html` in your AJAX payload as before
})(jQuery);
