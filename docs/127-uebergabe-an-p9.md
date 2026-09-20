# Übergabe: P8 ist durch — was eine neue Sitzung wissen muss

Geschrieben am **20. September 2026**, nachdem PR #256 gemergt war und damit die
letzten drei offenen Fragen aus dem P8-Bestand geschlossen sind. Auf
`cloudsrv24` läuft **`0.7.4-rc.17`**, auf `main` steht `745b4de6`.

**Dieses Dokument ersetzt nichts.** Der Plan ist `docs/20`, die Lehren stehen in
`CLAUDE.md`, der Zuschnitt von P8 in `docs/117`. Hier steht, was eine neue
Sitzung braucht, um **ohne die letzten zwei Wochen nachzulesen** weiterzuarbeiten
— an P9 oder an dem, was der Betreiber sonst vorzieht.

**Die neue Sitzung fängt mit der Messrunde an und nicht mit dem Plan.** §6.2
sagt, was zu messen ist; §8 sagt, in welcher Reihenfolge.

---

## 1 · Wo das Projekt steht

**P0 bis P8 sind abgenommen**, jede Stufe auf `cloudsrv24` gemessen und nicht
geschätzt.

| Stufe | Was | Abgenommen |
|---|---|---|
| P0–P4 | Fundament, Kern, Abonnements, Web und PHP, TLS | bis Juli 2026 |
| P5 / P5b / P5c | Datenbanken (MariaDB), PostgreSQL, Datenbankmanagement | 11.–14. August |
| P6 | Dateien, Zugänge, Cron, SFTP, Angriffsdurchgang | 21. August |
| P7 | DNS-Abgleich | 24. August |
| **P7b** | Serververwaltung — elf Merkmale (A1, A2, A3¹, A5, A6, A8, A9, A10, A11, A12, A14) | 9. September |
| **P8** | **Sicherungen und Wiederherstellung** | **18. September** |

¹ A3 erster Wurf (nur Anzeige). Der zweite Wurf steht in P9b.

**P8 im Einzelnen.** Der Plan ist `docs/117`, der Abnahmelauf `docs/118` mit dem
Protokoll `docs/119`, der Nachlauf `docs/120` mit `docs/121`. Danach kamen zwei
weitere Nachläufe für die Behebungen: `docs/122`/`docs/123` gegen `rc.16` und
`docs/124`/`docs/125` gegen `rc.17`.

**Was P8 gebaut hat**, ausgezählt am 20. September:

| | |
|---|---|
| Operationen im Agenten | `backup.create`, `backup.list`, `backup.remove`, `backup.restore`, `backup.verify` |
| Agent-Klassen | `Backup\Manifest`, `Backup\Packer`, `Backup\Store`, `Backup\Unpacker` |
| Panel | `BackupController`, `BackupSettingsController`, `Models\Backup`, `Enums\BackupStatus` |
| Support | `Backups`, `BackupLifecycle`, `Restore`, `RestoreLifecycle`, `Retention`, `Description` |
| Seiten | `Settings/Backups.vue`, `Subscriptions/Backups.vue`, `Subscriptions/BackupPick.vue`, `Subscriptions/BackupRestore.vue` |
| Kommandos | `srvpanel backup` (`RunBackups`), `srvpanel backup-verify` (`VerifyBackups`) |
| Units | `srvpanel-backups.{service,timer}`, `srvpanel-backup-verify.{service,timer}` |
| Diagnose | `Checks\Backups` — der Nachtlauf prüft die Sicherungen mit |

**Die Wiederherstellung ist Form A**, entschieden am 16. September: Ein
wiederhergestelltes Abonnement bekommt einen **neuen** Systembenutzer und ein
**neues** `db_prefix`. Die Begründung steht in `docs/117 §3`; sie ist am
20. September mit einem Wächter befestigt worden (`SystemUserLedgerTest`), weil
das Merkmal, auf das die verworfene Form B gezeigt hätte — die Abschrift des
Abonnementnamens in `system_users` —, sich **bei jeder Wiederherstellung
verdoppelt**. Gemessen auf `cloudsrv24`: 146 Reservierungen, zwei Namen mit je
zwei Zeilen.

