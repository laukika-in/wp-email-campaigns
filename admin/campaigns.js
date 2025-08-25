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

    var to = ($("#wpec-test-to").val() || "").trim();
    var subject = ($("#wpec-subject").val() || "").trim();
    var fromName = ($("#wpec-from-name").val() || "").trim();
    var fromEmail = ($("#wpec-from-email").val() || "").trim();
    var body = getCampaignHtml();

    if (!to || !subject || !body) {
      alert("Test needs To, Subject and Body.");
      return;
    }

    var $btn = $(this);
    $btn.prop("disabled", true);
    $("#wpec-test-loader").show();

    $.post(
      WPECCAMPAIGN.ajaxUrl,
      {
        action: "wpec_send_test",
        nonce: WPECCAMPAIGN.nonce,
        to: to,
        subject: subject,
        from_name: fromName,
        from_email: fromEmail,
        body: body, // IMPORTANT: key must be "body"
      },
      function (res) {
        if (res && res.success) {
          alert("Test email sent.");
        } else {
          alert((res && res.data && res.data.message) || "Test failed.");
        }
      },
      "json"
    ).always(function () {
      $btn.prop("disabled", false);
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
  // Reads HTML from the WP editor used on the Send screen
  function getCampaignHtml() {
    try {
      if (
        window.tinyMCE &&
        tinyMCE.get("wpec_camp_html") &&
        !tinyMCE.get("wpec_camp_html").isHidden()
      ) {
        return tinyMCE.get("wpec_camp_html").getContent() || "";
      }
    } catch (e) {}
    // Fallback to the textarea if TinyMCE is off
    var el = document.getElementById("wpec_camp_html");
    return el ? el.value || "" : "";
  }

  $(document).on("click", "#wpec-queue-campaign", function (e) {
    e.preventDefault();

    var subject = ($("#wpec-subject").val() || "").trim();
    var fromName = ($("#wpec-from-name").val() || "").trim();
    var fromEmail = ($("#wpec-from-email").val() || "").trim();
    var body = getCampaignHtml();
    var listIds = $("#wpec-list-ids").val() || []; // array

    if (!subject || !body || listIds.length === 0) {
      alert("Subject, Body, and at least one list are required.");
      return;
    }

    var $btn = $(this);
    $btn.prop("disabled", true);

    $.post(
      WPECCAMPAIGN.ajaxUrl,
      {
        action: "wpec_campaign_queue",
        nonce: WPECCAMPAIGN.nonce,
        subject: subject,
        from_name: fromName,
        from_email: fromEmail,
        body: body, // IMPORTANT: key must be "body"
        list_ids: listIds, // IMPORTANT: key must be "list_ids"
      },
      function (res) {
        if (!res || !res.success) {
          alert((res && res.data && res.data.message) || "Failed to queue.");
          $btn.prop("disabled", false);
          return;
        }
        // show a simple status and let cron take over
        alert(
          "Queued " +
            (res.data.queued || 0) +
            " recipients for background sending."
        );
        $btn.prop("disabled", false);
      },
      "json"
    ).fail(function () {
      alert("Request failed.");
      $btn.prop("disabled", false);
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

  $(function () {
    // Only on Send page (?page=wpec-send)
    var page = new URL(location.href).searchParams.get("page");
    if (page === "wpec-send") {
      ensureSelect2(initSendSelects);
    }
  });
  $(function () {
    if (!onSendPage()) return;
    // Load Select2 (local → CDN), then run cb

    // Get HTML from WP editor (falls back to textarea)
    function getEditorHtml() {
      var ed = window.tinyMCE && tinyMCE.get("wpec_camp_html");
      if (ed && !ed.isHidden()) return ed.getContent();
      return $("#wpec_camp_html").val() || "";
    }

    function initSendUI() {
      // Init Select2 on your IDs/classes
      $("#wpec-list-ids, #wpec-exclude-lists, select.wpec-s2").each(
        function () {
          $(this).select2({
            width: "resolve",
            allowClear: true,
            placeholder: $(this).data("placeholder") || "Select…",
          });
        }
      );

      // TEST SEND
      $(document).on("click", "#wpec-send-test", function (e) {
        e.preventDefault();
        $("#wpec-test-loader").show();
        $.post(WPECCAMPAIGN.ajaxUrl, {
          action: "wpec_send_test",
          nonce: WPECCAMPAIGN.nonce,
          to: $("#wpec-test-to").val(),
          subject: $("#wpec-subject").val(),
          from_name: $("#wpec-from-name").val(),
          from_email: $("#wpec-from-email").val(),
          body: getEditorHtml(),
        })
          .done(function (res) {
            alert(
              res && res.success ? "Test sent." : res?.data?.message || "Failed"
            );
          })
          .always(function () {
            $("#wpec-test-loader").hide();
          });
      });

      // QUEUE SEND
      $(document).on("click", "#wpec-queue-campaign", function (e) {
        e.preventDefault();
        var listIds = $("#wpec-list-ids").val() || [];
        $.post(WPECCAMPAIGN.ajaxUrl, {
          action: "wpec_campaign_queue",
          nonce: WPECCAMPAIGN.nonce,
          subject: $("#wpec-subject").val(),
          from_name: $("#wpec-from-name").val(),
          from_email: $("#wpec-from-email").val(),
          body: getEditorHtml(),
          list_ids: listIds,
        }).done(function (res) {
          if (res && res.success) {
            $("#wpec-send-status").text(
              "Queued " + (res.data?.queued || 0) + " emails."
            );
          } else {
            alert(res?.data?.message || "Queue failed.");
          }
        });
      });
    }

    $(function () {
      if (!document.getElementById("wpec-send-app")) return; // only on Send page
      ensureSelect2(initSendUI);
    });
  });
})(jQuery);
