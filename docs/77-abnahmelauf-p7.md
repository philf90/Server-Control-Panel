# Abnahmelauf P7 — der DNS-Abgleich auf `cloudsrv24`

Geschrieben am 23. August 2026. Der Lauf ist **Schritt 10** aus `docs/72 §8` und
prüft die **acht Punkte des Abnahmekriteriums** aus `docs/72 §3`. Mit ihm ist P7
abgenommen oder nicht.

**Dieses Dokument ist die Vorschrift, nicht das Protokoll.** Das Protokoll
entsteht **während** des Laufs als `docs/78` und nicht danach — solange keine
Messung darin steht, ist ein Protokoll eine Gliederung.

---

## 1. Was dieser Lauf beweist, und was schon feststeht

**Sieben der acht Punkte sind Anzeigefragen.** Sie liessen sich auch mit
`dns_get_record()` bauen. Punkt 4 ist der, der etwas beweist:

> **Ein Zwischenspeicher, der eine Anleitung beantwortet, macht aus einer Hilfe
> eine Irreführung.**

Und genau Punkt 4 hat die Zwischenabnahme **nicht** gemessen (`docs/74 §7`): Sie
hat keine Änderung eingespielt und beobachtet, ob sie vor einem
Auflöserzwischenspeicher sichtbar wird. Punkt 3 dieses Laufs ist deshalb der
teuerste und der einzige, der Vorbereitung am Vortag braucht.

**Was schon steht und hier nicht wiederholt wird** — abgenommen in `docs/74`
gegen `v0.7.0-rc.1`: Migration, Timer, Dienst, die gemessene Frist der Unit, der
Durchgang von Hand, die Frische, der Knopf, der Protokolleintrag und dass der
Befund den Seitenwechsel überlebt. Vier Befunde sind daraus behoben; die
Bilderrunde (`docs/76`) hat acht weitere gebracht, alle behoben **und**
nachgesehen.

---

## 2. Was man braucht

- **`cloudsrv24` mit dem Stand, gegen den abgenommen wird.** Punkt 0 belegt ihn.
- **Ein Terminal auf dem MacBook** und **einen Browser** mit den
  Entwicklerwerkzeugen.
- **Zugriff auf die Zone `cloudlab24.de` bei ipv64** — der Lauf setzt und ändert
  dort Einträge. Ohne diesen Zugriff sind die Punkte 1 bis 4 und 7 nicht fahrbar.
- **Etwa 90 Minuten**, davon rund 30 Wartezeit.
- **Am Vortag oder mindestens eine Stunde vorher:** die Vorbereitung aus §3.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.**

  > **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
  > wurde.** Der teuerste Fehler von P4 meldete genau die Zahl, die das
  > Kriterium verlangte, und tat das Falsche.

### 2.1 Was dieser Lauf kostet, und zwar wirklich

**Jede neu angelegte Domain löst eine Zertifikatsbestellung aus**, sobald ihr
Server-Block steht. Für einen Namen, der nicht hierher zeigt, scheitert sie —
und jeder Fehlversuch zählt gegen **fünf je Stunde, die für alle Kunden dieses
Servers zusammen gelten** (`docs/34 §11`).

Der Lauf legt **zwei** solche Domains an (Punkt 2 und Punkt 4). Das sind zwei der
fünf. Wer den Lauf wiederholen muss, wartet eine Stunde oder legt die Domains
nicht neu an.

> **Ein Zustand, dessen Herstellung ein Kontingent kostet, wird dort geprüft, wo
> er ohnehin entsteht.**

---

## 3. Die Vorbereitung — mindestens eine Stunde vorher

**Sie muss vor dem Lauf geschehen, weil DNS Zeit braucht.** Wer sie im Lauf
macht, misst die Verzögerung des Anbieters und nicht das Panel.

In der Zone `cloudlab24.de` bei ipv64 einrichten:

