# 98 — A10: die Diagnose des Bestands

**Geschrieben am 2. September 2026**, nach der Messrunde und vor der ersten
Zeile Code. Die Messrunde steht als `docs/81 §2.3o`; die Skizze, aus der A10
kommt, als `docs/81 §11`. Die Übergabe ist `docs/97`.

**Dieses Dokument ist der Plan.** Was hier als Zahl steht, ist gemessen; was als
Frage steht, entscheidet der Betreiber (§9), und **vor dieser Entscheidung wird
nicht gebaut**.

---

## 0. Warum A10 überhaupt

Die Mehrzahl der teuren Befunde aus `docs/45`, `docs/62` und `docs/66` waren
Zustände, die ein regelmässiger Bestandslauf gefunden hätte — nicht Fehler im
Code, sondern Dinge, die auf einem laufenden Server still kaputtgegangen sind.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat.** Ein Bestandslauf
> ist das „jemand", das jede Nacht nachsieht.

Zwei Beispiele aus diesem Projekt, beide echt und beide wochenlang unbemerkt:
`srvpanel-cron.timer` meldete `active`, hatte keinen nächsten Termin und der
letzte Lauf lag 22 Stunden zurück (`docs/64`); und ein Zertifikat blieb nach dem
Rückbau einer Domain samt privatem Schlüssel liegen, weil `tls --prune` nur
zurückgebaute Abonnements kannte (`docs/78`).

---

## 1. Was die Messrunde entschieden hat

`docs/81 §2.3o` hat neunzehn Messungen. Vier davon werfen den naheliegenden
Entwurf um, und sie stehen hier, weil ohne sie jeder Satz des Plans anders
lautete.

**M3 — `nginx -t` beantwortet nicht die Frage, für die A10 es rufen wollte.**
Ein fehlendes Semikolon geht in **zwei von vier** gemessenen Formen mit `rc=0`
und ohne ein Byte Ausgabe durch; der Block verliert dabei stillschweigend eine
Anweisung — einmal das Zugriffsprotokoll der Domain, einmal ihre Servernamen.

> **`nginx -t` prüft, ob die Datei eine Bedeutung hat — nicht, ob es die
> gemeinte ist.**

Damit ist der Prüfer **notwendig und nicht hinreichend**, und A10 braucht neben
ihm eine zweite Frage an dieselbe Datei (§3 B).

**M1 — alle drei Prüfer beantworten mehr als die Frage nach der Datei.** Auf
einer unberührten Konfiguration gaben `nginx -t` und `sshd -t` in diesem
Container rot: einmal, weil es kein IPv6 gibt, einmal, weil `/run/sshd` fehlte.
Sie sagen „könnte ich damit **jetzt hier** starten", und das ist eine Frage an
die Maschine. Ein Befund daraus kann wahr und trotzdem nicht behebbar sein.

**M9 — der Wortlaut taugt nicht als Kennung.** Jede `[emerg]`-Zeile von nginx
trägt Datum **und Prozessnummer**, jede Zeile von php-fpm ein Datum. Zwei Läufe
an derselben kaputten Datei ergeben zwei verschiedene Texte.

> **Ein Befund braucht eine Kennung, die nicht sein Text ist.**

**M11 — der Quota-Leser des Panels geht grün, wenn die Quota aus ist.**
`repquota` liefert `rc=0` und eine volle Tabelle, sobald die Quotadatei dasteht;
`quotaon -p` sagt daneben `is off`. `docs/41` hat das Panel vom Optionslesen auf
den Leseversuch gestellt, weil die Option nichts beweist —

> **Ein Leseversuch belegt, dass etwas zu lesen war, nicht dass es gilt.**

---

## 2. Die Form eines Befundes

Das ist die Frage, die A10 im Kern entscheidet (`docs/97 §3`), und sie lässt
sich hinterher nicht billig ändern: Davon hängt ab, ob die Seite eine Liste
zeigt oder Prosa, ob ein Nachtlauf „dasselbe wie gestern" sagen kann, und ob
das, was er meldet, behebbar ist.

**Ein Befund ist eine Zeile mit sechs Feldern.**

| Feld | Was | Warum |
|---|---|---|
| `check` | welche Prüfung, aus einer festen Liste — `web.config`, `unit.schedule`, `block.integrity` … | eine Kennung, die ein Wächter halten kann |
| `subject` | woran: Domain, Unit, Pfad, Systembenutzer — **in unserer Form** | „mit dem Ort", wie das Abnahmekriterium es verlangt |
| `state` | `ok` · `warn` · `fail` · `unknown` | siehe unten |
| `reason` | der **Schlüssel** des Grundes, aus einer festen Liste je `check` | M9: der Wortlaut wechselt, der Grund nicht |
| `detail` | der Wortlaut des Werkzeugs, ungekürzt | M8: der Ort des Werkzeugs steht dort und nirgends sonst |
| `measured_at` | wann | damit ein alter Befund als alt erkennbar ist |

