# P9 — Kundenfähigkeit und Betrieb: der Plan

Geschrieben am **20. September 2026**, **nach** der Messrunde (`docs/128`) und
nach den Entscheidungen des Betreibers vom selben Tag. Die Übergabe davor ist
`docs/127`, die Stufenzeile steht in `docs/20 §9`, A7 ausgeschrieben in
`docs/80`.

> **Jede Stufe seit P5b hat ihre Messrunde vor dem Plan gehabt, und jede hat den
> Entwurf umgeworfen.** Diese auch: Vier Ergebnisse aus `docs/128` ändern, was
> P9 bauen kann, und eines nimmt dem Plan eine Entscheidung ab, die `docs/127`
> noch dem Betreiber vorlegen wollte.

---

## §0 · Was beim Ausschreiben umgefallen ist

Vier Zeilen, die vor diesem Plan als richtig galten.

**1 · Die Reihenfolge aus `docs/20 §9` steht auf dem Kopf.** Dort steht die
Statistik zuerst und die Auswertung der Zugriffsprotokolle danach. Gemessen
(`docs/128` M2, M4) kommt **Traffic und Zugriffe je Abo allein aus den
Protokollen** — und die tragen heute nginx' `combined`, in dem ein
wiederkehrender Besucher als **Null** steht. Die Statistik kann also nicht vor
dem Protokoll gebaut werden; sie hätte nichts zu zeigen.

**2 · Eine Entscheidung aus `docs/127 §6.3` entfällt.** *„Ob Traffic aus den
Protokollen kommt oder aus nftables-Zählern"* ist keine Frage mehr: Ein Zähler
je Systembenutzer sieht den Website-Verkehr nicht, weil nginx **jede** Domain
als `www-data` bedient (gemessen: 200 720 B auf `www-data`, null auf den
Kunden). Damit entfällt auch die Verschränkung von P9 mit P9b, die `docs/127`
als Folge dieser Entscheidung vorsah.

> **Eine Entscheidung, die eine Messung vorwegnimmt, ist keine Entscheidung —
> sie ist eine Messung, die niemand gefahren hat.**

**3 · Die erste Falle von A7 ist nicht die, die `docs/80` nennt.** Dort steht
die **Entprellung** als Falle 1. Die Form dafür ist gebaut und richtig:
`FindingLog::replace()` je Prüfung, `first_seen_at` bleibt stehen, was der Lauf
nicht mehr nennt, wird gelöscht. Was fehlt, ist **die Erinnerung an die
Zustellung** — `findings` hat acht Spalten, und keine davon ist `notified_at`.
Die 400 Mails entstehen nicht an der Schwelle, sondern am Versand.

**4 · Und der zweite Kanal ist nicht nur eine Frage der Erreichbarkeit.**
`docs/80` begründet den Webhook damit, dass eine Meldung über den ausgefallenen
Weg nicht ankommt. Das stimmt — und gemessen kommt sie nicht nur nicht an, sie
**hält den Lauf auf**: `config/mail.php` führt `'timeout' => null`, und
PHPs `default_socket_timeout` steht auf 60 s. Vierhundert Meldungen gegen einen
schweigenden Versand sind **6,7 Stunden**, in denen der Nachtlauf steht.

> **Ein Kanal ohne Zeitgrenze ist kein langsamer Kanal, sondern ein Riegel vor
> allem, was hinter ihm in derselben Schlange steht.**

---

## §1 · Was P9 ist — und wie es geschnitten ist

**Kundenfähigkeit und Betrieb**: Der Kunde sieht, was sein Abonnement
verbraucht; der Betreiber erfährt von selbst, wenn etwas nicht stimmt; und das
Panel lässt sich von aussen ansprechen.

**Der Zuschnitt ist der von P7b** — Entscheidung des Betreibers vom
20. September. Nicht eine Stufe mit sieben Punkten und einem Abnahmelauf am
Ende, sondern **acht benannte Merkmale, jedes mit eigenem Abnahmelauf und
eigener Freigabe**.

