# Protokoll: Der Abnahmelauf von P7

Gefahren ab dem 24. August 2026 auf `cloudsrv24`. Die Vorschrift ist `docs/77`;
sie ist **Schritt 10** aus `docs/72 §8` und prüft die acht Punkte des
Abnahmekriteriums aus `docs/72 §3`.

**Dieses Dokument entsteht während des Laufs und nicht danach.** Es ist angelegt
worden, als die Vorbereitung gemessen war — und nicht vorher.

**Der Lauf ist nicht abgeschlossen.** Was hier steht, ist gemessen; was fehlt,
steht als offen und nicht als erledigt.

---

## 1. Die Vorbereitung — gemessen am 24. August

### 1.1 Die Vorbedingung für Punkt 4

```
command -v tcpdump && sudo -n true && echo "beides da"
/usr/bin/tcpdump
beides da
```

**Kriterium 4 ist fahrbar.** Ohne beides bliebe es offen (`docs/77 §2`).

### 1.2 Die Adressen dieses Servers

```
eth0  UP  159.195.56.255/22  2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083/64  fe80::b82d:51ff:fe72:3083/64
docker0  DOWN  172.17.0.1/16
```

Gemessen und nicht abgeschrieben. Die Adresse `159.195.56.255` stand vorher nur
in einem Bildschirmfoto, und daraus zu lesen ist Wissen aus zweiter Hand.

`docker0` ist unten und privat — die Siebung, die `docs/74 §6` schon belegt hat,
wird hier nicht noch einmal gefragt.

### 1.3 Die autoritativen Server und der Systemauflöser

| | |
|---|---|
| `ns1/ns2.ipv64.net` | `159.69.110.93`, `167.235.231.182` |
| Systemauflöser (`/etc/resolv.conf`) | `127.0.0.53` |

**Das sind dieselben zwei Adressen, die das Panel als „gefragt wurden"
anzeigt.** Punkt 4 prüft, ob diese Anzeige die Wahrheit sagt.

Der Auflöser ist der Stub von systemd-resolved, also **Loopback** — der
Mitschnitt in Punkt 4 braucht deshalb `-i any`.

### 1.4 Kein `CAA` in der Zone

```
dig +short @ns1.ipv64.net cloudlab24.de CAA
(leer)
```

**Richtig so.** Der Satz aus `docs/76 §4` ist fort; Punkt 6 (a) braucht diesen
Zustand als Gegenprobe und setzt ihn selbst.

---

## 2. Befund V1 — die Zone führt einen Platzhalter, und „kein Satz" gibt es nicht

**Gefunden in der Vorbereitung, bevor der Lauf begann.** Die erste Messung der
drei Namen ergab:

```
hier   159.195.56.255
fremd  192.0.2.1
ohne   159.195.56.255      ← sollte leer sein
```

`ohne.cloudlab24.de` war nie angelegt worden und antwortete trotzdem.

**Die Diagnose, und sie ist gemessen statt vermutet:**

```
dig +short @ns1.ipv64.net zufall-a7f3.cloudlab24.de A
159.195.56.255
```

Ein Name, den nie jemand angelegt hat, bekommt eine Antwort — die Zone führt
einen Platzhalter.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch
> hergestellt, dass man nichts tut.**

**Was das gekostet hätte:** Punkt 3 verlangt die Unterscheidung von „fehlt" und
„zeigt woandershin". Ohne diesen Fund hätte er zwei Namen gemessen, die beide
„zeigt hierher" sagen — und die Ausgabe hätte danach ausgesehen wie ein Befund
am Panel.

### Die Behebung, und sie fasst die Zone nicht an

Ein Platzhalter greift nach RFC 4592 nur für Namen, die es in der Zone **gar
nicht** gibt. Ein `TXT`-Satz an `ohne.cloudlab24.de` (`p7-abnahme`) lässt den
Namen existieren; die `A`-Frage kommt danach leer zurück.

**Der Platzhalter selbst bleibt stehen.** Ihn abzuräumen hiesse, den Server für
den Prüfkörper zu verändern.

> **Eine Wand, die man nur erreicht, indem man die davor abschaltet, wird durch
> das Abschalten nicht erreicht — sie wird umgangen.**

### Der Beleg, als Paar

