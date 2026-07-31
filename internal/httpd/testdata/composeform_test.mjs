// Prüfungen am Modell hinter dem Compose-Formular (web/src/lib/composeform.ts).
//
// Diese Datei läuft in Node, nicht im Browser: Das Modell ist reines
// TypeScript ohne DOM, und die Fragen, die hier gestellt werden, sind
// Round-Trip-Fragen — überlebt ein Kommentar eine Änderung, bleibt die
// Einrückung, wird eine lange Zeile nicht umgebrochen. Sie über eine echte
// Seite zu stellen wäre möglich und ungenau: Man sähe nur, dass am Ende etwas
// im Editor steht, nicht, was mit der Datei geschehen ist.
//
// Der Aufruf kommt aus internal/httpd/composeform_test.go. Das Bündeln
// übernimmt rolldown aus web/node_modules — dieselbe Kette, die auch die
// Oberfläche baut, und deshalb keine zusätzliche Abhängigkeit.
//
// Ausgabe: eine Zeile JSON mit den Verstößen. Kein Testrahmen, weil das Projekt
// keinen für JavaScript hat und ein Rahmen für zwanzig Behauptungen mehr
// Abhängigkeit als Nutzen wäre.

// Der Pfad kommt als Argument: Das Bündel liegt in einem temporären
// Verzeichnis und wird nicht eingecheckt — ein zweiter gebauter Stand im
// Repository wäre ein zweiter Stand, der veralten kann.
const {
  dienstAnlegen,
  dienstEntfernen,
  einrueckung,
  liesPort,
  liesVolume,
  lies,
  schreibPort,
  setzeFeld,
  setzeListe,
  setzeUmgebung,
} = await import(process.argv[2]);

const verstoesse = [];

function pruefe(name, bedingung, gesehen) {
  if (!bedingung) verstoesse.push(`${name}: ${JSON.stringify(gesehen)}`);
}

function gleich(name, gesehen, erwartet) {
  if (gesehen !== erwartet) {
    verstoesse.push(`${name}: erwartet ${JSON.stringify(erwartet)}, gesehen ${JSON.stringify(gesehen)}`);
  }
}

// ------------------------------------------------------------------ Lesen ---

const einfach = `# Vom Panel verwaltet — Modul Docker.
services:
  web:
    image: nginx:alpine
    restart: unless-stopped
    ports:
      - "8080:80"
    environment:
      TZ: Europe/Berlin
    volumes:
      - daten:/var/www

volumes:
  daten:
`;

{
  const a = lies(einfach);
  pruefe("einfache Datei ist lesbar", a.lesbar && !a.gesperrt, a);
  gleich("ein Dienst", a.dienste.length, 1);
  const d = a.dienste[0];
  gleich("Dienstname", d.name, "web");
  gleich("image", d.image, "nginx:alpine");
  gleich("restart", d.restart, "unless-stopped");
  gleich("Portzeilen", d.ports.length, 1);
  gleich("Wirtsport", d.ports[0].wirt, "8080");
  gleich("Containerport", d.ports[0].container, "80");
  gleich("Umgebungsform", d.umgebungsform, "abbildung");
  gleich("Umgebungsschlüssel", d.umgebung[0].schluessel, "TZ");
  gleich("Volumequelle", d.volumes[0].quelle, "daten");
  gleich("Volumeziel", d.volumes[0].ziel, "/var/www");
  gleich("keine unbekannten Felder", d.weitereFelder.length, 0);
}

// Die Portformen, an denen ein Zerlegen von links scheitert.
{
  const p = liesPort("127.0.0.1:8080:80/tcp");
  gleich("Adresse", p.adresse, "127.0.0.1");
  gleich("Wirt", p.wirt, "8080");
  gleich("Container", p.container, "80");
  gleich("Protokoll", p.protokoll, "tcp");
  gleich("Port zurückgeschrieben", schreibPort(p), "127.0.0.1:8080:80/tcp");

  const kurz = liesPort("80");
  gleich("nur Containerport", kurz.container, "80");
  gleich("kurz zurückgeschrieben", schreibPort(kurz), "80");

  const v = liesVolume("./html:/usr/share/nginx/html:ro");
  gleich("Volumemodus", v.modus, "ro");
}

