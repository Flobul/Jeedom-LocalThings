"use strict";

(function () {
  const root = document.getElementById("div_localthings");
  const endpoint = "plugins/localthings/core/ajax/localthings.ajax.php";
  if (!root) return;

  function ajax(action, data, success, failure) {
    domUtils.ajax({
      type: "POST",
      url: endpoint,
      data: Object.assign({ action: action }, data || {}),
      dataType: "json",
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
        if (failure) failure(error);
      },
      success: function (response) {
        if (response.state !== "ok") {
          jeedomUtils.showAlert({ message: response.result, level: "danger" });
          if (failure) failure(response.result);
          return;
        }
        if (success) success(response.result);
      },
    });
  }

  let scanState = { running: false };
  let scanTimer = null;
  let scanPolling = false;
  let scanBusy = false;
  let scanGeneration = 0;

  function renderScan(status) {
    scanState = status || { running: false };
    const scan = document.getElementById("bt_scanLocalthings");
    const stopping = scanState.running && scanState.stop_requested;
    const icon = scan?.querySelector("i");
    const label = scan?.querySelector("span");
    if (icon) icon.className = scanState.running ? "fas fa-spinner fa-spin" : "fas fa-satellite-dish";
    if (label) label.textContent = stopping ? "{{Arrêt en cours…}}"
      : scanState.running ? "{{Découverte en cours}}" : "{{Découvrir}}";
    scan?.setAttribute("aria-busy", String(Boolean(scanState.running)));
    scan?.setAttribute("aria-disabled", String(scanBusy || Boolean(stopping)));
    const probe = document.getElementById("bt_probeLocalthings");
    if (probe) probe.disabled = scanBusy || Boolean(scanState.running);
    const progress = document.getElementById("localthings-scan-progress");
    const bar = progress?.querySelector(".progress-bar");
    if (progress && bar) {
      progress.style.display = scanState.running ? "block" : "none";
      bar.style.width = Number(scanState.progress || 0) + "%";
      bar.textContent = scanState.running
        ? Number(scanState.tested || 0) + "/" + Number(scanState.candidates || 0)
          + (scanState.current_host ? " · " + scanState.current_host : "") : "";
    }
  }

  function scheduleScan(delay) {
    window.clearTimeout(scanTimer);
    if (root.isConnected) scanTimer = window.setTimeout(pollScan, delay);
  }

  function pollScan() {
    if (!root.isConnected || scanPolling) return;
    scanPolling = true;
    const generation = scanGeneration;
    ajax("scanStatus", {}, function (status) {
      scanPolling = false;
      if (!root.isConnected) return;
      if (generation !== scanGeneration) { scheduleScan(500); return; }
      const wasRunning = scanState.running;
      renderScan(status);
      if (wasRunning && !status.running) {
        if (status.cancelled) {
          jeedomUtils.showAlert({ message: "{{Découverte arrêtée}}", level: "info" });
        } else if (Array.isArray(status.errors) && status.errors.length) {
          const message = document.createElement("div");
          message.textContent = status.errors.join(" · ");
          jeedomUtils.showAlert({ message: message.innerHTML, level: "warning" });
        } else {
          const found = Array.isArray(status.found) ? status.found.length : 0;
          jeedomUtils.showAlert({ message: found + " {{appareil(s) LocalThings découvert(s)}}", level: found ? "success" : "info" });
          if (found) window.setTimeout(function () { if (root.isConnected) window.location.reload(); }, 900);
        }
      }
      scheduleScan(status.running ? 1500 : 5000);
    }, function () { scanPolling = false; scheduleScan(5000); });
  }

  function scanAction(action, data) {
    scanBusy = true;
    scanGeneration++;
    renderScan(scanState);
    ajax(action, data, function (status) {
      scanBusy = false;
      renderScan(status);
      scheduleScan(250);
    }, function () {
      scanBusy = false;
      renderScan(scanState);
      scheduleScan(250);
    });
  }

  // Reprend le suivi après rechargement, et détecte les lancements dans un autre onglet.
  pollScan();

  root.addEventListener("click", function (event) {
    const scan = event.target.closest("#bt_scanLocalthings");
    if (scan) {
      if (scanBusy) return;
      scanBusy = true;
      scanGeneration++;
      renderScan(scanState);
      ajax("scanStatus", {}, function (status) {
        scanBusy = false;
        renderScan(status);
        if (status.running) {
          if (status.stop_requested) return;
          scanBusy = true;
          renderScan(status);
          jeeDialog.confirm("{{Une découverte est déjà en cours. Voulez-vous l’arrêter ?}}", function (confirmed) {
            scanBusy = false;
            renderScan(scanState);
            if (confirmed) scanAction("stopScan", { job_id: status.job_id });
          });
        } else {
          scanAction("scan", {});
        }
      }, function () { scanBusy = false; renderScan(scanState); scheduleScan(1500); });
      return;
    }

    const probe = event.target.closest("#bt_probeLocalthings");
    if (probe) {
      if (scanBusy || scanState.running) return;
      const host = document.getElementById("in_localthings_host")?.value.trim() || "";
      if (!host) {
        jeedomUtils.showAlert({ message: "{{Saisissez une adresse IPv4}}", level: "warning" });
        return;
      }
      scanAction("probe", { host: host });
      return;
    }

    if (event.target.closest("#bt_healthLocalthings")) {
      jeeDialog.dialog({
        id: "md_localthings_health",
        title: "{{Santé LocalThings}}",
        contentUrl: "index.php?v=d&plugin=localthings&modal=health",
      });
      return;
    }

    if (event.target.closest("#bt_resetSearch")) {
      const input = document.getElementById("in_searchEqlogic");
      if (input) {
        input.value = "";
        input.dispatchEvent(new Event("keyup"));
      }
      return;
    }

    if (event.target.closest("#bt_refreshLocalthings")) {
      const id = document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue();
      if (!id) return;
      ajax("refresh", { id: id }, function () {
        jeedomUtils.showAlert({ message: "{{État de l’appareil actualisé}}", level: "success" });
      });
      return;
    }

    const communicationTest = event.target.closest("#bt_testCommunicationLocalthings");
    if (communicationTest) {
      const id = document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue();
      if (!id) return;
      communicationTest.disabled = true;
      const icon = communicationTest.querySelector("i");
      icon?.classList.add("fa-spin");
      const resetCommunicationTest = function () {
        communicationTest.disabled = false;
        icon?.classList.remove("fa-spin");
      };
      ajax("testCommunication", { id: id }, function (result) {
        resetCommunicationTest();
        const lastCommunication = root.querySelector(
          '.eqLogicAttr[data-l1key="configuration"][data-l2key="last_communication"]'
        );
        const lastError = root.querySelector(
          '.eqLogicAttr[data-l1key="configuration"][data-l2key="last_error"]'
        );
        if (lastCommunication) lastCommunication.textContent = result.last_communication || "";
        if (lastError) lastError.textContent = result.last_error || "";
        jeedomUtils.showAlert({ message: result.message, level: "success" });
      }, resetCommunicationTest);
      window.setTimeout(function () {
        resetCommunicationTest();
      }, 20000);
    }
  });

  document.getElementById("in_searchEqlogic")?.addEventListener("keyup", function () {
    const search = this.value.toLowerCase().trim();
    root.querySelectorAll(".eqLogicDisplayCard").forEach(function (card) {
      card.style.display = !search || card.textContent.toLowerCase().includes(search) ? "" : "none";
    });
  });
})();

