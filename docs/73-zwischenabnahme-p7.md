# Zwischenabnahme P7 — der DNS-Abgleich auf `cloudsrv24`

Geschrieben am 22. August 2026, für `v0.7.0-rc.1`. Der Lauf gehört als
**Schritt 8** in `docs/72 §8` und steht **vor** der Bilderrunde und vor dem
Abnahmelauf.

---

## 1. Warum dieser Lauf eigenständig ist

**Weil an P7 bisher nichts auf einem Server gelaufen ist.** Die Schritte 1 bis 7
sind gebaut, geprüft und gemergt — geprüft aber ausschliesslich gegen Wächter,
und die laufen im Container. Nie angefasst haben einen echten Server:

| Was | Seit |
|---|---|
| Die Migration `domain_dns_checks` | Schritt 5 |
| Das Modell und sein Zeitstempel | Schritt 5 |
| Route, Controller und Policy des Knopfes | Schritt 5 |
| Der Bereich „DNS-Abgleich" an der Domain | Schritt 5 |
| `srvpanel dns-check` | Schritt 7 |
| `srvpanel-dns.timer` und `.service` | Schritt 7 |
| `dns.check` gegen **echte** fremde Nameserver | Schritt 2 |

**Der Plan hat diesen Lauf zuerst für überflüssig erklärt**, mit der Begründung,
nichts an dieser Stufe stehe auf einem Dienst, den der Container nur als
Wegwerf-Fassung kennt. Das stimmt für den `Resolver` und für sonst nichts.

> **Eine Begründung, die für einen Teil stimmt, ist keine für das Ganze.**

Dazu kommt: In diesem Container gibt es kein `vendor/`, also hat auch kein
Feature-Test die Liste oben angefasst. Sie ist doppelt ungemessen.

---

## 2. Was man braucht

- **`cloudsrv24` mit `v0.7.0-rc.1`** — Punkt 0 belegt es.
- **Eine echte Domain im Panel**, deren `A`-Satz auf diesen Server zeigt. Der
  Lauf legt sie nicht an.
- **Ein Terminal auf dem MacBook** für die `ssh`-Punkte und **einen Browser**
  für die Punkte 6 bis 9. Zwei Terminals sind nicht nötig — anders als in
  `docs/47` misst hier nichts eine halbe Sekunde.
- **Etwa 40 Minuten**, davon rund 20 Wartezeit: Punkt 10 wartet auf den Timer,
  und der feuert zur Viertelstunde plus bis zu zwei Minuten Streuung.
- Die Bereitschaft, **jede Ausgabe zu schicken — auch die, die richtig
  aussieht.** Der teuerste Fehler von P4 meldete genau die Zahl, die das
  Kriterium verlangte, und tat das Falsche.

**Zwei Werte stehen unten nicht ausgeschrieben, weil sie es nicht können:** der
Name der Domain und die Adresse des Panels. Überall dort steht `<domain>`
beziehungsweise `<panel>`; alles andere ist wörtlich einzugeben.

**Die `ssh`-Zeile** steht als `ssh cloudsrv24` — wenn der Zugang unter einem
anderen Namen oder Benutzer läuft, ist das die einzige Stelle, die anzupassen
ist. Alle Serverbefehle laufen als `root`.

---

## 3. Der Lauf

### Punkt 0 — Welche Fassung läuft

```bash
ssh cloudsrv24 'srvpanel --version'
```

**Erwartet:** `0.7.0-rc.1`

**Wenn dort etwas anderes steht, ist der Rest wertlos.** `srvpanel --version`
fragt seit `v0.5.1` das Verzeichnis der Installation und nicht mehr eine
Umgebungsvariable, die niemand setzt — die Zahl ist also die laufende Fassung
und keine Behauptung darüber.

### Punkt 1 — Die Migration ist eingespielt

```bash
ssh cloudsrv24 'srvpanel migrate:status | grep domain_dns_checks'
```

**Erwartet:** eine Zeile mit `Ran`.