**Die Kennung eines Befundes ist `check` + `subject` + `reason` — und
ausdrücklich nicht `detail`.** Nur so überlebt ein Befund die Nacht: Derselbe
Schaden ergibt morgen denselben Schlüssel und einen anderen Text.

**Vier Zustände und nicht drei, und der vierte ist der wichtigste.**
`unknown` heisst „die Messung hat nichts ergeben" und **nicht** „es ist nichts".
Das Vorbild steht im eigenen Repo: `DnsRecordState::Unreachable` ist
ausdrücklich „kein Zustand der Zone, sondern einer der Messung", und ohne ihn
meldete die Anzeige „der Eintrag fehlt", während in Wahrheit der Nameserver
schwieg.

> **Eine leere Liste, die zwei Dinge bedeuten kann, bedeutet keins von beiden.**

Für A10 heisst das konkret: Antwortet der Agent nicht, ist **jede** Prüfung
`unknown` und keine `ok`. Ein Diagnoselauf, der bei totem Agenten Entwarnung
gibt, ist schlimmer als keiner.

**`warn` gegen `fail`** trennt nicht nach Gefühl, sondern nach einer Frage:
*Ist gerade etwas kaputt, oder wird es das?* Ein Zertifikat, das in zwölf Tagen
abläuft, ist `warn`; eines, das abgelaufen ist, `fail`. Ein Timer ohne nächsten
Termin ist `fail` — er ist abgeschaltet und sieht aus wie eingeschaltet.

---

## 3. Was geprüft wird

Neun Prüfungen. Jede sagt hier, **was sie nicht kann** — das ist der Teil, der
sonst als Zusage in die Oberfläche wandert.

### A · Die drei Prüfer — `web.config`, `php.config`, `ssh.config`

`nginx -t`, `php-fpm -t`, `sshd -t` **ohne** `-c`/`-y`/`-f`, also gegen den
laufenden Bestand. Das ist eine andere Frage als die von `config.validate`, das
seit P0 eine **einzelne Datei** prüft; A10 bekommt dafür eine eigene Operation
und benutzt nicht die alte mit einem leeren Pfad.

Gewertet wird **allein der Rückgabewert** (M5: alle drei schreiben auch im
Erfolgsfall auf `stderr`, und `sshd -t` schreibt im Erfolgsfall gar nichts).
Ausdrücklich **nicht** auf `syntax is ok` geprüft — diese Zeile steht bei nginx
auch in einem Lauf, der mit `rc=1` endet (M4).

**Was sie nicht können:** ein fehlendes Semikolon fangen (M3), und einen
Umgebungszustand von einem Konfigurationsfehler unterscheiden (M1, M6). Beim
`sshd -t` steht `rc=255` für beides.

**Der Agent läuft als root, und das ist hier nötig.** Ohne root meldet
`sshd -t` auf einer **heilen** Datei `no hostkeys available` (M6) — das falsche
Rot steht am gesunden Fall.

### B · Die Dateien, die dem Panel gehören — `web.file`, `php.file`

Die Prüfung, die M3 nötig macht. Für jede Domain und jeden Pool: Ist die Datei
da, ist sie nicht leer, und trägt sie noch die Anweisungen, die die Vorlage
zusagt?

**Nicht neu gerendert und verglichen** — das wäre eine zweite Fassung von
`SiteTemplate`, und die zweite ist die, die veraltet. Gefragt wird nach dem, was
schon heute ein Wächter am **erzeugten Text** fragt (`SiteTemplateTest`,
`PhpIsolationTest`): dieselben Fragen, an die Datei auf der Platte statt an die
Zeichenkette im Speicher.

> **Ein Wächter über den erzeugten Text sagt nichts über die Datei, die
> dasteht.**

**Und die Frage wird an den Anfang einer Anweisung gestellt und nicht an die
Datei.** Nachgemessen am 2. September gegen den Prüfkörper aus M3, Fall 2 — die
Form, die `nginx -t` mit `rc=0` durchlässt:

    index index.php index.html          <- das Semikolon fehlt
    access_log /var/log/nginx/mess.log;

`grep access_log` findet die Zeichenkette und meldet Grün; die Domain schreibt
trotzdem kein Protokoll, weil `access_log` zum **Argument** von `index` geworden
ist. Zerlegt man die Datei an `;`, `{` und `}` und nimmt je Anweisung das erste
Wort, bleiben `server`, `listen`, `server_name`, `index`, `root` — und
`access_log` fehlt, wie es soll.

> **Eine Anweisung, die zum Argument der vorigen geworden ist, steht wörtlich
> noch da.** Wer nach der Zeichenkette sucht, findet sie; wer nach der Anweisung
> sucht, nicht.

