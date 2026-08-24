# SrvPanel — für Claude

Ein Hosting-Panel in der Art von Plesk: Laravel 13, Inertia, Vue 3,
AGPL-3.0-only. Zielplattformen sind Debian 12/13 und Ubuntu 22.04/24.04.

**Der Plan ist `docs/20-hostingpanel-neuplan.md`.** Er ist die Quelle für
Architektur (§4), Rechtemodell (§6), Gestaltung (§7.2) und die Ausbaustufen
(§9). Wo dieses Dokument und der Plan sich widersprechen, gilt der Plan.

Die Oberfläche folgt seit August 2026 dem Gestaltungssystem **„Kontor"**
(Plan §7.2) — hell entworfen, keine Karten, Monospace nur für Kennungen.

Stand: **P0 bis P7 abgenommen.** P7 (der DNS-Abgleich) ist am **24. August
2026** auf `cloudsrv24` gegen `0.7.0-rc.8` abgenommen — alle acht Kriterien aus
`docs/72 §3`, der Lauf ist `docs/77`, das Protokoll **`docs/78`**. Die Lehre
dieses Laufs steht weiter unten; sie ist eine über Abnahmeläufe und nicht über
DNS.

 P6 ist am **21. August 2026** auf `cloudsrv24`
gegen `v0.6.0-rc.24` abgenommen — der Angriffsdurchgang (`docs/62`) und der
Abschlusslauf, der seine vier letzten Reste durch die echte Route belegt hat
(`docs/68` der Lauf, **`docs/69`** das Protokoll mit der Tabelle der fünfzehn
Kriterien). Aus P6 war **Schritt 8 (SFTP)** schon am 17. August abgenommen —
`docs/58` der Lauf, **`docs/59`** das Protokoll; beide Zusammenfassungen stehen
weiter unten. **Offen und benannt bleiben** Wand 2 aus Punkt 11 und Befund 23
(`docs/59`), beide kein Kriterium.

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

**Zum dritten Mal am 20. August**, beim Bereich „Job anlegen" der Cronseite: Er
ist der dritte von drei Bereichen, dazwischen liegen bis zu zehn Kärtchen, und
bei 390 px fand ihn niemand (`docs/64`, Befund 13). Gemeldet hat es wieder der
Betreiber.

**Dreimal derselbe Fehler heisst: Es fehlt kein Wächter, sondern eine Frage.**
Ein Test kann „erreichbar" nicht halten — das hängt daran, was ein Kunde
erwartet, und keine Eigenschaft des Quelltextes bildet es ab. Deshalb steht die
Regel hier und nicht in `tests/`:

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?** Nicht „ist sie erreichbar" — erreichbar ist alles, was man findet,
> wenn man lange genug rollt.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

**Was offen bleibt und benannt ist:** Wand 2 aus Punkt 11 und die vier Zeilen zu
den Befunden 20 und 21 gegen die nächste Fassung. Wer sie anfasst, fängt bei
`docs/59` an und nicht bei null.

**Befund 22 ist am 19. August geschlossen** — die Messvorschrift steht als
`tests/bilder-messen.js` im Repo, und `OverflowProbeTest` liest sie. Dabei hat
sich die berichtigte Fassung aus `docs/58 §12` selbst als zu kurz erwiesen: Ein
Prüfkörper von `clientWidth + 200` fällt auf einer Seite, die **schon** schiebt,
wieder auf `0` zurück, weil er dann nicht mehr das Breiteste ist. Gegen
`scrollWidth + 200` gemessen schlägt er in allen vier Lagen mit `200/200` aus,
heil wie kaputt (im Container gegen echtes Chromium gemessen).

> **Ein Prüfkörper, der nur auf der heilen Seite ausschlägt, belegt die Messung
> dort, wo sie niemand braucht.**

---

## Der Serverlauf zu `v0.6.0-rc.20` — 21. August 2026

Der Lauf ist `docs/65`, das Protokoll **`docs/66`**, gefahren auf `cloudsrv24`.
**Elf Punkte, zehn erfüllt, acht Befunde** — und **drei** davon stecken im
Prüfmittel oder im Kriterium statt im Prüfling. Das ist deutlich weniger als in
`docs/45`, `docs/48` und `docs/59`, wo es die Mehrheit war, und der Grund ist
kein besseres Auge:

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.** `tests/bilder-messen.js` liegt seit dem 19. August als geprüfte
> Vorschrift im Repo, statt in jedem Lauf neu geschrieben zu werden.

**Alle acht sind behoben** (`docs/66 §3`), jeder mit Wächter und Bruch — und
**am 21. August auf dem Server nachgesehen**, weil das zweierlei ist: Der
Nachlauf `docs/67` hat aus den Behebungen drei weitere Befunde gezogen, darunter
zwei Ausfälle des Panels (500 und 502), die es ohne die Behebungen nicht gäbe.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

Was darüber hinaus gilt:

**Der teuerste war eine Zeile, die es seit P6 Schritt 5 gibt.** `router.get`
legt seine Werte in die **Adresse**, und dort ist alles eine Zeichenkette: Aus
`false` wird das Wort `"false"`, und Laravels Regel `boolean` nimmt
`true, false, 1, 0, "1", "0"` — kein Wort. Die Suche im Dateimanager ist damit
an keinem Tag durchgekommen, in **beiden** Zuständen des Kästchens. Der
Gegenbeleg stand in derselben Datei: `recursive` trägt dieselbe Regel und
funktioniert, weil es im Rumpf eines `DELETE` reist.

> **Dieselbe Regel über einem Wert, der einmal als JSON und einmal als
> Zeichenkette reist, gilt nur einmal.**

> **Ein Fehler, den man am auffälligen Fall entdeckt, ist selten auf den
> auffälligen Fall beschränkt.** Gemeldet war „das Kästchen bleibt nicht
> angehakt"; getroffen hat es jede Suche.

`FileSearchTest` war dabei grün — er vergleicht die **Schlüssel**, die beide
Seiten schicken, und beide schickten denselben kaputten Wert.

> **Zwei Eingaben, die dasselbe schicken, schicken auch denselben Fehler.**

