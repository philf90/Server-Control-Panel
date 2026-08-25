# Abnahmelauf A9 — Rollen, Konten und Zugang auf `cloudsrv24`

Geschrieben am 25. August 2026. Der Lauf ist **Schritt 9** aus `docs/82 §7` und
prüft die **sieben Punkte des Abnahmekriteriums** aus `docs/82 §6` — dazu die
beiden Merkmale aus Schritt 7, die dort noch nicht stehen, und drei Reste aus A1
und A5, die ohnehin einen echten Server brauchen.

**Dieses Dokument ist die Vorschrift, nicht das Protokoll.** Das Protokoll
entsteht **während** des Laufs als eigenes Dokument und nicht danach — solange
keine Messung darin steht, ist ein Protokoll eine Gliederung.

*Seine Nummer steht hier bewusst nicht.* `docs/81` hat einmal eine genannt, die
einem ganz anderen Dokument gehörte; `DocLinkTest` konnte das nicht sehen, weil
er prüft, ob es die Datei gibt, und nicht, ob sie das Gemeinte ist.

> **Eine Nummer, die man vergibt, bevor es die Datei gibt, ist eine Zusage an
> einen Namen und nicht an einen Inhalt.**

---

## 1. Was dieser Lauf beweist, und was schon feststeht

**Fünf der sieben Punkte sind Anzeige- und Schrankenfragen**, und die CI misst
sie längst: `RoleGateTest` weist den Administrator auf jeder Geheimnisseite ab,
`LastOperatorTest` hält den letzten Betreiber, `AdminNetworkTest` die
Netzbeschränkung. Alle drei sind grün.

**Zwei Punkte misst kein Test, und deshalb gibt es diesen Lauf.**

**Punkt 8 ist der teuerste:** `srvpanel admin` als Rückweg für ein ausgesperrtes
Konto. Er steht seit `docs/82 §3` als Falle 3 in der Vorschrift, seine Begründung
steht im Kopf von `CreateAdmin` — und **gegangen ist ihn niemand.**

> **Ein Rückweg, den niemand gegangen ist, ist eine Zusage und kein Weg.**

**Punkt 9b ist der zweite:** Eine offene Sitzung endet, wenn ihre Adresse nicht
mehr zugelassen ist. Der Feature-Test misst das gegen `REMOTE_ADDR` aus einem
Testaufruf; ob es hinter dem echten nginx mit seinen weitergereichten Adressen
genauso ist, hat niemand gesehen.

> **Eine Adresse, die im Test aus einer Variablen kommt, kommt im Betrieb aus
> einer Kopfzeile — und dazwischen steht ein Reverse-Proxy.**

**Und Punkt 4 ist der, den man am leichtesten falsch abhakt.** Kriterium 3
verlangt zweierlei: einen 403 **und** eine Antwort ohne verbotene Felder. Das
zweite ist am Bild nicht zu sehen.

> **Ein Kriterium, das zwei Dinge verlangt, wird an dem gemessen, das man
> sieht — und das andere gilt als miterledigt.**

**Was schon steht und hier nicht wiederholt wird:** die CI auf allen vier
Zielplattformen gegen den Stand dieses Zweiges, 1538 framework-freie Prüfungen
im Gestell, 106 Eingriffe des Bruchskripts (alle beissen), und die Bildrunden zu
Kontenliste, Kontenformular und Zugangsseite bei 390 und 1440 px in beiden
Themes.

---

## 2. Was man braucht

- **`cloudsrv24` mit dem Stand, gegen den abgenommen wird.** Punkt 0 belegt ihn.
- **Ein Terminal mit SSH auf `cloudsrv24`** und `sudo`, das `apt` darf.
- **Zwei Browser oder ein Browser mit einem privaten Fenster.** Punkt 2 bis 5
  laufen als Administrator, während die Betreibersitzung offen bleibt — sonst
  meldet man sich acht Mal um.