| Name | Satz | Wert | Wofür |
|---|---|---|---|
| `hier.cloudlab24.de` | `A` | die Adresse von `cloudsrv24` (gemessen: `159.195.56.255`) | Punkt 1 |
| `fremd.cloudlab24.de` | `A` | `192.0.2.1` | Punkt 2, 4 |
| `ohne.cloudlab24.de` | — | **kein Satz** | Punkt 3 |

**`192.0.2.1` und nicht irgendeine Adresse.** Das ist TEST-NET-1 aus RFC 5737 —
sie gehört niemandem und kann mit keinem echten Rechner verwechselt werden. Wer
hier die Adresse eines fremden Hosters einträgt, hat im Protokoll eine Zahl
stehen, die aussieht wie ein Befund.

**Die TTL von `fremd.cloudlab24.de` auf 3600 setzen.** Punkt 4 hängt daran: Ein
Zwischenspeicher, der nach zwei Minuten ohnehin verfällt, belegt nichts.

**Und der `CAA`-Satz auf `cloudlab24.de` bleibt stehen** —
`cloudlab24.de. CAA 0 issue "digicert.com"`, gesetzt am 22. August und in
`docs/76 §4` als offen geführt. Er ist der Prüfkörper für Punkt 6. **Punkt 9
räumt ihn ab**, und das ist keine Aufräumarbeit, sondern Teil des Laufs:
Solange er steht, scheitert jede echte Bestellung für diese Domain.

### 3.1 Und die eine Sache, die am Vortag geschehen muss

**Den Zwischenspeicher des Systemauflösers auf `fremd.cloudlab24.de` füllen** —
mit dem **alten** Wert, damit Punkt 4 überhaupt etwas zu vergleichen hat:

```bash
ssh cloudsrv24 'dig +noall +answer fremd.cloudlab24.de A'
```

Erwartet: `192.0.2.1` mit einer TTL nahe 3600. **Ohne diesen Schritt misst Punkt
4 nichts**, und zwar unsichtbar: Ein Auflöser ohne Eintrag holt die Antwort
frisch und liefert denselben Wert wie das Panel.

> **Eine Gegenprobe, die zufällig dasselbe liefert wie der Prüfling, hat nichts
> verglichen.**

---

## 4. Der Lauf

### Punkt 0 — Welche Fassung läuft

```bash
ssh cloudsrv24 'readlink -f /opt/srvpanel/current; /opt/srvpanel/current/artisan --version'
```

Und im Browser die Fassung neben „SrvPanel" im Menü. **Beide notieren.** Ein
Abnahmelauf gegen eine Fassung, die niemand aufgeschrieben hat, ist eine
Erinnerung.

---

### Punkt 1 — „zeigt hierher" (Kriterium 1)

Im Panel `hier.cloudlab24.de` als Domain anlegen. **Sofort danach**, bevor der
erste Abgleich gelaufen ist, die Domainseite öffnen.

**Erwartet, und das sind drei Dinge auf einmal:**

1. Der Bereich „DNS-Abgleich" trägt die Marke **„ungeprüft"** oder „noch nie
   geprüft". *Das ist der Zustand aus `docs/76 §4`, der sonst nirgends
   herzustellen ist — er lebt nur zwischen dem Anlegen und dem ersten Lauf.*
   **Bild machen, sofort.** `srvpanel-dns.timer` fährt alle 15 Minuten.
2. Der Bereich „Zertifikat" zeigt eine Domain **ohne** Zertifikat, und darunter
   steht das Kästchen „Als Platzhalter bestellen" unmittelbar über der
   Knopfreihe. *Das ist der zweite Rest aus `docs/76 §4`: die Regel
   `.toggle + .button-row` greift nur in diesem Zustand, weil sonst ein
   `.section-note` dazwischensteht.* **Bild machen.**
3. Auf „Jetzt prüfen" klicken. Danach: `A` → **„Zeigt hierher"**, erwarteter und
   gefundener Wert gleich.