> **Eine Bedingung, die auf ein Merkmal zeigt, das der eigene Betrieb
> vervielfältigt, wird mit jedem Lauf schwächer — und sie steht schon
> geschrieben, bevor jemand sie braucht.**

---

## 2 · Die Zahlen, ausgezählt am 20. September 2026

| | | gegen 9. September |
|---|---|---|
| Voller Testlauf | **3580 Tests, 19 769 Zusicherungen, 0 Fehlschläge** | 3223 |
| Wächter (Testdateien) | **470** — 258 unter `tests/Unit`, 212 unter `tests/Feature` | 420 |
| Eingriffe im Bruchskript | **1464** (`vorher_datei`-Aufrufe) | 1244 |
| Prüfungen im Bruchskript | **2638** (`pruefe`-Aufrufe) | — |
| Messvorschriften im Repo | **22** unter `tests/` | 15 |
| Operationen im Agenten | **117** (`agent/src/Ops/*.php`) | 111 |
| Routen mit `can:` | **174** in `routes/web.php` | 163 |
| Seiten | **58** `.vue` unter `resources/js/Pages` | 54 |
| Migrationen | **44** | 42 |
| PHP in `app/` + `agent/src` | **101 162** Zeilen | 91 528 |
| Konsolenkommandos | **20** unter `app/Console/Commands` | — |
| Dokumente | **127** unter `docs/` | 93 |

Die Zahl der Eingriffe ist eine Auszählung des Quelltexts; die verbindliche
Aussage macht der CI-Job **„Jede Regel absichtlich brechen"** am Ende seines
Laufs. Er hängt allein an `pull_request`.

**Und die Aufteilung des Testlaufs ist eine Eigenschaft der Umgebung und nicht
des Repos.** Dieselbe Fassung, am selben Tag:

| | glatt | mit Warnung | riskant | übersprungen | Summe | Dauer |
|---|---|---|---|---|---|---|
| Container (root) | 3519 | 56 | 4 | 1 | **3580** | 255,6 s |
| CI (`runner`, uid 1001) | 3510 | 61 | 4 | 5 | **3580** | 175,9 s |

**Null Fehlschläge in beiden.** Die Warnungen sind Umgebung — `stat failed`,
`chown()`, ein Griff nach `/proc/<pid>/comm` —, und die vier übersprungenen
Fälle mehr in der CI sind genau die, die als root nicht messbar sind: Ein
`chown` auf einen fremden Benutzer darf nur root, und ein Schreibschutz greift
gegen ihn nicht.

> **Ein Wächter, der in einer Umgebung entsteht und nur dort gefahren wird, hält
> seine Umgebung für die Regel.** Wer einen Wächter baut, der Rechte, Eigentümer
> oder Schreibschutz anfasst, fährt ihn **unter beiden Kennungen** — der Griff
> dafür steht in `CLAUDE.md`, „Diese Umgebung".

**Seine Laufzeit ist gewachsen und das ist gemessen:** Der Lauf vom
20. September brauchte **32:22 Minuten**. Die Messung vom 15. September gab über
50 Läufe einen Median von 22,5 und als längsten 28,0 — **die alte Grenze von
dreissig Minuten hätte diesen Lauf abgeschnitten**, fünf Tage nach ihrer
Anhebung auf sechzig. Was skaliert, ist der Testlauf und nicht der Eingriff.

> **Ein abgeschnittener Lauf liest sich als roter — und der Nächste sucht dann
> am Skript statt an der Grenze.**

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
3. **Die Mandantenklammer verweigert im Grundzustand alles.**
   `withoutRestriction()` ist die begründete Ausnahme. **Autorisierung sitzt an
   der Aktion**, und wer eine Aktion *zeigt*, fragt vorher dieselbe Policy — als
   `abilities` im Payload und nie als `v-if` auf den Kontotyp.

Dazu die zweite Achse aus A9: **Betreiber** und **Administrator** sind eine
Rolle neben dem `AccountType` und keine vierte Ebene.

