// Live-Aktualisierung der Übersicht über Server-Sent Events.
//
// Bewusst ohne Framework: Die Seite kommt fertig gerendert vom Server, dieses
// Skript schreibt nur einzelne Werte fort. Fällt es aus, bleibt die Seite
// benutzbar — sie zeigt dann eben den Stand des letzten Seitenaufrufs.
(function () {
  "use strict";

  var statusEl = document.getElementById("live-status");
  var dotEl = document.getElementById("live-dot");
  if (!statusEl) {
    return;
  }

  function setStatus(text, state) {
    statusEl.textContent = text;
    if (dotEl) {
      dotEl.className = "dot " + state;
    }
  }

  function formatBytes(b) {
    if (b < 1024) {
      return b + " B";
    }
    var units = ["KiB", "MiB", "GiB", "TiB", "PiB"];
    var i = -1;
    do {
      b /= 1024;
      i++;
    } while (b >= 1024 && i < units.length - 1);
    return b.toFixed(1) + " " + units[i];
  }

  function formatRate(bytesPerSecond) {
    if (!bytesPerSecond || bytesPerSecond < 1) {
      return "0 B/s";
    }
    return formatBytes(bytesPerSecond) + "/s";
  }

  // "cpu.total" → snapshot.cpu.total, "load.0" → snapshot.load[0]
  function pick(obj, path) {
    var parts = path.split(".");
    var cur = obj;
    for (var i = 0; i < parts.length; i++) {
      if (cur === null || cur === undefined) {
        return undefined;
      }
      cur = cur[parts[i]];
    }
    return cur;
  }

  function apply(snapshot) {
    document.querySelectorAll("[data-live]").forEach(function (el) {
      var v = pick(snapshot, el.getAttribute("data-live"));
      if (typeof v === "number") {
        el.textContent = v.toFixed(1);
      }
    });

    document.querySelectorAll("[data-live-bytes]").forEach(function (el) {
      var v = pick(snapshot, el.getAttribute("data-live-bytes"));
      if (typeof v === "number") {
        el.textContent = formatBytes(v);
      }
    });

    document.querySelectorAll("[data-live-width]").forEach(function (el) {
      var v = pick(snapshot, el.getAttribute("data-live-width"));
      if (typeof v === "number") {
        el.style.width = Math.max(0, Math.min(100, v)).toFixed(1) + "%";
      }
    });

    document.querySelectorAll("[data-live-text]").forEach(function (el) {
      var v = pick(snapshot, el.getAttribute("data-live-text"));
      if (typeof v === "string") {
        el.textContent = v;
      }
    });

    fillTable("interfaces", snapshot.interfaces, function (iface) {
      return [
        code(iface.name),
        muted((iface.addrs || []).join(", ")),
        text(formatRate(iface.rx_rate)),
        text(formatRate(iface.tx_rate))
      ];
    });

    fillTable("top_processes", snapshot.top_processes, function (p) {
      return [
        muted(String(p.pid)),
        text(p.name),
        muted(p.user),
        text(p.cpu_pct.toFixed(1) + " %"),
        text(formatBytes(p.rss))
      ];
    });
  }

  function text(value) {
    var td = document.createElement("td");
    td.textContent = value;
    return td;
  }

  function muted(value) {
    var td = text(value);
    td.className = "muted";
    return td;
  }

  function code(value) {
    var td = document.createElement("td");
    var el = document.createElement("code");
    el.textContent = value;
    td.appendChild(el);
    return td;
  }

  // Auf schmalen Bildschirmen wird jede Tabellenzeile zu einer Karte, und
  // jede Zelle holt ihre Beschriftung aus data-label. Die Zellen hier
  // entstehen neu — ohne diesen Schritt wären die Beschriftungen nach der
  // ersten Messung verschwunden, also 30 Sekunden nach dem Seitenaufruf.
  //
  // Die Namen kommen aus der Kopfzeile derselben Tabelle statt aus einer
  // zweiten Liste hier: Eine Kopie liefe früher oder später auseinander.
  function labelsOf(tbody) {
    var table = tbody.closest("table");
    if (!table) {
      return [];
    }
    return Array.prototype.map.call(
      table.querySelectorAll("thead th"),
      function (th) {
        return th.textContent.trim();
      }
    );
  }

  function fillTable(name, rows, render) {
    var tbody = document.querySelector('[data-live-table="' + name + '"]');
    if (!tbody || !rows) {
      return;
    }
    var labels = labelsOf(tbody);
    var frag = document.createDocumentFragment();
    rows.forEach(function (row) {
      var tr = document.createElement("tr");
      render(row).forEach(function (cell, i) {
        if (labels[i]) {
          cell.dataset.label = labels[i];
        }
        tr.appendChild(cell);
      });
      frag.appendChild(tr);
    });
    tbody.replaceChildren(frag);
  }

  var source = new EventSource("/events");

  source.addEventListener("open", function () {
    setStatus("Live", "on");
  });

  source.addEventListener("metrics", function (event) {
    try {
      apply(JSON.parse(event.data));
      setStatus("Live", "on");
    } catch (err) {
      setStatus("Antwort unlesbar", "off");
    }
  });

  // EventSource baut die Verbindung selbstständig wieder auf; hier wird nur
  // der Zustand angezeigt.
  source.addEventListener("error", function () {
    setStatus("Verbindung unterbrochen — neuer Versuch läuft", "off");
  });
})();
