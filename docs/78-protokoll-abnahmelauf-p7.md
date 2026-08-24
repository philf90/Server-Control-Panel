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

## 3. Die Lagen

*Noch keine — der Lauf beginnt mit Punkt 0.*

---

## 4. Was offen ist

**Alles ausser der Vorbereitung.** Die acht Punkte aus `docs/72 §3` sind noch
nicht gemessen.
