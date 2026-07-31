// Live-Ausgabe eines laufenden Paketvorgangs.
//
// Der Vorgang läuft serverseitig weiter, auch wenn diese Seite geschlossen
// wird — hier wird nur mitgelesen.
(function () {
  "use strict";

  var output = document.getElementById("job-output");
  var status = document.getElementById("job-status");
  if (!output) {
    return;
  }

  // Die Quelle steht am Element, nicht hier: Dasselbe Skript liest den
  // Paketvorgang und die ufw-Installation mit.
  var source = new EventSource(output.dataset.events || "/alt/packages/events");

  source.addEventListener("output", function (event) {
    var line;
    try {
      line = JSON.parse(event.data);
    } catch (err) {
      return;
    }
    var atBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 40;
    output.textContent += line + "\n";
    // Nur mitscrollen, wenn der Betrachter ohnehin am Ende steht — sonst
    // reißt es ihn beim Zurückblättern ständig nach unten.
    if (atBottom) {
      output.scrollTop = output.scrollHeight;
    }
  });

  source.addEventListener("end", function (event) {
    var result = "ok";
    try {
      result = JSON.parse(event.data);
    } catch (err) {
      /* Vorgabe behalten */
    }
    if (status) {
      status.textContent = result === "ok" ? "abgeschlossen" : "fehlgeschlagen: " + result;
    }
    source.close();
    // Die Seite neu laden, damit die Paketliste den neuen Stand zeigt.
    setTimeout(function () {
      window.location.reload();
    }, 1500);
  });

  source.addEventListener("error", function () {
    if (status) {
      status.textContent = "Verbindung unterbrochen — der Vorgang läuft weiter";
    }
  });
})();