**Und eine vierte Grenze, die P8 geschärft hat:**

> **Ein Geheimnis, das als Argument eines Vorgangs reist, steht auf der
> Vorgangsseite.** `Operations/Show.vue` rendert `payload` als JSON, und
> `OperationPolicy::view()` lässt jeden Admin und den Kunden des Abonnements
> hindurch. `DnsCredentialStore` nennt genau das als Grund, **keinen** Vorgang
> einzureihen — das Vorbild für jedes Fernziel.

---

## 4 · Wie hier gearbeitet wird — sieben Gewohnheiten, die tragen

Sie sind nicht Stil, sondern der Grund, warum die letzten Abnahmeläufe **wenige
Fehler im Prüfmittel** hatten und die davor die Mehrheit.

1. **Für jede Regel ein Wächter — und der Wächter wird gebrochen.** Ein Wächter,
   der nie rot war, ist keiner. Der Bruch kommt ins `tests/waechter-brechen.sh`,
   **vor die Bilanz** und nicht ans Dateiende, und er wird **einzeln gegen
   seinen eigenen Fall** gefahren — nicht über die Klasse gefiltert.
2. **Der Abnahmelauf wird vor dem Fahren ausgeschrieben.** Beim Ausschreiben
   fallen Kriterien um, die der Prüfling gar nicht erfüllen kann — bei A10 drei,
   bei A6 vier, bei P8 drei. Danach zu merken kostet einen Lauf.
3. **Das Messmittel gehört ins Repo, nicht in den Sitzungsverlauf.**
   `tests/bilder-messen.js` und die 21 Geschwister sind der Grund, dass dieselben
   Messfehler nicht zweimal passieren.
4. **Gemessen wird auf einem echten Server.** Eine Stufe gilt erst als fertig,
   wenn ihr Kriterium **nachweisbar** erfüllt ist — `cloudsrv24`, nicht der
   Container.
5. **Eine Behebung ist erst behoben, wenn jemand nachgesehen hat.** Der Nachlauf
   ist ein eigener Schritt mit eigenem Protokoll — P8 hat davon zwei gebraucht.
6. **Bei allem Sichtbaren: ein Bild, in beiden Themes und bei 390 px** — und
   daneben die Zahl. Keines von beiden ersetzt das andere.
7. **Wer entscheidet, was als Nächstes gebaut wird, sieht vorher am Quelltext
   nach, ob es das schon gibt** — und nicht an der Planzeile. Das ist die Lehre
   aus A8, das zweieinhalb Wochen als offen dastand und gebaut war. §6.1 dieses
   Dokuments ist dieser Schritt für P9, vorweggenommen.

**Und zwei Fragen vor jedem neuen Merkmal**, die kein Test halten kann:

- *Wo sucht jemand diese Handlung, und steht sie dort?* Fünfmal versäumt,
  jedes Mal vom Betreiber gemeldet. Ein Stück davon hält seit dem 18. September
  `SubscriptionReachTest` — ob der Weg **auffällt**, kann er nicht sagen.
- *Wo sieht jemand diesen Hinweis, ohne ihn zu suchen — und steht er dort?*

---

## 5 · Was benannt offen bleibt

Nichts davon ist ein Kriterienausfall, und keines ist Arbeit, die P9 aufhält.
Wer eines anfasst, fängt beim genannten Dokument an und nicht bei null.

