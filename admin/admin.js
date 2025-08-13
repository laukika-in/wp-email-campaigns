(function ($) {
  // ── Helpers ───────────────────────────────────────────────────────────────
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
    "change click",
    '.wp-list-table input[type="checkbox"]',
    setBulkState
  );
  $(document).ready(setBulkState);

  // ── Publish confirm (campaign editor) ─────────────────────────────────────
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

  // ── Upload/import progress (on Import page) ───────────────────────────────
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
    var dupesUrlList = new URL(
      location.origin +
        location.pathname +
        "?post_type=email_campaign&page=wpec-duplicates",
      location.origin
    );
    dupesUrlList.searchParams.set("focus_list", String(listId));
    var reloadUrl = location.href;
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
      '<a class="button button-primary" href="' + reloadUrl + '">Reload</a>';
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

  // Existing list filter + toggle
  $("#wpec-existing-search").on("input", function () {
    var q = $(this).val().toLowerCase();
    $("#wpec-existing-list option").each(function () {
      var t = $(this).text().toLowerCase();
      $(this).toggle(t.indexOf(q) !== -1 || $(this).val() === "");
    });
  });
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
  $("#wpec-open-add-contact").on("click", function (e) {
    e.preventDefault();
    $("#wpec-modal-overlay").show();
    $("#wpec-modal").show();
  });
  $("#wpec-open-create-list").on("click", function (e) {
    e.preventDefault();
    $("#wpec-modal-overlay").show();
    $("#wpec-modal-list").show();
  });
  $("#wpec-add-contact-newlist-toggle").on("change", function () {
    var on = $(this).is(":checked");
    $("#wpec-add-contact-newlist").toggle(on);
    if (on) {
      $("#wpec-add-contact-list").val("");
    }
  });
  $("#wpec-create-list-form").on("submit", function (e) {
    e.preventDefault();
    var $form = $(this),
      $loader = $("#wpec-create-list-loader");
    $loader.show();
    $.post(WPEC.ajaxUrl, $form.serialize() + "&action=wpec_list_create")
      .done(function (resp) {
        if (resp && resp.success) {
          var id = resp.data.list_id,
            name = resp.data.name + " (#" + id + ")";
          $("#wpec-existing-list, #wpec-add-contact-list").each(function () {
            $(this).append($("<option>", { value: id, text: name }));
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

  // ── CONTACTS DIRECTORY (AJAX filters + pagination + export) ──────────────
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
  function collectMulti(sel) {
    var out = [];
    $(sel + " option:selected").each(function () {
      out.push($(this).val());
    });
    return out;
  }
  function currentFilters() {
    return {
      search: $("#wpec-f-search").val() || "",
      company_name: collectMulti("#wpec-f-company"),
      city: collectMulti("#wpec-f-city"),
      state: collectMulti("#wpec-f-state"),
      country: collectMulti("#wpec-f-country"),
      job_title: collectMulti("#wpec-f-job"),
      postal_code: collectMulti("#wpec-f-postcode"),
      list_ids: collectMulti("#wpec-f-list"),
      emp_min: $("#wpec-f-emp-min").val(),
      emp_max: $("#wpec-f-emp-max").val(),
      rev_min: $("#wpec-f-rev-min").val(),
      rev_max: $("#wpec-f-rev-max").val(),
    };
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
      '<tr><td colspan="4">Loading…</td></tr>'
    );
    return $.post(WPEC.ajaxUrl, data).done(function (resp) {
      if (!resp || !resp.success) {
        $("#wpec-contacts-table tbody").html(
          '<tr><td colspan="4">Failed to load.</td></tr>'
        );
        return;
      }
      var rows = resp.data.rows || [];
      var $thead = $("#wpec-contacts-table thead tr");
      var baseHead =
        "<th>ID</th><th>Full name</th><th>Email</th><th>List(s)</th>";
      var cols = collectCols();
      if (cols.length) {
        cols.forEach(function (c) {
          baseHead += "<th>" + headerLabel(c) + "</th>";
        });
      }
      $thead.html(baseHead);

      if (!rows.length) {
        $("#wpec-contacts-table tbody").html(
          '<tr><td colspan="' +
            (4 + cols.length) +
            '">No contacts found.</td></tr>'
        );
      } else {
        var html = "";
        rows.forEach(function (r) {
          html += "<tr>";
          html += "<td>" + (r.id || "") + "</td>";
          var name = r.full_name ? r.full_name : "";
          var detailUrl = new URL(location.origin + location.pathname);
          detailUrl.searchParams.set("post_type", "email_campaign");
          detailUrl.searchParams.set("page", "wpec-contacts");
          detailUrl.searchParams.set("view", "contact");
          detailUrl.searchParams.set("contact_id", String(r.id));
          html +=
            '<td><a href="' +
            detailUrl.toString() +
            '">' +
            escapeHtml(name) +
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
      var pageNo = resp.data.page || 1,
        totalPages = resp.data.total_pages || 1,
        total = resp.data.total || 0;
      $("#wpec-page-prev").prop("disabled", pageNo <= 1);
      $("#wpec-page-next").prop("disabled", pageNo >= totalPages);
      $("#wpec-page-info").text(
        "Page " + pageNo + " of " + totalPages + " — " + total + " contacts"
      );
      $("#wpec-page-prev").data("page", pageNo - 1);
      $("#wpec-page-next").data("page", pageNo + 1);
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

  // Multi-select filter search (option filtering)
  $(document).on("input", ".wpec-ms-search", function () {
    var q = $(this).val().toLowerCase();
    var target = $(this).data("target");
    $(target + " option").each(function () {
      var t = ($(this).text() || "").toLowerCase();
      $(this).toggle(t.indexOf(q) !== -1 || $(this).is(":selected"));
    });
  });

  // Pager & interactions
  $("#wpec-page-prev").on("click", function () {
    contactsQuery($(this).data("page"));
  });
  $("#wpec-page-next").on("click", function () {
    contactsQuery($(this).data("page"));
  });
  $("#wpec-page-size").on("change", function () {
    contactsQuery(1);
  });
  $("#wpec-f-apply").on("click", function (e) {
    e.preventDefault();
    contactsQuery(1);
  });
  $("#wpec-f-reset").on("click", function (e) {
    e.preventDefault();
    $("#wpec-f-search").val("");
    $(".wpec-ms-search").val("").trigger("input");
    $(
      "#wpec-f-company, #wpec-f-city, #wpec-f-state, #wpec-f-country, #wpec-f-job, #wpec-f-postcode, #wpec-f-list"
    ).val([]);
    $("#wpec-f-emp-min, #wpec-f-emp-max, #wpec-f-rev-min, #wpec-f-rev-max").val(
      ""
    );
    $(".wpec-col-toggle").prop("checked", false);
    contactsQuery(1);
  });
  $(".wpec-col-toggle").on("change", function () {
    contactsQuery(1);
  });

  // Prefill from facet links
  function prefillFromUrl() {
    var s = new URL(location.href).searchParams;
    function setIf(id, key) {
      if (s.get(key)) {
        var val = s.get(key);
        $(id + " option").each(function () {
          if ($(this).val() === val) {
            $(this).prop("selected", true);
          }
        });
      }
    }
    setIf("#wpec-f-company", "company_name");
    setIf("#wpec-f-city", "city");
    setIf("#wpec-f-state", "state");
    setIf("#wpec-f-country", "country");
    setIf("#wpec-f-job", "job_title");
    setIf("#wpec-f-postcode", "postal_code");
  }

  // Export filtered CSV
  $("#wpec-export-contacts").on("click", function (e) {
    e.preventDefault();
    var f = currentFilters();
    var $form = $("#wpec-export-form").empty();
    $form
      .append(
        '<input type="hidden" name="action" value="wpec_export_contacts">'
      )
      .append(
        '<input type="hidden" name="_wpnonce" value="' +
          WPEC.nonce.replace("wpec_admin", "wpec_export_contacts") +
          '">'
      ); // fallback; actual nonce set server-side in form
    function appendArray(key, arr) {
      (arr || []).forEach(function (v) {
        $form.append(
          '<input type="hidden" name="' +
            key +
            '[]" value="' +
            $("<div>").text(v).html() +
            '">'
        );
      });
    }
    if (f.search)
      $form.append(
        '<input type="hidden" name="search" value="' +
          $("<div>").text(f.search).html() +
          '">'
      );
    appendArray("company_name", f.company_name);
    appendArray("city", f.city);
    appendArray("state", f.state);
    appendArray("country", f.country);
    appendArray("job_title", f.job_title);
    appendArray("postal_code", f.postal_code);
    appendArray("list_ids", f.list_ids);
    if (f.emp_min)
      $form.append(
        '<input type="hidden" name="emp_min" value="' + f.emp_min + '">'
      );
    if (f.emp_max)
      $form.append(
        '<input type="hidden" name="emp_max" value="' + f.emp_max + '">'
      );
    if (f.rev_min)
      $form.append(
        '<input type="hidden" name="rev_min" value="' + f.rev_min + '">'
      );
    if (f.rev_max)
      $form.append(
        '<input type="hidden" name="rev_max" value="' + f.rev_max + '">'
      );
    $form.submit();
  });

  $(function () {
    if (onContactsPage()) {
      prefillFromUrl();
      contactsQuery(1);
    }
  });
})(jQuery);