- **Ein zweites Netz.** Für Punkt 9 braucht es eine Adresse, die *nicht* im
  Bürones liegt: ein Telefon mit mobilen Daten genügt, oder ein Hotspot, in den
  sich das MacBook einbucht. Ohne das zweite Netz bleiben 9b und 9c offen.
- **Etwa 90 Minuten**, davon rund 10 Wartezeit (Punkt 11 fährt `apt-get update`
  zweimal).
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.**

  > **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt
  > wurde.**

### 2.1 Der Rückweg, bevor er gebraucht wird

Punkt 9 stellt eine Netzbeschränkung her. Sie ist so gebaut, dass sie ihren
eigenen Urheber nicht aussperren **kann** — das ist gerade Kriterium 5b. Sollte
sie es trotzdem tun, ist dies der Weg zurück, und er gehört **vor** dem Lauf
einmal gelesen:

```bash
ssh cloudsrv24 'srvpanel access'          # was gilt gerade?
ssh cloudsrv24 'srvpanel access --clear'  # alles abräumen
```

Danach gilt wieder „von überall", und im Protokoll steht ein `settings.access`
mit `quelle: Kommandozeile` und **ohne handelndes Konto** — auf der
Kommandozeile ist keines angemeldet.

> **Ein Rückweg, den man erst sucht, wenn man ihn braucht, ist keiner.**

**Dieses Kommando gab es beim ersten Ausschreiben dieses Laufs nicht.** Der Weg
zurück war ein `tinker`-Einzeiler, der in keiner Hilfe stand. Aufgefallen ist
das nicht beim Bauen der Netzbeschränkung, sondern beim Aufschreiben dieses
Abschnitts.

> **Wer eine Anleitung schreibt, geht die Schritte im Kopf — und merkt, wo
> keiner ist.**

**`--clear` fragt keinen Aussperrschutz**, und das ist Absicht: Wer das Kommando
aufruft, sitzt auf dem Server. Ein Rückweg, der dieselbe Bedingung prüft wie der
Hinweg, führt zurück an denselben Punkt.

### 2.2 Was dieser Lauf am Server verändert

| Was | Punkt | Zurückgenommen in |
|---|---|---|
| Ein zweites Adminkonto (Administrator) | 1 | Punkt 14 |
| Ein drittes Adminkonto (Betreiber, zum Aussperren) | 8 | Punkt 14 |
| Eine Netzbeschränkung | 9 | Punkt 9d |
| Eine tote apt-Quelle | 11 | Punkt 11c |

**Kein Kundendatensatz wird angefasst**, keine Domain angelegt, kein Zertifikat
bestellt. Der Lauf kostet damit nichts aus dem ACME-Kontingent — anders als
`docs/77`.

---

## 3. Der Lauf

### Punkt 0 — Welche Fassung läuft

```bash
ssh cloudsrv24 'readlink -f /opt/srvpanel/current; /opt/srvpanel/current/artisan --version'
```

Und im Browser die Fassung neben „SrvPanel" im Menü. **Beide notieren.**

**Dazu die Migration**, denn A9 bringt eine:

```bash
ssh cloudsrv24 'srvpanel tinker --execute="
  echo App\\Models\\Account::query()->whereNotNull(\"role\")->count() . \" mit Rolle, \" .
       App\\Models\\Account::query()->where(\"type\",\"admin\")->whereNull(\"role\")->count() . \" Adminkonten ohne\";"'
```

**Erwartet:** mindestens ein Konto mit Rolle, und **null** Adminkonten ohne.

> **Ein Adminkonto ohne Rolle meldet sich an und darf nichts.** Die Migration
> setzt jedes bestehende auf `operator`; ein Konto ohne Rolle hier hiesse, dass
> sie nicht gelaufen ist — und dann ist der Betreiber ausgesperrt, sobald er
> eine Einstellungsseite öffnet.

---

### Punkt 1 — Ein zweites Adminkonto entsteht ohne SSH (Kriterium 1)