// ------------------------------------------------------- Kommentare halten ---

const mitKommentaren = `services:
  web:
    # Das Abbild bewusst mit Tag, nicht mit latest.
    image: nginx:alpine
    restart: unless-stopped
    ports:
      # Nur lokal: davor steht ein Reverse-Proxy.
      - "127.0.0.1:8080:80"
      - "9000:9000"
`;

{
  const neu = setzeFeld(mitKommentaren, "web", "image", "nginx:1.27-alpine");
  pruefe(
    "Kommentar über image überlebt",
    neu.includes("# Das Abbild bewusst mit Tag, nicht mit latest."),
    neu,
  );
  pruefe("neues Abbild steht drin", neu.includes("nginx:1.27-alpine"), neu);
  pruefe("altes Abbild ist weg", !neu.includes("nginx:alpine\n"), neu);
  pruefe("Kommentar in der Portliste überlebt", neu.includes("# Nur lokal"), neu);
}

{
  // Eine Zeile ändern darf den Kommentar an der anderen nicht kosten.
  const neu = setzeListe(mitKommentaren, "web", "ports", ["127.0.0.1:8080:80", "9001:9000"]);
  pruefe("Kommentar an der ersten Portzeile überlebt", neu.includes("# Nur lokal"), neu);
  pruefe("geänderte Portzeile steht drin", neu.includes("9001:9000"), neu);
}

{
  // Eine Zeile entfernen ebenso.
  const neu = setzeListe(mitKommentaren, "web", "ports", ["127.0.0.1:8080:80"]);
  pruefe("Kommentar überlebt das Entfernen", neu.includes("# Nur lokal"), neu);
  pruefe("entfernte Zeile ist weg", !neu.includes("9000:9000"), neu);
  gleich("noch eine Portzeile", lies(neu).dienste[0].ports.length, 1);
}

// ------------------------------------------------------------- Formtreue ---

{
  // Vier Leerzeichen Einrückung bleiben vier.
  const vier = `services:
    web:
        image: nginx:alpine
`;
  gleich("Einrückung erkannt", einrueckung(vier), 4);
  const neu = setzeFeld(vier, "web", "restart", "always");
  pruefe("Einrückung bleibt bei vier", neu.includes("\n        restart: always"), neu);
}

{
  // Eine lange Zeile darf nicht umgebrochen werden. yaml faltet sonst bei 80.
  const lang =
    "sh -c 'while true; do echo eine sehr lange Zeile die nicht umgebrochen werden darf; sleep 5; done'";
  const quelle = `services:\n  web:\n    image: alpine\n    command: "${lang}"\n`;
  const neu = setzeFeld(quelle, "web", "restart", "always");
  pruefe("lange Zeile bleibt in einer Zeile", neu.includes(lang), neu);
}

{
  // Die Listenform von environment bleibt Liste.
  const liste = `services:
  web:
    image: alpine
    environment:
      - TZ=Europe/Berlin
      - DEBUG=1
`;
  const a = lies(liste);
  gleich("Listenform erkannt", a.dienste[0].umgebungsform, "liste");
  gleich("Wert aus der Listenform", a.dienste[0].umgebung[1].wert, "1");
  const neu = setzeUmgebung(
    liste,
    "web",
    [
      { schluessel: "TZ", wert: "Europe/Berlin" },
      { schluessel: "DEBUG", wert: "0" },
    ],
    "liste",
  );
  pruefe("bleibt Listenform", neu.includes("- DEBUG=0"), neu);
  pruefe("wird nicht zur Abbildung", !neu.includes("DEBUG: "), neu);
}