```
;; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 30053
;; flags: qr aa rd; QUERY: 1, ANSWER: 0, AUTHORITY: 1, ADDITIONAL: 1
                              ohne.cloudlab24.de A — keine ANSWER SECTION

;; ->>HEADER<<- opcode: QUERY, status: NOERROR, id: 29202
;; flags: qr aa rd; QUERY: 1, ANSWER: 1, AUTHORITY: 0, ADDITIONAL: 1
;; ANSWER SECTION:
zufall-b8e2.cloudlab24.de.  10  IN  A  159.195.56.255
```

Derselbe Server, dieselbe Frage, und der Unterschied liegt allein an der
Existenz des Namens. Das `AUTHORITY: 1` bei `ohne` ist die SOA und gehört zu
einer echten NODATA-Antwort.

**Warum das Paar und nicht die leere Zeile allein:** Eine leere `+short`-Ausgabe
sieht genauso aus wie ein Tippfehler im Namen oder ein Befehl, der gar nicht
gelaufen ist.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

### Und was daraus für Punkt 3 folgt

`Comparison::state()` entscheidet `Missing` an `values === []` bei
`answered > 0` — also an einer **leeren Antwort** und nicht an NXDOMAIN. Die
gemessene Lage erfüllt das: Der Server hat geantwortet (`NOERROR`, `aa`), und es
gibt keinen `A`-Wert.

**Erwartet wird deshalb „Fehlt" und ausdrücklich nicht „Nicht erreichbar".**
Steht dort morgen `Nicht erreichbar`, ist nicht der Zustand falsch, sondern die
Messung.

---

## 3. Der Lauf

### Punkt 0 — Die Fassung

```
readlink -f /opt/srvpanel/current
/opt/srvpanel/releases/0.7.0-rc.8

/opt/srvpanel/current/artisan --version
Laravel Framework 13.23.0
```

Im Menü des Panels steht `0.7.0-rc.8`. **Beide Quellen stimmen überein** — das
ist die Fassung, gegen die dieser Lauf misst.

### Punkt 1 — „zeigt hierher" · Kriterium 1 **erfüllt**

**Der Timer stand günstig.** `systemctl list-timers` vor dem Anlegen:

```
NEXT                            LEFT   LAST                            PASSED
Mon 2026-08-24 10:46:11 CEST    7min   Mon 2026-08-24 10:30:45 CEST    8min ago
```

Sieben Minuten Fenster für die beiden flüchtigen Zustände.

**Der Abgleich nach „Jetzt prüfen":**

| Name | Satz | Zustand | Erwartet | Gefunden |
|---|---|---|---|---|
| `hier.cloudlab24.de` | `A` | **Zeigt hierher** | `159.195.56.255` | `159.195.56.255` |
| `hier.cloudlab24.de` | `AAAA` | Fehlt | `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083` | – |

`Zuletzt geprüft: 2026-08-24 10:40:39 · gefragt wurden 159.69.110.93,
167.235.231.182`

**Kriterium 1 ist erfüllt.** Die beiden gefragten Adressen sind genau die
autoritativen aus §1.3.

**Drei Dinge fallen nebenbei ab:**

1. **`AAAA` → „Fehlt" ist richtig** und nicht etwa ein Rest: Der Server führt
   IPv6, die Zone trägt aber nur einen `A`-Satz. Das ist zugleich die
   **Ausgangslage für Punkt 5** — dort muss diese Zeile nach der Übersteuerung
   ganz **verschwinden** statt auf „Fehlt" stehenzubleiben. Der Kontrast ist
   damit schärfer als geplant: nicht „Fehlt gegen Fehlt", sondern „Zeile gegen
   keine Zeile".
2. **Der Zeitpunkt 10:40:39 liegt zwischen den Timerläufen** (10:30:45 und
   10:46:11). Der Knopf misst also auf Zuruf und nicht aus einem Bestand — was
   `docs/74 §6` schon belegt hatte und hier nebenbei noch einmal dasteht.
3. **Die Uhrzeit ist lokal** (der Timer meldet CEST). Punkt 7 misst das sauber.

### Die beiden flüchtigen Zustände aus `docs/76 §4`

**Die Marke „ungeprüft" ist im Kasten.** In der Domainliste stand
`hier.cloudlab24.de · Zusatzdomain · p6-b.invalid · 8.3 · ungeprüft · aktiv`.