Als Betreiber: **Server → Konten → „Konto anlegen"**.

Name `Zweite Verwaltung`, eine Adresse, die es noch nicht gibt, Rolle
**Administrator** (steht vorgewählt).

**Auf „Passwort erzeugen" klicken.** Das Passwort erscheint im Klartext im Feld.
**Notieren — es kommt nicht wieder.**

**Erwartet, und das sind vier Dinge:**

1. Die Rolle steht auf **Administrator**, ohne dass jemand sie gewählt hat.
   *Die sichere Richtung ist zugleich die häufige; wäre der Betreiber
   vorgewählt, entstünde die weitere Vollmacht durch ein Feld, das niemand
   angesehen hat.*
2. Das Passwort erfüllt die Richtlinie — die Prüfliste steht auf lauter Haken.
3. Nach dem Anlegen steht das Konto in der Liste: Rolle **Administrator**,
   Zustand **aktiv**, zweiter Faktor **noch nicht**, letzte Anmeldung
   **noch nie**.
4. **Das Passwort ist nirgends mehr abrufbar.** Die Kontenseite zeigt es nicht,
   und ein erneutes Öffnen des Formulars auch nicht.

**Und im Protokoll** (`/audit`, Filter `account.created`): ein Eintrag mit dem
Namen, der Adresse **und der Rolle**.

> **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
> beantwortet die Frage, die niemand stellt.**

---

### Punkt 2 — Der Administrator arbeitet (Kriterium 2)

Im **zweiten Browser** mit dem neuen Konto anmelden.

**Erwartet:** Die Einrichtung des zweiten Faktors kommt zuerst — `docs/20 §6.4`
macht ihn für Adminkonten verpflichtend, und `RequireTwoFactor` lässt vorher
nichts durch. Einrichten und weiter.

Danach: **Kunden, Pläne, Abonnements, Domains, Datenbanken** öffnen. Jede Seite
lädt, jede Liste trägt Zeilen.

**Das ist die Hälfte, die still bricht.** Wer `isAdmin()` beim Bauen zu „ist
Betreiber" umdeutet, nimmt dem Administrator genau die Arbeit, für die es ihn
gibt — und ein Lauf, der nur die Ablehnungen prüft, bliebe dabei grün.

---

### Punkt 3 — Acht Seiten geben 403 (Kriterium 3, erste Hälfte)

**Als Administrator** die acht Adressen von Hand eintippen:

```
/settings/php
/settings/database
/settings/mail
/settings/tls
/settings/dns
/logs
/accounts
/settings/access
```

**Erwartet:** acht Mal **403**.

**Das Kriterium nennt sechs, und das ist ein Mangel der Vorschrift und nicht des
Prüflings:** `docs/82 §6` wurde geschrieben, bevor Schritt 3 die Kontenseite und
Schritt 7 die Zugangsseite gebaut hat. Beide gehören dem Betreiber und stehen
hier mit.

> **Ein Kriterium, das vor dem Bauen geschrieben wurde, kennt nicht, was beim
> Bauen entstanden ist.**

---

### Punkt 4 — Nichts Verbotenes in der Antwort (Kriterium 3, zweite Hälfte)

**Gemessen an der Antwort, nicht am Bild.** Als Administrator eine Seite öffnen,
die er sehen **darf** — `/subscriptions` —, dann **Seitenquelltext anzeigen** und
im `<div id="app" data-page="…">` nachsehen.

Praktischer über die Entwicklerwerkzeuge, Konsole:

```js
JSON.parse(document.getElementById('app').dataset.page).props.abilities
```

**Erwartet:** `{"manage-settings": true, "operate-server": false}`.

Und dann die Gegenprobe **in derselben Konsole**, mit der Betreibersitzung im
anderen Browser: dort steht `operate-server: true`.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Dazu die Suche nach dem, was nicht dastehen darf.** In derselben Konsole:

