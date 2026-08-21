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
| 3 | Meldung der Experteneingabe | „Im Ausdruck fehlt der 4. Teil (Monat)." | | |
| 4 | Kontingentauskunft oben | `oben` ≈ 18 | | |
| 5 | „Job anlegen" bei 1440 px | Zeitplan in voller Breite | | |
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
