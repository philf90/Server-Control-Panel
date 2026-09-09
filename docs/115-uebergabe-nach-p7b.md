# Übergabe: P7b ist durch — was eine neue Sitzung wissen muss

Geschrieben am **9. September 2026**, nachdem Punkt 6 des A3-Laufs gegen
`0.7.3-rc.29` nachgemessen war und damit **die letzte offene Abnahme von P7b**
fiel. Auf `cloudsrv24` läuft seit demselben Abend **`0.7.3-rc.30`**.

**Dieses Dokument ersetzt nichts.** Der Plan ist `docs/20`, die Lehren stehen in
`CLAUDE.md`, der Zuschnitt von P7b in `docs/81 §12.1`. Hier steht, was eine neue
Sitzung braucht, um **ohne die letzten drei Wochen nachzulesen** weiterzuarbeiten
— an P8 oder an dem, was der Betreiber sonst vorzieht.

---

## 1 · Wo das Projekt steht

**P0 bis P7 und P7b sind abgenommen**, jede Stufe auf `cloudsrv24` gemessen und
nicht geschätzt. P7b hat elf Merkmale, und jedes hat sein Protokoll:

| | Was | Abgenommen | Plan | Protokoll |
|---|---|---|---|---|
| **A5** | Protokolle des Servers an einer Stelle | 25. August | `docs/81` | `docs/84` (Punkt 12) |
| **A9** | Zwei Rollen, Konten, Netze, Sitzungen | 25. August | `docs/82` | `docs/84` |
| **A1** | Paketverwaltung, Quellen, Updates | 28. August | `docs/81` | `docs/86` |
| **A2** | Dienste und Timer | 31. August | `docs/81` | `docs/91`, Nachlauf `docs/94` |
| **A10** | Diagnose des Bestands | 3. September | `docs/98` | `docs/100` |
| **A12** | Wartungsmodus | 5. September | `docs/101` | `docs/102` |
| **A14** | Ankündigungen im Panel | 6. September | `docs/103` | `docs/105` |
| **A11** | Zeit und Zeitzone des Servers | 7. September | `docs/106` | `docs/108` |
| **A8** | Adressen dieses Servers | *war seit 22. August gebaut* | `docs/80 §A8` | — |
| **A6** | Zeitpläne des Servers | 8. September | `docs/111` | `docs/113` |
| **A3** (erster Wurf) | Ports und Regelwerk, nur Anzeige | **9. September** | `docs/109` | `docs/114`, Nachlauf dessen §13 |

**A8 ist der Sonderfall und die Lehre daraus steht in `CLAUDE.md`:** Es war als
Nebenwirkung einer Behebung entstanden (`docs/74` Befund 2) und stand
zweieinhalb Wochen als offen im Plan.

> **Wer entscheidet, was als Nächstes gebaut wird, sieht vorher am Quelltext
> nach, ob es das schon gibt — und nicht an der Planzeile.**

---

## 2 · Die Zahlen, ausgezählt am 9. September 2026

| | |
|---|---|
| Voller Testlauf | **3223 Tests, 17 513 Zusicherungen, 0 Fehlschläge** |
| Wächter (Testdateien) | **420** — 230 unter `tests/Unit`, 190 unter `tests/Feature` |
| Eingriffe im Bruchskript | **1244** (`vorher_datei`-Aufrufe in `tests/waechter-brechen.sh`) |
| Messvorschriften im Repo | **15** unter `tests/` (`*-messen.*`, `stumpf.sh`, `waechter-brechen.sh`) |
| Operationen im Agenten | **111** (`agent/src/Ops/*.php`) |
| Routen mit `can:` | **163** in `routes/web.php` |
| Seiten | **54** `.vue` unter `resources/js/Pages` |
| Migrationen | **42** |
| PHP in `app/` + `agent/src` | **91 528** Zeilen |
| Dokumente | **93** unter `docs/` |

