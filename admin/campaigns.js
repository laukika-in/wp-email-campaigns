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
  // Pause
  $(document).on("click", ".wpec-pause", function (e) {
    e.preventDefault();
    var $btn = $(this),
      id = $btn.data("campaignId");
    $.post(WPECCAMPAIGN.ajaxUrl, {
      action: "wpec_campaign_pause",
      nonce: WPECCAMPAIGN.nonce,
      campaign_id: id,
    }).done(function (res) {
      if (res && res.success) {
        // swap to Resume button
        $btn.replaceWith(
          '<button class="button wpec-resume" data-campaign-id="' +
            id +
            '">Resume</button>'
        );
      } else {
        alert(res?.data?.message || "Failed to pause.");
      }
    });
  });

  // Resume
  $(document).on("click", ".wpec-resume", function (e) {
    e.preventDefault();
    var $btn = $(this),
      id = $btn.data("campaignId");
    $.post(WPECCAMPAIGN.ajaxUrl, {
      action: "wpec_campaign_resume",
      nonce: WPECCAMPAIGN.nonce,
      campaign_id: id,
    }).done(function (res) {
      if (res && res.success) {
        // swap to Pause button
        $btn.replaceWith(
          '<button class="button wpec-pause" data-campaign-id="' +
            id +
            '">Pause</button>'
        );
      } else {
        alert(res?.data?.message || "Failed to resume.");
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
          window.location =
            WPECCAMPAIGN.adminBase +
            "?post_type=email_campaign&page=wpec-queue";

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

  function applyPrefill(p) {
    if (!p) return;
    $("#wpec-subject").val(p.subject || "");
    $("#wpec-from-name").val(p.from_name || "");
    $("#wpec-from-email").val(p.from_email || "");

    // Set TinyMCE/textarea
    try {
      var ed = window.tinyMCE && tinyMCE.get("wpec_camp_html");
      if (ed && !ed.isHidden()) ed.setContent(p.body_html || "");
      else $("#wpec_camp_html").val(p.body_html || "");
    } catch (e) {}

    // Lists (after Select2 is ready)
    if (Array.isArray(p.list_ids)) {
      $("#wpec-list-ids").val(p.list_ids.map(String)).trigger("change");
    }

    // If not a draft, hide the Queue button to avoid re-sending
    if ((p.status || "") !== "draft") {
      $("#wpec-queue-campaign").prop("disabled", true).hide();
    }
  }

  $(function () {
    var page = new URL(location.href).searchParams.get("page");
    if (page === "wpec-send") {
      // after your ensureSelect2(...) and initSendUI(...), call:
      applyPrefill(window.WPECCAMPAIGN && window.WPECCAMPAIGN.prefill);
    }
  });

  function setRowState($tr, state) {
    $tr.attr("data-status", state);
    $tr.find(".wpec-status-pill").text(state);
    var id = $tr.data("id");
    var $act = $tr.find("td").last();
    $act.empty();

    if (state === "paused") {
      $act.append(
        '<button class="button wpec-q-resume" data-id="' +
          id +
          '">Resume</button> '
      );
      $act.append(
        '<button class="button wpec-q-cancel" data-id="' +
          id +
          '">Cancel</button>'
      );
    } else if (state === "queued" || state === "sending") {
      $act.append(
        '<button class="button wpec-q-pause" data-id="' +
          id +
          '">Pause</button> '
      );
      $act.append(
        '<button class="button wpec-q-cancel" data-id="' +
          id +
          '">Cancel</button>'
      );
    } else if (state === "cancelled") {
      $act.text("Cancelled");
    } else if (state === "sent") {
      $act.text("All sent");
    } else if (state === "failed") {
      $act.text("Completed with errors");
    } else {
      $act.text("—");
    }
  }

  // Pause
  $(document).on("click", ".wpec-q-pause", function (e) {
    e.preventDefault();
    var id = $(this).data("id"),
      $tr = $(this).closest("tr");
    $.post(
      WPECCAMPAIGN.ajaxUrl,
      {
        action: "wpec_campaign_pause",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      },
      function (res) {
        if (res && res.success) setRowState($tr, "paused");
        else alert((res && res.data && res.data.message) || "Pause failed.");
      },
      "json"
    );
  });

  // Resume
  $(document).on("click", ".wpec-q-resume", function (e) {
    e.preventDefault();
    var id = $(this).data("id"),
      $tr = $(this).closest("tr");
    $.post(
      WPECCAMPAIGN.ajaxUrl,
      {
        action: "wpec_campaign_resume",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      },
      function (res) {
        if (res && res.success) setRowState($tr, "queued");
        else alert((res && res.data && res.data.message) || "Resume failed.");
      },
      "json"
    );
  });

  // Cancel
  $(document).on("click", ".wpec-q-cancel", function (e) {
    e.preventDefault();
    if (!confirm("Cancel this campaign?")) return;
    var id = $(this).data("id"),
      $tr = $(this).closest("tr");
    $.post(
      WPECCAMPAIGN.ajaxUrl,
      {
        action: "wpec_campaign_cancel",
        nonce: WPECCAMPAIGN.nonce,
        campaign_id: id,
      },
      function (res) {
        if (res && res.success) setRowState($tr, "cancelled");
        else alert((res && res.data && res.data.message) || "Cancel failed.");
      },
      "json"
    );
  });
})(jQuery);