| | Was | Stand |
|---|---|---|
| **1** | `fail tls.file / expired / p6-b.invalid` in der Bestandsdiagnose | **Kein Rest des Prüflings.** `.invalid` ist nach RFC 2606 nicht ausstellbar; der Befund gehört dem Prüfstand (`docs/913 §15`). Wer die Zeile loswerden will, entfernt die Domain. |
| **2** | **Befund C** — ein Kontingent-Override, zweimal gesetzt und nie gespeichert | Am 20. September gemessen: **Die Kette trägt** (303, `{"backups":5}`, Protokollzeile, keine Prüfmeldung). Eine Hypothese mit genau der Signatur — veraltete `X-Inertia-Version`, 409, wortloses Neuladen — ist **widerlegt**. Ohne belegte Ursache; was bleibt, liegt **zwischen Knopf und Anfrage**, und dort hat niemand zugesehen. `docs/123 §9` |
| **3** | Die leere Aktionszelle bei 390 px während `Removing` | **Bewusst gelassen.** `dokument` bleibt 0, nichts wird verborgen, und eine Regel auf `:has(.button)` in dieser einen Spalte wäre die zweite Fassung, die altert. `docs/125 §7` |
| **4** | `Retention::keeps()` und `RunBackups::eligible()` bei fehlendem Kontingentschlüssel | **Eine Entscheidung, kein Rest.** Sie bleiben auf `null`. Sie auf den Vorgabewert zu stellen hiesse, dass der nächtliche Lauf auf solchen Plänen anfinge, Kundensicherungen abzuräumen. `docs/123 §9` |
| **5** | Zwei `test_every_exemption_carries_a_reason` über **leere** Ausnahmelisten | Sie prüfen damit nichts; PHPUnit nennt sie riskant, die CI übergeht es. **Das einzige kleine, klar umrissene Stück Arbeit, das herumliegt.** `docs/123` |

**Und drei Fragen sind am 20. September geschlossen worden** — sie stehen hier,
damit niemand sie ein viertes Mal aufschreibt:

- **Die `3 issues` auf `/backups/<id>/restore`** sind Chromes Ausfüllhilfe und
  kein Fund (`docs/126`). Die Antwort stand seit dem 23. August in `docs/76` und
  **nur im Protokoll ihres eigenen Laufs**; von dort ist die Frage durch vier
  Dokumente gewandert.

  > **Eine Antwort, die nur im Protokoll ihres eigenen Laufs steht, ist von
  > einer, die es nie gab, vier Wochen später nicht zu unterscheiden.**

  Dazu liegen drei Prüfblätter im Repo, die zusammen ein Verfahren sind:
  `tests/issues-pruefblatt.html` (*sieht das Werkzeug hin?*),
  `tests/issues-arten.html` (*welche Arten sieht es?*),
  `tests/issues-kaestchen.html` (*welche nicht?*). **Gemessen: Chrome 153
  übergeht Kästchen und Optionsknöpfe.** Eine Ablesung der Registerkarte in
  diesem Browser sagt über sie nichts.
- **Die Frage aus `docs/117 §3`** war seit dem 16. September entschieden und
  stand in drei Protokollen als offen — weil der Kopf des Abschnitts
  „entschieden" sagte und vier Absätze weiter unten „Entschieden ist es nicht".
- **Befund C** ist gemessen (oben, Nr. 2).

---

## 6 · Was als Nächstes kommt

Der Plan führt nach P8 die Stufe **P9 — Kundenfähigkeit und Betrieb**
(`docs/20 §9`, 3–4 Wochen, Fassungsreihe 0.10):

- **Statistik mit Spikelines auf Abo- und Domain-Ebene** (§4.6): Speicherplatz,
  Traffic, Zugriffe, Datenbankgrössen, FPM-Prozesse — Tagesauflösung über
  30 Tage, **aus der verdichteten Tabelle statt aus dem Ringpuffer**
- **Auswertung der Zugriffs-Logs je Domain als Nachtlauf**, mit
  Aufbewahrungsfrist
- **A7 — Ressourcenüberwachung, Schwellen, Benachrichtigungen** (Mail, Webhook).
  Ausgeschrieben steht er in `docs/80`, und **die Planzeile ist dünner als er**:
  Es fehlen dort die Entprellung, die Begründung des zweiten Kanals und die
  Anzeige „zuletzt erfolgreich zugestellt". Seine Auslöserliste ist seit P7b
  gewachsen — Dienst tot und Timer ohne Termin aus A2, offene
  Sicherheitsupdates und ablaufender Signaturschlüssel aus A1. **Wer A7 baut,
  liest `docs/80` und nicht `docs/20 §9`.**