> **Der Plan nennt die Reihenfolge und kein Enddatum.** P7b hat so elf Merkmale
> getragen; ein Merkmal, das beim Bauen kippt, hält die anderen nicht auf.

Das ist nicht nur Verwaltung: Gemessen haben **zwei der sieben Punkte aus
`docs/20 §9` ein Fundament und fünf keines** (`docs/127 §6.1`). Fünf Merkmale
ohne Fundament in einem Abnahmelauf hiesse, dass ein Befund an einem davon die
ganze Stufe hält.

---

## §2 · Die Entscheidungen des Betreibers

**Am 20. September 2026 entschieden**, nach der Messrunde und mit ihren Zahlen
daneben.

| | Frage | Entscheidung |
|---|---|---|
| **1** | Wie weit P9 geht | **Zuschnitt wie P7b** — mehrere benannte Merkmale, jedes mit eigenem Abnahmelauf. |
| **2** | Ob A7 vorgezogen wird | **Ja, als erstes Merkmal.** |
| **3** | Wo der Webhook hinzeigt | **Ein Ziel je Server, vom Betreiber gesetzt.** Die Verbindung liegt im Agenten; Zugangsdaten reisen nie als Vorgangsargument. |
| **4** | Aufbewahrung | **Rohdateien 14 Tage, verdichtete Zahlen 30 Tage.** |
| **5** | Traffic aus Protokollen oder nftables | **Von der Messrunde beantwortet** (§0 Punkt 2) — es gibt nur einen Weg. |

**Entscheidung 3 hat eine gemessene Kehrseite, und sie ist schärfer als bei
P8.** `Operations/Show.vue:276` rendert `payload` als JSON, und
`OperationPolicy::view()` lässt jeden Admin **und den Kunden des Abonnements**
hindurch. Der Wächter, der das halten soll, ist eine Liste von vier
Operationsnamen — und seine zweite Hälfte liest **genau eine** Migration.
Gemessen in beide Richtungen: `webhook_secret` in `create_databases_tables` ist
rot, dieselbe Spalte in einer neuen Migration **grün**.

> **Ein Wächter, der einen Ort prüft statt einer Regel, ist an jedem anderen Ort
> ein Freispruch.**

§8 sagt, was daraus folgt.

**Entscheidung 4 ist zwei Fristen und nicht eine.** Die Rohdateien rotieren
schon heute mit `rotate 14` aus `WebLogrotate::template()`; daran ändert sich
nichts. Die 30 Tage gelten den **verdichteten Tageswerten**, und die kosten
gemessen 32 MiB bei 2000 Abonnements — die Frist ist keine Platzfrage.

---

## §3 · Die acht Merkmale und ihre Reihenfolge

| | Merkmal | hängt an | Aufwand |
|---|---|---|---|
| **B1** | **A7** — Schwellen und Meldungen an den Betreiber | nichts (Quellen stehen seit P7b) | 1,5–2 Wochen |
| **B2** | Das Zugriffsprotokoll — Format und Nachtlauf | nichts (der Griff `srvpanel:vhost --sites` steht) | 1 Woche |
| **B3** | Kennzahlen je Abo — die verdichtete Tabelle | **B2** | 1 Woche |
| **B4** | Die Zeitreihe auf Abo- und Domainseite | **B3** | 0,5–1 Woche |
| **B5** | Meldungen an den Kunden | **B1**, für Kontingente auch **B3** | 0,5 Woche |
| **B6** | Branding | nichts | 0,5 Woche |
| **B7** | API v1 mit OpenAPI und Tokens | nichts | 2–3 Wochen |
| **B8** | Ein Vorgang ohne Weiterleitung | nichts (`docs/92`) | 0,5 Woche |

**Die Kette ist B2 → B3 → B4**, und sie ist die einzige. Alles andere steht
für sich und kann jederzeit dazwischen.

