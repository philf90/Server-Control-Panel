// Ziel zum Verschieben und Kopieren: auswählen statt tippen.
//
// Vorher war das Ziel ein freies Textfeld mit dem aktuellen Ordner als Vorgabe.
// Ein Tippfehler wurde erst beim Absenden zu einer Fehlermeldung — und
// "/srv/date" statt "/srv/daten" legt beim Verschieben keinen Ordner an, sondern
// benennt um. Zur Wahl steht jetzt nur, was es gibt.
//
// Die Struktur kommt aus /files/dirs und damit durch dieselbe Pfadwache wie die
// Liste. Verbindlich bleibt die Prüfung beim Ausführen: Diese Auswahl ist eine
// Bedienhilfe, keine Sicherheitsgrenze — ein selbstgebauter POST kommt an ihr
// vorbei und an der Wache nicht.
//
// Ohne dieses Skript bleibt die serverseitig gefüllte Auswahlliste stehen
// (Schreibbereiche und der Weg zum Eintrag). Auch die ist nicht frei.
(function () {
  "use strict";

  function el(art, klasse, text) {
    var n = document.createElement(art);
    if (klasse) n.className = klasse;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function ausstatten(box) {
    var auswahl = box.querySelector("select.ziel-auswahl");
    if (!auswahl) {
      return;
    }

    // Die Liste bleibt im Markup, verliert aber ihren Namen: Sonst kämen zwei
    // Werte für "target" an, und welcher gewinnt, entscheidet die Reihenfolge.
    var start = box.dataset.zielStart || auswahl.value;
    auswahl.removeAttribute("name");
    auswahl.hidden = true;

    var feld = document.createElement("input");
    feld.type = "hidden";
    feld.name = "target";
    feld.value = start;
    box.appendChild(feld);

    var kopf = el("div", "ziel-kopf");
    var gewaehlt = el("code", "ziel-gewaehlt", start);
    kopf.appendChild(el("span", "muted", "Ziel: "));
    kopf.appendChild(gewaehlt);
    box.appendChild(kopf);

    var pfadzeile = el("div", "ziel-pfad");
    box.appendChild(pfadzeile);

    var liste = el("div", "ziel-liste");
    box.appendChild(liste);

    var marken = el("div", "ziel-marken");
    box.appendChild(marken);

    var stand = el("p", "ziel-stand muted klein");
    box.appendChild(stand);

    function waehle(pfad) {
      feld.value = pfad;
      gewaehlt.textContent = pfad;
    }

    function knopf(text, klasse, pfad) {
      var b = el("button", klasse, text);
      b.type = "button";
      b.addEventListener("click", function () {
        zeige(pfad);
      });
      return b;
    }

    function zeige(pfad) {
      stand.textContent = "wird geladen …";
      fetch("/alt/files/dirs?path=" + encodeURIComponent(pfad), {
        headers: { Accept: "application/json" },
      })
        .then(function (antwort) {
          if (!antwort.ok) {
            throw new Error("Ordner nicht lesbar (" + antwort.status + ")");
          }
          return antwort.json();
        })
        .then(function (d) {
          // Der Server bestimmt, was hier steht — auch den Pfad selbst: Er kommt
          // aufgelöst zurück, nicht so, wie er angefragt wurde.
          pfadzeile.replaceChildren();
          (d.crumbs || []).forEach(function (k) {
            pfadzeile.appendChild(knopf(k.name, "ziel-krume", k.path));
          });

          liste.replaceChildren();
          if (d.parent) {
            liste.appendChild(knopf("↑ übergeordnet", "ziel-eintrag hoch", d.parent));
          }
          (d.dirs || []).forEach(function (o) {
            var b = knopf(o.name, "ziel-eintrag" + (o.writable ? "" : " nurlesbar"), o.path);
            if (o.sensitive) {
              b.disabled = true;
              b.title = "gesperrter Eintrag";
              b.className = "ziel-eintrag gesperrt";
            }
            liste.appendChild(b);
          });
          if ((d.dirs || []).length === 0) {
            liste.appendChild(el("span", "muted klein", "keine Unterordner"));
          }

          // Hierher wählen — nur, wo das Panel auch schreiben darf. Ein Knopf,
          // der zuverlässig in einen Fehler läuft, ist die schlechteste Antwort.
          var hierher = el("button", "ziel-hierher small", "diesen Ordner wählen");
          hierher.type = "button";
          hierher.disabled = !d.writable;
          hierher.addEventListener("click", function () {
            waehle(d.path);
          });
          pfadzeile.appendChild(hierher);

          if (!d.writable) {
            stand.textContent =
              "In " + d.path + " darf das Panel nicht schreiben — als Ziel nicht wählbar.";
          } else if (d.truncated) {
            stand.textContent = "Die Liste ist gekürzt: sehr viele Einträge.";
          } else {
            stand.textContent = "";
          }

          // Die Schreibbereiche als Sprungmarken: Von dort aus findet man jedes
          // erlaubte Ziel, ohne einen Pfad zu kennen. Sie kommen aus derselben
          // Antwort — vorher las das Skript sie aus den Beschriftungen der
          // Auswahlliste, und wenn ein Bereich zugleich der aktuelle Ordner war,
          // stand er dort unter anderem Namen und fehlte.
          if (marken.childNodes.length === 0 && (d.roots || []).length > 0) {
            marken.appendChild(el("span", "muted klein", "Bereiche:"));
            d.roots.forEach(function (w) {
              marken.appendChild(knopf(w, "ziel-marke", w));
            });
          }

          // Der Sprung in einen Ordner wählt ihn zugleich aus, solange er
          // beschreibbar ist: Sonst müsste man jeden Schritt zweimal bestätigen.
          if (d.writable) {
            waehle(d.path);
          }
        })
        .catch(function (err) {
          stand.textContent = err.message;
        });
    }

    zeige(start);
  }

  document.querySelectorAll(".zielwahl").forEach(ausstatten);
})();
