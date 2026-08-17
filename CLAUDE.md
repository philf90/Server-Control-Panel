# SrvPanel — für Claude

Ein Hosting-Panel in der Art von Plesk: Laravel 13, Inertia, Vue 3,
AGPL-3.0-only. Zielplattformen sind Debian 12/13 und Ubuntu 22.04/24.04.

**Der Plan ist `docs/20-hostingpanel-neuplan.md`.** Er ist die Quelle für
Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen
(§9). Wo dieses Dokument und der Plan sich widersprechen, gilt der Plan.

Die Oberfläche folgt seit August 2026 dem Gestaltungssystem **„Kontor"**
(Plan §7.2) — hell entworfen, keine Karten, Monospace nur für Kennungen.

Stand: **P0 bis P5c abgenommen; P6 läuft.** Aus P6 ist **Schritt 8 (SFTP)
abgenommen** — am 17. August 2026 auf `cloudsrv24`, `docs/58` ist der Lauf,
**`docs/59`** das Protokoll; die Zusammenfassung steht weiter unten.

P5 brachte Datenbanken, Zugänge, Sicherungen,
Zurückspielen, Fernzugriff und das Hochladen mitgebrachter Sicherungen
(MariaDB 10.11.14, alle sieben Kriterien aus `docs/36 §17`). **P5b brachte
PostgreSQL** — abgenommen am 11. August 2026 auf `cloudsrv24` gegen
PostgreSQL 16.14: **alle sieben Kriterien aus `docs/38 §3`**, das Protokoll
steht in **`docs/42`**. Die Übergabe dafür war `docs/37`, der Plan `docs/38`.

**P5b ist am 12. August 2026 vollständig abgeschlossen und abgenommen** — mit
dem Fernzugriff (`docs/45`), den fünf Befunden daraus und der Fassungsspanne als
CI-Messung. **Zwei Punkte aus `docs/42 §5` bleiben dabei bewusst offen und sind
nie gemessen worden:** der `template1`-Beleg und die Frage, ob ein Zugang ohne
jede Datenbank überhaupt entstehen kann. Wer sie später anfasst, fängt bei
`docs/42 §5` an und nicht bei null.

**Der Fernzugriff ist abgenommen** — am 11. August 2026 auf `cloudsrv24`, alle
sechzehn Punkte aus `docs/43`. Das Protokoll ist **`docs/45`**. Der Lauf hat
**zwölf Fehler gefunden und keinen davon ein Test**; zwei haben ihn
unterbrochen, bis sie behoben und ausgeliefert waren, und **fünf steckten im
Abnahmelauf selbst.** Der teuerste davon: Die kaputte Zeile aus Punkt 6 — dem
Kern des Laufs — stand *innerhalb* des verwalteten Blocks, und den schreibt das
Panel bei jedem Lauf neu. Der Eingriff war fort, bevor ihn etwas bemerken
konnte, und der Vorgang meldete Erfolg.

> **Ein Eingriff, den der Prüfling selbst überschreibt, prüft nichts.**

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

**Und der zweite Unterbrecher traf jedes Formular dieses Panels, seit es das
Panel gibt:** Laravel merkt sich die vorige Seite nur bei GET-Anfragen, die
nicht als XHR gelten — und jede Inertia-Navigation ist XHR. `_previous.url`
stand nach dem Anmelden dauerhaft auf `/login`, dorthin ging jede
`ValidationException`, und die Meldung sah niemand. `RememberPageUrl` setzt es
jetzt.

> **Ein Wächter über `back()` im eigenen Code sagt nichts über das `back()`, das
> das Framework macht.**

> **Ein Test, der eine Kopfzeile mitschickt, die der Browser nicht schickt,
> prüft eine andere Anwendung.** Fast jeder Formulartest hier benutzt
> `->from()`, und damit funktioniert im Test genau der Weg, den es im Browser
> nicht gibt.

**Und derselbe Satz noch einmal, andersherum — am 13. August, an genau diesem
Wächter.** `PreviousUrlTest` blieb **grün**, als der Bruch `RememberPageUrl` aus
`bootstrap/app.php` strich: Seine Aufrufe trugen `X-Inertia`, aber kein
`X-Requested-With`, und im Browser setzt Inertia beide. Ohne die zweite ist
`ajax()` falsch, und Laravels `StartSession` merkt sich die Seite selbst — der
Weg, dessen Fehlen diese Mittelschicht ausgleicht, war im Test offen.

> **Eine fehlende Kopfzeile prüft eine andere Anwendung genauso wie eine
> überflüssige — nur fällt sie niemandem auf, weil der Test grün ist.**

Gefunden hat es der Lauf des Bruchskripts, und zwar zwei Tage zu spät: Er fährt
wöchentlich. Im selben Lauf lagen zwei Wächter, die es gar nicht mehr gab — der
Umbau vom 11. August hat `PreviousUrlTest` übernommen (gleicher Name, gleiches
Thema, **anderer Gegenstand**) und die beiden Fälle zu `KeepPreviousUrl`
mitgenommen, während die Mittelschicht und ihr Eintrag in `routes/web.php`
stehenblieben. Sie stehen jetzt als `StreamNotAPageTest` da.

> **Zwei Regeln in einer Datei, die nach der einen heisst, verlieren die andere
> beim nächsten Umbau.**

> **Ein Wächter, der die Klasse prüft, hat über die Methode nichts gesagt.**
> `GuardReachTest` fand `PreviousUrlTest` und war zufrieden.
> `BreakScriptTest::test_every_check_names_a_test_that_exists` liest seitdem die
> Zielangabe jeder Prüfung im Skript.

Und einer über die Brüche selbst, aus demselben Schritt:

> **Ein Bruch muss die Regel verletzen und nicht den Code zerstören.** Ein `%`
> statt `%%` in einer Formatzeichenkette macht aus dem Rückfall einen
> `ValueError`: Der Testfall bricht ab, die Klasse meldet „übersprungen" statt
> „rot", und der Wächter war nie rot — er ist gar nicht erst dazu gekommen.

**Und der Abnahmelauf des Fernzugriffs hat das Panel abgeschaltet — an der
Stelle, an der er es vorschrieb.** `docs/43` Punkt 3 lautete `srvpanel db
--remote=on --bind=::`; danach gab jede Seite einen 500er. **MariaDB bindet `::`
ausschliesslich IPv6** (gemessen, 10.11.14), das Panel verbindet über
`127.0.0.1`, und im Quelltext stand seit P5 das Gegenteil. Das Protokoll ist
**`docs/44`**, mit den drei weiteren Fehlern, die daran hingen: eine Gegenprobe
über den Unix-Socket, die einen kaputten TCP-Weg nicht sehen kann; ein
`--remote=off`, das an der Bestandsabfrage starb, bevor es zum Agenten kam; und
ein `catch (Throwable) { return []; }`, das aus „nicht erreichbar" ein „der
Betreiber bietet es nicht an" machte. Die vier Sätze dazu:

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

> **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den falschen
> Weg.**

> **Ein Rückweg, der den Bestand braucht, ist keiner für den Fall, dass der
> Bestand weg ist.**

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

**Der Lauf hat sechs Fehler gefunden, und keinen davon ein Test** — vier fand
ein echter Server, zwei eine Messung im Browser bei 390px. Die drei teuersten
hingen aneinander, und das ist die Lehre dieser Stufe:

> **Ein Fehlerweg, der selbst fehlschlagen kann, ist kein Fehlerweg.**

Die Begründung eines abgewiesenen Dumps — die einzige Auskunft, an der ein
Abnahmekriterium hing — passte nicht in ihre Spalte (`varchar(255)`). Die
`PDOException` riss genau den `catch`-Zweig mit, der den Fehlschlag festhalten
sollte, und der Vorgang bekam vom Warteschlangen-Handler *„vermutlich
Zeitüberschreitung"* nach einer Sekunde Laufzeit. **Je wichtiger die Begründung,
desto länger ist sie** — „Datei nicht gefunden" passte immer. Kaum kam sie an,
schob sie die Seite bei 390px um 110px aus dem Bild, und der Fix am Rückbau
erzeugte einen zweiten Rest, den nur die inzwischen lesbare Meldung erklärte.

Zwei weitere Sätze aus demselben Lauf, beide über Vorgänge, die „fertig"
meldeten:

> **Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die
> anderen Vorgänge derselben Reihe nicht.**

> **Eine Reihenfolge, die erst beim Ausführen entsteht, kann beim Einreihen
> niemand kennen.**

Und zwei über die Wächter selbst, die beinahe ihren eigenen Fehler verfehlt
hätten:

