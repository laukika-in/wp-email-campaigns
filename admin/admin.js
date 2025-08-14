(function ($) {
  // ── Select2 loader (local first; fallback CDN) ────────────────────────────
  function ensureSelect2(cb) {
    if ($.fn.select2) {
      cb && cb();
      return;
    }
    // load CSS
    var css = document.createElement("link");
    css.rel = "stylesheet";
    css.href = WPEC.select2LocalCss || WPEC.select2CdnCss;
    css.onerror = function () {
      css.href = WPEC.select2CdnCss;
    };
    document.head.appendChild(css);
    // load JS
    var s = document.createElement("script");
    s.src = WPEC.select2LocalJs || WPEC.select2CdnJs;
    s.onload = function () {
      cb && cb();
    };
    s.onerror = function () {
      this.src = WPEC.select2CdnJs;
      this.onerror = null;
    };
    document.head.appendChild(s);
  }

  // ── Helpers ───────────────────────────────────────────────────────────────
  function anyChecked($scope) {
    return $scope.find('input[name="ids[]"]:checked').length > 0;
  }
  function setBulkState() {
    var $dup = $("#wpec-dup-form");
    var $lst = $("#wpec-list-form");
    $("#wpec-dup-bulk-delete").prop("disabled", !anyChecked($dup));
    $("#wpec-list-bulk-delete").prop("disabled", !anyChecked($lst));
    // All contacts bulkbar
    var $tbl = $("#wpec-contacts-table");
    var checked = $tbl.find("tbody input.row-cb:checked").length > 0;
    $("#wpec-bulkbar #wpec-bulk-delete").prop("disabled", !checked);
    var listSel = $("#wpec-bulk-move-list").val();
    $("#wpec-bulkbar #wpec-bulk-move").prop("disabled", !(checked && listSel));
  }
  $(document).on(
    "change click",
    '.wp-list-table input[type="checkbox"], #wpec-contacts-table input[type="checkbox"], #wpec-bulk-move-list',
    setBulkState
  );
  $(document).ready(setBulkState);

  // ── Campaign publish confirm ──────────────────────────────────────────────
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

  // ── Upload/import progress (Import page) ──────────────────────────────────
  function setProgress(pct, text) {
    $("#wpec-progress-wrap").show();
    $("#wpec-progress-bar").css("width", pct + "%");
    if (text) $("#wpec-progress-text").text(text);
  }
  function showResultPanel(stats, listId) {
    var $panel = $("#wpec-import-result");
    var dupesUrlAll = new URL(
      location.origin +
        location.pathname +
        "?post_type=email_campaign&page=wpec-duplicates",
      location.origin
    );
    var dupesUrlList = new URL(dupesUrlAll.toString());
    dupesUrlList.searchParams.set("focus_list", String(listId));
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
      '<button class="button button-primary" id="wpec-import-reload">Reload</button>';
    html += "</p>";
    $panel.html(html).show();
  }
  $(document).on("click", "#wpec-import-reload", function () {
    location.reload();
  });

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
    var fd = new FormData(this);
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
        setProgress(1, "Starting import...");
        processList(resp.data.list_id);
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

  // Existing list mode toggle
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

  // ── Duplicates: individual & bulk delete ──────────────────────────────────
  $(document).on("click", ".wpec-del-dup", function (e) {
    e.preventDefault();
    var $btn = $(this),
      listId = parseInt($btn.data("listId"), 10),
      contactId = parseInt($btn.data("contactId"), 10);
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
          $btn.closest("tr").fadeOut(120, function () {
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

  function bulkDelete(buttonSel, formSel, barSel, textSel, wrapSel, loaderSel) {
    $(buttonSel).on("click", function (e) {
      e.preventDefault();
      var $btn = $(this),
        $loader = $(loaderSel),
        $wrap = $(wrapSel),
        $bar = $(barSel),
        $text = $(textSel);
      var $checks = $(formSel + ' input[name="ids[]"]:checked');
      if (!$checks.length) return;
      if (!confirm("Delete selected contacts?")) return;
      var tasks = [];
      $checks.each(function () {
        var p = String($(this).val()).split(":");
        tasks.push({
          listId: parseInt(p[0], 10),
          contactId: parseInt(p[1], 10),
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
      (function next() {
        if (!tasks.length) {
          $loader.hide();
          $btn.prop("disabled", false);
          $text.text("Deleted " + done + " of " + total + ".");
          setTimeout(function () {
            $(wrapSel).hide();
          }, 500);
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
      })();
    });
  }
  bulkDelete(
    "#wpec-dup-bulk-delete",
    "#wpec-dup-form",
    "#wpec-dup-progress-bar",
    "#wpec-dup-progress-text",
    "#wpec-dup-bulk-progress",
    "#wpec-dup-bulk-loader"
  );
  bulkDelete(
    "#wpec-list-bulk-delete",
    "#wpec-list-form",
    "#wpec-list-progress-bar",
    "#wpec-list-progress-text",
    "#wpec-list-bulk-progress",
    "#wpec-list-bulk-loader"
  );

  // ── Modals (Add contact / Create list) ────────────────────────────────────
  $(document).on(
    "click",
    ".wpec-modal-close, #wpec-modal-overlay",
    function () {
      $("#wpec-modal, #wpec-modal-list, #wpec-modal-overlay").hide();
    }
  );
  $(document).on("click", "#wpec-open-add-contact", function (e) {
    e.preventDefault();
    $("#wpec-modal-overlay").show();
    $("#wpec-modal").show();
  });
  $(document).on("click", "#wpec-open-create-list", function (e) {
    e.preventDefault();
    $("#wpec-modal-overlay").show();
    $("#wpec-modal-list").show();
  });
  $(document).on("change", "#wpec-add-contact-newlist-toggle", function () {
    var on = $(this).is(":checked");
    $("#wpec-add-contact-newlist").toggle(on);
    if (on) {
      $("#wpec-add-contact-list").val("");
    }
  });
  $(document).on("submit", "#wpec-create-list-form", function (e) {
    e.preventDefault();
    var $form = $(this),
      $loader = $("#wpec-create-list-loader");
    $loader.show();
    $.post(WPEC.ajaxUrl, $form.serialize() + "&action=wpec_list_create")
      .done(function (resp) {
        if (resp && resp.success) {
          var id = resp.data.list_id,
            name = resp.data.name + " (" + id + ")";
          $(
            "#wpec-existing-list, #wpec-add-contact-list, #wpec-bulk-move-list"
          ).each(function () {
            $(this).append(
              $("<option>", { value: id, text: resp.data.name + " (0)" })
            );
          });
          alert("List created");
          $("#wpec-modal, #wpec-modal-list, #wpec-modal-overlay").hide();
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
  $(document).on("submit", "#wpec-add-contact-form", function (e) {
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
          $("#wpec-modal, #wpec-modal-list, #wpec-modal-overlay").hide();
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

  // ── Lists page: inline "View counts" expansion ────────────────────────────
  $(document).on("click", ".wpec-toggle-counts", function () {
    var $btn = $(this),
      listId = parseInt($btn.data("listId"), 10);
    var $row = $btn.closest("tr");
    if (!$row.length) return;
    var colCount = $row.children("td,th").length;
    var $next = $row.next('.wpec-expand-row[data-list-id="' + listId + '"]');
    if ($next.length) {
      $next.toggle();
      return;
    }
    var html =
      '<tr class="wpec-expand-row" data-list-id="' +
      listId +
      '"><td colspan="' +
      colCount +
      '"><div class="wpec-expand-box"><div class="wpec-expand-loading">Loading counts…</div></div></td></tr>';
    $row.after(html);
    $.post(WPEC.ajaxUrl, {
      action: "wpec_list_metrics",
      nonce: WPEC.nonce,
      list_id: listId,
    })
      .done(function (resp) {
        var $box = $row
          .next('.wpec-expand-row[data-list-id="' + listId + '"]')
          .find(".wpec-expand-box");
        if (resp && resp.success) {
          var m = resp.data || {};
          var t = "";
          t += '<table class="widefat striped wpec-metrics-table"><tbody>';
          t +=
            "<tr><th>Imported (cumulative)</th><td>" +
            (m.imported || 0) +
            "</td></tr>";
          t +=
            "<tr><th>Duplicates (current)</th><td>" +
            (m.duplicates || 0) +
            "</td></tr>";
          t +=
            "<tr><th>Not uploaded (last import)</th><td>" +
            (m.not_uploaded_last || 0) +
            "</td></tr>";
          t +=
            "<tr><th>Deleted (via UI)</th><td>" +
            (m.deleted || 0) +
            "</td></tr>";
          t +=
            "<tr><th>Added manually</th><td>" +
            (m.manual_added || 0) +
            "</td></tr>";
          t +=
            "<tr><th>Total (current in list)</th><td>" +
            (m.total || 0) +
            "</td></tr>";
          t += "</tbody></table>";
          $box.html(t);
        } else {
          $box.html('<div class="error">Failed to load counts.</div>');
        }
      })
      .fail(function () {
        $row
          .next('.wpec-expand-row[data-list-id="' + listId + '"]')
          .find(".wpec-expand-box")
          .html('<div class="error">Request failed.</div>');
      });
  });

  // ── CONTACTS DIRECTORY (AJAX filters + pagination + export + bulk ops) ───
  function onContactsPage() {
    return (
      new URL(location.href).searchParams.get("page") === "wpec-all-contacts"
    );
  }

  function collectCols() {
    var cols = [];
    $(".wpec-col-toggle:checked").each(function () {
      cols.push($(this).val());
    });
    return cols;
  }
  function collectMultiSel(sel) {
    var out = [];
    $(sel)
      .find("option:selected")
      .each(function () {
        out.push($(this).val());
      });
    return out;
  }
  function currentFilters() {
    return {
      search: $("#wpec-f-search").val() || "",
      company_name: collectMultiSel("#wpec-f-company"),
      city: collectMultiSel("#wpec-f-city"),
      state: collectMultiSel("#wpec-f-state"),
      country: collectMultiSel("#wpec-f-country"),
      job_title: collectMultiSel("#wpec-f-job"),
      postal_code: collectMultiSel("#wpec-f-postcode"),
      list_ids: collectMultiSel("#wpec-f-list"),
      emp_min: $("#wpec-f-emp-min").val(),
      emp_max: $("#wpec-f-emp-max").val(),
      rev_min: $("#wpec-f-rev-min").val(),
      rev_max: $("#wpec-f-rev-max").val(),
    };
  }

  function renderPager(pageNo, totalPages, total) {
    var $nums = $("#wpec-page-numbers").empty();
    var maxShown = 7;
    function add(n, text, active, disabled) {
      var $a = $('<a href="#" class="wpec-page-link">')
        .text(text || n)
        .data("page", n);
      if (active) $a.addClass("is-active");
      if (disabled) $a.addClass("is-disabled");
      $nums.append($a);
    }
    var start = Math.max(1, pageNo - 3);
    var end = Math.min(totalPages, start + maxShown - 1);
    if (end - start < maxShown - 1) start = Math.max(1, end - maxShown + 1);
    if (start > 1) {
      add(1);
      if (start > 2) $nums.append('<span class="dots">…</span>');
    }
    for (var i = start; i <= end; i++) {
      add(i, null, i === pageNo);
    }
    if (end < totalPages) {
      if (end < totalPages - 1) $nums.append('<span class="dots">…</span>');
      add(totalPages);
    }
    $("#wpec-page-prev")
      .prop("disabled", pageNo <= 1)
      .data("page", pageNo - 1);
    $("#wpec-page-next")
      .prop("disabled", pageNo >= totalPages)
      .data("page", pageNo + 1);
    $("#wpec-page-info").text(" — " + total + " contacts");
  }

  function contactsQuery(page) {
    var per = parseInt($("#wpec-page-size").val(), 10) || 50;
    var filters = currentFilters();
    var data = Object.assign(
      {
        action: "wpec_contacts_query",
        nonce: WPEC.nonce,
        page: page || 1,
        per_page: per,
        cols: collectCols(),
      },
      filters
    );
    $("#wpec-contacts-table tbody").html(
      '<tr><td colspan="999">Loading…</td></tr>'
    );
    return $.post(WPEC.ajaxUrl, data).done(function (resp) {
      if (!resp || !resp.success) {
        $("#wpec-contacts-table tbody").html(
          '<tr><td colspan="999">Failed to load.</td></tr>'
        );
        return;
      }
      var rows = resp.data.rows || [];
      var cols = collectCols();

      // Build thead
      var $thead = $("#wpec-contacts-table thead tr");
      var head =
        '<th style="width:28px"><input type="checkbox" id="wpec-master-cb"></th><th>ID</th><th>Full name</th><th>Email</th><th>List(s)</th>';
      cols.forEach(function (c) {
        head += "<th>" + headerLabel(c) + "</th>";
      });
      $thead.html(head);

      // Build rows
      if (!rows.length) {
        $("#wpec-contacts-table tbody").html(
          '<tr><td colspan="999">No contacts found.</td></tr>'
        );
      } else {
        var html = "";
        rows.forEach(function (r) {
          var detailUrl = new URL(location.origin + location.pathname);
          detailUrl.searchParams.set("post_type", "email_campaign");
          detailUrl.searchParams.set("page", "wpec-contacts");
          detailUrl.searchParams.set("view", "contact");
          detailUrl.searchParams.set("contact_id", String(r.id));
          html += "<tr>";
          html +=
            '<td><input type="checkbox" class="row-cb" data-id="' +
            r.id +
            '"></td>';
          html += "<td>" + (r.id || "") + "</td>";
          html +=
            '<td><a href="' +
            detailUrl.toString() +
            '">' +
            escapeHtml(r.full_name || "") +
            "</a></td>";
          html += "<td>" + escapeHtml(r.email || "") + "</td>";
          html += "<td>" + escapeHtml(r.lists || "") + "</td>";
          cols.forEach(function (c) {
            html +=
              "<td>" + escapeHtml(r[c] == null ? "" : String(r[c])) + "</td>";
          });
          html += "</tr>";
        });
        $("#wpec-contacts-table tbody").html(html);
      }

      // Pager
      renderPager(
        resp.data.page || 1,
        resp.data.total_pages || 1,
        resp.data.total || 0
      );
      setBulkState();
    });
  }

  function headerLabel(key) {
    switch (key) {
      case "company_name":
        return "Company name";
      case "company_employees":
        return "Employees";
      case "company_annual_revenue":
        return "Annual revenue";
      case "contact_number":
        return "Contact number";
      case "job_title":
        return "Job title";
      case "industry":
        return "Industry";
      case "country":
        return "Country";
      case "state":
        return "State";
      case "city":
        return "City";
      case "postal_code":
        return "Postal code";
      case "status":
        return "Status";
      case "created_at":
        return "Created";
      default:
        return key;
    }
  }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (m) {
      return {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      }[m];
    });
  }

  // Pager clicks
  $(document).on("click", "#wpec-page-prev, #wpec-page-next", function () {
    var p = $(this).data("page");
    if (p) contactsQuery(p);
  });
  $(document).on("click", ".wpec-page-link", function (e) {
    e.preventDefault();
    var p = $(this).data("page");
    if (!$(this).hasClass("is-disabled")) contactsQuery(p);
  });
  $(document).on("change", "#wpec-page-size", function () {
    contactsQuery(1);
  });

  // Filters
  $(document).on("click", "#wpec-f-apply", function (e) {
    e.preventDefault();
    contactsQuery(1);
  });
  $(document).on("click", "#wpec-f-reset", function (e) {
    e.preventDefault();
    $("#wpec-f-search").val("");
    $(".wpec-s2").val(null).trigger("change");
    $("#wpec-f-emp-min, #wpec-f-emp-max, #wpec-f-rev-min, #wpec-f-rev-max").val(
      ""
    );
    $(".wpec-col-toggle").prop("checked", false);
    contactsQuery(1);
  });
  $(document).on("change", ".wpec-col-toggle", function () {
    contactsQuery(1);
  });

  // Master checkbox
  $(document).on("change", "#wpec-master-cb", function () {
    var on = $(this).is(":checked");
    $("#wpec-contacts-table tbody input.row-cb").prop("checked", on);
    setBulkState();
  });
  $(document).on(
    "change",
    "#wpec-contacts-table tbody input.row-cb",
    setBulkState
  );

  // Bulk delete (All Contacts)
  $("#wpec-bulk-delete").on("click", function (e) {
    e.preventDefault();
    var ids = [];
    $("#wpec-contacts-table tbody input.row-cb:checked").each(function () {
      ids.push(parseInt($(this).data("id"), 10));
    });
    if (!ids.length) return;
    if (!confirm("Delete selected contacts? This removes them from all lists."))
      return;
    $("#wpec-bulk-loader").show();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_contacts_bulk_delete",
      nonce: WPEC.nonce,
      contact_ids: ids,
    })
      .done(function (resp) {
        if (resp && resp.success) {
          // Remove rows
          $("#wpec-contacts-table tbody input.row-cb:checked")
            .closest("tr")
            .remove();
          setBulkState();
        } else {
          alert((resp && resp.data && resp.data.message) || "Delete failed.");
        }
      })
      .always(function () {
        $("#wpec-bulk-loader").hide();
      });
  });

  // Bulk move to list
  $("#wpec-bulk-move").on("click", function (e) {
    e.preventDefault();
    var ids = [];
    $("#wpec-contacts-table tbody input.row-cb:checked").each(function () {
      ids.push(parseInt($(this).data("id"), 10));
    });
    var listId = parseInt($("#wpec-bulk-move-list").val(), 10) || 0;
    if (!ids.length || !listId) return;
    $("#wpec-bulk-loader").show();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_contacts_bulk_move",
      nonce: WPEC.nonce,
      contact_ids: ids,
      list_id: listId,
    })
      .done(function (resp) {
        if (resp && resp.success) {
          alert("Added " + (resp.data.added || 0) + " to the selected list.");
        } else {
          alert((resp && resp.data && resp.data.message) || "Move failed.");
        }
      })
      .always(function () {
        $("#wpec-bulk-loader").hide();
      });
  });

  // Export (filtered) via AJAX -> CSV stream
  $("#wpec-export-contacts").on("click", function (e) {
    e.preventDefault();
    var f = currentFilters();
    var form = $(
      '<form method="POST" action="' + WPEC.ajaxUrl + '" target="_blank">'
    );
    form
      .append(
        '<input type="hidden" name="action" value="wpec_contacts_export">'
      )
      .append('<input type="hidden" name="nonce" value="' + WPEC.nonce + '">');
    function appendArr(key, arr) {
      (arr || []).forEach(function (v) {
        form.append(
          $("<input>", { type: "hidden", name: key + "[]", value: v })
        );
      });
    }
    if (f.search)
      form.append(
        $("<input>", { type: "hidden", name: "search", value: f.search })
      );
    appendArr("company_name", f.company_name);
    appendArr("city", f.city);
    appendArr("state", f.state);
    appendArr("country", f.country);
    appendArr("job_title", f.job_title);
    appendArr("postal_code", f.postal_code);
    appendArr("list_ids", f.list_ids);
    if (f.emp_min)
      form.append(
        $("<input>", { type: "hidden", name: "emp_min", value: f.emp_min })
      );
    if (f.emp_max)
      form.append(
        $("<input>", { type: "hidden", name: "emp_max", value: f.emp_max })
      );
    if (f.rev_min)
      form.append(
        $("<input>", { type: "hidden", name: "rev_min", value: f.rev_min })
      );
    if (f.rev_max)
      form.append(
        $("<input>", { type: "hidden", name: "rev_max", value: f.rev_max })
      );
    $(document.body).append(form);
    form[0].submit();
    setTimeout(function () {
      form.remove();
    }, 1000);
  });

  // Init on Contacts page: Select2 + initial load
  function initSelect2() {
    $(".wpec-s2").each(function () {
      $(this).select2({
        width: "resolve",
        allowClear: true,
        placeholder: $(this).data("placeholder") || "",
      });
    });
    $("#wpec-bulk-move-list").select2({
      width: "resolve",
      placeholder: "Move to list…",
    });
  }

  $(function () {
    if (onContactsPage()) {
      ensureSelect2(function () {
        initSelect2();
        contactsQuery(1);
      });
    } else {
      // Import page Select2 for existing list + add-contact list
      if (
        $("#wpec-existing-list").length ||
        $("#wpec-add-contact-list").length
      ) {
        ensureSelect2(function () {
          $("#wpec-existing-list").select2({
            width: "resolve",
            placeholder: "Select a list…",
          });
          $("#wpec-add-contact-list").select2({
            width: "resolve",
            placeholder: "— None —",
          });
        });
      }
    }
  });

  // ===== Special Lists (Do Not Send / Bounced) =====
(function($){
  function buildRow(r){
    var actions = '';
    if (r.id) {
      var viewUrl = window.ajaxurl
        ? (new URLSearchParams({ post_type:'email_campaign', page:'wpec-contacts', view:'contact', contact_id:String(r.id) })).toString()
        : '';
      var href = (window.WPEC && WPEC.adminBase) ? (WPEC.adminBase + '?'+viewUrl) : (window.location.origin + '/wp-admin/edit.php?'+viewUrl);
      actions = '<a class="button button-small" href="'+ href +'">View detail</a>';
    }
    return '<tr>'+
      '<td><input type="checkbox" class="wpec-row-cb" data-id="'+ (r.id||'') +'"></td>'+
      '<td>'+ (r.id||'') +'</td>'+
      '<td>'+ (r.full_name||'') +'</td>'+
      '<td>'+ (r.email||'') +'</td>'+
      '<td>'+ actions +'</td>'+
    '</tr>';
  }

  function fetchSpecial(opts){
    var $wrap = $('#wpec-contacts-app[data-page="special"]');
    if (!$wrap.length) return;

    var status   = $wrap.data('status') || '';
    var pageSize = parseInt($('#wpec-page-size').val() || '50', 10);
    var page     = (opts && opts.page) ? opts.page : 1;

    $('#wpec-contacts-table tbody').html('<tr><td colspan="5">Loading…</td></tr>');
    $.post(WPEC.ajaxUrl, {
      action: 'wpec_contacts_query',
      nonce:  WPEC.nonce,
      page:   page,
      per_page: pageSize,
      status: status,
      cols: [] // keep table light
    }).done(function(res){
      if (!res || !res.success || !res.data) { $('#wpec-contacts-table tbody').html('<tr><td colspan="5">Error.</td></tr>'); return; }
      var rows = res.data.rows || [];
      if (!rows.length) { $('#wpec-contacts-table tbody').html('<tr><td colspan="5">No contacts.</td></tr>'); }
      else {
        var html = rows.map(buildRow).join('');
        $('#wpec-contacts-table tbody').html(html);
      }

      // pager
      var totalPages = res.data.total_pages || 1;
      var cur = res.data.page || 1;
      $('#wpec-page-numbers').text('Page '+cur+' of '+totalPages);
      $('#wpec-page-prev').prop('disabled', cur <= 1).off('click').on('click', function(){ fetchSpecial({page: cur-1}); });
      $('#wpec-page-next').prop('disabled', cur >= totalPages).off('click').on('click', function(){ fetchSpecial({page: cur+1}); });

      // master checkbox + enable/disable remove btn
      $('#wpec-master-cb').prop('checked', false);
      $('#wpec-status-remove').prop('disabled', true);
    });
  }

  // Only bind on special list pages
  $(function(){
    var $special = $('#wpec-contacts-app[data-page="special"]');
    if (!$special.length) return;

    // initial load
    fetchSpecial({page:1});
    $('#wpec-page-size').on('change', function(){ fetchSpecial({page:1}); });

    // master checkbox
    $(document).on('change', '#wpec-master-cb', function(){
      var on = $(this).is(':checked');
      $('.wpec-row-cb').prop('checked', on).trigger('change');
    });
    $(document).on('change', '.wpec-row-cb', function(){
      var any = $('.wpec-row-cb:checked').length > 0;
      $('#wpec-status-remove').prop('disabled', !any);
    });

    // Open/close modal
    $(document).on('click', '#wpec-status-add', function(){
      $('#wpec-modal-overlay, #wpec-modal').show();
    });
    $(document).on('click', '.wpec-modal-close, #wpec-modal-overlay', function(){
      $('#wpec-modal-overlay, #wpec-modal').hide();
    });

    // Add by emails -> move into this special list
    $(document).on('click', '#wpec-status-save', function(){
      var emails = $('#wpec-status-emails').val() || '';
      if (!emails.trim()) return;
      $('#wpec-status-loader').show();
      $.post(WPEC.ajaxUrl, {
        action: 'wpec_status_add_by_email',
        nonce:  WPEC.nonce,
        status: $special.data('status') || '',
        emails: emails
      }).always(function(){
        $('#wpec-status-loader').hide();
        $('#wpec-modal-overlay, #wpec-modal').hide();
        $('#wpec-status-emails').val('');
        fetchSpecial({page:1});
      });
    });

    // Remove selected -> move OUT (set active)
    $(document).on('click', '#wpec-status-remove', function(){
      var ids = $('.wpec-row-cb:checked').map(function(){ return $(this).data('id'); }).get();
      if (!ids.length) return;
      $('#wpec-status-loader').show();
      $.post(WPEC.ajaxUrl, {
        action: 'wpec_status_bulk_update',
        nonce:  WPEC.nonce,
        mode:   'remove',
        status: $special.data('status') || '',
        ids:    ids
      }).always(function(){
        $('#wpec-status-loader').hide();
        fetchSpecial({page:1});
      });
    });
  }); 

})(jQuery);
