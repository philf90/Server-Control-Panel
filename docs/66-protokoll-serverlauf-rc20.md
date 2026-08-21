# Protokoll des Serverlaufs zu `v0.6.0-rc.20`

**Der Lauf steht in `docs/65`.** Dieses Dokument füllt sich **während** des
Laufs, Punkt für Punkt — nicht danach.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

**Stand: noch nicht gefahren.** Was unten steht, sind die Felder, nicht die
Ergebnisse. Eine Zeile ohne Zahl ist eine offene Zeile und kein erfüllter Punkt.

---

## 0. Der Rahmen

| | |
|---|---|
| Fassung | *(`readlink -f /opt/srvpanel/current`)* |
| Server | |
| Abonnement | |
| Systembenutzer | |
| Browser | |
| Gefahren am | |
| Stand des Messmittels | *(`stand` aus `bilderMessen()`)* |

---

## 1. Die Punkte

Je Punkt: die gemessene Zahl, das Bild, und bei einer Abweichung der Befund mit
dem, was er über den Prüfling **oder über das Prüfmittel** sagt.

| # | Punkt | erwartet | gemessen | |
|---|---|---|---|---|
| 1 | Ein Wurzelelement | `1` | | |
| 2 | Rückmeldungen deutsch | keine Bezeichner | | |
| 3 | Meldung der Experteneingabe | „Im Ausdruck fehlt der 4. Teil (Monat)." | | |
| 4 | Kontingentauskunft oben | `oben` ≈ 18 | | |
| 5 | „Job anlegen" bei 1440 px | Zeitplan in voller Breite | | |
| 6 | Griff zum Formular | springt, Formular leer | | |
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

---

## 3. Was offen bleibt

*(Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.)*

Aus `docs/65 §12` schon vor dem Lauf benannt:

- `PasswordFields.vue generate touched` — ungemessen.
- Die Suche im ganzen Abonnement gibt es nicht.
- Die gestapelten Knopfreihen der anderen Seiten sind nicht gemessen.
- RSA und ECDSA werden nicht erzeugt.