Das ist **kein nginx-Parser** und soll keiner werden — es ist ein Schnitt an drei
Zeichen. Was er nicht kann, steht in §11.

Die Tiefe dieser Prüfung ist **Frage 4** in §9.

### C · Die verwalteten Blöcke — `block.integrity`

Es sind **zwei** Dateien und nicht fünf (M13): `/etc/ssh/sshd_config` und
`pg_hba.conf`. Je Datei drei Fragen:

1. **Steht das Markenpaar vollständig da?** `ManagedBlock::managed()` sieht ein
   `BEGIN` ohne `END` **nicht** — es liefert die Zeilen, als wäre nichts (M15).
   Den Zustand, den die Klasse selbst für fatal hält, kennt nur `without()`, und
   das steht im Schreibweg.

   > **Ein Diagnoselauf, der nichts schreibt, kommt an der Prüfung nicht vorbei,
   > die nur der Schreiber macht.**

   A10 baut deshalb einen **lesenden** Integritätsblick in `ManagedBlock` — an
   dieselbe Klasse und nicht daneben.

2. **Steht genau ein `BEGIN` da?** Ein zweiter Block wird heute still übergangen
   (M14), und genau so sieht ein halb durchgelaufener Schreibvorgang aus.

3. **Stimmen die Zeilen mit dem Sollzustand?** Eine fremde Zeile *innerhalb* der
   Marken kommt heute als unsere zurück (M16) — ein
   `host all all 0.0.0.0/0 trust` läse sich als Zeile des Panels. Die
   Maschinerie dafür gibt es: `RemoteAccess::orphans()` und `::missing()` für
   `pg_hba.conf`, `SshdConfig::lines()` für den sshd.

**Was sie nicht kann:** Sie sagt nichts über die Datei **ausserhalb** der
Marken. Dort steht der Bestand des Betreibers, und der ist Gesetz.

### D · Units und Termine — `unit.state`, `unit.schedule`

**A10 baut das nicht neu, es fragt es.** A2 hat den Leser gebaut: `Catalog::all()`
nennt die neunzehn Units, `service.status` beantwortet sie, `Units::hasNext()`
entscheidet über den nächsten Termin aus dem **Feldpaar** — die
Realtime-Spalte allein ist auch beim gesunden monotonen Timer leer
(`docs/89` M4).

Gewertet: Ein Dienst, den ein Timer startet, darf stillstehen; ein Timer ohne
nächsten Termin ist `fail`.

### E · Zertifikate — `tls.certificate`

**Zwei Fragen und nicht eine** (M18):

- **An die Datei:** gültig, nicht abgelaufen, und der Name der Domain steht im
  `subjectAltName`. Kostet 25 ms, braucht kein Netz, und
  `AcmeCertificateInfo`/`PanelTlsInfo` tun es seit P4 mit
  `openssl_x509_parse`.
- **An die Leitung:** liefert der Server es für diesen Namen auch aus? Das
  braucht **SNI** — ohne `-servername` bekommt man den Vorgabeblock und damit
  ein gültig aussehendes Zertifikat mit dem falschen Namen (`docs/78`, in der
  Messrunde nachgestellt und bestätigt).

Die zweite ist eine Runde über das Netz je Domain und kann hängen; **wie lange,
ist ungemessen** (M19: der Prüfkörper hat den Proxy dieses Containers gemessen
und nicht die Leitung). Ob sie in den Nachtlauf gehört, ist **Frage 3** in §9.

### F · Die Quota — `quota.state`

Gefragt werden **beide** Werkzeuge, und gemeldet wird ihre Uneinigkeit (M10,
M11):

| `quotaon -p` | `repquota` | Befund |
|---|---|---|
| `is on` | rc=0 | `ok` |
| `is off` | rc=1 | `fail` — keine Quota, und das Panel weiss es |
| **`is off`** | **rc=0** | **`fail`** — die Datei ist da, erzwungen wird nichts |
| nicht lesbar | — | `unknown` |

Die dritte Zeile ist der Zustand, den das Panel heute als Entwarnung liest.

**`quotaon` steht nicht in `Runner::PROGRAMS`** — der Agent kennt `setquota` und
`repquota`. Es kommt dazu, als lesendes Programm ohne schreibende Schalter.

**Was diese Prüfung nicht kann:** Auf `quotaon -p` ist der **Rückgabewert
wertlos** — er ist in jedem gemessenen Zustand `0`, und der Kanal wechselt
zwischen stdout und stderr (M10). Gelesen wird der Wortlaut, und das ist hier
ausnahmsweise richtig, weil er der einzige Träger ist.

### G · Systembenutzer — `system.user`

Für jedes Abonnement: Gibt es den Systembenutzer, den `system_users` reserviert
hat, und gehört ihm seine Wurzel unter `/var/www/vhosts`? Ein fehlender Benutzer
bei lebendem Abonnement ist `fail`.