**B1 steht vorn, weil der Betreiber es so entschieden hat** — und weil es das
einzige Merkmal ist, dessen Quellen vollständig dastehen: `Checks\Units`,
`Checks\Certificates`, `Checks\Backups`, die Paketliste aus A1 und die
Kennzahlen aus `app/Support/Metrics/`. Es baut nichts Neues zu, es schliesst
die Lücke zwischen „das Panel weiss es" und „jemand erfährt es".

**B7 ist das grösste und das unsicherste.** Es steht hinten, nicht weil es
unwichtig ist, sondern weil sein Zuschnitt erst noch entsteht — und weil ein
Merkmal, das zwei bis drei Wochen braucht, nicht vor sieben kleineren stehen
soll, die in derselben Zeit alle fertig würden.

---

## §4 · B1 — A7 im Einzelnen

**Was da ist** (gemessen, `docs/128` M5):

| | |
|---|---|
| Ablage der Zustände | `findings`, `unique(check, subject, reason)` |
| „seit wann" | `first_seen_at` — bleibt über Läufe stehen |
| „zuletzt bestätigt" | `measured_at` |
| Verschwinden | `FindingLog::forgetMissing()` löscht, was der Lauf nicht mehr nennt |
| „nicht messbar" ≠ „nichts gefunden" | `FindingCheck::UNREACHABLE` |

**Was fehlt, in dieser Reihenfolge:**

**1 · Eine Erinnerung an die Zustellung.** Eine Spalte `notified_at` an
`findings` — oder, wenn mehrere Kanäle je Befund getrennt buchen sollen, eine
eigene kleine Tabelle. Der Nachtlauf meldet dann, was **auffällig ist und noch
nicht gemeldet wurde**, und nicht, was auffällig ist.

Die Entprellung fällt damit nebenbei ab und braucht keine eigene Mechanik:

```
gemeldet wird, was   now − first_seen_at ≥ Haltezeit
                und  notified_at is null
```

Eine Platte, die um die Schwelle pendelt, erzeugt **eine** Zeile mit einem
`first_seen_at`, das jedes Mal neu beginnt — und meldet erst, wenn sie die
Haltezeit übersteht.

**Und darunter liegt eine Frage, die dieser Plan nicht beantwortet:
in welchem Takt A7 überhaupt prüft.** Die Bestandsdiagnose läuft **nachts**;
der Kennzahlensammler schreibt alle **zehn Sekunden**. Eine Schwelle auf
„Platte voll" nachts zu prüfen heisst, dass der Betreiber bis zu
vierundzwanzig Stunden nichts erfährt; sie im Takt des Sammlers zu prüfen
heisst, dass die Haltezeit die ganze Arbeit tut.

| Takt | erste Meldung nach | Haltezeit muss leisten |
|---|---|---|
| nachts, wie die Diagnose | bis zu 24 h | wenig — zwei Nächte genügen |
| alle 5 min, eigener Timer | Minuten | alles — sonst sind es die 400 Mails |

**Die Haltezeit ist eine Entscheidung und keine Messung, und sie hängt am
Takt.** Der Vorschlag: **nachts für alles, was die Diagnose ohnehin prüft**
(Dienst tot, Timer ohne Termin, Zertifikat, Sicherung, Updates) — dort meldet,
was zwei Nächte hintereinander dasteht. Für die Kennzahlen aus dem Ringpuffer
(Platte, RAM, Load) ist ein eigener, häufigerer Lauf zu erwägen; er gehört dann
mit seiner Haltezeit in die Messrunde vor B1 und nicht in diesen Plan.

> **Eine Entprellung ohne ihren Takt ist eine halbe Zahl.**

**2 · Eine Zeitgrenze am Mailweg.** `config/mail.php` bekommt einen `timeout`.
Der Vorschlag ist **10 s**, dieselbe Zahl wie `Acme\Curl::CONNECT_TIMEOUT` —
gemessen sind heute 60,02 s je totem Versand, und der Regler greift (mit 5 s
gemessen: 5,00 s).

