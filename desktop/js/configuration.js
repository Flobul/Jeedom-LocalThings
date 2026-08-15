"use strict";

(function () {
  const endpoint = "plugins/localthings/core/ajax/localthings.ajax.php";

  function request(action, data, success) {
    domUtils.ajax({
      type: "POST",
      url: endpoint,
      data: Object.assign({ action: action }, data || {}),
      dataType: "json",
      error: function (request, status, error) {
        handleAjaxError(request, status, error);
      },
      success: function (response) {
        if (response.state !== "ok") {
          jeedomUtils.showAlert({ message: response.result, level: "danger" });
          return;
        }
        if (typeof success === "function") {
          success(response.result);
        }
      },
    });
  }

  function setTransportState(result) {
    const state = document.getElementById("localthings-transport-state");
    if (!state) return;
    state.className = "label " + (result && result.ok ? "label-success" : "label-danger");
    state.textContent = result && result.ok
      ? "{{Disponible}} - " + (result.path || "OpenSSL")
      : "{{Indisponible}}";
  }

  function setCertificateState(result) {
    const state = document.getElementById("localthings-certificate-state");
    const detail = document.getElementById("localthings-certificate-detail");
    if (!state || !detail) return;
    const configured = Boolean(result && result.configured);
    state.className = "label " + (configured ? "label-success" : "label-warning");
    state.textContent = configured ? "{{Configurés}}" : "{{Non configurés}}";
    detail.textContent = configured
      ? [result.subject || "", result.expires ? "{{Expiration}} : " + result.expires : "", result.fingerprint ? "SHA-256 : " + result.fingerprint : ""].filter(Boolean).join(" | ")
      : (result && result.error ? result.error : "");
  }

  function loadCertificateState() {
    request("certificateStatus", {}, setCertificateState);
  }

  document.getElementById("bt_localthings_test_transport")?.addEventListener("click", function () {
    request("transportStatus", {}, setTransportState);
  });

  document.getElementById("bt_localthings_toggle_pem")?.addEventListener("click", function () {
    const panel = document.getElementById("localthings-pem-panel");
    if (panel) panel.style.display = panel.style.display === "none" ? "block" : "none";
  });

  document.getElementById("bt_localthings_bootstrap_certificates")?.addEventListener("click", function () {
    jeeDialog.confirm(
      "{{Le bundle communautaire sera téléchargé depuis son dépôt public puis stocké sur Jeedom. Continuer ?}}",
      function (confirmed) {
        if (!confirmed) return;
        request("bootstrapCertificates", {}, function (result) {
          setCertificateState(result);
          jeedomUtils.showAlert({ message: "{{Certificats LocalThings installés}}", level: "success" });
        });
      }
    );
  });

  document.getElementById("bt_localthings_install_certificates")?.addEventListener("click", function () {
    const certificate = document.getElementById("in_localthings_certificate")?.value.trim() || "";
    const privateKey = document.getElementById("in_localthings_private_key")?.value.trim() || "";
    if (!certificate || !privateKey) {
      jeedomUtils.showAlert({ message: "{{Le certificat et la clé privée sont obligatoires}}", level: "warning" });
      return;
    }
    request("installCertificates", { certificate: certificate, private_key: privateKey }, function (result) {
      document.getElementById("in_localthings_certificate").value = "";
      document.getElementById("in_localthings_private_key").value = "";
      setCertificateState(result);
      jeedomUtils.showAlert({ message: "{{Certificats LocalThings installés}}", level: "success" });
    });
  });

  request("transportStatus", {}, setTransportState);
  loadCertificateState();
})();

/* Indique les champs de configuration modifiés mais pas encore enregistrés. */
function printPluginConfiguration() {
  var divConfig = document.getElementById("configuration_plugin_localthings");
  var btnSave = document.getElementById("bt_savePluginConfig");
  if (!divConfig || !btnSave || divConfig.hasAttribute("data-localthings-tracked")) return;

  divConfig.setAttribute("data-localthings-tracked", "");

  var configInputs = divConfig.querySelectorAll(".configKey");
  var modificationCount = 0;
  var initialValues = new Map();
  var modificationMessage = document.createElement("i");

  modificationMessage.classList.add(
    "modificationWithoutSave",
    "label",
    "label-warning",
    "pull-right",
    "localthings-modification-message"
  );
  modificationMessage.innerHTML = "{{Modification en cours...}}";
  modificationMessage.unseen();
  btnSave.parentNode.insertBefore(modificationMessage, btnSave.nextSibling);

  function valueOf(input) {
    return input.type === "checkbox" ? input.checked : input.value;
  }

  function resetStyle(input) {
    input.style.setProperty("background-color", "", "important");
    input.style.setProperty("color", "", "important");
  }

  function setModifiedStyle(input) {
    input.style.setProperty("background-color", "var(--al-warning-color)", "important");
    input.style.setProperty("color", "var(--sc-lightTxt-color)", "important");
  }

  function updateModificationStatus() {
    if (modificationCount > 0) {
      modificationMessage.seen();
    } else {
      modificationMessage.unseen();
    }
  }

  configInputs.forEach(function (input) {
    resetStyle(input);
    initialValues.set(input, valueOf(input));

    var eventType = input.type === "checkbox" || input.nodeName === "SELECT" ? "change" : "input";
    input.addEventListener(eventType, function () {
      var isModified = valueOf(this) !== initialValues.get(this);
      var wasModified = this.hasAttribute("data-modified");

      if (isModified && !wasModified) {
        setModifiedStyle(this);
        this.setAttribute("data-modified", "");
        modificationCount++;
      } else if (!isModified && wasModified) {
        resetStyle(this);
        this.removeAttribute("data-modified");
        modificationCount--;
      }
      updateModificationStatus();
    });
  });

  btnSave.addEventListener("click", function () {
    configInputs.forEach(function (input) {
      initialValues.set(input, valueOf(input));
      resetStyle(input);
      input.removeAttribute("data-modified");
    });
    modificationCount = 0;
    modificationMessage.unseen();
  });
}

if (document.readyState === "loading") {
  document.addEventListener("DOMContentLoaded", printPluginConfiguration);
} else {
  setTimeout(printPluginConfiguration, 100);
}