**Der grösste betraf das Protokoll der ganzen Stufe.** `context` wurde
geschrieben und von keiner Oberfläche gelesen — weder von `toArrayRow()` noch
von den fünf Spalten der Seite noch vom Export. Ausgezählt über `app/`: 19
Aufrufe mit `target:`, **18 mit `context:` und ohne**, und alle achtzehn sind P6
oder Anmeldevorgänge. `file.removed` ohne die Datei, `sftp.key.remove` ohne den
Schlüssel.

> **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
> beantwortet die Frage, die niemand stellt.**

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

**Und einer über die Behebung des vorigen Befundes.** Die 85 deutschen Namen aus
`docs/64` Befund 15 waren vollständig — und nie gegen die sichtbare
Beschriftung ihrer Seite gehalten. Auf der Cronseite las der Kunde „Das Feld
Bezeichnung ist erforderlich" und suchte ein Feld, das dort „Beschriftung"
heisst. Nachgemessen: 68 Paare, **fünfzehn** Abweichungen.

> **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**

**Zwei meiner elf Kriterien waren falsch, nicht der Prüfling** — Punkt 4 (ich
erwartete 18 px, richtig waren 294) und Punkt 11 (ein leerer 3000-px-Block
taucht nie in `schiebt` auf; er läuft nicht über, er lässt überlaufen, und
aufgeführt sind seine Vorfahren).

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

**Und zwei Sätze über Gegenproben, beide an Punkt 8 bezahlt.** Ohne
`IdentitiesOnly=yes` ist `-i` nur ein Vorschlag: OpenSSH bietet zusätzlich die
Schlüssel des Agenten an, und die Anmeldung kann mit einem ganz anderen
gelingen, während die Gegenprobe daneben trotzdem scheitert.

> **Ein Schlüssel, der nur vorgeschlagen wird, belegt keine Anmeldung — es kann
> jeder andere gewesen sein.**

> **Eine Gegenprobe, die an einer anderen Hürde scheitert als der gemeinten, hat
> die gemeinte nicht geprüft.** `BatchMode=yes` kann die Frage nach dem
> Rechnerschlüssel nicht beantworten; läuft die Gegenprobe zuerst, endet sie mit
> `Host key verification failed` und nicht mit `Permission denied`.

**Und einer über Hinweise:** Der Satz unter dem privaten Schlüssel nannte
`~/.ssh` und die Rechte `600`. Auf Windows stimmt daran kein Teil.

> **Ein Hinweis, der ein Betriebssystem voraussetzt, ist auf dem anderen kein
> unvollständiger Hinweis, sondern ein falscher.**

**Wunsch 4 ist gebaut** (`docs/66 §4.5`): Die Cronseite zeigt beim Tippen, in
welchem Rhythmus der Job laufen wird und wann er das nächste Mal fällig ist.
Sie übersetzt weiterhin nicht selbst — sie fragt `cron.preview`, und den Satz
baut `Spoken`, die Fälligkeiten `Occurrence`. Zwei Dinge daraus:

> **Zwei Antworten, die beide stimmen, ergeben zusammen eine falsche Anzeige,
> wenn die Reihenfolge fehlt.** Zwei Anfragen können sich überholen; jede trägt
> deshalb eine Nummer, geprüft an **beiden** Ausgängen.

Und ein Wächter, der über Wunsch 4 hinausgeht: **`TopLevelSetupTest` zählt
Klammern, statt Wörter zu lesen.** Was am linken Rand einer `.vue` steht, sieht
nach oberster Ebene aus; er vergleicht diesen Eindruck mit der tatsächlichen
Verschachtelung und fängt damit den `})})`-Fehler vom 20. August, den `vue-tsc`
und jeder wortlesende Wächter durchgelassen haben. 61 Dateien, 515 Erklärungen
am Rand.

**Der erste Wurf von Wunsch 4 war trotzdem falsch gebaut, und zwei bestehende
Wächter haben es gefunden — beim Durchlauf am Ende und nicht beim Bauen.** Die
Route war ein `GET` mit einem eigenen `fetch`; `PanelRequestTest` meldete zwei
Dateien statt einer, `TenancySweepTest`, dass `cron/preview` nicht im Prüfstand
der Mandantenklammer steht.

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

---

## P7 — der Abnahmelauf, der keinen Fund am Prüfling hatte

Abgenommen am **24. August 2026** gegen `0.7.0-rc.8`, alle acht Kriterien aus
`docs/72 §3`. Der Lauf ist `docs/77`, das Protokoll **`docs/78`**.

**Alle acht waren bei der ersten Messung erfüllt.** Jede Korrektur dieses Tages
betraf die **Vorschrift** oder die **Umgebung** — nicht das Panel. Drei davon
hätten ein falsches Rot erzeugt, zwei ein falsches Grün.

> **Die Mehrheit der Fehler steckt nicht im Prüfling, sondern im Prüfmittel** —
> hier war es die Gesamtheit.

Gefunden wurden sie auf zwei Wegen und auf keinem dritten: durch **Messen der
Vorbereitung** statt sie vorauszusetzen, und durch **Nachsehen am Quelltext**,
bevor eine Anweisung ausgeschrieben wurde.

**Der teuerste hätte den Prüfling für etwas gemeldet, das er zu Recht tut.**
Kriterium 4 lautet „fragt die autoritativen Server und nicht den
Systemauflöser", und die Messung sollte zählen, ob überhaupt ein Paket an den
Auflöser geht. Es geht eines: `Resolver::nameservers()` fragt über
`dns_get_record()`, **wo eine Zone liegt**, und das ist im Kopf der Klasse
begründet. Das Kriterium meint die *Werte der Sätze*, nicht das Auffinden der
Zone.

> **Ein Kriterium, das man am falschen Paket misst, meldet den Prüfling für
> etwas, das er zu Recht tut.**

**Und der zweite steckte in der Zone.** `ohne.cloudlab24.de` sollte den Zustand
„fehlt" herstellen, indem man dort nichts anlegt — die Zone führt aber einen
Platzhalter, und ein Name, den es nicht gibt, bekommt dort eine Antwort. Punkt 3
hätte zwei Namen gemessen, die beide „zeigt hierher" sagen.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch
> hergestellt, dass man nichts tut.**

