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

---

## 4. Was offen ist

- Die Punkte 3 bis 9 (Kriterien 3 bis 8).

**Aus `docs/76 §4` ist damit nichts mehr offen**, was dieser Lauf hätte
abfallen lassen sollen: Die Marke „ungeprüft" ist im Kasten, `.toggle +
.button-row` ist belegt. Kriterium 5 folgt in Punkt 5.