```js
const p = document.getElementById('app').dataset.page
;['password', 'secret', 'api_key', 'credential', 'private'].filter(w => p.includes(w))
```

**Erwartet: eine leere Liste.** Findet sich ein Wort, gehört die Fundstelle
angesehen und **nicht sofort als Befund gemeldet** — `passwordPolicy` steht auf
jeder Seite und ist die Richtlinie, kein Passwort.

> **Ein Wort, das nach einem Geheimnis klingt, ist noch keines.**

---

### Punkt 5 — Die Navigation kommt aus der Policy (Kriterium 4)

**Als Administrator** die Navigation ansehen.

**Erwartet:** Die Gruppe „Server" trägt **drei** Einträge — Vorgänge, Protokoll,
Allgemein. „Logs", „Konten", „Zugang", „PHP-Versionen", „Datenbankserver",
„Mailversand", „Zertifikat" und „DNS-Zugang" stehen **nicht** da. Die Gruppen
„Verwaltung" und „Konto" sind vollständig.

**Und die Überschrift „Server" steht trotzdem da**, weil noch Einträge darunter
sind. Eine Überschrift ohne Einträge behauptet, es gäbe dort etwas.

**Die Antwort kommt aus der Policy und nicht aus einem `v-if` auf die Rolle** —
das ist an der Fläche nicht zu sehen und steht deshalb in Punkt 4: Was das Menü
liest, ist `props.abilities`.

**Bild machen**, beide Rollen nebeneinander.

---

### Punkt 6 — Der letzte Betreiber lässt sich nicht wegnehmen (Kriterium 5)

**Als Betreiber**, an seinem eigenen Konto: **Konten → der eigene Name →
Bearbeiten**.

**Erwartet, bevor irgendetwas geklickt wird:**

1. In der Liste trägt die Zeile neben „Betreiber" die Marke **„letzter"**.
2. Unter der Liste steht der Satz, warum.
3. Im Formular ist im Auswahlfeld „Rolle" der Eintrag **Administrator gesperrt**,
   und im Feld „Zustand" der Eintrag **deaktiviert**.

**Dann die Gegenprobe von Hand.** Die Sperre der Auswahl ist Oberfläche; die
Schranke sitzt am Server:

```bash
ssh cloudsrv24 'srvpanel tinker --execute="
  \$a = App\\Models\\Account::query()->where(\"role\",\"operator\")->where(\"status\",\"active\")->count();
  echo \"aktive Betreiber: \$a\";"'
```

**Erwartet: 1.** Steht dort mehr als 1, ist Punkt 6 in dieser Form nicht fahrbar
— dann zuerst die übrigen Betreiber herabstufen oder sperren, und **dann**
messen.

Und der eigentliche Beleg, mit den Entwicklerwerkzeugen (Netzwerk-Reiter offen)
oder schlicht dadurch, dass man die Sperre im Auswahlfeld umgeht:

**Erwartet:** Ein `PATCH` mit `role=administrator` wird abgewiesen, und **oben
auf der Seite** steht der Satz aus `LastOperator::refusal()` — nicht am Feld.

> **Der Satz eines Fehlers steht oben in der Zusammenfassung, das Feld trägt
> nur `aria-invalid`.**

---

### Punkt 7 — Gesperrt heisst gesperrt, und das Protokoll behält den Namen (Kriterium 7)

**Als Betreiber** das Administratorkonto aus Punkt 1 auf **deaktiviert** setzen.

**Erwartet:**

1. Die Liste zeigt den Zustand **deaktiviert** in Rot.
2. Der zweite Browser fliegt beim nächsten Klick heraus — oder spätestens die
   nächste Anmeldung scheitert mit „Diese Zugangsdaten passen nicht zu einem
   aktiven Konto."
3. **Im Protokoll steht der Name weiter da.** Filter auf `account.created` und
   `account.updated`: Beide Einträge tragen den Namen des Kontos, nicht `null`.

