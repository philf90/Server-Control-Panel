# Der Abschlusslauf für P6

*Ausgeschrieben am 21. August 2026, **vor** dem Lauf. Das Protokoll dazu wird
`docs/69` und entsteht **während** des Laufs, nicht danach.*

## 0. Wofür es diesen Lauf gibt

**P6 ist gebaut, und das Abnahmekriterium ist gemessen.** Der Angriffsdurchgang
(`docs/61`/`62`) hat alle fünfzehn Punkte auf `cloudsrv24` belegt, die
Bilderrunde (`docs/63`/`64`) ihre zweite Runde vollständig gefahren, und die
drei Läufe danach (`docs/65`/`66`, `docs/67`) haben jede Behebung nachgesehen.

**Was fehlt, ist keine Arbeit am Panel, sondern vier benannte Reste und eine
Abnahmeerklärung.** Dieser Lauf schliesst die Reste; das Protokoll `docs/69`
wird die Erklärung.

**Die vier Reste, mit ihrer Fundstelle:**

| | Rest | steht in |
|---|---|---|
| 1 | Die Punkte 7 und 8 sind über den **Weg der Operation** gemessen, nicht durch die echte Route | `docs/62 §3` |
| 2 | Punkt 5 (durch einen Verweis hinaus schreiben) — dasselbe | `docs/62 §3` |
| 3 | Die Umbruchregel aus `docs/67` Befund 6 gilt auf **jeder** gestapelten Tabelle und ist auf **einer** Seite gemessen | `docs/64 §7.4` |
| 4 | Die halbe Hälfte von Punkt 13: dass `1001` die Kennung von `p1136` ist, sagt `id` und nicht der Vorgang | `docs/62 §3` |

**Warum die ersten beiden zählen, obwohl das Kriterium erfüllt ist.** `docs/61
§1` sagt es: Zwischen einem Pfad aus dem Formular und einer Datei stehen **zwei**
Wände — die Normalisierung in `Workspace::path()` und das `chroot`+`setuid` der
Sandbox. Der Prüfstand geht den Weg der Operation und kommt damit an der ersten
vorbei. Gemessen ist also, dass die *zweite* hält; ungemessen ist der Weg, den
ein Kunde tatsächlich nimmt.

> **Eine Wand, die man auf einem Weg prüft, den niemand geht, ist auf dem Weg,
> den alle gehen, ungeprüft.**

**Warum der dritte zählt.** `docs/64 §7.4` hat die Bedingung selbst
aufgeschrieben: Solange eine Behebung auf ihrer Seite bleibt, genügt es, die
berührten Stellen nachzumessen — wird sie zu einer Marke, ist die volle Runde
fällig. Befund 6 aus `docs/67` ist eine Regel an `.stacks td` im ≤720-px-Block
und erreicht damit **jede gestapelte Tabelle dieses Panels**. Belegt ist sie auf
`/audit`.

> **Eine Regel, die auf einer Seite gemessen und auf sechzehn wirksam ist, ist
> auf fünfzehn eine Vermutung.**

**Und ein Rest, der keiner mehr ist.** `docs/61 §0` und `docs/62 §3` führen
`/var/lib/srvpanel` mit `root:root 0755` statt `srvpanel:srvpanel 0750` als
offene Abweichung. Das war Befund 1 aus `docs/67` — `StateDirectory=` in einer
Unit, die als root läuft —, behoben und am Server belegt. **Punkt 5 dieses Laufs
führt ihn nach**, statt ihn stehen zu lassen.

> **Ein Rest, der erledigt ist und noch dasteht, kostet beim nächsten Lauf
> dieselbe Stunde wie ein echter.**

---

| | Rahmen | |
|---|---|---|
| Fassung | **`v0.6.0-rc.24`** | Punkt 0 prüft das |
| Server | `cloudsrv24` | |
| Abonnement | **140**, `p6-abnahme.invalid`, Systembenutzer **`p1139`** | |
| Wurzel | `/var/www/vhosts/p6-abnahme.invalid` | |
| Messmittel | `tests/bilder-messen.js`, Stand **2026-08-21** | für Punkt 3 |
| Angemeldet | als **Kunde** des Abonnements 140, nicht als Betreiber | Punkte 1, 2, 3 |