### H · Verwaiste Zeilen — `orphan.row`

Zertifikate ohne deckende Domain, `system_users` ohne Abonnement,
`/etc/cron.d/srvpanel-*` ohne Job. **Gemeldet und nicht gelöscht**
(`docs/36 §5`) — und bei der Deckung wird nach der **Deckung** gefragt und nicht
nach der Zuordnung: Ein Platzhalter deckt eine lebende Domain, ohne ihr
zugeordnet zu sein, und wer nur `domains.certificate_id` fragte, meldete den
Schlüssel unter einer laufenden Website als verwaist (`docs/78`).

### I · Der Signaturschlüssel — `apt.key`

Aus A1: `Keys::state()` kennt `valid`, `expiring`, `expired`. Ein ablaufender
Schlüssel ist `warn`, ein abgelaufener `fail` — dann kommt kein Update mehr an.

---

## 4. Der Nachtlauf

Ein Timer nach dem Muster der vier vorhandenen: `OnCalendar=daily`,
`Persistent=true`, `RandomizedDelaySec=1h`, und der Dienst mit `Type=oneshot`
**und eigener Frist** — ein `oneshot` ohne Angabe läuft ohne Frist, und ein
einziger hängender Lauf nimmt alle folgenden mit (`docs/74` Befund 4).

**Die Frist liegt unter dem Takt.** `OneshotDeadlineTest` rechnet das schon
nach.

**Stündlich wäre falsch.** Die Prüfer sind billig (M17: zusammen unter 100 ms),
teuer ist nur die Frage an die Leitung — und ein Zustand, der sich über Nacht
nicht ändert, braucht keinen stündlichen Blick.

### Und die Falle, die der Plan selbst nennt

> **Ein Diagnoselauf, der bei jedem Lauf etwas meldet, wird nach zwei Wochen
> nicht mehr gelesen. Was er meldet, muss behebbar sein und nicht nur wahr.**

Drei Dinge folgen daraus, und sie sind der Grund für die Form aus §2:

1. **Gespeichert wird der Zustand, nicht der Lauf.** Ein Befund mit derselben
   Kennung (`check`+`subject`+`reason`) ist derselbe Befund; er bekommt ein
   „steht seit" und keine zweite Zeile. Ohne M9 wäre das nicht zu haben.
2. **Ein `ok` erzeugt keine Zeile.** Die Seite zeigt, was nicht stimmt, und
   daneben, wann zuletzt gemessen wurde.
3. **Was nicht behebbar ist, wird nicht gemeldet.** Ein `nginx -t`, das an einer
   fehlenden Adressfamilie scheitert (M1), ist wahr und hilft niemandem; solche
   Gründe gehören als eigener `reason` erkannt und als `unknown` geführt, nicht
   als `fail`.

---

## 5. Die Fallen

1. **A10 repariert nichts.** Kein Knopf, der einen Block neu schreibt, keine
   Automatik, die einen Timer startet. Ein Diagnoselauf, der schreibt, ist der
   nächste Schreiber in derselben Datei — und `docs/42` hat gemessen, was zwei
   Schreiber in `pg_hba.conf` anrichten.
2. **Kein Pfad und keine Unit kommen von aussen.** Wie bei A5: übergeben wird
   ein Schlüssel, die Positivliste steht im Agenten.
3. **Der Wortlaut des Werkzeugs ist Ausgabe und nie Eingabe.** Er wird gezeigt
   und nicht geparst, um daraus einen Pfad zu gewinnen, den dann jemand öffnet.
4. **Die Seite gehört dem Betreiber.** `detail` trägt den ungekürzten Wortlaut
   des Werkzeugs — bei php-fpm sind das Poolnamen und Pfade, bei nginx
   Zertifikatspfade. Das ist dieselbe Art Inhalt, deretwegen `/logs` dem
   Betreiber allein gehört (A9). Wer den Administrator hereinlassen will,
   entscheidet, was er sieht — **Frage 5**.
5. **Vier Zustände, nicht zwei.** Ein `catch (Throwable) { return []; }` macht
   aus „nicht erreichbar" ein „alles in Ordnung" — genau der Fehler aus
   `docs/44`.

---

## 6. Was A10 ausdrücklich **nicht** wird

- **Keine Benachrichtigung.** Schwellen, E-Mail und Webhook sind A7, und A7
  steht in P9. A10 legt den Befund ab; wer ihn verschickt, kommt später.
- **Kein Malware-Scan.** A13 ist die billige Hälfte davon und steht als
  **Frage 2** in §9.
- **Keine Reparatur** (§5.1).
- **Kein Wartungsmodus.** A12 ist am 2. September als eigener Punkt hinter A10
  eingeordnet worden, und zwar aus demselben Grund wie §5.1: A12 schreibt.
