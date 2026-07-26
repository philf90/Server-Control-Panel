// Restzeit bis zum automatischen Rückbau einer Firewall-Änderung.
//
// Reine Anzeige: Der Rückbau selbst läuft serverseitig und hängt nicht davon
// ab, ob dieses Skript ausgeführt wird.
(function () {
  "use strict";

  var el = document.getElementById("fw-countdown");
  if (!el) {
    return;
  }

  var remaining = parseInt(el.getAttribute("data-seconds"), 10);
  if (isNaN(remaining)) {
    return;
  }

  var timer = setInterval(function () {
    remaining -= 1;
    if (remaining <= 0) {
      clearInterval(timer);
      el.textContent = "0";
      // Der Server hat inzwischen zurückgerollt; die Seite zeigt danach den
      // wiederhergestellten Stand.
      window.location.reload();
      return;
    }
    el.textContent = String(remaining);
  }, 1000);
})();