**Das ist der Grund, warum gesperrt und nicht gelöscht wird** (`docs/82 §1.1`):
`audit_events.account_id` steht auf `nullOnDelete()`, ein gelöschtes Konto zöge
seine ganze Protokollhistorie auf `null`.

> **Ein Protokoll, aus dem sich der Handelnde nachträglich entfernen lässt, ist
> kein Protokoll — es ist eine Liste von Ereignissen.**

Danach wieder auf **aktiv** setzen; Punkt 10 braucht das Konto noch.

---

### Punkt 8 — `srvpanel admin` als Rückweg (Kriterium 6)

**Der teuerste Punkt dieses Laufs, und der einzige, der etwas herstellt, das
niemand haben will.**

**Nicht am eigenen Konto.** Ein drittes, wegwerfbares Adminkonto anlegen — über
die Oberfläche, Rolle **Betreiber**:

Name `Wegwerf`, Adresse `wegwerf@cloudlab24.de`, Passwort erzeugen und
**bewusst nicht notieren**.

**Damit ist es ausgesperrt**: Niemand kennt das Passwort, und ein zweiter Faktor
ist nicht eingerichtet.

**Jetzt der Rückweg:**

```bash
ssh cloudsrv24 'srvpanel admin wegwerf@cloudlab24.de --generate'
```

**Erwartet, und jedes davon einzeln:**

1. Die Ausgabe meldet `Passwort von wegwerf@cloudlab24.de gesetzt, Konto aktiv.`
   — **nicht** „Adminkonto angelegt", denn es gibt es schon.
2. Darunter steht ein Passwort, und darunter „Es wird nicht noch einmal
   angezeigt."
3. **Mit diesem Passwort gelingt die Anmeldung.**
4. Die Rolle ist danach unverändert **Betreiber** — das Kommando setzt bei einem
   bestehenden Konto Passwort und Zustand, nicht die Rolle.

**Und die zweite Hälfte des Rückwegs, die noch keiner gegangen ist:** dasselbe
Kommando für eine Adresse, die es **nicht** gibt.

```bash
ssh cloudsrv24 'srvpanel admin neu@cloudlab24.de --generate --name="Neu von Hand"'
```

**Erwartet:** `Adminkonto neu@cloudlab24.de angelegt.`, ein Passwort — und in
der Kontenliste steht es als **Betreiber**.

> **`CreateAdmin` legt Betreiber an und nicht Administratoren.** Es ist der
> Rückweg für jemanden, der sich ausgesperrt hat; ein Administrator käme nicht
> an die Ursache.

**Beide Konten kommen in Punkt 14 wieder weg.**

---

### Punkt 9 — Die Netzbeschränkung (Schritt 7, kein Kriterium in §6)

**Sie steht nicht in `docs/82 §6`,** weil das Kriterium vor Schritt 7
geschrieben wurde. Sie ist trotzdem das Merkmal mit der grössten Wirkung: Eine
falsche Zeile hier sperrt jeden Administrator aus.

#### 9a — Speichern, was einen selbst trägt

**Server → Zugang.** Oben steht die eigene Adresse.

Das Netz eintragen, in dem diese Adresse liegt — bei einer festen IP genügt die
Adresse selbst, sie wird zu `/32` beziehungsweise `/128` ergänzt.

**Erwartet:** gespeichert, und die Liste zeigt die kanonische Schreibweise.

**Dann zwei Eingaben, die abgewiesen werden müssen:**

| Eingabe | Erwartete Meldung |
|---|---|
| `192.0.2.7/24` | „hat gesetzte Wirtsbits" — mit beiden Lesarten im Satz |
| `0.0.0.0/0` | „deckt das ganze Internet ab und beschränkt damit nichts" |

#### 9b — Der Aussperrschutz (das eigentliche Kriterium von Schritt 7)

Die eigene Zeile **entfernen** und stattdessen nur `198.51.100.0/24` eintragen.
Speichern.