Der Ausweg fasst die Zone nicht an: Ein Platzhalter greift nach RFC 4592 nur für
Namen, die es **gar nicht** gibt, also lässt ein `TXT`-Satz den Namen existieren
und die `A`-Frage kommt leer zurück.

**Zwei Sätze über Prüfkörper**, beide an Punkt 4 bezahlt. Der erste, weil das
Verfahren einen gefüllten Auflöser-Zwischenspeicher brauchte und ipv64 die TTL
fest auf 10 Sekunden stellt:

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**

Der zweite, weil das ganze Verfahren an dieser Haltbarkeit hing und deshalb
ersetzt wurde — gemessen wird jetzt das UDP-Paket selbst:

> **Eine Messung, die um ihren Gegenstand herumführt, hängt an Bedingungen, die
> mit ihm nichts zu tun haben.**

**Und einer über das Zurücknehmen.** Punkt 9 räumt den `CAA`-Satz ab und misst
danach noch einmal — nicht als Aufräumen, sondern als Beleg für Punkt 6:

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

**Was benannt offen bleibt** (`docs/78 §5`): der fehlende Aufstieg zur
CAA-Elternzone (Grenze, kein Mangel), „Nameserver uneinig" und „kein Sollzustand
bekannt" als nicht herstellbare Zustände, die Grenze des Durchgangs, und eine
Beobachtung ausserhalb von P7 — die Zertifikatsautomatik hat für die drei neuen
Domains über eine Stunde lang nichts bestellt.

---

## Die Zertifikatsbeobachtung aus `docs/78 §5` — 24. August 2026

Sie stand als „hatte nach über einer Stunde kein Zertifikat" da und war kein
Warten, sondern ein Fehlschlag: Die Automatik hatte bestellt, eine Sekunde nach
`web.site.apply`, und die Prüfung von ACME bekam **403**. Die Prüfdatei lag,
stand auf `0644`, ihre Verzeichnisse auf `0755`, und der `location`-Block zeigte
mit `root` genau dorthin. Falsch war der Weg: `/var/lib/srvpanel` ist `0750
srvpanel:srvpanel`, der nginx-Worker läuft als `www-data`.

> **Eine Datei, die für alle lesbar ist, ist damit nicht erreichbar — der Weg zu
> ihr entscheidet.**

**Dieselbe Frage war in P6 schon einmal gestellt und beantwortet** —
`CronApply::SPOOL_DIR` nennt wörtlich denselben Grund für die Aufzeichnungen der
Cronjobs, und dort ist die Antwort `/var/spool`. Für die ACME-Prüfdatei hat sie
niemand gestellt. Das ist der Satz aus `docs/59` zum vierten Mal:

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.**

Kein Wächter konnte ihn sehen, weil er zwischen zwei Dateien steht: Der
Ablageort wohnt im Agenten, die Rechte seiner Elternverzeichnisse in der
Paketierung, und jede Seite für sich war in Ordnung. `ChallengeReachTest` wandert
jetzt vom Wurzelverzeichnis bis zum Ablageort.

**Und zwei Sätze über das Messen, beide an diesem Tag bezahlt.** Der erste, weil
der erste Griff in den Fehlerlog leer blieb — `/var/log/nginx/error.log` ist
nicht die Datei, jede Domain hat über `SiteTemplate` ihren eigenen unter
`/var/www/vhosts/<benutzer>/logs/<domain>/error.log`:

> **Ein leerer Griff in die falsche Datei sieht aus wie ein Befund.**

Der zweite, weil der frisch gebaute Wächter beim Gegenprüfen an genau der Stelle
grün blieb, an der er hätte rot sein müssen: Seine Frage „steht der Ablageort in
der Paketierung?" ging an die Vereinigung von `nfpm.yaml` und `postinstall.sh`.

> **Eine Frage an die Vereinigung hält auch dann, wenn eine der Quellen blind
> ist — die andere zahlt für sie mit.**

**Was ein Betreiber danach von Hand tut:** `srvpanel vhost --sites`. Das Update
zieht den Block der Oberfläche nach, die der Kundendomains nicht — und die
zeigen bis dahin auf den alten Ort.

**Nachgesehen am 24. August gegen `0.7.0-rc.9`** (`docs/78 §5`): `200` auf die
Prüfdatei, `404` auf einen Namen, den es dort nicht gibt, und alle vier Blöcke
tragen dieselbe eine `root`-Zeile. Die Kette bis zum **ausgestellten**
Zertifikat ist dabei ausdrücklich nicht gemessen — die zwei Bestellungen, die
`vhost --sites` anstiess, galten Namen unter `.invalid` und sind zu Recht
abgewiesen worden.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

Und eine Falle beim Nachsehen: **`srvpanel tinker` läuft ohne angemeldetes
Konto.** Jedes Modell mit `BelongsToSubscription` steht dort auf
`whereRaw('0 = 1')` — ohne `withoutGlobalScopes()` kommen null Zeilen zurück,
und zwar wortlos. Gemessen als Paar: `mit Klammer: 0` · `ohne Klammer: 679`.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

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
`ChallengeReachTest` (der Webserver kommt bis zur ACME-Prüfdatei — geprüft an
den Rechten jedes Verzeichnisses auf dem Weg dorthin) und
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

**Und die Falle daneben, gefunden am 20. August beim Bau von Befund 15.** Ein
frisch gebauter Wächter stand grün — und genau die fünf Felder, an denen der
Befund überhaupt entdeckt worden war, sah er nicht: Sie entstehen aus
`...array_fill_keys(Schedule::FIELDS, …)` und stehen nirgends im Quelltext.

> **Ein Wächter, der einen Ausdruck nicht auflösen kann, hat nicht wenig
> gemessen — er hat an dieser Stelle gar nicht gemessen.**