Sie lebt nur zwischen dem Anlegen und dem ersten Abgleich, und ihre Herstellung
kostet sonst einen Zertifikat-Fehlversuch. Hier ist sie kostenlos abgefallen —
genau wie `docs/76 §4` es vorgesehen hat.

> **Ein Zustand, dessen Herstellung ein Kontingent kostet, wird dort geprüft, wo
> er ohnehin entsteht.**

**Der zweite Zustand — `.toggle + .button-row` — ist im Markup gegeben.** Auf
der Seite einer Domain ohne Zertifikat gilt:

- `alsPlatzhalter` ist falsch (das Kästchen trägt „nicht möglich" und ist
  gesperrt: keine DNS-Zugangsdaten),
- `props.certificate` ist `null`,

und damit rendert **keiner** der beiden `.section-note` dazwischen. Der Text, der
im Bild zwischen Kästchen und Knopf steht, liegt **innerhalb** des
`<label class="toggle">` als `.hint` und `.hint.obstacle`.

**Und sie wirkt — gemessen im Browser bei 390 px:**

```
Nachbar von .toggle:   button-row
ist die Knopfreihe:    true
margin-top daran:      24px
--block-gap ist:       24px
```

Der Nachbar **ist** die Knopfreihe, und der Abstand **ist** `--block-gap`. Damit
ist `.toggle + .button-row` belegt — die Regel, die in `docs/76 §4` seit dem
23. August als „weiterhin unbelegt" stand, weil sie sich nur in genau diesem
Zustand zeigen lässt.

> **Ein Abstand, der richtig aussieht, ist noch kein Beleg dafür, dass die Regel
> greift, die ihn erzeugen sollte** — zwei gleiche Zahlen sind einer.

**Eine Beobachtung, die einer Vorhersage widerspricht.** Die Vorschrift nennt
diesen Zustand den „kürzeren von beiden", weil die Automatik bestellt, sobald
der Server-Block steht. Beim Messen stand dort nach mehreren Minuten weiter
„Noch keine" — die Bestellung braucht länger als angenommen. Kein Fund, aber die
Begründung für die Eile war falsch.

### Punkt 2 — „zeigt woandershin" · Kriterium 2 **erfüllt**

`fremd.cloudlab24.de` angelegt, „Jetzt prüfen" — Meldung „Der DNS-Abgleich ist
gelaufen."

| Name | Satz | Zustand | Erwartet | Gefunden |
|---|---|---|---|---|
| `fremd.cloudlab24.de` | `A` | **Zeigt woandershin** | `159.195.56.255` | **`192.0.2.1`** |
| `fremd.cloudlab24.de` | `AAAA` | Fehlt | `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083` | – |

`Zuletzt geprüft: 2026-08-24 10:45:09 · gefragt wurden 159.69.110.93,
167.235.231.182`