- **Keine Historie.** Gespeichert wird der gegenwärtige Zustand je Kennung mit
  einem „steht seit", nicht jeder Lauf. Eine Verlaufskurve ist P9.
- **Keine Prüfung fremder Dienste über ihre Konfiguration hinaus.** Ob MariaDB
  gesunde Tabellen hat, fragt A10 nicht.

---

## 7. Das Abnahmekriterium

Gemessen auf einem echten Server, nicht geschätzt (Plan §8). Acht Punkte; die
Punkte 2 und 5 sind die, um derentwillen es diesen Lauf gibt.

1. **Ein Lauf auf einem heilen Server meldet nichts.** Null Befunde mit `fail`,
   und die Seite sagt, wann zuletzt gemessen wurde.
2. **Ein von Hand entfernter verwalteter Block taucht im nächsten Lauf mit
   seinem Ort auf** — `check=block.integrity`, `subject=/etc/ssh/sshd_config`.
   Nach dem Zurücklegen ist er im übernächsten Lauf fort.
3. **Ein von Hand gestoppter Timer taucht auf**, mit dem Namen der Unit, und
   nicht erst nach seinem verpassten Termin.
4. **Ein `BEGIN` ohne `END` wird gemeldet und nicht überlesen** — der Zustand,
   den `managed()` heute nicht sieht (M15).
5. **Ein fehlendes Semikolon in einer Vhost-Datei wird gemeldet, obwohl
   `nginx -t` `rc=0` gibt** (M3). Das ist der Punkt, der B von A trennt; fällt
   er aus, ist A10 ein Aufruf von `nginx -t` mit einer Seite davor.
6. **Ein Zertifikat, das in weniger als 30 Tagen abläuft, steht als `warn` da**,
   ein abgelaufenes als `fail`, und der Name im `subjectAltName` wird gegen die
   Domain gehalten.
7. **Bei angehaltenem Agenten steht jede Prüfung auf `unknown`** und keine auf
   `ok`.
8. **Derselbe Schaden über zwei Nächte erzeugt eine Zeile und nicht zwei**, mit
   einem „steht seit" vom ersten Lauf — obwohl der Wortlaut des Werkzeugs sich
   geändert hat (M9).

**Punkt 5 und Punkt 8 dürfen nicht ausfallen.** Der erste belegt, dass A10 mehr
ist als ein Aufruf der drei Prüfer; der zweite, dass der Lauf in zwei Wochen
noch gelesen wird.

---

## 8. Die Schritte

| | Was | Warum in dieser Reihenfolge |
|---|---|---|
| **1** | ~~Der Befund als Form: Enum, Modell, Migration, die Kennung~~ **gebaut am 2. September 2026** | alles andere hängt daran; die Form lässt sich später nicht billig ändern |
| **2** | ~~`ManagedBlock` bekommt seinen **lesenden** Integritätsblick~~ **gebaut am 2. September 2026** — `inspect()`, fünf Zustände, gehalten am Schreibweg | die eine Prüfung, die es heute nur im Schreibweg gibt (M15) |
| **3** | Die Operation `system.diagnose` im Agenten: A, C, F, I | die Prüfungen, die Systemrechte brauchen |
| **4** | B — die Dateien des Panels gegen die Zusagen ihrer Vorlage | der Punkt, der M3 beantwortet; Tiefe nach Frage 4 |
| **5** | D, E, G, H in `app/` — sie fragen, was A2, P4 und `docs/35` gebaut haben | kein Systemrecht nötig |
| **6** | Kommando, Dienst und Timer, samt Frist | erst wenn es etwas zu fahren gibt |
| **7** | Die Seite — Liste, `unknown` sichtbar, „steht seit" | zuletzt, wie in diesem Projekt üblich |
| **8** | Der Nachlauf auf `cloudsrv24` gegen das Kriterium aus §7 | eine Stufe gilt erst als fertig, wenn sie gemessen ist |

---

## 9. Die Fragen, die der Betreiber entscheidet

**Vor Schritt 1 zu entscheiden sind 1 und 2; die übrigen vor dem Schritt, an
dem sie hängen.**

### Frage 1 — Wohin gehört A12?

> **Entschieden am 2. September 2026: eigener Punkt in P7b, hinter A10.** Der
> Betreiber hat den Vorschlag angenommen; `docs/81 §11` und `§12.1` tragen es.
> A10 bleibt damit die Stufe, die nichts schreibt.


`docs/81 §11` verortet **A12** (Wartungsmodus: alle Kundenseiten auf 503, das
Panel erreichbar) mit „mit A1". **A1 ist am 28. August abgenommen**, und A12
steht damit in keiner Stufenzeile — es ist weder gebaut noch irgendwo
eingeplant.

| | |
|---|---|
| **a** | mit A10 mitreiten (es ist derselbe Gegenstand: der Zustand des Servers) |
| **b** | in P7b als eigener Punkt hinter A10 |
| **c** | nach P9b oder später — es ist ein Merkmal und kein Mangel |