**Erwartet:** Abgewiesen, mit einem Satz, der **die eigene Adresse nennt** und
sagt, was hilft.

**Und die Liste ist unverändert.** Nachsehen:

```bash
ssh cloudsrv24 'srvpanel tinker --execute="
  print_r(app(App\\Support\\Settings\\Settings::class)->adminNetworks());"'
```

> **Eine Ablehnung, die den Zustand trotzdem ändert, ist keine.**

#### 9c — Eine offene Sitzung überlebt die Beschränkung nicht

**Das zweite Netz kommt jetzt.** Die Liste steht auf dem Bürones (aus 9a).

1. **Auf dem Telefon im Mobilnetz** die Panel-Adresse öffnen und sich als
   **Betreiber** anmelden.
   **Erwartet:** Die Anmeldung scheitert mit „Von dieser Adresse aus ist die
   Anmeldung für Verwaltungskonten nicht zugelassen."
   Und im Protokoll steht `auth.login.blocked` mit der Adresse.

2. **Das MacBook** — mit offener, angemeldeter Betreibersitzung — in den Hotspot
   des Telefons einbuchen und **irgendwo klicken**.
   **Erwartet:** Weiterleitung auf `/login` mit dem Hinweis, dass die Sitzung
   beendet wurde. Im Protokoll: `auth.session.blocked`.

   **Das ist der Punkt, den kein Test messen kann** — hinter dem echten nginx
   kommt die Adresse aus einer weitergereichten Kopfzeile und nicht aus einer
   Variablen des Testaufrufs.

3. Zurück ins Bürones. **Erwartet:** Anmeldung gelingt wieder.

4. **Und die Gegenprobe, die man vergisst:** Ein **Kundenkonto** im Mobilnetz
   anmelden. **Erwartet: es kommt herein.** Die Beschränkung gilt
   Verwaltungskonten; ein Kunde, der sich aus dem Urlaub nicht anmelden kann,
   ist ein Ausfall.

#### 9d — Zurücknehmen

Alle Zeilen entfernen, speichern.

**Erwartet:** „Die Anmeldung ist wieder von überall möglich." Und im Mobilnetz
kommt der Betreiber wieder herein.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

---

### Punkt 10 — Die Sitzungsübersicht (Schritt 7, zweite Hälfte)

**Konten → das Administratorkonto → Bearbeiten**, Bereich „Offene Sitzungen".

**Erwartet:**

1. Mindestens eine Zeile, mit Adresse, Gerät und letzter Aktivität.
2. Am eigenen Konto trägt die laufende Sitzung die Marke **„diese Sitzung"**.
3. „Beenden" an der Sitzung des Administrators: Der zweite Browser fliegt beim
   nächsten Klick heraus.
4. Im Protokoll: `account.session.ended` mit dem Namen des betroffenen Kontos.

**Und die Zuordnung**, die der Feature-Test misst und die hier nur bestätigt
wird: Die Liste eines Kontos zeigt **nur dessen** Sitzungen. Wer zwei Konten
angemeldet hat, sieht in jeder Liste eine.

---

### Punkt 11 — `apt-get update` mit einer toten Quelle (A1 Schritt 1, M5)

**Der Befund, mit dem P7b angefangen hat**, und der einzige Punkt hier, der
nicht zu A9 gehört. Er braucht einen echten apt und steht deshalb in diesem
Lauf.

#### 11a — Der Zustand vorher

```bash
ssh cloudsrv24 'sudo apt-get update >/tmp/stdout.txt 2>/tmp/stderr.txt; echo "rc=$?"; wc -c /tmp/stdout.txt /tmp/stderr.txt'
```

**Erwartet:** `rc=0` und `stderr` klein oder leer.

#### 11b — Eine Quelle, die es nicht gibt

