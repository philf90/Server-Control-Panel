# Der Nachlauf zu A10 — die Bestandsdiagnose auf `cloudsrv24`

**Ausgeschrieben am 2. September 2026, vor dem Fahren.** Er ist Schritt 8 aus
`docs/98 §8` und misst die acht Punkte aus `docs/98 §7` auf einem echten Server.
Ohne ihn ist A10 gebaut und nicht abgenommen.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 0 · Was vor dem Lauf gelesen wird

**Die Kriterien aus `docs/98 §7` sind am Quelltext nachgesehen worden, bevor
dieser Lauf entstand** — so, wie `docs/95 §0` es verlangt. Drei sind dabei
umgefallen, und alle drei hätten sonst den Prüfling für etwas gemeldet, das er
zu Recht tut.

### Punkt 7 ist berichtigt — zwei Prüfungen brauchen den Agenten nicht

Er lautete: *„Bei angehaltenem Agenten steht **jede** Prüfung auf `unknown` und
keine auf `ok`."* Das kann der Prüfling nicht erfüllen. **Zwei der sechs
Prüfungen fragen den Agenten gar nicht:** `SystemUsers` und `Orphans` nehmen
`Host` und die Datenbank und messen weiter, wenn `srvpanel-agentd` steht.

Dazu eine dritte Stelle: `tls.wire` bekommt dann **keine Zeile**. Die Leitung
wird nur gefragt, wenn die Datei in Ordnung ist; ohne den Agenten gibt es keine
Datei, über die zu urteilen wäre, und der Ausfallweg schreibt `unreachable` für
`tls.file` und sonst nichts.

**So lautet der Punkt jetzt:**

> Bei angehaltenem Agenten steht **jede Prüfung, die ihn braucht**, auf
> `unknown` — die elf Schlüssel `web.config`, `php.config`, `ssh.config`,
> `web.file`, `php.file`, `block.integrity`, `quota.state`, `apt.key`,
> `unit.state`, `unit.schedule` und `tls.file`. Keine davon steht auf `ok`.
> `system.user` und `orphan.row` messen weiter, `tls.wire` bekommt keine Zeile.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

### Punkt 3 nennt den falschen Schlüssel

Er verlangt, dass ein gestoppter Timer auftaucht — und sagt nicht, unter
welchem Schlüssel. `Units::judge()` fragt in dieser Reihenfolge: gibt es die
Unit, hat ein Timer einen Termin, wie ist der Zustand. Ein gestoppter Timer hat
keinen Termin und fällt damit in den **zweiten** Zweig:

> `check=unit.schedule`, `reason=no_next` — **nicht** `unit.state`.

Das ist keine Spitzfindigkeit. Wer beim Messen nach `unit.state` sucht, findet
nichts und schreibt einen Ausfall auf, den es nicht gibt.

### Punkt 2 braucht einen Sollzustand, sonst ist er nicht herstellbar

Ein entfernter Block ergibt `block_missing` **nur, wenn im Bestand etwas steht,
das dort stehen müsste** (`ManagedBlocks::judge()`: kein Block und nichts zu tun
ist der Normalzustand). Auf einem Server ohne einen einzigen SFTP-Schlüssel
erzeugt das Entfernen des Bereichs **keinen** Befund — und das ist richtig.

**Vor Punkt 2 wird deshalb belegt, dass der Bereich nicht leer ist:**

```
sed -n '/# BEGIN srvpanel/,/# END srvpanel/p' /etc/ssh/sshd_config
```

Kommen dort ausser den beiden Marken keine Zeilen, wird zuerst ein
SFTP-Zugang mit einem öffentlichen Schlüssel angelegt. Sonst misst Punkt 2 den
Normalzustand und liest sich wie ein Ausfall.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

### Die Reihenfolge ist bindend

**Punkt 1 verlangt einen heilen Server**, und die Punkte 2 bis 6 machen ihn
kaputt. Er steht deshalb vorn, und jeder Prüfkörper wird in dem Punkt wieder
abgeräumt, in dem er entsteht.

**Punkt 8 braucht denselben Schaden über zwei Läufe** und einen geänderten
Wortlaut dazwischen. Er hängt am Schaden aus Punkt 5 und steht danach; wer ihn
vorzieht, hat keinen Befund, den er über zwei Nächte tragen könnte.

**Punkt 7 hält den Agenten an** und steht am Ende: Danach ist jede Prüfung, die
ihn braucht, `unknown`, und kein anderer Punkt wäre mehr messbar.