**Was für a spricht:** A12 braucht dieselbe Vhost-Maschinerie, die A10 in
Schritt 4 ohnehin anfasst. **Was dagegen spricht:** A12 **schreibt**, und A10 ist
die Stufe, die ausdrücklich nichts schreibt (§5.1). Zwei Gegenstände in einer
Stufe, von denen einer schreibt und der andere nicht, ist genau die Vermischung,
die `docs/81 §12.1` bei A3 aufgelöst hat.

> **Eine Empfehlung: b.** A12 ist zwei bis drei Tage, es hat einen klaren
> eigenen Abnahmepunkt, und es gehört nicht in eine Stufe, deren erste Regel
> „schreibt nichts" lautet.

### Frage 2 — Reitet A13 mit A10 mit?

> **Entschieden am 2. September 2026: nein — und die Messung ist gefahren.**
> Sie steht als `docs/81 §2.3o` M20 und begründet die Antwort schärfer als der
> Vorschlag darunter. Gegen einen echten PHP-Baum von 16 932 Dateien und 79 MB:
> die beiden `find`-Griffe **78** und **81 ms**, der Textgriff **13 136 ms** —
> und im zweiten Lauf **135 ms**. Die erste Zahl war der kalte
> Zwischenspeicher, und nachts um vier ist er kalt.
>
> **Der Unterschied ist keine Grösse, sondern eine Art:** Die `find`-Griffe
> fragen Metadaten, der Textgriff liest den ganzen Bestand des Kunden von der
> Platte. Was auf `cloudsrv24` noch zu messen bleibt, ist die absolute Zahl —
> die Platte dieses Containers ist nicht seine.
>
> **Die Form des Befundes aus §2 trägt A13 ohne Änderung.** Was sich
> unterscheidet, ist der Takt: ein eigener Timer und nicht dieser.


`docs/81 §11` führt **A13** — die billige Hälfte des Malware-Scans: Dateien mit
`0777`, frisch geänderte PHP-Dateien, `eval(base64_decode` als Textsuche — mit
„mit A10".

**Was dafür spricht:** Es ist von der Form her genau ein `check` mehr in §3, es
schreibt nichts, und die Kennung eines Befundes trägt es ohne Änderung.

**Was dagegen spricht, und es ist gewichtig:** Es ist die einzige Prüfung, die
**über die Kundendaten** läuft statt über die Konfiguration. Auf vier Domains
mit einem gewachsenen Bestand sind das zehntausende Dateien statt zwanzig —
**die Laufzeit ist ungemessen**, und §4 hat gerade begründet, dass ein
Nachtlauf billig sein muss. Und der Befund ist von anderer Art: „diese Datei hat
0777" ist wahr und meistens harmlos, also genau die Sorte Meldung, die die
Falle aus §4 beschreibt.

> **Eine Empfehlung: nein — aber die Messung jetzt.** Wenn A13 mitreiten soll,
> gehört seine Laufzeit auf `cloudsrv24` gemessen, **bevor** die Form des
> Befundes festgeschrieben wird. Ein `check`, der Minuten braucht, gehört nicht
> in denselben Lauf wie einer, der 4 ms braucht — dann ist es ein zweiter
> Timer, und das ist eine Entscheidung und kein Detail.

### Frage 3 — Gehört die Frage an die Leitung in den Nachtlauf?

> **Entschieden am 2. September 2026: c.** Beides nachts, die Leitung nur für
> Domains, deren Datei `ok` ist. Der Betreiber hat die berichtigte Empfehlung
> angenommen.

Für Zertifikate (§3 E). Die Frage an die **Datei** ist billig und offline; die
an die **Leitung** braucht SNI, eine Runde über das Netz je Domain und eine
Frist, und wie lange sie an einer stillen Adresse steht, ist ungemessen (M19).

| | |
|---|---|
| **a** | nur die Datei im Nachtlauf; die Leitung nur auf Knopfdruck |
| **b** | beides nachts, mit einer harten Frist je Domain |
| **c** | beides nachts, aber die Leitung nur für Domains, deren Datei `ok` ist |

> **Berichtigt am 2. September 2026, noch vor der Entscheidung.** Hier stand
> „Empfehlung: a", und die Begründung war das Hängen an einer stillen Adresse.
> Nachgesehen trägt sie nicht: `Runner::run()` erzwingt seine Frist mit `SIGTERM`
> und danach `SIGKILL`, und eine Runde über die Leitung braucht hier gar kein
> Programm — `stream_socket_client` mit `peer_name` und `capture_peer_cert` tut
> es, und der Agent benutzt genau dieses Muster seit P7 zweimal in
> `Acme/Dns/`. `openssl` steht **nicht** im Positivverzeichnis und müsste es
> dafür auch nicht.
>
> **Eine Sorge, die eine gebaute Vorkehrung übersieht, ist keine.**