**Zwei Zeilen, die jeder Sitzung dieses Laufs vorausgehen** — sie setzen die
Namen, damit unten nichts abgetippt werden muss:

```bash
ABO=p6-abnahme.invalid
SYS=p1139
WURZEL=/var/www/vhosts/$ABO
echo "$WURZEL — $(id "$SYS")"
```

**Erwartet:** eine Zeile mit `uid=…($SYS)`. Kommt „no such user", stimmt der
Systembenutzer nicht, und **alles Weitere misst ein anderes Abonnement.**

---

## 1. Die Punkte

*(Punkt 0 ist die Rahmenprüfung und zählt nicht mit — erfüllt werden die
Punkte 1 bis 5.)*

| # | Punkt | schliesst |
|---|---|---|
| 0 | Die Fassung | — |
| 1 | Die beiden bösartigen Archive **durch die echte Route** | Rest 1 (Kriterium 7, 8) |
| 2 | Durch einen Verweis hinaus schreiben, **durch die echte Route** | Rest 2 (Kriterium 5) |
| 3 | Die Umbruchregel auf zwei weiteren Seiten | Rest 3 |
| 4 | `id` am Vorgang | Rest 4 (Kriterium 13) |
| 5 | Die Rechte an `/var/lib/srvpanel` | der Rest, der keiner mehr ist |

**Die Reihenfolge ist nicht beliebig.** Punkt 0 zuerst, sonst misst der Lauf
eine andere Fassung. Punkt 1 und 2 legen Wegwerf-Dinge an, die Punkt 3 im Bild
haben will — wer sie vorher aufräumt, misst eine leerere Seite als der Kunde.

---

## 2. Punkt 0 — welche Fassung läuft

```bash
srvpanel --version
dpkg -l srvpanel | tail -1
```

**Erwartet:** `0.6.0-rc.24` in beiden Zeilen.

> **Ein Lauf, der die Fassung nicht nennt, gehört zu keiner.**

---

## 3. Punkt 1 — die beiden Archive durch die echte Route

**Was hier anders ist als in `docs/62`.** Dort hat der Prüfstand
`files.extract` als Operation aufgerufen. Hier klickt der Kunde „Entpacken" im
Dateimanager, und die Anfrage geht durch `web`, durch `can:editFiles`, durch
`FileController::extract()`, durch `Workspace::path()` und erst dann in die
Sandbox.

### 3.1 Die zwei Verzeichnisse — durch das Panel, nicht von Hand

**Angemeldet als Kunde**, im Dateimanager des Abonnements 140:

1. Nach `/httpdocs` navigieren.
2. „Verzeichnis anlegen" → Name **`boes`**.
3. In `boes` hinein, „Verzeichnis anlegen" → Name **`ziel`**.

**Warum durch das Panel:** Ein Verzeichnis, das root anlegt, trägt womöglich
andere Rechte als eines, das der Kunde anlegt — und dann misst der Lauf die
Rechte und nicht die Grenze. (`docs/53` Befund 3 ist genau dieser Fehler.)

### 3.2 Die zwei Archive — auf dem Server, ausserhalb des Panels

Der Prüfkörper darf **nicht** durch das Panel entstehen; sonst prüft der
Prüfling sich selbst (`docs/62 §2`, `ArchiveDepthTest`).

```bash
mkdir -p /tmp/boes && cd /tmp/boes
echo 'getroffen' > nutzlast
echo 'brav' > beweis

# Kriterium 7 — relativer Ausbruch. ZWÖLF Schritte hinauf, nicht vier.
hinauf=$(printf '../%.0s' $(seq 12))
tar --transform "s|^nutzlast\$|${hinauf}tmp/getroffen-relativ|" \
    -cf raus-relativ.tar nutzlast beweis

# Kriterium 8 — absoluter Pfad.
tar -P --transform 's|^nutzlast$|/tmp/getroffen-absolut|' \
    -cf raus-absolut.tar nutzlast beweis

tar -tvf raus-relativ.tar; tar -tvf raus-absolut.tar
```