**Erst wenn die beiden Bilder gemacht sind, weitermachen** — der Zustand kommt
nicht wieder.

---

### Punkt 2 — „zeigt woandershin", mit dem gefundenen Wert (Kriterium 2)

`fremd.cloudlab24.de` als Domain anlegen, „Jetzt prüfen".

**Erwartet:** `A` → **„Zeigt woandershin"**, erwartet die Serveradresse,
**gefunden `192.0.2.1`**.

**Der gefundene Wert muss dastehen.** Ohne ihn ist die Anzeige ein Urteil ohne
Auskunft — und der Kunde weiss nicht, ob sein Eintrag beim alten Hoster steht
oder nirgends.

**Und keine rote Meldung.** „Zeigt woandershin" ist kein Fehler (`docs/72 §2.3`):
Wer absichtlich über ein CDN fährt, hat genau diesen Zustand.

---

### Punkt 3 — „fehlt" ist nicht „zeigt woandershin" (Kriterium 3)

`ohne.cloudlab24.de` als Domain anlegen, „Jetzt prüfen".

**Erwartet:** `A` → **„Fehlt"**, kein gefundener Wert.

**Und dann beide Seiten nebeneinander ansehen** — `fremd` und `ohne`. Kriterium 3
verlangt die *Unterscheidung*, nicht zwei Zustände. Sehen sie gleich aus, ist der
Punkt nicht erfüllt, auch wenn beide Wörter richtig dastehen.

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss.**

---

### Punkt 4 — Die autoritativen Server, nicht der Auflöser (Kriterium 4)

**Der Punkt, der etwas beweist.** Der Reihe nach, und die Reihenfolge ist das
Verfahren:

**(a) Den Zwischenspeicher belegen.** Er wurde in §3.1 gefüllt; jetzt nachsehen,
dass er noch hält:

```bash
ssh cloudsrv24 'dig +noall +answer fremd.cloudlab24.de A'
```

Erwartet: `192.0.2.1`, TTL deutlich unter 3600 und über 0. **Steht dort 3600,
ist der Eintrag gerade frisch geholt worden und der Zwischenspeicher war leer —
dann §3.1 wiederholen und mindestens eine Minute warten.**

**(b) Den Eintrag beim Anbieter ändern:** `fremd.cloudlab24.de` von `192.0.2.1`
auf **`198.51.100.1`** (TEST-NET-2).

**(c) Sofort — innerhalb der Restlaufzeit aus (a) — beide Wege fragen:**

```bash
ssh cloudsrv24 'echo "--- Systemaufloeser ---"; dig +noall +answer fremd.cloudlab24.de A; \
  echo "--- autoritativ ---"; dig +noall +answer @ns1.ipv64.net fremd.cloudlab24.de A'
```

Erwartet: Der Systemauflöser sagt **`192.0.2.1`** (aus dem Zwischenspeicher), der
autoritative Server sagt **`198.51.100.1`**. **Sagen beide dasselbe, ist der
Prüfkörper hin** — dann ist der Zwischenspeicher verfallen, und (a) bis (c)
müssen wiederholt werden. Weitermachen wäre sinnlos:

> **Eine Gegenprobe, die zufällig dasselbe liefert wie der Prüfling, hat nichts
> verglichen.**

**(d) Im Panel „Jetzt prüfen" klicken.**

**Erwartet: das Panel zeigt `198.51.100.1`** — den neuen Wert, den der
Systemauflöser in diesem Moment noch nicht kennt. Das ist der Beleg, und er ist
nur in diesem Zeitfenster zu haben.

**Die Ausgabe aus (c) und das Bild aus (d) gehören zusammen ins Protokoll.**
Eines allein belegt nichts: Die Ausgabe zeigt, dass ein Unterschied bestand, das
Bild, auf welcher Seite das Panel steht.

---

### Punkt 5 — Kein `AAAA` ohne IPv6 am Server (Kriterium 5)