Die Zahl der Eingriffe ist eine Auszählung des Quelltexts; die verbindliche
nennt der CI-Job **„Jede Regel absichtlich brechen"** am Ende seines Laufs. Er
braucht rund 24 Minuten und hängt allein an `pull_request`.

---

## 3 · Die drei Grenzen — kurz, und sie gelten unverändert

Ausführlich in `CLAUDE.md`; wer sie verletzt, merkt es an der CI und nicht am
Server.

1. **Der Agent ist die einzige Stelle mit Systemrechten.** `agent/` ist
   framework- und abhängigkeitsfrei, hinter einem Unix-Socket, mit typisierten
   Operationen und einer Programm-Positivliste mit absoluten Pfaden. **Nichts
   Privilegiertes gehört in `app/`.**
2. **Zustände folgen dem Agenten, nicht dem Klick.** `Lifecycle::afterSuccess()`
   aus `RunAgentOperation`.
3. **Die Mandantenklammer verweigert im Grundzustand alles.** `withoutRestriction()`
   ist die begründete Ausnahme. **Autorisierung sitzt an der Aktion**, und wer
   eine Aktion *zeigt*, fragt vorher dieselbe Policy — als `abilities` im
   Payload und nie als `v-if` auf den Kontotyp.

Dazu die zweite Achse aus A9: **Betreiber** und **Administrator** sind eine Rolle
neben dem `AccountType` und keine vierte Ebene. `AccountTypeAxisTest` hält das.

---

## 4 · Wie hier gearbeitet wird — sechs Gewohnheiten, die tragen

Sie sind nicht Stil, sondern der Grund, warum die letzten fünf Abnahmeläufe
**wenige Fehler im Prüfmittel** hatten und die davor die Mehrheit.

1. **Für jede Regel ein Wächter — und der Wächter wird gebrochen.** Ein Wächter,
   der nie rot war, ist keiner. Der Bruch kommt ins `tests/waechter-brechen.sh`,
   und er wird **einzeln gefahren** und nicht bloss geschrieben.
2. **Der Abnahmelauf wird vor dem Fahren ausgeschrieben.** Beim Ausschreiben
   fallen Kriterien um, die der Prüfling gar nicht erfüllen kann — bei A10 drei,
   bei A6 vier. Danach zu merken kostet einen Lauf.
3. **Das Messmittel gehört ins Repo, nicht in den Sitzungsverlauf.**
   `tests/bilder-messen.js` und die vierzehn Geschwister sind der Grund, dass
   dieselben Messfehler nicht zweimal passieren.
4. **Gemessen wird auf einem echten Server.** Eine Stufe gilt erst als fertig,
   wenn ihr Kriterium **nachweisbar** erfüllt ist — `cloudsrv24`, nicht der
   Container.
5. **Eine Behebung ist erst behoben, wenn jemand nachgesehen hat.** Der Nachlauf
   ist ein eigener Schritt mit eigenem Protokoll (`docs/87`, `docs/94`,
   `docs/113 §12`, `docs/114 §13`).
6. **Bei allem Sichtbaren: ein Bild, in beiden Themes und bei 390 px** — und
   daneben die Zahl. Keines von beiden ersetzt das andere.

**Und eine Frage vor jedem neuen Merkmal**, die kein Test halten kann:
*Wo sucht jemand diese Handlung, und steht sie dort?* Sie ist dreimal versäumt
worden, und jedes Mal hat es der Betreiber gemeldet.

---

## 5 · Was benannt offen bleibt

Nichts davon ist ein Kriterienausfall. Wer eines anfasst, fängt beim genannten
Dokument an und nicht bei null.

**Aus P7b, frisch:**