```bash
ssh cloudsrv24 'echo "deb https://gibt.es.nicht.invalid/debian stable main" | sudo tee /etc/apt/sources.list.d/zzz-tot.list'
ssh cloudsrv24 'sudo apt-get update >/tmp/stdout2.txt 2>/tmp/stderr2.txt; echo "rc=$?"; echo "--- stderr ---"; cat /tmp/stderr2.txt; echo "--- stdout hat W-Zeilen? ---"; grep -c "^W:" /tmp/stdout2.txt || true'
```

**Erwartet, und das ist der ganze Befund:**

1. **`rc=0`** — obwohl eine Quelle unerreichbar war.
2. Auf **stderr** stehen `W:`-Zeilen, darunter eine mit `gibt.es.nicht.invalid`.
3. Auf **stdout** steht **keine** `W:`-Zeile.

> **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine Prüfung
> — er ist eine Zeile, die aussieht wie eine.**

**Und dann die Wirkung im Panel.** Als Betreiber **Server → PHP-Versionen**, eine
Version installieren, die noch nicht da ist.

**Erwartet:** Der Vorgang bricht ab und die Meldung **nennt die tote Quelle** —
nicht „Unable to locate package php8.x-fpm".

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

**Wenn die Meldung stattdessen das Paket nennt**, ist M5 nicht behoben, und das
ist ein Befund. Die Ausgabe des Vorgangs gehört dann vollständig ins Protokoll.

#### 11c — Zurücknehmen

```bash
ssh cloudsrv24 'sudo rm /etc/apt/sources.list.d/zzz-tot.list && sudo apt-get update >/dev/null 2>&1; echo "rc=$?"'
```

**Erwartet:** `rc=0`, und die PHP-Installation läuft danach durch. **Diese
Gegenprobe gehört dazu** — sonst belegt 11b nur, dass irgendetwas abbricht.

---

### Punkt 12 — Die Logs gegen ein echtes Journal (A5)

**Server → Logs.**

1. Eine **Datei** wählen (`Paketverwaltung`, `Panel`). **Erwartet:** echte
   Zeilen, und die Kopfzeile nennt Pfad, Grösse und Zeitpunkt.
2. Ein **Journal** wählen (`Journal: Weboberfläche`). **Erwartet:** echte Zeilen
   aus `journalctl`.
3. **Der Fall, für den A5 seinen eigenen Befund hat:** ein Journal einer Unit
   wählen, die nichts geschrieben hat.
   **Erwartet:** ein Hinweis, dass keine Einträge vorliegen — und **nicht** die
   Zeile `-- No entries --` als Inhalt des Protokolls.

   > **Ein Leser, der `-- No entries --` als Zeile nimmt, zeigt eine Meldung des
   > Werkzeugs als Inhalt des Protokolls.**

4. Den Filter benutzen, „Angezeigtes sichern" drücken. **Erwartet:** eine Datei
   mit genau dem Gezeigten.
5. **Und die Grössenangabe**, die die CI am 25. August gemeldet hat: Sie muss
   die deutsche Schreibweise tragen — `1.234,5 MB` und nicht `1234.5 MB`.

---

### Punkt 13 — Bilder bei 390 und 1440 px

Nach `tests/bilder-messen.js`, in **beiden Themes**, für:

- Kontenliste (mit mindestens drei Konten, darunter ein gesperrtes)
- Kontenformular (mit offenen Sitzungen)
- Zugangsseite (mit zwei Netzen und einer Fehlermeldung)
- Die Navigation als Administrator

**Erwartet:** `dokument: 0` in jeder Lage, und die Gegenprobe schlägt mit
`200/200` aus.

> **Eine Gegenprobe, deren Ausschlag von der Breite abhängt, ist bei der
> grösseren Breite keine.**

**Die Zahl ersetzt das Hinsehen nicht.** Beim Bau sind drei Befunde ohne Zahl
gefunden worden — zwei Formulare auf 0 px Abstand, die Zeilen der Netzliste auf
0 px, und eine Adresse, die in ihrem Feld abgeschnitten war.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