Das `postinstall`-Skript fährt `srvpanel migrate --force` bei jedem Paketwechsel;
dieser Punkt belegt, dass es diesmal auch durchgelaufen ist.

### Punkt 2 — Timer und Dienst sind da und angestellt

```bash
ssh cloudsrv24 'systemctl is-enabled srvpanel-dns.timer; systemctl list-timers srvpanel-dns.timer --all --no-pager'
```

**Erwartet:** `enabled`, und in der Tabelle eine Zeile mit einem **`NEXT`, das
einen Termin nennt** — kein `-`.

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

Genau das ist am 19. August 2026 auf diesem Server passiert, mit
`srvpanel-cron.timer`: `is-active` sagte `active`, `NEXT` stand auf `-`, und der
letzte Lauf lag 22 Stunden zurück. `srvpanel-dns.timer` trägt deshalb
`OnCalendar` und nicht nur einen monotonen Sockel — dieser Punkt prüft, dass das
auf dem Server auch ankommt.

### Punkt 3 — Die Frist der Unit, gemessen statt vermutet

```bash
ssh cloudsrv24 'systemctl show srvpanel-dns.service -p TimeoutStartUSec; systemctl show srvpanel-cron.service -p TimeoutStartUSec'
```

**Erwartet für `srvpanel-dns.service`:** `TimeoutStartUSec=10min`.

**Und die zweite Zeile ist der eigentliche Messwert.** `srvpanel-cron.service`
ist ein `Type=oneshot` **ohne** eigenes `TimeoutStartSec` — was dort steht, ist
die Vorgabe, und die kennt dieser Container nicht. Sie hat Folgen:

- Steht dort **`infinity`**, ist die Überlegung in `srvpanel-dns.service`
  bestätigt: Ein hängender oneshot läuft ewig, hält die Unit in `activating`, und
  systemd startet sie beim nächsten Termin nicht noch einmal. Ein einziger
  hängender Lauf nähme dann alle folgenden mit — lautlos.
- Steht dort **`1min 30s`**, gilt das Gegenteil, und dann haben `srvpanel-cron`,
  `srvpanel-usage` und `srvpanel-tls` eine Frist, die niemand gewählt hat. Für
  `srvpanel tls` wäre das ernst: Eine Erneuerung über ACME kann länger dauern,
  und ein Abbruch mitten in einer Bestellung ist teuer.

> **Eine Frist, die man nicht aufschreibt, ist die Frist einer Vorgabe, die man
> nicht gemessen hat.**

Beide Ausgaben gehören ins Protokoll, egal welche es ist. Der zweite Fall ist
ein Befund **ausserhalb** von P7 und wird hier nur festgehalten, nicht behoben.

### Punkt 4 — Der Durchgang von Hand, zum ersten Mal

```bash
ssh cloudsrv24 'srvpanel dns-check'
```

**Erwartet:** eine Zeile der Form

```
N Domain/Domains fällig, M geprüft, K ohne Antwort, L wartet/warten noch (X,X s).
```

Beim **ersten** Lauf ist `N` die Zahl aller Domains, die nicht im Rückbau sind —
keine hat je einen Befund. `M` sollte gleich `N` sein, solange weniger als 25
Domains da sind und der Lauf unter vier Minuten bleibt; sonst steht der Rest
unter `L`, und darunter erscheint die Zeile mit der Grenze.

**`K` ist die Zahl, auf die es ankommt.** „Ohne Antwort" heisst: Für diese Domain
hat kein einziger Nameserver geantwortet. Ein oder zwei bei fremden Aliassen sind
normal; steht `K` gleich `M`, sagt das nichts über die Domains und alles über
diesen Server — dann kommt der Auflöser nicht hinaus, und Punkt 6 wird leer sein.

### Punkt 5 — Die Frische greift

Unmittelbar danach, ohne Pause:

```bash
ssh cloudsrv24 'srvpanel dns-check'
```

**Erwartet:** `0 Domain/Domains fällig, 0 geprüft, 0 ohne Antwort, 0 wartet/warten noch (0,0 s).`