**Was nur die Leitung fängt, ist ein echter Fall und einer, den dieses Projekt
schon hatte:** Die Datei liegt gültig da, und der Server liefert sie nicht aus —
weil der Block nicht nachgeladen wurde, weil der `server`-Block für den Namen
fehlt und die Anfrage auf den Vorgabeblock fällt, oder weil im Vhost ein anderer
Pfad steht. `docs/78` hat den Satz dafür:

> **Ein Beleg für den Weg ist keiner für das Ziel.**

> **Eine Empfehlung: c.** Beides nachts, die Leitung aber nur für Domains, deren
> **Datei** `ok` ist. Der Grund ist nicht mehr der Aufwand — vier Handshakes
> kosten nichts — sondern §4: Ein abgelaufenes Zertifikat wird auch über die
> Leitung abgelaufen ausgeliefert, und das ergäbe **zwei Befunde für eine
> Ursache**. Wer die Datei schon rot gemeldet hat, braucht die Leitung nicht
> auch noch zu fragen.
>
> **a bleibt vertretbar**, wenn die Lücke oben bewusst in Kauf genommen wird;
> **b** ist einfacher zu bauen und meldet dafür doppelt.

### Frage 4 — Wie tief prüft B die Dateien des Panels?

> **Entschieden am 2. September 2026: b, zerlegt.** Gefragt wird an den Anfang
> einer Anweisung und nicht an die Datei — der Grund ist M21.

| | |
|---|---|
| **a** | **da und nicht leer** — fängt den gelöschten Vhost, nicht das fehlende Semikolon |
| **b** | **plus die Zusagen der Vorlage**, gefragt **an den Anfang einer Anweisung** — dieselben Fragen, die `SiteTemplateTest` und `PhpIsolationTest` an den erzeugten Text stellen, an die zerlegte Datei gestellt |
| **c** | **voller Abgleich gegen einen frischen Rendervorgang** |

**c ist der Vollständige und der Teure**, und er hat einen Haken, der ihn hier
zum falschen Werkzeug macht: Er müsste die Vorlage ein zweites Mal aufrufen und
damit den Sollzustand aus dem Bestand neu bauen — jede Änderung an
`SiteTemplate`, die ältere Dateien nicht anfasst, erzeugte dann in **jeder**
Nacht einen Befund für jede Domain, die seither nicht neu geschrieben wurde.
Das ist die Falle aus §4 in Reinform.

> **Eine Empfehlung: b — aber zerlegt und nicht als Textsuche.** Am
> 2. September nachgemessen: `grep access_log` findet die Zeichenkette auch in
> der Datei, in der `access_log` zum Argument von `index` geworden ist (§3 B).
> **So gebaut hätte b Punkt 5 des Abnahmekriteriums nicht erfüllt**, und der
> Wächter dazu wäre grün gewesen.
>
> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
> steht.** Derselbe Satz wie bei `ClassReachTest` und `OutcomeTest`, hier an
> einer Konfigurationsdatei.
>
> Es kommt weiterhin ohne eine zweite Fassung der Vorlage aus — der Unterschied
> ist ein Schnitt an `;`, `{` und `}` vor der Frage.

### Frage 5 — Wer sieht die Seite?

> **Entschieden am 2. September 2026: b.** Der Administrator sieht die Liste,
> der Betreiber zusätzlich `detail`.

`detail` trägt den ungekürzten Wortlaut der Werkzeuge (§5.4).

| | |
|---|---|
| **a** | nur der Betreiber, wie `/logs` |
| **b** | der Administrator sieht die Liste, der Betreiber zusätzlich `detail` |
| **c** | beide alles |

> **Eine Empfehlung: b.** Es ist die Rollenteilung, die am 27. August für die
> Updates-Seite gebaut wurde, und sie hat dort getragen. Der Administrator soll
> sehen, dass etwas nicht stimmt und woran — das steht in `subject` und
> `reason`, und beide sind unsere Formulierung und nicht die des Werkzeugs.

---

## 10. Die Wächter

Für jede Regel einer, und jeder wird absichtlich gebrochen. Was hier steht, ist
die Absicht; die Brüche kommen mit dem Bau in `tests/waechter-brechen.sh`.