> **Ein Test, der gegen eine andere Datenbank läuft als der Server, prüft die
> Grenzen der falschen.** Diese Tests laufen gegen SQLite, der Server gegen
> MariaDB — eine `varchar(255)` nimmt dort jede Länge.

> **Ein Abnahmeschritt, der zwei Fälle vergleicht, wird sinnlos, wenn der Bau
> einen davon abgeschafft hat — und er merkt es nicht.**

**`docs/40` ist gebaut** (die Anzeigezeitzone): `App\Support\Time\Clock` ist die
einzige Stelle, die aus UTC eine Anzeige macht und eine Filtergrenze zurück;
achtzehn Lesestellen gehen darüber, das Protokoll mitsamt seinem Filter, und der
CSV-Export bleibt UTC mit der Zone in der Kopfzeile. Die Einstellung sitzt auf
der neuen Seite **„Allgemein"** — den Ort gab es vorher nicht.

> **Eine umgestellte Anzeige ohne mitrechnenden Filter zeigt eine Zeile und
> findet sie nicht.** Das ist die Hälfte, die still bricht; sie hat den
> Grenzfalltest an *beiden* Enden des Tages bekommen, weil die Richtung am
> Vorzeichen des Versatzes hängt.

**Schritt 10 aus `docs/38 §17` ist gebaut** (der Fernzugriff): `pg.remote.access`
schreibt `listen_addresses` nach `conf.d/60-srvpanel.conf` und den verwalteten
Block nach `pg_hba.conf`, die Netze eines Zugangs stehen in `db_user_networks`,
und `srvpanel db --remote=on` schaltet beide Systeme. **Abgenommen ist er seit
dem 11. August 2026** — der Lauf stand in `docs/43`, das Protokoll ist
`docs/45`. Was aus `docs/42 §5` offen blieb, steht dort benannt; zwei Punkte
sind es geblieben.

**Und der Fund dieses Schritts stand in keinem Plan:** In `pg_hba.conf` schrieb
schon jemand — `Hba::ensure()` seit Schritt 6 —, und der **Rückweg** des
Fernzugriffs warf dessen Zeile weg. Der Griff, der den Server vor einer kaputten
Datei retten soll, beschädigt sie also selbst, und zwar unsichtbar: Erst das
nächste Zurückspielen scheitert, Wochen später, an einer Meldung über
peer-Authentifizierung. Gefunden hat das kein Nachdenken, sondern ein
Wegwerf-Cluster.

> **Ein zweiter Schreiber in derselben Datei ist kein zweiter Schreiber, solange
> nur einer die Sperre nimmt.**

Zwei weitere Sätze aus demselben Lauf. Der erste, weil `flock` je *offener
Datei* sperrt und nicht je Prozess — die verschachtelte Sperre wartete auf sich
selbst, ohne Fehler und ohne Meldung:

> **Eine Sperre, die man zweimal nimmt, ist ein Stillstand ohne Fehlermeldung.**

Der zweite, weil die Messung, die seit `v0.4.0-rc.4` jede Aufnahme begleitet,
hier auf 0 stand, während das Formular abgeschnitten war:

> **Eine Zahl, die am Dokument misst, sagt nichts über eine Zelle, die selbst
> scrollen darf.**

**Und die eine Frage, die `docs/37 §3` vor dem Planen verlangt hat, ist
gemessen worden — sie hat das Abnahmekriterium umgeworfen.** „Ein
Datenbankbenutzer kann die Namen fremder Datenbanken nicht aufzählen" ist in
PostgreSQL nicht erfüllbar: Der Verbindungsaufbau verrät die Existenz, und der
Entzug von `pg_database` nähme dem **Kunden** `pg_dump`. Dazu elf weitere
lesbare Katalogsichten, die Namen führen, und eine Absperrung, die bei
`TEMPLATE template0` lautlos zurückfällt. Das neue Kriterium steht in
`docs/38 §3`. Daraus die Lehre neben der aus P4:

> **Wissen aus zweiter Hand sieht aus wie Wissen.** Der Satz „`pg_database` ist
> für jeden lesbar" stimmte — und war trotzdem die falsche Frage, weil er einen
> von elf Kanälen nannte und den Preis der Antwort verschwieg.

Ausgeliefert wurde für P5c `v0.5.3-rc.14`; der Stand von P6 ist
`v0.6.0-rc.11`.

**Und der Bildschirmfoto-Durchgang zu Schritt 4 hat zwei Fehler gefunden, die
grün waren** (`docs/46 §20.11`). Der erste schob die Seite bei 390px um **99px**
aus dem Bild: Ein Bereichstitel trug einen Tabellennamen, und der darf 63 Zeichen
lang sein. Der zweite war **überhaupt nicht messbar** — Spalten- und Indextabelle
standen ohne Abstand und ohne Beschriftung untereinander und lasen sich als eine.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt. Keines
> von beiden ersetzt das andere.**

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

Der erste ist die **dritte** Fassung derselben Ausnahme nach `.ident` und
`.stacks td .ident`: `overflow-wrap: anywhere` **mit** `min-width: 0`, weil ein
Flexkind sonst nicht unter seine Inhaltsbreite darf.
`MobileLayoutTest::test_a_section_heading_can_break` rechnet sie seitdem nach.

**Und Schritt 5 hat den umgekehrten Fall gebracht** (`docs/46 §20.13`): Die
Messung war grün — `dokument: 0px`, der Rollbehälter rollte wie gewollt —, und
die Ansicht war trotzdem kaputt. Eine bei 512 Zeichen gekürzte Textzelle machte
den Inhalt der Zeilentabelle **5710px** breit statt 1907; bei 390px sind das
zehn Bildschirme Rollen durch eine einzige Zelle.

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.**

Die Ursache war `td .ident { white-space: nowrap }` — eine Regel mit einer
Begründung, die für Kennungen stimmt und für Werte nicht. Derselbe Schnitt wie
bei `psql -A -t`, nur im Browser statt am Server:

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.**

Und die Blätterleiste schob dabei 8px, an einer Stelle, die es seit `v0.4.0`
gibt: `.pager-state` trug `nowrap`, richtig für „Seite 2 von 5" und falsch für
„1.001–1.050 von mehr als 1.050".

> **Ein `nowrap` über einer Zahl, die wächst, ist keine Zusage über die Zeile —
> es ist eine über den Bestand.**

**Und der Plan hat einen Schritt dazubekommen: 5b, die Baumansicht** (§11.1,
§13). Der Betreiber hat gefragt, ob sich Tabellen und Struktur als aufklappbarer
Baum zeigen lassen; ich hatte erwartet, dass das bei 390px an der Einrückung
scheitert, und **gemessen ist das Gegenteil.** Der waagerechte Überlauf ist in
jedem Entwurf 0px — entschieden wird die Frage senkrecht: zwanzig Tabellen,
zugeklappt, **4992px als gestapelte Tabelle gegen 964px als Baum**. Bei 1440px
nehmen sich die beiden nichts (881 gegen 803).

> **Der Baum löst kein Navigationsproblem. Er löst ein Telefonproblem.**

Der Grund steht in `docs/24 §5`: `.stacks` ist für ein **Verzeichnis** gedacht,
das man Zeile für Zeile liest. Eine Tabellenliste sucht man nach *einem* Namen
ab, und dafür ist das Kärtchen die falsche Form.

**P5c ist abgeschlossen** — abgenommen am 14. August 2026 auf `cloudsrv24` gegen
`v0.5.3-rc.13`, **alle sieben Kriterien aus `docs/46 §4`**, beide Systeme; die
vier Befunde am Panel behoben und gegen `v0.5.3-rc.14` im Browser nachgeprüft
(`docs/48 §4`). Das Protokoll ist **`docs/48`**. `docs/46` ist der Plan: Tabellen und Struktur
durchsehen, Zeilen ansehen, filtern, blättern und ändern.

**Der Lauf hat zwölf Befunde gebracht und keinen davon ein Test** — **sieben über
den Abnahmelauf selbst**, vier über das Panel, einer über den Aufbau. Dasselbe
Verhältnis wie bei `docs/45` und `docs/47`: Die Mehrheit der Fehler steckt nicht
im Prüfling, sondern im Prüfmittel. Die vier am Panel sind behoben, jeder mit
einem Wächter — `CellWhitespaceTest`, `CountedNounTest`, `ViewStateTest`,
`AnnounceWithdrawalTest`.

Drei davon sind derselbe Satz aus drei Richtungen:

