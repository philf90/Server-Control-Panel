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
| 6 | Griff zum Formular | springt, Formular leer | „Job anlegen" steht in der Kopfzeile | Sprung noch offen |
| 7 | Zielbaum im Bild | `oben` ≥ 0 | | |
| 8 | Schlüssel erzeugen und anmelden | Anmeldung gelingt, Fremdschlüssel abgewiesen | | |
| 9 | Suchleiste | ab 720 px da, Pfad sichtbar, Inhalt übertragen | | |
| 10 | Kopfleiste am Telefon | eine Zeile, vier ganze Wörter | | |
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

