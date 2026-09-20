# Die Messrunde vor P9 — Kundenfähigkeit und Betrieb

Gefahren am **20. September 2026** im Container, gegen `main @ fae85f8`, **vor**
der ersten Zeile Plan. Sie beantwortet die neun Fragen aus `docs/127 §6.2`; die
Messvorschriften liegen als **`tests/zugriffsprotokoll-messen.sh`** und
**`tests/kennzahlen-messen.php`** daneben und nicht in einem Sitzungsverlauf.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.** (`docs/66`)

**Jede Messung hat ihre Gegenprobe, und jede sagt, was sie nicht sagt.** Der
Abschnitt „sagt nicht" ist kein Beiwerk: Drei Messungen dieser Runde haben beim
ersten Anlauf etwas anderes gemessen als ihren Gegenstand, und **jedes Mal hat
es die Gegenprobe gefangen und nicht der Blick auf die Zahl.**

**Der Ausgangsstand ist nachgemessen und nicht übernommen:** `php artisan test`
ergibt **3580 Tests, 19 770 Zusicherungen, 0 Fehlschläge** (3519 glatt, 56 mit
Warnung, 4 riskant, 1 übersprungen), 148,4 s. Das deckt sich mit `docs/127 §2`.

---

## 0 · Was die Runde umgeworfen hat

Sechs Dinge, und **vier davon ändern die Form**, die `docs/127` sich gedacht
hatte.

1. **Das Zugriffsprotokoll trägt nicht unsere Zeile, sondern nginx' Vorgabe**
   (M4). `docs/127 §6.2` sagt: *„Das Format kommt aus `SiteTemplate`, und es ist
   **unsere** Zeile und nicht nginx' Vorgabe."* Gemessen steht `log_format`
   **nirgends** im Repo, und `access_log <pfad>;` ohne Formatnamen heisst
   `combined`. Das ist keine Kleinigkeit: In `combined` steht
   `$body_bytes_sent` und nicht, was über die Leitung ging.
2. **Ein Zähler je Systembenutzer kann Website-Traffic nicht zuordnen** (M2).
   Der zweite Kandidat aus `docs/127` ist keiner. nginx bedient **jede**
   Domain als `www-data`; gemessen zählen 200 KB Website-Verkehr zu
   `www-data` und **null** zum Kunden. Damit hängt M2 allein an M4 — und die
   Verschränkung mit P9b entfällt.
