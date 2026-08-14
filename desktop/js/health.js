"use strict";

(function () {
  const root = document.getElementById("div_healthLocalthings");
  if (!root) return;

  function reloadHealth() {
    jeeDialog.dialog({
      id: "md_localthings_health",
      title: "{{Santé LocalThings}}",
      contentUrl: "index.php?v=d&plugin=localthings&modal=health",
    });
  }

  root.addEventListener("click", function (event) {
    if (event.target.closest("#bt_refreshHealthLocalthings")) {
      reloadHealth();
      return;
    }

    const button = event.target.closest(".bt_testHealthCommunication");
    if (!button) return;
    button.disabled = true;
    button.querySelector("i")?.classList.add("fa-spin");
    domUtils.ajax({
      type: "POST",
      url: "plugins/localthings/core/ajax/localthings.ajax.php",
      data: {
        action: "testCommunication",
        id: button.getAttribute("data-eqlogic_id"),
      },
      dataType: "json",
      error: function (request, status, error) {
        button.disabled = false;
        button.querySelector("i")?.classList.remove("fa-spin");
        handleAjaxError(request, status, error);
      },
      success: function (response) {
        if (response.state !== "ok") {
          button.disabled = false;
          button.querySelector("i")?.classList.remove("fa-spin");
          jeedomUtils.showAlert({ message: response.result, level: "danger" });
          return;
        }
        jeedomUtils.showAlert({ message: response.result.message, level: "success" });
        reloadHealth();
      },
    });
  });
})();