- **Benachrichtigungen an Kunden**: Kontingent erreicht, Zertifikat läuft ab,
  Sicherung fehlgeschlagen
- **Branding**: Logo, Farben, Fusszeile, Absenderadresse, eigene Panel-Domain
- **API v1** mit OpenAPI-Beschreibung und Tokens
- **Ein Vorgang ohne Weiterleitung** — ausgeschrieben in `docs/92`

**Fertig, wenn** ein fremder Kunde das Panel benutzen kann, ohne zu fragen —
gemessen an einem Durchlauf mit einer Person, die das Projekt nicht kennt.

### 6.1 Was von P9 schon gebaut ist — ausgezählt, nicht erinnert

Das ist Gewohnheit 7 aus §4, für P9 vorweggenommen. **Zwei der sieben Punkte
haben ein Fundament, fünf haben keines.**

| Punkt | Stand am 20. September | Gemessen an |
|---|---|---|
| **Spikelines** | **Der Baustein ist da, die Daten nicht.** `Components/Tile.vue` ist die Verlaufskachel samt Ablesung, `SparklineShapeTest` und `PairedSeriesTest` halten ihre Regeln. Sie wird aber **nur auf `Overview.vue`** benutzt, und die Quelle ist der Ringpuffer. | `grep -rl sparkline`, `Tile.vue` |
| **Verdichtete Tabelle** | **Gibt es nicht.** `app/Support/Metrics/` hat `Collector`, `RingBuffer`, `Store` — vier Reihen (`cpu`, `ram`, `load`, `network`), 10 s Takt, `retention: 8640` Sätze, also **24 Stunden**. Keine Migration trägt Messwerte. P9 will 30 Tage in Tagesauflösung. | `config/srvpanel.php`, `ls database/migrations` |
| **Werte je Abo** | **Einer von fünf.** `subscriptions.disk_used_mb` und `disk_usage_measured_at` gibt es (`srvpanel usage`, eigener Timer). Traffic, Zugriffe, Datenbankgrössen und FPM-Prozesse werden **nicht** je Abo gemessen. | `Models/Subscription.php` |
| **Zugriffsprotokolle** | **Gelesen, nicht ausgewertet.** `WebLogsTail` liefert das Ende einer Datei an die Domainseite (seit dem 15. September mit Zeilennummern). Ein Nachtlauf, der zählt, gibt es nicht. | `agent/src/Ops/WebLogsTail.php` |
| **A7 / Benachrichtigungen** | **Nichts davon.** Kein `app/Notifications/`, keine Schwellentabelle, kein Webhook. Was **da** ist: der Mailversand (`MailSettingsController`), die Kennzahlen, und seit P7b die Auslöser — `Checks\Units`, `Checks\Certificates`, `Checks\Backups`, die Paketliste. **Die Lücke ist genau die zwischen „das Panel weiss es" und „jemand erfährt es".** | `ls app/`, `docs/80 §A7` |
| **Branding** | **Nichts.** Kein Feld, keine Einstellung, keine Marke dafür. | `grep -ri branding app resources` |
| **API v1** | **Keine `routes/api.php`.** Ein Haken dafür ist aber gesetzt: `bootstrap/app.php` schaltet Laravels Aushandlung mit `shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))` — genau für diesen Fall. | `ls routes/`, `bootstrap/app.php:76` |
| **Vorgang ohne Weiterleitung** | **Der schlimmste Teil ist seit dem 31. August behoben** (Herkunft im Brotkrümel, Gegenstand auf der Seite). Was bleibt, ist der Umweg selbst — vier offene Fragen in `docs/92 §4`, allen voran der Log-Strom, der auf jeder Seite mitliefe. | `docs/92` |

> **Ein Merkmal, das als Nebenwirkung einer Behebung entsteht, trägt den Namen
> nicht, unter dem es geplant war — und die Planzeile bleibt offen stehen.**

### 6.2 Was vor dem Plan zu messen ist — die Messrunde

