// Rechte als Kästchen, die Oktalzahl daneben — beides im Gleichschritt.
//
// Vorher gab es ein Textfeld mit einer vierstelligen Zahl. Wer wissen wollte, ob
// 0644 der Gruppe das Schreiben erlaubt, musste zwei Ziffern im Kopf zerlegen;
// wer 0755 setzen wollte, musste sie zusammensetzen. Jetzt steht dieselbe Angabe
// als Raster da, und die Ziffer bleibt sichtbar — sie steht in jeder Anleitung,
// und wer sie kennt, tippt sie weiterhin.
//
// Die Kästchen kommen serverseitig gesperrt ("disabled") an: Ohne dieses Skript
// beschreiben sie den Ist-Zustand, statt eine Bedienung vorzutäuschen, die nichts
// bewirkt. Hier werden sie freigeschaltet.
//
// Die Worte für die Rechte stehen im Markup (data-rechte-wort) und kommen aus
// privops.DescribeMode — ein Verzeichnis wird "betreten", eine Datei
// "ausgeführt". Dieses Skript setzt sie nur zusammen.
(function () {
  "use strict";

  var ROLLEN = ["user", "group", "other"];
  var WERT = { r: 4, w: 2, x: 1 };
  var SONDER = { setuid: 4, setgid: 2, sticky: 1 };

  function aufzaehlung(teile) {
    if (teile.length === 0) return "nichts";
    if (teile.length === 1) return teile[0];
    return teile.slice(0, -1).join(", ") + " und " + teile[teile.length - 1];
  }

  function ausstatten(block) {
    var feld = document.getElementById(block.dataset.rechteFeld);
    if (!feld) {
      return;
    }
    var kaesten = block.querySelectorAll("[data-rechte-rolle]");
    var sonderkaesten = block.querySelectorAll("[data-rechte-sonder]");
    var saetze = block.querySelectorAll("[data-rechte-satz]");
    if (kaesten.length === 0) {
      return;
    }

    // Erst freischalten, wenn klar ist, dass das Skript läuft.
    kaesten.forEach(function (k) {
      k.disabled = false;
    });
    sonderkaesten.forEach(function (k) {
      k.disabled = false;
    });

    function kasten(rolle, recht) {
      return block.querySelector(
        '[data-rechte-rolle="' + rolle + '"][data-rechte-recht="' + recht + '"]',
      );
    }

    // --- Kästchen → Ziffer -------------------------------------------------
    function ziffer() {
      var sonder = 0;
      sonderkaesten.forEach(function (k) {
        if (k.checked) {
          sonder += SONDER[k.dataset.rechteSonder] || 0;
        }
      });
      var stellen = ROLLEN.map(function (rolle) {
        var summe = 0;
        Object.keys(WERT).forEach(function (recht) {
          var k = kasten(rolle, recht);
          if (k && k.checked) {
            summe += WERT[recht];
          }
        });
        return String(summe);
      });
      return String(sonder) + stellen.join("");
    }

    // --- Ziffer → Kästchen -------------------------------------------------
    function ausZiffer(text) {
      var roh = (text || "").trim();
      if (!/^[0-7]{3,4}$/.test(roh)) {
        return false;
      }
      if (roh.length === 3) {
        roh = "0" + roh;
      }
      var sonder = Number(roh[0]);
      sonderkaesten.forEach(function (k) {
        k.checked = (sonder & (SONDER[k.dataset.rechteSonder] || 0)) !== 0;
      });
      ROLLEN.forEach(function (rolle, i) {
        var stelle = Number(roh[i + 1]);
        Object.keys(WERT).forEach(function (recht) {
          var k = kasten(rolle, recht);
          if (k) {
            k.checked = (stelle & WERT[recht]) !== 0;
          }
        });
      });
      return true;
    }

    // --- die Sätze ---------------------------------------------------------
    function saetzeSchreiben() {
      saetze.forEach(function (zelle) {
        var rolle = zelle.dataset.rechteSatz;
        var worte = [];
        Object.keys(WERT).forEach(function (recht) {
          var k = kasten(rolle, recht);
          if (k && k.checked) {
            worte.push(k.dataset.rechteWort || recht);
          }
        });
        zelle.textContent = "darf " + aufzaehlung(worte);
      });
    }

    function vonKaesten() {
      feld.value = ziffer();
      saetzeSchreiben();
    }

    kaesten.forEach(function (k) {
      k.addEventListener("change", vonKaesten);
    });
    sonderkaesten.forEach(function (k) {
      k.addEventListener("change", vonKaesten);
    });

    feld.addEventListener("input", function () {
      // Eine halb getippte Zahl ("06") ist kein Fehler, sondern ein Zwischenstand
      // — dann bleiben die Kästchen stehen, bis die Angabe vollständig ist.
      if (ausZiffer(feld.value)) {
        saetzeSchreiben();
      }
    });
  }

  document.querySelectorAll(".rechte[data-rechte-feld]").forEach(ausstatten);
})();
