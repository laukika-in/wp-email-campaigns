(function ($) {
  // ─── Campaign Pause/Resume/Cancel ─────────────────────────────────────────
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

  // ─── Confirm on publish (sending wired later) ─────────────────────────────
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
            " | Invalid: " +
            (s.invalid || 0) +
            " | Duplicates: " +
            (s.duplicates || 0) +
            " | Total seen: " +
            (s.total || 0)
        );

        if (resp.data.done) {
          $(".wpec-loader").hide();
          location.href = location.href.replace(
            /([&?])wpec_start_import=\d+&?/,
            "$1"
          ); // clean URL
          location.reload();
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

  // Intercept form submit for AJAX path (when JS is loaded)
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

  // If PHP redirected with ?wpec_start_import=ID (non-JS fallback path), auto-start import
  $(function () {
    if (WPEC && WPEC.startImport && parseInt(WPEC.startImport, 10) > 0) {
      $(".wpec-loader").show();
      setProgress(1, "Continuing import...");
      processList(parseInt(WPEC.startImport, 10));
    }
  });
})(jQuery);