**Jede Stufe seit P5b hat ihre Messrunde vor dem Plan gehabt, und jede hat den
Entwurf umgeworfen** — P8s Messrunde (`docs/116`) hat vier Annahmen gekippt,
darunter die Form der Wiederherstellung. Diese neun Fragen stehen aus gemessenen
Gründen da, nicht aus Vollständigkeit. **Jede bekommt eine Gegenprobe und einen
Satz darüber, was sie nicht sagt.**

**M1 · Was kostet ein Tageslauf, der verdichtet — und was kostet die Tabelle?**
Der Ringpuffer ist eine Datei fester Grösse und braucht kein Aufräumen; eine
Tabelle mit Tageswerten je Abo und Kennzahl wächst mit Abonnements × Kennzahlen
× 30. Zu messen: Zeilen und Bytes bei 100, 500 und 2000 Abonnements, und wie
lange ein Tageslauf über den Ringpuffer braucht. **Was es nicht sagt:** wie
teuer die *Erhebung* der vier fehlenden Grössen ist — das ist M2 bis M4.

**M2 · Wie misst man Traffic je Abo, und wie teuer ist es?** Heute misst nichts
es. Kandidaten: die Zugriffsprotokolle summieren (dann hängt es an M4), oder
nftables-Zähler je Systembenutzer (dann hängt es an P9b und an einer neuen
Systemgrenze). Beides messen, beides mit Gegenprobe. **Was es nicht sagt:** ob
der Kunde damit dieselbe Zahl sieht wie sein Provider.

**M3 · Wie teuer ist ein Zugriffsprotokoll-Nachtlauf wirklich?** `docs/81 §11`
hat A13 aus A10 gelöst, weil ein Griff, der den Bestand des Kunden liest, nicht
in denselben Lauf gehört wie einer, der eine Konfigurationsdatei prüft. Hier ist
es dieselbe Frage: Ein `access.log` einer lebhaften Domain hat Hunderttausende
Zeilen. Zu messen an einer **echten** Datei auf `cloudsrv24`, nicht an einer
gebauten — und mit der Klammer aus `docs/921`: `stat` davor und danach, denn ein
Zugriffsprotokoll steht beim Messen nicht still.

> **Eine Vorschrift, die im Container entstanden ist, setzt einen Gegenstand
> voraus, der stillhält — und auf einem echten Server hält nichts still.**

**M4 · Was steht überhaupt in diesen Dateien?** Das Format kommt aus
`SiteTemplate`, und es ist **unsere** Zeile und nicht nginx' Vorgabe. Zu messen:
welche Felder stehen da, ist die Zeile eindeutig zerlegbar, was passiert bei
einem User-Agent mit Anführungszeichen, und wie sieht eine Zeile nach einer
Rotation aus. **Und die Rotation selbst gehört gemessen** — `docs/921` hat 8393
Zeilen abends und 481 am Morgen gesehen. Ein Nachtlauf, der nach der Rotation
liest, zählt den falschen Tag.

**M5 · Wie kommt ein Schwellenwert zu einer Meldung, ohne 400 Mails zu
erzeugen?** Das ist A7s erste Falle. Zu messen ist keine Zahl, sondern eine
Form: Wie lange muss ein Zustand halten, bevor er meldet, und wie kommt er
wieder heraus? Der Bestand hat dafür ein Vorbild — die Bestandsdiagnose
**verfolgt den Zustand und rastet nicht ein** (`docs/913`, gemessen: Befund da,
nach dem `touch` fort, nach dem `rm` wieder da).

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

**M6 · Geht eine Meldung über den Weg hinaus, der ausgefallen ist?** Der
Mailversand läuft über diesen Server. Zu messen: Was tut `MailSettings`, wenn
der Versand tot ist — schweigt es, wirft es, wie lange hängt es? Und: Was kostet
ein Webhook nach draussen, und wo liegen seine **Zugangsdaten**? Nach Grenze 1
gehört die Verbindung in den Agenten, nach der vierten Grenze in §3 darf ein
Geheimnis nicht als Vorgangsargument reisen. **`Acme\Outbound` ist der
bestehende Weg nach draussen** und das gemessene Vorbild aus `docs/117 §5`.

