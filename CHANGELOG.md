# Changelog

Die Vorgeschichte dieses Repositorys — das Server-Panel „Asylum" bis 0.6.2 —
steht unter dem Tag `v0.6.2` und im Branch `legacy/asylum`. CloudSrv ist ein
anderes Produkt und zählt neu.

## [Unbereinigt]

### P0 — Fundament und Werkzeug

- **Repo-Übergang.** Go-Code, die Svelte-Oberfläche und die Dokumente 01–18 des
  Vorgängers sind entfernt; erhalten bleiben die Sprachvorgabe (`docs/19`), der
  Plan (`docs/20`) und das Signaturmaterial. Die Lizenz ist von Apache-2.0 auf
  **AGPL-3.0-only** gewechselt.
- **Agent (`cloudsrv-agentd`).** Framework- und abhängigkeitsfreies PHP-CLI als
  einziger Prozess mit Systemrechten. Unix-Socket mit 0660, Aufruferprüfung
  über SCM_CREDENTIALS, NDJSON-Protokoll, typisierte Operationen,
  Programm-Positivliste mit absoluten Pfaden, feste Umgebung, Zeitlimit,
  gedeckelte Ausgabe, eigenes Protokoll mit der tatsächlich ausgeführten
  Kommandozeile. Operationen: `agent.ping`, `system.info`, `service.status`,
  `config.validate`.
- **Anwendung.** Laravel mit Inertia und Vue 3, Gestaltungssystem „Leitstand"
  in beiden Themes und beiden Dichtestufen, Adminübersicht mit
  Verlaufskacheln, Gesundheitsendpunkt für die Bereitschaftsprüfung.
- **Kennzahlen.** Ringpuffer fester Größe je Kennzahl, Sammler im
  Zehnsekundentakt als eigene Unit.
- **Paketierung.** `.deb` über nfpm mit `/opt/cloudsrv/releases/<version>` und
  Symlink-Umschaltung, vier systemd-Units, Installer mit
  Vorbedingungsprüfung, Instandhaltungsskripte.
- **CI.** Statische Prüfung, Tests, Oberfläche, Shellcheck, Lieferkette — und
  neu ein Integrationslauf, der das gebaute Paket unter echtem systemd auf
  Debian 12/13 und Ubuntu 22.04/24.04 installiert.

### Bezeichner auf Englisch

Dateien, Klassen, Methoden, Variablen, Konfigurations- und
Protokollschlüssel, CSS-Marken, Datenattribute und Job-Namen in der CI sind
englisch; Kommentare, Dokumentation und die Texte der Oberfläche bleiben
deutsch. Die Vorgabe steht in §2 des Plans.

Betroffen war die Schnittstelle mit: `Ergebnis`→`Result`, `Kontext`→`Context`,
`Verbindung`→`Connection`, `Ringpuffer`→`RingBuffer`, `Sammler`→`Collector`,
`Speicher`→`Store`; die Nutzdaten des Agenten (`vorhanden`→`present`,
`speicher`→`memory`, `pfad`→`path`, `art`→`kind`); die Schlüssel in
`/etc/cloudsrv/agent.json` (`benutzer`→`user`, `pruefbare_wurzeln`→
`config_roots`); die Konfiguration (`cloudsrv.kennzahlen.*`→`cloudsrv.metrics.*`);
das Kommando `cloudsrv:kennzahlen`→`cloudsrv:metrics`; die CSS-Marken
(`--grund`→`--bg`, `--akzent`→`--accent`, …) und die Werte von `data-theme`
(`dunkel`/`hell`→`dark`/`light`) und `data-density` (`kunde`→`customer`).

Da noch nichts ausgeliefert ist, gibt es dafür keinen Migrationspfad — und
genau deshalb war jetzt der Zeitpunkt.

### Gefunden und behoben

- `SO_PEERCRED` gibt es in PHPs Socket-Extension nicht (geprüft mit 8.4). Die
  Aufruferprüfung läuft statt dessen über `SO_PASSCRED` und `SCM_CREDENTIALS` —
  dieselbe Auskunft aus derselben Quelle, vom Kernel ausgefüllt und vom
  Absender nicht zu fälschen. Der Plan sagte SO_PEERCRED zu; §4.2 ist
  nachgezogen.
- Der Agent reagierte nicht auf SIGTERM. `pcntl_signal` setzt `SA_RESTART`,
  wenn man nicht widerspricht: Der Kernel nahm den unterbrochenen
  `accept()`-Aufruf danach wieder auf, das Beenden-Flag stand auf true und der
  Prozess hing weiter. systemd hätte ihn nach der Frist mit SIGKILL beendet —
  mitten in einem laufenden Auftrag. Die Schleife wartet jetzt mit `select`
  und Frist.
- Die Bereitschaftsprüfung antwortete mit 500 statt 503: Beim Umbenennen blieb
  eine Konstante stehen, die kein Test berührte. Die Lücke ist geschlossen —
  `HealthTest` prüft jetzt beides, den Gesundheitsendpunkt ohne Agenten und
  die Übersicht ohne Agenten.
- Ein Unix-Socket-Pfad ist im Kernel auf 108 Zeichen begrenzt. Darüber warf PHP
  eine `ValueError` mitten im Start; jetzt steht dort eine Meldung, aus der
  hervorgeht, was zu ändern ist.
