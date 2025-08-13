(function ($) {
  // ─── Campaign Pause/Resume/Cancel (unchanged) ─────────────────────────────
  $(document).on(
    "click",
    ".wpec-pause, .wpec-resume, .wpec-cancel",
    function (e) {
      e.preventDefault();
      var $btn = $(this);
      var id = $btn.data("id");
      var action = $btn.hasClass("wpec-pause")
        ? "wpec_pause"
        : $btn.hasClass("wpec-resume")
        ? "wpec_resume"
        : "wpec_cancel";
      $btn.prop("disabled", true);
      $.post(
        ajaxurl,
        { action: action, id: id, nonce: WPEC.nonce },
        function (resp) {
          $btn.prop("disabled", false);
          if (resp && resp.success) {
            alert(resp.data.message || "OK");
            location.reload();
          } else {
            alert("Failed");
          }
        }
      );
    }
  );

  // ─── Confirm on publish (sending comes later) ─────────────────────────────
  $(function () {
    var $form = $("#post");
    if ($("body").hasClass("post-type-email_campaign")) {
      $form.on("submit", function () {
        if ($("#publish").length) {
          var ok = confirm(
            "Publish campaign? (Sending is configured in next phase.)"
          );
          if (!ok) return false;
        }
      });
    }
  });

  // ─── Lists: Upload & Import with progressive chunks ───────────────────────
  function setProgress(pct, text) {
    $("#wpec-progress-wrap").show();
    $("#wpec-progress-bar").css("width", pct + "%");
    if (text) $("#wpec-progress-text").text(text);
  }

  function showResultPanel(stats, listId) {
    var $panel = $("#wpec-import-result");
    var dupesUrlAll = new URL(location.href);
    dupesUrlAll.searchParams.set("view", "dupes");
    dupesUrlAll.searchParams.set("list_id", ""); // remove
    var dupesUrlList = new URL(location.href);
    dupesUrlList.searchParams.set("view", "dupes_list");
    dupesUrlList.searchParams.set("list_id", String(listId));

    var html = "";
    html += '<h3 style="margin-top:0;">Import Summary</h3>';
    html += '<ul class="wpec-stats">';
    html +=
      "<li><strong>Total uploaded:</strong> " + (stats.imported || 0) + "</li>";
    html +=
      "<li><strong>Duplicates (global):</strong> " +
      (stats.duplicates || 0) +
      "</li>";
    html +=
      "<li><strong>Not uploaded:</strong> " + (stats.invalid || 0) + "</li>";
    html += "<li><strong>Total seen:</strong> " + (stats.total || 0) + "</li>";
    html += "</ul>";
    html += "<p>";
    html +=
      '<a class="button" href="' +
      dupesUrlList.toString() +
      '">View duplicates for this list</a> ';
    html +=
      '<a class="button" href="' +
      dupesUrlAll.toString() +
      '">View duplicates (all lists)</a> ';
    var reloadUrl = location.href
      .replace(/([&?])wpec_start_import=\d+&?/, "$1")
      .replace(/[&?]$/, "");
    html +=
      '<a class="button button-primary" href="' +
      reloadUrl +
      '">Reload Lists</a>';
    html += "</p>";

    $panel.html(html).show();
  }

  function processList(listId) {
    $.post(WPEC.ajaxUrl, {
      action: "wpec_list_process",
      list_id: listId,
      nonce: WPEC.nonce,
    })
      .done(function (resp) {
        if (!resp || !resp.success) {
          $(".wpec-loader").hide();
          alert((resp && resp.data && resp.data.message) || "Import error");
          return;
        }
        var s = resp.data.stats || {};
        var pct = resp.data.progress || 0;
        setProgress(
          pct,
          "Imported: " +
            (s.imported || 0) +
            " | Duplicates: " +
            (s.duplicates || 0) +
            " | Not uploaded: " +
            (s.invalid || 0) +
            " | Total seen: " +
            (s.total || 0)
        );

        if (resp.data.done) {
          $(".wpec-loader").hide();
          if (WPEC) WPEC.startImport = 0; // stop fallback
          showResultPanel(s, resp.data.list_id);
        } else {
          setTimeout(function () {
            processList(listId);
          }, 200);
        }
      })
      .fail(function () {
        $(".wpec-loader").hide();
        alert("Import request failed.");
      });
  }

  // Intercept form submit for AJAX path
  $(document).on("submit", "#wpec-list-upload-form", function (e) {
    e.preventDefault();
    var $btn = $("#wpec-upload-btn");
    var form = this;
    var fd = new FormData(form);

    $btn.prop("disabled", true);
    $(".wpec-loader").show();
    setProgress(0, "Preparing upload...");

    $.ajax({
      url: WPEC.ajaxUrl + "?action=wpec_list_upload",
      type: "POST",
      data: fd,
      processData: false,
      contentType: false,
    })
      .done(function (resp) {
        if (!resp || !resp.success) {
          $(".wpec-loader").hide();
          $btn.prop("disabled", false);
          alert((resp && resp.data && resp.data.message) || "Upload failed");
          return;
        }
        var listId = resp.data.list_id;
        setProgress(1, "Starting import...");
        processList(listId);
      })
      .fail(function () {
        $(".wpec-loader").hide();
        $btn.prop("disabled", false);
        alert("Upload failed.");
      });
  });

  // Non-JS fallback redirect param -> auto-continue (no auto-reload at finish)
  $(function () {
    if (WPEC && WPEC.startImport && parseInt(WPEC.startImport, 10) > 0) {
      $(".wpec-loader").show();
      setProgress(1, "Continuing import...");
      processList(parseInt(WPEC.startImport, 10));
    }
  });
})(jQuery);