**M7 · Was kostet eine Zeitreihe je Domain auf der Seite?** `Tile.vue` bekommt
**fertige Stützstellen** vom Server — das ist die Regel aus §4.6 und keine
Empfehlung. Eine Abo-Übersicht mit fünf Kacheln und eine Domainseite mit drei
sind acht Reihen zu je 30 Punkten. Zu messen: die Abfragen (die Falle aus
`docs/103` — ein fertiger Wert in `share()` läuft auch bei einem partiellen
Nachladen, ein Verschluss nicht) und die Lage bei 390 px mit
`tests/bilder-messen.js`.

**M8 · Trägt ein Token, was API v1 braucht?** Zu messen am Bestand, nicht am
Wunsch: Was tut `bootstrap/app.php` mit `api/*` heute, greift die
Mandantenklammer ohne Sitzung, und was sagt `RouteGuard` zu einer Route ohne
`can:`. **Die Gegenprobe ist die wichtige:** Eine API-Route, die die
Mandantenklammer nicht erreicht, liefert im Grundzustand `whereRaw('0 = 1')` —
also eine **leere Liste und keinen Fehler**.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

**M9 · Was kostet der Log-Strom auf jeder Seite?** Das ist die erste der vier
Fragen aus `docs/92 §4` und die einzige, die eine Zahl hat. Jede offene
SSE-Verbindung belegt einen PHP-FPM-Arbeiter; `config/srvpanel.php` deckelt sie
deshalb über `stream_seconds`. Zu messen: Wie viele Arbeiter hat der Pool auf
`cloudsrv24`, und was kostet eine Abfrage im Takt gegen den Strom?

**Was die Messrunde ausdrücklich nicht ist:** ein Plan. Sie hält fest, was
gemessen wurde, mit Gegenprobe und mit dem, was sie nicht sagt — und **danach**
wird geplant. `docs/116` ist die Vorlage, `tests/sicherung-messen.php` ein
Beispiel für eine Messvorschrift, die ins Repo gehört.

### 6.3 Was der Betreiber vor dem Plan entscheidet

- **Wie weit P9 geht.** Sieben Punkte sind viel für 3–4 Wochen, und fünf davon
  haben kein Fundament. Ein Zuschnitt wie bei P7b — mehrere Merkmale, jedes mit
  eigenem Abnahmelauf — ist wahrscheinlich der tragfähigere.
- **Ob A7 vorgezogen wird.** `docs/80` nennt ihn „die grösste Lücke zwischen
  ‚das Panel weiss es' und ‚jemand erfährt es'". Er hängt an nichts aus P9
  ausser dem Mailversand, der steht.
- **Wo der Webhook hinzeigt und wer seine Zugangsdaten sieht** — nach dem
  Rollenmodell der Betreiber allein.
- **Ob Traffic aus den Protokollen kommt oder aus nftables-Zählern.** Das zweite
  verschränkt P9 mit P9b.
- **Wie lange Zugriffsprotokolle aufbewahrt werden** — eine Frist, die der Plan
  nennt, aber keine Zahl.

### 6.4 Und die Alternativen zu P9

`docs/20 §9` führt danach **P9b — Absicherung des Servers** (A3 zweiter Wurf,
A4 Anmeldeschutz, **und das S3-Fernziel für Sicherungen**, dort verortet am
16. September) und dann **P10 — Härtung und Freigabe**. Wer P9b vorzieht,
verschiebt eine Zeile in `docs/20 §9` **und** eine in `docs/81 §12.1` — und
nicht nur eine davon.

> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

---

## 7 · Der Zustand von `cloudsrv24`

- Fassung **`0.7.4-rc.17`**, Kanal `beta`. Der letzte Lauf dagegen ist
  `docs/124`/`docs/125` vom 19. September: alle fünf Punkte erfüllt, ein Befund
  und der steckte im Prüfmittel.