**3 · Der zweite Kanal**, §7.

**4 · „Zuletzt erfolgreich zugestellt".** `docs/80` nennt es und begründet es:
*Ein Kanal, der schweigt, ist von einem, der nichts zu melden hat, nicht zu
unterscheiden.* Das ist ein Wert je Kanal auf der Einstellungsseite, kein
Protokoll.

**Was B1 an Schwellen bekommt** — die Auslöser stehen alle schon:

| Auslöser | Quelle | steht seit |
|---|---|---|
| Platte voll, RAM, Load | `app/Support/Metrics/` | P1 |
| Dienst tot, Timer ohne Termin | `Checks\Units` | A2 |
| Zertifikat läuft ab | `Checks\Certificates` | P4 / A6 |
| Sicherung fehlgeschlagen | `Checks\Backups` | P8 |
| Sicherheitsupdates offen, Signaturschlüssel läuft ab | Paketliste | A1 |

> **Die Auslöserliste von A7 ist seit P7b gewachsen, und `docs/20 §9` weiss
> nichts davon.** Wer A7 baut, liest `docs/80` und nicht die Planzeile.

---

## §5 · B2 — Das Zugriffsprotokoll

### Das Format bekommt zwei Felder

Heute steht in `SiteTemplate` `access_log <pfad>;` ohne Formatnamen — das ist
nginx' `combined`, und darin fehlt alles, was ein Traffic-Zähler braucht.
Gemessen (`docs/128` M2):

| | `200` voller Abruf | `304` Wiederbesuch |
|---|---|---|
| `body_bytes_sent` (was `combined` schreibt) | 1000 | **0** |
| `bytes_sent` (Körper **und** Kopfzeilen) | 1248 | **189** |
| `request_length` (was hereinkam) | 96 | 111 |

`SiteTemplate` bekommt ein eigenes `log_format` mit **`$bytes_sent`** und
**`$request_length`**. Beides sind nginx-Variablen; es wird nichts geschätzt
und keine Kopfzeilengrösse addiert.

**Der Übergang ist der Teil, den man vergisst.** Alte Zeilen haben acht Felder,
neue neun. Zwei Wege, und der zweite ist der billigere:

1. Der Leser erkennt beide Formen an der Feldzahl. Kostet eine Fallunterscheidung
   für immer.
2. **Der Nachtlauf zählt erst ab dem ersten Tag, der vollständig im neuen Format
   geschrieben wurde.** Er erkennt das an der Zeile selbst, nicht an einem
   Datum — und eine Domain, deren `access.log.1` noch alt ist, wird
   übersprungen und nicht falsch gezählt.

Der Plan nimmt **2**. Ein Tag ohne Zahlen ist ehrlicher als ein Tag mit halben.

> **Ein Zähler, der zwei Formate mischt, liefert eine Zahl, die niemand
> nachrechnen kann — und sie sieht aus wie eine Zahl.**

### Die Bestandsdateien werden neu geschrieben, und der Griff dafür steht

Ein geändertes `SiteTemplate` schreibt **neue** Domains anders als die
bestehenden. Nachgesehen statt angenommen: Den Griff gibt es seit P7b —
`srvpanel:vhost --sites` schreibt den Block **jeder** Kundendomain neu
(`app/Console/Commands/ApplyVhost.php`).

**Und er ist nicht optional, sondern Teil des Merkmals.** Die Bestandsdiagnose
prüft jede Nacht die verwalteten Bereiche gegen die Vorlage
(`Checks\ManagedBlocks`, Gründe `line_missing` und `foreign_line`). Wer die
Vorlage ändert und die Dateien stehen lässt, bekommt am nächsten Morgen **einen
Befund je Domain** — und zwar zu Recht.