> **Eine Anzeige, die drei verschiedene Werte gleich aussehen lässt, behauptet
> etwas, das sie nicht weiss.** `a\tb`, `a  b` und `a b` ergaben exakt dieselben
> 25×16 Pixel. Nach dem Wortlaut von Kriterium 2 war das erfüllt — der Umbruch
> blieb *innerhalb* der Zelle. Das ist der Unterschied zwischen „der Lauf ist
> abgenommen" und „die Anzeige stimmt".

> **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als wäre
> sie durchgelaufen.** Nach der Zeitüberschreitung stand die Kopfzeile auf
> `wert ↑`, während darunter die nach `id` sortierte Seite lag. Es waren **fünf**
> Griffe und nicht nur die Sortierung.

> **Ein Fehler, der an drei Stellen unabhängig gemacht wurde, ist keine
> Unachtsamkeit, sondern eine fehlende Stelle.** „geschätzt 1 Zeilen" stand auch
> im Protokoll und in der Planvorlage, beide seit P2.

Und zwei über die Werkzeuge, die diese Fehler finden sollten:

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Zweimal im selben Lauf — bei Punkt 3 (a) und bei der Entprellung, wo
> zwanzig Konsolenöffnungen erst durch einen Zähler auf einem anderen Kanal
> belegt waren.

> **Eine Vorgabe für Quelltext, die alles erbt, gilt irgendwann für Daten.**
> `tab-size: 4` steht in Tailwinds Reset und machte aus einem Tabulator ein
> breiteres Leerzeichen.

Vier Entscheidungen des Betreibers tragen den Plan, und die zweite hat die
Architektur entschieden: **kein freies SQL.** Damit bekommt der Agent typisierte
Fragen und keine Anweisung, und die erste Grenze gilt wörtlich statt dem Sinne
nach. Der Plan fügt **keinen neuen Weg mit Rechten** hinzu — er benutzt den
befristeten Zugang, unter dem seit P5 mitgebrachte Dumps laufen.

Drei Messungen haben den Entwurf vor der ersten Zeile umgeworfen. Die teuerste
betrifft eine Form, die seit P5 in jeder Antwort steckt:

> **Ein Format, das für Bezeichner reicht, reicht nicht für Werte.** `psql -A -t
> -F'\t'` gibt `NULL` und `''` beide als leeres Feld aus, macht aus einem
> Tabulator im Wert eine Spalte und aus einem Zeilenumbruch eine Zeile. Für
> Katalogfragen ist das richtig und hat zwei Stufen getragen.

> **Was der Geprüfte selbst zurücknehmen kann, ist keine Schranke, sondern eine
> Voreinstellung.** `RESET ROLE` hebt ein `SET ROLE` auf, `SET TRANSACTION READ
> WRITE` ein `BEGIN READ ONLY`, und `SET statement_timeout = 0` auch ein
> `ALTER ROLE … SET`. Alle drei gemessen.

**Schritt 0 ist gefahren** (12. August, `docs/46 §2.3`, N1–N12) — und er hat die
beiden Funde gebracht, die §8 tragen. `mysql --batch` maskiert die Maskierung
einer JSON-Zeile: Aus `"a\tb"` wird `"a\\tb"`, und das ist **gültiges JSON mit
einem falschen Wert**. Und ein `BLOB` mit ungültigem UTF-8 macht die **ganze
Zeile** über `json_decode()` unlesbar, während MariaDBs `JSON_VALID()` sie für
gültig hält.

> **Eine Maskierung über einer Maskierung ist schlimmer als ein Parserfehler.**
> Der fiele auf.

> **Eine Gültigkeitsprüfung des einen Systems sagt nichts über den Leser im
> anderen.**

**Auch der Abnahmelauf von P4 hat sechs Fehler gefunden, und keinen davon ein
Test.** Drei betrafen ein Kriterium, drei die Bedienung. Der teuerste sah aus wie ein Erfolg:
Die Erneuerung meldete `1 fällig, 1 bestellt` — genau die Zahl, die das
Kriterium verlangt — und bestellte ein **gewöhnliches** Zertifikat statt eines
Platzhalters. Gefunden hat es der Betreiber, weil er nach der grünen Meldung die
Seriennummern verglichen hat. Daraus die Lehre, die über TLS hinausgeht:

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

Die anderen fünf folgen demselben Muster wie schon der Wurf davor: eine
Bedingung, die an einer *Absicht* hängt statt an einem *Zustand* (das
Platzhalter-Kästchen, der Hinweis auf ungedeckte Namen), eine Prüfung, die eine
Zeile zu spät läuft (die Kettenreihenfolge), eine zweite Fassung derselben Regel
(`srvpanel dns` kannte nur RFC 2136) und ein Bedienelement ohne sichtbare
Beschriftung.

**Die Abnahme davor hing an den Screenshots**, und sie haben sich gelohnt: drei
Fehler auf einer Seite, die vollständig grün getestet war — eine Kennung, die im
Fliesstext nicht brach und die Seite um 83px aus dem Bildschirm schob, ein
Abstand, der aus der Reihenfolge der Seite abgeleitet war und mit der nächsten
Ergänzung fiel, und ein `<select>`, das abschneidet statt umzubrechen. Das ist
der Grund für die Regel weiter unten, und `v0.4.0-rc.4` ist mit dem
Umbruchfehler ausgeliefert worden, weil sie einen Tag zu früh kam.

---

## P6 Schritt 8 — der SFTP-Zugang, abgenommen am 17. August 2026

Der Lauf ist `docs/58`, das Protokoll **`docs/59`**, gefahren auf `cloudsrv24` in
**zwei Durchgängen**: der erste gegen `v0.6.0-rc.10` bis Punkt 11, der zweite
gegen `v0.6.0-rc.11` für die elf Korrekturen daraus und für die Bilder. Alle
dreizehn Punkte erfüllt bis auf eine bewusst offen gelassene Wand (ein
Zusatzbenutzer ohne `ftp_accounts`). Die Grundlagen stehen in `docs/50` (die
Messungen zu OpenSSH) und `docs/57` (die Übergabe).

**Zweiundzwanzig Befunde, und keinen davon hat ein Test gefunden** — fünfzehn am
Panel, fünf am Prüfmittel, zwei am Kriterium. Dasselbe Verhältnis wie in
`docs/45`, `docs/47` und `docs/48`: Die Mehrheit steckt nicht im Prüfling.

**Was daraus über SFTP hinausgilt.**

Der erste Satz kehrt das Vorbild aus P5b um: Dort trägt „schreiben, neu laden, bei
einem Fehler zurückrollen", weil PostgreSQL mit einer kaputten Datei weiterbedient
— **der sshd stirbt daran**. Der Satz dazu steht unten unter „Diese Umgebung",
an der Stelle, an der er gemessen wurde; er wird hier nicht wiederholt, weil zwei
Fassungen derselben Regel auseinanderlaufen.

Der zweite ist der teuerste Fehler dieses Laufs, und er war kein Fehler im Code,
sondern im Hinsehen: Neun Meldungen des Agenten standen in Markdown, `**.pub**`
also wörtlich im Satz — und ich habe darüber in **vier** Aufnahmen hinweggelesen,
weil jede zu einer anderen Frage angesehen wurde.

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.**

Der dritte trifft jede künftige Überlaufmessung. Der Prüfkörper der Gegenprobe
war ein fester Block von 900px: bei 390px die erwarteten 510, bei 1440px eine
`0` — also derselbe Wert, den auch eine kaputte Messung liefert. Er gehört an das
Fenster gebunden (`clientWidth + 200`), und die berichtigte Vorschrift steht in
`docs/58 §12`.

> **Eine Gegenprobe, deren Ausschlag von der Breite abhängt, ist bei der grösseren
> Breite keine.**

Und zwei über Auskünfte, die es gab und die niemand trug — **zweimal derselbe
Fehler in einem Lauf, an zwei Übergängen desselben Weges** (Agent → Controller,
Controller → Seite):

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.**

> **Ein roter Rand am Feld behauptet, das Feld sei falsch. Wer ihn für einen
> Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern
> ist.**

**Und der Menüpunkt, den kein Kriterium bestellt hat.** Der SFTP-Zugang lag drei
Klicks tief — genau dort, wo der Dateimanager vor `docs/55` Befund 8 lag. Gemeldet
hat es der Betreiber während des Laufs; keiner der 136 Wächter hätte es gefunden,
weil die Frage daran hängt, was ein Kunde *sucht*.

> **Ein Fehler, den man an einer Stelle behoben hat, ist beim nächsten Merkmal
> wieder da, wenn die Behebung nicht die Regel wurde.**