function addCmdToTable(_cmd) {
  if (!isset(_cmd)) _cmd = { configuration: {} };
  if (!isset(_cmd.configuration)) _cmd.configuration = {};

  let html = '<tr class="cmd" data-cmd_id="' + init(_cmd.id) + '">';
  html += '<td class="hidden-xs"><span class="cmdAttr" data-l1key="id"></span></td>';
  html += '<td><div class="input-group">';
  html += '<input class="cmdAttr form-control input-sm roundedLeft" data-l1key="name">';
  html += '<span class="input-group-btn"><a class="btn btn-default btn-sm cmdAction roundedRight" data-l1key="chooseIcon" title="{{Choisir une icône}}"><i class="fas fa-icons"></i></a></span>';
  html += '</div><span class="cmdAttr" data-l1key="display" data-l2key="icon"></span>';
  html += '<select class="cmdAttr form-control input-sm" data-l1key="value" style="display:none"><option value="">{{Aucune}}</option></select></td>';
  html += '<td><span class="type" type="' + init(_cmd.type) + '">' + jeedom.cmd.availableType() + '</span><span class="subType" subType="' + init(_cmd.subType) + '"></span></td>';
  html += '<td>';
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isVisible">{{Afficher}}</label> ';
  html += '<label class="checkbox-inline"><input type="checkbox" class="cmdAttr" data-l1key="isHistorized">{{Historiser}}</label>';
  if (init(_cmd.subType) === "numeric" || init(_cmd.subType) === "slider") {
    html += '<div class="input-group">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="minValue" placeholder="{{Min}}">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="maxValue" placeholder="{{Max}}">';
    html += '<input class="cmdAttr form-control input-sm" data-l1key="unite" placeholder="{{Unité}}">';
    html += '</div>';
  }
  if (init(_cmd.subType) === "select") {
    html += '<input class="cmdAttr form-control input-sm" data-l1key="configuration" data-l2key="listValue" placeholder="{{Valeur|Libellé;...}}">';
  }
  html += '</td>';
  html += '<td>' + (init(_cmd.type) === "info" ? '<span class="cmdAttr" data-l1key="htmlstate"></span>' : "") + '</td>';
  html += '<td><div class="input-group" style="display:inline-flex">';
  if (is_numeric(_cmd.id) && _cmd.id !== "") {
    html += '<a class="btn btn-default btn-xs cmdAction roundedLeft" data-action="configure" title="{{Configuration avancée}}"><i class="fas fa-cogs"></i></a>';
    html += '<a class="btn btn-success btn-xs cmdAction" data-action="test" title="{{Tester}}"><i class="fas fa-rss"></i></a>';
  }
  html += '<a class="btn btn-danger btn-xs cmdAction roundedRight" data-action="remove" title="{{Supprimer}}"><i class="fas fa-minus-circle"></i></a>';
  html += '</div></td></tr>';

  const holder = document.createElement("tbody");
  holder.innerHTML = html;
  const row = holder.firstElementChild;
  document.querySelector("#table_cmd tbody").appendChild(row);
  jeedom.eqLogic.buildSelectCmd({
    id: document.querySelector('.eqLogicAttr[data-l1key="id"]')?.jeeValue(),
    filter: { type: "info" },
    error: function (error) {
      jeedomUtils.showAlert({ message: error.message, level: "danger" });
    },
    success: function (result) {
      row.querySelector('.cmdAttr[data-l1key="value"]')?.insertAdjacentHTML("beforeend", result);
      row.setJeeValues(_cmd, ".cmdAttr");
      jeedom.cmd.changeType(row, init(_cmd.subType));
    },
  });
}