### Was auf dem Server vorher stimmen muss

```
srvpanel version
srvpanel diagnose --help
systemctl is-active srvpanel-worker srvpanel-agentd
systemctl list-timers srvpanel-diagnose.timer --all
```

Kennt der Wrapper `diagnose` nicht, trägt diese Maschine A10 noch nicht, und
der Lauf ist nicht fahrbar.

**Der Timer wird für den Lauf nicht gebraucht** — gemessen wird mit dem Kommando
von Hand. Er steht hier trotzdem, weil ein Timer ohne nächsten Termin
abgeschaltet ist und aussieht wie eingeschaltet (`docs/64`).

### Wie ein Punkt gemessen wird

Jeder Punkt hat dieselbe Form: **Zustand herstellen, belegen, dass er da ist,
fahren, lesen, zurückbauen, noch einmal fahren.** Der zweite Lauf ist kein
Aufräumen, sondern der Beleg, dass die Anzeige den Zustand *misst* und nicht
*behält*.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

**Jede Vorbereitung von Hand wird belegt, bevor gemessen wird.** `docs/96` hat
einen ganzen Punkt daran verloren, dass ein `sed -i` Erfolg meldete, ohne sein
Muster gefunden zu haben.

> **Ein `sed`, das nichts findet, meldet Erfolg — und der Rückfall, der daran
> hängt, läuft nie.**

Gelesen wird an **zwei** Stellen, und beide gehören ins Protokoll: die Ausgabe
des Kommandos und die Seite `/diagnose`. Sie beantworten verschiedene Fragen —
das Kommando, ob der Lauf durchkam; die Seite, ob der Befund ankommt.

Die Zustände heissen auf beiden **In Ordnung**, **Auffällig**, **Kaputt** und
**Nicht gemessen**; das Kommando zählt sie am Ende seiner Ausgabe.

*(Bis zum 3. September 2026 hiess der zweite „Sieht jemand hin". Wer eine
Aufnahme aus einem früheren Lauf liest, findet dort noch das alte Wort.)*

---

## 1 · Punkt 1 — ein heiler Server meldet nichts

```
date +%T; time srvpanel diagnose; echo "rc=$?"
```

**Erwartet:** `rc=0`, eine Zeile „6 Prüfung(en) gefahren, <Zeitpunkt>." und
darunter entweder „Keine Befunde." oder ausschliesslich Zeilen mit **Sieht
jemand hin** und **Nicht gemessen** — keine mit **Kaputt**.

**Die Dauer wird mitgeschrieben.** Sie ist kein Kriterium, beantwortet aber M19
aus `docs/98 §11` und sagt, ob die Frist von 1800 Sekunden richtig gerechnet
war.

**Dann die Seite** `/diagnose` als Betreiber: Sie nennt den Zeitpunkt des Laufs,
auch wenn die Liste leer ist. Das ist die Hälfte des Punktes, die beim Bauen
gefehlt hat.

> **Dieselbe leere Liste bedeutet „nichts gefunden" und „nie gemessen", und nur
> eine der beiden Bedeutungen ist eine Entwarnung.**

**Die Gegenprobe zur Zeitangabe:** ein zweiter Lauf, eine Minute später. Der
Zeitpunkt auf der Seite muss sich verschoben haben — sonst steht dort eine
Vorlage und keine Messung.

**Was ein Ausfall hier bedeutet.** Ein **Kaputt** auf einem Server, den der
Betreiber für heil hält, ist kein Ausfall des Punktes, sondern ein Befund über
den Server. Er gehört mit Ort und Grund ins Protokoll; danach entscheidet der
Betreiber, ob er ihn behebt oder als bekannt stehenlässt.

---

## 2 · Punkt 2 — ein entfernter verwalteter Block

**Der Punkt, um dessentwillen es Schritt 2 gibt** (M15). Vorher steht der Beleg
aus §0, dass der Bereich nicht leer ist.

```
cp /etc/ssh/sshd_config /root/sshd_config.abnahme
sed -i '/# BEGIN srvpanel/,/# END srvpanel/d' /etc/ssh/sshd_config
grep -c 'srvpanel' /etc/ssh/sshd_config
```

**Belegt vor der Messung:** die Zählung ergibt `0`.

```
srvpanel diagnose
```