> **Eine geänderte Vorlage ist eine Änderung an jeder Datei, die aus ihr
> entstanden ist. Wer nur die Vorlage ändert, hat die Hälfte getan und die
> andere Hälfte dem Nachtlauf überlassen.**

Die Reihenfolge in B2 ist damit: Vorlage ändern, `--sites` fahren, **dann** die
Rotation abwarten — erst der Tag danach ist vollständig im neuen Format.

### Der Nachtlauf liest `.1` und nicht `access.log`

Gemessen (`docs/128` M4): Die Rotation **benennt um**. `access.log` bekommt eine
neue Inode, der abgeschlossene Tag heisst `access.log.1`, und `delaycompress`
lässt ihn unkomprimiert — aber nur bis zur nächsten Rotation, danach ist er
`.2.gz`.

Daraus folgen drei Dinge für den Bau:

1. **Gelesen wird `access.log.1`.** Wer `access.log` liest, zählt den
   angefangenen Tag.
2. **Der Lauf muss zwischen zwei Rotationen liegen.** Läuft er nach der zweiten,
   ist sein Tag gepackt; läuft er vor der ersten, gibt es ihn noch nicht. Der
   Timer gehört also **hinter** den von `logrotate` und mit genug Abstand davor.
   Die Zeiten auf `cloudsrv24` sind nachzusehen (§10).
3. **Ein offener Lesegriff überlebt die Rotation** und liest die alte Datei zu
   Ende — gemessen. Das ist hier kein Schaden, sondern eine Zusage: Ein Lauf,
   den die Rotation überrascht, zählt seinen Tag fertig.

### Und eine tote Zeile in der Vorlage

`WebLogrotate::template()` führt **`nocreate` und `create 0640 <user> adm`**.
Gemessen gewinnt `create`; die neue Datei entsteht mit `-rw-r----- <user>:adm`.
**`nocreate` tut nichts.** Sie fällt weg — nicht weil sie schadet, sondern weil
eine Zeile, die nichts tut, beim nächsten Lesen für eine Begründung gehalten
wird.

---

## §6 · B3 und B4 — die Tabelle und die Seite

### Die Tabelle ist lang und nicht breit

Gemessen (`docs/128` M1), 30 Tage:

| Form | 2000 Abos | Zeilen | Datei |
|---|---|---|---|
| **A lang** — je Abo, Kennzahl und Tag | | 300 000 | **32 MiB** |
| B breit — je Abo und Tag, fünf Wertspalten | | 60 000 | 16 MiB |

Der Plan nimmt **A**. Die 16 MiB Unterschied sind kein Argument gegen eine Form,
die eine sechste Kennzahl ohne Migration aufnimmt — und P9 hat schon heute fünf
Kennzahlen je Abo und drei je Domain, also zwei Stellen, an denen die Liste
wachsen wird.

> **Die Summe der Feldbreiten ist keine Dateigrösse.** Gerechnet wären es
> 5,72 MiB; auf der Platte liegen 32. Wer eine Tabelle rechnet statt sie zu
> messen, plant eine andere. (`docs/128` M1)

**Die fünf Kennzahlen je Abo:** Speicherplatz (steht — `disk_used_mb`), Traffic
und Zugriffe (aus B2), Datenbankgrössen, FPM-Prozesse. **Die drei je Domain:**
Traffic, Zugriffe, Fehlerquote — alle drei aus B2.

**Der Nachtlauf schreibt Tag für Tag**, nicht Abo für Abo. Gemessen kostet das
12,5 % mehr Datei (36 statt 32 MiB) — genannt, damit niemand die Zahl aus §6
später für falsch hält.

### Die Seite holt eine Abfrage und nicht acht

Gemessen: 150 Punkte in **0,0006 s** als eine Abfrage, in 0,0040 s als fünf.
Beides ist billig; die eine Abfrage ist trotzdem die richtige, weil sie nicht
mit der Zahl der Kacheln wächst.

