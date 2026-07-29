// Rückfrage vor zerstörenden Aktionen — als Dialog auf der Seite.
//
// Warum dieses Skript überhaupt existiert: Bis hierher stand die Rückfrage in
// einem Attribut (onsubmit="return confirm(…)"). Die Content-Security-Policy des
// Panels ist `script-src 'self'` ohne 'unsafe-inline', und der Browser verwirft
// so ein Attribut, bevor es einmal läuft — im Browser nachgemessen: kein Dialog,
// und das Konto war nach einem Klick weg. Dreizehn Stellen waren so gebaut.
//
// Ein Skript aus dem Binary darf, was das Attribut nicht durfte. Es hängt an
// jedem Formular mit data-bestaetigen (oder an dem Knopf, der es abschickt) und
// hält das Absenden auf, bis bestätigt wurde.
//
// Es ist trotzdem nur die bequeme Fassung. Verbindlich ist der Server: Der
// Handler führt nichts aus, solange das Feld "bestaetigt" fehlt, und antwortet
// sonst mit einer Zwischenseite. Ohne dieses Skript ist genau das der Weg — eine
// Seite mehr, dieselbe Sicherheit. Ein selbstgebauter POST kommt an diesem
// Skript vorbei und an der Prüfung im Handler nicht.
//
// Angaben im Markup (am Formular oder am Knopf; der Knopf gewinnt, weil ein
// Formular mehrere haben kann — siehe formaction auf Panel-Zugänge):
//
//   data-bestaetigen          der Satz, der sagt, was passiert (Pflicht)
//   data-bestaetigen-titel    Überschrift des Dialogs
//   data-bestaetigen-knopf    Beschriftung des bestätigenden Knopfes
//   data-bestaetigen-tippen   Wort, das eingegeben werden muss (dritte Stufe)
//   data-bestaetigen-hinweis  Erklärung dazu
(function () {
  "use strict";

  function el(art, klasse, text) {
    var n = document.createElement(art);
    if (klasse) n.className = klasse;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  // Die Angaben stehen am Knopf oder am Formular. Ein Formular mit drei Knöpfen
  // und drei formactions (Zugang zurücksetzen) braucht drei verschiedene Fragen.
  function angaben(form, knopf) {
    var q = (knopf && knopf.dataset.bestaetigen) || form.dataset.bestaetigen;
    if (!q) return null;
    function wert(name) {
      if (knopf && knopf.dataset[name]) return knopf.dataset[name];
      return form.dataset[name] || "";
    }
    return {
      frage: q,
      titel: wert("bestaetigenTitel") || "Bestätigung",
      knopf: wert("bestaetigenKnopf") || "fortfahren",
      tippen: wert("bestaetigenTippen"),
      hinweis: wert("bestaetigenHinweis"),
    };
  }

  function feldSetzen(form, name, wert) {
    var f = form.querySelector('input[type="hidden"][name="' + name + '"]');
    if (!f) {
      f = document.createElement("input");
      f.type = "hidden";
      f.name = name;
      form.appendChild(f);
    }
    f.value = wert;
  }

  // Der Dialog wird einmal gebaut und wiederverwendet: Zwanzig Zeilen in einer
  // Liste sollen nicht zwanzig <dialog> im Dokument bedeuten.
  var dialog = null;
  var teile = null;

  function bauen() {
    dialog = document.createElement("dialog");
    dialog.className = "frage-dialog";

    var titel = el("h2", "frage-titel");
    var text = el("p", "frage-text");

    var tippbox = el("div", "frage-tippen");
    var beschriftung = el("label", "frage-label");
    beschriftung.setAttribute("for", "frage-eingabe");
    var eingabe = document.createElement("input");
    eingabe.type = "text";
    eingabe.id = "frage-eingabe";
    eingabe.autocomplete = "off";
    eingabe.setAttribute("autocapitalize", "off");
    eingabe.setAttribute("autocorrect", "off");
    eingabe.spellcheck = false;
    tippbox.appendChild(beschriftung);
    tippbox.appendChild(eingabe);

    var reihe = el("div", "button-row frage-knoepfe");
    var ja = el("button", "danger");
    ja.type = "button";
    var nein = el("button", "secondary", "abbrechen");
    nein.type = "button";
    reihe.appendChild(ja);
    reihe.appendChild(nein);

    dialog.appendChild(titel);
    dialog.appendChild(text);
    dialog.appendChild(tippbox);
    dialog.appendChild(reihe);
    document.body.appendChild(dialog);

    teile = { titel: titel, text: text, tippbox: tippbox, beschriftung: beschriftung, eingabe: eingabe, ja: ja, nein: nein };
  }

  function fragen(a, weiter) {
    if (!dialog) bauen();
    if (!dialog.showModal) {
      // Uralter Browser ohne <dialog>: window.confirm aus einem Skript ist
      // erlaubt — verworfen wird nur der Inline-Handler. Die getippte
      // Bestätigung entfällt dabei; sie kommt dann von der Zwischenseite, denn
      // der Server verlangt sie weiter.
      if (window.confirm(a.frage)) weiter("");
      return;
    }

    teile.titel.textContent = a.titel;
    teile.text.textContent = a.frage;
    teile.ja.textContent = a.knopf;

    var tippen = a.tippen;
    teile.tippbox.hidden = !tippen;
    teile.eingabe.value = "";
    if (tippen) {
      teile.beschriftung.textContent = a.hinweis || "Zum Bestätigen " + tippen + " eingeben";
      teile.eingabe.placeholder = tippen;
    }

    function passt() {
      if (!tippen) return true;
      return teile.eingabe.value.trim().toLowerCase() === tippen.toLowerCase();
    }
    function pruefen() {
      teile.ja.disabled = !passt();
    }
    function absenden() {
      if (!passt()) return;
      schliessen();
      weiter(teile.eingabe.value);
    }
    function schliessen() {
      teile.eingabe.removeEventListener("input", pruefen);
      teile.eingabe.removeEventListener("keydown", aufEnter);
      teile.ja.removeEventListener("click", absenden);
      teile.nein.removeEventListener("click", schliessen);
      dialog.close();
    }
    function aufEnter(e) {
      if (e.key === "Enter") {
        e.preventDefault();
        absenden();
      }
    }

    pruefen();
    teile.eingabe.addEventListener("input", pruefen);
    teile.eingabe.addEventListener("keydown", aufEnter);
    teile.ja.addEventListener("click", absenden);
    teile.nein.addEventListener("click", schliessen);

    dialog.showModal();
    // Escape schließt von selbst — das ist der Abbruch, und abgebrochen heißt:
    // kein POST. Der gefährliche Knopf bekommt bewusst nicht den Fokus.
    if (tippen) teile.eingabe.focus();
    else teile.nein.focus();
  }

  document.addEventListener("submit", function (e) {
    var form = e.target;
    if (!(form instanceof HTMLFormElement)) return;
    if (form.dataset.bestaetigtDurch === "1") {
      // Der zweite Durchlauf nach dem Dialog: durchlassen.
      delete form.dataset.bestaetigtDurch;
      return;
    }
    var knopf = e.submitter;
    var a = angaben(form, knopf);
    if (!a) return;

    e.preventDefault();
    fragen(a, function (getippt) {
      feldSetzen(form, "bestaetigt", "1");
      if (a.tippen) feldSetzen(form, "tippen", getippt);
      form.dataset.bestaetigtDurch = "1";
      // requestSubmit statt submit: submit() ignoriert das formaction des
      // Knopfes, und auf Panel-Zugänge entscheidet genau das, welche
      // Zurücksetzung gemeint ist. Ein submit() hätte dort immer die erste
      // ausgeführt.
      if (form.requestSubmit) form.requestSubmit(knopf || undefined);
      else form.submit();
    });
  });
})();