**Erwartet:** `check=block.integrity`, `subject=/etc/ssh/sshd_config`,
`reason=block_missing`. Der Wortlaut nennt die Zeilen, die im Bestand stehen und
in der Datei fehlen — und den sieht nur der Betreiber.

**Zurückgelegt und noch einmal:**

```
cp /root/sshd_config.abnahme /etc/ssh/sshd_config
sshd -t; echo "rc=$?"
systemctl reload ssh
srvpanel diagnose
```

**Erwartet:** die Zeile ist fort. `sshd -t` **vor** dem Neuladen, und nicht aus
Höflichkeit:

> **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für den
> Fall, dass ihn genau dieser Vorgang beendet hat.**

---

## 3 · Punkt 3 — ein gestoppter Timer

```
systemctl stop srvpanel-cron.timer
systemctl show srvpanel-cron.timer -p ActiveState -p NextElapseUSecRealtime -p NextElapseUSecMonotonic
srvpanel diagnose
```

**Belegt:** `ActiveState=inactive`, und die beiden Termine stehen auf leer
beziehungsweise `infinity`. Genau daran entscheidet `Units::hasNext()`.

**Erwartet:** eine Zeile mit `check=unit.schedule`, `reason=no_next`,
`subject=srvpanel-cron.timer` — **sofort** und nicht erst nach dem verpassten
Termin. Das ist der Unterschied zwischen einer Diagnose und einem Nachsehen im
Protokoll.

**Und die zweite Hälfte des Punktes, die ebenso zählt:**
`srvpanel-cron.service` darf **nicht** als Befund erscheinen. Ein Dienst, den
ein Timer startet, steht zwischen zwei Terminen still, und die Zuordnung kommt
aus `Triggers` am Timer — sie überlebt, dass der Timer gestoppt ist
(`UnitVerdictTest`). Erscheint der Dienst hier, ist das ein Befund.

```
systemctl start srvpanel-cron.timer
srvpanel diagnose
```

**Erwartet:** die Zeile ist fort.

---

## 4 · Punkt 4 — `BEGIN` ohne `END`

Der Zustand, den `managed()` bis Schritt 2 nicht gesehen hat (M15), und der
Anlass für M22.

```
tail -n 20 /etc/ssh/sshd_config
cp /etc/ssh/sshd_config /root/sshd_config.abnahme
sed -i '/# END srvpanel/d' /etc/ssh/sshd_config
grep -c '# BEGIN srvpanel' /etc/ssh/sshd_config
grep -c '# END srvpanel' /etc/ssh/sshd_config
```

**Belegt:** `1` für `BEGIN`, `0` für `END`. Ohne beide Zahlen misst der Punkt
nichts.

**Das `tail` steht davor, weil die Nachbarschaft die Erwartung entscheidet.**
Ohne `END` liest `ManagedBlock::managed()` bis zum Dateiende. Steht der Bereich
am Ende der Datei, kommt genau ein Befund; steht danach noch etwas, kommt ein
zweiter mit `foreign_line` dazu, und der ist dann richtig.

```
srvpanel diagnose
```

**Erwartet:** `check=block.integrity`, `subject=/etc/ssh/sshd_config`,
`reason=begin_without_end`. Der Wortlaut nennt die Zeilennummern von `BEGIN`
und `END`, letztere als `–`.

**Zurück wie in Punkt 2**, mit `sshd -t` vor dem Neuladen.

---

## 5 · Punkt 5 — das fehlende Semikolon (darf nicht ausfallen)

**Der Punkt, der B von A trennt.** Fällt er aus, ist A10 ein Aufruf von
`nginx -t` mit einer Seite davor.

Der Prüfkörper ist **M3 Form 1**: `server_name` verliert sein Semikolon, und
weil die Anweisung beliebig viele Namen nimmt, wird die nächste Zeile zu einem
davon. `nginx -t` gibt `rc=0` **ohne ein Byte Ausgabe**, und der Block hat
lautlos eine Anweisung verloren.

**Der Ort ist `/etc/nginx/srvpanel.d/<domain>.conf`** und nicht
`sites-available`. Hier stand bis zum 3. September das zweite, und der Lauf ist
daran aufgelaufen: In `sites-available` liegt auf `cloudsrv24` genau eine Datei,
`default`. `Site::CONF_DIR` ist das Verzeichnis, das `web.site.apply` schreibt,
und `SystemDiagnose::webFiles()` liest von dort.

> **Ein Pfad in einer Vorschrift, den niemand gegen den Quelltext hält, ist eine
> Vermutung mit Fussnote.**

