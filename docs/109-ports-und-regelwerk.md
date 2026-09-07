# A3, erster Wurf — welche Ports lauschen und welches Regelwerk läuft

Geschrieben am **7. September 2026**, **nach** der Messrunde (`docs/81 §2.3s`).
Der erste Wurf von A3 **zeigt an und schreibt nicht**; der zweite Wurf, der eine
eigene nftables-Tabelle anlegt, steht in P9b und ist nicht Gegenstand dieses
Plans.

**Warum es ihn gibt.** Seit P5b öffnet dieses Panel auf Wunsch 3306 und 5432 und
schreibt daneben, die Firewall sei nicht seine Sache — es öffnet also einen Port
und kann nicht sagen, ob er erreichbar ist. `srvpanel db --remote=on` sagt
wörtlich „die Firewall kommt mit P9". Das ist die grösste Lücke zwischen dem,
was dieses Panel *tut*, und dem, was es über den Zustand danach *sagen* kann.

---

## 1. Was die Messrunde schon entschieden hat

Fünf Messungen, und zwei davon haben den Entwurf umgeworfen, bevor eine Zeile
entstand. Die Werte stehen in `docs/81 §2.3s`; hier steht, was daraus folgt.

### 1.1 Ein Werkzeug reicht nicht — und das Schweigen des falschen sieht aus wie eine Antwort

**M10.** Eine Regel über `iptables-legacy` ist für `nft list ruleset`
unsichtbar: `rc=0`, stdout 0 Bytes, stderr 0 Bytes. Das ist zeichengleich mit
„es gibt keine Regeln".

> **Ein leeres Regelwerk und ein Regelwerk, das man mit dem falschen Werkzeug
> abfragt, sehen gleich aus — und beide sagen `rc=0`.**

Die gemessene Sichtbarkeit:

| Regeln von | `nft list ruleset` | `iptables-nft -S` | `iptables-legacy -S` |
|---|---|---|---|
| `iptables-nft` | ja (`table ip filter`) | ja | nein |
| `iptables-legacy` | **nein** | nein | ja |
| `firewalld` | ja (`table inet firewalld`) | **nein** | nein |
| `ufw` | ja (`ip filter` + `ip6 filter`) | ja (Ketten `ufw-*`) | nein |

**Kein einzelnes Werkzeug sieht alle vier.** Der Leser fragt deshalb **beide
Familien** — `nft list ruleset` **und** `iptables-legacy -S` — und `nft` allein
darf nie zu „keine Regeln" führen.

**Und es sind zwei Fragen, nicht eine.** *Was steht im Regelwerk* beantwortet
`nft`; *wer verwaltet es* beantwortet es nicht — eine `table ip filter` sagt
nicht, ob ufw sie geschrieben hat oder jemand von Hand. Dafür gibt es
`ufw status` und `firewall-cmd --state`.

### 1.2 Der Server kann nicht sagen, ob ein Port von aussen erreichbar ist

**M20**, gemessen an zwei Läufen mit einer DROP-Regel davor:

| | Blick von innen | von aussen |
|---|---|---|
| ohne Sperre davor | `LISTEN 0 5 0.0.0.0:19100` · `nft rc=0, 0 Bytes` | **erreichbar** |
| mit DROP davor | `LISTEN 0 5 0.0.0.0:19100` · `nft rc=0, 0 Bytes` | **nicht erreichbar** |

Der Blick von innen ist Feld für Feld derselbe.

> **Ein Port, der lauscht, und ein Regelwerk, das nichts verbietet, sagen über
> die Erreichbarkeit von aussen nichts — und sie sagen es in beiden Fällen mit
> denselben Zeichen.**

**Das ist die Zusage, die dieser Wurf nicht machen darf.** Auf einem gemieteten
Server steht regelmässig eine Cloud-Firewall davor, die diese Maschine nicht
sieht. Eine Anzeige „Port offen", die sich auf `ss` und `nft` stützt, ist dort
falsch — und zwar schweigend falsch, was schlimmer ist als keine Anzeige.

### 1.3 Zwei Werkzeuge antworten leer, wenn sie nicht durften

**M4.** `ss -ltnp` ohne root: dieselben Zeilen, `rc=0`, und **die Prozessspalte
ist wortlos leer**. Keine Meldung, kein anderer Rückgabewert.

> **Eine leere Spalte, die „nicht nachgesehen" bedeutet, sieht aus wie
> „niemand".**

**M8** dagegen ist der freundliche Fall: `nft` ohne CAP_NET_ADMIN gibt `rc=1`
und `Operation not permitted (you must be root)` — „konnte nicht nachsehen" ist
dort **unterscheidbar** von „nichts da". Der Agent läuft als root und kommt an
beides; der Leser muss trotzdem wissen, unter welchen Rechten er gefragt hat,
sonst meldet er für jeden Port „Eigentümer unbekannt".