**Was offen bleibt und benannt ist:** Wand 2 aus Punkt 11, die vier Zeilen zu den
Befunden 20 und 21 gegen die nächste Fassung, und Befund 22 — der Prüfkörper hat
**keinen Wächter**, weil die Messvorschrift in einem Dokument steht und kein Test
sie liest. Wer sie anfasst, fängt bei `docs/59` an und nicht bei null.

---

## Die eine Gewohnheit, die dieses Projekt trägt

**Für jede Regel gibt es einen Wächter, und der Wächter wird gegengeprüft.**

Der Fehler, der hier immer wiederkehrt, ist derselbe: *eine Zeichenkette, die
auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
prüft.* Eine Policy ohne Route. Ein Kommando, das im Startskript fehlt. Ein
Verzeichnisname, der umbenannt wurde. Ein Zertifikat mit dem falschen Namen.
Ein Favicon mit null Byte. Er ist mindestens sechsmal aufgetreten und jedes Mal
teuer gewesen, weil nichts ihn meldet.

Deshalb: Wer eine Regel aufstellt, baut den Test dazu — und **bricht die Regel
danach absichtlich, um zu sehen, dass der Test zubeißt.** Ein Wächter, der nie
rot war, ist kein Wächter.

Vorhandene Wächter, an denen man sich orientieren kann:
`RouteAuthorizationTest`, `PolicyReachTest`, `ButtonStyleTest`, `IconTest`,
`ThemeTest`, `InertiaPagesTest`, `PackagingTest`, `WordChoiceTest`,
`MobileLayoutTest`, `DesignTokensTest` — aus P3
`AgentOperationReachTest` (jeder Operationsname zeigt auf eine Operation, die
es gibt), `EngineReachTest` (was das eine Datenbanksystem kann, kann das andere
auch — oder ein begründeter Eintrag sagt warum nicht), `LifecycleReachTest`, `AnchoredPatternTest`, `PhpVersionCatalogTest`,
`DirectiveAllowlistTest`, `PhpIsolationTest` — und aus dem Optik-Rework
`ClassReachTest` (jede Klasse in einem Template zeigt auf eine Regel, die es
gibt), `TableStyleTest`, `ClassNameTest` (jeder Klassenname ist englisch, und
jede Regel in app.css wird von einem Template erreicht) und `PaginationTest`
(wer paginiert, lässt auch blättern) — dazu `RedirectTargetTest` (wer
weiterleitet, nennt das Ziel; `back()` kennt es hier nicht) und
`PairedSeriesTest` (zwei Kurven in einem Feld teilen sich die Achse) und
`HostnameSourceTest` (nur `Names` fragt den Kernel nach dem Rechnernamen) und
`AbilityReachTest` (ein Knopf, den der Betrachter nicht drücken darf, wird nicht
gezeigt), `NavIconTest` (jeder Menüpunkt trägt ein Zeichen, und jedes Zeichen ist
gezeichnet), `FieldErrorTest` (ein Fehler steht einmal auf der Seite — und wo
ein Feld rot werden kann, steht auch die Zusammenfassung, die sagt warum) und `SparklineShapeTest` (in einem ungleich gezogenen Feld wird
nichts Rundes in Nutzerkoordinaten gezeichnet). Der Bruch selbst steht als
`tests/waechter-brechen.sh` im Repo: Er bricht jede Regel der Reihe nach und
prüft, dass ihr Wächter zubeisst.

**Die Falle, in die dieses Vorgehen selbst dreimal gelaufen ist.** Ein Wächter
zählt seine Treffer, damit er merkt, wenn sein Ausdruck ins Leere läuft — und
zählt sie dort, wo die Regel gerade eingehalten wird. Zieht die Regel um, steht
der Zähler auf null, und der Wächter meldet Rot für genau die Ordnung, die er
durchsetzen soll: *Ein Wächter, der beim Aufräumen zubeisst, wird beim
Aufräumen abgeschaltet.* Passiert bei `MobileLayoutTest`, bei
`DesignTokensTest::test_every_step_of_the_scale_is_used` und bei drei Tests des
Optik-Reworks. Die Untergrenze zählt seitdem überall mit, wo die Regel stehen
*darf*; der Befund kommt weiter nur von dort, wo sie stehen *soll*.

**Und ein zweites Muster, das der Umbau aus `docs/35` freigelegt hat: eine
Ressource, die sich anlegen, aber nirgends löschen lässt.** Zertifikate konnte
dieses System bestellen, hochladen und erneuern — ein `remove` gab es weder im
Panel noch im Agenten. Jedes zurückgebaute Abonnement liess seinen privaten
Schlüssel unter `/etc/srvpanel/tls/certs` liegen, und zwar seit es
Kundenzertifikate gibt. Gemerkt hat es niemand, weil ein Grabstein die Zeile am
Leben hielt: Solange etwas auf den Rest zeigt, sieht der Rest nicht aus wie
einer. Aufgefallen ist es erst, als eine Migration danach *fragte* — und dann
gleich zwölfmal. Wer etwas anlegt, das auf der Platte bleibt, baut den Weg
zurück mit; sonst findet ihn Jahre später eine Datenmigration.

**Drei Funde aus P3, die keiner Regel widersprachen, sondern eine fehlende
zeigten:** `$` passt in PCRE auch vor einem abschliessenden Zeilenumbruch (neun
Muster betroffen, vier davon aus P0–P2); zwei fertig gebaute Agent-Operationen
wurden von nichts aufgerufen; eine Knopfklasse zeigte auf eine CSS-Regel, die
es nicht gibt. Jeder Fund hat seinen Wächter bekommen.

**Und: im Browser nachsehen, nicht nur bauen.** Drei Fehler dieser Woche waren
grün getestet und trotzdem falsch — ein Knopfrand mit 1,04:1 Kontrast, ein
Umschalter, der sichtbar nichts tat, eine doppelte Erfolgsmeldung. Bei allem
Sichtbaren gehört ein Screenshot dazu, in beiden Themes und bei 390 px.

**Und wenn es gerade nicht geht, wird es nachgeholt und nicht abgehakt.** P4
Schritt 6 ging ohne Screenshots in die Auslieferung, weil `vendor/` fehlte —
und die nachgeholte Runde fand auf einer einzigen Seite drei Fehler, jeden
davon vollständig grün getestet. Der teuerste war eine Kennung im Fliesstext,
die 83px aus dem Bildschirm schob; er war ausgeliefert. **Für den Fall, dass
`artisan serve` nicht läuft, gibt es einen Weg, der eine Fassung früher
gereicht hätte:** das gebaute Stylesheet aus `public/build` mit dem Markup des
fraglichen Bausteins in einer eigenen HTML-Datei, gerendert im vorinstallierten
Chromium bei 390px, `scrollWidth - clientWidth` per `<script>` als Text auf die
Seite geschrieben. Das ersetzt den Blick auf die echte Seite nicht — aber es
beantwortet die Frage, ob etwas überläuft, ohne Datenbank und ohne PHP.

---

## Architektur — die drei Grenzen

**1. Der Agent ist die einzige Stelle mit Systemrechten.** `agent/` ist ein
framework- und abhängigkeitsfreies PHP-CLI hinter einem Unix-Socket; die
Anwendung schickt typisierte Operationen, niemals Text, der zu einer
Kommandozeile oder Konfigurationsdatei wird. Programme stehen auf einer
Positivliste mit absoluten Pfaden. **Nichts Privilegiertes gehört in
`app/`** — auch nicht „nur kurz". Siehe Plan §4.1 und §4.2.

**2. Zustände folgen dem Agenten, nicht dem Klick.** Ein Vorgang ändert den
Zustand erst, *nachdem* der Agent geantwortet hat (`Lifecycle::afterSuccess()`
aus `RunAgentOperation`). `Operation.type` geht an den Agenten,
`Operation.task` steuert den Lebenslauf.

**3. Die Mandantenklammer verweigert im Grundzustand alles.**
`app/Support/Tenancy/Tenancy.php` klammert Abfragen auf `whereRaw('0 = 1')`,
solange niemand etwas anderes sagt. `withoutRestriction()` ist die
ausdrückliche Ausnahme und will begründet sein; Admins sind über
`forAccount()` unbeschränkt. **Autorisierung sitzt an der Aktion**, nicht im
Menü — jede Route trägt `can:` oder steht mit Begründung in
`app/Support/Authorization/RouteGuard.php`.