**Erwartet in den beiden letzten Zeilen:** einmal ein Eintrag, der mit
`../../../…` beginnt, einmal einer, der mit `/tmp/` beginnt — dazu je ein
`beweis`. **Steht dort kein `..` und kein führender Schrägstrich, hat `tar` sie
weggeputzt**, und der ganze Punkt misst nichts.

> **Ein Prüfkörper, der beim Bauen entschärft wurde, sieht aus wie eine
> gehaltene Grenze.**

**Zwölf Schritte und nicht vier** — die Berichtigung vom 18. August
(`docs/61 §6`): Vom Zielverzeichnis aus landen vier Schritte **innerhalb** der
Wurzel des Abonnements, und dann wäre ein Ausbruch keiner.

### 3.3 Die Archive ins Zielverzeichnis legen

```bash
install -o "$SYS" -g "$SYS" -m 0644 \
  /tmp/boes/raus-relativ.tar /tmp/boes/raus-absolut.tar \
  "$WURZEL/httpdocs/boes/ziel/"
ls -l "$WURZEL/httpdocs/boes/ziel/"
```

**Warum das erlaubt ist und den Lauf nicht schönt:** Geprüft wird das
**Entpacken**, nicht das Hochladen. Die Dateien tragen den Eigentümer des
Kunden und `0644` — an ihnen ist nichts privilegiert. Wer sie lieber über SFTP
einspielt, darf das; das Ergebnis ist dasselbe.

### 3.4 Die Messung — zweimal derselbe Klick

**Im Dateimanager**, angemeldet als Kunde, in `/httpdocs/boes/ziel`:

1. Bei `raus-relativ.tar` auf **„Entpacken"** — das Ziel ist das Verzeichnis,
   in dem man gerade steht.
2. Dasselbe bei `raus-absolut.tar`.

**Erwartet je Archiv** die Meldung:

    Das Archiv ist entpackt — 1 Einträge, 1 übergangen, weil sie aus dem
    Zielverzeichnis herausführen.

Danach auf dem Server:

```bash
ls -l /tmp/getroffen-relativ /tmp/getroffen-absolut 2>&1
find "$WURZEL" -name 'getroffen*' 2>/dev/null
ls -l "$WURZEL/httpdocs/boes/ziel/"
```

| | erwartet |
|---|---|
| `/tmp/getroffen-relativ` | **No such file or directory** |
| `/tmp/getroffen-absolut` | **No such file or directory** |
| `find` über die Wurzel | **keine Zeile** |
| im Zielverzeichnis | **`beweis`** liegt da, zweimal entpackt |

### 3.5 Die zwei Gegenproben, ohne die die Nullen nichts sagen

**Nach innen** — der `beweis` in 3.4 ist sie: Ein Archiv, das gar nicht
entpackt wird, erzeugt dieselbe Abwesenheit wie eine gehaltene Grenze.

**Nach aussen** — dasselbe Archiv, von `tar` selbst entpackt:

```bash
mkdir -p /tmp/gegen && cd /tmp/gegen
tar -xPf /tmp/boes/raus-relativ.tar
tar -xPf /tmp/boes/raus-absolut.tar
ls -l /tmp/getroffen-relativ /tmp/getroffen-absolut
```

**Erwartet:** **beide Dateien liegen jetzt da.** Damit ist belegt, dass die
Archive treffen können und die Abwesenheit oben eine Wand war und kein
untaugliches Archiv.

> **Ein Angriff, der nicht trifft, misst den Angreifer und nicht die Abwehr.**

**Aufräumen sofort danach**, sonst steht der Treffer der Gegenprobe in Punkt 4
als Bestand herum:

```bash
rm -f /tmp/getroffen-relativ /tmp/getroffen-absolut
rm -rf /tmp/gegen
```

---

## 4. Punkt 2 — durch einen Verweis hinaus schreiben, durch die echte Route

**Kriterium 5.** In `docs/62` hat der Prüfstand geschrieben; hier schreibt die
Route.

### 4.1 Der Verweis und sein Ziel

```bash
echo 'unberuehrt' > /tmp/ausserhalb-ziel
chmod 0666 /tmp/ausserhalb-ziel
sha256sum /tmp/ausserhalb-ziel | tee /tmp/ausserhalb-ziel.davor

ln -sfn /tmp/ausserhalb-ziel "$WURZEL/httpdocs/boes/durchgang"
chown -h "$SYS:$SYS" "$WURZEL/httpdocs/boes/durchgang"
ls -l "$WURZEL/httpdocs/boes/durchgang"
```