Das belegt `Sweep::FRESH_MINUTES` — ein Befund gilt eine Stunde als frisch, und
ohne diese Untergrenze fragte ein Server mit drei Domains dieselben fremden
Nameserver viermal in der Stunde, für nichts.

**Steht hier dieselbe Zahl wie in Punkt 4, ist die Frische wirkungslos** und der
Timer belästigt ab jetzt alle fünfzehn Minuten fremde Server.

### Punkt 6 — Der Bereich an einer echten Domain

Im Browser: `https://<panel>/` anmelden → **Domains** → `<domain>`. Zum Bereich
**„DNS-Abgleich"** scrollen; er steht **unter** dem Zertifikat.

**Erwartet:**

- Eine Tabelle mit je einer Zeile pro Name und Satztyp (`A`, gegebenenfalls
  `AAAA`).
- In der Spalte für den Zustand eine Marke mit einem dieser Wörter:
  **Zeigt hierher** · **Zeigt woandershin** · **Fehlt** · **Nameserver uneinig**
  · **Nicht erreichbar**.
- Darunter: **„Zuletzt geprüft: …"** und **„· gefragt wurden …"** mit den
  Adressen der Nameserver, die wirklich gefragt wurden.

**Für eine Domain, deren `A`-Satz hierher zeigt, muss dort „Zeigt hierher"
stehen.** Steht „Zeigt woandershin", vergleiche den angezeigten gefundenen Wert
mit `curl -s ifconfig.me` auf dem Server — dann weicht ab, was das Panel für
seine eigene Adresse hält (siehe Punkt 11).

**Kein `AAAA` in der Tabelle ist richtig**, wenn der Server keine öffentliche
IPv6 führt. Ein fehlendes `AAAA` wird ausdrücklich **nicht** als Fehler gemeldet.

**Der Zeitpunkt steht in der Anzeigezeitzone** (`docs/40`), nicht in UTC —
vergleiche ihn mit der Uhr des MacBooks, nicht mit `date -u` auf dem Server.

### Punkt 7 — Der Knopf

Auf derselben Seite: **„Jetzt prüfen"**.

**Erwartet:**

- Der Knopf liest während des Laufs **„Wird geprüft …"** und ist gesperrt.
- Danach eine Erfolgsmeldung: **„Der DNS-Abgleich ist gelaufen."**
- **„Zuletzt geprüft"** trägt jetzt die aktuelle Zeit.

Das ist der Weg, den Punkt 4 nicht geht: Route, Policy, Controller. Er misst
**ohne** Rücksicht auf die Frische — deshalb funktioniert er unmittelbar nach
Punkt 5.

### Punkt 8 — Kein Vorgang, kein Protokolleintrag

Auf derselben Seite weiter unten zu **„Vorgänge"**.

**Erwartet:** **kein neuer Eintrag** durch Punkt 7.

`dns.check` ist als nicht verändernd gebaut: Nachsehen ändert nichts, und eine
Seite, die bei jedem Nachsehen eine Zeile schreibt, öffnet man nicht gern.
Dieselbe Aufteilung wie bei `dns.credential.list` und `acme.certificate.info`.

**Steht dort ein Eintrag, ist das ein Befund** — dann ist der Abgleich als
Vorgang gelaufen und füllt ab jetzt bei jedem Timer-Lauf das Protokoll.

### Punkt 9 — Der Befund überlebt den Seitenwechsel

Die Seite verlassen (auf **Domains** und wieder zurück auf `<domain>`).

**Erwartet:** Die Tabelle steht unverändert da, mit demselben Zeitpunkt.

Das belegt, dass der Befund in `domain_dns_checks` liegt und nicht in einer
Sitzung — und dass genau **eine** Zeile je Domain geführt wird.

### Punkt 10 — Der Timer feuert von selbst

**Der Punkt, der das ganze Merkmal trägt**, und der einzige mit Wartezeit.

Zuerst den nächsten Termin ablesen:

```bash
ssh cloudsrv24 'systemctl list-timers srvpanel-dns.timer --no-pager'
```