```
ls -1 /etc/nginx/srvpanel.d/
D=<domain>
cp /etc/nginx/srvpanel.d/$D.conf /root/$D.conf.abnahme
grep -n 'server_name' /etc/nginx/srvpanel.d/$D.conf
sed -i '0,/^\(\s*server_name [^;]*\);/s//\1/' /etc/nginx/srvpanel.d/$D.conf
grep -n -A3 'server_name' /etc/nginx/srvpanel.d/$D.conf
nginx -t; echo "rc=$?"
```

**Getroffen wird das erste `server_name`, also das des Blocks auf Port 80** —
`0,/…/` ersetzt genau einmal. Verschluckt wird damit das `access_log`, das in
der Vorlage darauf folgt.

**Belegt wird beides, und beides ist tragend:** dass die Zeile ihr Semikolon
verloren hat, **und** dass `nginx -t` trotzdem `rc=0` gibt.

> **Ein Prüfer, der die Datei für gültig hält, ist die ganze Voraussetzung
> dieses Punktes.**

Gibt `nginx -t` hier `rc=1`, ist es eine der beiden lauten M3-Formen — dann
wird **die Stelle gewechselt und nicht das Kriterium**. Welche Form auffällt,
entscheidet die Nachbarschaft und nicht die Regel.

```
srvpanel diagnose
```

**Erwartet:** `check=web.file`, `subject=<domain>`, `reason=directive_lost`. Der
Wortlaut nennt die verschluckte Anweisung — und den sieht nur der Betreiber.

**Und dass er sie überhaupt nennen kann, ist eine Entscheidung im Leser.** Eine
Domain mit TLS hat **zwei** Blöcke, und beide führen `access_log`. Ein Prüfer,
der nur fragte „steht die Anweisung irgendwo in der Datei?", bliebe hier still —
der Block auf 443 trägt sie ja weiter. {@see Statements::lostInNginx()} fragt
deshalb zweierlei: ob eine zugesagte Anweisung als Kopf fehlt, **und** ob sie als
**Argument** einer anderen auftaucht. Das zweite trifft hier zu und ist der
Grund, dass Punkt 5 auf einer TLS-Domain überhaupt messbar ist.

> **Eine Zusage, die zweimal eingelöst wird, ist gegen den Verlust einer der
> beiden blind — es sei denn, gefragt wird, wo die verlorene gelandet ist.** Der
Administrator sieht dieselbe Zeile mit Ort, Zustand und Satz, aber ohne den
Wortlaut; auch das gehört ins Protokoll, weil es Frage 5 aus `docs/98 §9` ist.

**Die Datei bleibt für Punkt 8 kaputt.** Zurückgebaut wird dort.

---

## 6 · Punkt 6 — das ablaufende Zertifikat

Drei Zustände, und keiner lässt sich durch Warten herstellen.

**a) `expiring` unter dreissig Tagen** — Hinweis. **b) `expired`** — Kaputt.
**c) `name_mismatch`**, wenn der `subjectAltName` die Domain nicht deckt.

Hergestellt wird das mit selbst ausgestellten Zertifikaten in einem eigenen
Verzeichnis und **nicht** durch Verstellen der Systemuhr:

> **Ein Prüfkörper, der die Uhr des Servers verstellt, misst jeden anderen
> Vorgang dieses Servers mit.**

```
srvpanel diagnose
```

**Erwartet:** `check=tls.file` mit den drei Gründen, jeweils mit dem Namen des
Zertifikats als Ort, und die Schwere in dieser Ordnung: `expiring` als **Sieht
jemand hin**, die anderen beiden als **Kaputt**.

**Fällt dieser Punkt als „nicht herstellbar" aus**, wird er mit seinem Grund
protokolliert. Er ist keiner der beiden, die nicht ausfallen dürfen.

---

## 7 · Punkt 8 — derselbe Schaden über zwei Läufe (darf nicht ausfallen)

**Der zweite Punkt, der nicht ausfallen darf** (M9). Er baut auf dem Schaden aus
Punkt 5 auf, der noch steht.

Der Befund von Punkt 5 wird zuerst festgehalten — auf der Seite als „Steht
seit", und daneben aus der Datenbank:

```
srvpanel tinker --execute="dump(\App\Models\Finding::withoutGlobalScopes()->where('check','web.file')->get(['id','subject','reason','first_seen_at','measured_at','detail'])->toArray());"
```