{
  // Ein leerer Wert ist etwas anderes als kein Eintrag.
  const quelle = `services:\n  web:\n    image: alpine\n    environment:\n      DEBUG: "1"\n`;
  const neu = setzeUmgebung(quelle, "web", [{ schluessel: "DEBUG", wert: "" }], "abbildung");
  gleich("leerer Wert bleibt ein Eintrag", lies(neu).dienste[0].umgebung.length, 1);
}

// --------------------------------------------------------------- Sperren ---

{
  const anker = `services:
  basis: &basis
    image: alpine
  web:
    <<: *basis
    restart: always
`;
  const a = lies(anker);
  pruefe("Anker sperrt das Dokument", a.gesperrt, a);
  gleich("Änderung am gesperrten Dokument ändert nichts", setzeFeld(anker, "web", "restart", "no"), anker);
}

{
  const erbt = `services:
  web:
    extends:
      file: gemeinsam.yaml
      service: basis
    restart: always
`;
  const d = lies(erbt).dienste[0];
  pruefe("extends sperrt den Dienst", d.gesperrt, d);
  pruefe("Grund benennt extends", d.grund.includes("extends"), d.grund);
}

{
  const mehrere = `services:\n  a:\n    image: alpine\n---\nservices:\n  b:\n    image: alpine\n`;
  pruefe("mehrere Dokumente sperren", lies(mehrere).gesperrt, lies(mehrere));
}

{
  const kaputt = `services:\n  web:\n   image: [unvollstaendig\n`;
  const a = lies(kaputt);
  pruefe("kaputtes YAML ist nicht lesbar", !a.lesbar, a);
  gleich("kaputtes YAML wird nicht umgeschrieben", setzeFeld(kaputt, "web", "image", "x"), kaputt);
}

{
  // Unbekannte Felder werden benannt und nicht angefasst.
  const mitDeploy = `services:
  web:
    image: alpine
    healthcheck:
      test: ["CMD", "true"]
    deploy:
      replicas: 2
`;
  const d = lies(mitDeploy).dienste[0];
  pruefe("healthcheck wird benannt", d.weitereFelder.includes("healthcheck"), d.weitereFelder);
  pruefe("deploy wird benannt", d.weitereFelder.includes("deploy"), d.weitereFelder);
  const neu = setzeFeld(mitDeploy, "web", "image", "alpine:3.20");
  pruefe("healthcheck bleibt unangetastet", neu.includes("test:"), neu);
  pruefe("deploy bleibt unangetastet", neu.includes("replicas: 2"), neu);
}

{
  // depends_on in der Abbildungsform ist keine halbe Liste, sondern ein
  // unbekanntes Feld.
  const bedingt = `services:
  web:
    image: alpine
    depends_on:
      db:
        condition: service_healthy
`;
  const d = lies(bedingt).dienste[0];
  gleich("keine halbe Abhängigkeitsliste", d.abhaengig.length, 0);
  pruefe("depends_on wird als unbedienbar benannt", d.unbedienbar.includes("depends_on"), d.unbedienbar);
}

// ------------------------------------------------------ Dienste verwalten ---

{
  const neu = dienstAnlegen(einfach, "db");
  const a = lies(neu);
  gleich("zwei Dienste", a.dienste.length, 2);
  gleich("neuer Dienst heißt db", a.dienste[1].name, "db");
  gleich("neuer Dienst hat eine Neustartregel", a.dienste[1].restart, "unless-stopped");
  pruefe("der alte Dienst bleibt", neu.includes("nginx:alpine"), neu);
  pruefe("die oberste Volumeebene bleibt", neu.includes("\nvolumes:"), neu);

  const zurueck = dienstEntfernen(neu, "db");
  gleich("wieder ein Dienst", lies(zurueck).dienste.length, 1);
}

{
  // Ein Dienst in einer Datei ohne services-Block.
  const leer = "";
  const neu = dienstAnlegen(leer, "web");
  gleich("Dienst in leerer Datei", lies(neu).dienste.length, 1);
}

process.stdout.write(JSON.stringify({ verstoesse }) + "\n");