| Wächter | Regel |
|---|---|
| `FindingIdentityTest` | **gebaut** — die Kennung ist `check`+`subject`+`reason` und enthält `detail` **nicht**; gemessen an der Wirkung über zwei Läufe. Dazu: ein Ausfall löscht keinen Befund, ein behobener verschwindet, ein unbekannter Grund kommt nicht in die Datenbank, und der Wortlaut wird vor der Spalte gekürzt |
| `FindingStateTest` | **gebaut** — vier Zustände, `unknown` trägt `neutral` und steht zwischen `warn` und `fail`, nur `ok` bleibt stumm, kein Hinweis ausser dem von `ok` gibt Entwarnung, und die Tabelle hat **keine** Spalte für die Schwere |
| `ValidatorVerdictTest` | die drei Prüfer werden am **Rückgabewert** gewertet und nicht an `syntax is ok`; er sucht die Zeichenkette ausdrücklich als **verbotene** und streift Kommentare ab |
| `ManagedBlockIntegrityTest` | **gebaut** — der lesende Blick meldet `BEGIN` ohne `END`, `END` ohne `BEGIN` und den doppelten Block an den neun Formen aus M14, **und er zählt wie der Schreiber**: Wo `without()` wirft, sagt `inspect()` `begin_without_end`, nirgends sonst. Die fremde Zeile hält er ausdrücklich **nicht** — die kennt nur, wer den Sollzustand hat, und das ist Schritt 5 |
| `SiteFileIntegrityTest` | die Zusagen der Vorlage werden am **Anfang einer Anweisung** geprüft und nicht als Zeichenkette — der Prüfkörper ist die Datei aus M3 Fall 2, in der `grep` grün und die Anweisung fort ist |
| `DiagnoseCatalogTest` | **gebaut** — jede Prüfung kennt Gründe, jeder Grund trägt Schwere und Satz, `unreachable` ergibt überall `unknown`, und wer ihn **nicht** führt, steht mit Begründung da (beide Richtungen, weil ein toter Eintrag bei einer Umbenennung entsteht) |
| `QuotaVerdictTest` | die dritte Zeile der Tabelle aus §3 F ist `fail` — gemessen an gebauten Prüfkörpern, nicht an erfundenen |
| `DiagnoseWriteTest` | keine Prüfung schreibt: keine der beteiligten Klassen ruft `put`, `render`, `file_put_contents` oder eine mutierende Operation |
| `OneshotDeadlineTest` | vorhanden — der neue Dienst kommt dazu |
| `UnitCatalogTest` | vorhanden — die neue Unit steht im Katalog und im Paket |

> **Nachgetragen am 2. September, beim Bauen.** `FindingLog` steht in dieser
> Tabelle nicht als eigener Wächter, und das ist Absicht: Seine Regel — „derselbe
> Schaden über zwei Nächte ergibt eine Zeile" — ist keine Eigenschaft seines
> Quelltextes, sondern seiner Wirkung, und die misst `FindingIdentityTest`. Ein
> zweiter Wächter über den Quelltext wäre genau der, der beim ersten Wurf grün
> geblieben wäre: Dort stand ein `updateOrCreate`, das `first_seen_at` bei jedem
> Lauf mitschrieb, und ein Kommentar daneben, der das Gegenteil behauptete.

**Zwei davon sind die, die wirklich zubeissen müssen:** `FindingIdentityTest`,
weil M9 sonst still zurückkommt, und `DiagnoseWriteTest`, weil §5.1 sonst eine
Absichtserklärung bleibt.

---

## 11. Was benannt offen bleibt

- **Die Laufzeit an einer stillen Adresse** (M19) — hier nicht messbar, gehört
  auf `cloudsrv24`, und sie entscheidet Frage 3 mit.
- **Die Quota-Zustände „an, ohne Grenze" und „an, mit Grenze"** (M12) — der
  Kernel dieses Containers kann keine Quota erzwingen. Die Tabelle in §3 F hat
  für ihre erste Zeile **keine Messung**, nur die Symmetrie der übrigen.
- **Die Laufzeit von A13** — ungemessen, und Frage 2 hängt daran.
- ~~**Was die vier neu eingespielten Pakete an einem Testlauf ändern.**~~
  **Nachgesehen am 2. September: nichts.** Voller Lauf mit allen vieren
  installiert — 2807 grün, 0 Ausfälle, `rc=0` (`docs/81 §2.3o`).
- **Die absolute Laufzeit von A13 auf `cloudsrv24`** — die Art der Kosten ist
  gemessen (M20), die Zahl nicht: Die Platte dieses Containers ist nicht die des
  Servers. Sie entscheidet den Takt des zweiten Timers.
- **Was der Schnitt an `;`, `{` und `}` nicht kann** (§3 B). Er zählt kein
  `include`, er kennt keine Anführungszeichen und keine `#`-Kommentare mit einem
  Semikolon darin, und er sagt nichts über die **Reihenfolge** oder die
  Argumente einer Anweisung. Er beantwortet genau eine Frage: steht die zugesagte
  Anweisung als Anweisung da. Wer mehr will, baut einen Parser — und dann ist es
  eine zweite Fassung von nginx.
- **Der Wortlaut über Fassungen hinweg.** M7 belegt, dass keiner der drei
  Prüfer übersetzt — das ist eine Zusage über die Programme und keine über ihre
  Fassungen. Deshalb wird der Wortlaut gezeigt und nicht gedeutet.