- **Vier Dauerdienste** (`srvpanel-agentd`, `-metrics`, `-web`, `-worker`) und
  **acht Timer** (`backups`, `backup-verify`, `cron`, `diagnose`, `dns`,
  `packages`, `tls`, `usage`) unter `srvpanel.target`. `stop` und `start` des
  Ziels sind am 4. September gemessen (`docs/100 §9.10`).
- Die Bestandsdiagnose läuft nachts über neun Prüfungen und meldet den einen
  Rest aus §5.
- Der Prüfkörper aus P8 steht noch: ein Abonnement mit zwei Datenbanken, einer
  Domain und einem Cronjob, dazu die Sicherungen daneben. **`system_users` trägt
  146 Reservierungen mit zwei doppelten Abschriften** — das ist Betrieb und kein
  Schaden (§1).
- **Eine Freigabe lässt sich aus dem Container nicht setzen** — der Tag ist der
  Griff des Betreibers, gemessen am 8. September (zweimal `HTTP 403` auf ein
  Tag-Ref, während ein Branch-Ref in derselben Minute durchging).
  `workflow_dispatch` ist **kein** Ersatz: `release.yml` hängt Freigabenotiz und
  Release an `startsWith(github.ref, 'refs/tags/')`, die Paketquelle aber nicht
  — heraus käme ein halber Zustand. Was hier geht: die Notiz vorbereiten und
  gegen `packaging/version-channel.sh` und `packaging/release-notes.sh` messen,
  den Commit nennen, den Befehl fertig hinschreiben — und einen lokal angelegten
  Tag **wieder löschen**, sonst steht er bei jedem `fetch` im Weg.

---

## 8 · Was die neue Sitzung zuerst tut

1. **`CLAUDE.md` lesen.** Es ist lang und trägt die Lehren; die Abschnitte
   „Architektur — die drei Grenzen", „Die eine Gewohnheit, die dieses Projekt
   trägt" und **„Diese Umgebung"** sind die, die Zeit sparen. Der letzte
   beantwortet fast jede Frage der Form „geht das hier?" — und die Antwort ist
   meistens ja.

   > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
   > braucht einen Versuch.**

2. **Nachsehen, ob `vendor/autoload.php` da ist** — nicht, ob `vendor/`
   existiert. Ohne ihn prüft allein die CI, und jede Änderung kostet eine Runde.
   Der Weg zurück steht in „Diese Umgebung" (drei composer-Einstellungen und
   `--no-dev`).

3. **Den Zweig frisch von `main` starten**, falls der zugewiesene noch auf
   gemergter Historie steht. Am 20. September ist das erledigt:
   `claude/p8-backups-recovery-5uua46` steht auf `745b4de6`, gleichauf mit
   `main`. Ein neuer Zweigname für P9 ist Sache des Betreibers.

4. **Nichts nachzumessen.** P8 hat **keinen offenen Rest aus dem eigenen
   Bauen** — was in §5 stehenbleibt, ist entweder eine Entwurfsentscheidung,
   gehört dem Prüfstand, oder ist gemessen und ohne Ursache.

5. **Die Messrunde fahren — und zwar zuerst.** Die neun Fragen aus §6.2, jede
   mit Gegenprobe und mit dem, was sie nicht sagt. Das Ergebnis kommt als
   eigenes Dokument ins Repo — **die nächste freie Nummer**, nach dem Vorbild
   von `docs/116`. Eine Messvorschrift, die dabei entsteht, gehört nach `tests/`
   und nicht in den Sitzungsverlauf.

   *(Hier stand zuerst die nächste Nummer, ausgeschrieben. `DocLinkTest` hat
   sie abgewiesen, weil er prüft, ob ein genanntes Dokument existiert — und das
   künftige tut es noch nicht. Die Nummer nennt, wer es anlegt.)*

6. **Danach die Fragen aus §6.3 dem Betreiber vorlegen** — sie sind nicht vom
   Plan zu beantworten, und drei davon entscheiden seinen Zuschnitt.

7. **Und erst dann den Plan schreiben.**

> **Eine Stufe gilt erst als fertig, wenn ihr Abnahmekriterium nachweisbar
> erfüllt ist — gemessen auf einem echten Server, nicht geschätzt.**