**`withoutGlobalScopes()` ist nicht optional** — `srvpanel tinker` läuft ohne
angemeldetes Konto, und die Mandantenklammer gibt sonst wortlos null Zeilen
zurück.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

**Dann wird der Wortlaut geändert, ohne die Kennung zu ändern.** In derselben
Datei verliert ein **zweites** `server_name` sein Semikolon; damit nennt der
Wortlaut zwei verschluckte Anweisungen statt einer, während `check`, `subject`
und `reason` dieselben bleiben.

```
sed -i '0,/^\(\s*server_name [^;]*\);/s//\1/' /etc/nginx/sites-available/$D
nginx -t; echo "rc=$?"
srvpanel diagnose
```

**Erwartet:** **eine** Zeile und nicht zwei. `first_seen_at` steht auf dem
Zeitpunkt des Laufs aus Punkt 5, `measured_at` auf dem dieses Laufs, und der
Wortlaut ist der neue.

> **Die Kennung eines Befundes ist `check` + `subject` + `reason` — der Wortlaut
> gehört nicht dazu.** Stünde er darin, hätte jede geänderte Meldung einen neuen
> Befund erzeugt, und „steht seit" wäre eine Zahl ohne Bedeutung.

**Der Rückbau:**

```
cp /root/$D.abnahme /etc/nginx/sites-available/$D
nginx -t; echo "rc=$?"
systemctl reload nginx
srvpanel diagnose
```

**Erwartet:** die Zeile ist fort.

---

## 8 · Punkt 7 — der angehaltene Agent (berichtigt, siehe §0)

**Steht am Ende**, weil danach nichts anderes mehr messbar ist.

```
systemctl stop srvpanel-agentd
systemctl is-active srvpanel-agentd
srvpanel diagnose; echo "rc=$?"
```

**Erwartet:** `rc=0`. Ein unerreichbarer Agent ist kein gescheiterter Lauf,
sondern ein Lauf mit lauter `unknown`; in der Zusammenfassung steht **Nicht
gemessen** mit einer Zahl und **Kaputt** mit keiner.

> **Ein Rückgabewert, der einen gefundenen Schaden als Fehlschlag meldet, macht
> aus dem Boten den Schuldigen.**

Auf der Seite stehen die **elf** Schlüssel aus §0 auf **Nicht gemessen**, keiner
fehlt, und keiner steht auf **In Ordnung**.

**Die Gegenprobe ist die zweite Hälfte:** `system.user` und `orphan.row`
brauchen den Agenten nicht und messen weiter. Zeigen *sie* `unknown`, ist nicht
der Agent das Problem, sondern die Zuordnung.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

```
systemctl start srvpanel-agentd
srvpanel diagnose
```

**Erwartet:** kein **Nicht gemessen** mehr.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Die Laufzeit an einer stillen Adresse** (M19, `docs/98 §11`). Sie gehört
  hierher und ist kein Abnahmepunkt; mitgeschrieben wird sie an der Dauer des
  Laufs aus Punkt 1.
- **Die Quota-Zustände „an, ohne Grenze" und „an, mit Grenze"** (M12). Der
  Kernel des Containers konnte sie nicht erzwingen; ob dieser Server sie kennt,
  sagt `quotaon -p /` und nicht dieser Lauf.
- **Die Frist von 1800 Sekunden.** Sie ist gerechnet und nicht gemessen. Schöpft
  der Lauf aus Punkt 1 sie aus, ist das ein Befund und kein Ausfall.
- **Den Nachtlauf als Timer.** Dass `srvpanel-diagnose.timer` einen nächsten
  Termin hat, ist A2s Kriterium und nicht A10s; gelesen wird er in §0.
- **`php.file` und `php.config`.** Sie gehen denselben Weg wie `web.file` und
  sind mit Punkt 5 belegt; ein eigener Prüfkörper dafür würde denselben Leser
  ein zweites Mal messen.

---

## 10 · Wann er durch ist

Alle acht Punkte gemessen, **Punkt 5 und Punkt 8 erfüllt**. Fällt einer der
übrigen als „nicht herstellbar" aus, wird das mit seinem Grund protokolliert und
hält die Abnahme nicht auf; fällt einer als **nicht erfüllt** aus, ist er ein
Befund, und A10 ist nicht abgenommen.

Das Protokoll bekommt die nächste freie Nummer und enthält je Punkt den
**gemessenen** Wert und nicht das Urteil „erfüllt" allein.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
