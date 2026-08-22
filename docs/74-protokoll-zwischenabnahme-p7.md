# Protokoll: Zwischenabnahme P7

Gefahren am 22. August 2026 auf `cloudsrv24` gegen **`v0.7.0-rc.1`**, vom
MacBook aus. Der Lauf steht in `docs/73`; er ist Schritt 8 aus `docs/72 §8`.

**Ergebnis: alle Punkte erfüllt.** Vier Befunde, **keiner davon ein Fehlschlag
des Merkmals** — drei betreffen die Auskunft *über* das Merkmal, einer liegt
ausserhalb von P7. Dazu ein Fehlschluss von mir, der einen Schritt gekostet hat.

---

## 1. Was gemessen wurde

| # | Punkt | Ergebnis |
|---|---|---|
| 0 | Fassung | `0.7.0-rc.1` ✓ |
| 1 | Migration | `[21] Ran` ✓ |
| 2 | Timer angestellt, mit Termin | `enabled`, `NEXT` 12:31:00, `ACTIVATES` richtig ✓ |
| 3 | Frist der Unit | `10min` ✓ · Vorgabe gemessen: **`infinity`** |
| 4 | Durchgang von Hand | `2 fällig, 2 geprüft, 0 ohne Antwort (0,1 s)` ✓ |
| 5 | Frische | zweiter Lauf `0 fällig` ✓ |
| 6 | Bereich an der Domain | vier Zustände, alle richtig ✓ |
| 7 | Der Knopf | Meldung, neuer Zeitpunkt ✓ |
| 8 | Kein Vorgang | keine neue Zeile ✓ |
| 9 | Bestand nach Seitenwechsel | unverändert ✓ |
| 10 | Timer feuert von selbst | zwei Läufe im Journal, ohne Zutun ✓ |
| 11 | Adressen | `159.195.56.255` + `2a0a:…:3083`, docker0 gesiebt ✓ |

**Die Messstrecke** war `cloudlab24.de` (`A` und `AAAA` auf den Server) und
`cloudlab24.ipv64.de` (`A` auf `198.51.100.1`, kein `AAAA`). Beide als
Zusatzdomain unter dem Abonnement `p6-b.invalid`.

**Vier Zustände in einem Lauf**, und damit mehr, als die Zwischenabnahme
verlangt:

| Name | Satz | Zustand | Erwartet / gefunden |
|---|---|---|---|
| `cloudlab24.de` | `A` | Zeigt hierher (grün) | `159.195.56.255` / dito |
| `cloudlab24.de` | `AAAA` | Zeigt hierher (grün) | `2a0a:…:3083` / dito |
| `cloudlab24.ipv64.de` | `A` | Zeigt woandershin (**gelb**) | `159.195.56.255` / `198.51.100.1` |
| `cloudlab24.ipv64.de` | `AAAA` | Fehlt (rot) | `2a0a:…:3083` / `—` |