- ~~**Der Befund an „Dienste" und „Timer"**~~ (`docs/114 §13.3`) — bei totem
  Agenten standen beide mit ihrer Kopfzeile über null Zeilen. **Erledigt:** am
  9. September auf `cloudsrv24` gegen `0.7.3-rc.30` nachgesehen, mit Gegenprobe
  (`docs/114 §14`). Er stand hier als die eine fällige Messung — sie ist
  gefahren, und **P7b hat damit keinen offenen Rest mehr aus dem eigenen
  Bauen.**
- **Die ungeklärte Konsolenmeldung auf `/services`** (`docs/114 §9.7`) — ein
  Inkognito-Fenster entscheidet sie.
- **`Verwaltet von: nftables` nennt die Maschine und nicht den Schreiber**
  (`docs/114 §12`). So entworfen (`docs/109 §1.1`); feiner wird es im zweiten
  Wurf von A3, und der steht in **P9b**.
- **Der anacron-Satz** (`docs/113 §13`) ist auf keiner Maschine gesehen worden —
  weder `cloudsrv24` noch der Container haben anacron.
- **`Statements::nginx()` kennt keine Anführungszeichen** (`docs/102 §9`). Ein
  `;`, `{` oder `}` in einer Zeichenkette zerreisst die Zerlegung. Heute meiden
  die Vorlagen die Zeichen, `SiteFileIntegrityTest` hält das — **der Leser ist
  unverändert**, und wer ihn anfasst, gehört zu A10.
- **Ob die ACME-`location` auch im HTTPS-Block stehen sollte** (`docs/102 §9`) —
  eine Frage und keine Zusage.
- **Der Warnpfad von `apt.key` kann auf diesem Server nie feuern**
  (`docs/100 §12`): Der Signaturschlüssel läuft nicht ab. Dass die Prüfung
  **liest**, ist belegt; dass sie **meldet**, nicht.

**Zwei Reste auf dem Server selbst:**

- **`orphan.row` / `tls.cloudlab24.de`** — der Rest aus P7. Die Diagnose meldet
  ihn zu Recht; ob `srvpanel tls --prune` ihn abräumt, entscheidet der Betreiber.
- **Das hochgeladene Wegwerfzertifikat** aus `docs/100 §6` — läuft am
  **13. September 2026** von selbst aus.

**Älter, unverändert:** die beiden Punkte aus `docs/42 §5` (der
`template1`-Beleg; ob ein Zugang ohne jede Datenbank entstehen kann), Wand 2 aus
Punkt 11 und Befund 23 aus `docs/59`, und die ungemessene Laufzeit über
142 Pakete (`docs/81 §2.3h`).

---

## 6 · Was als Nächstes kommt

Der Plan führt nach P7b die Stufe **P8 — Sicherungen und Wiederherstellung**
(`docs/20 §9`, 3–4 Wochen, Fassungsreihe 0.9):

- Sicherung je Abonnement: Dateien, Datenbanken, Konfiguration (Domains, DNS,
  Cron, FTP, Zertifikate) als beschriebenes, portables Format mit Manifest
- Zeitpläne, Aufbewahrungsregeln, Ziele: lokal, S3-kompatibel, SFTP/FTP
- Wiederherstellung vollständig oder einzeln — **durch den Kunden selbst**
- Automatische Sicherung vor riskanten Aktionen
- Ein Prüflauf, der eine Sicherung regelmässig testweise zurückspielt

**Fertig, wenn** ein vollständig gelöschtes Abonnement aus einer Sicherung
wiederhergestellt wird und danach Webseiten, Datenbanken, DNS und Cron
funktionieren — **durch einen automatisierten Lauf, nicht von Hand.**

### 6.1 Was vor dem Plan zu messen ist

Jede Stufe seit P5b hat ihre Messrunde **vor** dem Plan gehabt, und jede hat den
Entwurf umgeworfen. Diese sieben Fragen stehen aus gemessenen Gründen da, nicht
aus Vollständigkeit:

1. **Was kostet eine Dateisicherung — in Zeit und in Platz?** Für Datenbanken
   gibt es das seit P5 (`DatabaseDump`, `Db\Dump::ROOT` = `/var/lib/srvpanel/dumps`,
   sechs Operationen im Agenten). Für **Dateien** ist es neu und ungemessen.