Dann warten, bis dieser Termin **plus zwei Minuten** vorbei ist (die Streuung aus
`RandomizedDelaySec`). Danach:

```bash
ssh cloudsrv24 'journalctl -u srvpanel-dns.service --since "-25 min" --no-pager'
```

**Erwartet:** mindestens ein Lauf mit der Berichtszeile aus Punkt 4 und
`Deactivated successfully` beziehungsweise `Finished`.

**Die Zahl `fällig` wird dabei klein sein** — die Befunde aus den Punkten 4 und 7
sind noch keine Stunde alt. Das ist richtig so und belegt die Frische ein zweites
Mal, diesmal aus dem Timer heraus.

**Kommt gar nichts**, ist der Timer zwar `enabled`, feuert aber nicht — dann
gehört die Ausgabe von Punkt 2 noch einmal daneben, und der Befund ist dieselbe
Sorte wie am 19. August.

### Punkt 11 — Die Adressen, und was an ihnen fehlt

Auf der Domainseite steht unter der Tabelle, gegen welche Adressen verglichen
wurde. Zum Vergleich:

```bash
ssh cloudsrv24 'ip -brief address show scope global'
```

**Erwartet:** Die abgeleiteten Adressen des Panels sind die öffentlichen
Adressen dieses Servers.

**Und hier steht ein Befund, der vor dem Lauf schon feststeht.** `docs/72 §2.1a`
hat entschieden: „Abgeleitet, mit Übersteuerung." Gebaut ist davon nur die
Ableitung. `Settings::saveDnsAddresses()` existiert und **wird von nichts
aufgerufen** — es gibt kein Formular, keine Route und keinen Schalter am
Kommando. Die Übersteuerung kann damit nie einen Wert haben.

> **Eine Einstellung, die sich lesen, aber nirgends setzen lässt, ist keine
> Einstellung — sie ist ein Vorsatz.**

Zwei Folgen, beide nur hier zu sehen und nicht im Container:

1. Läuft dieser Server hinter NAT, einer Floating-IP oder einem Lastverteiler,
   meldet der Abgleich **jede** Domain als „Zeigt woandershin", und es gibt in
   dieser Fassung keinen Weg, das zu berichtigen.
2. Der Warnhinweis auf der Seite, der eingetragene gegen abgeleitete Adressen
   hält, kann nie erscheinen. Er ist toter Code.

**Dieser Punkt wird nicht behoben, sondern gemessen:** Wenn die abgeleiteten
Adressen stimmen, ist die Lücke folgenlos und geht in einen eigenen Handgriff.
Wenn sie nicht stimmen, ist P7 auf diesem Server unbrauchbar, und die
Übersteuerung wird zur Vorbedingung des Abnahmelaufs.

---

## 4. Was zurückkommen soll

Für jeden Punkt: **die Ausgabe wörtlich**, nicht zusammengefasst. Dazu für die
Punkte 6 bis 9 je ein Bildschirmfoto des Bereichs — noch nicht die Bilderrunde,
nur der Beleg, dass die Anzeige existiert und Zahlen trägt.

Ausdrücklich auch dann, wenn alles richtig aussieht:

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 5. Was dieser Lauf ausdrücklich **nicht** prüft

- **Die acht Abnahmekriterien aus `docs/72 §3`.** Die brauchen eine Domain, deren
  `A` absichtlich woandershin zeigt, eine ohne `A`, ein fremdes `CAA` und eine
  Messung gegen den Zwischenspeicher eines Auflösers. Das ist der Abnahmelauf,
  nicht dieser.
- **Die Bilderrunde.** 390 px, beide Themes, `tests/bilder-messen.js` — Schritt 9.
- **Den Fall „Nameserver uneinig".** Der braucht zwei autoritative Server mit
  verschiedenen Antworten, und den stellt man nicht nebenbei her.
- **Die Grenze des Durchgangs.** 25 Domains und 240 Sekunden lassen sich auf
  einem Server mit einer Handvoll Domains nicht auslösen. Sie ist im Gestell
  gemessen; hier wird sie nur nicht widerlegt.