**Beide Hälften des Kriteriums sind erfüllt.** Der gefundene Wert `192.0.2.1`
steht da — das ist der Teil, den `docs/72 §3` wörtlich verlangt („mit dem
gefundenen Wert"), und ohne ihn wäre die Anzeige ein Urteil ohne Auskunft.

**Und die Marke ist bernsteinfarben, nicht rot.** „Zeigt woandershin" ist als
Auskunft dargestellt und nicht als Mangel — die Zusage aus `docs/72 §2.3` für
den Kunden, der absichtlich über ein CDN fährt. Daneben steht „Fehlt" in Rot:
Die drei Zustände sind auch farblich unterschieden und nicht nur im Wort.

**Eine Beobachtung ohne Fund.** Der Zertifikatsbereich sagt hier dasselbe wie
bei `hier.cloudlab24.de`: „Noch keine. Bestellt wird von selbst…". Ob die
Bestellung für `fremd` inzwischen gescheitert ist — der Name zeigt auf TEST-NET-1
und ist für Let's Encrypt nicht erreichbar — oder ob sie noch nicht lief, ist von
der Seite aus nicht zu unterscheiden. Das betrifft die Zertifikatsanzeige und
nicht den DNS-Abgleich; hier steht es als Beobachtung und nicht als Mangel.

### Punkt 3 — „fehlt" ist nicht „zeigt woandershin" · Kriterium 3 **erfüllt**

`ohne.cloudlab24.de` angelegt, „Jetzt prüfen".

| Name | Satz | Zustand | Erwartet | Gefunden |
|---|---|---|---|---|
| `ohne.cloudlab24.de` | `A` | **Fehlt** | `159.195.56.255` | – |
| `ohne.cloudlab24.de` | `AAAA` | Fehlt | `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083` | – |

`Zuletzt geprüft: 2026-08-24 10:50:19`

**Und ausdrücklich „Fehlt", nicht „Nicht erreichbar".** Die Vorbereitung hatte
das aus `Comparison::state()` vorhergesagt: NODATA heisst `answered > 0` und
`values === []`. Der `TXT`-Kniff aus Befund V1 trägt also durch bis in die
Anzeige.

**Die Unterscheidung, um die es geht** — dieselbe Spalte, zwei Domains:

| | `fremd` · `A` | `ohne` · `A` |
|---|---|---|
| Zustand | **Zeigt woandershin** (bernstein) | **Fehlt** (rot) |
| Erwartet | `159.195.56.255` | `159.195.56.255` |
| Gefunden | **`192.0.2.1`** | **–** |

**Sie trägt über zwei Kanäle:** die Marke (Wort *und* Farbe) und die Spalte
`GEFUNDEN` (ein Wert gegen einen Strich). Wer den Farbcode nicht kennt, sieht
den Unterschied trotzdem — und das ist der Unterschied zwischen „zwei Zustände
existieren" und „ein Kunde erkennt sie auseinander".

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss.**

### Punkt 4 — die autoritativen Server · Kriterium 4 **erfüllt**

**Der Punkt, der etwas beweist**, und der einzige, den `docs/74 §7` ausdrücklich
offen gelassen hat.

Mitschnitt über 45 Sekunden, in denen ausschliesslich „Jetzt prüfen" auf
`fremd.cloudlab24.de` geklickt wurde:

```
=== BELEG: Saetze der Domain an den autoritativen Servern ===
      2 AAAA? fremd.cloudlab24.de.
      2 A? fremd.cloudlab24.de.
      2 CAA? fremd.cloudlab24.de.
=== VERSTOSS: dieselben Saetze am Systemaufloeser ===
0
=== erlaubt: was sonst an den Systemaufloeser ging ===
      1 A? ns1.ipv64.net.
      1 A? ns2.ipv64.net.
      1 NS? cloudlab24.de.
      2 NS? fremd.cloudlab24.de.
```

**Die Gegenprobe**, eigenes Fenster, nur ein `dig` über den Systemauflöser:

```
dig +short fremd.cloudlab24.de A → 192.0.2.1
grep -c '> 127.0.0.53.53:.*A? fremd.cloudlab24.de' → 1
```

**Drei Aussagen stecken darin, und jede steht für sich:**

1. **Die Null ist eine Messung.** Die Gegenprobe zeigt `1` — ein `A?` auf genau
   diesen Namen am Systemauflöser **erscheint** im Mitschnitt, wenn es eines
   gibt. Also heisst die `0`: „das Panel hat keines geschickt", und nicht „ich
   habe an der falschen Stelle gelauscht".

   > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
   > steht.**

2. **Die `2` ist keine Zufallszahl.** Jeder Satztyp geht zweimal hinaus, einmal
   je autoritativem Server — genau wie der Kopf des `Resolver` es beschreibt
   („jeder einzeln"). Damit hat der Zustand `Inconsistent` überhaupt eine
   Grundlage: Er entsteht aus dem Vergleich dieser beiden Antworten.

3. **Am Auflöser steht genau das Erlaubte.** `NS?` auf die Zone, `A?` auf die
   beiden Nameservernamen — und kein einziger Satz der Domain.

**Und ohne die Prüfung am Quelltext wären diese vier Zeilen als Verstoss
gezählt worden.** Die erste Fassung dieser Messung fragte „geht überhaupt ein
Paket an den Systemauflöser?" — die Antwort ist ja, und zwar zu Recht.

> **Ein Kriterium, das man am falschen Paket misst, meldet den Prüfling für
> etwas, das er zu Recht tut.**

### Punkt 4 (d) — die Änderungsprobe

`fremd.cloudlab24.de` bei ipv64 von `192.0.2.1` auf `198.51.100.1` geändert,
eine halbe Minute gewartet, „Jetzt prüfen":

| Satz | Zustand | Erwartet | Gefunden |
|---|---|---|---|
| `A` | Zeigt woandershin | `159.195.56.255` | **`198.51.100.1`** |

`Zuletzt geprüft: 2026-08-24 11:09:51`

**Das belegt nicht Kriterium 4** — bei einer TTL von 10 Sekunden zeigte auch ein
Auflöser den neuen Wert. Es belegt, dass das Panel keinen **eigenen**
Zwischenspeicher führt, der älter wäre als seine Anzeige.

> **Zwei Messungen, die man zusammenzählt, obwohl sie Verschiedenes zeigen,
> ergeben eine Zahl, die nichts bedeutet.**

### Punkt 5 — kein `AAAA` ohne IPv6 · Kriterium 5 **erfüllt**

Die Übersteuerung unter Einstellungen → Allgemein auf **nur die IPv4** gesetzt,
danach wieder geleert. Beide Male `hier.cloudlab24.de` geprüft:

| | Zeilen im Abgleich |
|---|---|
| (c) Übersteuerung `159.195.56.255` | **eine** — `A` → Zeigt hierher (11:11:16) |
| (d) Übersteuerung leer | **zwei** — `A` → Zeigt hierher, `AAAA` → Fehlt (11:11:53) |

**Die `AAAA`-Zeile fällt nicht auf „Fehlt" — sie verschwindet.** Das ist genau
der Unterschied, den `DesiredRecords::for()` im Kopfkommentar benennt: „hier
fehlt etwas" gegen „danach wird nicht gefragt". Ohne IPv6 des Servers entsteht
gar kein Sollwert, statt eines mit leerer Erwartung.

**Und weil die Zeile in (d) zurückkommt, sagt (c) etwas über die Übersteuerung**
und nicht über einen Fehler. Ohne diesen zweiten Schritt wäre „die Zeile ist
weg" nicht von „die Zeile ist kaputt" zu unterscheiden.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

### Punkt 6 — das fremde `CAA` · Kriterium 6 **erfüllt**

| Schritt | Zeit | Ergebnis |
|---|---|---|
| (a) Gegenprobe, **vor** dem Satz | 11:15:47 | `cloudlab24.de`: **kein** Hinweis |
| (b)/(c) Satz gesetzt und in der Zone belegt | — | `cloudlab24.de. 10 IN CAA 0 issue "digicert.com"` |
| (d) nach dem Satz | 11:16:52 | **Hinweis steht** |
| Gegenprobe am Kind | 11:17:22 | `hier.cloudlab24.de`: **kein** Hinweis |

**Der Wortlaut der Meldung:**

> `cloudlab24.de — CAA erlaubt nur digicert.com. Solange letsencrypt.org nicht
> dabeisteht, scheitert jede Bestellung.`

**Sie ist eine Auskunft und keine Markierung.** Sie nennt die Domain, was erlaubt
ist, was fehlt und die Folge — also alles, was jemand braucht, um es zu beheben,
ohne erst nachzuschlagen.

**Und (a) ist nicht Förmlichkeit.** Ohne die Messung vor dem Setzen wäre der
Hinweis in (d) von einem, der immer dasteht, nicht zu unterscheiden.

### Die benannte Grenze: kein Aufstieg zur Elternzone

`hier.cloudlab24.de` zeigt **keinen** Hinweis, obwohl `cloudlab24.de` darüber
den blockierenden Satz trägt. **Eine echte Zertifizierungsstelle klettert
hinauf** — eine Bestellung für `hier.cloudlab24.de` würde also sehr wohl
scheitern.

Das ist **kein Fund**, sondern die Grenze, die im Kopf von `Authority`
ausgeschrieben steht: Das Panel fragt nur die Namen, die es ohnehin prüft, und
meldet bei leerem Satz deshalb „nichts gefunden" statt „darf".

> **Ein Urteil, das eine Regel nur halb kennt, gehört als halbes gekennzeichnet
> und nicht als ganzes ausgegeben.**

Sie wäre erst dann ein Mangel, wenn die Anzeige behauptete, es dürfe ausgestellt
werden.

### Eine Beobachtung ohne Fund — und nicht in P7s Zuständigkeit

`cloudlab24.de` trägt ein Zertifikat (Let's Encrypt, YR2, gültig bis
20.11.2026). `hier.cloudlab24.de` trägt nach 35 Minuten weiter keines, und der
Bereich sagt unverändert „Noch keine. Bestellt wird von selbst…".

Das betrifft die **Zertifikatsautomatik** und nicht den DNS-Abgleich. Ob die
Bestellung für die neuen Domains gescheitert ist oder nie lief, ist von der Seite
aus nicht zu unterscheiden — dieselbe Beobachtung wie bei `fremd` in Punkt 2, nur
an einem Namen, der hierher zeigt und deshalb nicht daran scheitern sollte.

**Hier steht sie als Beobachtung.** Wer sie verfolgt, fängt bei den Vorgängen und
dem Protokoll dieser Domains an.

### Punkt 7 — der Zeitpunkt · Kriterium 7 **erfüllt**

**Die Zeitzone**, gegen die Serveruhr gehalten:

| | |
|---|---|
| `date` auf `cloudsrv24` | `Mo 24. Aug 11:27:05 CEST 2026` |
| Anzeige nach dem Klick | `Zuletzt geprüft: 2026-08-24 11:27:38` |
| Unterschied | **33 Sekunden** |

Das ist die Zeit zwischen den beiden Handgriffen und **nicht zwei Stunden**.
Stünde dort UTC, läse man `09:27:38`. `docs/74` Befund 3 sass genau hier — der
Zeitpunkt rechnete in der falschen Zeitzone — und er ist behoben.

**Und der Zeitpunkt bewegt sich richtig:** `11:17:22` (aus Punkt 6) blieb beim
Wiederaufrufen der Seite stehen und sprang erst durch „Jetzt prüfen" auf
`11:27:38`. Er ist also eine Aussage über die letzte Messung und nicht über den
letzten Seitenaufruf.

> **Eine Antwort aus dem Zwischenspeicher ist eine Aussage über vorhin — und
> wenn sie das ist, sagt sie es auch.**

**Eine Beobachtung ohne Fund:** Die Reihenfolge der gefragten Nameserver
wechselt zwischen den Läufen (`167.235.231.182, 159.69.110.93` gegen
`159.69.110.93, 167.235.231.182`). Der Inhalt ist derselbe; die Reihenfolge
kommt aus dem NS-Satz und ist keine Aussage des Panels.

### Punkt 8 — bei 390 px läuft nichts über · Kriterium 8

`dokument` ist das Kriterium und muss `0` sein; `gegenprobe` muss `200/200`
lauten, sonst ist die Zeile ungültig und keine Messung.

| Seite | Breite | Thema | `dokument` | Gegenprobe | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|---|---|
| `cloudlab24.de` (mit CAA-Hinweis) | 390 | hell | **0** | 200/200 | — | — | 4 |
| `cloudlab24.de` (mit CAA-Hinweis) | 390 | dunkel | **0** | 200/200 | — | — | 4 |

**Diese Seite ist vorgezogen worden**, solange der `CAA`-Satz stand: Der Hinweis
ist ein langer Satz mit zwei Domainnamen darin, also die Sorte Zeile, die bei
390 px schiebt.

**Er bricht sauber auf drei Zeilen um und bleibt im Kasten.** Und `rollt` ist
leer — kein Element rollt waagerecht, der Satz versteckt sich also nicht in
einem Behälter, der die Zahl beruhigt.

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.** Genau deshalb wird `rollt` mitgelesen und nicht nur
> `dokument`.

Der Themenwechsel lief über `window.srvpanelTheme('dark')` in der Konsole — das
ist derselbe Aufruf, den die Profilseite nach dem Speichern macht, und er spart
die Navigation. Die Farben ändern die Anordnung nicht, und beide Zeilen sind in
jeder Zahl gleich.

---

## 4. Was offen ist

- Punkt 8 für die drei übrigen Domainseiten (`hier`, `fremd`, `ohne`), je zwei
  Themes — sechs Lagen.
- Punkt 9: der `CAA`-Satz steht noch.
- **Der `CAA`-Satz steht** und verweigert Let's Encrypt jede Ausstellung unter
  `cloudlab24.de`. Punkt 9 räumt ihn ab — das ist Teil des Laufs und nicht
  Aufräumen danach.

**Aus `docs/76 §4` ist damit nichts mehr offen**, was dieser Lauf hätte
abfallen lassen sollen: Die Marke „ungeprüft" ist im Kasten, `.toggle +
.button-row` ist belegt. Kriterium 5 folgt in Punkt 5.
