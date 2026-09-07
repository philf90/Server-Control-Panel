# A3, erster Wurf — der Abnahmelauf

Ausgeschrieben am **7. September 2026**, **vor** dem Fahren. Der Plan ist
`docs/109`, die Messrunde `docs/81 §2.3s`. Gefahren wird auf `cloudsrv24`.

---

## 0 · Was beim Ausschreiben umgefallen ist

**Vier der acht Punkte aus `docs/109 §7` haben ihre Fassung gewechselt**, und
drei davon, weil der Prüfling oder der Server sie so nicht hergibt.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### 0.1 Punkt 3 hing an einer Vorbedingung, die ein echter Server nicht hat

Er lautete: „**Bei lauschendem Port und leerem Regelwerk** steht nirgends
„offen" oder „erreichbar"." Ein Server im Betrieb hat selten ein leeres
Regelwerk — und **die Zusage hängt gar nicht daran**. Die Seite darf diese
Wörter in *keinem* Zustand sagen, weil sie in keinem Zustand gemessen sind.

Neu gefasst: Die drei Wörter stehen auf der gerenderten Seite nicht, **wie der
Server auch steht**, und der Satz über die Firewall des Anbieters steht genau
einmal.

### 0.2 Punkt 4 ist auf einem laufenden Server zur Hälfte nicht messbar — und der Rest wäre gefährlich

Er verlangte „mit laufendem `ufw` steht `ufw` da". Das hiesse, auf
`cloudsrv24` ufw zu **aktivieren**. Gemessen (M19): `ufw --force enable` setzt
`-P INPUT DROP`. Das Panel horcht auf 8443, SSH auf 22 — beide wären fort, und
zwar auf demselben Weg, über den man es zurücknehmen wollte.

> **Ein Prüfkörper, der den Weg kappt, auf dem man ihn zurücknimmt, ist keiner.**

Dasselbe für firewalld. Beide Fälle sind in der Messrunde gegen echte Werkzeuge
gemessen (M13/M15/M19) und werden hier **nicht wiederholt**. Punkt 4 misst, was
der Server wirklich hat, und dass die Seite es benennt.

### 0.3 Punkt 2 hat eine Vorbedingung, die niemand geprüft hatte

Er braucht eine Regel über `iptables-legacy`. Ob es das auf `cloudsrv24`
überhaupt gibt, steht nirgends. Fehlt es, meldet `Runner::run` `NOT_FOUND`, der
Zustand trägt `installed: false` — und der Punkt ist nicht herstellbar, obwohl
er ein Ausschlusskriterium ist.

**Er beginnt deshalb mit einer Prüfung und nicht mit einem Eingriff** (§2a).

### 0.4 Punkt 6 hat einen Rückweg, den der A10-Lauf schon einmal bezahlt hat

Den Agenten anzuhalten legt **`srvpanel-worker` und `srvpanel-metrics` mit
hin** — `Requires=srvpanel-agentd.service` überträgt das Anhalten. Ein `start`
des Agenten holt sie **nicht** zurück (`docs/100 §9.10`).

> **Eine Abhängigkeit, die das Anhalten überträgt und das Starten nicht,
> hinterlässt einen Zustand, den nur der herstellt, der ihn auch beheben
> müsste.**

Zurückgeholt wird deshalb über **`systemctl start srvpanel.target`** und nicht
über den Agenten allein. Und gemessen wird **nach einem `sleep 2`**: Ein
`is-active` unmittelbar nach dem `stop` misst den Übergang und nicht den
Zustand.

---

## 0b · Der Vorflug — was hinterher wieder dastehen muss

**Vor jedem Eingriff**, und das Ergebnis gehört ins Protokoll:

    date -u +%FT%TZ
    ss -H -ltnp | tee /root/a3-vorflug-ports.txt | wc -l
    nft list ruleset > /root/a3-vorflug-nft.txt; echo "nft rc=$?  $(wc -c </root/a3-vorflug-nft.txt) Bytes"
    command -v iptables-legacy || echo "iptables-legacy: fehlt"
    iptables-legacy -S 2>/dev/null | tee /root/a3-vorflug-legacy.txt
    command -v ufw firewall-cmd || echo "ufw/firewalld: fehlen"
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web
    srvpanel version

Der Vorflug ist **kein Formalismus**: Punkt 2 legt eine Regel an, Punkt 6 hält
Dienste an. Ohne diese Zeilen ist „abgeräumt" eine Behauptung.

---

## 1 · Die Lauscher stehen da

**Ohne einen einzigen Prüfkörper.** Der Server hat einen echten Bestand; ihn zu
messen ist besser, als einen zu bauen.

    ss -H -ltnp | wc -l

Dann `/services` aufmachen und den Bereich **„Ports und Regelwerk"** gegen diese
Ausgabe halten.