### 1.4 Ein Rückgabewert kann aus einem Fehlschlag vor der Frage stammen

**M15.** `firewall-cmd --state` hat vier gemessene Ausgänge, und **drei
beantworten die Frage nicht**: `rc=1` (der Interpreter passte nicht), `rc=36`
(kein D-Bus), `rc=252` (Bus da, Dienst aus — *das* ist die Antwort), `rc=0`
(`running`).

> **Ein Rückgabewert, der aus einem Fehlschlag vor der Frage entsteht, sieht aus
> wie eine Antwort auf die Frage.**

Der Leser wertet deshalb **den genannten Zustand** und nicht bloss „ungleich
null"; alles, was er nicht kennt, ist `nicht feststellbar` und nicht „aus".

### 1.5 Was der Container nicht messen konnte

**Kein IPv6.** `/proc/net/if_inet6` gibt es nicht, `AF_INET6` gibt `Errno 97`,
und eine eigene Netz-Namespace hilft nicht. Damit ist hier **ungemessen**, wie
ein Lauscher auf `::` aussieht und ob `bindv6only` greift — genau die Falle, die
`docs/44` schon einmal bezahlt hat: MariaDB bindet `::` ausschliesslich IPv6,
und das Panel verband über `127.0.0.1`.

Das ist keine Lücke im Plan, sondern ein Punkt des Abnahmelaufs (§7 Punkt 5).

---

## 2. Die Fragen an den Betreiber

Vier, und die erste entscheidet den Umfang.

| | Frage | Vorschlag |
|---|---|---|
| **1** | Soll dieser Wurf „von aussen erreichbar" überhaupt beantworten — über einen Dienst ausserhalb, den das Panel fragt? | **Nein.** Ein solcher Dienst erführe, welche Ports dieser Server offen hat; das ist eine Auskunft an Dritte, die niemand bestellt hat. Der Wurf zeigt, was die Maschine weiss, und sagt beim Rest, dass sie es nicht weiss. |
| **2** | Wer darf hinsehen — Administrator (`inspect-server`) oder nur der Betreiber? | **`inspect-server`**, wie `/services` und `/updates`. Lauschende Ports sind dieselbe Art Auskunft wie installierte Paketfassungen, und die sind in `docs/81 §3` Frage 2 ausdrücklich zugelassen. **Aber:** `ss -ltnp` nennt auch **Prozessnamen**, und das ist neu. |
| **3** | Wo liegt es — ein Bereich auf `/services` oder eine eigene Seite? | **Ein Bereich auf `/services`.** Wer wissen will, was auf diesem Server läuft, sucht dort; eine eigene Seite wäre der vierte Menüpunkt für dieselbe Frage. |
| **4** | Bekommt die Bestandsdiagnose (A10) einen `check` dafür? | **Nicht in diesem Wurf.** A10 ist abgenommen; ein neuer `check` ist eine Änderung an einer abgenommenen Stufe und braucht ihren eigenen Nachlauf (derselbe Grund wie in `docs/107 §9`). |

**Frage 2 ist die, die ich nicht allein entscheiden will.** Ein Prozessname
verrät mehr als eine Portnummer: `ss -ltnp` zeigt, dass auf 25 ein `postfix`
sitzt und auf 11211 ein `memcached`. Das ist keine Zugangsdatei und kein Weg zu
root — aber es ist eine Landkarte. Der Vorschlag lautet, den **Port** dem
Administrator zu zeigen und den **Prozessnamen** dem Betreiber vorzubehalten;
das ist derselbe Schnitt wie bei der Schlüsselspalte auf `/updates`
(`SourceKeyFilterTest`).

---

## 3. Die Form

### 3.1 Der Agent

Eine Operation, **`system.ports`**, lesend, ohne jeden Parameter von aussen —
dieselbe Bauart wie `system.time` aus A11 und `system.diagnose` aus A10. Sie
ruft vier Programme von der Positivliste und gibt einen Zustand zurück, keinen
Text:

```
{
  "listeners": [
    { "address": "0.0.0.0", "port": 3306, "family": "inet",
      "scope": "any" | "loopback" | "specific",
      "process": "mariadbd" | null, "pid": 1234 | null }
  ],
  "privileged": true,
  "filter": {
    "readable": true,
    "reason": null | "unreadable" | "incomplete",
    "nft":     { "tables": ["inet firewalld"], "bytes": 1234 },
    "legacy":  { "configured": false, "lines": 3 },
    "manager": "firewalld" | "ufw" | "iptables" | "none" | "unknown"
  }
}
```

**Drei Dinge daran sind aus den Messungen abgeleitet und nicht gewählt.**

