# Die Messrunde vor P7

Gefahren am 21. August 2026 im Entwicklungscontainer, gegen zwei
Wegwerf-Instanzen von **PowerDNS 4.8.3** — eine mit `gsqlite3`, eine mit
`gmysql` gegen **MariaDB 10.11.14**, also gegen dieselbe Datenbankfassung, die
auf `cloudsrv24` läuft.

Sie kommt vor dem Plan, weil dieses Projekt damit schon einmal ein
Abnahmekriterium umgeworfen hat, bevor eine Zeile Plan entstand (`docs/38 §2`).

> **Diesmal hat sie mehr umgeworfen als ein Kriterium: die ganze Stufe.** Am
> selben Tag hat der Betreiber gefragt, ob ein eigener Nameserver überhaupt
> Sinn ergibt, und die Zahlen unten waren die Antwort — §4.4 (die Zone wird bei
> einem gewöhnlichen Datenbank-Neustart dunkel) und §4.5 (ein CSK, dessen DS das
> Panel nicht setzen kann). **P7 führt keine Zone mehr** (`docs/72`).
>
> Dieses Dokument bleibt vollständig stehen. Eine Messrunde, die eine
> Entscheidung *gegen* etwas trägt, ist genauso viel wert wie eine dafür — und
> wer die Frage in einem Jahr noch einmal aufmacht, fängt nicht bei null an.
Die Fragen, die sie beantwortet, und die elf Entscheidungen des Betreibers
stehen in `docs/70`.

**Das Messmittel liegt als `tests/dns-messen.sh` im Repo** und nicht in einem
Sitzungsverlauf:

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.**

---

## 1. Was diese Runde ausdrücklich nicht misst

- **Debian 12 und 13.** Der Proxy dieses Containers gibt `deb.debian.org` einen
  403. Die Befehlsfolge für den Betreiber steht in `docs/70 §14.1`.
- **Port 53 gegen `systemd-resolved`.** Hier gibt es kein systemd
  (`docs/70 §14.2`).
- **Eine echte Validierung durch einen fremden Auflöser.** Gemessen ist, dass
  die Zone signiert ausgeliefert wird (§4.5) — nicht, dass ein validierender
  Auflöser der Welt sie annimmt. Das braucht einen DS beim Registrar und gehört
  in den Abnahmelauf auf `cloudsrv24`.
- **Last.** Wie sich der Dienst bei tausend Zonen verhält, ist nicht gemessen.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

---

## 2. Wie man den Wegwerf-Dienst hochzieht

Hier stand für PowerDNS bisher nichts, und die Übergabe führte es als
ungemessen. Es geht — derselbe Satz wie bei MariaDB, beim `sshd` und bei
PHPStan:

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.**

```bash
apt-get update                       # ohne das: 404 auf drei Dateien
apt-get install -y pdns-server pdns-backend-sqlite3 pdns-backend-mysql pdns-tools
```

`pdns-server` kommt als **4.8.3-4build3** aus `noble/universe`. Zwei Dinge, die
Zeit gekostet haben:

- **Der Sockelpfad muss kurz sein.** Wie bei PostgreSQL reisst der Scratchpad
  die Grenze von 107 Byte; `socket-dir` zeigt deshalb auf `/tmp/pd`, die Daten
  bleiben im Scratchpad.
- **`--config-name=mysql` sucht `pdns-mysql.conf`**, nicht `pdns--mysql.conf`.
  Der Bindestrich steht schon drin. Ein Lauf mit `--config-name=-mysql` meldet
  „Unable to launch, no backends configured for querying" — was nach einem
  Fehler in der Konfiguration aussieht und einer im Aufruf ist.