**Erwartet:** dieselbe Zahl Zeilen. Je Zeile Port, Reichweite und Eigentümer.
Mindestens ein Lauscher steht auf **„lauscht auf allen Adressen"** (nginx auf
80/443) und mindestens einer auf **„lauscht nur lokal"**.

> **Ein Wert, den nur die Seite kennt, ist eine Behauptung. Gemessen ist er
> erst gegen die Quelle, aus der er kommt.**

---

## 2 · Legacy wird gesehen · **Ausschluss**

**Der Punkt, für den es diesen Wurf gibt.** Gemessen (M10) ist eine
Legacy-Regel für `nft list ruleset` unsichtbar — dort steht `rc=0` und nichts,
also die Antwort für „keine Regeln".

### 2a · Erst die Vorbedingung

    command -v iptables-legacy && iptables-legacy -S

**Fehlt es**, ist der Punkt ohne `apt-get install -y iptables` nicht
herstellbar. Dann **erst entscheiden** — und wenn installiert wird, gehört das
in den Vorflug und wieder abgeräumt.

### 2b · Der Eingriff

Ein Port, auf dem nichts horcht und den niemand von aussen braucht:

    iptables-legacy -A INPUT -p tcp --dport 12346 -j DROP
    iptables-legacy -S | grep 12346
    nft list ruleset | wc -c        # → 0 erwartet, oder der Bestand von vorher

**Erwartet auf der Seite** (`/services` neu laden):

| Zeile | steht auf |
|---|---|
| Verwaltet von | `iptables` |
| nftables | `führt nichts` — **oder** der Bestand von vorher |
| iptables (alte Bauart) | **`führt Regeln`** |
| Meldung | „Die Regeln dieser Maschine liegen in der **alten iptables-Bauart**" |

**Zurück, und das ist Teil des Punktes:**

    iptables-legacy -D INPUT -p tcp --dport 12346 -j DROP
    diff <(iptables-legacy -S) /root/a3-vorflug-legacy.txt && echo "legacy wie vorher"

> **Ein Prüfkörper, der stehenbleibt, verändert die Antwort der nächsten
> Messung.**

**Fällt dieser Punkt aus, ist A3 nicht abgenommen.** Ohne ihn ist M10
beschrieben und nicht behoben.

---

## 3 · Keine Zusage über aussen · **Ausschluss**

**Berichtigt, siehe §0.1.** Gemessen wird am gerenderten Text, in dem Zustand,
in dem der Server steht.

Im Browser auf `/services`, in der Konsole:

    const t = document.body.innerText
    for (const w of ['offen', 'erreichbar', 'geschlossen']) {
      console.log(w, '→', (t.match(new RegExp(w, 'g')) || []).length)
    }
    console.log('Satz →', (t.match(/Steht eine Firewall des Anbieters davor/g) || []).length)

**Erwartet:** die drei Wörter **0 mal** — und der Satz **genau 1 mal**.

**Die Gegenprobe gehört dazu**, sonst misst der Zähler nichts:

    console.log('lauscht →', (t.match(/lauscht/g) || []).length)

**Erwartet:** so oft wie es Lauscher gibt. Steht dort 0, misst der Zähler die
falsche Seite und die drei Nullen darüber bedeuten nichts.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Fällt dieser Punkt aus, ist A3 nicht abgenommen.**

---

## 4 · Der Verwalter wird benannt

**Berichtigt, siehe §0.2** — gemessen wird der Zustand des Servers, nicht ein
hergestellter.

    command -v ufw firewall-cmd
    nft list tables
    iptables-legacy -S | grep -v '^-P .* ACCEPT$' | wc -l

**Erwartet:** Die Zeile „Verwaltet von" nennt genau das, was daraus folgt —
`nftables`, `iptables`, `keiner` oder `nicht feststellbar`. Ein englischer
Rohwert wäre ein Befund.

**Was hier ausdrücklich nicht gemessen wird:** `ufw` und `firewalld` als
laufende Verwalter. Beide sind in der Messrunde gegen die echten Werkzeuge
gemessen (M13/M15/M19); sie hier herzustellen hiesse, den eigenen Zugang zu
kappen.

---

## 5 · IPv6

**Der Fall, den der Container grundsätzlich nicht messen kann** — er hat kein
IPv6 im Kernel (`docs/81 §2.3s`). Die Klammerform `[::]:80` steht im Leser nach
der Dokumentation von iproute2 und **nicht nach einer Messung**.

    ss -H -ltnp | grep -E '\[' | head

**Erwartet:** Jede dieser Zeilen steht auf der Seite — mit der Adresse **ohne**
Klammern, dem richtigen Port und „lauscht auf allen Adressen" für `[::]` bzw.
„lauscht nur lokal" für `[::1]`.

