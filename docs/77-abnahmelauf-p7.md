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
- **Drei Terminals auf dem MacBook** und **einen Browser** mit den
  Entwicklerwerkzeugen. Drei, weil Punkt 4 gleichzeitig mitschneidet, klickt und
  gegenprüft.
- **`tcpdump` auf `cloudsrv24` und `sudo`, das es zulässt.** Punkt 4 steht und
  fällt damit; ohne bleibt Kriterium 4 offen. Vorher nachsehen:
  ```bash
  ssh cloudsrv24 'command -v tcpdump && sudo -n true && echo "beides da"'
  ```
- **Zugriff auf die Zone `cloudlab24.de` bei ipv64** — der Lauf setzt und ändert
  dort Einträge. Ohne diesen Zugriff sind die Punkte 1 bis 4 und 7 nicht fahrbar.
- **Etwa 90 Minuten**, davon rund 30 Wartezeit.
- **Vor dem Lauf:** die drei Einträge aus §3. Sie brauchen keinen Vorlauf über
  die Ausbreitungszeit hinaus — bei einer TTL von 10 Sekunden sind das
  Sekunden.
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

## 3. Die Vorbereitung — drei DNS-Einträge, sonst nichts

**Sie muss vor dem Lauf geschehen, weil DNS Zeit braucht.** Wer sie im Lauf
macht, misst die Verzögerung des Anbieters und nicht das Panel.

In der Zone `cloudlab24.de` bei ipv64 einrichten:

| Name | Satz | Wert | Wofür |
|---|---|---|---|
| `hier.cloudlab24.de` | `A` | die Adresse von `cloudsrv24` (gemessen: `159.195.56.255`) | Punkt 1 |
| `fremd.cloudlab24.de` | `A` | `192.0.2.1` | Punkt 2, 4 |
| `ohne.cloudlab24.de` | `TXT` | `p7-abnahme` — **und kein `A`** | Punkt 3 |

**`192.0.2.1` und nicht irgendeine Adresse.** Das ist TEST-NET-1 aus RFC 5737 —
sie gehört niemandem und kann mit keinem echten Rechner verwechselt werden. Wer
hier die Adresse eines fremden Hosters einträgt, hat im Protokoll eine Zahl
stehen, die aussieht wie ein Befund.

**Warum `ohne` einen `TXT`-Satz bekommt, obwohl der Zustand „fehlt" heisst.**
Die Zone `cloudlab24.de` führt einen **Platzhalter** — gemessen am 24. August:
`ohne.cloudlab24.de` antwortete mit der Serveradresse, ohne dass dort je etwas
angelegt worden wäre. Ein Name, den es nicht gibt, existiert in dieser Zone
nicht; der Platzhalter beantwortet ihn.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch
> hergestellt, dass man nichts tut.**

Ein Platzhalter greift nach RFC 4592 nur für Namen, die es in der Zone **gar
nicht** gibt. Ein beliebiger anderer Satz am Namen lässt ihn existieren — und
dann kommt die `A`-Frage leer zurück, statt vom Platzhalter beantwortet zu
werden. Genau das ist „fehlt": `Comparison::state()` entscheidet ihn an
`values === []` bei `answered > 0`, also an einer **leeren Antwort** und nicht
an NXDOMAIN.

**Der Platzhalter selbst bleibt stehen.** Ihn für einen Abnahmelauf abzuräumen
hiesse, den Server für den Prüfkörper zu verändern.

**Und die Gegenprobe gehört dazu:**

```bash
dig +short @ns1.ipv64.net zufall-a7f3.cloudlab24.de A   # der Platzhalter: antwortet
dig +short @ns1.ipv64.net ohne.cloudlab24.de A          # leer
dig +short @ns1.ipv64.net ohne.cloudlab24.de TXT        # "p7-abnahme"
```

Ohne die erste Zeile ist die zweite von einer Zone ohne Platzhalter nicht zu
unterscheiden — und dann sagt sie nichts darüber, dass der Kniff nötig war.