**`0666` ist Absicht:** Der Zielpfad muss für den Kunden schreibbar sein. Wäre
er es nicht, scheiterte der Versuch an den Dateirechten und nicht an der
Grenze — und der Lauf hielte ein `EACCES` für eine Wand.

> **Eine Gegenprobe, die an einer anderen Hürde scheitert als der gemeinten,
> hat die gemeinte nicht geprüft.**

### 4.2 Der Versuch, wie ein Kunde ihn macht

Im Browser, angemeldet als Kunde:

    https://cloudsrv24.de:8443/subscriptions/140/files/edit?path=/httpdocs/boes/durchgang

**Was hier steht, ist selbst ein Messwert** — festhalten, welcher Satz kommt.
Öffnet der Editor die Datei nicht, ist das die erste Wand und der Punkt geht
mit 4.3 weiter. Öffnet er sie, Inhalt ändern und **speichern**.

### 4.3 Der Versuch durch die Route selbst

Der Editor ist nur ein Formular; die Wand steht hinter `PUT`. In der Konsole
derselben angemeldeten Sitzung:

```js
const token = decodeURIComponent(
  (document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/) || [])[1] || ''
)

const schreiben = async (pfad, inhalt) => {
  const antwort = await fetch('/subscriptions/140/files', {
    method: 'PUT',
    headers: {
      'X-XSRF-TOKEN': token,
      'X-Requested-With': 'XMLHttpRequest',
      'Content-Type': 'application/json',
      Accept: 'application/json',
    },
    credentials: 'same-origin',
    body: JSON.stringify({ path: pfad, content: inhalt }),
  })

  return { pfad, status: antwort.status, umgeleitet: antwort.redirected }
}

console.log(JSON.stringify([
  await schreiben('/httpdocs/boes/durchgang', 'DURCHGEKOMMEN'),
  await schreiben('/httpdocs/boes/ziel/beweis', 'DURCHGEKOMMEN'),
], null, 1))
```

**Kein `X-Inertia`** — die Kopfzeile liegt vor dem `can:` der Route und erzeugt
einen 409, der über die Wand nichts sagt (`docs/62 §2`).

### 4.4 Was erwartet wird

| | erwartet |
|---|---|
| `/tmp/ausserhalb-ziel` nach beidem | **`unberuehrt`**, Prüfsumme wie in `.davor` |
| die zweite Zeile der Ausgabe (`beweis`) | **durchgekommen** — die Datei trägt `DURCHGEKOMMEN` |

```bash
sha256sum /tmp/ausserhalb-ziel
cat /tmp/ausserhalb-ziel.davor
cat "$WURZEL/httpdocs/boes/ziel/beweis"
```

**Die zweite Zeile ist die Gegenprobe, und ohne sie ist die erste wertlos.**
Sie schreibt über dieselbe Route, mit demselben Token, in derselben Sitzung —
nur auf einen Pfad **innerhalb** der Wurzel. Bleibt auch sie unverändert, war
nicht die Grenze im Weg, sondern der Weg kaputt.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**`/etc/shadow` wird nicht angefasst.** `docs/62` hat das entschieden und die
Begründung steht dort: Die Gegenprobe *müsste treffen*, und das hiesse, die
Kennwortdatei der Maschine zu überschreiben, auf der der Lauf fährt. Ein
Wegwerfziel ausserhalb der Wurzel ist dieselbe Form des Angriffs mit einem
anderen Ziel.

---

## 5. Punkt 3 — die Umbruchregel auf zwei weiteren Seiten

**Die Regel:** `.stacks td { overflow-wrap: anywhere }` im ≤720-px-Block von
`app.css`, gebaut für `/audit` (Befund 6, `docs/67`). Sie kann Überlauf nur
verkleinern — aber sie ändert, **wo** eine lange Kennung bricht, und das ist
auf jeder gestapelten Tabelle sichtbar.

### 5.1 Der Prüfkörper — ein Name, der nicht brechen will

