// Messwerte in den Verläufen der Telemetriekacheln ablesen.
//
// Die Sparklines selbst kommen fertig vom Server: Die Content-Security-Policy
// verbietet Inline-Skripte, und der Verlauf soll auch ohne dieses Skript zu
// sehen sein. Hier kommt nur das Ablesen dazu — welcher Messpunkt liegt unter
// dem Zeiger, wann wurde er erhoben, und was stand dort?
//
// Die Werte stehen als JSON in data-spark, samt Uhrzeit und fertigem Text.
// Gerechnet und formatiert wird serverseitig; dieses Skript sucht nur den
// nächsten Punkt und setzt ihn hin.
(function () {
  "use strict";

  var SVGNS = "http://www.w3.org/2000/svg";

  function pfad(klasse) {
    var p = document.createElementNS(SVGNS, "path");
    p.setAttribute("class", klasse + " aus");
    return p;
  }

  function ausstatten(box) {
    var svg = box.querySelector("svg.spark");
    if (!svg) {
      return;
    }

    var punkte;
    try {
      punkte = JSON.parse(svg.dataset.spark || "[]");
    } catch (err) {
      return; // Unlesbare Angabe: dann eben kein Mouseover.
    }
    if (!punkte || punkte.length < 2) {
      return;
    }

    var feld = svg.viewBox.baseVal;
    var fuehrung = pfad("fuehrung");
    var marke = pfad("marke");
    svg.appendChild(fuehrung);
    svg.appendChild(marke);

    var kasten = document.createElement("div");
    kasten.className = "sparktip aus";
    var wert = document.createElement("b");
    var zeit = document.createElement("span");
    kasten.appendChild(wert);
    kasten.appendChild(zeit);
    box.appendChild(kasten);

    function naechster(x) {
      var beste = punkte[0];
      var abstand = Infinity;
      for (var i = 0; i < punkte.length; i++) {
        var d = Math.abs(punkte[i].x - x);
        if (d < abstand) {
          abstand = d;
          beste = punkte[i];
        }
      }
      return beste;
    }

    function zeigen(ev) {
      var rahmen = svg.getBoundingClientRect();
      if (rahmen.width <= 0) {
        return;
      }
      var p = naechster(((ev.clientX - rahmen.left) / rahmen.width) * feld.width);

      fuehrung.setAttribute("d", "M" + p.x + " 0 L" + p.x + " " + feld.height);
      marke.setAttribute("d", "M" + p.x + " " + p.y + " L" + p.x + " " + p.y);
      wert.textContent = p.v;
      zeit.textContent = p.t;

      fuehrung.classList.remove("aus");
      marke.classList.remove("aus");
      kasten.classList.remove("aus");

      // Die Stelle wird über das CSSOM gesetzt und nicht über ein
      // style-Attribut im Markup: Letzteres verwirft die CSP des Panels
      // stillschweigend (style-src 'self' ohne unsafe-inline), eine Zuweisung
      // aus JavaScript nicht.
      //
      // Der Kasten bleibt dabei in der Kachel. Ohne die Begrenzung ragte er an
      // den Rändern heraus und über die Nachbarkachel.
      var halb = kasten.offsetWidth / 2;
      var links = (p.x / feld.width) * rahmen.width;
      kasten.style.left = Math.min(Math.max(links, halb), rahmen.width - halb) + "px";
    }

    function verstecken() {
      fuehrung.classList.add("aus");
      marke.classList.add("aus");
      kasten.classList.add("aus");
    }

    // Die Ereignisse hängen an der Hülle, nicht am <svg>: Ein SVG meldet
    // Zeigerbewegungen nur über gezeichneten Flächen zuverlässig, und ein
    // Verlauf besteht fast nur aus Leerraum. Die Hülle ist genau so groß.
    box.addEventListener("pointermove", zeigen);
    box.addEventListener("pointerdown", zeigen);
    box.addEventListener("pointerleave", verstecken);
    box.addEventListener("pointercancel", verstecken);
  }

  document.querySelectorAll(".sparkbox").forEach(ausstatten);
})();