`privileged` steht da, weil M4 die leere Prozessspalte nicht von „niemand"
unterscheidet — ohne dieses Feld wäre die Anzeige „Eigentümer unbekannt" für
jeden Port eine Aussage über den Server statt über den Aufruf.

`legacy.configured` hing hier zuerst an `lines > 3`, weil M11 gemessen hat, dass
beide Bauarten im unberührten Zustand genau die drei Zeilen
`-P INPUT/FORWARD/OUTPUT ACCEPT` ausgeben.

**Berichtigt am 7. September beim Bauen** (M11b): Das ist falsch. Ein
`iptables-legacy -P INPUT DROP` **ohne eine einzige Regel** gibt ebenfalls drei
Zeilen — und sperrt alles.

> **Eine Zahl, die „unberührt" bedeuten soll, zählt eine geänderte
> Standardrichtlinie mit — und die ist genau der Fall, den man sehen will.**

Gefragt wird deshalb nach dem **Inhalt**: Alles, was nicht `-P <Kette> ACCEPT`
ist, ist eine Konfiguration. Das trägt beide Fälle und zählt nichts.

`manager` ist eine **geschlossene** Grundmenge und kein durchgereichter Wortlaut
— aus demselben Grund, aus dem A11 `„Operation not possible due to RF-kill"`
nicht auf die Seite lässt.

### 3.2 Die Wortwahl — der Kern dieses Wurfs

Je Lauscher **eine** Zeile, und sie sagt genau, was gemessen ist:

| Zustand | Anzeige |
|---|---|
| `0.0.0.0` oder `::` | **lauscht auf allen Adressen** |
| `127.0.0.1` oder `::1` | **lauscht nur lokal** |
| eine bestimmte Adresse | **lauscht auf `<Adresse>`** |
| `privileged: false` | Eigentümer **nicht feststellbar** — nicht „keiner" |

Und **einmal je Seite**, nicht je Zeile, der Satz, den M20 verlangt:

> Ob diese Ports von aussen erreichbar sind, weiss dieser Server nicht. Steht
> eine Firewall des Anbieters davor, sieht er sie nicht.

**Die Wörter „offen", „erreichbar" und „geschlossen" kommen in diesem Bereich
nicht vor.** Sie sind Aussagen über den Weg von aussen, und den misst hier
niemand. Was gemessen ist, heisst „lauscht" und „im Regelwerk dieser Maschine
gesperrt".

### 3.3 Das Regelwerk

Ein zweiter, kleiner Bereich mit vier Zeilen: welcher Verwalter erkannt wurde,
ob `nft` etwas führt, ob `iptables-legacy` etwas führt, und wann zuletzt
gefragt. **Kein Regelwerk im Wortlaut** — das wäre der zweite Wurf und ausserdem
eine Textwand.

**Und die Zeile, die es ohne M10 nicht gäbe:** Führt `iptables-legacy` Regeln
**und** `nft` nicht, steht dort ausdrücklich, dass die Regeln dieser Maschine in
der alten Bauart liegen. Ein Leser, der nur `nft` kennt, hielte sie sonst für
leer.

---

## 4. Wo es liegt

Ein Bereich **„Ports und Regelwerk"** auf `/services`, unter den Diensten.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?** Der Satz steht in `CLAUDE.md`, weil derselbe Fehler dreimal passiert
> ist. „Was läuft auf diesem Server" und „worauf horcht es" ist eine Frage, und
> sie hat schon eine Seite.

---

## 5. Die Wächter

| Wächter | Regel |
|---|---|
| `PortStateTest` | Der Leser für `ss -H -ltnp` geht nach **Feldern** und nicht nach Spaltenbreite; die Prüfkörper sind die gemessenen Ausgaben aus `§2.3s`. `privileged: false` trägt **keinen** Eigentümer, statt `null` als „niemand" auszugeben. |
| `FilterVerdictTest` | Der Zustand des Regelwerks entsteht aus **beiden** Familien. Eine leere Antwort von `nft` allein führt nie zu „keine Regeln" — gemessen an einem Prüfkörper, in dem `iptables-legacy` Regeln führt und `nft` nichts. Und `legacy.configured` hängt an **mehr als drei Zeilen**. |
| `ReachabilityWordTest` | Im Bereich stehen die Wörter „erreichbar", „offen" und „geschlossen" nicht — und der Satz, dass der Server es nicht weiss, steht genau **einmal**. Gehalten an der `.vue` ohne Framework. |
| `ManagerVocabularyTest` | `manager` ist eine geschlossene Grundmenge; jeder Wert, den der Agent aussprechen kann, ist dem Panel bekannt — dieselbe Naht wie `DiagnoseSeamTest`. |
| `PortAbilityTest` | Der Bereich trägt die Fähigkeit seiner Route, und der Prozessname erscheint nur für den Betrachter, der ihn sehen darf (nach Frage 2). |