Die zweite Instanz braucht eine MariaDB, und die läuft hier nur mit
`--user=root` (sonst: „Please consult the Knowledge Base to find out how to run
mysqld as root"). Das Schema liegt als
`/usr/share/pdns-backend-mysql/schema/schema.mysql.sql` im Paket und legt
**sieben InnoDB-Tabellen** an: `domains`, `records`, `supermasters`, `comments`,
`domainmetadata`, `cryptokeys`, `tsigkeys`.

---

## 3. Die Messungen im Überblick

| # | Frage | Ergebnis |
|---|---|---|
| 1 | Läuft PowerDNS in diesem Container? | **Ja**, 4.8.3, beide Backends |
| 2 | Spricht die API HTTPS? | **Nein** — `curl` bricht mit 35 ab |
| 3 | Kennt 4.8.3 eine TLS-Option für den Webserver? | **Nein** |
| 4 | Zone anlegen | 201, SOA und NS entstehen von selbst |
| 5 | Zone doppelt anlegen | 409 |
| 6 | Unbekannte Zone löschen | 404 |
| 7 | Ganze Zonenvorlage in einem Aufruf | 204 |
| 8 | Kaputte A-Adresse | 422 |
| 9 | MX ohne Priorität | 422 |
| 10 | Unbekannter Satztyp | 422 |
| 11 | Name ausserhalb der Zone | 422 |
| 12 | CNAME am Apex neben dem SOA | 422 |
| 13 | Gültiger Satz — Gegenprobe | **204** |
| 14 | Gut und kaputt in einem Aufruf | **Atomar** — 422, das Gute steht nicht im Bestand |
| 15 | Falscher API-Schlüssel / keiner | 401 |
| 16 | Änderung über die API sichtbar nach | **0,8 ms** (2,8 ms API, 3,2 ms sichtbar im zweiten Lauf) |
| 17 | Dieselbe Änderung am Bestand vorbei | **62 431 ms** |
| 18 | Zwischenspeicher-Vorgaben | `cache-ttl 20`, `query-cache-ttl 20`, `negquery-cache-ttl 60`, `dnssec-key-cache-ttl 30` |
| 19 | Platzhalter `*` | löst auf; ein echter Eintrag daneben ebenfalls |
| 20 | DNSSEC einschalten | 204, **CSK** mit `ECDSAP256SHA256`, DS in drei Digest-Arten |
| 21 | Signierte Auslieferung | A und SOA je zwei Sätze, DNSKEY vorhanden |
| 22 | DNSSEC ausschalten | 204, Bestand sofort leer — **Auslieferung erst nach 30 s** |
| 23 | MariaDB weg, sofort | DNS **NOERROR** aus dem Zwischenspeicher, API **500** |
| 24 | MariaDB weg, nach 25 s | DNS **SERVFAIL**, API 500 |
| 25 | MariaDB zurück | nach **5 s** beides wieder gesund, ohne Neustart |
| 26 | Stirbt der pdns-Prozess dabei? | **Nein** |

---

## 4. Die sechs Messungen, die etwas entscheiden

### 4.1 Die API spricht kein HTTPS, und es gibt keine Option dafür

`curl` bricht mit Rückgabewert 35 ab. **Die Gegenprobe gehört dazu:** Ein
gescheitertes HTTPS sieht genauso aus wie ein Port, den es nicht gibt — daneben
steht deshalb derselbe Port über HTTP mit `200` und 327 gelesenen Bytes. Und die
Optionsliste von 4.8.3 kennt für den Webserver `webserver-address`,
`-port`, `-allow-from`, `-loglevel`, `-max-bodysize` und
`-hash-plaintext-credentials` — **keine für ein Zertifikat, keine für einen
Schlüssel und keine für einen Unix-Socket.**

Damit stösst `docs/20 §9` auf die erste der vier Zusagen aus
`agent/src/Acme/Curl.php`. Die Entscheidung dazu steht in `docs/70 §13`: eine
benannte Ausnahme für die Rückschleife, eng gefasst und mit Wächter.

### 4.2 Die API prüft selbst — und sie prüft atomar

Fünf verschiedene Fehler, fünfmal `422`, jedes Mal mit einer Begründung im
selben Feld:

```json
{"error": "Record x.zone./A '999.1.2.3': Parsing record content
           (try 'pdnsutil check-zone'): unable to parse IP address"}
```

Und ein gültiger Satz daneben mit `204` — ohne diese Gegenprobe wäre „alles
wird abgewiesen" von „die Verbindung ist kaputt" nicht zu unterscheiden.

**Der Aufruf ist atomar.** Ein guter und ein kaputter Satz in *einem* PATCH:
422, und der gute steht danach nicht im Zonenbestand. Damit ist „ein Zonenfehler
wird nicht übernommen" aus `docs/20 §9` keine Eigenschaft, die das Panel bauen
muss, sondern eine, die es **belegen** muss.

**Aber die Begründung ist für einen Serveradministrator geschrieben und nicht
für einen Kunden.** „try 'pdnsutil check-zone'" nennt ein Programm, das der
Kunde nicht hat und nicht haben soll. Der Text darf deshalb nicht durchgereicht
werden — er gehört übersetzt, und `docs/19` sagt wie. Das ist ein eigener
Schritt im Plan und keine Zeile nebenbei.

> **Eine Meldung, die den Namen eines Werkzeugs nennt, das der Leser nicht hat,
> beantwortet die Frage eines anderen.**

### 4.3 Die eigene Zone braucht kein Warten — gemessen mit Gegenprobe

Eine Änderung über die API ist **0,8 ms** nach der Antwort ausgeliefert (im
zweiten Lauf 3,2 ms nach 2,8 ms API-Zeit). Das ist der Unterschied zu jedem
externen Anbieter, für den `Patience` und `Resolver::ready()` überhaupt
existieren — dort sind es 60 bis 900 Sekunden (`docs/34 §11`).

**Die Zahl allein wäre wertlos**, denn sie sähe genauso aus, wenn gar kein
Zwischenspeicher liefe. Zwei Dinge stehen deshalb daneben:

1. **Die Vorgaben laufen**, keine davon steht in der Wegwerf-Konfiguration:
   `cache-ttl 20`, `query-cache-ttl 20`, `negquery-cache-ttl 60`.
2. **Dieselbe Änderung am Bestand vorbei** — direkt in die Tabelle `records`,
   an der API vorbei — ist erst nach **62 431 ms** ausgeliefert. Die Zeile steht
   nach 1,2 ms im Bestand; ausgeliefert wird sie 62 Sekunden später, passend zu
   `negquery-cache-ttl 60`.

Der Faktor zwischen beiden Wegen ist rund **78 000**. Damit hat der Satz „über
die HTTP-API, nicht über die Datenbank" aus `docs/20 §9` zum ersten Mal eine
Zahl statt einer Begründung.

> **Ein zweiter Schreiber in derselben Datei ist kein zweiter Schreiber, solange
> nur einer die Sperre nimmt** — und hier ist es nicht einmal eine Sperre,
> sondern ein Zwischenspeicher, der von der Änderung nichts weiss.

**Für P7 heisst das:** Eine ACME-Bestellung gegen die eigene Zone wartet nicht.
Der Faden aus P4, den `docs/20` als „DNS-01 gegen die eigene Zone (nach P7
automatisch)" führt, wird damit nicht nur eingelöst, sondern schneller als jeder
der acht Anbieter.

### 4.4 Der Nameserver überlebt den Ausfall der Datenbank — und findet allein zurück

Das ist das benannte Risiko aus Entscheidung 3 (`docs/70 §13`), und es ist
gemessen statt befürchtet:

| Lage | DNS | API |
|---|---|---|
| MariaDB läuft | `NOERROR`, 1 Antwort | 200 |
| MariaDB gestoppt, sofort | `NOERROR`, 1 Antwort (aus dem Zwischenspeicher) | **500** |
| MariaDB gestoppt, nach 25 s | **`SERVFAIL`**, 0 Antworten | 500 |
| MariaDB zurück, nach 5 s | `NOERROR`, 1 Antwort | 200 |

**Der Prozess stirbt nicht**, und das ist die Umkehrung des `sshd` aus P6: Dort
tötet ein Neuladen mit einer kaputten Datei den Dienst, hier bedient er weiter,
solange der Zwischenspeicher trägt, und erholt sich ohne Neustart.

Drei Dinge, die daraus in den Plan gehören:

1. **`SERVFAIL` und nicht `NXDOMAIN`.** Der Unterschied ist gross: `SERVFAIL`
   heisst „kaputt, frag später nochmal", `NXDOMAIN` hiesse „gibt es nicht" — und
   das letzte hätten Auflöser negativ zwischengespeichert und Mailserver als
   endgültig behandelt.
2. **Die API meldet den Ausfall ungefähr zwanzig Sekunden, bevor Kunden ihn
   sehen.** Das ist die Zeitspanne, in der eine Überwachung noch handeln kann,
   und sie ist der Grund, die API und nicht den DNS-Port zu überwachen.
3. **Die Erholung ist selbsttätig.** Ein Rückweg, der einen Neustart des
   Nameservers verlangt, wäre hier falsch gebaut.

### 4.5 DNSSEC ist ein einziger Aufruf — und der Schlüssel ist ein CSK

`PUT {"dnssec": true}` genügt: 204, danach steht ein **CSK** (kein KSK/ZSK-Paar)
mit `ECDSAP256SHA256` da, und die API liefert die DS-Angaben in drei
Digest-Arten (SHA-1, SHA-256, SHA-384). Die signierte Zone liefert für `A` und
`SOA` je zwei Sätze statt einem — der Satz und seine Signatur —, und `DNSKEY`
ist vorhanden.

**Die Gegenprobe unterscheidet:** Dieselbe Frage an die unsignierte Zone der
anderen Instanz ergibt `an=0` bei 103 Byte gegen `an=2` bei 233 Byte.

**Dass es ein CSK ist, entscheidet, was „Schlüsselwechsel" aus `docs/20 §9`
heisst.** Bei getrennten KSK und ZSK wechselt man den ZSK, ohne den Registrar
anzufassen; bei einem CSK hängt an jedem Wechsel ein neuer DS. Der Plan muss
sagen, welches von beidem P7 anbietet — und die zweistufige Führung aus
Entscheidung 7 gilt dann für jeden Wechsel und nicht nur für das Einschalten.

### 4.6 Schlüssel und Einträge laufen verschieden schnell aus

Das ist die Messung, die beinahe als Fehler durchgegangen wäre.
`PUT {"dnssec": false}` meldet 204, und **zwei Sekunden später liefert die Zone
weiterhin DNSKEY aus.** Das sah nach einem Abschalten aus, das nicht abschaltet.

Nachgesehen: Die API sagt `dnssec: false`, die Liste der `cryptokeys` ist leer,
und die Tabelle `cryptokeys` in MariaDB ist es auch. Der Bestand ist also sofort
richtig — es ist `dnssec-key-cache-ttl` mit seiner Vorgabe von **30 Sekunden**.
Nach Ablauf: `an=0`, während der A-Satz derselben Zone weiter mit `an=1`
antwortet.

**Die Gegenprobe ist genau diese zweite Zeile.** Ohne sie hiesse `an=0` womöglich
nur, dass die Zone tot ist.

> **Eine Änderung, die man sofort danach misst, misst den Zwischenspeicher und
> nicht die Änderung.**

Und die Asymmetrie gehört in den Plan: **Eintragsänderungen räumen den
Zwischenspeicher aus (0,8 ms), Schlüsseländerungen nicht (bis 30 s).** Jeder
Abnahmeschritt, der DNSSEC schaltet und danach fragt, braucht diese Wartezeit —
sonst prüft er den alten Zustand und nennt ihn einen Befund.

---

## 5. Der Fehler in dieser Messvorschrift selbst

Der erste Lauf von `tests/dns-messen.sh` hat die Atomarität so geprüft: PATCH
mit einem guten und einem kaputten Satz, danach den guten Namen beim
Nameserver erfragen und `NXDOMAIN` erwarten.

Die Antwort war `NOERROR` mit einer Antwort — und das sah aus wie ein Befund am
Prüfling. Es war der **Platzhalter** aus derselben Zonenvorlage, zwei Abschnitte
weiter oben gesetzt: `*.zone` beantwortet jeden Namen darunter, auch einen, den
es nicht gibt.

> **Eine Gegenprobe, die ein Platzhalter beantwortet, hat den Gegenstand nicht
> gefragt.**

Gefragt wird seitdem der **Zonenbestand über die API** und nicht der
Nameserver: Dort steht ein RRset oder es steht keines, und ein Platzhalter kann
die Frage nicht beantworten. Die Zeile mit der Nameserver-Antwort steht weiterhin
darunter — als Beleg für die Falle und nicht als Prüfung.

Das ist derselbe Fehler wie „ein Prüfkörper, der die Seite ohne den Gegenstand
misst" aus `docs/62`, nur an einem anderen Gegenstand. Und er ist nur
aufgefallen, weil die Vorschrift **gefahren** wurde:

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

### 5.1 Und ein zweiter, im Wegwerf-Gestell dieses Containers

Das Gestell aus `CLAUDE.md` („Diese Umgebung") fährt hier 1218 Wächter grün, 17
rot, 281 Löcher. **Die 17 roten sind keine Befunde**, und das ist gemessen und
nicht angenommen: Derselbe Durchgang gegen den Stand von `main` ergibt dieselben
1218/17/281. Der Unterschied von zwei Behauptungen ist `DocLinkTest`, der die
Verweise der beiden neuen Dokumente mitzählt.

Beim Nachsehen ist dabei etwas aufgefallen, das für jeden gilt, der dieses
Gestell benutzt: **Dieselben drei Wächter sind einzeln gefahren einmal rot und
im Bündel achtmal.** Die Ursache ist das gemeinsame `require_once` aller
Testdateien in einem Prozess — was die eine Datei lädt, steht der nächsten im
Weg.

> **Ein Wächter, der im Bündel etwas anderes meldet als allein, misst seine
> Nachbarn mit.**

Das ist die Umkehrung des Satzes aus `CLAUDE.md`, dass ein einzeln beissender
Eingriff im Lauf nicht beissen muss — hier beisst im Lauf etwas, das allein
schweigt. Wer mit diesem Gestell einen Befund hat, fährt ihn **einzeln nach**,
bevor er ihn aufschreibt.

---

## 6. Was der Plan aus dieser Runde mitnimmt

1. **Die HTTP-API kann alles, was `docs/20 §9` verlangt** — Zonen, Einträge,
   DNSSEC, Schlüssel, DS — und sie prüft selbst. Der Plan baut keine
   Inhaltsprüfung nach, er baut eine **Übersetzung** der Begründungen (§4.2).
2. **Die Ausnahme in `Curl`** ist unvermeidlich und gehört eng gefasst (§4.1).
3. **Kein `Patience` für die eigene Zone** (§4.3) — und ein Wächter, der dafür
   sorgt, dass niemand die Wartelogik der Anbieter versehentlich mitbenutzt.
4. **Die Überwachung fragt die API**, nicht den DNS-Port (§4.4).
5. **Schlüsselwechsel heisst bei einem CSK immer auch DS-Wechsel** (§4.5) — die
   zweistufige Führung gilt für jeden Wechsel.
6. **Jede Messung nach einer Schlüsseländerung wartet 30 Sekunden** (§4.6).
7. **Die zwei Messungen am Server** aus `docs/70 §14` fehlen weiterhin und
   gehören vor den Plan.