**Die TTL ist gleichgültig, und das war sie nicht immer.** Der erste Wurf dieser
Vorschrift verlangte 3600 auf `fremd`, weil Punkt 4 einen haltbaren
Auflöser-Zwischenspeicher brauchte. Bei ipv64 steht die TTL fest auf 10 Sekunden
und lässt sich nicht ändern — gemeldet vom Betreiber am 24. August. Punkt 4 ist
deshalb neu entworfen und misst jetzt direkt statt über einen Umweg; er braucht
keine TTL mehr. Siehe dort.

**Und der `CAA`-Satz gehört ausdrücklich *nicht* in die Vorbereitung** — er wird
in Punkt 6 gesetzt und in Punkt 9 entfernt. Der Grund steht in §3.2.

### 3.1 Was ausdrücklich **nicht** in die Vorbereitung gehört

**Ein Prüfkörper mit Haltbarkeit.** Der erste Wurf stellte das Füllen des
Auflöser-Zwischenspeichers hierher, „am Vortag" — bei einer TTL von einer Stunde.

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**

Der zweite Wurf zog ihn nach Punkt 4 (a). Der dritte hat ihn **abgeschafft**: Er
war nie nötig. Siehe Punkt 4.

### 3.2 Warum das `CAA` erst in Punkt 6 gesetzt wird

**Weil eine echte Zertifizierungsstelle zur Elternzone aufsteigt.** Ein
`CAA 0 issue "digicert.com"` an `cloudlab24.de` verbietet Let's Encrypt die
Ausstellung für **jeden** Namen darunter — also auch für `hier`, `fremd` und
`ohne`, die dieser Lauf anlegt. Stünde der Satz von Anfang an, scheiterte jede
Bestellung dieses Laufs an ihm, und die Punkte 1 bis 3 mässen einen Server, der
nebenbei kaputtgemacht wurde.

> **Ein Prüfkörper, der neben dem Gegenstand auch alles andere trifft, misst
> nicht mehr den Gegenstand.**

Dass das Panel selbst **nicht** aufsteigt (`Authority`, siehe Punkt 6), ändert
daran nichts: Die Bestellung macht Let's Encrypt und nicht das Panel.

Gesetzt wird der Satz deshalb in Punkt 6, gemessen, und in Punkt 9 wieder
entfernt. **Bei einer TTL von 10 Sekunden ist er innerhalb einer halben Minute
sichtbar und danach genauso schnell wieder fort** — was hier ein Nachteil war,
ist dort einer der wenigen Vorteile.

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

**Zuerst nachsehen, wann der Timer feuert:**

```bash
systemctl list-timers srvpanel-dns.timer --no-pager
```

**Steht `NEXT` weniger als zwei Minuten voraus, abwarten.** Der Timer misst alle
15 Minuten; feuert er unmittelbar nach dem Anlegen, ist die Marke „ungeprüft"
fort, bevor jemand sie fotografiert hat. Nach dem Feuern steht fast eine volle
Viertelstunde zur Verfügung.

> **Ein Zustand, der von selbst endet, wird nicht dadurch messbar, dass man
> sich beeilt — sondern dadurch, dass man weiss, wann er endet.**

Dann im Panel `hier.cloudlab24.de` als Domain anlegen. **Sofort danach**, bevor
der erste Abgleich gelaufen ist, die Domainseite öffnen.

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

   **Dieser Zustand ist der kürzere von beiden.** `hier.cloudlab24.de` zeigt
   hierher und die Zone trägt kein CAA — die Bestellung läuft also durch,
   möglicherweise in unter einer Minute. Wer erst A in Ruhe fotografiert und
   dann B sucht, findet B unter Umständen nicht mehr.
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

**Und nicht „Nicht erreichbar".** Die beiden liegen hier dicht beieinander:
`Missing` heisst „geantwortet, kein Wert", `Unreachable` heisst „niemand hat
geantwortet". Steht dort `Nicht erreichbar`, ist nicht der Zustand falsch,
sondern die Messung — dann hat der Nameserver geschwiegen, und §3 ist zu
wiederholen.

