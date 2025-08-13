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

  // ─── Confirm on publish (sending later) ───────────────────────────────────
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

  // ─── Upload/import progress (contacts) ────────────────────────────────────
  function setProgress(pct, text) {
    $("#wpec-progress-wrap").show();
    $("#wpec-progress-bar").css("width", pct + "%");
    if (text) $("#wpec-progress-text").text(text);
  }
  function showResultPanel(stats, listId) {
    var $panel = $("#wpec-import-result");
    var dupesUrlAll = new URL(location.href);
    dupesUrlAll.searchParams.set("view", "dupes");
    dupesUrlAll.searchParams.delete("list_id");
    var dupesUrlList = new URL(location.href);
    dupesUrlList.searchParams.set("view", "dupes_list");
    dupesUrlList.searchParams.set("list_id", String(listId));
    var reloadUrl = location.href
      .replace(/([&?])wpec_start_import=\d+&?/, "$1")
      .replace(/[&?]$/, "");

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
          if (WPEC) WPEC.startImport = 0;
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
  $(function () {
    if (WPEC && WPEC.startImport && parseInt(WPEC.startImport, 10) > 0) {
      $(".wpec-loader").show();
      setProgress(1, "Continuing import...");
      processList(parseInt(WPEC.startImport, 10));
    }
  });

  // ─── Duplicates: enable/disable bulk button on selection ──────────────────
  function updateBulkButtonState() {
    var anyChecked = $('.wp-list-table input[name="ids[]"]:checked').length > 0;
    $("#wpec-dup-bulk-delete").prop("disabled", !anyChecked);
  }
  $(document).on(
    "change",
    '.wp-list-table input[name="ids[]"]',
    updateBulkButtonState
  );
  $(document).on(
    "click",
    '.wp-list-table th.check-column input[type="checkbox"]',
    function () {
      // header checkbox toggle
      var checked = $(this).is(":checked");
      $('.wp-list-table input[name="ids[]"]').prop("checked", checked);
      updateBulkButtonState();
    }
  );

  // ─── Duplicates: individual AJAX delete ───────────────────────────────────
  $(document).on("click", ".wpec-del-dup", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var listId = parseInt($btn.data("listId"), 10);
    var contactId = parseInt($btn.data("contactId"), 10);
    if (!listId || !contactId) return;

    if (!confirm("Remove this contact from the current list?")) return;

    $btn.prop("disabled", true);
    $.post(WPEC.ajaxUrl, {
      action: "wpec_delete_list_mapping",
      nonce: WPEC.nonce,
      list_id: listId,
      contact_id: contactId,
    })
      .done(function (resp) {
        if (resp && resp.success) {
          // Remove row
          $btn.closest("tr").fadeOut(150, function () {
            $(this).remove();
            updateBulkButtonState();
          });
        } else {
          alert((resp && resp.data && resp.data.message) || "Delete failed.");
          $btn.prop("disabled", false);
        }
      })
      .fail(function () {
        alert("Delete request failed.");
        $btn.prop("disabled", false);
      });
  });

  // ─── Duplicates: bulk AJAX delete with progress bar ───────────────────────
  $("#wpec-dup-bulk-delete").on("click", function (e) {
    e.preventDefault();
    var $btn = $(this);
    var $loader = $("#wpec-dup-bulk-loader");
    var $progressWrap = $("#wpec-dup-bulk-progress");
    var $bar = $("#wpec-dup-progress-bar");
    var $text = $("#wpec-dup-progress-text");

    var $checks = $('.wp-list-table input[name="ids[]"]:checked');
    if (!$checks.length) return;

    if (!confirm("Delete selected contacts from their current lists?")) return;

    var tasks = [];
    $checks.each(function () {
      var val = $(this).val(); // format: listId:contactId
      var parts = String(val).split(":");
      var listId = parseInt(parts[0], 10);
      var contactId = parseInt(parts[1], 10);
      if (listId && contactId) {
        tasks.push({
          listId: listId,
          contactId: contactId,
          $row: $(this).closest("tr"),
        });
      }
    });

    var total = tasks.length,
      done = 0;
    if (!total) return;

    $btn.prop("disabled", true);
    $loader.show();
    $progressWrap.show();
    $bar.css("width", "0%");
    $text.text("Deleting 0 of " + total + "...");

    function next() {
      if (tasks.length === 0) {
        $loader.hide();
        $btn.prop("disabled", false);
        $text.text("Deleted " + done + " of " + total + ".");
        updateBulkButtonState();
        return;
      }
      var t = tasks.shift();
      $.post(WPEC.ajaxUrl, {
        action: "wpec_delete_list_mapping",
        nonce: WPEC.nonce,
        list_id: t.listId,
        contact_id: t.contactId,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            done += 1;
            if (t.$row && t.$row.length)
              t.$row.fadeOut(100, function () {
                $(this).remove();
              });
          }
        })
        .always(function () {
          var pct = Math.round((done / total) * 100);
          $bar.css("width", pct + "%");
          $text.text("Deleting " + done + " of " + total + "...");
          setTimeout(next, 80);
        });
    }
    next();
  });
})(jQuery);