**Und was geteilt wird, wird als Verschluss geteilt.** Die Falle aus
`docs/103 M5`: Ein fertiger Wert in `share()` läuft bei **jeder** Anfrage, auch
bei einer, die ihn nicht mitschickt — und die Übersichtsseite fragt alle
dreissig Sekunden nach.

### Acht Kacheln sind bei 390 px 1405 px Seite

Gemessen mit beiden gebauten Stylesheets, Gegenprobe 200 px bei beiden Breiten:

| | 390 px | 1440 px |
|---|---|---|
| Kachel | 390 × 172 px | 206 × 216 px |
| acht zusammen | **1405 px** | 394 px |
| Zeilen | 8 | 2 |
| waagerechter Überlauf | 0 | 0 |

Der Stapel trägt, der Trenner dreht sich, `MobileLayoutTest` hält den Fall. **Der
Preis ist nicht der Überlauf, sondern die Länge** — knapp vier Bildschirmhöhen,
bevor auf der Seite irgendetwas anderes steht.

B4 hat damit eine Entwurfsfrage, die keine Messung beantwortet: **Zeigt die
Abo-Übersicht alle fünf Kacheln, oder zeigt sie drei und die übrigen auf einer
eigenen Seite?** Sie gehört in die Bilderrunde von B4 und nicht in diesen Plan.

---

## §7 · Der Weg nach draussen

**Das Vorbild steht und ist gemessen.** `Acme\Curl` ist die Umsetzung von
`Outbound`:

| | |
|---|---|
| `CONNECT_TIMEOUT` | 10 s |
| `TIMEOUT` | 30 s |
| Antwort gedeckelt | 512 KiB |
| Schema | **nur `https`**, abgewiesen vor curl |
| TLS | `VERIFYPEER`, `VERIFYHOST 2` |

Der Webhook geht denselben Weg: **im Agenten**, hinter dem Socket, mit
denselben Grenzen. Grenze 1 lässt nichts anderes zu — wer eine Adresse nach
draussen wählen darf, wählt sonst auch `http://127.0.0.1:…`.

**Die Zugangsdaten liegen wie die des DNS-Anbieters.** `DnsCredentialStore` ist
der Vorläufer, und sein Grund ist gemessen: Ein Geheimnis darf **nicht als
Argument eines Vorgangs reisen**, weil die Vorgangsseite `payload` als JSON
zeigt und die Policy jeden Admin und den Kunden durchlässt.

**Und der Betreiber allein sieht sie** — Entscheidung 3. Ein Ziel je Server,
nicht je Abonnement. Das ist zugleich die kleinere Oberfläche und die, die
keine neue Frage an die Mandantenklammer stellt.

---

## §8 · Die Wächter

Für jede Regel einer, und jeder wird gebrochen — der Eingriff kommt in
`tests/waechter-brechen.sh` **vor die Bilanz** und wird einzeln gegen seinen
eigenen Fall gefahren.

**Zuerst einer, der schon fällig ist, unabhängig von jedem Merkmal:**

**W0 · `SecretsStayOutOfTheQueueTest` wird geweitet.** Gemessen ist er eine
Datei weiter blind: Seine Schema-Hälfte liest **eine** Migration und sichert das
mit `assertSame(1, $read)` zu. Er liest künftig **alle** Migrationen; die
Ausnahme ist dann nicht „diese eine Datei", sondern eine benannte Spalte mit
Begründung je Eintrag. Seine Operationsliste bleibt eine Liste — aber sie
bekommt die Gegenrichtung: **jede** Operation, deren Argumentnamen auf
`password`, `secret`, `token` oder `key` enden, steht entweder darin oder ist
mit Grund ausgenommen.

> **Ein Wächter mit einer Liste ist so gut wie das Gedächtnis dessen, der sie
> pflegt. Ein Wächter mit einer Gegenrichtung ist so gut wie seine Regel.**

**Je Merkmal:**

