(function ($) {
  /* ========= helpers ========= */

  function onPage(slug) {
    const sp = new URLSearchParams(location.search);
    return sp.get("page") === slug;
  }

  function adminEditUrl(params) {
    // Build  
    var base =
      (window.WPECCAMPAIGN && WPECCAMPAIGN.adminBase) ||
      (window.ajaxurl
        ? window.ajaxurl.replace("admin-ajax.php", "edit.php")
        : "/wp-admin/edit.php");
    const usp = new URLSearchParams({ post_type: "email_campaign", ...params });
    return base + "?" + usp.toString();
  }

  function getEditorHtml() {
    try {
      if (
        window.tinyMCE &&
        tinyMCE.get("wpec_camp_html") &&
        !tinyMCE.get("wpec_camp_html").isHidden()
      ) {
        return tinyMCE.get("wpec_camp_html").getContent() || "";
      }
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
    // CSS
    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href =
      CFG.select2LocalCss ||
      CFG.select2CdnCss ||
      "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css";
    css.onerror = function () {
      css.href =
        CFG.select2CdnCss ||
        "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css";
    };
    document.head.appendChild(css);
    // JS
    var s = document.createElement("script");
    s.src =
      CFG.select2LocalJs ||
      CFG.select2CdnJs ||
      "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js";
    s.onload = function () {
      cb && cb();
    };
    s.onerror = function () {
      this.src =
        CFG.select2CdnJs ||
        "https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js";
      this.onerror = null;
    };
    document.head.appendChild(s);
  }

  /* ========= SEND page ========= */

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

  function bindSendHandlers() {
    // Hide Queue button if campaign isn't a draft
    var initialStatus = (
      $("#wpec-campaign-status").val() || "draft"
    ).toLowerCase();
    if (initialStatus && initialStatus !== "draft") {
      $("#wpec-queue-campaign").hide();
    } else {
      $("#wpec-queue-campaign").show();
    }

    // SEND TEST
    $(document).on("click", "#wpec-send-test", function (e) {
      e.preventDefault();

      var to = ($("#wpec-test-to").val() || "").trim();
      var subject = ($("#wpec-subject").val() || "").trim();
      var fromName = ($("#wpec-from-name").val() || "").trim();
      var fromEmail = ($("#wpec-from-email").val() || "").trim();
      var body = getEditorHtml();

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
          body: body, // key must be "body"
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

    // SAVE DRAFT
    $(document).on("click", "#wpec-save-draft", function (e) {
      e.preventDefault();

      var cid = parseInt($("#wpec-campaign-id").val() || "0", 10) || 0;
      var name = ($("#wpec-name").val() || "").trim();
      var subject = ($("#wpec-subject").val() || "").trim();
      var fromName = ($("#wpec-from-name").val() || "").trim();
      var fromEmail = ($("#wpec-from-email").val() || "").trim();
      var body = getEditorHtml();

      if (!subject || !body) {
        alert("Subject and Body are required.");
        return;
      }

      var $btn = $(this).prop("disabled", true);

      $.post(
        WPECCAMPAIGN.ajaxUrl,
        {
          action: "wpec_campaign_queue",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
          name: name,
          subject: subject,
          from_name: fromName,
          from_email: fromEmail,
          body: body,
          save_only: "1", // mark as draft
        },
        function (res) {
          if (res && res.success) {
            // store new campaign_id (in case this was a new draft)
            if (res.data && res.data.campaign_id) {
              $("#wpec-campaign-id").val(res.data.campaign_id);
            }
            $("#wpec-campaign-status").val("draft");
            $("#wpec-queue-campaign").show(); // ensure queue button visible for drafts
            alert("Draft saved.");
          } else {
            alert(
              (res && res.data && res.data.message) || "Failed to save draft."
            );
          }
        },
        "json"
      ).always(function () {
        $btn.prop("disabled", false);
      });
    });

    // QUEUE SEND → redirect to Queue page
    $(document).on("click", "#wpec-queue-campaign", function (e) {
      e.preventDefault();

      var cid = parseInt($("#wpec-campaign-id").val() || "0", 10) || 0;
      var name = ($("#wpec-name").val() || "").trim();
      var subject = ($("#wpec-subject").val() || "").trim();
      var fromName = ($("#wpec-from-name").val() || "").trim();
      var fromEmail = ($("#wpec-from-email").val() || "").trim();
      var body = getEditorHtml();
      var listIds = $("#wpec-list-ids").val() || [];

      if (!subject || !body || listIds.length === 0) {
        alert("Subject, Body, and at least one list are required.");
        return;
      }

      var $btn = $(this).prop("disabled", true);

      $.post(
        WPECCAMPAIGN.ajaxUrl,
        {
          action: "wpec_campaign_queue",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
          name: name,
          subject: subject,
          from_name: fromName,
          from_email: fromEmail,
          body: body,
          list_ids: listIds,
        },
        function (res) {
          if (!res || !res.success) {
            alert((res && res.data && res.data.message) || "Failed to queue.");
            $btn.prop("disabled", false);
            return;
          }
          // Hide the queue button to avoid double-send
          $("#wpec-queue-campaign").hide();

          // Redirect to Queue page
          window.location.href = adminEditUrl({ page: "wpec-queue" });
        },
        "json"
      ).fail(function () {
        alert("Request failed.");
        $btn.prop("disabled", false);
      });
    });
  }

  /* ========= QUEUE page ========= */

  function bindQueueHandlers() {
    // Pause
    $(document).on("click", ".wpec-q-pause", function (e) {
      e.preventDefault();
      var id = parseInt($(this).data("id") || "0", 10) || 0;
      if (!id) return;

      $.post(WPECCAMPAIGN.ajaxUrl, {
        action: "wpec_campaign_pause",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      }).done(function (res) {
        if (res && res.success) {
          var $tr = $('tr[data-id="' + id + '"]');
          $tr.attr("data-status", "paused");
          $tr.find(".wpec-status-pill").text("paused");
          $tr.find(".wpec-q-pause").hide();
          $tr.find(".wpec-q-resume").show();
        } else {
          alert(res?.data?.message || "Pause failed.");
        }
      });
    });

    // Resume
    $(document).on("click", ".wpec-q-resume", function (e) {
      e.preventDefault();
      var id = parseInt($(this).data("id") || "0", 10) || 0;
      if (!id) return;

      $.post(WPECCAMPAIGN.ajaxUrl, {
        action: "wpec_campaign_resume",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      }).done(function (res) {
        if (res && res.success) {
          var $tr = $('tr[data-id="' + id + '"]');
          $tr.attr("data-status", "sending");
          $tr.find(".wpec-status-pill").text("sending");
          $tr.find(".wpec-q-resume").hide();
          $tr.find(".wpec-q-pause").show();
        } else {
          alert(res?.data?.message || "Resume failed.");
        }
      });
    });

    // Cancel
    $(document).on("click", ".wpec-q-cancel", function (e) {
      e.preventDefault();
      var id = parseInt($(this).data("id") || "0", 10) || 0;
      if (!id) return;
      if (!confirm("Cancel this job?")) return;

      $.post(WPECCAMPAIGN.ajaxUrl, {
        action: "wpec_campaign_cancel",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      }).done(function (res) {
        if (res && res.success) {
          var $tr = $('tr[data-id="' + id + '"]');
          $tr.attr("data-status", "cancelled");
          $tr.find(".wpec-status-pill").text("cancelled");
          $tr.find("td:last").html("<em>Cancelled</em>");
        } else {
          alert(res?.data?.message || "Cancel failed.");
        }
      });
    });
  }
  function onCampaignDetailPage() {
    const sp = new URLSearchParams(location.search);
    return (
      sp.get("post_type") === "email_campaign" &&
      sp.get("page") === "wpec-campaigns" &&
      sp.get("view") === "detail"
    );
  }

  function updateActions(state, queued) {
    const $wrap = $("#wpec-campaign-actions");
    if (!$wrap.length) return;
    const finished =
      queued === 0 && ["sent", "failed", "cancelled"].includes(state);

    const $pause = $wrap.find(".wpec-q-pause");
    const $resume = $wrap.find(".wpec-q-resume");
    const $cancel = $wrap.find(".wpec-q-cancel");

    if (finished) {
      $pause.remove();
      $resume.remove();
      $cancel.remove();
      if (!$wrap.find("em").length) {
        $wrap.append(
          $("<em>").text(
            state === "sent"
              ? "All sent"
              : state === "failed"
              ? "Completed with errors"
              : "Cancelled"
          )
        );
      }
      return;
    }
    if (["queued", "sending"].includes(state)) {
      $pause.show();
      $resume.hide();
      $cancel.show();
    } else if (state === "paused") {
      $pause.hide();
      $resume.show();
      $cancel.show();
    } else {
      $pause.hide();
      $resume.hide();
      $cancel.hide();
    }
  }

  function bindDetailButtons(cid) {
    $(document)
      .off("click.wpecDetailPause", ".wpec-q-pause")
      .on("click.wpecDetailPause", ".wpec-q-pause", function (e) {
        e.preventDefault();
        $.post(WPECCAMPAIGN.ajaxUrl, {
          action: "wpec_campaign_pause",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
        });
      });

    $(document)
      .off("click.wpecDetailResume", ".wpec-q-resume")
      .on("click.wpecDetailResume", ".wpec-q-resume", function (e) {
        e.preventDefault();
        $.post(WPECCAMPAIGN.ajaxUrl, {
          action: "wpec_campaign_resume",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
        });
      });

    $(document)
      .off("click.wpecDetailCancel", ".wpec-q-cancel")
      .on("click.wpecDetailCancel", ".wpec-q-cancel", function (e) {
        e.preventDefault();
        if (!confirm("Cancel this campaign?")) return;
        $.post(WPECCAMPAIGN.ajaxUrl, {
          action: "wpec_campaign_cancel",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
        });
      });
  }

  function startDetailPolling(cid) {
    function tick() {
      $.post(
        WPECCAMPAIGN.ajaxUrl,
        {
          action: "wpec_campaign_status",
          nonce: WPECCAMPAIGN.nonce,
          campaign_id: cid,
        },
        null,
        "json"
      ).done(function (res) {
        if (!res || !res.success) return;
        var d = res.data || {};
        $("#wpec-stat-queued").text(d.queued || 0);
        $("#wpec-stat-sent").text(d.sent || 0);
        $("#wpec-stat-failed").text(d.failed || 0);
        updateActions(d.state || "", parseInt(d.queued || 0, 10));
      });
    }
    tick();
    return setInterval(tick, 4000);
  }
  /* ========= boot ========= */

  $(function () {
    // SEND page
    if (onPage("wpec-send")) {
      ensureSelect2(function () {
        initSendSelects();
        bindSendHandlers();
      });
      return;
    }

    // QUEUE page
    if (onPage("wpec-queue")) {
      bindQueueHandlers();
      return;
    }

    // CAMPAIGN DETAIL page
    if (onCampaignDetailPage()) {
      var cid = parseInt($("#wpec-campaign-detail").data("cid"), 10) || 0;
      if (!cid) {
        const sp = new URLSearchParams(location.search);
        cid = parseInt(sp.get("id") || sp.get("campaign_id") || 0, 10);
      }
      if (cid) {
        bindDetailButtons(cid);
        startDetailPolling(cid);
      }
      return;
    }
  });
})(jQuery);
