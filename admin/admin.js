(function ($) {
  // ===== Saved Views (Presets) for All Contacts =====
  function presetPayload() {
    return Object.assign(currentFilters(), {
      cols: collectCols(),
      page_size: parseInt($("#wpec-page-size").val(), 10) || 50,
    });
  }
  function applyPresetToUI(p) {
    if (!p || !p.data) return;
    var d = p.data;

    // Scalars
    $("#wpec-f-search").val(d.search || "");
    $("#wpec-f-emp-min").val(d.emp_min || "");
    $("#wpec-f-emp-max").val(d.emp_max || "");
    $("#wpec-f-rev-min").val(d.rev_min || "");
    $("#wpec-f-rev-max").val(d.rev_max || "");
    if (d.page_size) $("#wpec-page-size").val(String(d.page_size));

    // Multi-selects (use Select2 if present)
    function setMulti(sel, vals) {
      if (!Array.isArray(vals)) vals = [];
      var $el = $(sel);
      $el.val(vals);
      if ($el.hasClass("wpec-s2") && $el.data("select2")) $el.trigger("change");
    }
    setMulti("#wpec-f-company", d.company_name);
    setMulti("#wpec-f-city", d.city);
    setMulti("#wpec-f-state", d.state);
    setMulti("#wpec-f-country", d.country);
    setMulti("#wpec-f-job", d.job_title);
    setMulti("#wpec-f-postcode", d.postal_code);
    setMulti("#wpec-f-list", d.list_ids);
    setMulti("#wpec-f-status", d.status);

    // Columns
    $(".wpec-col-toggle").prop("checked", false);
    (d.cols || []).forEach(function (c) {
      $('.wpec-col-toggle[value="' + c + '"]').prop("checked", true);
    });
  }
  function refreshPresetButtonsState() {
    var hasSel = !!$("#wpec-preset").val();
    $("#wpec-preset-load, #wpec-preset-overwrite, #wpec-preset-delete").prop(
      "disabled",
      !hasSel
    );
    // Default checkbox reflects selection
    var sel = $("#wpec-preset").val();
    var isDefault =
      sel && window.WPEC && WPEC._presets && WPEC._presets.default_id === sel;
    $("#wpec-preset-default").prop("checked", !!isDefault);
  }
  function loadPresetsList(cb) {
    $.post(WPEC.ajaxUrl, { action: "wpec_presets_list", nonce: WPEC.nonce })
      .done(function (res) {
        if (!res || !res.success) return;
        WPEC._presets = {
          items: res.data.items || [],
          default_id: res.data.default_id || "",
        };
        var $sel = $("#wpec-preset").empty();
        $sel.append('<option value="">' + "— Select a view —" + "</option>");
        (WPEC._presets.items || []).forEach(function (it) {
          $sel.append($("<option>", { value: it.id, text: it.name }));
        });
        if (WPEC._presets.default_id) {
          $sel.val(WPEC._presets.default_id);
        }
        refreshPresetButtonsState();
        if (cb) cb();
      })
      .fail(function () {
        if (cb) cb();
      });
  }
  function savePreset(name, overwriteId) {
    var payload = presetPayload();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_presets_save",
      nonce: WPEC.nonce,
      name: name,
      id: overwriteId || "",
      data: JSON.stringify(payload),
    }).done(function (res) {
      if (res && res.success) {
        WPEC._presets = {
          items: res.data.items || [],
          default_id: res.data.default_id || "",
        };
        loadPresetsList(function () {
          if (res.data.saved_id) $("#wpec-preset").val(res.data.saved_id);
          refreshPresetButtonsState();
        });
      } else {
        alert((res && res.data && res.data.message) || "Save failed.");
      }
    });
  }
  function deletePreset(id) {
    $.post(WPEC.ajaxUrl, {
      action: "wpec_presets_delete",
      nonce: WPEC.nonce,
      id: id,
    }).done(function (res) {
      if (res && res.success) {
        WPEC._presets = {
          items: res.data.items || [],
          default_id: res.data.default_id || "",
        };
        loadPresetsList(refreshPresetButtonsState);
      } else {
        alert((res && res.data && res.data.message) || "Delete failed.");
      }
    });
  }
  function setDefaultPreset(id) {
    $.post(WPEC.ajaxUrl, {
      action: "wpec_presets_set_default",
      nonce: WPEC.nonce,
      id: id || "",
    }).done(function (res) {
      if (res && res.success) {
        if (!WPEC._presets) WPEC._presets = {};
        WPEC._presets.default_id = res.data.default_id || "";
        refreshPresetButtonsState();
      }
    });
  }
  // === Lists: delete empty list ===
  jQuery(document).on("click", ".wpec-list-delete", function (e) {
    e.preventDefault();
    var $btn = jQuery(this);
    var id = parseInt($btn.data("listId"), 10) || 0;
    if (!id) return;
    if (!confirm("Delete this empty list?")) return;

    $btn.prop("disabled", true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_list_delete",
        nonce: WPEC.nonce,
        list_id: id,
      })
      .done(function (resp) {
        if (resp && resp.success) {
          $btn.closest("tr").fadeOut(120, function () {
            jQuery(this).remove();
          });
        } else {
          alert((resp && resp.data && resp.data.message) || "Delete failed.");
          $btn.prop("disabled", false);
        }
      })
      .fail(function () {
        alert("Request failed.");
        $btn.prop("disabled", false);
      });
  });

  // Wire up toolbar
  $(document)
    .on("change", "#wpec-preset", refreshPresetButtonsState)
    .on("click", "#wpec-preset-load", function (e) {
      e.preventDefault();
      var id = $("#wpec-preset").val();
      if (!id || !WPEC._presets) return;
      var found = (WPEC._presets.items || []).find(function (x) {
        return x.id === id;
      });
      if (!found) return;
      applyPresetToUI(found);
      contactsQuery(1);
    })
    .on("click", "#wpec-preset-save", function (e) {
      e.preventDefault();
      var name = window.prompt("Name this view:");
      if (!name) return;
      savePreset(name, "");
    })
    .on("click", "#wpec-preset-overwrite", function (e) {
      e.preventDefault();
      var id = $("#wpec-preset").val();
      if (!id) return;
      var item = (WPEC._presets.items || []).find(function (x) {
        return x.id === id;
      });
      var name = (item && item.name) || "Untitled";
      if (!confirm('Overwrite "' + name + '" with current filters?')) return;
      savePreset(name, id);
    })
    .on("click", "#wpec-preset-delete", function (e) {
      e.preventDefault();
      var id = $("#wpec-preset").val();
      if (!id) return;
      if (!confirm("Delete this saved view?")) return;
      deletePreset(id);
    })
    .on("change", "#wpec-preset-default", function () {
      var id = $("#wpec-preset").val() || "";
      setDefaultPreset(this.checked ? id : "");
    });

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
  // Show/hide bulk bar and enable/disable Apply
  function setBulkState() {
    const anyChecked =
      $("#wpec-lists-table tbody .wpec-row-cb:checked").length > 0;
    const destVal = ($("#wpec-bulk-dest").val() || "").trim();

    // show bulk bar only when at least one row is checked
    $("#wpec-bulkbar").toggle(!!anyChecked);

    // enable Apply when a destination is selected (list:NN OR status:xxx)
    const canApply = !!(anyChecked && destVal.length);
    $("#wpec-bulk-apply").prop("disabled", !canApply);
  }

  // events that affect bulk state
  $(document).on("change", "#wpec-master-cb", function () {
    const checked = $(this).is(":checked");
    $("#wpec-lists-table tbody .wpec-row-cb").prop("checked", checked);
    setBulkState();
  });
  $(document).on(
    "change",
    "#wpec-lists-table tbody .wpec-row-cb",
    setBulkState
  );
  $(document).on("change", "#wpec-bulk-dest", setBulkState);

  // ensure state is recalculated after table refresh
  $(document).on("wpec:tableRefreshed", setBulkState);

  // ── Upload/import progress (Import page) ──────────────────────────────────
  function setProgress(pct, text) {
    $("#wpec-progress-wrap").show();
    $("#wpec-progress-bar").css("width", pct + "%");
    if (text) $("#wpec-progress-text").text(text);
  }
  function showResultPanel(stats, listId) {
    var $panel = $("#wpec-import-result");
    var dupesUrlAll = new URL(
      location.origin + location.pathname + "?page=wpec-duplicates",
      location.origin
    );
    var dupesUrlList = new URL(dupesUrlAll.toString());
    dupesUrlList.searchParams.set("focus_list", String(listId));
    var html = "";
    html += '<h3 style="margin-top:0;">Import Summary</h3>';
    if (WPEC.__upload && WPEC.__upload.listName) {
      html += '<p class="description" style="margin:0 0 8px 0">';
      html += "Target list: <strong>" + WPEC.__upload.listName + "</strong>";
      html += "</p>";
    }

    html += '<ul class="wpec-stats">';
    html +=
      "<li><strong>Now uploaded (this file):</strong> " +
      (stats.uploaded_this_import || 0) +
      "</li>";
    html +=
      "<li><strong>Duplicates in this import:</strong> " +
      (stats.duplicates_this_import || 0) +
      "</li>";
    html +=
      "<li><strong>Contacts in the list:</strong> " +
      (stats.list_contacts || 0) +
      "</li>";
    html +=
      "<li><strong>Duplicates in list:</strong> " +
      (stats.list_duplicates || 0) +
      "</li>";
    html +=
      "<li><strong>Total contacts (overall):</strong> " +
      (stats.contacts_overall || 0) +
      "</li>";
    html +=
      "<li><strong>Total duplicates (overall):</strong> " +
      (stats.duplicates_overall || 0) +
      "</li>";
    html += "</ul>";
    html += "<p>";
    // Link to the just-uploaded list view
    var listUrl = new URL(location.origin + location.pathname, location.origin);

    listUrl.searchParams.set("page", "wpec-lists");
    listUrl.searchParams.set("view", "list");
    listUrl.searchParams.set("list_id", String(listId));
    html +=
      '<a class="button button-primary" href="' +
      listUrl.toString() +
      '">View this list</a> ';

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

        var nowUp =
          s.uploaded_this_import != null
            ? s.uploaded_this_import
            : s.imported || 0;
        var dupImp =
          s.duplicates_this_import != null
            ? s.duplicates_this_import
            : s.duplicates || 0;
        var listCnt =
          s.list_contacts != null ? s.list_contacts : s.imported || 0;

        setProgress(
          pct,
          "Now uploaded: " +
            nowUp +
            " | Duplicates in this import: " +
            dupImp +
            " | Contacts in list: " +
            listCnt
        );

        if (resp && resp.success) {
          if (resp.data && resp.data.done) {
            $(".wpec-loader").hide();
            if (WPEC) WPEC.startImport = 0;
            showResultPanel(resp.data.stats || {}, resp.data.list_id);
          } else {
            setTimeout(function () {
              processList(listId);
            }, 200);
          }
        } else {
          $(".wpec-loader").hide();
          alert((resp && resp.data && resp.data.message) || "Import error");
        }
      })
      .fail(function () {
        $(".wpec-loader").hide();
        alert("Import request failed.");
      });
  }
  // ===== Mapping step (between Upload and Process) =====

  // state for this upload session
  var WPEC_UPLOAD = { listId: 0, cols: [], map: {} };

  // show/hide step panels
  function enterStep(n) {
    $("#wpec-upload-panel").toggle(n === 1); // step 1 (upload)
    $("#wpec-map-panel").toggle(n === 2); // step 2 (mapping)
    $("#wpec-review-panel").toggle(n === 3); // step 3 (review)
    // hide progress UI unless we are actually importing
    if (n !== 3) $("#wpec-progress-wrap, #wpec-import-result").hide();
  }

  // simple header normalizer
  function norm(s) {
    return String(s || "")
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, " ")
      .trim();
  }

  // field list we support (left column = DB fields, right = Select2 of headers)
  var DB_FIELDS = [
    ["first_name", "First name", true],
    ["last_name", "Last name", true],
    ["email", "Email", true],
    ["company_name", "Company name", false],
    ["company_employees", "Company number of employees", false],
    ["company_annual_revenue", "Company annual revenue", false],
    ["contact_number", "Contact number", false],
    ["job_title", "Job title", false],
    ["industry", "Industry", false],
    ["country", "Country", false],
    ["state", "State", false],
    ["city", "City", false],
    ["postal_code", "Postal code", false],
  ];

  // heuristic auto-map
  function autoGuess(cols) {
    var guessed = {};
    var lookup = cols.map(norm);
    function findOne(cands) {
      for (var i = 0; i < lookup.length; i++) {
        if (cands.indexOf(lookup[i]) !== -1) return i;
      }
      return -1;
    }
    guessed.first_name = findOne([
      "first name",
      "firstname",
      "f name",
      "given name",
    ]);
    guessed.last_name = findOne([
      "last name",
      "lastname",
      "surname",
      "family name",
    ]);
    guessed.email = findOne(["email", "email address", "e mail"]);
    guessed.company_name = findOne([
      "company",
      "company name",
      "org",
      "organization",
    ]);
    guessed.company_employees = findOne([
      "employees",
      "employee count",
      "company employees",
    ]);
    guessed.company_annual_revenue = findOne([
      "annual revenue",
      "revenue",
      "company revenue",
    ]);
    guessed.contact_number = findOne([
      "contact number",
      "phone",
      "phone number",
      "mobile",
    ]);
    guessed.job_title = findOne(["job title", "title", "role"]);
    guessed.industry = findOne(["industry"]);
    guessed.country = findOne(["country"]);
    guessed.state = findOne(["state", "region", "province"]);
    guessed.city = findOne(["city", "town"]);
    guessed.postal_code = findOne([
      "postal code",
      "postcode",
      "zip",
      "zip code",
    ]);
    return guessed;
  }

  // build mapping table UI
  function renderMapUI(cols) {
    var html =
      '<table class="widefat striped"><thead><tr><th style="width:280px">Our field</th><th>Uploaded column</th></tr></thead><tbody>';
    DB_FIELDS.forEach(function (f) {
      html +=
        '<tr data-key="' +
        f[0] +
        '"><th>' +
        f[1] +
        (f[2] ? ' <span class="description">(required)</span>' : "") +
        "</th>";
      html += '<td><select class="wpec-map-sel" data-key="' + f[0] + '">';
      html += '<option value="">— None —</option>';
      cols.forEach(function (c, i) {
        html += '<option value="' + i + '">' + c + "</option>";
      });
      html += "</select></td></tr>";
    });
    html += "</tbody></table>";
    $("#wpec-map-table").html(html);

    // apply guesses
    var g = autoGuess(cols);
    Object.keys(g).forEach(function (k) {
      var idx = g[k];
      if (idx >= 0)
        $('#wpec-map-table select.wpec-map-sel[data-key="' + k + '"]').val(
          String(idx)
        );
    });

    // Select2 (if present)
    if ($.fn.select2) {
      $("#wpec-map-table .wpec-map-sel").select2({
        width: "resolve",
        placeholder: "— None —",
      });
    }

    validateMap();
  }

  function validateMap() {
    var requiredOk =
      $('#wpec-map-table select.wpec-map-sel[data-key="first_name"]').val() &&
      $('#wpec-map-table select.wpec-map-sel[data-key="last_name"]').val() &&
      $('#wpec-map-table select.wpec-map-sel[data-key="email"]').val();
    $("#wpec-map-next").prop("disabled", !requiredOk);
  }

  // STEP 1: upload file (do not start import)
  $(document).on("submit", "#wpec-list-upload-form", function (e) {
    e.preventDefault();

    var fd = new FormData(this);
    // switch server action to our existing uploader
    fd.append("action", "wpec_list_upload");
    fd.append("nonce", WPEC.nonce);

    // lock UI
    $("#wpec-upload-btn").prop("disabled", true);
    $(".wpec-loader").show();

    $.ajax({
      url: WPEC.ajaxUrl,
      type: "POST",
      data: fd,
      processData: false,
      contentType: false,
      dataType: "json",
    })
      .done(function (resp) {
        if (!resp || !resp.success || !resp.data || !resp.data.list_id) {
          alert((resp && resp.data && resp.data.message) || "Upload failed.");
          $("#wpec-upload-btn").prop("disabled", false);
          $(".wpec-loader").hide();
          return;
        }
        // keep selected list info for later steps (mapping/review/summary)
        if (resp && resp.data) {
          window.WPEC = window.WPEC || {};
          WPEC.__upload = WPEC.__upload || {};
          WPEC.__upload.listId = resp.data.list_id || 0;
          WPEC.__upload.listName = resp.data.list_name || "";
        }

        WPEC_UPLOAD.listId = parseInt(resp.data.list_id, 10);

        // probe headers of the uploaded CSV
        $.post(WPEC.ajaxUrl, {
          action: "wpec_list_probe_headers",
          nonce: WPEC.nonce,
          list_id: WPEC_UPLOAD.listId,
        }).done(function (r2) {
          $("#wpec-upload-btn").prop("disabled", false);
          $(".wpec-loader").hide();

          if (!r2 || !r2.success || !r2.data || !r2.data.columns) {
            alert(
              (r2 && r2.data && r2.data.message) || "Could not read header row."
            );
            return;
          }
          WPEC_UPLOAD.cols = r2.data.columns;
          renderMapUI(WPEC_UPLOAD.cols);
          enterStep(2); // show mapping below the upload card
          window.scrollTo({
            top: $("#wpec-map-panel").offset().top - 40,
            behavior: "smooth",
          });
        });
      })
      .fail(function () {
        $("#wpec-upload-btn").prop("disabled", false);
        $(".wpec-loader").hide();
        alert("Upload failed.");
      });
  });

  // mapping changes -> validate
  $(document).on("change", "#wpec-map-table .wpec-map-sel", validateMap);

  // STEP 2: back
  $(document).on("click", "#wpec-map-back", function (e) {
    e.preventDefault();
    enterStep(1);
  });

  // STEP 2: next (save map, move to review)
  $(document).on("click", "#wpec-map-next", function (e) {
    e.preventDefault();

    // collect mapping
    var map = {};

    $("#wpec-map-table .wpec-map-sel").each(function () {
      var key = $(this).data("key");
      var v = $(this).val();
      if (v !== "") map[key] = parseInt(v, 10);
    });

    $.post(WPEC.ajaxUrl, {
      action: "wpec_list_set_header_map",
      nonce: WPEC.nonce,
      list_id: WPEC_UPLOAD.listId,
      map: JSON.stringify(map),
    }).done(function (resp) {
      if (!resp || !resp.success) {
        alert(
          (resp && resp.data && resp.data.message) || "Could not save mapping."
        );
        return;
      }
      // summary view
      if (WPEC.__upload && WPEC.__upload.listName) {
        $("#wpec-step-mapping .wpec-step-header").append(
          '<p class="description">Target list: <strong>' +
            WPEC.__upload.listName +
            "</strong></p>"
        );
      }

      var s =
        '<table class="widefat striped"><thead><tr><th>Our field</th><th>Mapped column</th></tr></thead><tbody>';
      DB_FIELDS.forEach(function (f) {
        var idx = map[f[0]];
        var val =
          idx != null && idx >= 0 ? WPEC_UPLOAD.cols[idx] || "#" + idx : "—";
        s +=
          "<tr><td>" +
          f[1] +
          (f[2] ? " *" : "") +
          "</td><td>" +
          val +
          "</td></tr>";
      });
      s += "</tbody></table>";
      $("#wpec-map-summary").html(s);

      enterStep(3);
      window.scrollTo({
        top: $("#wpec-review-panel").offset().top - 40,
        behavior: "smooth",
      });
    });
  });

  // STEP 3: start import -> reuse existing processList()
  $(document).on("click", "#wpec-start-import", function (e) {
    e.preventDefault();
    $("#wpec-progress-wrap").show();
    $(".wpec-loader").show();
    if (typeof processList === "function") {
      processList(WPEC_UPLOAD.listId);
    }
  });

  // ensure we start on step 1 when page loads
  $(function () {
    enterStep(1);
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
  // List page: remove a single contact from THIS list
  $(document).on("click", ".wpec-del-from-list", function (e) {
    e.preventDefault();
    var $btn = $(this),
      listId = parseInt($btn.data("listId"), 10),
      contactId = parseInt($btn.data("contactId"), 10);
    if (!listId || !contactId) return;
    if (!confirm("Remove this contact from this list?")) return;
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
        var $cb = $(this);
        var raw = String($cb.val() || "");
        var parts = raw.indexOf(":") !== -1 ? raw.split(":") : [];
        var listId =
          parseInt($cb.data("listId"), 10) ||
          parseInt(
            $cb.closest("form").data("listId") ||
              $("#wpec-current-list-id").val() ||
              parts[0] ||
              0,
            10
          ) ||
          0;
        var contactId =
          parseInt($cb.data("contactId"), 10) ||
          parseInt(parts[1] || raw, 10) ||
          0;
        if (listId && contactId) {
          tasks.push({
            listId: listId,
            contactId: contactId,
            $row: $cb.closest("tr"),
          });
        }
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
  // ADD — List page bulk delete binding (below the dup binding)
  bulkDelete(
    "#wpec-list-bulk-delete",
    "#wpec-list-form",
    "#wpec-list-progress-bar",
    "#wpec-list-progress-text",
    "#wpec-list-bulk-progress",
    "#wpec-list-bulk-loader"
  );
  // Enable/disable the List page bulk delete button when selections change
  $(document).on("change", '#wpec-list-form input[name="ids[]"]', function () {
    const any = $('#wpec-list-form input[name="ids[]"]:checked').length > 0;
    $("#wpec-list-bulk-delete").prop("disabled", !any);
  });

  // === List page: enable/disable bulk actions by checkbox selection ===
  jQuery(function ($) {
    var $listForm = $("#wpec-list-form");
    if (!$listForm.length) return;

    function toggleListBulk() {
      var any = $listForm.find('input[name="ids[]"]:checked').length > 0;
      $("#wpec-list-bulk-delete, #wpec-list-bulk-move").prop("disabled", !any);
    }
    $(document).on(
      "change",
      "#wpec-list-form input[type=checkbox]",
      toggleListBulk
    );
    toggleListBulk(); // initial
  });

  function wpecToggleListBulk() {
    var any = jQuery('#wpec-list-form input[name="ids[]"]:checked').length > 0;
    var hasDest = parseInt(jQuery("#wpec-list-move-list").val() || "0", 10) > 0;
    jQuery("#wpec-list-bulk-delete").prop("disabled", !any);
    jQuery("#wpec-list-bulk-move").prop("disabled", !(any && hasDest));
  }
  jQuery(document).on(
    "change",
    '#wpec-list-form input[type="checkbox"]',
    wpecToggleListBulk
  );
  jQuery(document).on("change", "#wpec-list-move-list", wpecToggleListBulk);
  jQuery(wpecToggleListBulk);

  // Move selected (from this list) to another list
  jQuery(document).on("click", "#wpec-list-bulk-move", function (e) {
    e.preventDefault();

    // collect contact IDs and infer the current (source) list id from the first checked row
    var ids = [];
    var firstRaw = null;

    jQuery('#wpec-list-form input[name="ids[]"]:checked').each(function () {
      var raw = String(jQuery(this).val()); // "{listId}:{contactId}"
      if (!firstRaw) firstRaw = raw;
      var parts = raw.split(":");
      var cid = parseInt(parts[1], 10) || 0;
      if (cid) ids.push(cid);
    });

    var dest = parseInt(jQuery("#wpec-list-move-list").val(), 10) || 0;
    if (!ids.length || !dest) return;

    // source list id
    var src = 0;
    if (firstRaw && firstRaw.indexOf(":") !== -1) {
      src = parseInt(firstRaw.split(":")[0], 10) || 0;
    }
    if (!src) {
      // fallback to hidden/input if present
      src =
        parseInt(jQuery("#wpec-current-list-id").val() || "0", 10) ||
        parseInt(jQuery("#wpec-list-form").data("listId") || "0", 10) ||
        0;
    }

    jQuery("#wpec-list-bulk-loader").show();
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_contacts_bulk_move",
        nonce: WPEC.nonce,
        ids: ids,
        list_id: dest,
        source_list_id: src, // tell the server to remove from current list
      })
      .done(function (res) {
        if (res && res.success) {
          location.reload(); // stay on the same list and refresh
        } else {
          alert((res && res.data && res.data.message) || "Move failed.");
        }
      })
      .always(function () {
        jQuery("#wpec-list-bulk-loader").hide();
      });
  });

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
    $("#wpec-add-contact-newlist-toggle")
      .prop("checked", true)
      .trigger("change");
    $('#wpec-add-contact-newlist input[name="new_list_name"]').focus();
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
    return new URL(location.href).searchParams.get("page") === "wpec-contacts";
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
      status: collectMultiSel("#wpec-f-status"),
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
  function formatWpecDate(val) {
    if (val == null || val === "") return "";
    let d;

    // Number? (seconds or milliseconds)
    if (typeof val === "number" || /^\d+$/.test(String(val))) {
      let n = Number(val);
      if (n < 1e12) n *= 1000; // treat as seconds
      d = new Date(n);
    } else {
      // MySQL DATETIME 'YYYY-MM-DD HH:MM:SS' (parse as LOCAL time)
      const m = String(val).match(
        /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?/
      );
      if (m) {
        d = new Date(
          Number(m[1]),
          Number(m[2]) - 1,
          Number(m[3]),
          Number(m[4]),
          Number(m[5]),
          m[6] ? Number(m[6]) : 0
        );
      } else {
        d = new Date(val); // ISO or other parseable
      }
    }
    if (isNaN(d)) return "";

    const day = String(d.getDate()).padStart(2, "0");
    const month = [
      "Jan",
      "Feb",
      "Mar",
      "Apr",
      "May",
      "Jun",
      "Jul",
      "Aug",
      "Sep",
      "Oct",
      "Nov",
      "Dec",
    ][d.getMonth()];
    const year = d.getFullYear();
    let h = d.getHours();
    const ampm = h >= 12 ? "PM" : "AM";
    h = h % 12 || 12;
    const min = String(d.getMinutes()).padStart(2, "0");

    return `${day} ${month} ${year} ${h}:${min} ${ampm}`;
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
    $("#wpec-lists-table tbody").html(
      '<tr><td colspan="999">Loading…</td></tr>'
    );
    return $.post(WPEC.ajaxUrl, data).done(function (resp) {
      if (!resp || !resp.success) {
        $("#wpec-lists-table tbody").html(
          '<tr><td colspan="999">Failed to load.</td></tr>'
        );
        return;
      }
      var rows = resp.data.rows || [];
      var cols = collectCols();

      // Build thead
      var $thead = $("#wpec-lists-table thead tr");
      var head =
        '<th style="width:28px"><input type="checkbox" id="wpec-master-cb"></th><th>ID</th><th>Full name</th><th>Email</th><th>Status</th><th>Created</th><th>Lists</th>';
      cols.forEach(function (c) {
        head += "<th>" + headerLabel(c) + "</th>";
      });
      $thead.html(head);

      // Build rows
      if (!rows.length) {
        $("#wpec-lists-table tbody").html(
          '<tr><td colspan="999">No contacts found.</td></tr>'
        );
      } else {
        var html = "";
        rows.forEach(function (r) {
          var detailUrl = new URL(location.origin + location.pathname);
          detailUrl.searchParams.set("page", "wpec-lists");
          detailUrl.searchParams.set("view", "contact");
          detailUrl.searchParams.set("contact_id", String(r.id));
          html += "<tr>";
          html +=
            '<td style="width:24px;"><input type="checkbox" class="wpec-row-cb" data-id="' +
            r.id +
            '"></td>';

          html += "<td>" + (r.id || "") + "</td>";
          html +=
            '<td><a href="' +
            detailUrl.toString() +
            '">' +
            escapeHtml(r.full_name || "") +
            "</a></td>";
          var emailHtml = escapeHtml(r.email || "");
          if (r.status && r.status !== "active") {
            var txt = r.status === "unsubscribed" ? "DND" : "Bounced";
            emailHtml +=
              ' <span class="wpec-pill wpec-pill-' +
              r.status +
              '">' +
              txt +
              "</span>";
          }
          html += "<td>" + emailHtml + "</td>";

          html += "<td>" + (r.status || "") + "</td>";
          html += "<td>" + formatWpecDate(r.created_at) + "</td>";
          html += "<td>" + escapeHtml(r.lists || "") + "</td>";

          cols.forEach(function (c) {
            html +=
              "<td>" + escapeHtml(r[c] == null ? "" : String(r[c])) + "</td>";
          });
          html += "</tr>";
        });
        $("#wpec-lists-table tbody").html(html);
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
  const $app = $('#wpec-lists-app[data-page="all"]');
  if (!$app.length) return;
  function updateColCount() {
    const n = $(".wpec-col-toggle:checked").length;
    $("#wpec-col-count").text(n);
  }
  $(document).on("change", ".wpec-col-toggle", updateColCount);
  updateColCount();

  // Quick status segmented filters → mirror into #wpec-f-status
  function setStatusFilter(value) {
    // value = "__all" | "active" | "unsubscribed" | "bounced"
    const $sel = $("#wpec-f-status");
    if (value === "__all") {
      $sel.val(null).trigger("change");
    } else {
      $sel.val([value]).trigger("change");
    }
    $(".wpec-seg-btn").removeClass("is-on");
    $('.wpec-seg-btn[data-status="' + value + '"]').addClass("is-on");
    $("#wpec-f-apply").trigger("click");
  }
  $(document).on("click", ".wpec-seg-btn", function () {
    setStatusFilter($(this).data("status"));
  });

  /* ========== 3) Compact view (persisted) ========== */
  const compactKey = "wpec-contacts-compact";
  function applyCompact() {
    const on = localStorage.getItem(compactKey) === "1";
    $app.closest(".wrap").toggleClass("wpec-compact", on);
    $("#wpec-compact-toggle").prop("checked", on);
  }
  $("#wpec-compact-toggle").on("change", function () {
    localStorage.setItem(compactKey, this.checked ? "1" : "0");
    applyCompact();
  });
  applyCompact();
  // Skeleton rows on load
  function putSkeletonRows() {
    const cols = $("#wpec-lists-table thead th").length || 8;
    let skel = "";
    for (let i = 0; i < 8; i++) {
      skel += "<tr>";
      for (let c = 0; c < cols; c++) {
        skel +=
          '<td><div class="wpec-skel" style="width:' +
          (30 + Math.random() * 60) +
          '%"></div></td>';
      }
      skel += "</tr>";
    }
    $("#wpec-lists-table tbody").html(skel);
  }
  $(document).on("wpec:loading", putSkeletonRows);
  function ensureCompactToggle() {
    const $toolbar = $(".wpec-toolbar");
    if (!$toolbar.length) return; // safe if you haven’t added the toolbar yet
    if (!$("#wpec-compact-toggle").length) {
      $toolbar
        .find(".cluster")
        .last()
        .append(
          '<label style="display:inline-flex;align-items:center;gap:6px;margin-left:8px;">' +
            '<input type="checkbox" id="wpec-compact-toggle"> Compact' +
            "</label>"
        );
    }
    $("#wpec-compact-toggle")
      .off("change")
      .on("change", function () {
        localStorage.setItem(compactKey, this.checked ? "1" : "0");
        applyCompact();
      });
  }
  ensureCompactToggle();
  applyCompact();

  // Filters
  function ensureActiveFiltersBar() {
    if (!$("#wpec-active-filters").length) {
      // Insert after the filters card
      $('<div id="wpec-active-filters"></div>').insertAfter(
        $(".wpec-filters").closest(".wpec-card")
      );
    }
  }

  function valLabelFromSelect($sel) {
    const vals = $sel.val() || [];
    return vals.map(function (v) {
      const $opt = $sel.find(
        'option[value="' + String(v).replace(/"/g, '\\"') + '"]'
      );
      return { value: v, label: ($opt.data("label") || $opt.text()).trim() };
    });
  }
  function buildChips() {
    ensureActiveFiltersBar();
    const $bar = $("#wpec-active-filters").empty();

    const defs = [
      { id: "#wpec-f-search", type: "text", label: "Search" },
      { id: "#wpec-f-company", type: "select", label: "Company" },
      { id: "#wpec-f-city", type: "select", label: "City" },
      { id: "#wpec-f-state", type: "select", label: "State" },
      { id: "#wpec-f-country", type: "select", label: "Country" },
      { id: "#wpec-f-job", type: "select", label: "Job" },
      { id: "#wpec-f-postcode", type: "select", label: "PIN" },
      { id: "#wpec-f-list", type: "select", label: "List" },
      { id: "#wpec-f-status", type: "select", label: "Status" },
      { id: "#wpec-f-emp-min", type: "min", label: "Employees ≥" },
      { id: "#wpec-f-emp-max", type: "max", label: "Employees ≤" },
      { id: "#wpec-f-rev-min", type: "min", label: "Revenue ≥" },
      { id: "#wpec-f-rev-max", type: "max", label: "Revenue ≤" },
    ];

    const chips = [];

    defs.forEach(function (d) {
      const $el = $(d.id);
      if (!$el.length) return;

      if (d.type === "text") {
        const val = ($el.val() || "").trim();
        if (val)
          chips.push({
            key: d.id,
            label: d.label + ": " + val,
            clear: () => {
              $el.val("");
            },
          });
      }
      if (d.type === "select") {
        valLabelFromSelect($el).forEach(function (it) {
          chips.push({
            key: d.id + "::" + it.value,
            label: d.label + ": " + it.label,
            clear: () => {
              const vals = ($el.val() || []).filter(
                (v) => String(v) !== String(it.value)
              );
              $el.val(vals).trigger("change");
            },
          });
        });
      }
      if (d.type === "min" || d.type === "max") {
        const val = $el.val();
        if (val !== "" && val != null) {
          chips.push({
            key: d.id,
            label: d.label + " " + val,
            clear: () => {
              $el.val("");
            },
          });
        }
      }
    });

    if (!chips.length) {
      $bar.hide();
      return;
    }

    chips.forEach(function (ch) {
      const $chip = $(
        '<span class="wpec-chip" data-key="' + ch.key + '"></span>'
      ).text(ch.label);
      const $x = $(
        '<button type="button" class="wpec-chip-remove" aria-label="Remove">&times;</button>'
      );
      $x.on("click", function () {
        ch.clear();
        buildChips();
        $("#wpec-f-apply").trigger("click"); // re-run your fetch/render
      });
      $chip.append($x);
      $bar.append($chip);
    });

    $bar.css("display", "flex");
  }

  /* ========== 1) Empty state ========== */
  function ensureEmptyState() {
    if (!$("#wpec-empty").length) {
      const $tfoot = $("#wpec-lists-table tfoot");
      const row =
        '<tr><td colspan="5"><div class="wpec-empty" id="wpec-empty" style="display:none">' +
        '<span class="dashicons dashicons-search"></span> ' +
        "No contacts match the current filters." +
        "</div></td></tr>";
      if ($tfoot.length) $tfoot.html(row);
      else $("#wpec-lists-table").append($("<tfoot/>").html(row));
    }
  }
  function updateEmptyState() {
    ensureEmptyState();
    const hasRows = $("#wpec-lists-table tbody tr").length > 0;
    $("#wpec-empty").toggle(!hasRows);
  }

  $(document).on("click", "#wpec-f-apply", function (e) {
    e.preventDefault();
    buildChips();
    contactsQuery(1);
  });
  $(document).on("click", "#wpec-f-reset", function (e) {
    e.preventDefault();
    $("#wpec-f-search").val("");
    $(".wpec-s2").val(null).trigger("change");
    $("#wpec-f-emp-min,#wpec-f-emp-max,#wpec-f-rev-min,#wpec-f-rev-max").val(
      ""
    );
    buildChips();
    $(".wpec-col-toggle").prop("checked", false);
    $("#wpec-f-status").val("").trigger("change");

    contactsQuery(1);
  });
  $(document).on("change", ".wpec-col-toggle", function () {
    contactsQuery(1);
  });

  // Master checkbox handler
  $(document).on("change", "#wpec-master-cb", function () {
    var on = $(this).is(":checked");
    $("#wpec-lists-table tbody .wpec-row-cb").prop("checked", on);
    setBulkState();
  });

  // Row checkbox handler
  $(document).on(
    "change",
    "#wpec-lists-table tbody .wpec-row-cb",
    setBulkState
  );

  // Bulk delete (All Contacts)
  $("#wpec-bulk-delete").on("click", function (e) {
    e.preventDefault();
    var ids = [];
    $("#wpec-lists-table tbody .wpec-row-cb:checked").each(function () {
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
          $("#wpec-lists-table tbody .wpec-row-cb:checked")
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
  // Duplicates page: enable/disable bulk delete when checkboxes change
  function wpecToggleDupBulk() {
    var any = $('#wpec-dup-form input[name="ids[]"]:checked').length > 0;
    $("#wpec-dup-bulk-delete").prop("disabled", !any);
  }
  // header + row checkboxes
  $(document).on(
    "change",
    '#wpec-dup-form input[type="checkbox"]',
    wpecToggleDupBulk
  );
  // initialize on load
  $(wpecToggleDupBulk);

  // Bulk move to list
  $("#wpec-bulk-move").on("click", function (e) {
    e.preventDefault();
    var ids = [];
    $("#wpec-lists-table tbody .wpec-row-cb:checked").each(function () {
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

    // include Status when present
    if (f.status) {
      form.append(
        $("<input>", { type: "hidden", name: "status", value: f.status })
      );
    }

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
    $("#wpec-bulk-dest").select2({
      width: "resolve",
      placeholder: "— Select —",
    });
  }

  $(function () {
    if (onContactsPage()) {
      ensureSelect2(function () {
        initSelect2();
        // Load presets (and default) first, then run the query
        loadPresetsList(function () {
          var defaultId = WPEC._presets && WPEC._presets.default_id;
          if (defaultId) {
            var item = (WPEC._presets.items || []).find(function (x) {
              return x.id === defaultId;
            });
            if (item) applyPresetToUI(item);
          }
          contactsQuery(1);
        });
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

  function buildRow(r) {
    var actions = "";
    if (r.id) {
      var viewUrl = window.ajaxurl
        ? new URLSearchParams({
            page: "wpec-lists",
            view: "contact",
            contact_id: String(r.id),
          }).toString()
        : "";
      var href =
        window.WPEC && WPEC.adminBase
          ? WPEC.adminBase + "?" + viewUrl
          : window.location.origin + "/wp-admin/edit.php?" + viewUrl;
      actions =
        '<a class="button button-small" href="' + href + '">View detail</a>';
    }
    return (
      "<tr>" +
      '<td><input type="checkbox" class="wpec-row-cb" data-id="' +
      (r.id || "") +
      '"></td>' +
      "<td>" +
      (r.id || "") +
      "</td>" +
      "<td>" +
      (r.full_name || "") +
      "</td>" +
      "<td>" +
      (r.email || "") +
      "</td>" +
      "<td>" +
      actions +
      "</td>" +
      "</tr>"
    );
  }

  function fetchSpecial(opts) {
    var $wrap = $('#wpec-lists-app[data-page="special"]');
    if (!$wrap.length) return;

    var status = $wrap.data("status") || "";
    var pageSize = parseInt($("#wpec-page-size").val() || "50", 10);
    var page = opts && opts.page ? opts.page : 1;
    $(document).trigger("wpec:loading");
    $("#wpec-lists-table tbody").html('<tr><td colspan="5">Loading…</td></tr>');
    $.post(WPEC.ajaxUrl, {
      action: "wpec_contacts_query",
      nonce: WPEC.nonce,
      page: page,
      per_page: pageSize,
      status: status,
      cols: [], // keep table light
    }).done(function (res) {
      if (!res || !res.success || !res.data) {
        $("#wpec-lists-table tbody").html(
          '<tr><td colspan="5">Error.</td></tr>'
        );
        return;
      }
      var rows = res.data.rows || [];
      if (!rows.length) {
        $("#wpec-lists-table tbody").html(
          '<tr><td colspan="5">No contacts.</td></tr>'
        );
      } else {
        var html = rows.map(buildRow).join("");
        $("#wpec-lists-table tbody").html(html);
      }

      // pager
      var totalPages = res.data.total_pages || 1;
      var cur = res.data.page || 1;
      $("#wpec-page-numbers").text("Page " + cur + " of " + totalPages);
      $("#wpec-page-prev")
        .prop("disabled", cur <= 1)
        .off("click")
        .on("click", function () {
          fetchSpecial({ page: cur - 1 });
        });
      $("#wpec-page-next")
        .prop("disabled", cur >= totalPages)
        .off("click")
        .on("click", function () {
          fetchSpecial({ page: cur + 1 });
        });

      // master checkbox + enable/disable remove btn
      $("#wpec-master-cb").prop("checked", false);
      $("#wpec-status-remove").prop("disabled", true);
    });
  }
  function clamp(n, min, max) {
    return Math.min(max, Math.max(min, n));
  }

  function initDualRange($wrap) {
    const $low = $wrap.find(".wpec-range-l");
    const $high = $wrap.find(".wpec-range-h");
    const $bar = $wrap.find(".wpec-range-track > span");

    const targetMinSel = $wrap.data("target-min");
    const targetMaxSel = $wrap.data("target-max");
    const $targetMin = $(targetMinSel);
    const $targetMax = $(targetMaxSel);

    const min = Number($wrap.data("min"));
    const max = Number($wrap.data("max"));
    const step = Number($wrap.data("step")) || 1;

    // Ensure attributes agree
    $low.attr({ min, max, step });
    $high.attr({ min, max, step });

    // Init values from numeric inputs if present, else full span
    const initLow = $targetMin.val() !== "" ? Number($targetMin.val()) : min;
    const initHigh = $targetMax.val() !== "" ? Number($targetMax.val()) : max;
    $low.val(clamp(initLow, min, max));
    $high.val(clamp(initHigh, min, max));

    function paint() {
      const l = Number($low.val());
      const h = Number($high.val());
      const pctL = ((l - min) / (max - min)) * 100;
      const pctH = ((h - min) / (max - min)) * 100;
      $bar.css({ left: pctL + "%", width: pctH - pctL + "%" });
    }

    function syncFromSliders() {
      let l = Number($low.val());
      let h = Number($high.val());
      if (l > h) {
        const t = l;
        l = h;
        h = t;
        $low.val(l);
        $high.val(h);
      }
      $targetMin.val(l);
      $targetMax.val(h);
      paint();
    }

    function syncFromInputs() {
      let l = $targetMin.val() === "" ? min : Number($targetMin.val());
      let h = $targetMax.val() === "" ? max : Number($targetMax.val());
      l = clamp(l, min, max);
      h = clamp(h, min, max);
      if (l > h) {
        const t = l;
        l = h;
        h = t;
      }
      $low.val(l);
      $high.val(h);
      paint();
    }

    // Events
    $low.on("input change", syncFromSliders);
    $high.on("input change", syncFromSliders);
    $targetMin.on("input change", syncFromInputs);
    $targetMax.on("input change", syncFromInputs);

    // Initial paint
    paint();

    // Expose a reset helper
    $wrap.data("wpecReset", function () {
      $low.val(min);
      $high.val(max);
      $targetMin.val("");
      $targetMax.val("");
      paint();
    });
  }

  $(function () {
    // Init all sliders on Contacts page
    const $app = $('#wpec-lists-app[data-page="all"]');
    if (!$app.length) return;

    $(".wpec-range").each(function () {
      initDualRange($(this));
    });

    // Hook your existing Reset button to reset the sliders too
    $("#wpec-f-reset").on("click", function () {
      $(".wpec-range").each(function () {
        const reset = $(this).data("wpecReset");
        if (typeof reset === "function") reset();
      });
    });
  });
  // Only bind on special list pages
  $(function () {
    var $special = $('#wpec-lists-app[data-page="special"]');
    if (!$special.length) return;

    // initial load
    fetchSpecial({ page: 1 });
    $("#wpec-page-size").on("change", function () {
      fetchSpecial({ page: 1 });
    });

    // master checkbox
    $(document).on("change", "#wpec-master-cb", function () {
      var on = $(this).is(":checked");
      $(".wpec-row-cb").prop("checked", on).trigger("change");
    });
    $(document).on("change", ".wpec-row-cb", function () {
      var any = $(".wpec-row-cb:checked").length > 0;
      $("#wpec-status-remove").prop("disabled", !any);
    });

    // Open/close modal
    $(document).on("click", "#wpec-status-add", function () {
      $("#wpec-modal-overlay, #wpec-modal").show();
    });
    $(document).on(
      "click",
      ".wpec-modal-close, #wpec-modal-overlay",
      function () {
        $("#wpec-modal-overlay, #wpec-modal").hide();
      }
    );

    // Add by emails -> move into this special list
    $(document).on("click", "#wpec-status-save", function () {
      var emails = $("#wpec-status-emails").val() || "";
      if (!emails.trim()) return;
      $("#wpec-status-loader").show();
      $.post(WPEC.ajaxUrl, {
        action: "wpec_status_add_by_email",
        nonce: WPEC.nonce,
        status: $special.data("status") || "",
        emails: emails,
      }).always(function () {
        $("#wpec-status-loader").hide();
        $("#wpec-modal-overlay, #wpec-modal").hide();
        $("#wpec-status-emails").val("");
        fetchSpecial({ page: 1 });
      });
    });

    // Remove selected -> move OUT (set active)
    $(document).on("click", "#wpec-status-remove", function () {
      var ids = $(".wpec-row-cb:checked")
        .map(function () {
          return $(this).data("id");
        })
        .get();
      if (!ids.length) return;
      $("#wpec-status-loader").show();
      $.post(WPEC.ajaxUrl, {
        action: "wpec_status_bulk_update",
        nonce: WPEC.nonce,
        mode: "remove",
        status: $special.data("status") || "",
        ids: ids,
      }).always(function () {
        $("#wpec-status-loader").hide();
        fetchSpecial({ page: 1 });
      });
    });
  });

  function isAllPage() {
    return $('#wpec-lists-app[data-page="all"]').length > 0;
  }

  function decorateAllRows() {
    if (!isAllPage()) return;
    var $tbody = $("#wpec-lists-table tbody");
    $tbody.find("tr").each(function () {
      var $tr = $(this);
      // add per-row checkbox if missing
      if ($tr.find("td:first-child input.wpec-row-cb").length) return;
      var $cells = $tr.children("td");
      if (!$cells.length) return;
      var idText = $cells.eq(0).text().trim();
      var id = parseInt(idText, 10) || "";
      $tr.prepend(
        '<td style="width:24px;"><input type="checkbox" class="wpec-row-cb" data-id="' +
          id +
          '"></td>'
      );
    });
  }

  function currentSelection() {
    return $("#wpec-lists-table tbody .wpec-row-cb:checked")
      .map(function () {
        return $(this).data("id");
      })
      .get();
  }

  function toggleBulkbar() {
    var any = currentSelection().length > 0;
    $("#wpec-bulkbar").css("display", any ? "flex" : "none");
    $("#wpec-bulk-apply, #wpec-bulk-delete").prop("disabled", !any);
  }

  function refreshTable() {
    if ($("#wpec-f-apply").length) {
      $("#wpec-f-apply").trigger("click");
    } else {
      location.reload();
    }
  }

  // Linkify the Lists column (5th cell) using lists_meta from the AJAX response
  function linkifyLists(rows) {
    if (!rows || !rows.length) return;
    const base = window.WPEC && WPEC.listViewBase ? WPEC.listViewBase : "";
    if (!base) return;

    const $trs = $("#wpec-lists-table tbody tr");
    $trs.each(function (i) {
      const meta = rows[i] && rows[i].lists_meta;
      if (!meta) return;

      // meta format: "123::List A|456::List B"
      const parts = meta.split("|");
      const links = parts
        .map(function (pair) {
          const ix = pair.indexOf("::");
          if (ix === -1) return null;
          const id = pair.slice(0, ix);
          const name = pair.slice(ix + 2);
          return '<a href="' + base + id + '">' + escapeHtml(name) + "</a>";
        })
        .filter(Boolean);

      // 5th TD = "List(s)" (index 4) — extra columns (if any) come after this
      const $cells = $(this).children("td");
      const $listsCell = $cells.eq(6);
      $listsCell.html(links.length ? links.join(", ") : "<em>-</em>");
    });
  }

  function wireAllPage() {
    if (!isAllPage()) return;

    // master checkbox (header)
    $(document).on("change", "#wpec-master-cb", function () {
      var on = $(this).is(":checked");
      $("#wpec-lists-table tbody .wpec-row-cb").prop("checked", on);
      toggleBulkbar();
    });

    // row checkboxes
    $(document).on(
      "change",
      "#wpec-lists-table tbody .wpec-row-cb",
      function () {
        if (!$(this).is(":checked"))
          $("#wpec-master-cb").prop("checked", false);
        toggleBulkbar();
      }
    );

    // observe tbody for re-renders
    var $tbody = $("#wpec-lists-table tbody");
    if ($tbody.length && "MutationObserver" in window) {
      new MutationObserver(function () {
        decorateAllRows();
        toggleBulkbar();
      }).observe($tbody.get(0), { childList: true });
    }

    // initial pass
    decorateAllRows();
    toggleBulkbar();

    // Bulk: Apply (list move OR status update)
    $(document).on("click", "#wpec-bulk-apply", function (e) {
      e.preventDefault();

      const dest = $("#wpec-bulk-dest").val() || "";
      const $checks = $("#wpec-lists-table tbody .wpec-row-cb:checked");
      const ids = $checks
        .map(function () {
          return parseInt($(this).data("id"), 10) || 0;
        })
        .get()
        .filter(Boolean);

      if (!dest || !ids.length) return;

      // UI lock
      $("#wpec-bulk-apply").prop("disabled", true);
      $("#wpec-bulk-loader").show();

      // Helpers
      const done = () => {
        $("#wpec-bulk-loader").hide();
        $("#wpec-bulk-apply").prop("disabled", false);
        // clear selection & reload table
        $("#wpec-master-cb").prop("checked", false);
        setBulkState();
        // Re-run the current query (works whether filters used or not)
        $("#wpec-f-apply").trigger("click");
      };
      const fail = (msg) => {
        $("#wpec-bulk-loader").hide();
        $("#wpec-bulk-apply").prop("disabled", false);
        alert(msg || "Operation failed.");
      };

      // list:NN or status:unsubscribed|bounced|active
      if (dest.indexOf("list:") === 0) {
        const listId = parseInt(dest.split(":")[1], 10) || 0;
        if (!listId) return fail("Bad destination list.");

        $.post(
          WPEC.ajaxUrl,
          {
            action: "wpec_contacts_bulk_move",
            nonce: WPEC.nonce,
            ids: ids,
            list_id: listId,
          },
          function (res) {
            if (res && res.success) return done();
            fail(res && res.data && res.data.message);
          },
          "json"
        ).fail(function () {
          fail();
        });
      } else if (dest.indexOf("status:") === 0) {
        const status = dest.split(":")[1]; // unsubscribed | bounced | active
        const isRemove = status === "active"; // 'active' means remove from DND/Bounced

        $.post(
          WPEC.ajaxUrl,
          {
            action: "wpec_status_bulk_update",
            nonce: WPEC.nonce,
            ids: ids,
            mode: isRemove ? "remove" : "add",
            // 'status' is used only when mode = add; WP handler ignores it for remove.
            status: isRemove ? "unsubscribed" : status,
          },
          function (res) {
            if (res && res.success) return done();
            fail(res && res.data && res.data.message);
          },
          "json"
        ).fail(function () {
          fail();
        });
      }
    });

    // Delete selected
    $(document).on("click", "#wpec-bulk-delete", function () {
      var ids = currentSelection();
      if (!ids.length) return;
      if (!window.confirm("Delete selected contacts? This cannot be undone."))
        return;

      $("#wpec-bulk-loader").show();
      $.post(WPEC.ajaxUrl, {
        action: "wpec_contacts_bulk_delete",
        nonce: WPEC.nonce,
        contact_ids: ids,
      }).always(function () {
        $("#wpec-bulk-loader").hide();
        $("#wpec-master-cb").prop("checked", false);
        refreshTable();
      });
    });

    // Intercept the contacts AJAX to linkify lists after the table renders
    $(document).ajaxSuccess(function (_e, xhr, settings) {
      try {
        if (!settings || !settings.data) return;
        if (settings.url.indexOf("admin-ajax.php") === -1) return;
        if (settings.data.indexOf("action=wpec_contacts_query") === -1) return;

        var resp = xhr.responseJSON || JSON.parse(xhr.responseText);
        if (resp && resp.success && resp.data && resp.data.rows) {
          // Defer so the original renderer paints first
          setTimeout(function () {
            decorateAllRows();
            linkifyLists(resp.data.rows);
            toggleBulkbar();
          }, 0);
        }
      } catch (err) {}
    });
  }

  $(wireAllPage);

  /* ==== CONTACT DETAIL: chips, status, add-to-list, quick actions ==== */
  (function () {
    // Helper: get the contact id from the page root
    function currentContactId() {
      var $root = $("#wpec-contact-detail");
      return $root.length ? parseInt($root.data("contactId"), 10) || 0 : 0;
    }
    // Helper: title-case a status for small inline badge refresh
    function niceStatus(s) {
      if (!s) return "";
      return s.charAt(0).toUpperCase() + s.slice(1);
    }

    // 2) Change status (Active / Unsubscribed / Bounced)
    $(document).on("click", "#wpec-contact-status-apply", function (e) {
      e.preventDefault();
      var $btn = $(this);
      var contactId =
        parseInt($("#wpec-contact-detail").data("contactId"), 10) || 0;
      var status = ($("#wpec-contact-status-select").val() || "").trim();
      if (!contactId || !status) return;

      $btn.prop("disabled", true);
      $("#wpec-contact-status-loader").show();

      $.post(WPEC.ajaxUrl, {
        action: "wpec_status_bulk_update",
        nonce: WPEC.nonce,
        ids: [contactId],
        mode: status === "active" ? "remove" : "add",
        status: status,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            // Hard refresh so the header pill (#wpec-status-pill) always matches DB.
            location.reload();
          } else {
            alert(
              (resp && resp.data && resp.data.message) ||
                "Failed to update status."
            );
          }
        })
        .always(function () {
          $("#wpec-contact-status-loader").hide();
          $btn.prop("disabled", false);
        });
    });

    // 3) Add to list (dropdown + Add)
    // Add to list
    $(document).on("click", "#wpec-contact-addlist-apply", function (e) {
      e.preventDefault();
      var $btn = $(this);
      var contactId =
        parseInt($("#wpec-contact-detail").data("contactId"), 10) || 0;
      var $sel = $("#wpec-contact-addlist-select");
      var listId = parseInt($sel.val(), 10) || 0;
      if (!contactId || !listId) return;

      $btn.prop("disabled", true);
      $("#wpec-contact-addlist-loader").show();

      $.post(WPEC.ajaxUrl, {
        action: "wpec_contacts_bulk_move",
        nonce: WPEC.nonce,
        ids: [contactId],
        list_id: listId,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            var listName = $sel.find("option:selected").text();
            var base =
              WPEC && WPEC.listViewBase
                ? WPEC.listViewBase
                : window.ajaxurl
                ? window.ajaxurl.replace(
                    "admin-ajax.php",
                    "edit.php?page=wpec-lists&view=list&list_id="
                  )
                : "";
            var href = base ? base + String(listId) : "#";
            var chip =
              '<span class="wpec-chip" data-list-id="' +
              listId +
              '">' +
              '<a class="wpec-chip-link" href="' +
              href +
              '">' +
              $("<div>").text(listName).html() +
              "</a> " +
              '<button type="button" class="wpec-chip-close" aria-label="Remove" data-list-id="' +
              listId +
              '" data-contact-id="' +
              contactId +
              '">&times;</button>' +
              "</span>";
            $("#wpec-contact-memberships").append(chip);

            // prevent adding the same list again
            $sel.find('option[value="' + listId + '"]').remove();
            $sel.val("").trigger("change");
          } else {
            alert(
              (resp && resp.data && resp.data.message) ||
                "Failed to add to list."
            );
          }
        })
        .always(function () {
          $("#wpec-contact-addlist-loader").hide();
          $btn.prop("disabled", false);
        });
    });

    // Chip close: after removing mapping, put that list back into the dropdown
    $(document).on("click", ".wpec-chip-close", function (e) {
      e.preventDefault();
      var $btn = $(this);
      var listId = parseInt($btn.data("listId"), 10) || 0;
      var contactId = parseInt($btn.data("contactId"), 10) || 0;
      if (!listId || !contactId) return;
      if (!confirm("Remove this contact from this list?")) return;

      $btn.prop("disabled", true);
      $.post(WPEC.ajaxUrl, {
        action: "wpec_delete_list_mapping",
        nonce: WPEC.nonce,
        list_id: listId,
        contact_id: contactId,
      })
        .done(function (resp) {
          if (resp && resp.success) {
            // grab name before removing the chip
            var listName = (
              $btn.siblings(".wpec-chip-link").text() || ""
            ).trim();
            $btn.closest(".wpec-chip").remove();
            if (listName) {
              // reinsert option so it can be added again later
              var $sel = $("#wpec-contact-addlist-select");
              var opt = $("<option>", { value: listId, text: listName });
              // keep options alphabetic-ish
              var inserted = false;
              $sel.find("option").each(function () {
                if ($(this).text().toLowerCase() > listName.toLowerCase()) {
                  opt.insertBefore($(this));
                  inserted = true;
                  return false;
                }
              });
              if (!inserted) $sel.append(opt);
            }
          } else {
            alert(
              (resp && resp.data && resp.data.message) ||
                "Failed to remove from list."
            );
            $btn.prop("disabled", false);
          }
        })
        .fail(function () {
          alert("Request failed.");
          $btn.prop("disabled", false);
        });
    });

    // 4) Quick actions (status shortcuts)
    $(document).on("click", "#wpec-contact-set-active", function (e) {
      e.preventDefault();
      $("#wpec-contact-status-select").val("active");
      $("#wpec-contact-status-apply").trigger("click");
    });
    $(document).on("click", "#wpec-contact-mark-dnd", function (e) {
      e.preventDefault();
      $("#wpec-contact-status-select").val("unsubscribed");
      $("#wpec-contact-status-apply").trigger("click");
    });
    $(document).on("click", "#wpec-contact-mark-bounced", function (e) {
      e.preventDefault();
      $("#wpec-contact-status-select").val("bounced");
      $("#wpec-contact-status-apply").trigger("click");
    });

    // Optional: if you had “Quick Send / Log Activity” placeholder buttons, hide them for now
    $(".wpec-quick-send, .wpec-quick-log").hide();
  })();
})(jQuery);
// === Contact detail actions (sidebar) ===
jQuery(function () {
  var $wrap = jQuery("#wpec-contact-detail");
  if (!$wrap.length) return;
  var cid = parseInt($wrap.data("contactId"), 10) || 0;
  if (!cid) return;

  function busy(on) {
    jQuery("#wpec-contact-actions-loader").toggle(!!on);
  }

  // Status -> Active
  jQuery("#wpec-contact-set-active").on("click", function () {
    busy(true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_status_bulk_update",
        nonce: WPEC.nonce,
        mode: "remove", // remove from DND/Bounced => Active
        status: "unsubscribed", // ignored by server for "remove"
        ids: [cid],
      })
      .always(function () {
        busy(false);
        location.reload();
      });
  });

  // Status -> Do Not Send (unsubscribed)
  jQuery("#wpec-contact-mark-dnd").on("click", function () {
    busy(true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_status_bulk_update",
        nonce: WPEC.nonce,
        mode: "add",
        status: "unsubscribed",
        ids: [cid],
      })
      .always(function () {
        busy(false);
        location.reload();
      });
  });

  // Status -> Bounced
  jQuery("#wpec-contact-mark-bounced").on("click", function () {
    busy(true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_status_bulk_update",
        nonce: WPEC.nonce,
        mode: "add",
        status: "bounced",
        ids: [cid],
      })
      .always(function () {
        busy(false);
        location.reload();
      });
  });

  // Add to list
  jQuery("#wpec-contact-addlist-btn").on("click", function () {
    var listId =
      parseInt(jQuery("#wpec-contact-addlist-select").val(), 10) || 0;
    if (!listId) return;
    busy(true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_contacts_bulk_move",
        nonce: WPEC.nonce,
        contact_ids: [cid],
        list_id: listId,
      })
      .done(function (resp) {
        if (resp && resp.success) {
          location.reload();
        } else {
          alert((resp && resp.data && resp.data.message) || "Move failed.");
        }
      })
      .always(function () {
        busy(false);
      });
  });

  // Delete contact
  jQuery("#wpec-contact-delete").on("click", function () {
    if (!confirm("Delete this contact? This removes it from all lists."))
      return;
    busy(true);
    jQuery
      .post(WPEC.ajaxUrl, {
        action: "wpec_contacts_bulk_delete",
        nonce: WPEC.nonce,
        contact_ids: [cid],
      })
      .done(function (resp) {
        if (resp && resp.success) {
          window.location =
            WPEC && WPEC.adminBase
              ? WPEC.adminBase + "?page=wpec-contacts"
              : window.location.origin +
                "/wp-admin/edit.php?page=wpec-contacts";
        } else {
          alert((resp && resp.data && resp.data.message) || "Delete failed.");
        }
      })
      .always(function () {
        busy(false);
      });
  });

  // ===== Contact Detail page wiring =====

  var $detail = $("#wpec-contact-detail");
  if (!$detail.length) return;

  var cid = parseInt($detail.data("contact-id"), 10) || 0;
  function setPill(status) {
    var $pill = $("#wpec-status-pill");
    $pill
      .removeClass("is-active is-unsubscribed is-bounced")
      .addClass(
        status === "bounced"
          ? "is-bounced"
          : status === "unsubscribed"
          ? "is-unsubscribed"
          : "is-active"
      )
      .text(status);
  }

  // Status change
  $(document).on("change", "#wpec-contact-status", function () {
    var status = $(this).val();
    $("#wpec-contact-loader").show();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_contact_update_status",
      nonce: WPEC.nonce,
      contact_id: cid,
      status: status,
    })
      .done(function (res) {
        if (res && res.success) setPill(status);
        else alert((res && res.data && res.data.message) || "Update failed.");
      })
      .always(function () {
        $("#wpec-contact-loader").hide();
      });
  });

  // Add to list
  $(document).on("click", "#wpec-contact-add-btn", function (e) {
    e.preventDefault();
    var listId = parseInt($("#wpec-contact-add-list").val(), 10) || 0;
    if (!listId) return;
    $("#wpec-contact-loader").show();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_contact_add_to_list",
      nonce: WPEC.nonce,
      contact_id: cid,
      list_id: listId,
    })
      .done(function (res) {
        if (res && res.success && res.data) {
          var chip =
            '<span class="wpec-chip" data-list-id="' +
            res.data.list_id +
            '" data-contact-id="' +
            cid +
            '"><a href="' +
            res.data.list_url +
            '">' +
            res.data.list_name +
            '</a><button type="button" class="wpec-chip-remove" aria-label="Remove">&times;</button></span>';
          $("#wpec-list-chips").append(chip);
          $("#wpec-contact-add-list").val("");
        } else {
          alert((res && res.data && res.data.message) || "Add failed.");
        }
      })
      .always(function () {
        $("#wpec-contact-loader").hide();
      });
  });

  // Remove from list (uses existing AJAX endpoint)
  $(document).on("click", ".wpec-chip-remove", function () {
    var $chip = $(this).closest(".wpec-chip");
    var listId = parseInt($chip.data("listId"), 10) || 0;
    if (!listId || !cid) return;
    if (!confirm("Remove this contact from the list?")) return;

    $("#wpec-contact-loader").show();
    $.post(WPEC.ajaxUrl, {
      action: "wpec_delete_list_mapping",
      nonce: WPEC.nonce,
      list_id: listId,
      contact_id: cid,
    })
      .done(function (res) {
        if (res && res.success) $chip.remove();
        else alert((res && res.data && res.data.message) || "Remove failed.");
      })
      .always(function () {
        $("#wpec-contact-loader").hide();
      });
  });
});