| | Wächter | hält |
|---|---|---|
| B1 | `NotificationLedgerTest` | Ein Befund wird höchstens einmal gemeldet — gemessen an der **Wirkung** über zwei Läufe, und die Gegenrichtung: Ein Befund, der verschwindet und wiederkommt, meldet wieder. |
| B1 | `MailTimeoutTest` | Der Mailweg hat eine Zeitgrenze, und sie ist gesetzt — gemessen am aufgelösten Wert und nicht an der Zeile in `config/mail.php`. |
| B1 | `ChannelReachTest` | Jeder Kanal, den die Einstellungsseite anbietet, hat eine Umsetzung, und jede Umsetzung steht auf der Seite. |
| B2 | `LogFormatTest` | Die Felder, die der Leser erwartet, sind genau die, die `SiteTemplate` schreibt — in **beide** Richtungen, damit ein neues Feld nicht stumm hinten anfällt. |
| B2 | `RotationSeamTest` | Der Nachtlauf liest `access.log.1` und nicht `access.log` — gehalten am **gerufenen Pfad**, nicht an einer Zeichenkette in der Datei. |
| B2 | `LogEraTest` | Eine Zeile im alten Format wird übersprungen und nicht falsch gezählt; eine im neuen wird gezählt. Beide Richtungen, mit einer gemessenen Zeile je Form als Prüfkörper. |
| B3 | `DailyRunIdempotenceTest` | Zweimal derselbe Tag ergibt eine Zeile und nicht zwei — derselbe Satz wie bei `FindingLog`, an einer anderen Tabelle. |
| B4 | `SeriesSourceTest` | `Tile.vue` bekommt fertige Stützstellen vom Server; kein Rechnen im Klienten. |
| B4 | `SharedClosureTest` | Was in `share()` steht, steht dort als Verschluss — die Regel aus `docs/103 M5`, die bisher niemand hält. |
| B7 | `ApiTenancyTest` | Jede Route unter `api/` durchläuft die Mandantenklammer — gemessen an der **Antwort** und nicht an der Middlewareliste. |

**Und einer, den `docs/92` nötig macht, sobald B8 drankommt:**

**`StreamPageTest`** — welche Seiten eine `EventSource` öffnen dürfen. Gemessen
(`docs/128` M9): Der Panel-Pool hat **12 Arbeiter**, und zwei belegte Arbeiter
lassen die nächste Anfrage **16 s** warten. Ein Strom auf jeder Seite hiesse,
aus **einer** von 58 Seiten alle 58 zu machen.

> **Zwölf offene Ströme machen das Panel für alle unerreichbar, und zwar für bis
> zu fünf Minuten.** `request_terminate_timeout` hilft nicht — es steht auf
> einer Stunde.

---

## §9 · Die Abnahmekriterien

**Je Merkmal eines, auf `cloudsrv24` nachweisbar** — nicht geschätzt.

| | Erfüllt, wenn |
|---|---|
| **B1** | Ein herbeigeführter Zustand (Dienst gestoppt) erzeugt **eine** Meldung, die zweite Nacht erzeugt **keine**, und nach `systemctl start` meldet der Lauf nichts mehr. Über beide Kanäle, mit „zuletzt erfolgreich zugestellt" auf der Seite. |
| **B2** | Für eine Domain mit echtem Verkehr steht am Morgen eine Tageszeile, deren Zahlen sich von Hand aus `access.log.1` nachrechnen lassen — `stat` davor und danach. |
| **B3** | Nach dreissig Nächten stehen dreissig Zeilen je Abo und Kennzahl, und die einunddreissigste Nacht löscht die erste. |
| **B4** | Fünf Kacheln auf der Abo-Seite und drei auf der Domainseite, mit Bild in beiden Themes und bei 390 px, dazu die Zahl daneben. |
| **B5** | Ein Kunde, dessen Kontingent überschritten wird, bekommt genau eine Mail — und der Betreiber sieht, dass sie zugestellt wurde. |
| **B6** | Logo, Farbe, Fusszeile und Absenderadresse des Betreibers stehen auf der Anmeldeseite und in einer verschickten Mail. Jede Farbe kommt aus `resources/css/app.css`. |
| **B7** | Ein Token eines Kunden liest über `api/v1` genau seine Abonnements — und dasselbe Token an einer fremden ID bekommt `404` und nicht `403`. |
| **B8** | Ein Vorgang, der aus einer Liste heraus angestossen wird, führt zurück in diese Liste — die vier Fragen aus `docs/92 §4`. |