`cloudsrv24` führt IPv6 (gemessen: `2a0a:4cc0:c1:ebd1:b82d:51ff:fe72:3083`), also
wird der Fall über die Übersteuerung hergestellt.

**(a)** Unter **Einstellungen → Allgemein** die Adressen ansehen. Erwartet: das
Feld ist leer, daneben stehen die **abgeleiteten** Werte, IPv4 und IPv6.

**(b)** Als Übersteuerung **nur die IPv4-Adresse** eintragen und speichern.

**(c)** Auf `hier.cloudlab24.de` „Jetzt prüfen".

**Erwartet: die `AAAA`-Zeile ist fort** — sie fällt **nicht** auf „Fehlt". Das
ist die Zusage aus `docs/72 §3`: Ein fehlendes `AAAA` ist kein Mangel, wenn der
Server keine IPv6 führt.

**(d)** Die Übersteuerung wieder leeren, speichern, erneut prüfen. Erwartet: die
`AAAA`-Zeile ist zurück.

**Schritt (d) ist kein Aufräumen, sondern die Gegenprobe.** Bliebe die Zeile auch
danach fort, hätte (c) nichts über die Übersteuerung gesagt, sondern über einen
Fehler.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

### Punkt 6 — Das fremde `CAA` wird gemeldet (Kriterium 6)

Auf `cloudlab24.de` (die trägt den Satz seit dem 22. August) „Jetzt prüfen".

**Erwartet:** ein Hinweis, dass der `CAA`-Satz die eigene Zertifizierungsstelle
nicht nennt — sichtbar **bevor** jemand eine Bestellung auslöst.

**Und die Gegenprobe im selben Lauf:** `hier.cloudlab24.de` hat kein eigenes
`CAA`. Dort darf **kein** Hinweis stehen.

**Und das ist kein Befund, obwohl es einer zu sein scheint.** Eine echte
Zertifizierungsstelle klettert bei fehlendem CAA zur Elternzone hinauf und fände
dort das `digicert.com` von `cloudlab24.de` — für `hier.cloudlab24.de` würde eine
Bestellung also sehr wohl scheitern. **Dieses Panel steigt bewusst nicht auf**
(`Authority`, mit Begründung im Kopf der Klasse): Es fragt nur die Namen, die es
ohnehin prüft. Deshalb meldet ein leerer Satz hier auch **nicht** „darf", sondern
„nichts gefunden".

> **Ein Urteil, das eine Regel nur halb kennt, gehört als halbes gekennzeichnet
> und nicht als ganzes ausgegeben.**

Wer im Lauf auf diese Lücke stösst, schreibt sie als **benannte Grenze** ins
Protokoll und nicht als Mangel. Sie ist erst dann einer, wenn die Anzeige
behauptet, es dürfe ausgestellt werden.

Zur Kontrolle, was wirklich in der Zone steht:

```bash
ssh cloudsrv24 'dig +noall +answer @ns1.ipv64.net cloudlab24.de CAA; \
  dig +noall +answer @ns1.ipv64.net hier.cloudlab24.de CAA'
```

---

### Punkt 7 — Der Zeitpunkt steht dabei (Kriterium 7)

Auf jeder der vier Domainseiten: Unter dem Abgleich steht **„Zuletzt geprüft:"**
mit Datum und Uhrzeit, dazu die gefragten Nameserver.

**Zweierlei prüfen:**

1. Die Uhrzeit ist die **lokale** und nicht UTC. `docs/74` Befund 3 war genau
   hier: Der Zeitpunkt rechnete in der falschen Zeitzone. Gegenprobe:
   ```bash
   ssh cloudsrv24 'date'
   ```
   Der Unterschied zur Anzeige darf nur die Messdauer sein, nicht zwei Stunden.
2. Der Zeitpunkt **ändert sich**, wenn man „Jetzt prüfen" drückt, und bleibt
   sonst stehen.