Jeder mit seinem Bruch in `tests/waechter-brechen.sh`, jeder einmal rot gesehen.

---

## 6. Die Fallen, die schon dastehen

1. **`ss` kennt kein `--json`** (M2). Der Leser zerlegt Spalten. Die Kopfzeile
   klebt (`Peer Address:PortProcess`) — gelesen wird mit `-H`.
2. **Eine unbekannte Option gibt `rc=255`** und alles auf stderr. Ein leerer
   stdout mit `rc != 0` ist deshalb ein Fehlschlag und keine leere Liste.
3. **`iptables` ist ein alternatives-Symlink.** Der Agent ruft
   `iptables-legacy` und `iptables-nft` mit **absoluten Pfaden** und nie
   `iptables` — sonst hängt die Antwort daran, worauf der Symlink gerade zeigt.
4. **Ein Prüfkörper bleibt liegen.** `iptables` lässt seine `table ip filter`
   stehen, auch wenn man jede Regel löscht. Wer für den Abnahmelauf eine Regel
   anlegt, räumt die Tabelle mit ab — sonst misst der nächste Punkt sie mit.

---

## 7. Das Abnahmekriterium — acht Punkte

Gefahren auf `cloudsrv24`. **Die Punkte 2 und 3 dürfen nicht ausfallen.**

| | Was | Erfüllt, wenn |
|---|---|---|
| **1** | Die Lauscher stehen da | Ein für den Lauf angelegter Lauscher auf `0.0.0.0` erscheint mit Port, Bereich und Eigentümer; einer auf `127.0.0.1` erscheint als **nur lokal**. |
| **2** | **Legacy wird gesehen** *(Ausschluss)* | Eine Regel über `iptables-legacy` erscheint im Bereich — während `nft list ruleset` daneben `rc=0` und nichts gibt. Ohne diesen Punkt ist M10 nicht behoben, sondern beschrieben. |
| **3** | **Keine Zusage über aussen** *(Ausschluss)* | Bei lauschendem Port und leerem Regelwerk steht **nirgends** „offen" oder „erreichbar", und der Satz aus §3.2 steht genau einmal auf der Seite. |
| **4** | Der Verwalter wird benannt | Mit laufendem `ufw` steht `ufw` da, ohne jeden Verwalter `keiner` — und bei einem Zustand, den der Agent nicht kennt, `nicht feststellbar`. |
| **5** | **IPv6** | Ein Lauscher auf `::` erscheint mit `family: inet6` und als „auf allen Adressen". Der Fall, den der Container nicht messen konnte (§1.5). |
| **6** | Nicht feststellbar bleibt nicht feststellbar | Mit angehaltenem Agenten steht im Bereich **`nicht feststellbar`** und nicht „keine Ports" und nicht „keine Regeln". |
| **7** | Die Tür | Ein Administrator sieht den Bereich; ein Kundenkonto bekommt 403. Der Prozessname erscheint nach der Entscheidung aus Frage 2. |
| **8** | 390 px | Vier Lagen mit `tests/bilder-messen.js`, je frisch geladen: `dokument = 0`, Gegenprobe 200/200. **Mit einem echten Bestand**, nicht mit drei Zeilen — auf einem Server sind es zwanzig Lauscher und mehr. |

**Ins Protokoll gehören** je Punkt der gemessene Wert und nicht „erfüllt", die
Fassung, gegen die gemessen wurde, der Beleg, dass der Prüfstand abgeräumt ist
(Regel **und** Tabelle), und was danach offen bleibt.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

---

## 8. Was A3s erster Wurf ausdrücklich **nicht** wird

- **Er schreibt nichts.** Keine Regel, kein Schalter, keine Rücknahme nach Zeit.
  Das ist der zweite Wurf, und er steht in P9b — mit der Frist, ohne die ein
  Regelsatz den eigenen Zugang zusperrt.
- **Er beantwortet „von aussen erreichbar" nicht** (Frage 1). Gemessen ist, dass
  diese Maschine es nicht kann; ein Dienst ausserhalb wäre eine Auskunft an
  Dritte über die offenen Flächen dieses Servers.
- **Er zeigt kein Regelwerk im Wortlaut.** Vier Zeilen Zustand, nicht die
  Ketten.
- **Er bekommt keinen `check` in der Bestandsdiagnose** (Frage 4) — A10 ist
  abgenommen, und eine Änderung dort braucht ihren eigenen Nachlauf.
- **Er fasst UDP nicht an.** `ss -ltnp` fragt TCP. Ein lauschender UDP-Dienst
  (DNS, NTP) ist eine eigene Frage mit einer eigenen Messrunde; hier stünde er
  ungemessen da.