**Und die Gegenprobe:** Die Zahl der Zeilen mit `[` in `ss` und die Zahl der
Zeilen auf der Seite, die zu keiner IPv4-Adresse gehören, sind dieselbe. Ein
Leser, der von links trennte, liesse sie alle aus — und eine kürzere Liste
sähe aus wie ein Server mit weniger Diensten.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit
> Fussnote.** Hier hört sie auf, eine zu sein.

---

## 6 · Nicht feststellbar bleibt nicht feststellbar

    systemctl stop srvpanel-agentd
    sleep 2
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics

**Erwartet:** alle drei `inactive` — das ist `Requires=` und kein Fehler.

Dann `/services` neu laden.

**Erwartet:** Der Bereich sagt **`nicht feststellbar`** und **nicht** „keine
Ports" und **nicht** „keine Regeln".

**Zurück, über das Ziel und nicht über den Agenten:**

    systemctl start srvpanel.target
    sleep 2
    systemctl is-active srvpanel-agentd srvpanel-worker srvpanel-metrics srvpanel-web

**Erwartet:** alle vier `active`. Ein `start` des Agenten allein liesse Worker
und Metrik liegen (`docs/100 §9.10`), und jeder Vorgang bliebe danach wortlos
auf „wartet" stehen.

---

## 7 · Die Tür — und der Prozessname

**Zwei Fragen in einem Punkt**, und die zweite ist die neue.

| Konto | erwartet |
|---|---|
| **Betreiber** | `/services` 200, Bereich vollständig, Spalte „Eigentümer" trägt **Namen** |
| **Administrator** | `/services` 200, Bereich vollständig, Spalte „Eigentümer" trägt **„nicht feststellbar"** |
| **Kundenkonto** | **403** |

**Und die Gegenprobe gehört in die Nutzlast und nicht ins Bild.** Beim
Administrator, in der Konsole — **auf einer direkt geladenen `/services`**, nicht
auf einer, zu der man über das Menü navigiert ist:

    const p = JSON.parse(document.querySelector('script[data-page]').textContent).props
    console.log('Namen in der Nutzlast:',
      p.ports.listeners.filter(l => l.process !== null).length)
    console.log('Lauscher insgesamt  :', p.ports.listeners.length)

**Erwartet:** beim Administrator **0** Namen bei einer Zahl Lauscher grösser
null — beim Betreiber sind beide Zahlen gleich.

> **Eine Grenze, die erst im Browser gezogen wird, ist keine.** Ein Blick auf
> die leere Spalte sagt nicht, ob der Name über die Leitung kam.

**Diese Zeile ist gemessen und nicht geraten.** Der erste Entwurf las
`window.__inertia.page.props`; in einem echten Chromium gegen dieses Panel gibt
das `undefined`. Die Nutzlast steht in einem
`<script data-page="app" type="application/json">`, und **sie ist die der
geladenen Seite** — nach einer Navigation über das Menü stünde dort die vorige.

> **Eine Messvorschrift, die eine Laufzeit voraussetzt, die es nicht gibt, misst
> nicht — sie meldet einen Fehler an sich selbst.**

---

## 8 · 390 px

`tests/bilder-messen.js` in die Browserkonsole, dann `bilderMessen()` — **einmal
je frisch geladener Seite**, vier Lagen (390/1440 × hell/dunkel).

**Erwartet:** `dokument = 0`, `gegenprobe = 200 (soll 200)`, `schiebt = 0`.

**Mit dem echten Bestand** — auf `cloudsrv24` sind das mehr Lauscher als im
Container, und die Seite ist damit länger als jede, die hier gemessen wurde.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Ob ein Port von aussen erreichbar ist.** Das ist der Punkt: Gemessen (M20)
  kann diese Maschine es nicht beantworten, und der Wurf verspricht es nicht.
- **`ufw` und `firewalld` als laufende Verwalter** — siehe §0.2.
- **Das Regelwerk im Wortlaut.** Vier Zeilen Zustand, nicht die Ketten; das ist
  der zweite Wurf.
- **UDP.** `ss -ltnp` fragt TCP. Ein lauschender DNS oder NTP steht hier nicht,
  und das ist entschieden (`docs/109 §8`) und nicht vergessen.
- **Die Bestandsdiagnose.** A10 ist abgenommen; ein neuer `check` dort braucht
  seinen eigenen Nachlauf.

---

## 10 · Wann er durch ist

**Alle acht Punkte erfüllt**, und **2 und 3 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt — und „nicht
herstellbar" heisst am **Gegenstand** gescheitert und nicht am Werkzeug.

Ins Protokoll gehören:

- die **Fassung**, gegen die gemessen wurde,
- je Punkt der **gemessene** Wert und nicht „erfüllt",
- der Beleg, dass der Prüfstand abgeräumt ist: `iptables-legacy -S` gegen den
  Vorflug, `nft list ruleset` gegen den Vorflug, und alle vier Dienste `active`,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