3. **Ein Zugriffsprotokoll-Nachtlauf ist billig, nicht teuer** (M3). `docs/127`
   rahmt ihn als Kostenfrage („Hunderttausende Zeilen"). Gemessen: 200 000
   Zeilen zerlegt in **0,138 s kalt**. Die Frage ist nicht, was er kostet,
   sondern **wann** er läuft — die Rotation entscheidet, welchen Tag er zählt.
4. **Die Schwellenfrage aus M5 ist keine Schwellenfrage.** Die Form, nach der
   `docs/127` sucht, ist gebaut und richtig: `FindingLog::replace()` je Prüfung,
   `first_seen_at` bleibt stehen, was der Lauf nicht mehr nennt, wird gelöscht.
   Was fehlt, ist **keine Entprellung, sondern eine Spalte**: Nichts merkt sich,
   dass über einen Befund schon jemand benachrichtigt wurde.
5. **Der Mailweg hängt sechzig Sekunden je totem Versand** (M6).
   `config/mail.php` führt `'timeout' => null`; gemessen sind das 60,02 s gegen
   ein schwarzes Loch. Bei 400 Meldungen sind das **6,7 Stunden**.
6. **Die Gefahr an API v1 ist nicht die Preisgabe, sondern das Schweigen** (M8).
   Die Mandantenklammer hängt am Modell und nicht an der Middleware; eine
   api-Route ohne `ApplyTenancy` bekommt `where 0 = 1`. Sie liefert **200 mit
   einer leeren Liste** — nicht zu unterscheiden von „der Kunde hat nichts".

Und eine Zahl, die kleiner ist als ihr Ruf: **Die verdichtete Tabelle kostet bei
2000 Abonnements 32 MiB.** Die Rechnung, die `docs/127` nahelegt, sagt 5,72 —
sie liegt um das **Fünf- bis Dreissigfache** daneben, und trotzdem ist auch die
gemessene Zahl belanglos.

---

## M1 · Was ein Tageslauf kostet, der verdichtet — und was die Tabelle kostet

Messvorschrift: `tests/kennzahlen-messen.php`, gefahren gegen **MariaDB
10.11.14** (dieselbe Fassung wie `cloudsrv24`) und nicht gegen SQLite — die
Frage ist, was InnoDB an Seiten anlegt, und die beantwortet keine andere
Maschine.

### Der Ringpuffer heute

| Reihe | Spalten | gerechnet | gemessen |
|---|---|---|---|
| `cpu` | 2 | 207 392 B | 207 392 B |
| `ram` | 2 | 207 392 B | 207 392 B |
| `load` | 3 | 276 512 B | 276 512 B |
| `network` | 2 | 207 392 B | 207 392 B |

**0,86 MiB für 24 Stunden**, feste Grösse, kein Aufräumen. Rechnung und Messung
stimmen aufs Byte — das ist die Gegenprobe, und sie sagt, dass die Formel
`32 + 8 · (1 + Spalten) · Vorhalt` trägt.

### Der Tageslauf über den Ringpuffer

| | Dauer |
|---|---|
| Lauf 0 · verdichten, **kalt** | 0,0248 s |
| Lauf 1 · verdichten, warm | 0,0038 s |
| Lauf 2 · verdichten, warm | 0,0033 s |
| **Gegenprobe** · nur lesen, ohne Verdichten | 0,0027 s |

Über alle vier Reihen: **0,011 s je Nacht und Server.** Die Gegenprobe steht
dicht an der Messung — 0,0027 gegen 0,0033 —, und das ist selbst das Ergebnis:
**Der Lauf kostet das Lesen, nicht das Verdichten.**

### Die Tabelle, 30 Tage

Zwei Formen, weil die Wahl zwischen ihnen die Entscheidung des Plans ist:
**A lang** (eine Zeile je Abonnement, Kennzahl und Tag) nimmt eine sechste
Kennzahl ohne Migration auf; **B breit** (eine Zeile je Abonnement und Tag, fünf
Wertspalten) hat ein Fünftel der Zeilen.

| Form | Abos | Zeilen | gerechnet | InnoDB schätzt | **Datei** |
|---|---|---|---|---|---|
| A lang | 100 | 15 000 | 0,29 MiB | 1,92 MiB | **9,00 MiB** |
| B breit | 100 | 3 000 | 0,15 MiB | 0,38 MiB | **0,44 MiB** |
| A lang | 500 | 75 000 | 1,43 MiB | 7,03 MiB | **15,00 MiB** |
| B breit | 500 | 15 000 | 0,73 MiB | 1,91 MiB | **9,00 MiB** |
| A lang | 2000 | 300 000 | 5,72 MiB | 24,03 MiB | **32,00 MiB** |
| B breit | 2000 | 60 000 | 2,92 MiB | 8,03 MiB | **16,00 MiB** |

> **Die Summe der Feldbreiten ist keine Dateigrösse.** Bei 100 Abonnementen
> liegt die gerechnete Zahl um das Einunddreissigfache daneben — InnoDB legt
> Seiten zu 16 KiB an, und ein Sekundärindex kostet noch einmal so viel wie die
> Daten. Wer eine Tabelle plant und sie rechnet, plant eine andere.

**Und die Reihenfolge des Einfügens zählt mit.** Dieselben 300 000 Zeilen:

| | Datei |
|---|---|
| Abo für Abo eingefügt | 32,00 MiB |
| **Tag für Tag** eingefügt — so schreibt ein Nachtlauf | **36,00 MiB** |

**+12,5 %.** Kein Plangrund, aber die Gegenprobe zu der Zeile darüber: Die
Grösse hängt nicht nur an der Zahl der Zeilen.

### Was M1 nicht sagt

- Nichts über den Nebenverkehr einer Datenbank im Betrieb — gemessen sind leere
  Tabellen auf einer ruhigen Maschine.
- Nichts über das **Schreiben** je Nacht, nur über das Liegen.
- Nichts über die Platte von `cloudsrv24`.
- Die Erhebung der vier fehlenden Grössen ist nicht mitgemessen; das ist M2
  bis M4.

---

## M2 · Wie man Traffic je Abo misst, und was es kostet

`docs/127` nennt zwei Kandidaten. **Einer davon ist keiner**, und das ist
gemessen.

### Der Zähler je Systembenutzer misst den falschen Benutzer

Prüfstand: eine eigene `inet`-Tabelle in nftables 1.0.9 mit zwei Zählern —
einer auf die uid eines angelegten Kundenbenutzers, einer auf `www-data` —,
davor ein echtes nginx mit einer 200 000 Byte grossen Datei.

| Zähler | nach einem Abruf von 200 000 B |
|---|---|
| Kunde (uid 1001) | **0 Pakete, 0 Bytes** |
| `www-data` (uid 33) | **9 Pakete, 200 720 Bytes** |

**Gegenprobe:** Verkehr, den der Kundenbenutzer selbst erzeugt, zählt auf seinen
Zähler (1 Paket, 60 B) — der Zähler arbeitet also. Er sieht den Website-Verkehr
nur nicht.

Der Grund steht in `PoolTemplate`: Der PHP-Pool eines Abonnements läuft als
`{$user}`, **nginx aber bedient jede Domain als `www-data`**. Statische Dateien
gehen gar nicht durch PHP. Ein Zähler je Systembenutzer misst damit genau das,
was ein Kundenskript nach **draussen** telefoniert — und nicht, was Besucher
holen.

> **Ein Zähler am Benutzer misst den, der den Socket besitzt — und das ist bei
> einer Website nie der Kunde.**

Damit **entfällt die Verschränkung von P9 mit P9b**, die `docs/127 §6.3` als
Entscheidung vorsieht: Es gibt nichts zu entscheiden.

### Aus den Protokollen — aber nicht aus diesem Format

`combined` führt `$body_bytes_sent`. Gemessen an einer Antwort mit genau
1000 Byte Körper:

| | |
|---|---|
| was in der Zeile steht (`body_bytes_sent`) | **1000 B** |
| was wirklich gesendet wurde (Körper + Kopfzeilen) | **1248 B** |
| was der Kunde angefragt hat (`request_length`) | **96 B** |
| **je Anfrage ungezählt** | **344 B** |

Bei einer 1-KB-Antwort fehlen 25 %. **Und der häufigste Fall auf einer Website
mit Wiederbesuchern ist der schlimmste** — gemessen an derselben Datei, einmal
voll und einmal mit passendem `If-None-Match`:

| | `body_bytes_sent` | `bytes_sent` | `request_length` |
|---|---|---|---|
| `200` voller Abruf | 1000 | **1248** | 96 |
| **`304 Not Modified`** | **0** | **189** | 111 |

Ein `304` schreibt eine **Null** in die Zeile, während 189 Byte hinaus- und
111 hereingehen. Ein Zähler über `combined` zählt einen wiederkehrenden
Besucher als **nichts**.

**Die Gegenprobe zeigt zugleich den Ausweg, und er kostet keine Rechnerei:**
nginx führt **`$bytes_sent`** — den ganzen gesendeten Umfang samt Kopfzeilen.
Gemessen ist er 1248 gegen die 1248, die `curl` gesehen hat (1000 Körper +
248 Kopf). Zusammen mit `$request_length` steht damit beides in derselben
Zeile, ohne dass irgendwo eine Kopfzeilengrösse geschätzt wird.

Es kostet eine Zeile in `SiteTemplate` — und einen Übergang: Alte Zeilen haben
acht Felder, neue neun. Ein Nachtlauf, der beide liest, muss das sehen; ein
Nachtlauf, der erst nach der ersten Rotation anfängt, muss es nicht.

### Was M2 nicht sagt

- Nicht, ob der Kunde damit dieselbe Zahl sieht wie sein Provider. Die zählt am
  Netzanschluss und nimmt TCP, TLS und Wiederholungen mit; keine der beiden
  gemessenen Quellen tut das.
- Nichts über IPv6, das dieser Container nicht hat.
- Nichts darüber, was ein eigenes `log_format` an Schreibkosten hinzufügt.

---

## M3 · Was ein Zugriffsprotokoll-Nachtlauf wirklich kostet

Prüfkörper: **200 000 Zeilen, 25 MiB, 133 B je Zeile**, aus zehn Pfaden, fünf
User-Agents und acht Statuscodes gewürfelt — **nicht** aus einer wiederholten
Zeile. Eine Datei aus einer Zeile misst den Zwischenspeicher des Prozessors und
nicht das Zerlegen.

| Lauf | Dauer | Zeilen/s |
|---|---|---|
| **kalt** (`drop_caches` davor) | 0,138 s | 1 451 479 |
| warm, erster | 0,071 s | 2 798 401 |
| warm, zweiter | 0,064 s | 3 113 887 |
| **Gegenprobe** · nur lesen, ohne Zerlegen | 0,013 s | 15 979 075 |

Die Gegenprobe steht um den **Faktor fünf** daneben — der Lauf misst also
wirklich das Zerlegen. Der Zwischenspeicher macht den **Faktor zwei**; wer nur
warm misst, meldet die doppelte Geschwindigkeit.

**Hochgerechnet:** Eine lebhafte Domain mit einer Million Zeilen am Tag kostet
kalt **0,7 s**. Hundert solcher Domains kosten gut eine Minute. Der Nachtlauf
ist keine Kostenfrage.

> **Die teure Frage an einem Nachtlauf über Protokolle ist nicht, was er
> kostet, sondern welchen Tag er zählt.** Das beantwortet M4.

### Was M3 nicht sagt

- Sie misst die Platte **dieses Containers**. Auf `cloudsrv24` ist der kalte
  Lauf eine andere Zahl, und nur dort ist sie verbindlich.
- Sie misst eine Datei, in die niemand nebenher schreibt.
- Sie sagt nichts über den Speicher, den ein Zähler je Domain und Tag braucht.
- Der Prüfkörper ist gebaut; eine echte Datei hat andere Häufigkeiten — und
  **wie** anders, sagt erst eine echte Datei.

---

## M4 · Was in diesen Dateien steht — und was die Rotation macht

Messvorschrift: `tests/zugriffsprotokoll-messen.sh`, gefahren gegen **nginx
1.24.0** und **logrotate 3.21.0**, mit dem Block aus `SiteTemplate::render()`
und der Vorlage aus `WebLogrotate::template()`. Angepasst sind zwei Dinge, beide
gedruckt: die Zeile `listen [::]:80` (dieser Container hat kein IPv6, und
`nginx -t` wäre sonst rot, bevor etwas gemessen ist) und die Pfade. **Die
`access_log`-Anweisung selbst bleibt Wort für Wort stehen.**

### Das Format ist nginx' Vorgabe

| | |
|---|---|
| `log_format` im ganzen Quelltext | **0 Treffer** |
| Felder der `access_log`-Anweisung | **2** — Pfad, kein Formatname |

Die Zeile, die dabei herauskommt:

```
127.0.0.1 - - [20/Sep/2026:12:33:35 +0000] "GET / HTTP/1.1" 200 1000 "-" "curl/8.5.0"
```

Das ist `combined`, Wort für Wort.

**Gegenprobe:** Ein zweiter Server-Block mit eigenem `log_format` auf demselben
nginx liefert

```
127.0.0.1|2026-09-20T12:33:35+00:00|GET|/index.html|200|1000|96|Mozilla/5.0 (…)
```

— eine nachweislich andere Zeile. Ohne sie wüsste niemand, ob die Messung das
Format misst oder nur, dass nginx überhaupt protokolliert.

### Die Zeile ist eindeutig zerlegbar, und zwar weil nginx maskiert

Die Sorge aus `docs/127` ist ein User-Agent mit Anführungszeichen. Gemessen mit
`Mozilla/5.0 (sagt "hallo"; ein \ Backslash)`:

| | |
|---|---|
| Anführungszeichen in der Zeile | **6** — die drei Paare von `combined` |
| was nginx aus `"` macht | `\x22` |
| was nginx aus `\` macht | `\x5C` |
| Stücke beim Trennen an `"` | **7** |

> **Ein Zerleger an den Anführungszeichen trägt — aber nur, solange er nicht
> vorher demaskiert.** Wer `\x22` zurückübersetzt und dann trennt, zerlegt eine
> Zeile, die es nie gab.

### Die Rotation benennt um, sie schneidet nicht ab

Gefahren mit der echten Vorlage; ersetzt ist allein der `postrotate`-Aufruf
(`systemctl kill` braucht systemd als PID 1). Geklammert wird mit `stat` davor
und danach, und verglichen wird die **Inode** — eine Grösse von 0 entstünde auch
bei `copytruncate`, und genau darin unterscheiden sich die beiden Formen.

| | |
|---|---|
| `access.log` davor | Inode **974980** |
| `access.log` danach | Inode **974984**, 0 B |
| `access.log.1` | Inode **974980** — dieselbe Datei, neuer Name |

Also **Umbenennen und neu anlegen**. Drei Folgerungen, alle gemessen:

1. **`create` schlägt `nocreate`.** Die Vorlage führt beide; die neue Datei
   entsteht mit `-rw-r----- root:adm`, also nach `create 0640 <user> adm`. Die
   Zeile `nocreate` in `WebLogrotate::template()` ist **wirkungslos**.
2. **`delaycompress` trägt.** Nach dem zweiten Lauf liegen da: `access.log`,
   `access.log.1` (unkomprimiert), `access.log.2.gz`. **Der Tag, den ein
   Nachtlauf zählen will, liegt in `.1` und ist nicht gepackt.**
3. **Ein Lesegriff, der vor der Rotation geöffnet wurde, liest die alte Datei
   zu Ende.** Gemessen: der offene Griff liefert `zeile-vor-rotation`, während
   `access.log` unter neuer Inode `zeile-nach-rotation` trägt.

> **Ein Nachtlauf, der `access.log` liest, zählt den angefangenen Tag. Der Tag,
> den er meint, heisst `access.log.1` — und nur bis zur nächsten Rotation.**

### Was M4 nicht sagt

- Nichts darüber, was auf `cloudsrv24` in den Dateien steht: Die Zeilen hier
  sind von `curl` erzeugt und tragen keinen echten Verkehr.
- Nichts über die Zeiten: Wann `logrotate` auf dem Server läuft und wann der
  Nachtlauf, ist eine Frage an die Timer und nicht an diese Messung.
- Nichts über `error.log` — gemessen ist allein das Zugriffsprotokoll.
- Die Vorlage ist mit `rotate 14` gemessen; ob vierzehn Tage die Frist sind, die
  der Plan nennen soll, ist eine Frage an den Betreiber (`docs/127 §6.3`).

---

## M5 · Wie ein Schwellenwert zu einer Meldung kommt, ohne 400 Mails zu erzeugen

**Die Form, nach der `docs/127` sucht, ist gebaut** — sie heisst
`FindingLog::replace()` und gehört der Bestandsdiagnose.

| | |
|---|---|
| Ablage | Tabelle `findings`, **8 Spalten** |
| Schlüssel | `unique(check, subject, reason)` — ein Eintrag je Zustand, nicht je Vorkommen |
| „seit wann" | `first_seen_at`, bleibt über Läufe stehen |
| „zuletzt bestätigt" | `measured_at` |
| Zustandsspalte | **gibt es nicht** — der Zustand kommt aus `FindingCheck::state()` |

Der Mechanismus: Eine Prüfung meldet je Lauf **alles**, was sie gefunden hat —
auch nichts. `forgetMissing()` löscht, was der Lauf nicht mehr nennt. Und eine
Prüfung, die **nicht laufen konnte**, meldet `UNREACHABLE` statt einer leeren
Liste.

> **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von beiden.**
> Hier bedeutet sie genau eines, und die andere Bedeutung hat einen eigenen
> Grund bekommen. (`FindingLog`)

Damit sind beide Hälften der Frage aus `docs/127` beantwortet, ohne etwas zu
bauen: *Wie lange muss ein Zustand halten?* — `now − first_seen_at`. *Wie kommt
er wieder heraus?* — der Lauf nennt ihn nicht mehr, die Zeile ist fort.

### Was fehlt, ist keine Entprellung, sondern eine Spalte

**Gemessen: Es gibt nirgends im Bestand eine Ablage „schon gemeldet".** Ein
Durchgang über `app/` und `database/migrations` nach `notified`, `benachrichtigt`
und `notification` findet **einen** Treffer, und der ist ein Kommentar in
`Support/Plans/Quota.php`, der auf P9 verweist.

> **Ein Nachtlauf, der jeden bestehenden Befund meldet, meldet ihn jede Nacht.**
> Die 400 Mails entstehen nicht an der Schwelle, sondern an der Zustellung —
> und die hat kein Gedächtnis.

`findings` trägt acht Spalten, und keine davon ist `notified_at`.

### Was M5 nicht sagt

- Nichts darüber, **wie lange** ein Zustand halten soll, bevor er meldet. Das
  ist keine Messung, sondern eine Entscheidung.
- Nichts über Kennzahlen: Die Diagnose prüft Zustände, nicht Verläufe. Ob
  „Kontingent zu 90 % voll" in dieselbe Tabelle gehört oder in eine eigene, ist
  offen.
- Nichts über den umgekehrten Weg — ob eine **Entwarnung** verschickt werden
  soll, wenn eine Zeile verschwindet.

---

## M6 · Ob eine Meldung über den Weg hinausgeht, der ausgefallen ist

### Die vierte Grenze steht, und ihr Wächter ist eine Liste

`docs/127 §3` sagt: *Ein Geheimnis, das als Argument eines Vorgangs reist, steht
auf der Vorgangsseite.* Nachgesehen:

| | |
|---|---|
| `resources/js/Pages/Operations/Show.vue:276` | `JSON.stringify(props.operation.payload …)` |
| `OperationPolicy::view()` | jeder Admin, dazu der Kunde des Abonnements |

Die Grenze hat zwei Wächter, und **gemessen greift keiner von beiden bei einem
Webhook**:

1. `SecretsStayOutOfTheQueueTest::CARRIES_A_SECRET` ist eine **von Hand
   gepflegte Liste von vier Operationsnamen**. Eine neue Operation, die ein
   Token trägt, steht darin nur, wenn jemand daran denkt.
2. Die zweite Hälfte liest **genau eine Migration** (`…create_databases_tables`)
   und sichert mit `assertSame(1, $read)` zu, dass sie sie gefunden hat.

**Gemessen, in beide Richtungen:**

| Eingriff | Wächter |
|---|---|
| `$table->string('webhook_secret')` in einer **neuen** Migration | **grün** |
| derselbe Spaltenname in `create_databases_tables.php` | **rot** |

Der Wächter lebt also — und ist eine Datei weiter blind. Der Arbeitsbaum stand
nach beiden Eingriffen wieder sauber.

> **Ein Wächter, der einen Ort prüft statt einer Regel, ist an jedem anderen Ort
> ein Freispruch.**

### Der Mailweg hat keine Zeitgrenze

`config/mail.php` führt für `smtp` **`'timeout' => null`**; PHPs
`default_socket_timeout` steht auf **60 s**. Gemessen gegen eine selbstgebaute
SMTP-Senke, einen geschlossenen Hafen und eine Adresse, die Pakete verschluckt:

| | Dauer | Ausgang |
|---|---|---|
| **Gegenprobe** · Senke antwortet | 0,05 s | gesendet |
| Hafen zu (`connection refused`) | 0,00 s | `TransportException` |
| **Schwarzes Loch, `timeout` wie im Bestand** | **60,02 s** | `TransportException` |
| Schwarzes Loch, `timeout` auf 5 s | 5,00 s | `TransportException` |

Ohne die Gegenprobe wäre diese Messung wertlos gewesen — ihr erster Anlauf
meldete für **alle vier** Fälle `localhost:25`, weil der Aufsatz den Prüfling
gar nicht erreichte. Vier plausible Fehlermeldungen, und keine davon über den
gemessenen Gegenstand.

> **Der Regler ist da, er steht nur auf `null`.** 400 Meldungen gegen einen
> toten Versand sind 6,7 Stunden, und der nächtliche Lauf hält so lange.

### Der Weg nach draussen, den es schon gibt

`Acme\Curl` ist die Umsetzung von `Outbound` und das Vorbild aus `docs/117 §5`:

| | |
|---|---|
| `CONNECT_TIMEOUT` | 10 s |
| `TIMEOUT` | 30 s |
| Antwort gedeckelt | 512 KiB |
| Schema | **nur `https`**, abgewiesen vor curl |
| TLS | `VERIFYPEER`, `VERIFYHOST 2` |

Er liegt im **Agenten** — Grenze 1 — und ist in jeder Richtung begrenzt, in der
der Mailweg es nicht ist.

### Was M6 nicht sagt

- Nichts darüber, was ein **kranker** Mailserver tut — gemessen ist ein toter
  und ein schweigender. Ein Server, der die Verbindung annimmt und dann 40 s
  für `250 OK` braucht, ist ein dritter Fall.
- Nichts über Warteschlange und Wiederholung: Gemessen ist ein einzelner
  Versand, nicht `retry_after`.
- Nichts darüber, wo die Zugangsdaten eines Webhooks **liegen** sollen — nur,
  wo sie **nicht** liegen dürfen.
- Der Prüfkörper ist eine selbstgebaute Senke, kein echter MTA.

---

## M7 · Was eine Zeitreihe je Domain auf der Seite kostet

### Die Abfragen sind keine Frage

Gemessen an der Tabelle aus M1 mit 300 000 Zeilen, für **ein** Abonnement,
fünf Kennzahlen, 30 Tage:

| | Abfragen | kalt | warm | Punkte |
|---|---|---|---|---|
| eine Abfrage je Kachel | 5 | 0,0040 s | 0,0006 s | 150 |
| eine Abfrage für alle | **1** | **0,0006 s** | 0,0002 s | 150 |

Die Punktzahl ist in beiden Zeilen gleich — sonst mässen sie Verschiedenes. Der
Unterschied ist der Faktor sieben auf einer Zahl, die unter fünf Millisekunden
liegt.

**Die Falle aus `docs/103 M5` bleibt die eigentliche:** Ein fertiger Wert in
`share()` läuft bei **jeder** Anfrage, auch bei einer, die ihn nicht mitschickt.
Wer Stützstellen teilt, teilt einen Verschluss.

### Acht Kacheln bei 390 px sind 1405 px Seite

Gemessen im vorinstallierten Chromium gegen **beide** gebauten Stylesheets —
`app-CLs_eKys.css` trägt die Seitenregeln, `app-BYG-Hy0q.css` die
`scoped`-Regeln der Komponenten; wer eines von beiden nimmt, misst eine Seite,
die aussieht wie eine Seite.

| | 390 px | 1440 px |
|---|---|---|
| **Ladebeleg** `.tiles` display | `flex` | `flex` |
| **Ladebeleg** `.trend` Höhe | `46px` | `46px` |
| Kachel | 390 × 172 px | 206 × 216 px |
| **acht Kacheln zusammen** | **1405 px** | 394 px |
| Zeilen | **8** | 2 |
| Trenner der zweiten Kachel | oben 1px, links 0 | oben 0, links 1px |
| waagerechter Überlauf | **0 px** | **0 px** |
| **Gegenprobe** (muss 200 sein) | **200 px** | **200 px** |

Die Null beim Überlauf ist eine Messung, weil die Gegenprobe daneben bei
**beiden** Breiten genau 200 ergibt — die Falle aus `docs/59` Befund 22.

Der Stapel trägt: `--kachel-min: 100%` ab 720 px, und der Trenner dreht sich von
links nach oben. Das ist seit dem Befund vom Telefon des Betreibers so und wird
von `MobileLayoutTest` gehalten.

> **Acht Kacheln kosten bei 390 px keinen Überlauf, sondern knapp vier
> Bildschirmhöhen — bevor auf der Seite irgendetwas anderes steht.**

### Was M7 nicht sagt

- Der Aufsatz ist handgeschriebenes Markup mit dem echten Stylesheet, **nicht**
  die echte Seite mit echten Daten. Er trifft die Lage aufs Pixel (`docs/56`
  Punkt 5); er sagt nichts darüber, was `Tile.vue` bei echten Reihen rechnet.
- Die Zahlen gelten für Kacheln **ohne** zweite Richtung bis auf eine; fünf
  gepaarte wären höher.
- Nichts über den Bildlauf mit einem Finger, nichts über die Ladezeit.
- Die Abfragen sind gegen eine ruhige Datenbank gemessen.

---

## M8 · Ob ein Token trägt, was API v1 braucht

Gemessen am gebooteten Kernel und nicht am Quelltext.

| | |
|---|---|
| Gruppe `web` | **13 Einträge**, `ApplyTenancy` an siebter Stelle |
| Gruppe `api` | **1 Eintrag** — `SubstituteBindings` |
| Routen unter `api/` | **0** |
| `routes/api.php` | **nein** |
| `withRouting(…)` | trägt `web`, `commands`, `health` — **kein `api`** |

Der Haken, den `docs/127 §6.1` nennt, ist gesetzt:
`shouldRenderJsonWhen(fn ($r) => $r->is('api/*'))`. Er entscheidet, wie ein
Fehler aussieht, und nicht, wer ihn bekommt.

**Die Klammer hängt aber nicht an der Middleware.** Sie steht als globaler
Scope in `Subscription::booted()`. Gemessen mit zwei Abonnements in der
Tabelle:

| Zustand | Antwort | SQL |
|---|---|---|
| **Ladebeleg** · Zeilen roh in der Tabelle | 2 | — |
| Grundzustand (`ApplyTenancy` lief nie) | **0** | `… where 0 = 1` |
| eingeschränkt auf Abonnement 1 | 1 | `… where "id" in (1)` |
| **Gegenprobe** · unbeschränkt (Admin) | 2 | `select * from "subscriptions"` |

Die Null ist eine Messung, weil daneben eine Eins und eine Zwei stehen.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.** `docs/127` sagt das, und es stimmt —
> **und genau darin liegt die Gefahr.** Eine api-Route, die den Mandanten zu
> setzen vergisst, liefert `200 []`. Ein Kunde ohne Abonnements und ein Kunde,
> dessen Klammer nie gesetzt wurde, sehen von aussen gleich aus.

### Was M8 nicht sagt

- Nichts über Tokens: Es gibt keine, und was eines tragen müsste, ist eine
  Frage an den Zuschnitt und nicht an den Bestand.
- Nichts über `RouteGuard` an einer Route ohne `can:` — dafür müsste eine
  solche Route existieren; heute tut sie es nicht.
- Nichts über Ratenbegrenzung, Fassungspflege oder OpenAPI.
- Gemessen ist `Subscription`; die anderen mandantengebundenen Modelle klammern
  über `BelongsToSubscription` und sind **nicht einzeln** nachgemessen.

---

## M9 · Was der Log-Strom auf jeder Seite kostet

### Die Zahlen stehen im Repo

| | |
|---|---|
| `packaging/etc/fpm.conf` | `pm = dynamic`, **`pm.max_children = 12`** |
| | `request_terminate_timeout = 3600s` |
| `config/srvpanel.php` | `stream_seconds = 300` |
| Seiten insgesamt | **58** |
| Seiten, die einen Strom öffnen | **1** — `Operations/Show.vue` |

Ein einziger Ort öffnet eine `EventSource`: `useOperationStream.ts`.

### Und was ein belegter Pool wirklich tut

Prüfstand: eigenes php-fpm 8.3.6 mit **`pm = static`, `pm.max_children = 2`** —
derselbe Bau wie der Panel-Pool, nur zwei statt zwölf —, davor nginx mit
`fastcgi_buffering off`.

| Lage | Antwortzeit von `sofort.php` |
|---|---|
| **Gegenprobe** · Pool frei | 0,007–0,014 s |
| ein Strom offen (1 von 2) | 0,011 s |
| **zwei Ströme offen (2 von 2)** | **15,987 s** |
| **Gegenprobe** · danach wieder frei | 0,008 s |

Drei schnelle Zahlen um die eine langsame herum — die 16 Sekunden sind gemessen
und kein Ausreisser. Die Anfrage wartete, bis ein Strom endete.

> **Zwölf offene Ströme machen das Panel für alle unerreichbar, und zwar für bis
> zu fünf Minuten.** `request_terminate_timeout` hilft nicht: Es steht auf einer
> Stunde. Die einzige Grenze ist `stream_seconds`.

Der Vorschlag aus `docs/92 §4` — ein Log-Strom auf jeder Seite — hiesse, aus
**einer** von 58 Seiten alle 58 zu machen.

### Was M9 nicht sagt

- Gemessen ist ein Pool von **zwei**, nicht von zwölf. Die zwölf stehen im
  Paket; was auf `cloudsrv24` wirklich läuft, ist nicht nachgesehen.
- Nichts darüber, wie viele Leute gleichzeitig im Panel sind.
- Nichts über das Abfragen im Takt als Alternative: `poll_ms` steht auf 500,
  gemessen ist es nicht.
- `pm = dynamic` kann zwischen `start_servers` und `max_children` wachsen; der
  Prüfstand stand auf `static`, damit die Grenze sichtbar ist.

---

## Was auf dem Server zu messen bleibt

Keines davon ist im Container zu beantworten, und keines darf geschätzt werden:

1. **Eine echte `access.log`** (M3, M4) — Grösse, Zeilenzahl, die wirkliche
   Verteilung der Statuscodes, und **wann** `logrotate` gegenüber dem Nachtlauf
   läuft. `tests/zugriffsprotokoll-messen.sh` ist dafür nicht gebaut; es stellt
   seinen Prüfstand selbst her. Für den Server taugt die Klammer aus `docs/921`:
   `stat` davor und danach.
2. **Der kalte Durchsatz der Platte** (M1, M3) — 1,45 Mio. Zeilen/s sind hier
   gemessen und dort eine Vermutung.
3. **Die Grösse des FPM-Pools im Betrieb** (M9) und wie viele Leute gleichzeitig
   angemeldet sind.
4. **Was der echte Mailweg tut**, wenn der Server unter Last steht (M6) — der
   dritte Fall neben tot und schweigend.
5. **Ob `system_users` und die Kundenverzeichnisse** eine Traffic-Erhebung je
   Abo überhaupt tragen, wenn eine Domain umzieht (M2).

---

## Korrekturen an `docs/127`

- **§6.2 M4 sagt, das Format sei „unsere Zeile und nicht nginx' Vorgabe".** Es
  ist nginx' Vorgabe: `log_format` steht nirgends im Repo, und `access_log`
  trägt nur einen Pfad. Die Folge ist nicht kosmetisch — `combined` zählt den
  Rumpf ohne Kopfzeilen, und bei einem `304` zählt es **null**, während
  189 Byte hinausgehen. Damit hängt M2 daran.
- **§6.2 M2 führt nftables-Zähler als zweiten Kandidaten.** Sie können
  Website-Verkehr nicht zuordnen, weil nginx jede Domain als `www-data`
  bedient. Die daran hängende Entscheidung aus **§6.3** („ob Traffic aus den
  Protokollen kommt oder aus nftables-Zählern") entfällt.
- **§6.2 M3 fragt, wie teuer der Nachtlauf ist.** Er ist billig. Die Frage, die
  bleibt, ist die nach dem Zeitpunkt.
- **§6.2 M5 fragt nach einer Entprellung.** Die Form steht; was fehlt, ist eine
  Ablage der Zustellung.

## Korrekturen an `CLAUDE.md`, „Diese Umgebung"

- **Die festgenagelte PHP-Fassung ist `8.3.6-0ubuntu0.24.04.11`**, nicht `.10`.
  Mit `.10` schlägt der Aufruf fehl und verwirft wieder alle fünf Pakete.
- **`apt-get update` gehört vor jede Installation**, nicht nur vor PowerDNS:
  Der Index eines frischen Containers ist alt genug für `404` auf nginx.
- **Der Scratchpad ist `drwx------`.** Ein nginx-Prüfstand darin ist für den
  Arbeiter als `www-data` nicht erreichbar; die Meldung lautet
  „Permission denied" und liest sich wie ein Befund am Prüfling. Prüfstände mit
  fremden Kennungen gehören nach `/var/tmp`.
- **Eine eigene `nginx.conf` erbt `user` nicht.** Wer `/etc/nginx/nginx.conf`
  nicht einbindet, bekommt Arbeiter als `nobody` — und ein `502` an einem
  Socket, dessen Rechte richtig aussehen.

---

## Was diese Runde über das Messen gelernt hat

**Drei Messungen haben beim ersten Anlauf ihren Gegenstand nicht erreicht, und
jedes Mal hat es die Gegenprobe gefangen:**

| | was aussah wie ein Ergebnis | was es war |
|---|---|---|
| M6 | vier plausible `TransportException` | alle vier gegen `localhost:25` — der Aufsatz erreichte den Prüfling nicht |
| M9 | drei Antworten in 0,007 s | drei `502` — nginx lief als `nobody` und kam nicht an den Socket |
| M4 | eine Zeile im Protokoll | ein `404` auf eine Datei, die im falschen Verzeichnis lag |

> **Eine Messung, bei der der Prüfling gar nicht geladen wurde, sieht aus wie
> ein Ergebnis.** Das steht seit dem 11. September in `CLAUDE.md`, und es ist in
> dieser Runde dreimal fällig geworden — zweimal an einer Zahl, die *schneller*
> war als erwartet.

**Und eine vierte Lehre, die neu ist:** Die Gegenprobe von M6 war nicht „ist die
Zahl plausibel", sondern „steht neben den drei Fehlschlägen **ein Erfolg**".
Ohne die selbstgebaute SMTP-Senke hätten vier gleichlautende Fehlermeldungen wie
vier Messungen ausgesehen.

> **Drei Fehlschläge nebeneinander belegen nichts. Erst der Erfolg daneben sagt,
> dass das Werkzeug funktioniert und der Prüfling geantwortet hat.**

**Ein fünftes Mal hat es keine Gegenprobe gefangen, sondern shellcheck.** In
`tests/zugriffsprotokoll-messen.sh` stand

    php -r '…' REPO="$REPO"

— das übergibt `REPO=` als **Argument** an das PHP-Skript und nicht als
Umgebungsvariable. `getenv("REPO")` lieferte `false`, der Pfad zum Autoloader
war falsch, und gearbeitet hat allein der Rückfall dahinter, den ein
`2>/dev/null` stumm gemacht hatte. Die Vorschrift lieferte die ganze Zeit
richtige Zahlen — über einen Weg, den niemand gemeint hatte.

> **Ein Rückfall, der immer greift, ist kein Rückfall, sondern der Hauptweg —
> und er verbirgt, dass der gemeinte nie gelaufen ist.**

`bash -n` hat dazu nichts gesagt; es beantwortet „parst es" und nicht „stimmt
es". Der Aufruf steht in `CLAUDE.md` und ist einmal Kopieren:
`shellcheck -e SC1091 <datei>`.
