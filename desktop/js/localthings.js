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

  function pollScan() {
    ajax("scanStatus", {}, function (status) {
      const progress = document.getElementById("localthings-scan-progress");
      const bar = progress?.querySelector(".progress-bar");
      if (progress && bar) {
        progress.style.display = status.running ? "block" : "none";
        bar.style.width = Number(status.progress || 0) + "%";
        bar.textContent = status.running
          ? Number(status.tested || 0) + "/" + Number(status.candidates || 0)
          : "";
      }
      if (status.running) {
        window.setTimeout(pollScan, 1500);
      } else {
        const found = Array.isArray(status.found) ? status.found.length : 0;
        if (found > 0) {
          jeedomUtils.showAlert({
            message: found + " {{appareil(s) LocalThings découvert(s)}}",
            level: "success",
          });
          window.setTimeout(function () { window.location.reload(); }, 900);
        } else if (Array.isArray(status.errors) && status.errors.length > 0) {
          jeedomUtils.showAlert({ message: status.errors.join("<br>"), level: "warning" });
        } else {
          jeedomUtils.showAlert({ message: "{{Découverte terminée, aucun nouvel appareil trouvé}}", level: "info" });
        }
      }
    });
  }

  root.addEventListener("click", function (event) {
    const scan = event.target.closest("#bt_scanLocalthings");
    if (scan) {
      ajax("scan", {}, function () {
        jeedomUtils.showAlert({ message: "{{Découverte LocalThings démarrée}}", level: "success" });
        pollScan();
      });
      return;
    }

    const probe = event.target.closest("#bt_probeLocalthings");
    if (probe) {
      const host = document.getElementById("in_localthings_host")?.value.trim() || "";
      if (!host) {
        jeedomUtils.showAlert({ message: "{{Saisissez une adresse IPv4}}", level: "warning" });
        return;
      }
      probe.disabled = true;
      ajax("probe", { host: host }, function () {
        jeedomUtils.showAlert({ message: "{{Analyse de l’adresse démarrée}}", level: "success" });
        pollScan();
      });
      window.setTimeout(function () { probe.disabled = false; }, 5000);
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