2. **Zählt eine Sicherung gegen die Quota des Kunden?** Das Panel misst die
   Quota über den **Leseversuch** und nicht über die Mount-Option (`docs/41`),
   und `quotaon -p` hat auf `cloudsrv24` schon einmal `is off` gesagt, während
   die Option gesetzt war.
3. **Wohin, und auf welchem Weg kommt sie zum Kunden?**
   `/var/lib/srvpanel` ist `0750 srvpanel:srvpanel`, der nginx-Worker läuft als
   `www-data` — die Lehre der ACME-Prüfdatei (`docs/78 §5`). Der bestehende Weg
   ist `response()->download()` im Panel (`DatabaseController::download()`) und
   **nicht** der Webserver. Was für einen Dump von wenigen MB trägt, ist für ein
   Abonnement von mehreren GB ungemessen.
4. **Der Weg nach draussen.** S3, SFTP und FTP heissen: eine Verbindung nach
   aussen **und Zugangsdaten**. Nach Grenze 1 gehört beides in den Agenten; nach
   `docs/20 §6.1` ist ein Geheimnis **kritisch** — der Betreiber sieht es, der
   Administrator nicht, wie bei DNS-Zugangsdaten und dem SMTP-Kennwort. Und die
   Leitung zum Agenten trägt **1 MiB je Zeile** (`Connection::REQUEST_MAX`,
   nutzbar `CONTENT_MAX` = 1 MiB − 64 KiB): Eine Sicherung reist dort nicht als
   Feld, ihr Manifest schon.
5. **Bekommt ein wiederhergestelltes Abonnement seinen alten Systembenutzer
   zurück?** Seit `docs/35` wird ein zurückgebautes Abonnement **hart** gelöscht,
   und `Lifecycle::claim()` verbraucht den Namen dauerhaft in `system_users`. Am
   Namen hängen `/var/www/vhosts/<benutzer>`, die Dateirechte und
   `/etc/cron.d/srvpanel-<benutzer>`. **Das entscheidet die Form der
   Wiederherstellung** und gehört vor die erste Zeile Plan.
6. **Speichert eine Sicherung die Beschreibung oder die erzeugte Datei?** Der
   Inhalt eines Server-Blocks wird **erzeugt** und nicht abgelegt: `SiteTemplate`
   im Agenten baut ihn, `web.site.apply` ist der einzige Weg dorthin. A10 hält
   jede Vhost-Datei **je Form** gegen `SiteTemplate::PROMISED_BY_FORM` — „Die
   Form ist bekannt, wenn die Datei geschrieben wird, und sie ist bekannt, wenn
   sie geprüft wird." Eine Wiederherstellung, die eine gesicherte Datei
   **wörtlich zurückspielt**, legt damit etwas ab, das keine Vorlage erzeugt
   hat; stammt sie aus einer älteren Fassung, meldet der Nachtlauf sie in der
   Nacht darauf als `directive_lost`. Dasselbe gilt für `pg_hba.conf` und die
   Cron-Dateien — überall dort schreibt ein verwalteter Bereich.

   > **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
   > Rechnung und nicht ihre Eingaben — und die Rechnung ändert sich mit der
   > nächsten Fassung.**

   Das hängt an Frage 5 und ist nicht dieselbe: 5 fragt, ob die
   Wiederherstellung **dieselben Pfade** trifft, 6 fragt, ob sie sie **neu
   erzeugt oder zurückspielt**. Beide Antworten zusammen ergeben die Form.
7. **Wie meldet ein langer Lauf seinen Ausgang?** Form A aus `docs/86 §5`: Ein
   Vorgang, der nur absetzt, sagt über den Ausgang nichts, und `fertig` liest
   sich wie das Gegenteil. `AwaitDispatchedRun` liest das Urteil nach — gebaut
   für apt. Ob dieselbe Form für eine Sicherung trägt oder ob sie Fortschritt
   braucht, ist ungemessen.

