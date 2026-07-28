// Upload mit Fortschrittsanzeige und Ablagefläche.
//
// Warum XMLHttpRequest und nicht fetch: fetch kennt keinen Fortschritt beim
// Senden. Bei einer Datei von zwei Gigabyte ist ein Balken kein Schmuck — ohne
// ihn weiß niemand, ob die Leitung arbeitet oder die Verbindung längst hängt.
//
// Ohne dieses Skript funktioniert das Formular unverändert weiter: Der Server
// nimmt denselben Multipart-Körper an und antwortet mit der Seite statt mit
// JSON. Der Balken fehlt dann, der Upload nicht.
(function () {
  "use strict";

  var form = document.getElementById("upload-form");
  if (!form) return;

  var feld = document.getElementById("upload-datei");
  var balken = document.getElementById("upload-fortschritt");
  var stand = document.getElementById("upload-stand");
  var ablage = document.getElementById("ablage");

  function bytes(n) {
    var einheiten = ["B", "KiB", "MiB", "GiB", "TiB"];
    var i = 0;
    while (n >= 1024 && i < einheiten.length - 1) {
      n /= 1024;
      i++;
    }
    return (i === 0 ? n : n.toFixed(1)) + " " + einheiten[i];
  }

  function melden(text) {
    if (stand) stand.textContent = text;
  }

  form.addEventListener("submit", function (ereignis) {
    if (!feld || !feld.files || feld.files.length === 0) return;
    ereignis.preventDefault();

    // Dieselbe Reihenfolge wie im Markup: Token und Zielverzeichnis vor den
    // Dateien. Der Server liest den Körper in dieser Reihenfolge und prüft den
    // Token, bevor Inhalt fließt.
    var daten = new FormData();
    daten.append("_csrf", form.elements._csrf.value);
    daten.append("dir", form.elements.dir.value);
    if (form.elements.overwrite && form.elements.overwrite.checked) {
      daten.append("overwrite", "1");
    }
    var gesamt = 0;
    for (var i = 0; i < feld.files.length; i++) {
      daten.append("file", feld.files[i]);
      gesamt += feld.files[i].size;
    }

    var anfrage = new XMLHttpRequest();
    anfrage.open("POST", form.action, true);
    anfrage.setRequestHeader("Accept", "application/json");
    // Der Token zusätzlich als Kopfzeile: Der Server nimmt beide Wege, und über
    // die Kopfzeile steht er fest, bevor der erste Teil gelesen ist.
    anfrage.setRequestHeader("X-CSRF-Token", form.elements._csrf.value);

    if (balken) {
      balken.hidden = false;
      balken.value = 0;
    }
    form.querySelectorAll("button, input").forEach(function (el) {
      el.disabled = true;
    });

    anfrage.upload.addEventListener("progress", function (e) {
      if (!e.lengthComputable) return;
      var anteil = Math.round((e.loaded / e.total) * 100);
      if (balken) balken.value = anteil;
      melden(anteil + " % — " + bytes(e.loaded) + " von " + bytes(e.total));
    });

    anfrage.addEventListener("load", function () {
      var antwort = {};
      try {
        antwort = JSON.parse(anfrage.responseText);
      } catch (e) {
        antwort = {};
      }
      if (anfrage.status === 200 && antwort.ok) {
        melden("fertig — die Liste wird neu geladen");
        window.location.reload();
        return;
      }
      // Der Fehler bleibt stehen, und die Auswahl auch: Wer eine zu große Datei
      // erwischt hat, soll nicht alles neu zusammensuchen müssen.
      melden(antwort.error || "Der Upload ist fehlgeschlagen (Status " + anfrage.status + ").");
      if (balken) balken.hidden = true;
      form.querySelectorAll("button, input").forEach(function (el) {
        el.disabled = false;
      });
    });

    anfrage.addEventListener("error", function () {
      melden("Die Verbindung ist abgebrochen.");
      if (balken) balken.hidden = true;
      form.querySelectorAll("button, input").forEach(function (el) {
        el.disabled = false;
      });
    });

    melden("Upload läuft: " + bytes(gesamt));
    anfrage.send(daten);
  });

  // Ablagefläche. Ohne preventDefault öffnet der Browser die Datei selbst,
  // statt sie dem Formular zu geben.
  if (!ablage || !feld) return;
  ["dragenter", "dragover"].forEach(function (art) {
    ablage.addEventListener(art, function (e) {
      e.preventDefault();
      ablage.classList.add("bereit");
    });
  });
  ["dragleave", "drop"].forEach(function (art) {
    ablage.addEventListener(art, function () {
      ablage.classList.remove("bereit");
    });
  });
  ablage.addEventListener("drop", function (e) {
    e.preventDefault();
    if (!e.dataTransfer || !e.dataTransfer.files.length) return;
    feld.files = e.dataTransfer.files;
    melden(e.dataTransfer.files.length + " Datei(en) ausgewählt");
  });
})();