---

### Punkt 14 — Aufräumen, und das gehört zum Lauf

1. Die drei angelegten Konten **sperren** — nicht löschen; es gibt keinen Weg
   dafür, und das ist Absicht (`docs/82 §9`).
2. Nachsehen, dass die Netzbeschränkung leer ist (9d).
3. Nachsehen, dass `/etc/apt/sources.list.d/zzz-tot.list` fort ist (11c).
4. **Und die Zahl der aktiven Betreiber wieder auf ihren Ausgangswert bringen.**

```bash
ssh cloudsrv24 'srvpanel tinker --execute="
  foreach (App\\Models\\Account::query()->where(\"type\",\"admin\")->get() as \$a)
    echo \$a->id . \" \" . \$a->email . \" \" . (\$a->role?->value ?? \"ohne\") . \" \" . \$a->status->value . PHP_EOL;"'
```

**Erwartet:** genau der Stand von Punkt 0, plus drei gesperrte Konten.

---

## 4. Was zurückkommen soll

Für jeden Punkt: **die Ausgabe, nicht die Zusammenfassung.** Bei den Punkten mit
Bild zusätzlich das Bild.

Und ausdrücklich auch das, was richtig aussieht — die drei teuersten Fehler der
letzten Läufe (`docs/45`, `docs/48`, `docs/59`) haben Erfolg gemeldet.

---

## 5. Was dieser Lauf ausdrücklich **nicht** prüft

- **Die apt-Messrunde auf Debian 12/13 und Ubuntu 22.04.** Sie steht seit A1 als
  Schritt 0 offen und braucht drei weitere Maschinen. `cloudsrv24` ist Ubuntu
  24.04; Punkt 11 gilt für diese eine Plattform.
- **Die vier apt-Fälle, die im Container nicht vorkamen** — ein zurückgehaltenes
  Paket, ein Schlüssel mit Ablauf, eine Neuinstallation in `dist-upgrade`, ein
  `Requested-By`.
- **Teil 3 von M5** — `panel.update` liest nach dem Neustart seine eigene
  Fassung nach. Das hängt an A1 Schritt 6 und steht bis dahin als Ausnahme in
  `AptResultTest`.
- **Ob `systemd-run` den Neustart von `srvpanel-web` überlebt.** Steht seit A1
  Schritt 2 offen.
- **Eine dritte Rolle.** `docs/82 §4` entscheidet zwei und begründet es; der Weg
  für eine dritte steht dort.
- **Der Wechsel der Anmeldeadresse.** Bewusst nicht gebaut (`docs/82 §2.4`).

---

## 6. Wann A9 abgenommen ist

**Wenn die sieben Punkte aus `docs/82 §6` erfüllt sind** — hier die Punkte 1
bis 8 — **und die Punkte 9 und 10** dazu, die das Kriterium nicht kennt, weil es
vor Schritt 7 geschrieben wurde.

Die Punkte 11 bis 13 gehören zu A1 und A5 und entscheiden über A9 nicht; ihre
Befunde stehen im Protokoll und gehören dorthin, wo sie hingehören.

**Ein Punkt, der nicht fahrbar war, ist offen und nicht erfüllt.** Punkt 9c
braucht ein zweites Netz; ohne das bleibt er offen und wird als solcher
aufgeschrieben.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**

---

## 7. Was nach diesem Lauf zu bauen bleibt

- **Die Kriterien 3 und 4 in `docs/82 §6`** kennen sechs Geheimnisseiten; es
  sind acht. Berichtigt am 25. August, nachdem dieser Lauf es aufgedeckt hat.
- **Nichts weiter.** `srvpanel access` stand hier als offener Punkt und ist
  gebaut, bevor der Lauf gefahren wurde — §2.1 zeigt auf ein Kommando, das es
  gibt.
- **A3, A4 und A7** haben weiterhin keine Stufe (`docs/20 §9`).
