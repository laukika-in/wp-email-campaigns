(function ($) {
  // -------- Page guard -------------------------------------------------------
  function onSendPage() {
    const sp = new URLSearchParams(location.search);
    return (
      sp.get("post_type") === "email_campaign" && sp.get("page") === "wpec-send"
    );
  }

  // -------- Helpers ----------------------------------------------------------
  function val(sel) {
    return $(sel).val() || "";
  }

  function getEditorHtml() {
    try {
      var ed = window.tinyMCE && tinyMCE.get("wpec_camp_html");
      if (ed && !ed.isHidden()) return ed.getContent() || "";
    } catch (e) {}
    var el = document.getElementById("wpec_camp_html");
    return el ? el.value || "" : "";
  }

  function ensureSelect2(cb) {
    if ($.fn.select2) {
      cb && cb();
      return;
    }
    var CFG = window.WPECCAMPAIGN || {};

    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = CFG.select2LocalCss || CFG.select2CdnCss;
    css.onerror = function () {
      css.href = CFG.select2CdnCss;
    };
    document.head.appendChild(css);

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

  function initSelects() {
    // main lists + any future select2 with class
    $("#wpec-list-ids, #wpec-exclude-lists, select.wpec-s2").each(function () {
      $(this).select2({
        width: "resolve",
        allowClear: true,
        placeholder: $(this).data("placeholder") || "Select…",
      });
    });
  }

  function ajax(action, payload) {
    payload = payload || {};
    payload.action = action;
    payload.nonce =
      (window.WPECCAMPAIGN && WPECCAMPAIGN.nonce) ||
      (window.WPEC && WPEC.nonce);
    var url =
      (window.WPECCAMPAIGN && WPECCAMPAIGN.ajaxUrl) ||
      (window.WPEC && WPEC.ajaxUrl);
    return $.post(url, payload, null, "json");
  }

  // -------- State ------------------------------------------------------------
  let currentCampaignId = parseInt(val("#wpec-campaign-id"), 10) || 0;
  let pollTimer = null;

  function startPolling(cid) {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(function () {
      ajax("wpec_campaign_status", { campaign_id: cid }).done(function (res) {
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
            (d.total || 0) +
            " | State: " +
            (d.state || "-")
        );
        if ((d.queued || 0) === 0) {
          $("#wpec-cancel-campaign").prop("disabled", true);
          clearInterval(pollTimer);
          pollTimer = null;
        }
      });
    }, 4000);
  }

  function collectPayload(saveOnly) {
    return {
      campaign_id: parseInt(val("#wpec-campaign-id"), 10) || 0,
      name: val("#wpec-name"),
      subject: val("#wpec-subject"),
      from_name: val("#wpec-from-name"),
      from_email: val("#wpec-from-email"),
      body: getEditorHtml(), // KEY must be "body"
      list_ids: $("#wpec-list-ids").val() || [], // KEY must be "list_ids"
      save_only: saveOnly ? "1" : "0",
    };
  }

  // -------- Bindings (only on Send page) ------------------------------------
  $(function () {
    if (!onSendPage()) return;

    // Load Select2 (local -> CDN) then init
    ensureSelect2(initSelects);

    // TEST SEND
    $(document).on("click", "#wpec-send-test", function (e) {
      e.preventDefault();

      var to = val("#wpec-test-to").trim();
      var subject = val("#wpec-subject").trim();
      var body = getEditorHtml();

      if (!to || !subject || !body) {
        alert("Test needs To, Subject and Body.");
        return;
      }

      var $btn = $(this);
      $btn.prop("disabled", true);
      $("#wpec-test-loader").show();

      ajax("wpec_send_test", {
        to: to,
        subject: subject,
        from_name: val("#wpec-from-name"),
        from_email: val("#wpec-from-email"),
        body: body,
      })
        .always(function () {
          $btn.prop("disabled", false);
          $("#wpec-test-loader").hide();
        })
        .done(function (res) {
          alert(
            res && res.success
              ? "Test email sent."
              : res?.data?.message || "Test failed."
          );
        });
    });

    // SAVE DRAFT
    $(document).on("click", "#wpec-save-draft", function (e) {
      e.preventDefault();
      var $btn = $(this).prop("disabled", true);

      ajax("wpec_campaign_queue", collectPayload(true))
        .always(function () {
          $btn.prop("disabled", false);
        })
        .done(function (r) {
          if (r && r.success) {
            currentCampaignId =
              r.data && r.data.campaign_id
                ? r.data.campaign_id
                : currentCampaignId;
            $("#wpec-campaign-id").val(currentCampaignId || "");
            alert("Draft saved.");
          } else {
            alert((r && r.data && r.data.message) || "Failed to save draft.");
          }
        });
    });

    // QUEUE SEND
    $(document).on("click", "#wpec-queue-campaign", function (e) {
      e.preventDefault();

      var payload = collectPayload(false);
      if (
        !(payload.subject && payload.body && (payload.list_ids || []).length)
      ) {
        alert("Subject, Body, and at least one list are required.");
        return;
      }

      var $btn = $(this).prop("disabled", true);

      ajax("wpec_campaign_queue", payload)
        .always(function () {
          $btn.prop("disabled", false);
        })
        .done(function (r) {
          if (!r || !r.success) {
            alert((r && r.data && r.data.message) || "Failed to queue.");
            return;
          }
          currentCampaignId = r.data.campaign_id || currentCampaignId;
          $("#wpec-cancel-campaign").prop("disabled", false);
          alert(
            "Queued " +
              (r.data.queued || 0) +
              " recipients for background sending."
          );
          startPolling(currentCampaignId);
        })
        .fail(function () {
          alert("Request failed.");
        });
    });

    // CANCEL
    $(document).on("click", "#wpec-cancel-campaign", function (e) {
      e.preventDefault();
      if (!currentCampaignId) return;
      if (!confirm("Cancel the current job?")) return;

      ajax("wpec_campaign_cancel", { campaign_id: currentCampaignId }).done(
        function () {
          $("#wpec-send-status").text("Job cancelled.");
          $("#wpec-cancel-campaign").prop("disabled", true);
        }
      );
    });
  });
})(jQuery);