**Und dann beide Seiten nebeneinander ansehen** — `fremd` und `ohne`. Kriterium 3
verlangt die *Unterscheidung*, nicht zwei Zustände. Sehen sie gleich aus, ist der
Punkt nicht erfüllt, auch wenn beide Wörter richtig dastehen.

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss.**

---

### Punkt 4 — Die autoritativen Server, nicht der Auflöser (Kriterium 4)

**Der Punkt, der etwas beweist** — und der einzige, den die Zwischenabnahme
ausdrücklich nicht gemessen hat (`docs/74 §7`).

**Gemessen wird, wen das Panel fragt, und nicht, was es dabei erfährt.** Die
ersten beiden Fassungen dieser Vorschrift wollten es über einen
Auflöser-Zwischenspeicher belegen: alten Wert cachen, Eintrag ändern, und wenn
das Panel den neuen zeigt, hat es nicht den Auflöser gefragt. Das ist ein
Umweg, und er ist an ipv64 gescheitert — dort steht die TTL fest auf 10
Sekunden. Ein Zwischenspeicher, der zehn Sekunden hält, ist kein Prüfkörper,
sondern ein Wettrennen.

> **Eine Messung, die um ihren Gegenstand herumführt, hängt an Bedingungen, die
> mit ihm nichts zu tun haben.**

Der Gegenstand ist ein UDP-Paket an eine Adresse. Also wird das Paket
angesehen.

**(a) Die autoritativen Adressen holen** — und den Systemauflöser dazu:

```bash
ssh cloudsrv24 'dig +short ns1.ipv64.net ns2.ipv64.net; \
  echo "--- Systemaufloeser ---"; grep ^nameserver /etc/resolv.conf'
```

Die ersten Adressen sind die, an die Pakete gehen **müssen**; die letzte ist
die, an die **keines** gehen darf. Beide notieren — die Zeile aus (d) ist ohne
sie nicht zu lesen.

**(b) Mitschneiden.** In einem eigenen Terminal:

```bash
ssh cloudsrv24 'sudo timeout 60 tcpdump -n -i any "udp port 53" 2>/dev/null'
```

**(c) Innerhalb dieser 60 Sekunden zweierlei tun**, und die Reihenfolge ist
gleichgültig:

1. Im Panel auf `fremd.cloudlab24.de` **„Jetzt prüfen"** klicken.
2. In einem dritten Terminal die **Gegenprobe** fahren:
   ```bash
   ssh cloudsrv24 'dig +short fremd.cloudlab24.de A'
   ```
   Das ist eine Frage **über den Systemauflöser**, und sie muss im Mitschnitt
   als Paket an dessen Adresse auftauchen.

**(d) Den Mitschnitt lesen.** Erwartet:

| | |
|---|---|
| Pakete an die NS-Adressen aus (a) | **mehrere** — das Panel |
| Pakete an den Systemauflöser | **genau die der Gegenprobe** und sonst keines |

**Ohne die Gegenprobe belegt der Mitschnitt nichts.** „Kein Paket an den
Auflöser" sieht genauso aus, ob das Panel ihn meidet oder `tcpdump` an der
falschen Schnittstelle lauscht.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Und die zweite Hälfte, die das Panel gegen sich selbst prüft.** Der Mitschnitt
sagt, wen es fragt — nicht, ob es die Antwort danach frisch verwendet. Deshalb
noch einmal, jetzt ohne `tcpdump`:

1. Bei ipv64 `fremd.cloudlab24.de` von `192.0.2.1` auf **`198.51.100.1`**
   (TEST-NET-2) ändern.
2. Eine halbe Minute warten — bei TTL 10 ist das reichlich.
3. Im Panel „Jetzt prüfen".

**Erwartet: `198.51.100.1`.** Das belegt **nicht** Kriterium 4 — bei dieser TTL
zeigte auch ein Auflöser den neuen Wert. Es belegt, dass das Panel keinen
**eigenen** Zwischenspeicher führt, der älter ist als seine Anzeige. Das gehört
so ins Protokoll und nicht als zweiter Beleg für dasselbe.

