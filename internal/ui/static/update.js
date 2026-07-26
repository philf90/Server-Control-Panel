// Begleitet einen Update-Lauf über den Neustart des Panels hinweg.
//
// Anders als beim Paketvorgang gibt es hier keinen offenen Kanal: Der Dienst,
// der ihn bedienen würde, startet mitten im Vorgang neu. Stattdessen wird
// gefragt — und ein fehlgeschlagener Abruf ist keine Störung, sondern der zu
// erwartende Zustand während des Neustarts.
(function () {
  "use strict";

  var script = document.currentScript;
  if (!script || script.getAttribute("data-running") !== "1") {
    return;
  }

  var output = document.getElementById("update-output");
  var status = document.getElementById("update-status");
  var startedWith = script.getAttribute("data-current") || "";

  var interval = 2000;
  // Großzügig bemessen: Download, Austausch, Neustart und die
  // Bereitschaftsprüfung samt möglichem Rückweg brauchen zusammen im
  // schlechtesten Fall einige Minuten.
  var deadline = Date.now() + 8 * 60 * 1000;
  var wasUnreachable = false;

  function setStatus(text) {
    if (status) {
      status.textContent = text;
    }
  }

  function show(lines) {
    if (!output || !lines) {
      return;
    }
    var atBottom = output.scrollHeight - output.scrollTop - output.clientHeight < 40;
    output.textContent = lines.join("\n") + "\n";
    if (atBottom) {
      output.scrollTop = output.scrollHeight;
    }
  }

  function poll() {
    if (Date.now() > deadline) {
      setStatus("keine Rückmeldung — bitte die Seite neu laden");
      return;
    }

    fetch("/update/status", { cache: "no-store", credentials: "same-origin" })
      .then(function (resp) {
        if (!resp.ok) {
          throw new Error("HTTP " + resp.status);
        }
        return resp.json();
      })
      .then(function (data) {
        show(data.lines);

        // Eine andere Fassung als beim Laden der Seite heißt: Der Dienst ist
        // neu gestartet und bedient wieder Anfragen.
        if (wasUnreachable && data.version !== startedWith) {
          setStatus("Fassung " + data.version + " ist da — die Seite wird neu geladen");
          setTimeout(function () {
            window.location.reload();
          }, 1200);
          return;
        }
        if (wasUnreachable) {
          setStatus("wieder erreichbar — die Seite wird neu geladen");
          setTimeout(function () {
            window.location.reload();
          }, 1200);
          return;
        }
        setStatus("läuft …");
        setTimeout(poll, interval);
      })
      .catch(function () {
        // Genau hier startet der Dienst neu. Das ist der Normalfall.
        wasUnreachable = true;
        setStatus("das Panel startet neu …");
        setTimeout(poll, interval);
      });
  }

  setTimeout(poll, interval);
})();