Das ist „Eine Null ist nur dann eine Messung" eine Ebene tiefer: Es fehlt nicht
der Ausschlag, sondern der Prüfkörper. Wer einen Wächter über eine Aufzählung
baut, prüft ihn deshalb an dem Fall, der ihn ausgelöst hat — und macht ihn rot,
wo er nicht hinsehen kann, statt ihn dort schweigen zu lassen.

**Und ein Ausdruck, den dieses Repo an einem Tag zweimal falsch geschrieben
hat:** `\.value\s*=` trifft auch `===`, denn `=` ist dessen erstes Zeichen.
`RevealTest` hat damit vier Löcher erfunden, die es nie gab, und `PrivateKeyTest`
eine Stunde später drei Zuweisungen gezählt, wo eine steht. Die Abgrenzung
lautet `(?<![=!<>])=(?!=)`.

> **Ein Ausdruck, der eine Zuweisung sucht, findet jeden Vergleich mit, solange
> er das Gleichheitszeichen nicht abgrenzt.**

**Und die teuerste Falle dieses Tages: ein Fehler, den kein Wächter sehen
kann, weil er in den Klammern steht.** `})})` in einer `.vue` — die
schliessende Klammer des einen `watch` war an die des nächsten gerutscht, und
ein `watch` samt seinem `ref` stand damit **innerhalb** eines Rückrufs. Er wurde
nie registriert; das Merkmal war von seinem ersten Tag an wirkungslos. `vue-tsc`
und `npm run build` liefen durch, und jeder Wächter fand jedes Wort, das er
suchte.

> **Ein Wächter, der Wörter liest, sieht keine Klammern.**

> **Ein Fehler, der in einer Funktion sitzt, wird vom Typprüfer entschuldigt —
> die Funktion läuft ja später.** Der Typprüfer meldete ihn erst, als die
> Klammer richtig sass.

Gefunden hat ihn der **volle** Lauf des Bruchskripts, nachdem jeder einzelne
Eingriff von Hand gebissen hatte.

> **Ein Eingriff, der einzeln beisst, beisst nicht unbedingt im Lauf** — er
> steht dort neben anderen, und die verändern seinen Gegenstand.

**Und zwei Sätze über Messmittel, beide am 20. August bezahlt.** Der erste, weil
eine Regel bei 390 px ein Feld 240 px *hoch* machte, statt es breit zu machen:

> **Eine Flex-Grundgrösse ist eine Breite oder eine Höhe, je nachdem, wie die
> Reihe gerade steht.**

Aufgefallen ist das nicht am Bild, sondern daran, dass zwei verschiedene Inhalte
dieselbe Zahl ergaben. Der zweite, weil der Aufsatz nach einem `npm run build`
auf ein Stylesheet zeigte, das es nicht mehr gab — und weitermass:

> **Ein Aufsatz, der auf ein gebautes Stylesheet zeigt, zeigt nach dem nächsten
> Bau ins Leere — und misst weiter.** Wer eine Wegwerfseite baut, baut sie im
> selben Schritt wie die Messung.

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
bleibt · **`60` die Messrunde vor Schritt 9** (Cron), zweiunddreissig Messungen
gegen einen Wegwerf-cron im Container · und **`61` der Angriffsdurchgang** —
Schritt 11 und damit das Abnahmekriterium der ganzen Stufe.

Und **`63` die Bilderrunde** — Schritt 12: die neun Ansichten, ihre Zustände,
das Messmittel mit seiner Gegenprobe und die Fallen, die diesen Lauf schon
gekostet haben — mit **`64`** als Protokoll dazu, angelegt am 19. August nach
den ersten beiden Ansichten.

Und **`65` der Serverlauf zu `v0.6.0-rc.20`** — die elf Punkte, mit denen die
sieben Befunde der zweiten Runde und die drei Wünsche auf einem echten Server
geprüft werden, samt den drei Dingen, die der Aufsatz im Container
grundsätzlich nicht kann (ein Herunterladen, `<style scoped>`, echte Daten) —
mit **`66`** als Protokoll dazu — und **`67` der Nachlauf zu `v0.6.0-rc.21`**:
fünf Punkte, die nachsehen, ob die acht Behebungen aus `docs/66` auf dem Server
auch wirken. Alle fünf erfüllt, Punkt 4 erst gegen `v0.6.0-rc.24`; sechs
Befunde, drei davon am Prüfmittel oder an meiner eigenen Anweisung. In §3 steht,
was offen bleibt.

Und **`68` der Abschlusslauf für P6** — die vier Reste, die zwischen dem
gemessenen Abnahmekriterium und der Abnahme stehen: die Punkte 5, 7 und 8 des
Angriffsdurchgangs **durch die echte Route** statt über den Weg der Operation,
die Umbruchregel aus `docs/67` Befund 6 auf zwei weiteren Seiten, und `id` am
Vorgang. §9 sagt, was er ausdrücklich nicht prüft, §10, wann P6 abgenommen ist —
und nennt die vier Stellen, die heute noch das Gegenteil sagen.

**Und die erste Messung hat zwei Fehler in dieser Vorschrift gefunden**, beide
am Prüfmittel: `schiebt` war als Kriterium gedacht und meldet bei jeder Tabelle
das gewollte `.stacks thead` (`Eine Liste, die auch das Gewollte nennt, ist ein
Hinweis und kein Urteil`), und eine Messung nach einem Breitenwechsel **ohne
Neuladen** trägt Reste mit — derselbe Überlauf von 468 px bei 390 und 1440, den
dieselbe Seite frisch geladen nicht hat.

> **Eine Messung nach einem Wechsel der Breite misst auch, was von vorher übrig
> ist.**