Damit sind **Kriterium 2** (der gefundene Wert steht da, nicht nur „falsch") und
**Kriterium 3** („Fehlt" ist von „Zeigt woandershin" unterschieden) im Vorbeigehen
belegt. Und die Farbwahl trägt: „woandershin" ist gelb und keine Fehlermeldung —
wer über ein CDN fährt, wird nicht angeblafft (`docs/72 §4`).

**Der fremde Alias ist die eigentliche Probe gewesen.** `cloudlab24.ipv64.de`
liegt in `ipv64.de` und damit ausserhalb der Zone seiner Domain — genau der Fall,
an dem die erste Fassung von `Dns` scheiterte und die **ganze** Domain als „nicht
erreichbar" ausgab. Er hat geantwortet. Der Aufruf je Name trägt auf einem echten
Server.

---

## 2. Befund 1 — der Bericht kann zwei Fälle nicht unterscheiden

**Der erste Lauf des Timers meldete `2 geprüft, 2 ohne Antwort` in `0,0 s`**, und
ich habe daraus geschlossen, der Agentenaufruf sei gescheitert. Das war falsch:
Im Panel standen zu dem Zeitpunkt nur die beiden Reste `p6-abnahme.invalid` und
`p6-b.invalid`, und `.invalid` ist nach RFC 2606 reserviert. „Ohne Antwort" war
die richtige Auskunft, und `0,0 s` auch — der Auflöser weist `.invalid` sofort ab.

**Um das zu wissen, mussten wir ins Protokoll des Agenten sehen.** Dort stand
`{"op":"dns.check","ok":true}` und in den Argumenten die Zone. Der Bericht des
Kommandos allein gibt es nicht her:

- `AgentMeasurement::of()` fängt **jede** Ausnahme und gibt `null`.
- `Survey` überspringt den Namen, `nameservers` bleibt leer.
- `Sweep::wasSilent()` liest genau diese Leere und zählt „ohne Antwort".

Ein gescheiterter Agentenaufruf und eine Zone ohne Nameserver sind danach
dasselbe. Es ist die Form, vor der `docs/44` warnt — dort machte ein
`catch (Throwable) { return []; }` aus „nicht erreichbar" ein „der Betreiber
bietet es nicht an".

> **Ein Fehlerweg, der sich vom Normalfall nicht unterscheiden lässt, ist keine
> Auskunft, sondern eine Vermutung.**

**Nicht behoben.** Der Weg dahin ist klein — `Measurement` müsste den Fehlschlag
von der leeren Antwort trennen, und der Bericht müsste beides getrennt zählen —,
aber er gehört nicht in einen Lauf hinein.

---

## 3. Befund 2 — die Übersteuerung der Adressen hat keinen Weg hinein

`docs/72 §2.1a` hat entschieden: „Abgeleitet, mit Übersteuerung." Gebaut ist die
Ableitung. `Settings::saveDnsAddresses()` existiert und **wird von nichts
aufgerufen** — kein Formular, keine Route, kein Schalter am Kommando. Über
`app/`, `resources/js/` und `routes/` ausgezählt: drei Fundstellen, zwei davon
die Definition selbst.

Zwei Folgen:

1. Läuft ein Server hinter NAT, einer Floating-IP oder einem Lastverteiler,
   meldet der Abgleich **jede** Domain als „Zeigt woandershin", und es gibt in
   dieser Fassung keinen Weg, das zu berichtigen.
2. Der Warnhinweis auf der Domainseite, der eingetragene gegen abgeleitete
   Adressen hält, kann nie erscheinen. Er ist toter Code.

> **Eine Einstellung, die sich lesen, aber nirgends setzen lässt, ist keine
> Einstellung — sie ist ein Vorsatz.**

**Auf `cloudsrv24` folgenlos:** Punkt 11 hat gemessen, dass die abgeleiteten
Adressen die richtigen sind. Der Befund bleibt trotzdem stehen — er ist eine
Eigenschaft des Codes und nicht dieses Servers.

Bemerkenswert ist, was ihn **nicht** gefunden hat. P3 hat für genau diese Form
einen Wächter (`AgentOperationReachTest`: „zwei fertig gebaute Agent-Operationen
wurden von nichts aufgerufen"). Für einen Schreiber in `Settings` gibt es keinen.

---

## 4. Befund 3 — der Zeitpunkt rechnet in der falschen Zeitzone

Auf **einer** Seite stehen zwei Zeitstempel aus zwei Quellen:

| Anzeige | Weg | Zone |
|---|---|---|
| Vorgänge (`2026-08-22 12:49:14`) | `Clock::display()`, `DomainController.php:281` | die **eingestellte** Anzeigezeitzone |
| DNS-Abgleich (`22.8.2026, 13:22:30`) | `checked_at` als ISO-8601 an den Browser, dort `new Date().toLocaleString('de-DE')` | die Zone des **Browsers** |

`docs/40` hat `Clock` als *die eine Stelle* gebaut, die aus UTC eine Anzeige
macht; achtzehn Lesestellen gehen darüber. Der DNS-Bereich ist eine neunzehnte,
die es nicht tut.

**Im Lauf ist es nicht aufgefallen**, weil das MacBook und die Anzeigezeitzone
beide CEST sind. Wer das Panel aus einer anderen Zone öffnet, sieht zwei Zeiten
nebeneinander, keine davon beschriftet — und die Frage „ist das vor oder nach dem
Vorgang passiert?" ist dann nicht beantwortbar.

> **Zwei Zeitangaben auf einer Seite, die in verschiedenen Zonen rechnen, sind
> schlimmer als eine falsche: Man kann sie miteinander vergleichen.**

**P7 hat die Abweichung nicht erfunden.** `Databases/Show.vue:438` und
`Files/Index.vue:114` rechnen genauso im Browser. Es sind jetzt drei.

---

## 5. Befund 4 — drei Timer ohne Frist, ausserhalb von P7

Punkt 3 hat die Zahl gemessen, die dieser Entwicklungscontainer nicht liefern
kann:

```
srvpanel-dns.service    TimeoutStartUSec=10min
srvpanel-cron.service   TimeoutStartUSec=infinity
```

**`infinity` bestätigt die Begründung**, die in `srvpanel-dns.service` steht: Ein
`Type=oneshot` ohne eigene Angabe läuft ohne Frist. Hängt so ein Lauf — an einem
Systemaufruf, an einem Socket, an einem fremden Server —, bleibt die Unit in
`activating`, und **systemd startet sie beim nächsten Termin nicht noch einmal.**
Ein einziger hängender Lauf nimmt dann alle folgenden mit.

Das gilt für `srvpanel-cron`, `srvpanel-usage` und `srvpanel-tls`. Bei `tls` ist
es am teuersten: Eine Erneuerung wartet auf die Zertifizierungsstelle, und wenn
sie hängt, erneuert dieser Server nie wieder ein Zertifikat — ohne Fehlermeldung,
mit einem Timer, der `enabled` meldet.

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

Derselbe Satz wie am 19. August 2026, andere Ursache. Damals war es ein Timer
ohne Kalender, diesmal wäre es ein Dienst ohne Frist.

**Ausserhalb von P7 und ausdrücklich nicht behoben.** Der Handgriff ist klein —
drei Units bekommen ein `TimeoutStartSec` — aber er gehört nicht in diesen Zweig.

---

## 6. Was der Lauf nebenbei belegt hat

- **Die Frische greift auf beiden Wegen.** Von Hand (`0 fällig` unmittelbar nach
  einem Lauf) und aus dem Timer heraus (`0 fällig` um 12:16 und 12:45, weil die
  Befunde von 12:03 noch keine Stunde alt waren).
- **Der Knopf misst ohne Rücksicht auf die Frische** — 12:53:57 und dann
  13:22:30, beide von Hand ausgelöst.
- **Der Knopf hängt an der Policy und nicht am Kontotyp.** Die Seite war als
  Kunde geöffnet („Angemeldet als Philipp Foos, gewechselt von Administrator"),
  und der Knopf war da.
- **`docker0` wird gesiebt.** `172.17.0.1` steht in `ip -brief`, aber nicht in
  den erwarteten Werten.
- **Die Nameserver stimmen je Zone.** `cloudlab24.de` ist an `ns1/ns2.ipv64.net`
  delegiert; `cloudlab24.ipv64.de` hat keinen eigenen NS-Satz, und der Auflöser
  steigt zu `ipv64.de` auf — wie gebaut. Dass beide Seiten dieselben zwei
  Adressen zeigen, war echt und keine Verwechslung der Zonen.

---

## 7. Was dieser Lauf nicht geprüft hat

Unverändert gegenüber `docs/73 §5`: die acht Abnahmekriterien aus `docs/72 §3`
in ihrer vollen Form, die Bilderrunde bei 390 px in beiden Themes, der Zustand
„Nameserver uneinig", ein fremdes `CAA` — und die Grenze des Durchgangs, die auf
einem Server mit vier Domains nicht auszulösen ist.

**Neu dazugekommen:** Kriterium 4 („die Prüfung fragt die autoritativen Server
und nicht den Systemauflöser") ist hier **nicht** gemessen worden. Der Lauf hat
keine Änderung eingespielt und beobachtet, ob sie vor einem Zwischenspeicher
sichtbar wird. Das ist der Punkt, der etwas beweist, und er gehört in den
Abnahmelauf.
