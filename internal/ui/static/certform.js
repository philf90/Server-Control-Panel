// Zeigt auf der Zertifikatsseite nur die Felder, die zur getroffenen Auswahl
// gehören.
//
// Bewusst eine Verbesserung und keine Voraussetzung: Ohne dieses Skript ist
// alles sichtbar und das Formular vollständig bedienbar — die Beschriftungen
// sagen dann, was für welchen Anbieter gilt. Ausgewertet wird ohnehin
// serverseitig; hier wird nichts geprüft und nichts weggelassen, was gesendet
// würde.
(function () {
  "use strict";

  var form = document.querySelector('form[action="/alt/certificate"]');
  if (!form) {
    return;
  }

  var modus = form.querySelectorAll('input[name="mode"]');
  var anbieter = form.querySelector('select[name="provider"]');
  if (!modus.length || !anbieter) {
    return;
  }

  // Die Blöcke, die nur im Modus "acme" etwas zu sagen haben. Der erste Block
  // (Betriebsart) bleibt immer stehen.
  var acmeBloecke = Array.prototype.slice.call(
    form.querySelectorAll("fieldset.rule")
  ).slice(1);

  function gewaehlterModus() {
    for (var i = 0; i < modus.length; i++) {
      if (modus[i].checked) {
        return modus[i].value;
      }
    }
    return "selfsigned";
  }

  function zeige(el, sichtbar) {
    if (el) {
      el.hidden = !sichtbar;
    }
  }

  function feldVon(name) {
    var el = form.querySelector('[name="' + name + '"]');
    return el ? el.closest(".feld") : null;
  }

  function anwenden() {
    var acme = gewaehlterModus() === "acme";
    acmeBloecke.forEach(function (block) {
      zeige(block, acme);
    });

    var p = anbieter.value;
    zeige(feldVon("hook_set"), acme && p === "hook");
    zeige(feldVon("hook_clean"), acme && p === "hook");
    zeige(feldVon("cf_token"), acme && p === "cloudflare");

    // Steht kein Anbieter fest, hat der ganze Block nichts zu zeigen.
    var zugang = anbieter.closest("form").querySelector(".zugang");
    zeige(zugang, acme && p !== "");
  }

  Array.prototype.forEach.call(modus, function (el) {
    el.addEventListener("change", anwenden);
  });
  anbieter.addEventListener("change", anwenden);
  anwenden();
})();