Das ist die Regel fürs **Durchsetzen** und war nie eine Erlaubnis, jedem alles
anzubieten. Die Kehrseite: **Wer eine Aktion zeigt, fragt vorher dieselbe
Policy, die sie später abweist.** Die Antwort kommt als `can`-Ablage im
Inertia-Payload — nicht als `v-if` auf den Kontotyp, denn das wäre eine zweite
Fassung der Policy, und die zweite Fassung ist die, die veraltet.
`AbilityReachTest` prüft beide Richtungen.

Weiteres, das man wissen muss: Weiche Löschungen verbrauchen Bezeichner —
**für Kunden.** Ihre Nummer steht in Rechnungen, an ihrem Grabstein hängen die
Konten, und die Anmeldung liest ihn. Für Abonnements galt dasselbe bis August
2026; seit `docs/35` steht die Reservierung des Systembenutzers in
`system_users`, und ein zurückgebautes Abonnement wird hart gelöscht.
`Lifecycle::claim()` verbraucht einen Namen, `nextSystemUser()` zeigt ihn nur
an — wer die beiden verwechselt, vergibt `p1000` zweimal.
Agent-Klassen sind aus der Anwendung autoladbar (`SrvPanel\Agent\` →
`agent/src/`), das Panel darf also `Names::fqdn()` direkt fragen.

---

## Sprache und Gestaltung

**`docs/19-sprache-der-oberflaeche.md` ist bindend** und wird von
`WordChoiceTest` geprüft — **und seit dem 12. August auch der Ort einer
Rückmeldung (§6):** Der Satz eines Fehlers steht oben in der Zusammenfassung,
das Feld trägt nur `aria-invalid`, und Erfolg wird **nie** am Feld gemeldet.
`FieldErrorTest` prüft alle drei Richtungen. Kurz:

- **Kommentare, Dokumentation und alle Texte der Oberfläche: deutsch.
  Bezeichner: englisch** (§4a) — das schliesst CSS-Klassen, Datenattribute,
  Komponentennamen und ihre Eigenschaften ein und wird von `ClassNameTest`
  gegen eine Wortliste geprüft. Eine echte Schnittstelle, die eine Migration
  kosten würde, bleibt wie sie ist.
- Keine Emoji in der Oberfläche (§3a) — sie sehen auf jedem System anders aus
  und nehmen keine Textfarbe an.
- Kommentare erklären **warum**, nicht was. Der wertvollste Kommentar hält
  fest, was schiefging und weshalb es jetzt anders ist.

**Jede Farbe kommt aus `resources/css/app.css`** (Plan §7.2). Ein Hexwert in
einer Komponente ist ein Fehler und keine Ausnahme; die CI prüft das. Beide
Themes entstehen zusammen, nie eines nachträglich — **hell ist die
Ausgangsfassung**, seit „Kontor" das dunkle „Leitstand" abgelöst hat. Und die
Form jedes Bausteins steht ebenfalls dort: Tabelle, Feld, Marke, Balken,
Meldung, Schalter. Eine Komponente, die ihr eigenes `input` oder `table`
gestaltet, ist derselbe Fehler wie ein Hexwert. Kontrast wird gerechnet,
nicht geschätzt: 4,5:1 für Text, **3:1 für die Grenze eines Bedienelements**
(WCAG 1.4.11). Das Aussehen eines Knopfes steht ausschliesslich in `app.css`
— `ButtonStyleTest` besteht darauf.

Weitere Dokumente: `21` Signaturschlüssel · `22` Passwörter · `23` Pläne und
Kontingente · `24` mobile Ansicht · `25` Mailversand · `26` Abonnements ·
`27` Zertifikat · `28` Web und PHP · **`32` Übergabe an P4** — was für TLS
schon dasteht, was fehlt, und die Falle, die dabei aussperrt — · **`33` der
Abnahmelauf für 0.3.1**, der davor kommt · und **`34` der zweite Wurf von P4**:
DNS-01, Platzhalter, eigene Zertifikate — mit den drei Stellen, an denen der
erste Wurf der Erweiterung nicht standhält, und den vier Fragen, die der
Betreiber vorher beantwortet · und **`35` das Verzeichnis der Systembenutzer** —
**abgenommen am 7. August 2026**: Abonnements werden hart gelöscht, die
verbrauchten Namen stehen in `system_users`. **§9 nennt drei Stellen, an denen
der Plan nicht trug**, §10 die Befehlsfolge, **§11 den Abbruch des ersten
Anlaufs** — ein Zertifikat liess sich in diesem System nicht löschen — und §12
die Messwerte. Die Entwürfe zum Gestaltungssystem stehen
unter `docs/entwuerfe/`: `20` die Wahl von 2026 („Leitstand"), `29` der erste
Rework-Plan, `30` die zwei neuen Richtungen, `31` das bediente Muster zu
„Kontor".

Und aus P5: **`36` Datenbanken** — der Plan, die sieben Abnahmekriterien als
Befehlsfolge (§17), die Entscheidungen des Betreibers (§19) und ein langes
Protokoll dessen, was beim Bauen anders war als im Plan (§22.3a–§22.3x) — sowie
**`37` die Übergabe an P5b** und **`38` der Plan von P5b**: §2 die Messungen,
die vor dem Plan kamen, §3 das neu gefasste Abnahmekriterium, §19 die
Befehlsfolge dazu — und **`39` die Zwischenabnahme**, neun Punkte auf einem
echten Server gegen `v0.5.1-rc.2`, weil die Schritte 4 bis 7 nur gegen einen
Wegwerf-Cluster im Container gemessen sind. Sie steht dort, weil sie zuerst nur
in einem Sitzungsverlauf stand: **Was man zweimal braucht, gehört ins Repo —
auch wenn es keine Zeile Code ist.** Aus demselben Grund gibt es **`40` die
Zeitzone der Anzeige**: entschieden am 10. August, gebaut nach P5b — und
**`42` die Abnahme von P5b**: die sieben Kriterien mit ihren gemessenen Werten,
die sechs Fehler mit ihren Lehren, die drei Stellen, an denen der Plan nicht
trug, und in §5 das, was offen blieb. Das Panel
zeigt Zeiten in UTC, und der Betreiber liest sie auf einer Uhr, die zwei Stunden
weiter ist. Und **`43` die Zwischenabnahme des Fernzugriffs** — der Lauf für `cloudsrv24`,
zwölf Punkte, mit der Fassungstabelle in §3 und dem, was er ausdrücklich nicht
prüft. Und **`41` die Dateisystem-Quota** — wie sie eingeschaltet wird, und
warum das Panel den *Leseversuch* misst statt der Mount-Option: Auf `cloudsrv24`
stand `usrquota` in den Optionen und `quotaon -p /` sagte `is off`. Und **`46`
das Datenbankmanagement (P5c)** — geplant am 12. August 2026, Schritte 0 bis 3
gebaut: §2 die dreiundzwanzig Messungen, die vor dem Plan kamen, §2.3 die zwölf,
die am 12. August für MariaDB nachgeholt wurden, §3 die vier Entscheidungen des
Betreibers, §4 das Abnahmekriterium mit sieben Punkten, §15 die Befehlsfolge
dazu, **§16 was P5c ausdrücklich nicht wird** und §20 was beim Bauen anders war
als im Plan. Und **`47` die Zwischenabnahme von P5c** — der Lauf für
`cloudsrv24` nach Schritt 3, sechzehn Punkte **ohne ein einziges Bild**, weil
die Oberfläche noch nicht existiert. Sein Punkt 1 ist Risiko 8 und **gehört vor
das Update**: Die Form einer befristeten Kennung ist von `r` auf `[rc]`
erweitert worden und gilt rückwirkend nicht — ein Kundenzugang, der heute
`<präfix>_c` plus acht Hexziffern heisst, verschwindet ab dieser Fassung aus der
Zugangsliste seiner Datenbank und wird gleichzeitig als Rest gemeldet.

Und aus P6, der Reihe nach: **`49` die Übergabe an P6** · **`50` die Messrunde
davor** — was die Systeme wirklich tun, gemessen statt nachgelesen · **`51` der
Plan** (Dateien, Zugänge, Cron; **§4 ist der Angriffsdurchgang**, und der ist
Schritt 11) · **`52`/`53`** die Zwischenabnahme von P6 mit ihrem Protokoll ·
**`54`/`55`** der Prüflauf des Dateimanagers mit seinem Protokoll — dort ist
Befund 8 der Menüpunkt, der in Schritt 8 als Befund 19 wiederkam · **`56` die
Nachprüfung der mobilen Ansicht**, deren Punkt 5 belegt hat, dass der Messaufsatz
dieses Containers aufs Pixel stimmt · **`57` die Messrunde vor Schritt 8** ·
**`58` der Lauf für den SFTP-Zugang** — dreizehn Punkte, mit der berichtigten
Messvorschrift in §12 — und **`59` das Protokoll dazu**: zweiundzwanzig Befunde
mit ihren Lehren, die beiden Durchgänge Punkt für Punkt und am Ende, was offen
bleibt.

**Der Lauf ist gefahren — am 12. August 2026, gegen `0.5.3-rc.1` und ab Punkt 7
gegen `0.5.3-rc.2`.** Sechs Kriterien erfüllt, das siebte (das Protokoll) benannt
offen, **sechs Befunde und keinen davon ein Test.** Drei betrafen den Code, drei
den Abnahmelauf selbst — dasselbe Verhältnis wie beim Fernzugriff.

**Der teuerste hat den Lauf unterbrochen und trifft jede deutsche
Kundendatenbank:** `Db\Session` rief `mysql` ohne `--default-character-set`.
Unter dem `LC_ALL=C` aus `Runner::ENVIRONMENT` — richtig gesetzt, seit P0, damit
Zahlenformate stabil bleiben — handelt der Klient **latin1** aus, der Server
konvertiert `JSON_OBJECT()` am Ausgang, und aus `ü` wird das einzelne Byte `FC`.
`json_decode()` gibt `null` zurück, und damit ist nicht die Zelle unlesbar,
sondern **die ganze Zeile**. Die Argumentliste stand dabei zweimal da, in `run()`
und in `linesAs()`; sie steht jetzt einmal als `Db\Session::CLIENT`.

> **Zwei Systeme unter derselben Umgebung treffen entgegengesetzte Vorgaben —
> und die eine ist verlustfrei, die andere nicht.** `psql` fällt unter `LC_ALL=C`
> auf `SQL_ASCII` zurück, also auf *keine* Konvertierung. Die PostgreSQL-Hälfte
> war fehlerfrei, und niemand hat das entschieden.

> **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von beiden
> ist der Ort, an dem man nachsieht.**

> **Ein Testdatensatz aus ASCII prüft keine Kodierung.** Das einzige
> nicht-ASCII-Zeichen im ganzen Bestand des Laufs war ein `ü` in `'unberührt'`,
> hingeschrieben als deutsches Wort und nicht als Prüfung.

**Und drei der sechs Befunde steckten im Lauf selbst**: eine Fassungsprüfung, die
in der falschen Datei suchte; eine Hilfsdatei unter `/root`, die `srvpanel
tinker` nach seinem `setpriv` nicht lesen kann; und eine Gegenprobe, deren
`LIKE`-Muster den eigenen Abfragetext traf.

> **Eine Frage an den Bestand, die sich selbst enthält, zählt sich mit.**

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** `konsole = 0` im Protokoll bekam seine Bedeutung erst durch die eine
> fremde Zeile daneben, die zeigt, dass die Tabelle überhaupt beschrieben wird.

**Und §15 Punkt 3 des Plans war nicht fahrbar, gefunden beim Ausschreiben von
`docs/47`.** Er verlangte, für den Beleg der Serverwand die Mandantenklammer
abzuschalten und die Adresse einer fremden Datenbank aufzurufen. Das Präfix
reist aber mit der **Datenbank** und nicht mit dem Aufrufer — der Aufruf gelingt
dann, und zwar zu Recht. Der Punkt steht jetzt als drei getrennte Wände da.

> **Eine Wand, die man nur erreicht, indem man die davor abschaltet, wird durch
> das Abschalten nicht erreicht — sie wird umgangen.**

> **Eine Option, die etwas erlaubt, ist nicht dasselbe wie ein Zustand, in dem
> es geschieht.**

---

## Befehle

```bash
composer pruefe        # Pint --test, PHPStan, Tests — der volle Durchgang
./vendor/bin/pint      # formatieren (vor jedem Commit)
./vendor/bin/phpunit   # nur die Tests
npm run types          # vue-tsc --noEmit
npm run build          # Vite
php artisan migrate
```

Auf dem Zielserver:
`srvpanel setup|update|metrics|usage|tls|vhost|acceptance|acceptance-web|admin`.

---

## Diese Umgebung

Der Container ist **nicht** der Zielserver. Was hier fehlt, muss man beim
Testen berücksichtigen:

- **MariaDB gibt es hier auch — sie ist nur nicht installiert.** Hier stand neun
  Monate lang „dieser Container hat keine Datenbank" für MariaDB, und das war die
  Hälfte der Wahrheit: `apt-get install mariadb-server` holt
  **10.11.14 aus dem Ubuntu-Archiv, dieselbe Fassung wie `cloudsrv24`**. Der
  Proxy sperrt `composer install` und zwei PPAs, nicht das Archiv der
  Distribution. Ein Wegwerf-Server ist derselbe Handgriff wie für PostgreSQL:
  `mariadb-install-db --datadir=…` in den Scratchpad, `mariadbd --skip-networking
  --socket=…`, kein systemd nötig. Gemessen am 12. August 2026, als Schritt 0 von
  P5c fällig war und als Blockade gemeldet werden sollte (`docs/46 §2.3`).

  > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
  > braucht einen Versuch.**
- **Einen `sshd` gibt es hier auch — er ist nur nicht installiert.** Dasselbe
  wie bei MariaDB, und derselbe Satz: `apt-get install openssh-server
  openssh-client` holt **OpenSSH 9.6p1** aus dem Ubuntu-Archiv, dieselbe
  Fassungsreihe, gegen die `docs/50 §6` gemessen hat. Ein Wegwerf-Dienst auf
  einem eigenen Port mit eigener Konfigurationsdatei rührt den installierten
  nicht an; `tests/sftp-messen.sh` fährt so 42 Messungen in einem Lauf.
  Gemessen am 16. August 2026, als Schritt 8 von P6 anstand und „kein `sshd`"
  in der Übergabe stand (`docs/57`).

  Der teuerste Fund daraus, weil er das Vorbild aus P5b umkehrt: **Ein
  Neuladen mit einer kaputten Datei tötet den sshd** — PostgreSQL bedient in
  derselben Lage weiter und behält die alten Regeln. Der Rückweg „schreiben,
  neu laden, bei einem Fehler zurückrollen" trägt hier nicht, weil nach dem
  Neuladen kein Dienst mehr da ist, in den man zurückrollen könnte.

  > **Ein Rückweg, der voraussetzt, dass der Dienst noch läuft, ist keiner für
  > den Fall, dass ihn genau dieser Vorgang beendet hat.**
- **PostgreSQL gibt es hier, und zwar vollständig.** `postgresql-16` ist
  installiert, Serverbinärdateien und alles. Ein
  Wegwerf-Cluster (`initdb` in den Scratchpad, `pg_ctl` auf einem eigenen Port)
  hat in P5b das Abnahmekriterium umgeworfen, bevor eine Zeile Plan entstand.
  Zwei Dinge dabei: Der Socketpfad muss **kurz** sein — der Scratchpad
  überschreitet die Grenze von 107 Byte, und die Meldung nennt sie —, und
  `postgres` läuft nicht als root, also `su postgres -c`. Was für MariaDB als
  Textprüfung gebaut werden musste, lässt sich für PostgreSQL messen.

  **Und seit dem 11. August misst es die CI auf allen vier Zielplattformen.**
  Der Integrationslauf fährt `postgresql` hoch, liest `server_version_num`,
  vergleicht gegen `Server::MIN_VERSION` aus dem Quelltext, stellt die Lage von
  vor PG 15 her und belegt beide Richtungen: dass `PUBLIC` vorher anlegen darf
  und nach `Shielding::statements()` nicht mehr. Damit sind die Punkte 1 bis 3
  aus `docs/38 §2.3` erledigt — sie standen auf **einer** Messung auf einer
  Plattform und auf Annahmen für die anderen drei.

  > **Eine Messung, die einmal jemand von Hand macht, ist ein Datum. Eine, die
  > die CI macht, ist eine Zusage.**
- **npm geht, Composer nicht — und das ist keine Kleinigkeit.** Gemessen am
  12. August 2026: `npm ping` antwortet, `npm ci` holt 108 Pakete in neun
  Sekunden, `npm run build` und `npm run types` laufen durch. Der Proxy sperrt
  `codeload.github.com` (Composer), nicht die npm-Registry. Damit sind
  **Typprüfung, Bau und die Überlaufmessung bei 390 px hier fahrbar**, auch wenn
  `vendor/` fehlt: Das gebaute Stylesheet aus `public/build` plus das Markup des
  fraglichen Bausteins in einer eigenen HTML-Datei, gerendert im
  vorinstallierten Chromium, `scrollWidth - clientWidth` per `<script>` als Text
  auf die Seite. Hier stand neun Monate lang nur, was ohne `vendor/` alles nicht
  geht, und zu npm nichts — ich hatte es stillschweigend für genauso gesperrt
  gehalten.

  **Und die Messung braucht ihre eigene Gegenprobe.** Ein absichtlicher
  900px-Block muss dort eine Zahl erzeugen; tut er es nicht, misst das Skript
  nichts und seine Nullen bedeuten nichts.

  > **Eine Messung, die nie etwas anderes als Null liefern kann, ist keine.**

  **Und am 16. August ist gemessen worden, wie genau dieser Aufsatz ist: aufs
  Pixel.** Die Kärtchenhöhen des Dateimanagers standen hier auf 236/54
  zugeklappt und 396/214 aufgeklappt; auf `cloudsrv24` gegen `v0.6.0-rc.8`
  ergaben dieselben Messungen **dieselben vier Zahlen** (`docs/56`, Punkt 5).
  Eine Wegwerf-HTML-Datei mit dem echten Markup und dem gebauten Stylesheet ist
  damit keine Näherung.

  > **Ein Aufsatz, der das echte Markup und das gebaute Stylesheet benutzt, misst
  > die echte Seite — und nicht etwas Ähnliches.**

  **Mit einer Grenze, die am 17. August fast einen Fehlbefund gekostet hätte:
  `<style scoped>` gilt in diesem Aufsatz nicht.** Vite übersetzt so eine Regel
  zu `.usage[data-v-1ecda25a]`, und handgeschriebenes Markup ohne dieses
  Attribut trifft sie nie — **105 Selektoren aus 19 Komponenten** stehen so im
  gebauten Stylesheet. Gemessen und fotografiert war „`1.024 MBvon 10.240 MB`"
  auf der Abonnementseite; in Wahrheit ist `.usage` eine Flexbox mit `gap`
  (`docs/59`). Wer eine Komponente mit eigenem Block misst, nimmt ihre Regeln
  dazu oder setzt das Attribut.

  > **Eine Regel, die an ein Attribut gebunden ist, das nur der Übersetzer
  > setzt, fehlt in jedem Aufsatz, der das Markup selbst schreibt.**

  Was das **nicht** ersetzt: den Blick auf die echte Seite mit echten Daten. Der
  braucht `artisan serve` und damit `vendor/`. **Und der Blick auf das Bild, das
  dieser Aufsatz selbst liefert** — im selben Schritt meldete er für einen
  sichtbar kaputten Zustand einen Überlauf von 0px, weil die Zelle schnitt statt
  zu schieben. Gefunden hat es die Aufnahme derselben Datei.

  > **Dieselbe Messung kann aufs Pixel stimmen und trotzdem nichts über die
  > Ansicht sagen.**
- **kein nginx, kein PHP-FPM, kein Agent, kein systemd.** Operationen laufen
  gegen Attrappen. Zwei Fehler sind nur aufgefallen, weil die CI nginx *hat*
  und dieser Container nicht — Tests, die Systemzustand annehmen, gehören
  abgesichert. Vorlagen werden deshalb **als Text** geprüft
  (`SiteTemplateTest`, `PhpIsolationTest`): Der Standardschutz ist eine
  Eigenschaft der erzeugten Zeichenkette.
- **Die Ratenbegrenzung greift beim Aufnehmen von Screenshots.** Drei
  Anmeldungen hintereinander sperren die Adresse (§6.4) — eine Anmeldung für
  alle Aufnahmen, dann `emulateMedia` und `setViewportSize` umschalten. Und
  Inertia schickt über XHR: `networkidle` kommt zurück, bevor die Antwort da
  ist; gewartet wird auf die Adresse.
- **PHPStan ist hier nicht lauffähig — und zwar, weil er nicht installiert
  ist.** `vendor/bin/phpstan` gibt es schlicht nicht; der Aufruf endet mit
  Rückgabewert 127. Nachinstallieren geht auch nicht: `composer install`
  scheitert an „Could not authenticate against github.com", der Proxy dieses
  Containers lässt die Paketquelle nicht durch. Er läuft in der CI;
  `composer pruefe` schlägt deshalb lokal fehl. Einzeln `pint` und
  `php artisan test` aufrufen.

  **Und manchmal geht auch das nicht.** In der Sitzung vom 5. August gab es
  `vendor/` überhaupt nicht — kein Pint, kein PHPUnit, `php artisan` bricht
  schon beim `require` des Autoloaders ab. Derselbe Proxy, dieselbe Meldung.
  Wer hier ankommt, sieht als Erstes nach, ob `vendor/` da ist: Ist es das
  nicht, prüft ausschliesslich die CI, und **jede** Änderung an `app/`,
  `agent/` oder `tests/` kostet eine Runde — nicht nur die, die PHPStan
  beträfe.

  **Und `ls vendor` genügt für diese Frage nicht.** Am 6. August lagen dort
  39 Pakete und trotzdem weder `vendor/bin` noch `vendor/autoload.php`: ein
  abgebrochenes `composer install`, das aussieht wie ein fertiges. Gefragt wird
  nach `vendor/autoload.php`, nicht nach dem Verzeichnis.

  **Und es gibt Werkzeuge, die der Proxy durchlässt.** Was scheitert, ist
  `composer install`, nicht jedes HTTPS — und die Trennlinie ist in P5 genau
  vermessen worden: Die Metadaten von packagist antworten mit **200**,
  `codeload.github.com` mit **403**. Composer löst also auf und scheitert beim
  Herunterladen.

  - **`pint.phar` gibt es von den GitHub-Releases, und es *ist* Pint.** In P4
    stand hier, man müsse sich mit `php-cs-fixer.phar` behelfen und dessen
    Regeln von Hand nachbauen; das war ein Umweg um etwas herum, das es gibt.
    `curl -sSL -o pint.phar
    https://github.com/laravel/pint/releases/latest/download/pint.phar` holt
    dieselbe Fassung, die `composer.json` verlangt (`^1.27`), und
    `php pint.phar --test` über das ganze Repo sagt dasselbe wie die CI —
    am 7. August 2026 gegengeprüft, beide grün. **Damit fällt eine ganze
    Klasse von CI-Runden weg:** Formatierung wird hier gemessen, nicht
    geraten.
  - **php-cs-fixer ist nicht Pint** — die Notiz bleibt für den Fall, dass
    `pint.phar` einmal nicht zu holen ist. Pints Laravel-Voreinstellung ist
    ein eigener Regelsatz; wer dort alles anschaltet, bekommt Meldungen zu
    Stellen, die Pint in Ruhe lässt (`) {}` an einem leeren Konstruktor zum
    Beispiel).
  - **PHPStan taugt nur für `agent/`.** Ohne `vendor/` fehlt larastan, und
    jedes `Model::query()` und jede Spalte gilt als undefiniert — hunderte
    Meldungen, die nichts bedeuten. Unterhalb von `agent/` gibt es kein
    Framework, und dort läuft Stufe 6 sauber durch. Genau so gefunden:
    `array_values()` auf einer Liste, die schon eine ist.

    **Für eine einzelne neue Datei geht trotzdem mehr, als es aussieht.** Ein
    Lauf über *nur* die geänderte Datei bringt zwar ein Dutzend
    `method.notFound` für jede `assert…` — aber alles, was PHPStan aus dem
    Code selbst schliesst, steht dazwischen und ist echt. Gefiltert nach
    Kennungen, die nichts mit fehlenden Klassen zu tun haben
    (`--error-format=raw`, dann ohne `notFound`), fällt zum Beispiel ein
    `function.impossibleType` heraus: ein `in_array($x, self::LEER, true)`
    gegen eine leere Konstante. Genau der hat eine CI-Runde gekostet, und die
    Gegenprobe zeigt, dass er lokal auffindbar gewesen wäre.

    **Und `tests/Support/` gehört in denselben Lauf wie `agent/src`.** Die
    Testdoppel dort hängen am Agenten und nicht am Framework, also läuft Stufe 6
    auch über sie sauber durch. Wer das trennt, sieht die teuerste Meldung
    nicht: Bekommt eine Schnittstelle unter `agent/` eine Methode und das Doppel
    nicht, ist das `method.abstract` — und in den Tests kein Fehlschlag, sondern
    ein Abbruch („Premature end of PHP process"), der alles Folgende verschluckt.
    Am 7. August genau so passiert, mit `DnsProvider::patience()`.

  **Und die framework-freien Wächter laufen hier — ohne PHPUnit.** Hier stand
  neun Monate lang, dass ohne `vendor/` nur die CI prüft und **jede** Änderung an
  `tests/` eine Runde kostet. Für die Wächter, die `PHPUnit\Framework\TestCase`
  erben, stimmt das nicht: Diese Basisklasse ist dort eine Sammlung von
  `assert…`-Methoden und sonst nichts. Ein Wegwerfskript im Scratchpad, das sie
  selbst definiert und die Testdatei einbindet, fährt sie — in P5c Schritt 5
  waren das 41 Fälle aus zwölf Wächtern, und die vier Brüche zu zwei neuen
  Regeln liessen sich damit **hier** belegen statt in der CI. Zwei Fassungen
  desselben Tests ist das nicht: In dem Skript steht keine einzige Behauptung,
  nur die Maschine, die die echten ausführt.

  Drei Dinge dabei: `tests/Support/` blind zu laden zieht Interfaces nach, die
  kein Wächter braucht (nur die Traits einbinden); `agent/src/autoload.php`
  gehört dazu; und was Laravels `Tests\TestCase` erbt — `DesignTokensTest`,
  `WordChoiceTest` — bleibt Sache der CI.

  **Und die Liste der Wächter zählt sich selbst ab.** Am 13. August stand dort
  eine handverlesene — dreizehn von 136 —, und drei Wächter, die eine
  Umbenennung gemeldet hätten, waren nicht darunter; sie liefen erst in der CI.

  > **Eine Prüfung, die nur nachsieht, woran man gerade denkt, prüft das
  > Erinnerungsvermögen.**

  **Was das Gestell nicht kann, zählt es nach Art** statt es „übersprungen" zu
  nennen: fehlende Klassen, `setUp()`, Datenlieferanten, `use App\`. Gemessen
  468 grün, 1 rot, 263 solcher Löcher. Der Grund für die Aufzählung ist ein
  eigener Fehler — ein `ArgumentCountError` aus einem `sprintf()` verschwand
  vorher im Topf, und eine Einteilung nach dem **Wortlaut** der Meldung
  (`not found` gegen `does not exist`) hat 104 Wächter in die falsche Richtung
  gekippt (`docs/46 §20.42`).

  > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
  > kleiner werden kann.** `phar.phpunit.de` sperrt der Proxy
  übrigens genauso wie `codeload.github.com` (403 am CONNECT).

  > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
  > braucht einen Versuch.** Derselbe Satz wie bei MariaDB weiter oben.

  **Für `agent/` gibt es ausserdem einen Ausweg, und er hat in P4 eine Runde
  gespart.**
  `agent/src/autoload.php` ist framework- und abhängigkeitsfrei; Code unterhalb
  von `agent/` lässt sich damit aus einem Wegwerfskript im Scratchpad fahren,
  ganz ohne PHPUnit — `require agent/src/autoload.php`, Testdoppel von Hand
  dazuladen, Behauptungen als `if`. Das ersetzt den Wächter nicht und gehört
  nicht ins Repo (zwei Fassungen desselben Tests, und die zweite veraltet),
  aber es beantwortet vor dem Commit die Frage, ob der Code überhaupt läuft.

  **Was das für die Arbeit heisst:** Undefinierte Variablen, fehlende
  Typangaben und tote Zweige findet hier nichts. Wer `app/`, `agent/` oder
  `tests/` anfasst, rechnet mit einer Runde CI dafür — viermal an einem Tag
  passiert, und jedes Mal war es eine Typangabe oder eine Variable, die beim
  Aufräumen wegfiel.

  **Eine Falle, die dreimal davon ausgemacht hat:** Ein einzeiliger
  Dokumentationsblock trägt **keine Marke**. In
  `/** Die Namen. @return list<string> */` ist `@return` Fliesstext, und die
  Angabe ist damit weg — PHPStan meldet „no value type specified", und zwar
  erst in der CI. Marken stehen auf einer eigenen Zeile; `/** @return
  list<string> */` allein geht, mit Text davor nicht.

  **Und eine zweite, die inzwischen viermal zugeschlagen hat: ein Name, der
  der Basisklasse gehört.** `count()` in einem PHPUnit-Testfall (dort `final`),
  `configure()` in einem Artisan-Kommando (dort `protected`), und in P5 gleich
  zweimal: `for()` in einer `Factory` und `matches()` wieder in einem Testfall
  (`PHPUnit\Framework\Assert::matches()`, `final` **und** `static`). Alle
  brechen beim **Laden** der Klasse und nicht beim Ausführen. `php -l` sieht
  davon nichts, die Meldung kommt als fataler Fehler — bei `matches()` endete
  `php artisan test` mit Rückgabewert 255, bevor ein einziger Test lief: Nicht
  eine Datei stand still, sondern alle vierundsiebzig.

  Bemerkenswert ist, wie P5 sie erlebt hat: Beim `for()` in der Factory hat der
  Blick in die Basisklasse sie gefangen, beim `matches()` im Testfall nicht —
  **die Regel zu kennen genügt nicht, wenn man nicht daran denkt, dass ein
  Testfall auch eine abgeleitete Klasse ist.** Wer in einer abgeleiteten Klasse
  eine private Hilfsmethode einzieht, sieht vorher in der Basisklasse nach; ein
  Testfall zählt dazu.
- **Der Hostname ist kurz.** `php_uname('n')` liefert nicht den vollen Namen —
  dafür gibt es `SrvPanel\Agent\Names::fqdn()` (oder `host()`, wenn ein Name
  gebraucht wird und `null` nicht taugt), und die ist die *einzige* Stelle, die
  diese Frage beantworten darf. Sie ist **viermal** neu erfunden worden; seit
  dem vierten Mal gibt es `HostnameSourceTest` dafür.
- **Screenshots** über Playwright mit dem vorinstallierten Chromium
  (`/opt/pw-browsers/chromium`), niemals `playwright install`. Vier Dinge, die
  jedes Mal Zeit gekostet haben:
  - `Totp::codeAt($secret, $step)` nimmt den **Schritt**, nicht den
    Zeitstempel: `intdiv(time(), Totp::PERIOD)`. Und `two_factor_last_step`
    verhindert die Wiederverwendung — ein zweiter Lauf in derselben Minute
    muss auf den nächsten Schritt warten.
  - **Der SSE-Kanal eines offenen Vorgangs blockiert `artisan serve`.** Der
    bedient eine Anfrage gleichzeitig, und `PHP_CLI_SERVER_WORKERS` greift
    hier nicht. Im Aufnahmeskript `page.route('**/stream', r => r.abort())`.
  - **Der Entwicklungsserver liefert aus `public/build`.** Nach jeder Änderung
    an einer `.vue` erst `npm run build`, sonst zeigt die Aufnahme den
    vorigen Stand. Zweimal darauf hereingefallen.
  - Nach jeder Aufnahme `scrollWidth - clientWidth` messen. Ein waagerechter
    Überlauf bei 390px sieht auf dem Bild nach nichts aus und ist auf dem
    Telefon der ganze Unterschied.
- Vordergrund-`sleep` ist blockiert — Hintergrundlauf verwenden.
- **`git checkout -- resources/` wirft eigene Arbeit weg.** Beim Gegenprüfen
  eines Wächters ist das der Weg zurück — und wenn im selben Verzeichnis noch
  nicht Eingechecktes liegt, ist es danach fort. `tests/waechter-brechen.sh`
  weigert sich deshalb bei schmutzigem `resources/`; von Hand gilt dasselbe.

---

## Ablauf

- Entwickelt wird auf dem zugewiesenen `claude/...`-Zweig, **nie direkt auf
  `main`**. Ist der zugehörige PR gemergt, wird der Zweig unter demselben Namen
  frisch von `main` gestartet, statt auf gemergter Historie zu stapeln.
- **Einen Pull Request nur öffnen, wenn ausdrücklich danach gefragt wurde.**
  `.github/pull_request_template.md` gibt die Gliederung vor.
- Freigaben sind annotierte Tags `v<version>` auf `main`; ein Release-Lauf baut
  daraus die `.deb`-Pakete und signiert die Prüfsummen. **Privates
  Schlüsselmaterial wird in diesem Container nie erzeugt.**
- Der `CHANGELOG.md` ist kein Protokoll der Commits, sondern der Ort, an dem
  steht, *warum* etwas so ist — und was vorher falsch war. `ChangelogTest`
  prüft ihn.

Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium **nachweisbar**
erfüllt ist (Plan §8 und §9) — gemessen auf einem echten Server, nicht
geschätzt.