Ohne einen langen Namen ohne Leerzeichen misst diese Seite nichts.

```bash
install -o "$SYS" -g "$SYS" -m 0644 /dev/null \
  "$WURZEL/httpdocs/boes/SHA256-Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1ndfoNv9EADbKjs.txt"
```

Für die Cronseite genügt der Testjob, der ohnehin dort steht — trägt er einen
kurzen Namen, im Panel einen zweiten anlegen mit der Beschriftung
`SHA256-Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1ndfoNv9EADbKjs` und dem Zeitplan
`0 5 1 * *` (einmal im Monat, damit er nicht läuft).

### 5.2 Die Messung

Je Seite und je Theme: Fenster auf **390 px**, Seite **neu laden**,
`tests/bilder-messen.js` einfügen, `messen()`.

> **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher übrig
> ist.** (`docs/63 §6`)

| Seite | |
|---|---|
| `/subscriptions/140/files?path=/httpdocs/boes` | hell und dunkel |
| `/subscriptions/140/cron` | hell und dunkel |

**Erwartet je Lage:**

| Feld | erwartet |
|---|---|
| `dokument` | **0** |
| `gegenprobe` | **200 / 200** |
| `schiebt` | **nur `.stacks thead` mit seinem `tr`** — jeder andere Eintrag ist ein Fund |
| `rollt` | jeder Eintrag trägt `darf: true` |

### 5.3 Und die Zahl allein reicht hier nicht

**Je Seite eine Aufnahme, und darauf wird eine Sache angesehen:** Bricht der
lange Name **innerhalb seiner Zelle** über zwei Zeilen — und bleibt die
Beschriftung links davon ganz? Ein Umbruch mitten in einem kurzen Wort wäre der
Preis der Regel und gehört benannt.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.** Deshalb wird jede der vier Aufnahmen
> einmal ganz gelesen, nicht nur an der Stelle des Prüfkörpers.

---

## 6. Punkt 4 — `id` am Vorgang

**Die halbe Hälfte von Kriterium 13.** Gemessen ist: Der Vorgang meldet eine
`uid`, sie ist nicht 0, und zwei Abonnements ergeben zwei Zahlen. Ungemessen
ist, dass die Zahl **die des Abonnements** ist — das sagte bisher `id` und
nicht der Vorgang.

```bash
id -u "$SYS"; id -G "$SYS"
```

Dann im Panel **eine** Datei-Handlung auslösen — etwa den Dateimanager des
Abonnements 140 öffnen — und danach:

```bash
grep '"ran_as"' /var/log/srvpanel/agent.log | tail -3
```

Jede Zeile ist ein JSON-Objekt der Form
`{"ts":…,"kind":"result","ok":true,"op":"files.list","ran_as":{"uid":N,"groups":[N]}}`.
Kommt **keine** Zeile, ist das kein Ergebnis, sondern ein Fund: `Connection`
schreibt `ran_as` je Anfrage, und genau daran ist `docs/61 §0a` beim ersten
Anlauf gescheitert.

| | erwartet |
|---|---|
| `id -u p1139` | eine Zahl **N** > 0 |
| `ran_as` des Vorgangs | `{"uid":N,"groups":[N]}` — **dieselbe Zahl** |

**Die Gegenprobe steht schon in `docs/62`:** `system.info` trägt **kein**
`ran_as`. Wäre das Feld eine Konstante, die jeder Vorgang mitschreibt, sähe es
genauso aus wie eine Messung.

> **Ein Feld, das überall gleich dasteht, belegt nicht, dass jemand es gefüllt
> hat.**

---

## 7. Punkt 5 — die Rechte an `/var/lib/srvpanel`

Der Rest aus `docs/61 §0`, der seit `docs/67` Befund 1 keiner mehr ist.
**Gemessen bei laufenden Diensten**, nicht nach einem `apt-get remove` — das ist
Befund 2 desselben Laufs:

```bash
systemctl is-active srvpanel-agentd srvpanel-web
stat -c '%a %U:%G %n' /var/lib/srvpanel /var/lib/srvpanel/storage /var/log/srvpanel
systemctl restart srvpanel-agentd && sleep 2
stat -c '%a %U:%G %n' /var/lib/srvpanel /var/lib/srvpanel/storage /var/log/srvpanel
```