---

### Punkt 8 — Bei 390 px läuft nichts über (Kriterium 8)

Auf **jeder der vier Domainseiten**, in **beiden Themes**, bei **390 px**:
`tests/bilder-messen.js` einfügen, `bilderMessen()` aufrufen, Bild machen.

**Vorher hart neu laden, und nach jedem Breitenwechsel noch einmal** — eine
Messung nach einem Wechsel der Breite trägt Reste von vorher mit (`docs/76 §1`).

**Erwartet je Lage:** `dokument: 0` und Gegenprobe `200/200`. Eine Zeile ohne
`200/200` ist ungültig und keine Messung.

**`schiebt` ist ein Hinweis und kein Urteil** (`docs/75 §5`): Jeder Eintrag wird
einzeln benannt, und `.stacks thead` ist gewollt.

Acht Lagen sind das (vier Seiten × zwei Themes). Bei 1440 px genügt eine
Stichprobe je Seite in hell — die Bilderrunde hat diese Breite bereits gemessen.

---

### Punkt 9 — Aufräumen, und das gehört zum Lauf

```bash
ssh cloudsrv24 'dig +noall +answer @ns1.ipv64.net cloudlab24.de CAA'
```

**Den `CAA`-Satz auf `cloudlab24.de` bei ipv64 entfernen** und die Ausgabe
danach noch einmal schicken — leer.

Solange er steht, scheitert jede echte Bestellung für diese Domain, und jeder
Fehlversuch zählt gegen die fünf je Stunde für **alle** Kunden dieses Servers.

Die drei angelegten Domains können stehenbleiben oder zurückgebaut werden; wer
sie zurückbaut, sieht dabei nach, ob der Server-Block und das Verzeichnis
mitgehen.

---

## 5. Was zurückkommen soll

Für jeden Punkt **die Ausgabe wörtlich**, nicht zusammengefasst — dazu die
Bilder aus den Punkten 1, 2, 3, 4(d), 5(c) und 8.

Zwei Bilder sind die eiligen und kommen nicht wieder: die Marke „ungeprüft" und
das Kästchen über der Knopfreihe, beide aus Punkt 1.

---

## 6. Was dieser Lauf ausdrücklich **nicht** prüft

- **Den Zustand „Nameserver uneinig".** Er braucht zwei autoritative Server mit
  verschiedenen Antworten, und den stellt man nicht nebenbei her. Er bleibt
  ungemessen und benannt (`docs/75 §2.4`).
- **Den Zustand „kein Sollzustand bekannt".** Derselbe Grund.
- **Die Grenze des Durchgangs** — 25 Domains und 240 Sekunden lassen sich auf
  einem Server mit einer Handvoll Domains nicht auslösen. Sie ist im Gestell
  gemessen; hier wird sie nur nicht widerlegt.
- **Den roten Fehlerzähler der Konsole.** Er stand in der Bilderrunde bei 390 px
  auf 1 und zeigte auf nichts Aufschlagbares. Dafür braucht es den Filter
  „Errors only" mit Neuladen — eine eigene Runde, kein Kriterium.
- **Den Aufstieg zur CAA-Elternzone.** Er ist nicht gebaut und im Kopf von
  `Authority` begründet; siehe Punkt 6. Ein CAA an `c.example.de` deckt
  `a.b.c.example.de` nicht ab.
- **Das Schreiben in fremde Zonen.** P7 liest. `docs/72 §4` ist die Begründung.

---

## 7. Wann P7 abgenommen ist

**Wenn alle acht Punkte aus `docs/72 §3` gemessen und erfüllt sind** — gemessen
auf `cloudsrv24`, nicht geschätzt, und mit den Ausgaben im Protokoll.

Ein Punkt, der aus einem benannten Grund nicht fahrbar war, ist **nicht**
erfüllt. Er steht dann in `docs/78` als offen, und P7 ist so lange nicht
abgenommen.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
