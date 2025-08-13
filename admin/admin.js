(function ($) {
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

  // ─── Helper: enable/disable bulk buttons based on selections ──────────────
  function anyChecked($scope) {
    return $scope.find('input[name="ids[]"]:checked').length > 0;
  }
  function setBulkState() {
    var $dup = $("#wpec-dup-form");
    var $lst = $("#wpec-list-form");
    $("#wpec-dup-bulk-delete").prop("disabled", !anyChecked($dup));
    $("#wpec-list-bulk-delete").prop("disabled", !anyChecked($lst));
  }
  $(document).on(
    "change click input",
    '.wp-list-table input[type="checkbox"]',
    setBulkState
  );
  $(document).ready(setBulkState);

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

  // ─── Filter existing list select ──────────────────────────────────────────
  $("#wpec-existing-search").on("input", function () {
    var q = $(this).val().toLowerCase();
    $("#wpec-existing-list option").each(function () {
      var t = $(this).text().toLowerCase();
      $(this).toggle(t.indexOf(q) !== -1 || $(this).val() === "");
    });
  });
  // toggle target sections
  $(document).on("change", 'input[name="list_mode"]', function () {
    var mode = $('input[name="list_mode"]:checked').val();
    $("#wpec-list-target-new").toggle(mode === "new");
    $("#wpec-list-target-existing").toggle(mode === "existing");
    if (mode === "new") {
      $('input[name="list_name"]').attr("required", true);
      $("#wpec-existing-list").removeAttr("required");
    } else {
      $('input[name="list_name"]').removeAttr("required");
      $("#wpec-existing-list").attr("required", true);
    }
  });

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
          $btn.closest("tr").fadeOut(150, function () {
            $(this).remove();
            setBulkState();
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

  // ─── Bulk delete (duplicates) with progress ───────────────────────────────
  $("#wpec-dup-bulk-delete").on("click", function (e) {
    e.preventDefault();
    var $btn = $(this),
      $loader = $("#wpec-dup-bulk-loader");
    var $wrap = $("#wpec-dup-bulk-progress"),
      $bar = $("#wpec-dup-progress-bar"),
      $text = $("#wpec-dup-progress-text");
    var $form = $("#wpec-dup-form"),
      $checks = $form.find('input[name="ids[]"]:checked');
    if (!$checks.length) return;
    if (!confirm("Delete selected contacts from their current lists?")) return;

    var tasks = [];
    $checks.each(function () {
      var parts = String($(this).val()).split(":");
      tasks.push({
        listId: parseInt(parts[0], 10),
        contactId: parseInt(parts[1], 10),
        $row: $(this).closest("tr"),
      });
    });
    var total = tasks.length,
      done = 0;
    $btn.prop("disabled", true);
    $loader.show();
    $wrap.show();
    $bar.css("width", "0%");
    $text.text("Deleting 0 of " + total + "...");
    function next() {
      if (!tasks.length) {
        $loader.hide();
        $btn.prop("disabled", false);
        $text.text("Deleted " + done + " of " + total + ".");
        setBulkState();
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
            done++;
            if (t.$row)
              t.$row.fadeOut(80, function () {
                $(this).remove();
              });
          }
        })
        .always(function () {
          var pct = Math.round((done / total) * 100);
          $bar.css("width", pct + "%");
          $text.text("Deleting " + done + " of " + total + "...");
          setTimeout(next, 60);
        });
    }
    next();
  });

  // ─── Bulk delete (per-list) with progress ─────────────────────────────────
  $("#wpec-list-bulk-delete").on("click", function (e) {
    e.preventDefault();
    var $btn = $(this),
      $loader = $("#wpec-list-bulk-loader");
    var $wrap = $("#wpec-list-bulk-progress"),
      $bar = $("#wpec-list-progress-bar"),
      $text = $("#wpec-list-progress-text");
    var $form = $("#wpec-list-form"),
      $checks = $form.find('input[name="ids[]"]:checked');
    if (!$checks.length) return;
    if (!confirm("Delete selected contacts from this list?")) return;

    var tasks = [];
    $checks.each(function () {
      var parts = String($(this).val()).split(":");
      tasks.push({
        listId: parseInt(parts[0], 10),
        contactId: parseInt(parts[1], 10),
        $row: $(this).closest("tr"),
      });
    });
    var total = tasks.length,
      done = 0;
    $btn.prop("disabled", true);
    $loader.show();
    $wrap.show();
    $bar.css("width", "0%");
    $text.text("Deleting 0 of " + total + "...");
    function next() {
      if (!tasks.length) {
        $loader.hide();
        $btn.prop("disabled", false);
        $text.text("Deleted " + done + " of " + total + ".");
        setBulkState();
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
            done++;
            if (t.$row)
              t.$row.fadeOut(80, function () {
                $(this).remove();
              });
          }
        })
        .always(function () {
          var pct = Math.round((done / total) * 100);
          $bar.css("width", pct + "%");
          $text.text("Deleting " + done + " of " + total + "...");
          setTimeout(next, 60);
        });
    }
    next();
  });

  // ─── Modals: open/close ───────────────────────────────────────────────────
  function openModal($el) {
    $("#wpec-modal-overlay").show();
    $el.show();
  }
  function closeModals() {
    $("#wpec-modal, #wpec-modal-list, #wpec-modal-overlay").hide();
  }
  $(document).on(
    "click",
    ".wpec-modal-close, #wpec-modal-overlay",
    closeModals
  );
  $("#wpec-open-add-contact").on("click", function (e) {
    e.preventDefault();
    openModal($("#wpec-modal"));
  });
  $("#wpec-open-create-list").on("click", function (e) {
    e.preventDefault();
    openModal($("#wpec-modal-list"));
  });

  // ─── Create list (AJAX) ───────────────────────────────────────────────────
  $("#wpec-create-list-form").on("submit", function (e) {
    e.preventDefault();
    var $form = $(this),
      $loader = $("#wpec-create-list-loader");
    $loader.show();
    $.post(WPEC.ajaxUrl, $form.serialize() + "&action=wpec_list_create")
      .done(function (resp) {
        if (resp && resp.success) {
          // Add new option to selects
          var id = resp.data.list_id,
            name = resp.data.name + " (#" + id + ")";
          $("#wpec-existing-list, #wpec-add-contact-list").each(function () {
            $(this).append($("<option>", { value: id, text: name }));
          });
          alert("List created");
          closeModals();
        } else {
          alert((resp && resp.data && resp.data.message) || "Create failed");
        }
      })
      .fail(function () {
        alert("Request failed");
      })
      .always(function () {
        $loader.hide();
      });
  });

  // ─── Add contact (AJAX) ───────────────────────────────────────────────────
  $("#wpec-add-contact-form").on("submit", function (e) {
    e.preventDefault();
    var $form = $(this),
      $loader = $("#wpec-add-contact-loader");
    $loader.show();
    $.post(WPEC.ajaxUrl, $form.serialize() + "&action=wpec_contact_create")
      .done(function (resp) {
        if (resp && resp.success) {
          alert(
            "Contact saved" + (resp.data.mapped ? " and added to list" : "")
          );
          closeModals();
        } else {
          alert((resp && resp.data && resp.data.message) || "Save failed");
        }
      })
      .fail(function () {
        alert("Request failed");
      })
      .always(function () {
        $loader.hide();
      });
  });
})(jQuery);