> **Zwei Messungen, die man zusammenzählt, obwohl sie Verschiedenes zeigen,
> ergeben eine Zahl, die nichts bedeutet.**

**Wenn `tcpdump` nicht verfügbar ist oder `sudo` es nicht zulässt**, ist
Kriterium 4 auf diesem Weg nicht fahrbar. Dann steht es als **offen** im
Protokoll — nicht als erfüllt, und nicht als „durch die Anzeige belegt". Dass
das Panel „gefragt wurden 167.235.231.182, 159.69.110.93" anzeigt, ist seine
eigene Behauptung über sich selbst.

> **Eine Anzeige, die sagt, was sie getan hat, ist kein Beleg dafür, dass sie es
> getan hat.**

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

**(a) Erst die Gegenprobe, solange es den Satz noch nicht gibt.** Auf
`cloudlab24.de` „Jetzt prüfen". Erwartet: **kein** CAA-Hinweis. Ohne diesen
Schritt ist der Hinweis in (c) von einem, der immer dasteht, nicht zu
unterscheiden.

**(b) Den Satz setzen** — bei ipv64 in der Zone `cloudlab24.de`:

| Feld | Wert |
|---|---|
| Präfix | *leer* |
| Typ | `CAA` |
| Ziel | `0 issue "digicert.com"` |

Also Flags `0`, Tag `issue`, Wert `digicert.com`. **`digicert.com` ist eine
echte, aber fremde Stelle** — Let's Encrypt steht nicht darin, und genau das
soll gemeldet werden.

Massgeblich ist die Zone und nicht das Formular:

```bash
ssh cloudsrv24 'dig +noall +answer @ns1.ipv64.net cloudlab24.de CAA'
```

Erst wenn dort `0 issue "digicert.com"` steht, weiter. Bei TTL 10 dauert das
Sekunden.

**(c)** Auf `cloudlab24.de` „Jetzt prüfen".

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

**Ab jetzt eilt es.** Solange der Satz steht, verweigert Let's Encrypt die
Ausstellung für **jeden** Namen unter `cloudlab24.de` — auch für die drei
Domains dieses Laufs. Punkt 9 räumt ihn ab, und das ist kein Aufräumen, sondern
der Abschluss dieses Punktes.

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

**Den `CAA`-Satz aus Punkt 6 bei ipv64 entfernen** und die Ausgabe danach noch
einmal schicken — leer.

Solange er steht, verweigert Let's Encrypt die Ausstellung für jeden Namen unter
`cloudlab24.de`, und jeder Fehlversuch zählt gegen die fünf je Stunde für
**alle** Kunden dieses Servers.

**Und die Gegenprobe zum Abräumen:** auf `cloudlab24.de` noch einmal „Jetzt
prüfen". Der Hinweis muss fort sein. Bleibt er stehen, hängt die Anzeige an
einem gemerkten Befund und nicht an der Zone.

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
- **Das Verhalten bei langer TTL.** Bei ipv64 steht sie fest auf 10 Sekunden.
  Ob das Panel eine Änderung auch dann sofort zeigt, wenn ein Auflöser sie eine
  Stunde lang verschwiege, ist hier nicht zu messen — Punkt 4 belegt statt
  dessen direkt, **wen** es fragt, und das ist die stärkere Aussage.
- **Das Schreiben in fremde Zonen.** P7 liest. `docs/72 §4` ist die Begründung.

---

## 7. Wann P7 abgenommen ist

**Wenn alle acht Punkte aus `docs/72 §3` gemessen und erfüllt sind** — gemessen
auf `cloudsrv24`, nicht geschätzt, und mit den Ausgaben im Protokoll.

Ein Punkt, der aus einem benannten Grund nicht fahrbar war, ist **nicht**
erfüllt. Er steht dann in `docs/78` als offen, und P7 ist so lange nicht
abgenommen.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
