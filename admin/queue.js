(function ($) {
  function post(action, cid) {
    return $.post(WPECQUEUE.ajaxUrl, {
      action,
      nonce: WPECQUEUE.nonce,
      campaign_id: cid,
    });
  }
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