| | erwartet |
|---|---|
| `/var/lib/srvpanel` | **`750 srvpanel:srvpanel`**, vor und nach dem Neustart gleich |
| `/var/log/srvpanel` | ebenso |

**Der Neustart ist der Punkt.** Vorher stand hier `755 root:root`, weil systemd
den Modus bei **jedem** Start nachzog; ein `stat` ohne Neustart hätte das nicht
gefunden.

> **Eine Prüfung, die zum falschen Zeitpunkt misst, misst einen Zustand, den es
> im Betrieb nie gibt.**

---

## 8. Der Rückbau

**Er gehört zum Lauf und nicht danach.** Was stehen bleibt, ist beim nächsten
Lauf Bestand und sieht aus wie ein Messwert.

```bash
rm -f /tmp/getroffen-relativ /tmp/getroffen-absolut
rm -rf /tmp/gegen /tmp/boes
rm -f /tmp/ausserhalb-ziel /tmp/ausserhalb-ziel.davor
rm -f "$WURZEL/httpdocs/boes/durchgang"
find "$WURZEL" -name 'getroffen*' -o -name 'raus-*.tar' 2>/dev/null
```

Im Panel: das Verzeichnis `/httpdocs/boes` samt Inhalt entfernen, und den
Testjob mit der langen Beschriftung löschen. **Der Testjob `Y`, der alle 15
Minuten läuft, gehört ebenfalls weg** — er stammt aus `docs/67` und ist dort als
Bestand benannt.

**Die letzte Zeile muss leer sein.** Bleibt ein `getroffen*` liegen, ist das
kein Aufräumfehler, sondern ein Befund.

---

## 9. Was dieser Lauf ausdrücklich nicht prüft

*(Sonst liest sich sein Schweigen wie ein Ergebnis.)*

- **Die stumpfe Fassung.** `docs/62` hat sie gefahren und die Frage aus
  `docs/61 §1` beantwortet: Nicht die Normalisierung hält, sondern das Chroot.
  Hier geht es allein darum, dass der **Weg durch die Route** dieselbe Antwort
  gibt wie der Weg durch die Operation.
- **Wand 2 aus Punkt 11** (`docs/59`) — ein Zusatzbenutzer ohne `ftp_accounts`.
  Bewusst offen, mit Namen.
- **Befund 23** (`docs/59`) — der Zeitstempel in der Messvorschrift, fällig beim
  nächsten Journalbeleg.
- **Die neunzehn Griffe in der Datenbankkonsole** (`RevealTest::UNEXAMINED`) und
  **die vollständige Umkehrung der Abstandsregel** — beide gehören
  ausdrücklich nicht zu P6 (`docs/51 §13`, `docs/64 §3`).
- **Eine dritte volle Bilderrunde.** Punkt 3 misst die zwei Seiten, an denen die
  neue Regel am ehesten etwas ändert. Fällt dort etwas auf, ist die Runde
  fällig — und dieser Lauf endet dann ohne Abnahme.

---

## 10. Wann P6 abgenommen ist

Wenn **die Punkte 1 bis 5** dieses Laufs ihr erwartetes Ergebnis zeigen, jede
Messung ihre Gegenprobe hat, jeder Fund entweder behoben und nachgemessen oder
im Protokoll benannt ist — und `docs/69` die fünfzehn Kriterien aus `docs/51 §4`
mit ihren **gemessenen Werten** führt, so wie `docs/42` es für P5b tut.

**Dann, und erst dann, werden vier Stellen nachgeführt**, die heute das
Gegenteil sagen:

| Stelle | steht heute da |
|---|---|
| `docs/62 §3`, letzte Zeile | „Damit ist P6 nicht abgenommen" |
| `docs/64 §3`, letzte Zeile | „Damit ist Schritt 12 nicht abgeschlossen" |
| `CLAUDE.md`, Kopf | „P0 bis P5c abgenommen; P6 läuft" |
| `CHANGELOG.md` | kein Abschnitt „P6 abgeschlossen" |

> **Ein Zustand, der nirgends steht, ist keiner — er ist eine Erinnerung.**

**Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