### 6.2 Was der Betreiber vor dem Plan entscheidet

- **Wo der Prüflauf zurückspielt**, der eine Sicherung testweise prüft: auf
  demselben Server, in ein verstecktes Abonnement, oder gar nicht automatisch.
- **Ob eine Sicherung im Raum des Kunden liegt** (und damit gegen seine Quota
  zählt) oder daneben.
- **Wer die Zugangsdaten eines Fernziels sieht** — nach dem Rollenmodell der
  Betreiber allein, aber die Sicherung ist eine Kundenfunktion.

### 6.3 Und die Alternative zu P8

`docs/81 §12.1` führt neben P8 die Stufe **P9b — Absicherung des Servers**
(A3 zweiter Wurf, A4 Anmeldeschutz, A13 als Vorschlag). Sie steht **hinter** P9.
Wer sie vorzieht, verschiebt eine Zeile in `docs/20 §9` und in `docs/81 §12.1`
— und nicht nur eine davon.

> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

---

## 7 · Der Zustand von `cloudsrv24`

- Fassung **`0.7.3-rc.30`**, Kanal `beta` — freigegeben am 9. September auf
  `61a88997`. Ihre einzige ausgelieferte Änderung gegenüber `rc.29` ist die
  Hülle um „Dienste" und „Timer" (`fc108469`); alles andere in dem Sprung ist
  Dokumentation und Prüfmittel. Das war Absicht: Ein Nachlauf gegen eine
  Fassung, die vieles mitbringt, misst nicht die eine Behebung.
- Vier Dauerdienste und fünf Timer unter `srvpanel.target`; `stop` und `start`
  des Ziels sind am 4. September gemessen (`docs/100 §9.10`).
- Die Bestandsdiagnose läuft nachts und meldet die beiden Reste aus §5.
- **Eine Freigabe lässt sich aus dem Container nicht setzen** — der Tag ist der
  Griff des Betreibers, gemessen am 8. September (`CLAUDE.md`, „Diese Umgebung").
  Was hier geht: die Notiz vorbereiten und gegen `packaging/version-channel.sh`
  und `packaging/release-notes.sh` messen.

---

## 8 · Was die neue Sitzung zuerst tut

1. **`CLAUDE.md` lesen.** Es ist lang und trägt die Lehren; die Abschnitte
   „Architektur", „Die eine Gewohnheit" und „Diese Umgebung" sind die, die Zeit
   sparen.
2. **Nachsehen, ob `vendor/autoload.php` da ist** — nicht, ob `vendor/`
   existiert. Ohne ihn prüft allein die CI, und jede Änderung kostet eine Runde.
   Der Weg zurück steht in „Diese Umgebung" (drei composer-Einstellungen).
3. **Nichts nachzumessen.** Dieser Schritt hiess bis zum Abend des
   9. September „den offenen Rest aus §5 messen, sobald eine Fassung mit
   `fc108469` ausgeliefert ist". `0.7.3-rc.30` hat ihn ausgeliefert, und die
   Messung ist gefahren (`docs/114 §14`). **P7b hat keinen offenen Rest mehr
   aus dem eigenen Bauen** — was in §5 stehenbleibt, ist entweder eine
   Entwurfsentscheidung oder gehört einer anderen Stufe.
4. **Erst dann planen.** Für P8 heisst das: die sieben Messungen aus §6.1 fahren,
   das Ergebnis als `docs/81`-artigen Abschnitt festhalten, die Fragen aus §6.2
   dem Betreiber vorlegen — **und danach** den Plan schreiben.

> **Eine Stufe gilt erst als fertig, wenn ihr Abnahmekriterium nachweisbar
> erfüllt ist — gemessen auf einem echten Server, nicht geschätzt.**
