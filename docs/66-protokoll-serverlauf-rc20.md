# Protokoll des Serverlaufs zu `v0.6.0-rc.20`

**Der Lauf steht in `docs/65`.** Dieses Dokument füllt sich **während** des
Laufs, Punkt für Punkt — nicht danach.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

**Stand: läuft.** Was unten ohne Zahl steht, ist eine offene Zeile und kein
erfüllter Punkt.

---

## 0. Der Rahmen

| | |
|---|---|
| Fassung | `v0.6.0-rc.20` |
| Server | `cloudsrv24` |
| Abonnement | 140 — `p6-abnahme.invalid` |
| Systembenutzer | `p1139` |
| Browser | Chrome, Gerätesymbolleiste 390×844 |
| Gefahren am | 20. August 2026 |
| Stand des Messmittels | `2026-08-19` |

---

## 1. Die Punkte

Je Punkt: die gemessene Zahl, das Bild, und bei einer Abweichung der Befund mit
dem, was er über den Prüfling **oder über das Prüfmittel** sagt.

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 1 | Ein Wurzelelement | `1` | **`[1, 1]`** | erfüllt |
| 2 | Rückmeldungen deutsch | keine Bezeichner | | |
| 3 | Meldung der Experteneingabe | „Im Ausdruck fehlt der 4. Teil (Monat)." | **wörtlich so, dazu der 5. Teil (Wochentag)**; `aria-invalid="true"` | erfüllt, hell und dunkel |
| 4 | Kontingentauskunft oben | ~~`oben` ≈ 18~~ → **über den Bereichen** | `oben: 294` von 3795 px Seitenhöhe, Text nennt „(10 von 10)" | erfüllt; das Kriterium war falsch (Befund 4) |
| 5 | „Job anlegen" bei 1440 px | Zeitplan in voller Breite | `fieldset.field wide` **1124×325**, Schnellwahl darin **1124×64**; `schiebt: []` | erfüllt, hell und dunkel |
| 6 | Griff zum Formular | springt, Formular leer | Knopf da, Sprung erfolgt, Beschriftung und Befehl **leer** | erfüllt |
| 7 | Zielbaum im Bild | `oben` ≥ 0 | `oben: 216`, `unten: 629`, `fenster: 844` | erfüllt — und ganz drin |
| 8 | Schlüssel erzeugen und anmelden | Anmeldung gelingt, Fremdschlüssel abgewiesen | Fingerabdruck stimmt überein, `sftp` verbindet, Fremdschlüssel `Permission denied (publickey)` | erfüllt (1440 px hell; Reste in §3) |
| 9 | Suchleiste | ab 720 px da, Pfad sichtbar, Inhalt übertragen | Leiste und Pfad ja; **jede Suche wird abgewiesen** | **nicht erfüllt — Befund 5** |
| 10 | Kopfleiste am Telefon | eine Zeile, vier ganze Wörter | `zeilen: 1`, `hoehe: 120`, alle vier Sätze vollständig | erfüllt, hell und dunkel |
| 11 | Gegenprobe des Laufs | `dokument` ≫ 0 | | |

---

## 2. Die Befunde

*(Je Befund: was gesehen wurde, welche Zahl dazugehört, und ob er am Panel, am
Prüfmittel oder am Kriterium hängt. Die Aufteilung ist der Punkt — in vier
Läufen davor steckte die Mehrheit nicht im Prüfling.)*

### Befund 1 — `/var/lib/srvpanel` gehört root, und `srvpanel tinker` führt nichts mehr aus

**Gefunden in der Vorbereitung, vor dem ersten Punkt.** Der Aufruf aus
`docs/65 §0.2` endete mit

    <warning> User Notice </warning> Writing to directory
    /var/lib/srvpanel/.config/psysh is not allowed.

und **ohne eine Zeile Ausgabe**. Der übergebene Code lief nicht.

**Gemessen auf dem Server:**

    drwxr-xr-x 7 root root 4096 Aug 20 21:34 /var/lib/srvpanel
    uid=111(srvpanel) gid=110(srvpanel) groups=110(srvpanel),33(www-data)
    srvpanel darf NICHT schreiben

