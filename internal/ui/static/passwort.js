// Passwortprüfung beim Tippen: Stärkeschätzung und Haken je Regel.
//
// Die Regeln sind dieselben wie in internal/auth (CheckPasswordPolicy) — sie
// müssen es sein, sonst zeigt die Seite grün und der Server lehnt ab. Deshalb
// steht hier keine einzige Zahl: Mindestlänge und Obergrenze kommen aus
// data-pw-min und data-pw-max, gerendert aus auth.Policy(). Und deshalb prüft
// der Server weiter selbst — diese Anzeige ist eine Hilfe, keine Kontrolle.
//
// Die Schlüssel in data-pw-regel entsprechen den PasswordRuleKey-Konstanten.
(function () {
  "use strict";

  // Zeichenraum je vorhandener Gruppe. Keine Vorschrift, sondern die Grundlage
  // der Schätzung: Wer nur Kleinbuchstaben nimmt, braucht mehr Länge.
  var GRUPPEN = [
    { re: /[a-z]/, groesse: 26 },
    { re: /[A-Z]/, groesse: 26 },
    { re: /[0-9]/, groesse: 10 },
    { re: /[ \t]/, groesse: 1 },
    { re: /[^A-Za-z0-9 \t]/, groesse: 33 },
  ];

  function zeichenraum(wert) {
    var raum = 0;
    GRUPPEN.forEach(function (g) {
      if (g.re.test(wert)) {
        raum += g.groesse;
      }
    });
    return raum || 1;
  }

  // Grobe Schätzung des Ratewiderstands in Bit: Länge mal Zeichenraum, gedämpft
  // um den Anteil verschiedener Zeichen. Ohne die Dämpfung käme "abababababab"
  // auf denselben Wert wie zwölf zufällige Kleinbuchstaben.
  function bits(wert) {
    var zeichen = Array.from(wert);
    if (zeichen.length === 0) {
      return 0;
    }
    var verschieden = new Set(zeichen).size;
    var vielfalt = 0.35 + 0.65 * (verschieden / zeichen.length);
    return zeichen.length * (Math.log(zeichenraum(wert)) / Math.log(2)) * vielfalt;
  }

  // Die Stufen sind bewusst grob: Eine Zahl mit Nachkommastelle würde eine
  // Genauigkeit behaupten, die eine Schätzung nicht hat.
  function stufe(b) {
    if (b < 40) return { klasse: "schwach", wort: "schwach" };
    if (b < 60) return { klasse: "mittel", wort: "mittel" };
    if (b < 80) return { klasse: "gut", wort: "gut" };
    return { klasse: "stark", wort: "stark" };
  }

  // --- die Regeln, eine Übersetzung von auth.CheckPasswordPolicy ------------

  function nurWiederholung(zeichen) {
    return zeichen.every(function (z) {
      return z === zeichen[0];
    });
  }

  // Eine Folge ist erst eine, wenn sie über das ganze Passwort läuft. Unter vier
  // Zeichen wird nicht geprüft — siehe PasswordIsTrivial in Go.
  function istFolge(zeichen) {
    if (zeichen.length < 4) {
      return false;
    }
    var auf = true;
    var ab = true;
    for (var i = 1; i < zeichen.length; i++) {
      var vor = zeichen[i - 1].codePointAt(0);
      var jetzt = zeichen[i].codePointAt(0);
      if (jetzt !== vor + 1) auf = false;
      if (jetzt !== vor - 1) ab = false;
    }
    return auf || ab;
  }

  function pruefe(wert, min, max, name) {
    var zeichen = Array.from(wert);
    var bytes = new TextEncoder().encode(wert).length;
    var trivial = zeichen.length >= 2 && (nurWiederholung(zeichen) || istFolge(zeichen));

    var wieName = true;
    if (name) {
      wieName = wert.toLowerCase().indexOf(name.toLowerCase()) < 0;
    }

    return {
      laenge: zeichen.length >= min,
      hoechstlaenge: bytes <= max,
      nichtname: wieName,
      abwechslung: !trivial,
      // Kein Name bekannt: Die Regel lässt sich hier nicht beantworten, der
      // Server tut es. Dann bleibt sie unentschieden statt falsch grün.
      offen: name ? [] : ["nichtname"],
    };
  }

  function ausstatten(box) {
    var feld = document.getElementById(box.dataset.pwFeld);
    if (!feld) {
      return;
    }
    var min = parseInt(box.dataset.pwMin, 10) || 0;
    var max = parseInt(box.dataset.pwMax, 10) || 0;
    var balken = box.querySelector(".pwbalken");
    var wort = box.querySelector(".pwwort");
    var zeilen = box.querySelectorAll("[data-pw-regel]");

    // Der Anmeldename kann aus der Seite kommen (angemeldet) oder aus dem
    // Formular daneben (Ersteinrichtung). Beim Tippen im Namensfeld wird die
    // Regel neu bewertet.
    var namensfeld = null;
    if (!box.dataset.pwName && feld.form) {
      namensfeld = feld.form.querySelector('input[name="username"]');
    }

    function name() {
      if (box.dataset.pwName) {
        return box.dataset.pwName;
      }
      return namensfeld ? namensfeld.value.trim() : "";
    }

    function zeichnen() {
      var wert = feld.value;

      // Kein Wert, keine Aussage. Ein leeres Feld mit vier roten Kreuzen sieht
      // aus wie eine Ablehnung, bevor überhaupt etwas eingegeben wurde — die
      // Regeln stehen dann als bloße Liste da, so wie ohne dieses Skript.
      if (wert === "") {
        zeilen.forEach(function (li) {
          li.classList.remove("erfuellt", "verletzt", "unentschieden");
        });
        balken.value = 0;
        balken.className = "bar pwbalken";
        wort.textContent = "noch keine Eingabe";
        return;
      }

      var ergebnis = pruefe(wert, min, max, name());
      var verletzt = false;

      zeilen.forEach(function (li) {
        var key = li.dataset.pwRegel;
        li.classList.remove("erfuellt", "verletzt", "unentschieden");
        if (ergebnis.offen.indexOf(key) >= 0) {
          li.classList.add("unentschieden");
          return;
        }
        if (ergebnis[key]) {
          li.classList.add("erfuellt");
          return;
        }
        li.classList.add("verletzt");
        verletzt = true;
      });

      var b = bits(wert);
      var s = stufe(b);
      // 90 Bit sind das Ende der Skala, nicht das Maximum des Möglichen: Alles
      // darüber ist ohnehin außer Reichweite.
      balken.value = Math.max(4, Math.min(100, Math.round((b / 90) * 100)));

      // Eine verletzte Regel schlägt die Schätzung: "gut" neben einem roten
      // Kreuz wäre ein Widerspruch, und der Server nimmt das Passwort ohnehin
      // nicht an. Bei zu kurz steht das ausdrücklich da — das ist der Fall, der
      // sich beim Weitertippen von selbst löst.
      if (verletzt) {
        balken.className = "bar pwbalken schwach";
        wort.textContent = ergebnis.laenge ? "nicht zulässig" : "noch zu kurz";
        return;
      }
      balken.className = "bar pwbalken " + s.klasse;
      wort.textContent = s.wort;
    }

    feld.addEventListener("input", zeichnen);
    if (namensfeld) {
      namensfeld.addEventListener("input", zeichnen);
    }
    zeichnen();
  }

  document.querySelectorAll(".pwcheck").forEach(ausstatten);
})();