**Und das Kriterium der Stufe bleibt, was `docs/20 §9` sagt:** *ein fremder
Kunde kann das Panel benutzen, ohne zu fragen — gemessen an einem Durchlauf mit
einer Person, die das Projekt nicht kennt.* Es steht **nach** B1 bis B8 und
nicht statt ihrer; ein Merkmal nimmt es nicht ab.

---

## §10 · Was auf dem Server zu messen bleibt

Keines davon ist im Container zu beantworten, und keines darf geschätzt werden.
**Punkt 1 hält B2 auf, Punkt 2 hält B1 auf** — beide sind vor dem ersten
Handgriff am jeweiligen Merkmal zu messen. Die übrigen halten nichts auf und
gehören in den Abnahmelauf ihres Merkmals.

1. **Wann `logrotate` läuft und wann der Nachtlauf laufen soll** — B2 hängt
   daran. Auf `cloudsrv24` stehen acht Timer unter `srvpanel.target`; wo der
   neunte hingehört, entscheidet die Zeit von `logrotate`.
2. **In welchem Takt A7 die Kennzahlen prüft** (§4) — und was eine Platte auf
   `cloudsrv24` über einen Tag wirklich tut. Ohne diese Kurve ist jede
   Haltezeit geraten.
3. **Eine echte `access.log`** — Grösse, Zeilenzahl, die wirkliche Verteilung
   der Statuscodes. Gemessen ist ein gebauter Prüfkörper.
4. **Der kalte Durchsatz der Platte** (B2, B3) — 1,45 Mio. Zeilen/s sind im
   Container gemessen und dort eine Vermutung.
5. **Die Grösse des FPM-Pools im Betrieb** (`StreamPageTest`, B8) und wie viele
   Leute gleichzeitig angemeldet sind.
6. **Was der echte Mailweg tut, wenn der Server unter Last steht** (B1) — der
   dritte Fall neben tot und schweigend.

---

## §11 · Was P9 ausdrücklich **nicht** wird

Eine Aufzählung dessen, was ein Merkmal nicht wird, ist nur dann eine
Entscheidung, wenn das Fehlende darin steht (`docs/105`).

- **Kein Traffic-Zähler an der Netzschnittstelle.** Gemessen geht es je
  Systembenutzer nicht, und je Schnittstelle wäre es der ganze Server. Was der
  Kunde sieht, kommt aus seinen Protokollen — und **deckt sich nicht mit der
  Zahl seines Providers**, der TCP, TLS und Wiederholungen mitzählt. Das gehört
  neben die Zahl auf der Seite und nicht in eine Fussnote.
- **Keine Zugriffsstatistik im Sinne von Besuchern, Sitzungen oder Herkunft.**
  Gezählt werden Anfragen und Bytes. Alles darüber ist ein eigenes Merkmal.
- **Keine Aufbewahrung über 30 Tage** für die verdichteten Zahlen und keine
  über 14 für die Rohdateien — Entscheidung 4.
- **Kein Webhook je Abonnement.** Ein Ziel je Server — Entscheidung 3.
- **Kein Fernziel für Sicherungen.** Das steht seit dem 16. September in P9b
  (`docs/81 §12.1`), und diese Stufe holt es nicht zurück.
- **Kein Log-Strom auf jeder Seite.** B8 räumt den Umweg auf; der Strom bleibt,
  wo er ist, bis `StreamPageTest` und eine Messung auf dem echten Pool etwas
  anderes sagen.