`.config` gab es dabei gar nicht — die erste Vermutung („ein Rest aus der Zeit
vor `setpriv`, angelegt als root") war falsch. Das Verzeichnis steht auf
**`0755 root:root`**, obwohl `nfpm.yaml` es als `0750 srvpanel:srvpanel`
ausliefert.

**Die Ursache ist eine Eigenschaft von `install -d`, im Container nachgemessen:**

    install -d -m 0700 a/b/c   →   a 755, a/b 755, a/b/c 700

**Modus und Eigentümer gelten nur für die letzte Ebene.** `create_storage()`
legt `/var/lib/srvpanel/storage` an; der Elternteil fiel dabei nebenbei an und
gehörte dem Aufrufer — root.

> **Ein Verzeichnis, das nebenbei entsteht, gehört dem, der zufällig da war.**

**Warum es niemandem auffiel.** Der Dienst schreibt in `storage/`, und das
gehört ihm. Erst psysh stolperte, weil es sein `.config` unter HOME anlegen
will — und der Fehlschlag hat die Form, vor der `packaging/bin/srvpanel` in
seinem eigenen Kommentar warnt:

> **Ein Befehl, der schweigt, sieht aus wie einer, der nichts gefunden hat.**

**Und der Prüfstand hat genau danebengesehen.** `packaging/testbed.sh` fragte
`test -d /var/lib/srvpanel`. Vorhanden war es die ganze Zeit.

> **Eine Prüfung, die nur nachsieht, dass etwas da ist, sagt nichts darüber,
> wem es gehört.**

**Behoben:** `create_storage()` legt den Elternteil ausdrücklich an — `install
-d` zieht Modus und Eigentümer auch bei einem vorhandenen Verzeichnis nach, die
Zeile richtet bestehende Installationen also mit. Der Prüfstand vergleicht
seitdem `stat -c "%a %U:%G"` gegen `750 srvpanel:srvpanel`, und
`PackagingTest::test_the_data_directory_belongs_to_the_service` hält beides.

**Der Lauf war dadurch nicht blockiert:** Die Kennungen kommen auch ohne psysh,
direkt aus der Datenbank.

### Befund 5 — „auch im Inhalt" ist noch nie durchgekommen

**Gesehen bei 1440 px**, Suchbegriff eingetragen, Kästchen angekreuzt, „Suchen":

> Das Formular wurde nicht gespeichert.
> **Das Feld Inhalt muss wahr oder falsch sein.**

Und das Kästchen bleibt danach leer.

**Die Ursache ist eine Zeile, die auf beiden Seiten gleich falsch ist:**

    router.get(…/files/search, { query, path, content: inContent.value })

`router.get` legt die Werte in die **Adresse**, und eine Adresse kennt keine
Wahrheitswerte — `false` wird dort zur Zeichenkette `"false"`. Laravels Regel
`boolean` nimmt `true, false, 1, 0, "1", "0"` und **nicht** `"true"`/`"false"`
(nachgemessen). Die Regel weist also ab, was der Browser schickt.

> **Dieselbe Regel über einem Wert, der einmal als JSON und einmal als
> Zeichenkette reist, gilt nur einmal.**

Der Gegenbeleg steht in derselben Datei: `recursive` trägt dieselbe Regel und
funktioniert — es reist im **Rumpf** eines `DELETE` und bleibt dort ein echter
Wahrheitswert.

**Das ist älter als heute.** `Search.vue` schickt seit P6 Schritt 5 dieselbe
Zeile; ich habe sie beim Bau von Wunsch 3 nach `Index.vue` übernommen. Was
heute neu ist, ist nicht der Fehler, sondern dass ihn jemand ausgelöst hat.

**Und mein Wächter von heute konnte ihn nicht finden.**
`FileSearchTest::test_both_inputs_send_the_same_values` vergleicht die
**Schlüssel**, die beide Seiten schicken — und beide schicken denselben kaputten
Wert.

> **Zwei Eingaben, die dasselbe schicken, schicken auch denselben Fehler.**

**Zu tun nach dem Lauf:** Der Sender wählt eine Form, die die Regel kennt —
`content: inContent.value ? 1 : 0` auf **beiden** Seiten. Dazu ein Wächter, der
nicht die Schlüssel vergleicht, sondern fragt: Kommt an einer GET-Route ein
Wert an, dessen Regel `boolean` heisst und der als `true`/`false` gesendet wird?

**Und es trifft jede Suche, nicht nur die im Inhalt** — nachgemessen im
Container gegen `mergeDataIntoQueryString` aus dem ausgelieferten
`@inertiajs/core`:

    false -> …/files/search?query=x&path=%2F&content=false   Rumpf: {}
    true  -> …/files/search?query=x&path=%2F&content=true    Rumpf: {}

Beide Zustände reisen als Wort, und die Regel nimmt weder das eine noch das
andere. Die Frage, die hier als „vom Betreiber zu messen" stand, ist damit
beantwortet, ohne dass er sie stellen musste: **Die Suche im Dateimanager ist
seit P6 Schritt 5 an keinem einzigen Tag durchgekommen.** Was der Betreiber
gesehen hat, war nicht das Kästchen — es war die Suche.

> **Ein Fehler, den man am auffälligen Fall entdeckt, ist selten auf den
> auffälligen Fall beschränkt.**

Der Grund, warum das niemandem auffiel: Bis heute gab es **keinen** Weg zur
Suche, den man versehentlich benutzt — `Search.vue` erreicht man nur, indem man
schon gesucht hat. Wunsch 3 hat die Leiste an die Stelle gestellt, an der
jemand sie drückt.

### Zwei Beobachtungen aus derselben Messung, beide keine Befunde

**`kopf.zeilen: 2` bei 1440 px.** Der vierte Knopf ist der `.search-toggle`, und
der steht dort auf `display: none`. Ein solches Element meldet `top: 0`, und die
Menge der Oberkanten hat damit zwei Werte. Der Ausdruck aus `docs/65` taugt für
390 px und nicht für 1440.

> **Eine Kante, die es nicht gibt, ist trotzdem eine Zahl.**

**Der Pfad in der Leiste bricht bei 1440 px über sieben Zeilen.** Das ist der
ungünstige Fall dieses Laufs — ein Verzeichnisname von rund 300 Zeichen —, und
`dokument` ist dabei **0**: Er bricht, statt zu schieben. Genau dafür trägt er
`.ident`. Schön ist es nicht; gemessen ist es in Ordnung.

---

### Punkt 8 — der Schlüssel aus dem Browser meldet sich an

**Der Punkt, für den es diesen Lauf vor allem gibt.** Gemessen am 21. August auf
einem Windows-Rechner, PowerShell, `OpenSSH_for_Windows_9.5p2`.

**Im Panel** (1440 px, hell): Bezeichnung `Testschlüssel rc20`, „Schlüssel
erzeugen". Der Satz über den privaten Teil steht **vor** der Knopfreihe, die
Zeile erscheint in der Tabelle, und der private Teil erscheint erst danach
darunter. Der Knopf „Schlüssel erzeugen" ist danach abgeblendet
(`:disabled="form.processing || erzeugt"`) — ein zweiter Klick kann den ersten
Schlüssel nicht mehr verdrängen.

**Auf dem Rechner, in dieser Reihenfolge:**

    ssh-keygen -y -f $k
      ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMEyXSAasImCYVLbe4lZZhwiF1kQs4gCHHv6olASrOz/
      Testschlüssel rc20 p1139

    ssh-keygen -l -f $k
      256 SHA256:Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1ndfoNv9EADbKjs Testschlüssel rc20 p1139 (ED25519)

    sftp -o IdentitiesOnly=yes -i $k -P 22 p1139@cloudsrv24.de
      Connected to cloudsrv24.de.
      sftp> pwd
      Remote working directory: /

**Und die Gegenprobe danach:**

    sftp -o BatchMode=yes -o IdentitiesOnly=yes -i "$env:TEMP\fremd" …
      p1139@cloudsrv24.de: Permission denied (publickey).

Der Fingerabdruck `SHA256:Sn3W6HvtgEGjDuvTnuZvc7Zys8zk1…` steht **zeichengleich**
in der Panel-Tabelle. Damit ist die Kette geschlossen: Was der Browser erzeugt
hat, liest OpenSSH, es ist derselbe Schlüssel, den der Server kennt, und ein
anderer kommt nicht durch.

**Der Aufsatz derselben Seite:** `dokument: 0`, `gegenprobe: 200/200`,
`schiebt: []`; `rollt` nennt allein die Schlüsseltabelle
(`div.scrolls`, `ueberlauf: 232`, `darf: true`) — die darf rollen.

**`pwd` meldet `/`, und mein Kriterium sagte „das Verzeichnis des
Abonnements".** Beides ist dasselbe: Innerhalb des `chroot` **ist** die Wurzel
des Abonnements `/`. Das Kriterium war unpräzise formuliert, nicht verfehlt —
und die gemessene Antwort ist die stärkere von beiden.

**Sie trennt allerdings zwei Fälle nicht**, und das gehört benannt: `/` sieht
gleich aus, ob der `chroot` auf die Wurzel dieses Abonnements zeigt oder
irgendwo anders hin.

> **Ein Pfad, der in jedem Gefängnis gleich heisst, sagt nichts darüber, in
> welchem man sitzt.**

**Nachgereicht, und damit entschieden:**

    sftp> ls
    conf     httpdocs     logs     mail     tmp

Das sind die Verzeichnisse dieses Abonnements. `.ssh` fehlt in der Aufzählung,
weil `ls` ohne `-a` keine Punktdateien zeigt — im Baum des Dateimanagers steht
es. **Punkt 8 belegt damit nicht nur die Anmeldung, sondern die Einsperrung.**

**Die drei Gegenproben zum Umgang mit dem privaten Teil:**

| | |
|---|---|
| steht er in einer Erfolgsmeldung? | nein — er steht in einem eigenen Block mit `notice warn` |
| überlebt er ein Neuladen? | **nein**, der Block ist fort, der Schlüssel bleibt in der Tabelle |
| steht er unter „Vorgänge"? | nein — dort steht zur Schlüsselerzeugung **überhaupt nichts** |

**Der Aufsatz bei 390 px, hell und dunkel dieselben Zahlen:**

    dokument: 0     gegenprobe: 200/200     rollt: []
    schiebt: [ thead 342, thead > tr 314 ]

`dokument: 0` — die Seite schiebt nicht. Die beiden Einträge unter `schiebt`
sind der bereits festgehaltene **Befund 2**: `.stacks thead` ist im
Kärtchenmodus mit Absicht weggeblendet und meldet trotzdem einen Überlauf. Das
ist ein Fehler der Liste, nicht der Seite.

### Punkt 8, die Gegenprobe zur Gegenprobe — „nirgends" ist keine Antwort

Der Betreiber hat die dritte Zeile der Tabelle oben gemeldet: *„in den
Operations wird die Key Erstellung nicht angezeigt"*. Das Kriterium lautete
„der private Teil taucht dort nicht auf", und das ist erfüllt — aber die
Beobachtung ist die stärkere: Dort steht **gar nichts**, auch nicht die
Erzeugung selbst.

Der Quelltext sagt, warum, und es ist richtig so. `SftpController::store` ruft
den Agenten unmittelbar (`sftp.key.apply`) statt über einen Vorgang mit
Lebenslauf, und hält die Handlung im **Protokoll** fest:

```php
$this->audit->record('sftp.key.add', subscriptionId: …, context: [
    'fingerprint' => $ergebnis['key']->fingerprint,
    'type' => $ergebnis['key']->type,
]);
```

Die beiden Listen bedeuten Verschiedenes: **„Vorgänge" ist, was der Agent im
Auftrag eines Lebenslaufs getan hat; „Protokoll" ist, was ein Mensch veranlasst
hat.** Ein Schlüssel gehört in die zweite. Und der Zusammenhang trägt nur
Fingerabdruck und Art — kein Schlüsselmaterial, auch kein öffentliches.

**Damit ist der Punkt aber noch nicht zu Ende gemessen.** „Es steht nicht unter
Vorgänge" ist erst dann eine gute Nachricht, wenn es unter Protokoll steht;
sonst hiesse es, eine Handlung, die einem Fremden Zugang zu allen Dateien gibt,
hinterlässt nirgends eine Spur.

> **„Es steht nicht dort" ist erst in Ordnung, wenn es woanders steht.**

**Gemessen, und die Spur ist da** — `/audit` bei 390 px:

    ZEITPUNKT  2026-08-21 10:16:52
    AKTION     sftp.key.add
    ERGEBNIS   erfolgreich
    ZIEL       —
    IP         94.31.74.201

**Punkt 8 ist damit vollständig erfüllt.** Und die Gegenprobe hat, wie so oft in
diesem Projekt, mehr gefunden als sie sollte — siehe Befund 7.

### Befund 7 — das Protokoll von P6 sagt die Art und nie das Stück

**Die Zeile oben trägt `ZIEL —`, und der Fingerabdruck steht nirgends.** Er ist
aufgezeichnet:

```php
$this->audit->record('sftp.key.add', subscriptionId: …, context: [
    'fingerprint' => …, 'type' => …,
]);
```

Nur führt aus dem `context` kein Weg hinaus. `AuditQuery::toArrayRow()` legt
acht Felder auf die Seite und `context` ist keines davon; `Audit/Index.vue` hat
fünf Spalten (`Zeitpunkt`, `Aktion`, `Ergebnis`, `Ziel`, `IP`); und der
CSV-Export baut seine Zeile aus derselben Ablage. Der Wert steht in der
Datenbank und ist durch keine Oberfläche zu erreichen.

**`Ziel` bliebe der Ausweg — und genau den nimmt P6 nicht.** `Audit::record()`
hat seit P0 einen Parameter `?Model $target`, und die früheren Stufen benutzen
ihn: `$audit->success('plan.created', $plan, …)`,
`'domain.created', $domain`, `'operation.started', $operation`. Ausgezählt über
`app/`:

| | |
|---|---|
| Aufrufe mit `target:` | **19** |
| Aufrufe mit `context:` und ohne `target:` | **18** |
| Aufrufe mit keinem von beidem | 7 |

**Und alle achtzehn der mittleren Zeile sind P6 oder Anmeldevorgänge** — die
drei `cron.job.*`, die zehn `file.*`, die beiden `sftp.key.*`, dazu
`auth.login.failed`, `auth.login.throttled`, `auth.session.expired`. Bei den
Anmeldungen ist es richtig: Dort gibt es kein Ziel. Bei den anderen fünfzehn
gibt es eines, und es steht ein Modell dafür bereit.

Was das Protokoll dieses Panels also über P6 sagt: `file.removed` — nicht
welche Datei. `file.chmod` — nicht welche und nicht worauf. `sftp.key.remove` —
nicht welcher Schlüssel. Für einen SSH-Schlüssel, der Zugang zu allen Dateien
eines Abonnements gibt, ist „welcher" die einzige Frage, für die man ein
Protokoll aufschlägt.

> **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
> beantwortet die Frage, die niemand stellt.**

Und die Form des Fehlers ist die bekannte: **kein Entwurf, sondern eine
Gewohnheit, die beim neuesten Code ausgesetzt hat.** P0 bis P5 übergeben ein
Ziel, P6 übergibt einen `context` und niemand rendert ihn. Dazwischen liegt
keine Entscheidung, nur eine andere Woche.

> **Eine Gewohnheit, die kein Wächter hält, endet an der Datei, in der niemand
> mehr hinsieht.**

**Zu tun nach dem Lauf**, drei Teile:

1. Die fünfzehn P6-Aufrufe bekommen ihr `target` (`$job`, `$key`; bei den
   `file.*` gibt es kein Modell — dort trägt der Pfad die Auskunft und muss
   sichtbar werden).
2. Eine Spalte für den Zusammenhang, in Liste **und** Export. Ohne die zweite
   ist der Beleg, den jemand aufhebt, weiter der ärmere.
3. Ein Wächter: **Wer `context` übergibt, übergibt ein `target` — oder der
   Zusammenhang ist sichtbar.** Und die Gegenprobe dazu ist der Grund, warum
   dieser Befund überhaupt gefunden wurde: Ein Feld, das geschrieben und nie
   gelesen wird, ist von aussen nicht von einem unterscheidbar, das es nicht
   gibt.

### Befund 6 — der Hinweis unter dem privaten Schlüssel kennt nur Unix

Unter dem Feld steht:

> Auf Ihrem Rechner gehört er nach `~/.ssh/id_ed25519` und braucht dort die
> Rechte `600`. Danach meldet `sftp` sich damit an.

Auf Windows stimmt daran **kein einziger Teil**: Der Ort heisst
`%USERPROFILE%\.ssh`, `600` gibt es nicht, und ohne
`icacls … /inheritance:r /grant:r` bricht OpenSSH mit
`UNPROTECTED PRIVATE KEY FILE` ab. Ein Kunde, der den Satz befolgt, landet bei
einer Fehlermeldung, die nach einem kaputten Schlüssel aussieht und eine der
Dateirechte ist.

Aufgefallen ist es nicht am Quelltext, sondern daran, dass ich dem Betreiber
für genau diesen Schritt eine zweite, andere Anleitung schreiben musste
(`docs/65 §8b`).

> **Ein Hinweis, der ein Betriebssystem voraussetzt, ist auf dem anderen kein
> unvollständiger Hinweis, sondern ein falscher.**

**Klein und billig zu beheben** — eine zweite Zeile für Windows unter derselben
Notiz. Entscheidung des Betreibers, ob sie in diese Stufe gehört.

### Punkt 7 — der Zielbaum, und was diese Messung nicht sagt

Bei 390 px, hell, zwei Einträge angekreuzt, „Verschieben":

    { oben: 216, unten: 629, fenster: 844 }

`oben ≥ 0` ist das Kriterium, und 216 erfüllt es. **Befund 18 ist behoben.**

Der Baum ist dabei sogar **ganz** im Bild: 629 < 844. Genau das ist die Grenze
dieser Messung, und sie gehört benannt — dieser Baum ist 413 px hoch, weil das
Abonnement sechs Einträge unter der Wurzel hat. Ein Abonnement mit dreissig
Verzeichnissen misst hier etwas anderes, und ob die Zentrierung dann oben
abschneidet, ist **nicht** gemessen. Das Kriterium ist deshalb `oben ≥ 0` und
nicht `unten ≤ fenster`: Der überzählige Baum darf rollen, er darf nur nicht
oben verschwinden.

> **Ein Prüfkörper, der in das Fenster passt, sagt nichts über den, der es
> nicht tut.**

**Und eine Beobachtung nebenbei, die kein Kriterium bestellt hat.** Der
Verzeichnisname aus §0.2 — rund 300 Zeichen — bricht im Baum über neun Zeilen,
ohne die Seite zu schieben. Das ist dieselbe Ausnahme wie bei `.ident` und
`.stacks td .ident` und beim Bereichstitel aus `docs/46 §20.11`, hier zum
vierten Mal und diesmal von Anfang an richtig.

### Punkt 10 — die Kopfleiste, und der Aufsatz trifft aufs Pixel

Bei 390 px, **hell und dunkel dieselben Zahlen:**

    dokument: 0     gegenprobe: 200/200     rollt: []
    kopf: { hoehe: 120, zeilen: 1,
            knoepfe: ["Verzeichnis anlegen", "Datei anlegen",
                      "Datei Hochladen", "Suchen"] }

**Eine Zeile statt vier**, und **120 px** — genau der Wert aus dem Aufsatz im
Container. Das ist kein Zufall und die Bestätigung dessen, was in Befund 4 als
Vorhersage stand: `kopf.hoehe` misst den Seitenkopf **selbst** und nicht seinen
Abstand von oben. Band und Kopfzeile darüber verschieben ihn, machen ihn aber
nicht höher.

> **Eine Höhe überlebt den Umzug, ein Abstand nicht.**

**Und die vier Sätze stehen vollständig da**, obwohl sichtbar nur „Verzeichnis /
Datei / Hochladen / Suchen" auf den Knöpfen steht. Das Verb ist aus dem **Bild**
genommen und nicht aus dem Dokument — der Knopf heisst für die Vorlesesoftware
weiterhin „Verzeichnis anlegen".

### Befund 2 bestätigt und erweitert — jetzt sind es auch die Verben

Dieselbe Messung meldet unter `schiebt`:

    span.verb   überlauf 46   <span class="verb"> anlegen</span>
    span.verb   überlauf 46   <span class="verb"> anlegen</span>
    span.verb   überlauf 30   <span class="verb">Datei </span>

bei `dokument: 0`. `.verb` ist unter 720 px nach derselben Technik gebaut wie
`.sr`: 1 px breit, geklippt. Der Prüfkörper meldet jeden so gebauten Kasten.

**Damit ist Befund 2 nicht mehr eine Eigenheit einer Komponente, sondern eine
Klasse** — und sie **wächst**: `.verb` habe ich am 20. August selbst
hinzugefügt. Ohne Filter steht in `schiebt` bald mehr Gewolltes als Fund.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil —
> und sie wird mit jedem neuen Merkmal unbrauchbarer.**

**Nebenbei ist es der Beleg für die richtige Bauart:** Ein Wort mit
`display: none` hätte `scrollWidth 0` und stünde gar nicht in der Liste. Dass
die drei `span.verb` dort auftauchen, zeigt, dass sie im Dokument **sind** — und
genau das verlangt WCAG 2.5.3 für den zugänglichen Namen.

> **Dasselbe Messmittel, das hier falsch meldet, belegt an derselben Stelle das
> Richtige.**

---

### Punkt 6 — der Griff springt, und das Formular ist leer

Bei 390 px: „Job anlegen" steht in der Kopfzeile des Bereichs „Jobs", der Druck
springt zum Formular, und dort stehen **Beschriftung und Befehl leer**. Der
zweite Teil ist der wichtigere — der Griff setzt `bearbeitet` zurück, sonst
stünde nach einem vorherigen „Ändern" ein fremder Job im Formular.

**Und der Bestand ist danach wieder hergestellt:** Dieselbe Messung meldet
`kontingent: "nicht gefunden"`. Das ist hier **richtig** — die Übersteuerung aus
Punkt 4 ist zurückgenommen, das Kontingent also nicht mehr voll, und die Meldung
gehört fort. In Punkt 4 wäre derselbe Wert ein Befund gewesen.

> **Derselbe Messwert bedeutet in zwei Zuständen zwei verschiedene Dinge.**

**Eine Beobachtung nebenbei, ungemessen:** Die Schnellwahl steht bei 390 px als
**sechs** volle Zeilen untereinander. Anders als die Kopfleiste tragen diese
Knöpfe **Sätze** („montags bis freitags um 09:00") und keine Objektnamen — die
Form „Zeichen über Wort" aus `docs/64 §12` trägt hier also nicht. Ob es stört,
ist nicht gemessen und steht hier als Frage, nicht als Befund.

---

### Befund 4 — mein erwarteter Wert gehörte zu einer anderen Seite

**Gemessen bei 390 px, hell und dunkel, dieselben Zahlen:**

    dokument: 0        gegenprobe: 200/200
    kontingent.oben:   294
    kontingent.text:   "Das Kontingent dieses Plans ist ausgeschöpft (10 von 10).
                        Entfernen Sie einen Job, um einen neuen anzulegen."

`docs/65` erwartete **18**. Die Zahl stammt aus dem Aufsatz im Container — und
der Aufsatz hat keine Kundensicht-Leiste, keine Kopfzeile und keine Beizeile
über den Bereichen. Auf der echten Seite stehen darüber:

    das Band „Sie arbeiten in der Sicht dieses Kunden …"
    die Kopfzeile „☰ Cronjobs"
    die Beizeile „p6-abnahme.invalid"

**Die Meldung steht genau dort, wo sie hingehört** — als erstes Element über den
Bereichen, unmittelbar vor „Zeitplan und Zeitzone". Die 294 px sind das, was
über ihr steht, und nicht das, was zwischen ihr und dem Seitenkopf liegt.

> **Ein Wert, der an einer anderen Seite gemessen wurde, gehört zu einer anderen
> Seite.**

**Der Vergleich, auf den es ankommt, geht trotzdem auf.** Vorher stand die
Meldung bei **3566 px** auf einer Seite von 3795 — also bei 94 % und damit
hinter vier Bildschirmen. Jetzt steht sie bei 294 von 3795, im **ersten**
Bildschirm (Fenster 844 px).

| | vorher | jetzt |
|---|---|---|
| Abstand von oben | 3566 px | **294 px** |
| Anteil der Seitenhöhe | 94 % | **8 %** |
| im ersten Bildschirm? | nein | **ja** |

**Was daraus für den Rest des Laufs folgt:** Jede Zahl in `docs/65`, die aus dem
Aufsatz stammt, gilt für eine Seite ohne Band und ohne Kopfzeile. Betroffen ist
noch **Punkt 10** („`kopf.hoehe` rund 120") — dort ist der Seitenkopf selbst
gemessen und nicht sein Abstand von oben, also trägt die Zahl; die Prüfung
bleibt trotzdem `zeilen: 1` und nicht die Höhe.

`docs/65` ist entsprechend berichtigt: Das Kriterium ist **„über den
Bereichen"** und keine Pixelzahl.

---

### Punkt 5 im Wortlaut — Befund 14 ist behoben

Bei 1440 px (Inhaltsbreite 1124), **hell und dunkel**, dieselben Zahlen:

    dokument: 0        gegenprobe: 200/200        schiebt: []
    rollt:    div.scrolls  überlauf 250  darf: true   (die Jobtabelle, gewollt)
    gruppen:  fieldset.field wide  1124x325
              div.field wide       1124x64      ← die Schnellwahl, **darin**
              div.field-row        1124x87

**`schiebt` ist leer** — auf dieser Seite schiebt bei 1440 px gar nichts, und
der einzige Roller ist der gewollte Behälter der Jobtabelle.

Die Zeitplangruppe hat **1124 px**, also die volle Inhaltsbreite und nicht die
540 px eines `.field`. Die Schnellwahl steht **innerhalb** von ihr. Damit ist
die Fassung gebaut, die im Container 34k tote Fläche gemessen hat — und nicht
die, die 193k ergeben hätte.

> **Eine Umgruppierung, die die Breite nicht mitnimmt, verschiebt die leere
> Fläche nur.**

---

### Punkt 3 im Wortlaut — Befund 16 ist behoben

Bei 390 px, `* * *` in der Experteneingabe, **hell und dunkel**:

> Das Formular wurde nicht gespeichert.
> Im Ausdruck fehlt der 4. Teil (Monat).
> Im Ausdruck fehlt der 5. Teil (Wochentag).

`document.querySelector('#expression').getAttribute('aria-invalid')` → `"true"`
in beiden Themen.

**Kein „Das Feld Monat ist erforderlich".** Die Meldung nennt die Stelle im
Ausdruck, und das eine sichtbare Feld ist markiert — die eingeklappten Felder
werden nicht mehr benannt.

> **Eine Sicht auf eine Sache ist noch keine Sicht auf ihre Fehlermeldungen.**

Bemerkenswert daneben: **Punkt 2 und Punkt 3 stehen in derselben
Zusammenfassung, und nur einer von beiden hat ein falsches Wort.** „Befehl"
stimmt, „Bezeichnung" nicht (Befund 3) — die Meldungen des Ausdrucks bauen ihre
Namen zur Laufzeit aus derselben Liste und treffen trotzdem, weil sie die
**Stelle** nennen und nicht das Feld.

---

### Befund 2 — das Messmittel meldet jede versteckte Beschriftung

**Gesehen auf `/settings/profile` bei 390 px, dunkel.** `dokument: 0`,
`gegenprobe: 200/200` — und `schiebt` mit **fünf** Einträgen:

    span.sr   überlauf 32   darf: false
    Pfad: … form > div.password:1 > ul.rules > li:1 > span.sr:2
    Anfang: <span data-v-8a7e51ac="" class="sr">offen:</span>

**Das ist kein Fund am Panel.** `.sr` in `PasswordFields.vue` ist die übliche
Technik für eine Beschriftung, die nur die Vorlesesoftware hört:
`width: 1px; overflow: hidden; clip-path: inset(50%)`. Ein Kasten von 1 px mit
verstecktem Überlauf hat **immer** `scrollWidth > clientWidth`, und
`overflow: hidden` steht nicht in der Liste der erlaubten Roller. Geschoben wird
dabei nichts — `dokument` ist 0.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Derselbe Satz wie beim `.stacks thead`, und derselbe Preis: Auf jeder Seite mit
Passwortfeldern stehen fünf Geisterzeilen in `schiebt` — und wer sie dreimal
überliest, überliest beim vierten Mal den echten Fund.

**Zu tun:** `tests/bilder-messen.js` überspringt einen Kasten, der nach der
Technik „nur für die Vorlesesoftware" gebaut ist — 1 px breit **und** geklippt.
Nicht breiter gefasst: Ein Filter über `overflow: hidden` allein nähme die
halbe Messung mit.

### Befund 3 — „Das Feld Bezeichnung ist erforderlich", und auf der Seite steht „Beschriftung"

**Gesehen auf `/subscriptions/140/cron` bei 390 px, hell** (Punkt 2). Das
Formular mit leerer Beschriftung und leerem Befehl abgeschickt:

> Das Formular wurde nicht gespeichert.
> Das Feld **Bezeichnung** ist erforderlich.
> Das Feld **Befehl** ist erforderlich.

„Befehl" stimmt. **„Bezeichnung" steht nirgends auf dieser Seite** — das Feld
heisst dort „Beschriftung".

**Das ist ein Fehler in der Behebung von Befund 15.** Die 85 Namen sind
vollständig eingetragen, aber nicht gegen die sichtbare Beschriftung ihrer
Seite gehalten. `label` heisst auf zwei Seiten „Bezeichnung" und auf der
Cronseite „Beschriftung"; die Liste trägt den häufigeren Fall, und der andere
hätte seinen Namen als dritten Wert am Aufruf gebraucht. Genau das steht in der
Fehlermeldung von `AttributeNameTest` — angewendet habe ich es nicht.

> **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**

**Nachgemessen über alle Seiten**, mit zwei Sieben (am Aufruf überschriebene
Felder heraus; Paare, bei denen ein Wort das andere enthält, heraus):

| | |
|---|---|
| geprüfte Feld/Beschriftung-Paare | 62 |
| am Aufruf überschrieben, also in Ordnung | 4 |
| harmlos — eines enthält das andere („E-Mail" / „E-Mail-Adresse") | 12 |
| **echte Abweichungen** | **9** |

Die neun:

| Seite | Feld | auf der Seite | in der Liste |
|---|---|---|---|
| `Databases/Create.vue` | `label` | Name | Bezeichnung |
| `Subscriptions/Cron.vue` | `label` | Beschriftung | Bezeichnung |
| `Customers/Edit.vue` | `notes` | Vermerk | Notizen |
| `Customers/Edit.vue` | `postal_code` | PLZ | Postleitzahl |
| `Files/Index.vue` | `path` | Name der Datei | Pfad |
| `Files/Index.vue` | `path` | Name des Verzeichnisses | Pfad |
| `Settings/Profile.vue` | `email` | Anmeldeadresse | E-Mail-Adresse |
| `Databases/Show.vue` | `label` | Weiterer Zugang | Bezeichnung |
| `Settings/Tls.vue` | `directory` | Zertifizierungsstelle | Verzeichnis |

**Zu tun nach dem Lauf:** je Fall entscheiden, ob die Seite oder die Liste das
bessere Wort hat, und den Rest als dritten Wert am Aufruf setzen. Dazu ein
Wächter, der Beschriftung und Namen **gegeneinander** hält statt nur die Liste
zu zählen — mit denselben zwei Sieben, sonst meldet er zweiundzwanzig Fälle,
von denen dreizehn keine sind.

**Der Lauf ist dadurch nicht blockiert.** Punkt 2 ist damit *teilweise*
erfüllt: Die Meldungen sind deutsch — aber eine von zweien nennt ein Feld,
das auf der Seite anders heisst.

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.)*

Aus `docs/65 §12` schon vor dem Lauf benannt:

- `PasswordFields.vue generate touched` — ungemessen.
- Die Suche im ganzen Abonnement gibt es nicht.
- Die gestapelten Knopfreihen der anderen Seiten sind nicht gemessen.
- RSA und ECDSA werden nicht erzeugt.

---

## 4. Wunsch 4 — die Umrechnung während des Tippens

**Bestellt vom Betreiber am 20. August**, beim Messen von Punkt 5: Beim Anlegen
soll sofort dastehen, **in welchem Rhythmus** der Job laufen wird. Keine
zusätzliche Eingabe, nur eine Anzeige.

> Die reine Cron-Schreibweise kann für unerfahrene Nutzer mehr Hindernis als
> Hilfsmittel sein.

### 4.1 Der Wunsch trifft auf eine Regel, die dieses Projekt hat

`Cron.vue` übersetzt **mit Absicht** nicht im Browser, und
`CronScheduleFormTest::test_the_page_does_not_translate_on_its_own` hält es:
Den Satz baut `App\Support\Cron\Spoken` auf dem Server, und ihn ein zweites
Mal in TypeScript zu bauen hiesse, dieselbe Regel in zwei Sprachen zu pflegen.

> **Eine Zusammenfügung darf doppelt stehen, eine Regel nicht.**

Die Schnellwahl ist die Ausnahme, und sie ist begründet: Dort **ist** die
Beschriftung der Satz, und der Wächter hält sie gegen `Spoken`.

### 4.2 Drei Wege, und nur einer hat keine zweite Fassung

| | Weg | zweite Fassung? | Kosten |
|---|---|---|---|
| A | `Spoken` in TypeScript nachbauen | **ja** | keine Anfrage, aber zwei Regeln, die auseinanderlaufen |
| B | Der Server antwortet auf die fünf Felder | nein | eine Anfrage je Tipppause |
| C | Nur die Schnellwahl trägt den Satz (heute) | nein | wer von Hand tippt, bekommt nichts |

**Vorgeschlagen ist B.** Eine lesende Route, die die fünf Felder entgegennimmt
und `{ spoken, next }` zurückgibt — den Satz aus `Spoken::schedule()` und die
nächsten Fälligkeiten aus `Occurrence::next()`, in der Anzeigezone des Lesers
(`Clock`). Entprellt, damit nicht jeder Tastendruck fragt.

**Und die Fälligkeiten sind der eigentliche Gewinn.** „Läuft als Nächstes am
21.08.2026 um 03:15, dann am 22.08. um 03:15" beantwortet die Frage eines
Anfängers eindeutiger als jede Prosa — und braucht **keine** Übersetzungsregel,
also auch keine zweite Fassung davon.

### 4.3 Entschieden am 20. August

**Weg B**, und gebaut wird **nach dem Lauf** — beides vom Betreiber bestätigt.
Mitten im Lauf zu ändern hiesse, die Punkte 2 bis 6 gegen zwei verschiedene
Fassungen zu messen.

> **Eine Messung, die zur Hälfte gegen eine andere Fassung lief, ist keine.**

### 4.4 Was beim Bauen zu beachten ist

Aufgeschrieben, solange es frisch ist — nicht, wenn der Lauf vorbei ist und die
Gründe verblasst sind.

- **Die Route ist lesend und trägt dieselbe Policy wie die Seite.** Kein Agent,
  kein Vorgang, keine Zeile im Protokoll — sie rechnet nur.
- **Sie prüft die fünf Felder nicht ein zweites Mal.** `Schedule::parse()` ist
  die Schranke; taugt eine Eingabe nicht, ist die Antwort schlicht „noch kein
  gültiger Zeitplan" und keine Fehlermeldung. Eine zweite Prüfung hier wäre
  dieselbe Regel ein zweites Mal.
- **Die Zeitpunkte gehen durch `Clock`.** Der Zeitplan gilt in Serverzeit, die
  Anzeige in der Zone des Lesers — genau der Unterschied, den der Kasten oben
  auf der Seite erklärt. Wer ihn hier vergisst, zeigt zwei Wahrheiten auf
  derselben Seite.
- **Entprellt, und der Wert gehört gemessen.** `docs/48` hat einmal zwanzig
  Konsolenöffnungen gebraucht, bis eine Entprellung überhaupt als solche
  belegt war:

  > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
  > steht.**

- **Die Anzeige ist keine Eingabe.** Kein Feld, kein `v-model`, nichts, was
  jemand für einen Griff halten kann.
- **Und der bestehende Wächter bleibt, wie er ist.** Die Seite übersetzt weiter
  nicht selbst — sie zeigt nur, was der Server ihr sagt. Kommt beim Bauen die
  Versuchung auf, „nur die einfachen Fälle" im Browser zu rechnen, ist das
  Weg A mit anderem Namen.