**Und die Vorbereitung dieses Schritts hat zwei Fehler gefunden, die kein Test
finden konnte, weil es keinen gab** (19. August 2026, gemeldet vom Betreiber:
„es werden keine Läufe angezeigt"). Beide betreffen die Zeitsteuerung, beide
sind still, und beide sind behoben.

Der erste ist die dritte Grenze an einer Stelle, an der sie nichts schützt:
`srvpanel-cron.service` läuft **ohne angemeldetes Konto**, und `Cron::store()`
fragte `CronJob` ohne `withoutRestriction()`. Der Einsammler meldete „88
eingesammelt, **0 eingepflegt**" — und die 88 waren fort, weil `cron.runs`
löscht, was es herausgegeben hat.

> **Zwei Stellen, die dieselbe Ausnahme brauchen, und nur eine hat sie: Die
> andere fällt nicht auf, weil sie leise das Richtige tut — nämlich nichts.**

Der zweite: `srvpanel-cron.timer` meldete `active`, `NEXT` stand auf `-`, und
der letzte Lauf lag **22 Stunden** zurück. Ein Timer mit ausschliesslich
monotonen Sockeln (`OnBootSec`, `OnUnitActiveSec`) kann seinen nächsten Termin
verlieren; `Persistent=true` half nicht, weil es allein auf `OnCalendar` wirkt
und dort als Notiz stand, die sich wie eine Zusage liest. Zwei der drei Timer
waren so gebaut.

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

Und die Ursache dafür, dass beides wochenlang unbemerkt blieb:

> **Eine Klasse, die sich nicht ersetzen lässt, hat keinen Test — und der Weg
> dahinter auch nicht.** `SrvPanel\Agent\Client` ist `final`; alles hinter ihm
> war ungeprüft. `Cron::record()` ist die Naht, `CronIngestTest` und
> `TimerRearmTest` sind die Wächter.

**`61 §0` ist der Teil, der vor dem Lauf gelesen wird und nicht währenddessen:**
vier Vorarbeiten, gefunden beim Ausschreiben am Quelltext. Zwei davon würden ein
falsches Grün erzeugen — die Punkte 13 und 14 des Kriteriums sind von aussen gar
nicht ablesbar, weil `Sandbox::parent()` `uid` und `groups` prüft und dann
verwirft, und der Angreifer zu Punkt 6 fällt ohne FFI auf ein Verfahren zurück,
das in `docs/50 §3` in 20 000 Runden null Mal traf.

Und **§1 ist die Lehre, die über diesen Lauf hinausgeht:** Zwischen einem Pfad
aus dem Formular und einer Datei stehen **zwei** Wände — die Normalisierung in
`Workspace::path()` und das `chroot`+`setuid` der Sandbox. Eine stumpfe Fassung,
die an beiden zugleich vorbeigreift, beantwortet „hätte der Angriff getroffen?"
und nicht „welche Wand hat ihn gehalten?".

> **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
> beiden etwas.**

**Der Durchgang läuft seit dem 18. August, und das Protokoll ist `docs/62`.**
**Alle fünfzehn Punkte sind auf `cloudsrv24` gemessen** — die Punkte 5, 7 und 8
gegen `4fe2e10`, die Punkte 9 bis 12 **durch das echte Formular** gegen
`v0.6.0-rc.17`, **Punkt 11 seit dem 19. August in allen 22 Zeilen**. Offen sind
nur noch die Reste, die dort einzeln benannt stehen —
ein Protokoll ohne seine Lücken liest sich wie eine Abnahme. Die Frage aus §1
ist beantwortet: **Nicht die Normalisierung hält, sondern das Chroot** (stumpf-A
hält weiter, stumpf-B bricht 3 von 3 aus).

Und der teuerste Fund des Laufs steckte im Prüfmittel: Die ersten drei Läufe
waren Zeile für Zeile identisch, weil die Eingriffe `Files\Workspace` treffen und
der Prüfstand dort nicht vorbeikam. `stumpf.sh --pruefen` meldete dabei zu Recht
„ist stumpf".

> **Ein Nachweis, dass der Eingriff wirkt, sagt nichts darüber, dass der
> Prüfkörper dort vorbeikommt.**

**Und der Bau der Punkte 7 und 8 hat einen Fehler gefunden, der mit dem Angriff
nichts zu tun hat.** `Archive::names()` zählte ein Tar über `foreach (new
PharData(…))` auf — also nur die oberste Ebene. Jedes Archiv mit einem
Unterverzeichnis verlor alles darunter, still, seit es das Merkmal gibt; Zip war
nie betroffen, weil `ZipArchive` über den Index aufzählt.

> **Eine Aufzählung, die Ebenen hat, zählt nicht dasselbe wie eine, die keine
> hat.**

**Kein Wächter dieses Repos hätte ihn finden können, weil keiner je ein Archiv
gebaut hat.** `ArchiveDepthTest` baut seine jetzt Byte für Byte selbst — auch
deshalb, weil `PharData` keinen Eintrag mit `..` schreiben kann und weil ein
Archiv aus dem Prüfling den Prüfling gegen sich prüfte.

Und einer über den Prüfkörper von Punkt 7, dessen Vorschrift in `docs/61 §6`
`../../../../` lautete: Vom Zielverzeichnis des Prüfstands aus landen vier
Schritte hinauf **innerhalb** der Wurzel des Abonnements.

> **Ein Prüfkörper, dessen Ziel von der Tiefe des Ordners abhängt, misst den
> Ordner und nicht die Grenze.**

**Und Punkt 12 hat den teuersten Satz dieses Abschnitts geliefert.** Im
Quelltext stand seit P6, `file_put_contents` melde bei voller Quota die Zahl der
geschriebenen Bytes; gemessen wird **`false`** — PHP wandelt den kurzen
Schreibvorgang selbst in einen Fehlschlag um und wirft die Zahl weg. Die Meldung
hing an einer Verzweigung nach genau diesem Wert, also war die, die das
Kontingent nennt, **unerreichbar**, und der Kunde las „Die Datei liess sich
nicht schreiben".

> **Zwei Meldungen für denselben Fall laufen auseinander — und die falsche ist
> die, die man bekommt.**

Der frisch gebaute Wächter war dabei grün: Er suchte den Satz im Quelltext, und
dort stand er — im anderen Zweig.

> **Ein Wächter, der einen Satz sucht statt seiner Erreichbarkeit, ist grün,
> sobald der Satz irgendwo steht.**

**Und derselbe Punkt hat seinen Prüfkörper zweimal verfehlt** — beide Male sah
es nach einem Ergebnis aus: einmal starb er an der 1-MiB-Grenze des Protokolls,
bevor das Kontingent ihn sah, einmal lief die Messung bei 390 px auf der Seite
**ohne** die Meldung.

> **Ein Prüfkörper, der die Seite ohne den Gegenstand misst, misst die Seite und
> nicht den Gegenstand.**

**Und Punkt 11 hat vier Fehler des Messmittels gebraucht, bis er lesbar war** —
alle vier in ihm und keiner im Panel. Der teuerste hat Daten gekostet: Der Kopf
des Skripts behauptete, der Lauf verändere nichts, weil der Rumpf weggelassen
wird und jede verändernde Route damit an ihrer Prüfung scheitert. `DELETE
/cron/{job}` prüft aber keinen Rumpf — es löscht. Zweimal ist so ein Cronjob
verschwunden, und beim ersten Mal sah es aus, als sei er „nicht gespeichert
worden".

> **Ein Vorgang, der nichts entgegennimmt, hat nichts, woran er scheitern
> kann.**

Für eine löschende Route heisst „durchgelassen" wörtlich „hat gelöscht"; anders
ist ihre Erreichbarkeit nicht zu belegen. Die drei anderen: eine
`X-Inertia`-Kopfzeile, die den 409 vor der Policy erzeugte; ein
`redirect: 'manual'`, das jede Weiterleitung zu einer `0` machte; und eine
Spalte, die „eindeutig" meldete, während die Gegenprobe daneben „BLIEB HÄNGEN"
sagte.

> **Ein Statuscode nach einer gefolgten Weiterleitung gehört einer anderen
> Anfrage.**

**Und der fünfte Fehler von Punkt 11 steckte nicht im Skript, sondern in dem,
was ich ihm übergeben habe.** Der Lauf bekam `eigenJob: 4` — die Kennung aus der
Messung der Punkte 9 und 10, und die lag auf dem **fremden** Abonnement:
`/etc/cron.d/srvpanel-p1136` gehört zu 137, nicht zu 140 (das läuft als
`p1139`). Drei der 22 Zeilen meldeten „BLIEB HÄNGEN", und das liest sich wie ein
Befund am Panel.

> **Eine Kennung, die man von einer Messung in die nächste mitnimmt, trägt ihr
> Abonnement nicht mit.**

Gefangen hat es die Gegenprobe — aber erst danach. `mandant-messen.js` hat
seitdem einen **Vorflug**, der die eigenen Zweitkennungen vorher liest und die
fremde Seite dabei nicht anfasst; `TenancySweepTest` prüft beide Richtungen.

**Daneben fiel ein Fehler heraus, der mit dem Angriff nichts zu tun hat:**
`FilesRead::MAX_BYTES` und `FilesWrite::MAX_BYTES` standen auf 2 MiB,
`Connection::REQUEST_MAX` auf 1 MiB — und der Inhalt einer Datei reist als Feld
*in* dieser einen Zeile. Die Hälfte der erklärten Grenze war nie zu erreichen;
eine Datei dazwischen öffnete sich im Editor und liess sich nie speichern, mit
einer Meldung über das Protokoll statt über die Datei.

> **Ein Wert, der grösser ist als der Weg dorthin, ist keine Grenze.**

**Behoben am 19. August** (`docs/62` Punkt 12b): `Connection::CONTENT_MAX` ist
die eine Zahl, an der beide Grenzen hängen, und `Client::call()` misst die
fertig kodierte Zeile, bevor sie über den Socket geht. `TransportLimitTest`
rechnet die Hüllengrösse nach, statt sie zu glauben.

**Und die Begründung dieses Befundes war beim ersten Ausschreiben falsch.** Sie
lautete, deutscher Text wachse als JSON um 1,71× und reisse die Grenze schon bei
620 KB, weil aus `ü` sechs Zeichen `\u00fc` würden. Das gilt für `json_encode`
mit seinen Voreinstellungen — und `Client::call()` setzt seit dem 11. August
`JSON_UNESCAPED_UNICODE`. Nachgemessen mit den Fahnen, die er wirklich führt:
deutsche Prosa **1,02×**, Umlaute **1,00×**, PHP mit Zeichenketten 1,12×,
Steuerzeichen 6×.

> **Ein Faktor, der an anderen Fahnen gemessen wurde, gehört zu einer anderen
> Leitung.**

Der Schluss stimmte trotzdem, die Zahl nicht — und ein Wächter, der einen Faktor
führt, hätte den Irrtum bloss festgeschrieben. `TransportLimitTest` baut deshalb
die volle Zeile und misst sie.

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
- **PowerDNS gibt es hier auch — es ist nur nicht installiert.** Derselbe Satz
  wie bei MariaDB, beim `sshd` und bei PHPStan, und diesmal stand „ungemessen"
  ausdrücklich in der Übergabe. `apt-get update && apt-get install -y
  pdns-server pdns-backend-sqlite3 pdns-backend-mysql pdns-tools` holt
  **4.8.3-4build3** aus `noble/universe` — dieselbe Fassung, die Ubuntu 24.04
  ausliefert. Zwei Wegwerf-Instanzen auf eigenen Ports, eine je Backend, rühren
  nichts an. Gemessen am 21. August 2026 (`docs/71 §2`).

  Drei Handgriffe, die Zeit gekostet haben: **`apt-get update` zuerst** (sonst
  404 auf drei Dateien aus einem veralteten Index); **der Sockelpfad muss kurz
  sein**, wie bei PostgreSQL, also `socket-dir=/tmp/pd` und die Daten im
  Scratchpad; und **`--config-name=mysql` sucht `pdns-mysql.conf`**, nicht
  `pdns--mysql.conf` — der Bindestrich steht schon drin, und ein Lauf mit
  `--config-name=-mysql` meldet „no backends configured for querying", was nach
  einem Fehler in der Konfiguration aussieht und einer im Aufruf ist.

  Die Messvorschrift liegt als **`tests/dns-messen.sh`** daneben, mit einer
  Gegenprobe je Messung. Ihr erster Lauf hat einen Fehler in ihr selbst
  gefunden: Die Atomaritätsprobe fragte den Nameserver nach einem Namen, den es
  nicht geben durfte — und bekam die Antwort des Platzhalters aus derselben
  Zonenvorlage.

  > **Eine Gegenprobe, die ein Platzhalter beantwortet, hat den Gegenstand nicht
  > gefragt.**
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
  - **`phpstan.phar` gibt es genauso, und zwar von derselben Stelle.** Oben
    stand neun Monate lang „nachinstallieren geht auch nicht", und das war eine
    Aussage über `composer install` — nicht über PHPStan. `curl -sSL -o
    phpstan.phar
    https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar`
    holt ihn, gemessen am 17. August 2026 (2.2.8). Die Auslieferungsdateien der
    GitHub-Releases kommen nicht über `codeload.github.com`, und genau die
    sperrt der Proxy.

    **Die Projektdatei `phpstan.neon` taugt dafür nicht** — sie bindet larastan
    ein, und ohne `vendor/` bricht der Lauf mit „is missing or is not readable"
    ab. Eine dreizeilige Wegwerfdatei im Scratchpad (`level: 6`,
    `treatPhpDocTypesAsCertain: false`) und `-c` darauf genügt.

    > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
    > braucht einen Versuch.** Derselbe Satz wie bei MariaDB und beim `sshd` —
    > diesmal an einem Werkzeug, das in dieser Datei als unerreichbar geführt
    > wurde.
  - **PHPStan taugt nur für `agent/`.** Ohne `vendor/` fehlt larastan, und
    jedes `Model::query()` und jede Spalte gilt als undefiniert — hunderte
    Meldungen, die nichts bedeuten. Unterhalb von `agent/` gibt es kein
    Framework, und dort läuft Stufe 6 sauber durch. Genau so gefunden:
    `array_values()` auf einer Liste, die schon eine ist.

    **Und die Dateiliste kommt aus dem Zweig und nicht aus dem Gedächtnis.**
    Am 22. August 2026 hat die CI acht PHPStan-Meldungen zu P7 gebracht, jede
    davon hier auffindbar — der Lauf war über `agent/src`, `tests/Support` und
    die framework-freien Klassen gefahren, also über die Pfade, die in diesem
    Abschnitt stehen, und nicht über die Dateien, die der Zweig anfasst. Ein
    Lauf über `git diff --name-only origin/main...HEAD` meldet dieselben acht
    Zeilen auf denselben Zeilennummern.

    > **Ein Werkzeug, das man über die gewohnten Pfade fährt, prüft die
    > Gewohnheit und nicht die Änderung.**

    **Und die geänderten Dateien allein reichen nicht — die Schnittstellen,
    die sie umsetzen, gehören dazu.** Am 22. August meldete ein solcher Lauf
    dreizehnmal `argument.type`: „`ScriptedMeasurement` given, `Measurement`
    expected". Beide Klassen sind in Ordnung; `Measurement.php` war bloss nicht
    im Lauf, weil der Zweig sie nicht geändert hatte, und ohne sie kann PHPStan
    das `implements` nicht auflösen. Mit der Schnittstelle daneben ist die
    Ausgabe leer.

    > **Ein Prüfer, dem die Schnittstelle fehlt, meldet nicht „ich kenne sie
    > nicht" — er meldet, dass die Klasse sie nicht erfüllt.**

    Die teuerste der acht war keine Typangabe: Zwei neue Methoden waren
    zwischen `diskQuota()` und seinen Dokumentationsblock gerutscht, und der
    versprach über `dnsAddresses()` ein `array{available: …}`, wo ein
    `list<string>` steht. Gemeldet wurde die Hälfte, die ein Werkzeug sehen
    kann — der fehlende Kommentar an der einen Methode, nicht der falsche über
    der anderen.

    > **Ein Werkzeug bemerkt den fehlenden Kommentar. Den falschen bemerkt es
    > nicht.**

    **Für eine einzelne neue Datei geht trotzdem mehr, als es aussieht.** Ein
    Lauf über *nur* die geänderte Datei bringt zwar ein Dutzend
    `method.notFound` für jede `assert…` — aber alles, was PHPStan aus dem
    Code selbst schliesst, steht dazwischen und ist echt. Gefiltert nach
    Kennungen, die nichts mit fehlenden Klassen zu tun haben
    (`--error-format=raw`, dann ohne `notFound`), fällt zum Beispiel ein
    `function.impossibleType` heraus: ein `in_array($x, self::LEER, true)`
    gegen eine leere Konstante. Genau der hat eine CI-Runde gekostet, und die
    Gegenprobe zeigt, dass er lokal auffindbar gewesen wäre.

    **Und diese Filterung braucht ihre eigene Gegenprobe.** „Nach dem Filter
    steht nichts mehr da" sieht genauso aus, ob PHPStan die Datei sauber fand
    oder sie gar nicht erst gelesen hat. Ein absichtlich falscher Typ muss dort
    eine Zeile erzeugen — sonst misst der Lauf nichts:

    > **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als
    > Null steht.**

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

  **Und die Attrappe muss die `final`-Methoden der echten Basisklasse tragen.**
  Ohne sie meldet das Gestell Grün für eine Klasse, die `php artisan test` mit
  Rückgabewert 255 tötet, bevor ein einziger Test läuft — am 18. August genau so
  passiert, mit einer Hilfsmethode namens `run()`. Mindestens `run()`, `count()`,
  `matches()` und `toString()` gehören als `final` in den Stub.

  > **Eine Attrappe, die weniger verbietet als das Original, sagt Ja zu Code,
  > den das Original ablehnt.**

  **Und dasselbe noch einmal am 18. August, an einer anderen Strenge: der Zahl
  der Werte eines Datenlieferanten.** `invokeArgs()` reicht überzählige Werte
  wortlos weiter; PHPUnit meldet je Methode eine Warnung und endet mit
  Rückgabewert **1**, ohne dass eine einzige Behauptung gebrochen ist. Im Log
  steht dann „2040 passed" und darunter der Fehlschlag — die Zahl der Warnungen
  ist das einzige, was sich ändert (36 → 39). Das Gestell prüft seitdem
  `getNumberOfParameters()` gegen die Zahl der Werte.

  > **Ein Lauf, der „alle bestanden" meldet und trotzdem scheitert, hat seinen
  > Grund neben der Zusammenfassung stehen und nicht darin.**

  **Und die Liste der Wächter zählt sich selbst ab.** Am 13. August stand dort
  eine handverlesene — dreizehn von 136 —, und drei Wächter, die eine
  Umbenennung gemeldet hätten, waren nicht darunter; sie liefen erst in der CI.

  > **Eine Prüfung, die nur nachsieht, woran man gerade denkt, prüft das
  > Erinnerungsvermögen.**

  **Und derselbe Satz noch einmal am 20. August, an `BreakScriptTest`.** Der
  Wächter über die Wächter läuft hier — er erbt nur von `TestCase`. Gefahren
  worden sind die 46 Fälle der Wächter, die in der Bilderrunde **entstanden**
  sind; dass eine erweiterte Regel in `app.css` einem **bestehenden** Eingriff
  seinen Text wegnimmt, hat erst die CI gemeldet. Wer eine Regel anfasst, fährt
  `BreakScriptTest` mit, und zwar unabhängig davon, ob dabei ein Wächter
  entstanden ist.

  > **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
  > gefahren — man denkt an das Gebaute und nicht an das Berührte.**

  **Und ein einzelner Eingriff des Bruchskripts lässt sich hier ebenfalls
  fahren.** `tests/waechter-brechen.sh` als Ganzes braucht `vendor/bin/phpunit`
  und läuft nicht — der einzelne Eingriff schon: Datei sichern, den
  Python-Block von Hand anwenden, den Wächter im Gestell fahren, Datei
  zurückholen. Am 20. August hat genau das einen Wächter überführt, der
  **wirkungslos** war, obwohl der Eingriff die Datei nachweislich veränderte
  (`docs/64 §2`). Wer einen Eingriff schreibt oder den Wächter dahinter anfasst,
  belegt ihn so, statt ihn ungeprüft zu pushen.

  > **„Das Bruchskript läuft hier nicht" ist keine Ausrede, sondern ein
  > Handgriff mehr.**

  **Und welche Eingriffe man fährt, sagt nicht das Gedächtnis, sondern der
  Zweig:** alle, deren `vorher_datei` eine Datei nennt, die dieser Zweig
  geändert hat. Am 23. August 2026 hat genau das gefehlt. Ein Eingriff von
  gestern brach `.toggle:has(input:disabled)`, und sein Zieltest fragte „gibt es
  für diese Hülle **eine** Regel mit `disabled`?". Die Behebung von Befund 6 gab
  `.toggle` eine **zweite** — die fürs Kästchen —, und die beantwortete die
  Frage mit. Der Eingriff änderte die Datei weiter nachweislich und biss nicht
  mehr; gemeldet hat es der Wochenlauf.

  > **Eine zweite Regel für dieselbe Hülle macht die Frage „gibt es eine?"
  > stumpf.**

  > **Ein Eingriff geht nicht nur kaputt, wenn seine Zielstelle umzieht — auch,
  > wenn jemand neben seiner Regel eine zweite baut, die dieselbe Frage
  > beantwortet.**

  Nachgeholt über die vierzehn Dateien dieses Zweiges: **53 Eingriffe, alle
  beissen.** Ein Wegwerfskript im Scratchpad genügt dafür — es wendet den
  Python-Block an, fährt den genannten Test im Gestell und holt die Datei
  zurück. Es gehört **nicht** ins Repo: Das wäre eine zweite Fassung von
  `tests/waechter-brechen.sh`, und die zweite veraltet.

  **Zwei Fallen dabei, beide bezahlt.** Der Lauf über alle 649 Eingriffe
  braucht mehr als zwei Minuten und wird abgebrochen — ein Abbruch mitten im
  Eingriff lässt die Datei kaputt liegen (`git status` vorher und nachher
  vergleichen). Und `sort -u datei | tee datei` leert die Datei, bevor `sort`
  sie liest.

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

  **Und eine zweite, die inzwischen fünfmal zugeschlagen hat: ein Name, der
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

  **Und am 19. August 2026 hat sie ein fünftes Mal zugeschlagen** — `run()` als
  private Hilfsmethode in einem frisch gebauten Feature-Test. Drei CI-Zweige
  fielen daran, und der Bruchlauf meldete korrekt „Der Testaufruf liefert nichts
  Lesbares". Seitdem gibt es **`BaseMethodClashTest`** dafür: Er spiegelt die
  `final`-Methoden von `PHPUnit\Framework\TestCase` und sucht ihre Namen als
  Deklaration unter `tests/Unit`, `tests/Feature` und `tests/Support`.

  > **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern eine
  > Gewohnheit.**

  Zwei Dinge daran sind lehrreich. Erstens ist der Wächter **hier fahrbar** —
  er erbt nur von `TestCase` und läuft im Gestell —, während der Fehler selbst
  in einem Feature-Test steckte, den das Gestell nicht laden kann: Der Wächter
  greift genau dort, wo die anderen Mittel nicht hinkommen. Zweitens lässt sich
  seine Regel **nicht** absichtlich brechen — eine echte Kollision tötet den
  Lauf beim Laden, bevor irgendein Wächter rot werden kann. Gebrochen werden
  deshalb seine Teile: der Anker des Ausdrucks, die Aufzählung der Dateien und
  die Spiegelung der Basisklasse.
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
  - **Kein `| head` über dem Messlauf.** `head` schliesst die Leitung nach der
    ersten Zeile, node stirbt am SIGPIPE — und die Aufnahmen der übrigen drei
    Lagen sind dann die des **vorigen** Laufs. Am 23. August genau so passiert:
    Hell zeigte den neuen Stand, Dunkel den alten, bei identischem Stylesheet,
    und das las sich eine Weile wie ein Fehler im Theme. Der Lauf gehört in eine
    Datei und die Datei danach gelesen.

    > **Ein Bild, das ein abgebrochener Lauf hat liegen lassen, sieht aus wie
    > ein Ergebnis — es trägt kein Datum im Bild.**
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
