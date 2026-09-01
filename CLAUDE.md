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

**A9 ist am 25. August 2026 abgenommen** — auf `cloudsrv24` gegen
`v0.7.1-rc.2`, die Punkte 1 bis 10 aus `docs/83`, der Lauf ist `docs/83` und das
Protokoll **`docs/84`**. Im selben Lauf sind **A1 Schritt 1** (Punkt 11, M5 auf
einem echten Server) und **A5** (Punkt 12) belegt.

**A1 ist am 28. August 2026 abgenommen** — der Lauf war am 27. August auf
`cloudsrv24` gegen `0.7.2-rc.3`, alle fünfzehn Punkte aus `docs/85`, das
Protokoll ist **`docs/86`**, die Abnahme selbst steht in dessen §6. Die beiden
Ausfälle sind genau die, die `docs/85 §6` zulässt (Punkt 4 ohne ablaufenden
Schlüssel, Punkt 2 ohne Neuinstallation im Bestand).

**Sechs Reste blieben benannt offen und waren kein Kriterienausfall**
(`docs/86 §5`); **vier davon sind am 28. August gebaut**. Der grösste war eine
Familie und keine drei Einzelfälle:

> **Ein Vorgang, der nur meldet, dass er abgesetzt wurde, sagt über den Ausgang
> dessen, was er abgesetzt hat, nichts — und `fertig` liest sich wie das
> Gegenteil.**

**Sie zerfällt beim Nachmessen in zwei.** „Dreimal dieselbe Form" stimmt für das
Symptom und nicht für die Ursache: `system.packages.upgrade` und `system.reboot`
setzen ab und kennen den Ausgang nicht (**Form A**), `system.packages.refresh`
ist fertig und trägt den unvollständigen Ausgang schon im Ergebnis (**Form B**).
`panel.update` hat gar keinen Vorgang.

> **Ein gemeinsames Symptom ist noch keine gemeinsame Ursache — und die
> Zusammenfassung, die beides verschmilzt, spart die Unterscheidung ein, die die
> Behebung braucht.**

**Keine der absetzenden Operationen übergibt `--wait`, und das ist tragend** —
Punkt 5 gibt es genau dafür, dass der Lauf den Neustart des Panels überlebt. A
ist deshalb keine Fahne, sondern eine Nachlese: `AwaitDispatchedRun` liest alle
fünfzehn Sekunden das Urteil, das `apt-run` ohnehin schreibt, **ab dem Versatz
des eigenen Laufs** — `upgrade.log` sammelt Läufe, und `--collect` räumt die Unit
auch im Fehlerfall ab.

> **Ein Zustand, der nach dem Ende verschwindet, ist kein Urteil über das
> Ende.**

B braucht davon nichts: Der Betreiber hat entschieden, dass **der Zustand bleibt
und der Vorbehalt sichtbar wird**. Was fehlte, war nicht der Zustand, sondern die
Sicht — die Meldung stand im Payload, und keine der sechs Spalten hat sie
gerendert.

> **Ein Feld im Payload ist noch keine Spalte.**

**Offen bleiben genau zwei:** Befund 14 (die Fusszeile von `/logs`, gehört zu A5)
und die ungemessene Laufzeit über 142 Pakete (`docs/81 §2.3h` Punkt 1). **Und
keine der vier Behebungen hat einen Server gesehen** — der Nachlauf dazu ist
`docs/87`, ausgeschrieben vor dem Fahren.

**Punkt 5 ist der Grund, dass es diesen Lauf gab, und er ist zweifach belegt.**
Dass eine transiente Unit den Neustart von `srvpanel-worker` überlebt, wenn
`srvpanel` selbst im Lauf steckt, behauptet dieses Projekt seit P0 und belegt
hatte es nur der eigene Gebrauch. Jetzt steht es auf zwei unabhängigen Wegen da:
einmal durch den Neustart aus dem eigenen postinst-Skript, einmal durch
`needrestart` in einem Lauf, in dem `srvpanel` gar nicht vorkam — dort traf es
zusätzlich den Agenten.

> **Ein Beleg, der zweimal auf verschiedenen Wegen entsteht, ist keine
> Wiederholung — der zweite schliesst aus, dass der erste an seinem Weg hing.**

Die **Rollenteilung** aus `docs/81 §3` Frage 2 ist am **27. August 2026 gebaut**
(`docs/81 §2.3m`): Die Updates-Seite gehört jetzt beiden — der Administrator
sieht über `inspect-server`, gedreht wird über `operate-server`. **Die
Bilderrunde dazu ist gefahren** (`docs/81 §2.3n`): acht Lagen, Überlauf 0 px,
Gegenprobe 200/200 — und bei 390 px ist die Ansicht des Administrators
**1940 px kürzer**. **Seit dem 28. August hat jeder Vorschlag einen Ort** — A8 und A3s erster Wurf in P7b, A3s zweiter und A4 in der neuen Stufe **P9b** vor P10, A7 stand längst in P9. Die sechzehn Befunde und sechs Beobachtungen des
A9-Laufs stehen mit ihrer Baureihenfolge in `docs/84 §7`.

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

**Und ein Befund, der beim Abräumen danach herausfiel:** Der Rückbau einer
Domain nimmt ihr Zertifikat nicht mit, und `srvpanel tls --prune` holte es auch
später nicht — es räumte nur Zertifikate **zurückgebauter Abonnements** ab, und
hier lebt das Abonnement weiter. Gemessen an Zertifikat 26 nach dem Löschen von
`tls.cloudlab24.de`: null verweisende Domains, `privkey.pem` lag.
`CertificatePrune` kennt seitdem zwei Arten ungebrauchter Zeilen — verwaist und
**ohne Domain** —, und **gefragt wird nach der Deckung und nicht nach der
Zuordnung**: Ein Platzhalter deckt eine lebende Domain, ohne ihr zugeordnet zu
sein, und wer nur `domains.certificate_id` fragte, löschte den Schlüssel unter
einer laufenden Website weg. Im Zweifel gilt eine Zeile als gebraucht.

Zwei Stellen wären dabei beinahe stehengeblieben, beide zweite Fassungen
derselben Regel: `forget()` mit der ausgeschriebenen Waisenbedingung und der
Ausstieg des Kommandos an `orphans === 0`.

**Was ein Betreiber danach von Hand tut:** `srvpanel vhost --sites`. Das Update
zieht den Block der Oberfläche nach, die der Kundendomains nicht — und die
zeigen bis dahin auf den alten Ort.

**Nachgesehen am 24. August gegen `0.7.0-rc.9`** (`docs/78 §5`): `200` auf die
Prüfdatei, `404` auf einen Namen, den es dort nicht gibt, und alle vier Blöcke
tragen dieselbe eine `root`-Zeile. Die Kette bis zum **ausgestellten**
Zertifikat brauchte einen zweiten Anlauf — die zwei Bestellungen aus
`vhost --sites` galten Namen unter `.invalid` und sind zu Recht abgewiesen
worden, es gab also keinen Fall, an dem sie sich hätte zeigen können.

> **Ein Beleg für den Weg ist keiner für das Ziel.**

Hergestellt wurde er mit einer neuen Unterdomain: Vorgang 682 `succeeded`,
`subject=CN = tls.cloudlab24.de`, Let's Encrypt YR1, `notBefore` von derselben
Minute — über HTTP-01, weil das Abonnement keine DNS-Zugangsdaten trägt. Und
die erste Fassung dieser Messung war keine: Ein ungesetzter Platzhalter liess
`openssl` ohne SNI gegen den Vorgabeblock laufen, und heraus kam ein gültig
aussehendes Zertifikat mit dem falschen Namen.

> **Ein Prüfkörper, der ohne seinen Gegenstand misst, misst etwas anderes und
> sieht dabei aus wie ein Ergebnis.**

Und eine Falle beim Nachsehen: **`srvpanel tinker` läuft ohne angemeldetes
Konto.** Jedes Modell mit `BelongsToSubscription` steht dort auf
`whereRaw('0 = 1')` — ohne `withoutGlobalScopes()` kommen null Zeilen zurück,
und zwar wortlos. Gemessen als Paar: `mit Klammer: 0` · `ohne Klammer: 679`.

> **Eine Frage, die im Grundzustand alles verweigert, antwortet mit einer leeren
> Liste und nicht mit einem Fehler.**

---

## P7b, die Serververwaltung — der erste Handgriff war ein Befund

**Die Stufe heisst P7b**, entschieden am 24. August 2026; `docs/20 §9` trägt sie
zwischen P7 und P8. Geplant wurde sie als „P9a", weil `docs/20 §9` die
Serververwaltung in P9 führte — sie hängt aber davor, und damit war der Name
falsch.

> **Ein Name, der eine Reihenfolge behauptet, wird falsch, wenn die Reihenfolge
> sich ändert — und er wird trotzdem weiterbenutzt, weil er in Überschriften
> steht.**

**A9 ist am 24. August vorgezogen worden** — zwei Verwaltungsrollen **und die
Kontenverwaltung**, ausgeschrieben als **`docs/82`**. Der Grund: Wer eine
Adminfunktion baut, entscheidet beim Bauen, auf welcher Seite sie liegt, und
jede Woche später sind das mehr Funktionen, die es nachtragen müssten. Sie steht
in P7b an zweiter Stelle, nach A5.

**Beim Ausschreiben fiel auf, dass die Skizze eine Fähigkeit voraussetzt, die
es nicht gibt:** `docs/81 §11` führt „Konten, Rollen, IP-Beschränkung" in seiner
Rollentabelle — eine Kontenverwaltung gibt es aber nirgends. Adminkonten
entstehen ausschliesslich über `srvpanel admin` auf der Kommandozeile.

> **Eine Tabelle, die eine Fähigkeit einer Rolle zuordnet, setzt voraus, dass es
> die Fähigkeit gibt — und sagt nichts darüber, ob sie jemand gebaut hat.**

Und der Fund, der den Entwurf entscheidet: `audit_events.account_id` steht auf
`nullOnDelete()`. Ein gelöschtes Adminkonto zieht damit seine **ganze
Protokollhistorie** auf `null`.

> **Ein Protokoll, aus dem sich der Handelnde nachträglich entfernen lässt, ist
> kein Protokoll — es ist eine Liste von Ereignissen.**

Adminkonten werden deshalb **gesperrt und nicht gelöscht**; den Zustand
`disabled` gibt es längst, und drei Stellen fragen ihn schon.

**Die Schritte 1 und 2 sind gebaut** — die Schritte 3, 5 und 7 stehen weiter
unten unter „A9 ist gebaut". `AdminRole` ist die zweite Achse — kein
vierter `AccountType`, weil der an 52 Stellen `isAdmin() === false` ergäbe und
der neue Betreiber eine leere Kundenliste sähe. Die Spalte `accounts.role` ist
nullable und ohne Vorgabe (`null` heisst „kein Admin"), und **die Rolle allein
gewährt nichts**: `Account::fulfils()` fragt beide Achsen.

Seit Schritt 2 lösen die Gates darüber auf — **eine Zeile im Provider**, keine
Aufrufstelle, kein `can`-Schlüssel, kein Bild. Genau dafür war die Naht zwei
Tage vorher gelegt worden.

**Der Aufwand von Schritt 2 lag woanders**, und das ist die Lehre: Sobald die
Gates die Rolle fragen, ist ein Adminkonto **ohne** Rolle wirkungslos — es
meldet sich an und darf nichts. `CreateAdmin` und `AccountFactory` mussten mit.

> **Eine Änderung, die eine Angabe zur Pflicht macht, muss jede Stelle
> mitnehmen, die sie erzeugt — sonst ist der erste neue Datensatz kaputt.**

Zwei Wächter halten das: `RoleGateTest` misst die Wirkung an der Tür (CI),
`RoleResolutionTest` hält im Quelltext, was ohne Framework prüfbar ist. Sein
Bruch hat dabei ein Loch in ihm selbst gefunden — der Ausdruck kannte
`=> AccountType::Admin` und übersah `=> \App\Enums\AccountType::Admin`.

> **Ein Wächter, der nur die gewohnte Schreibweise kennt, prüft die Gewohnheit
> und nicht die Regel.**

**Seit dem 28. August 2026 hat jeder Vorschlag einen Ort** (`docs/81 §12.1`).
**A7 hatte ihn längst** — er steht in der Aufzählung von P9 als
„Ressourcenüberwachung des Servers, Schwellen, Benachrichtigungen"; dass er
unter P7b als „hat noch keine Stufe" geführt wurde, war ein Widerspruch im
selben Dokument, das sechzig Zeilen weiter nur „Firewall und Fail2ban" nennt.

> **Zwei Zeilen desselben Dokuments über dieselbe Frage laufen auseinander, und
> keine von beiden ist der Ort, an dem man nachsieht.**

**A3 ist geteilt:** der erste Wurf (nur Anzeige — welche Ports lauschen) nach
P7b, weil das Panel seit P5b 3306 und 5432 öffnet und nicht sagen kann, ob sie
erreichbar sind; der zweite Wurf und **A4** in die neue Stufe **P9b**, zwischen
P9 und P10.

> **Eine Härtungsstufe, die selbst noch baut, prüft ihr eigenes Werk.** P10
> enthält den Angriffsdurchgang und den externen Review — was dort entsteht,
> wird von dem Durchgang begutachtet, der es hätte prüfen sollen.

Der Plan ist **`docs/81`** (A1 vollständig, die übrigen als Skizze), die
Bestandsaufnahme **`docs/80`**, die Übergabe **`docs/79`**.

**Angefangen wurde nicht mit einem Merkmal, sondern mit M5** — Schritt 1 aus
`docs/81 §9`, ein Befund an Code, der seit P1 ausgeliefert ist:

> **`apt-get update` gibt 0 zurück, auch wenn jede einzelne Quelle unerreichbar
> war.** Die Fehlschläge stehen als `W:`-Zeilen auf stderr, apt arbeitet mit den
> alten Listen weiter, und der Rückgabewert sagt nichts.

Das ist keine Nachlässigkeit von apt, sondern seine Zusage: Gefragt ist nicht
„habe ich alle Quellen erreicht", sondern „habe ich danach einen benutzbaren
Zustand". Bis dahin stand an drei Stellen `if (! $update->successful())` und
sonst nichts.

> **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine Prüfung
> — er ist eine Zeile, die aussieht wie eine.**

`Apt` ist jetzt die eine Stelle, die `apt-get update` ruft, und liest `stderr`
**je Quelle**. Sie entscheidet nichts — das tun die Aufrufer, und zwar
verschieden: `php.version.install` bricht an seiner **eigenen** toten Quelle ab
(die Adressen liest `PhpVersions::sourceUris()` aus der Datei, die
`packaging/php-source.sh` schreibt), `pg.server.install` nennt die Ausfälle nur,
weil es sein Depot noch nicht kennt. Nicht `--error-on=any`:

> **Eine Härte, die nur einheitlich zu haben ist, gehört nicht an eine Stelle,
> an der die Aufrufer verschieden entscheiden müssen.**

**Was der Fehler anrichtete, ist die eigentliche Lehre.** Bei unerreichbarer
Sury las der Betreiber *„Unable to locate package php8.4-fpm"* — der Zustand
richtig gemeldet, die Ursache falsch.

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

**Und der Fund am Prüfmittel wiegt schwerer als der am Prüfling.** Die Messung
zu M5 schrieb `>datei 2>&1` und zählte darin die `W:`-Zeilen. Damit war belegt,
dass sie auf *einem der beiden* Kanäle stehen — nicht, auf welchem. Im Kopf der
Messung stand trotzdem „sie stehen auf stderr", und genau daran hängt der neue
Leser: Stünden sie auf stdout, fände er wortlos nichts.

> **Eine Messung, die zwei Dinge zusammenwirft, belegt keines von beiden.**

Nachgemessen mit getrennten Kanälen und Gegenprobe auf beiden Seiten: stdout 0
Bytes, stderr 244, zwei `W:`-Zeilen, davon **eine** eine Quelle — die zweite ist
die Zusammenfassung und steht einmal da, egal wie viele ausgefallen sind.

**Zwei Wächter, sechs Brüche, alle einzeln belegt.** `AptResultTest` sucht
ausdrücklich **nicht** das Wort `successful()`; er zählt die Aufrufe von
`apt-get update` und misst die Wirkung an einem selbstgebauten Ergebnis mit
Rückgabe 0. `PhpSourceUriTest` hält die Naht zur Paketierung — liefe sie
auseinander, bliebe der Abbruch aus, und M5 wäre still wieder da.

> **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun".**

**Zwei bestehende Wächter haben dabei zugebissen**, beide beim Bauen und keiner
in der CI: `AnchoredPatternTest` am ersten `$` ohne `D` im neuen Leser, und
`GuardReachTest` an einem Kommentar, der `AptResultTest` nannte, bevor es ihn
gab.

**Schritt 2 ist gebaut, und er war derselbe Befund von der anderen Seite.**
Zwei apt-Läufe enden in der dpkg-Sperre; gefragt hat danach genau eine der vier
apt-rufenden Operationen, und ihre Frage sah nur die **eigenen** abgesetzten
Läufe. `queue:work` ist einspurig, aber `panel.update` setzt seinen Lauf über
`systemd-run` **ausserhalb** ab — in diesem Fenster ist die Kollision in beiden
Richtungen offen.

`AptLock` fragt jetzt die Sperre selbst, über `/proc/locks`. **Nicht über
`flock()`, und das ist gemessen:** dpkg nimmt eine POSIX-Sperre über `fcntl`,
PHPs `flock()` spricht `flock(2)`, und die beiden Familien sehen einander nicht.

> **Eine Sperre, die man mit dem falschen Werkzeug abfragt, meldet immer frei.**

Zugeordnet wird über den **Inode allein** und nicht über Gerät und Inode: Die
Umrechnung von `dev_t` gilt nicht für jede Bauart, und läge sie daneben,
entstünde ein falsches Negativ statt einer Ablehnung zuviel.

> **Wenn eine Zuordnung schiefgehen kann, entscheidet die Richtung, in die sie
> schiefgeht.**

**Und beim Verschieben fiel M5 ein zweites Mal an, nur andersherum.** Die alte
Prüfung las ausschliesslich `stdout` und schloss aus einer leeren Ausgabe „es
läuft keiner"; ohne systemd meldet `systemctl` aber `rc=1` mit der Auskunft auf
`stderr`. Die Frage war nicht beantwortet, und geraten wurde in die Richtung,
die einen kollidierenden Lauf losgehen lässt.

> **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu tun".**

Dazu ein Fund von PHPStan am frisch gebauten Wächter: eine leere Ausnahmeliste,
gegen die geprüft wurde.

> **Eine leere Positivliste ist kein Mechanismus, sondern eine Verzierung.**

**Und die Naht für A9 ist gelegt, bevor A9 gebaut wird.** `docs/20 §6.1` teilt
die Admin-Ebene in **Betreiber** und **Administrator**; P7b baut vier
Adminfunktionen, bevor A9 drankommt. Käme die Teilung danach, müsste jede Seite
ihre `can`-Ablage und ihren Bildsatz ein zweites Mal bekommen — `AbilityReachTest`
besteht darauf, dass ein Knopf, den der Betrachter nicht drücken darf, gar nicht
gezeigt wird.

Nachgezählt: 126 Routen tragen `can:`, aber nur **eine** war eine
Adminfähigkeit. Seitdem sind es zwei — `operate-server` (11 Routen: PHP,
Datenbank-Fernzugriff, Mailversand, Panel-Zertifikat, DNS) und
`manage-settings` (2: die Anzeigezeitzone). **Beide lösen auf `isAdmin()` auf**;
A9 ändert damit *eine Zeile* statt jeder Aufrufstelle.

`AdminAbility` ist nach dem Vorbild von `RouteGuard` gebaut, und die
**Voreinstellung ist der Betreiber**:

> **Der Fehler fällt damit zur sicheren Seite.** Eine Seite, die versehentlich
> zu streng ist, meldet sich beim Administrator; eine, die versehentlich zu
> offen ist, meldet sich nie.

Vier Kommentare nannten nach dem Umzug die falsche Fähigkeit — die Fehlerklasse
dieses Projekts in ihrer harmlosesten Form:

> **Ein Kommentar, der eine Fähigkeit nennt, ist derselbe Verweis wie ein `can:`
> im Code — nur prüft ihn nichts.**

**A5 ist gebaut — die Protokolle des Servers an einer Stelle.** Positivliste im
Agenten (`SrvPanel\Agent\Logs`), sieben Dateien und das Journal der acht eigenen
Units; **kein Pfad und keine Unit kommen von aussen**, übergeben wird ein
Schlüssel. Die Seite gehört dem Betreiber, entschieden beim Bauen: Ein
Stacktrace in `laravel.log` trägt, was ihn ausgelöst hat — bei einer Ausnahme im
Verbindungsaufbau also die Zugangsdaten der Datenbank.

**Der Fund der Messrunde ist M5 zum dritten Mal.** `journalctl` unterscheidet
mit seinem Rückgabewert nicht zwischen „kein Journal", „Unit unbekannt" und
„keine Einträge" — alle drei geben `rc=0`, `-- No entries --` auf **stdout** und
die Auskunft auf stderr.

> **Ein Leser, der `-- No entries --` als Zeile nimmt, zeigt eine Meldung des
> Werkzeugs als Inhalt des Protokolls.**

**Und die Bildrunde hat einen Fehler im Messmittel gefunden, nicht in der
Seite.** Das Theme wurde über `emulateMedia({ colorScheme })` umgestellt —
`app.css` kennt aber keine `prefers-color-scheme`-Regel, es hängt allein an
`data-theme` am `<html>`. Beide Läufe waren hell.

> **Eine Umstellung, die der Prüfling nicht liest, hat nichts umgestellt — und
> das Bild daneben sieht aus wie ein Ergebnis.**

Dazu: Ein leeres `schiebt` bedeutet erst etwas, wenn `rollt` daneben steht. Der
erste Lauf gab nur das erste aus.

**Was davon offen war, ist es nicht mehr** — und das stand hier bis zum
27. August 2026 falsch. Teil 3 von M5 hing an Schritt 6 und ist mit ihm gebaut;
die Ausnahme in `AptResultTest` ist fort. Die Messrunde auf Debian 12, Debian 13
und Ubuntu 22.04 fährt der CI-Job `apt-messrunde`, samt der vier Fälle, die im
Container nicht vorkamen — `docs/85 §5` führt sie ausdrücklich als „wer sie hier
noch einmal misst, misst die CI". Und die Abnahme von Schritt 1 ist über Punkt 3
des Abnahmelaufs belegt: `rc=0` bei toter Quelle, auf einem echten Server.

> **Eine Zeile, die einen Zustand behauptet, veraltet ohne Vorwarnung — und
> nichts prüft sie.** `DocLinkTest` hält, dass eine genannte Datei existiert,
> nicht, dass stimmt, was über sie gesagt wird. Wer eine Stufe abschliesst,
> liest die Sätze nach, die sie offen nannten.

---

## A9 ist gebaut — Rollen, Konten, Netze, Sitzungen

Die Schritte 3, 5 und 7 aus `docs/82` sind gebaut und zusammen mit A1 und A5 als
**`v0.7.1-rc.1`** freigegeben. Der Abnahmelauf steht als **`docs/83`** und ist
**am 25. August 2026 gefahren**; das Protokoll ist `docs/84`, und die Stufe ist
seitdem abgenommen — der Abschnitt darunter beschreibt den Stand beim Bauen.

**Schritt 3, die Kontenverwaltung.** Ein zweites Adminkonto entsteht ohne SSH;
bis dahin war `srvpanel admin` der einzige Weg, und der braucht root — also
genau die Rechte, die das Rechtemodell dem Administrator nicht geben will. Der
Aussperrschutz sitzt an **einer** Stelle und wird mit dem **Zielzustand**
gefragt, nicht mit dem gegenwärtigen: Herabstufen und Sperren sehen im Formular
verschieden aus und sind dieselbe Handlung.

> **Zwei Wege, die denselben Zustand herstellen, brauchen dieselbe Frage — und
> die Frage lautet, wie es nachher aussieht.**

**Schritt 5, die Fläche aus der Policy.** Seit Schritt 2 über die Rolle
auflöst, sah ein Administrator sieben Menüpunkte, die ihm alle einen 403 gaben.
Die geteilte Ablage heisst **`abilities` und nicht `can`** — `can` ist vergeben,
neun Seiten schicken eine eigene über ihr Objekt, und Seitenwerte überschreiben
geteilte.

> **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf genau dieser
> Seite fort — und der Ausfall liest sich wie ein Rechteproblem.**

Die Messung davor hat das Kriterium **schon erfüllt vorgefunden**: 43 Seiten
ausgezählt, acht nur für den Betreiber, in den übrigen 35 kein
Betreibergeheimnis. Der Payload-Teil ist deshalb kein Umbau, sondern ein
Stolperdraht.

> **Ein Zustand, der stimmt und den nichts hält, ist von einem, der nicht
> stimmt, nur durch Glück getrennt.**

Dabei fiel eine Zusage heraus, die niemand aufgeschrieben hatte:
`/operations/{id}` zeigt `output` **jedem** Admin, und das sind die
Protokollzeilen des Agenten — dieselbe Art Inhalt, deretwegen `/logs` dem
Betreiber allein gehört. Dass dort nichts Geheimes steht, liegt nicht an einem
Filter, sondern daran, dass die zwölf geheimnisführenden Operationen **null**
Zeilen senden. `AdminPayloadTest` hält das seitdem.

> **Eine Sicherheit, die aus einer Eigenschaft der Daten folgt und nicht aus
> einer Prüfung, hält genau so lange, bis jemand die Daten ändert.**

**Schritt 7, Netze und Sitzungen.** Gefragt wird an **zwei** Stellen — bei der
Anmeldung, damit das Protokoll die Wahrheit sagt, und bei jeder Anfrage, weil
eine offene Sitzung die Beschränkung sonst überlebt. `docs/82 §2.5` nannte nur
die Anmeldung; so gebaut wäre es die Hälfte gewesen.

> **Eine Beschränkung, die nur an der Tür gefragt wird, gilt für niemanden, der
> schon drin ist.**

Die CIDR-Rechnung gab es schon, nur am falschen Ort und **von keinem einzigen
Test abgedeckt** — `Pg\Hba::cidr()` seit P5b, über die Namensraumgrenze
gerufen. `Net\Cidr` trägt sie jetzt samt `contains()`, `CidrTest` prüft sie in
27 Fällen. Gesucht wird eine Sitzung über **Konto und Kennung**, nicht über die
Kennung allein: Sonst beendete eine abgeschriebene Kennung die Sitzung eines
fremden Kontos, und sie steht im Cookie des Betroffenen.

**Und `srvpanel access` ist ein Merkmal, das kein Plan bestellt hat.** Beim
Ausschreiben von `docs/83` fiel auf, dass `srvpanel admin` ein Konto
zurückholt, das sich mit Passwort oder zweitem Faktor ausgesperrt hat — für die
Netzbeschränkung gab es nichts dergleichen. Ändert sich die Adresse des
Betreibers, kommt niemand mehr herein.

> **Ein Merkmal, das aussperren kann, braucht seinen Rückweg — und dass er
> fehlt, merkt man beim Ausschreiben des Abnahmelaufs und nicht beim Bauen.**

`--clear` fragt den Aussperrschutz bewusst **nicht**: Es ist der Griff für den
Fall, dass die Beschränkung selbst der Schaden ist.

---

## Der Abnahmelauf von A9 — 25. August 2026

Gefahren gegen `v0.7.1-rc.2`, der Lauf ist `docs/83`, das Protokoll **`docs/84`**.
**Sechzehn Befunde, sechs Beobachtungen, keinen davon ein Test** — und wie in
`docs/45`, `docs/48` und `docs/59` steckt die Mehrheit nicht im Prüfling:
**sieben der sechzehn** betreffen die Vorschrift, das Prüfmittel oder das
Kriterium.

**Drei Vorschriften dieses Laufs haben am Gegenstand vorbeigemessen**, alle drei
nach demselben Muster. Der teuerste davon hätte den grössten Befund des Laufs
verschwiegen: Punkt 7 lautete „fliegt beim nächsten Klick heraus — **oder
spätestens** die nächste Anmeldung scheitert".

> **Ein Kriterium, das zwei Ausgänge zulässt, misst keinen von beiden.**

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.** Erwartung 4 von Punkt 8 („die Rolle bleibt Betreiber") lief gegen ein
> Konto, das schon Betreiber war.

> **Ein Prüfkörper, der eine andere Quelle tötet als die, an der die Prüfung
> hängt, erreicht die Prüfung nicht.** Punkt 11 tötete eine beliebige
> apt-Quelle; abbrechen kann `php.version.install` nur an seiner eigenen.

**Fünf Befunde hat der Betreiber gemeldet und nicht eine Messung** — darunter
der mit der grössten Wirkung: **Sperren beendet keine offene Sitzung.** Keine der
sieben Mittelschichten fragt den Kontozustand; ein gesperrtes Adminkonto behält
seine Rechte bis zu **30 Tage**, solange jemand die Sitzung benutzt.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

**Und der zweitgefährlichste ist eine Zeile, die zum Wiederanlegen verleitet.**
`form.reset()` stellt auf der Zugangsseite den Stand vom **Laden** wieder her;
eine gelöschte Zeile kommt zurück, obwohl der Server sie entfernt hat. Wer sie
wiedersieht, drückt noch einmal Speichern — und legt die Beschränkung wieder an,
die er gerade aufgehoben hat. Beide Vorgänge melden Erfolg.

> **Eine Anzeige, die den Zustand vor der Änderung zeigt, verleitet zu der
> Handlung, die die Änderung zurücknimmt.**

**Der Fund, der ein Werkzeug gekostet hat: ein `<style scoped>` im
Vorlagenblock.** Er war zwischen einen benannten Bereich und den ersten Inhalt
gerutscht; Vue wirft ein `<style>` an dieser Stelle weg, und **beide** Regeln der
Komponente waren auf der Seite fort — weder im gebauten Stylesheet noch in der
Renderfunktion.

> **Ein Block, der an der falschen Stelle steht, ist kein falsch stehender Block
> — er ist keiner.**

`ClassReachTest` war grün, weil er `<style>` in der **ganzen Datei** suchte.

> **Ein Wächter, der eine Zeichenkette sucht statt eines Blocks, ist grün,
> sobald die Zeichenkette irgendwo steht.**

Er schneidet den Vorlagenblock seitdem heraus; **`SfcBlockTest`** hält die Regel
selbst. Belegt ist beides mit einem Eingriff, der die **alte** Fassung gegen
dieselbe kaputte Quelle grün zeigt — ein Wächter, dessen Verschärfung man nicht
gegen den Fehler misst, den sie fangen soll, ist eine Behauptung.

**Vier weitere Sätze aus diesem Lauf.** Der erste, weil das Kriterium den
Kontozustand über acht Seiten prüfte, die es als `.vue` gar nicht gibt:

> **Ein Wächter, der die geschriebenen Seiten prüft, sagt nichts über die, die
> niemand geschrieben hat.** `resources/views/errors/` gibt es nicht; jeder 403
> ist Laravels englische Vorgabeseite, und A9 macht sie zum entworfenen Zustand
> für acht Seiten.

Der zweite, weil die Kürzung der Gerätekennung bei 120 Zeichen sitzt und ein
Desktop-Chrome bei 116 liegt:

> **Eine Obergrenze, die über dem tatsächlichen Höchstwert liegt, ist keine.**

Der dritte, weil `.button.small` unter `max-width: 720px` seine Höhe
zurückbekommt:

> **Ein Fehler, den nur die breite Ansicht hat, entgeht einer Prüfung, die auf
> die schmale zielt.**

Der vierte, weil ich eine Anweisung geschrieben habe, die zuerst „erst
nachsehen" sagte und dann den geratenen Hostnamen einsetzte:

> **Eine Anweisung, die zuerst „nachsehen" sagt und danach den geratenen Wert
> einsetzt, hat das Nachsehen zur Verzierung gemacht.**

**Und einer über das eigene Messmittel.** Die flache Konsolenzeile, mit der die
Bilderrunde gefahren wurde, liess `stand` weg — genau das Feld, das
`tests/bilder-messen.js` am 19. August bekommen hat, weil es bei jedem Neuladen
aus der Zwischenablage zurückkommt.

> **Wer ein Messmittel kürzt, kürzt zuerst das Feld weg, das vor der alten
> Messung schützt.**

## Was diese Runde über die Werkzeuge gelehrt hat

**Die CI ist auf diesem Zweig dreizehn Commits lang kein einziges Mal
gefahren.** `ci.yml` hängt auf `push` nur an `main` und sonst an
`pull_request` — ohne PR läuft nichts, und ausgelöst hat es niemand. Der erste
Lauf von Hand brachte `SizeUnitTest` rot, und zwar seit A5: Die Logs-Seite trug
eine **dritte** Fassung von `formatBytes`, schlechter als die kanonische, und
eine Datei von 1,2 GB las sich als „1234.6 MB". Zwischen dem Bau von A5 und dem
ersten Lauf liegen fünf weitere Commits.

> **Ein Wächter, den man nicht fährt, ist von einem, den es nicht gibt, nicht zu
> unterscheiden.**

**Und dasselbe eine Ebene höher: `waechter.yml` hängt allein an
`pull_request`.** Neun Läufe von Hand auf `ci.yml` (789 bis 797) haben das
Bruchskript nie angefasst; erst der PR hat es gefahren — 716 Eingriffe, alle
beissen, davon 52 neu von dieser Runde. Die vier zuletzt gebauten sind genau die, die das Gestell
hier mit „braucht Laravel — hier nicht messbar" meldet.

> **Ein Werkzeug, das an einem Ereignis hängt, das man nicht auslöst, ist
> abgeschaltet und sieht aus wie eingerichtet.**

Wer also auf einem Zweig arbeitet und wissen will, ob er trägt, löst die CI von
Hand aus (`workflow_dispatch`) **oder** öffnet den PR — und weiss dabei, dass
das erste das Bruchskript nicht mitnimmt.

**Pint läuft nach dem Wächter, und was ins Repo geht, ist die Fassung danach.**
Aus `{@see \App\Support\Authorization\AdminNetwork}` im Dokumentblock machte er
einen `use`-Eintrag — damit war ein framework-freier Wächter framework-abhängig
und lief genau dort nicht mehr, wofür es ihn gibt.

> **Ein Wächter, den man vor dem Formatierer prüft, ist nicht der, der ins Repo
> geht.**

**Und Pint hat eine Methode umbenannt, ohne ihre Aufrufstelle.** In einer
Testklasse setzt er `test_…` durch; aus `testFiles()` wurde `test_files()`, der
Aufruf blieb stehen. Heraus kam eine Datei, die `php -l` besteht und beim
Ausführen an einer undefinierten Methode stirbt — die Verwandte der Falle mit
`count()`, `matches()` und `run()`, nur mit dem Formatierer statt der
Basisklasse.

**Zweimal dieselbe Annahme über einen abschliessenden Wert.** `assertGuest()`
nimmt als ersten Wert den **Guard**, `assertDatabaseHas()` als dritten die
**Verbindung**; beide Male stand dort ein deutscher Satz, und Laravel suchte
einen Guard beziehungsweise eine Verbindung dieses Namens.
`AssertionArgumentTest` hält das an neun Helfern, und das Merkmal ist das
**Leerzeichen** — ein Guard heisst `web`, eine Verbindung `sqlite`, eine Meldung
ist ein Satz.

> **Ein Wert am Ende einer Argumentliste sieht aus wie eine Meldung, auch wenn
> etwas an ihm hängt.**

**Ein verwaister Dokumentationsblock.** Eine neue Methode war zwischen
`impersonation()` und ihren Block gerutscht; PHPStan meldete die Hälfte, die ein
Werkzeug sehen kann. `DocblockAnchorTest` hält das jetzt — und sein **erster
Wurf fragte die falsche Frage**: „zwei Blöcke hintereinander" meldete zwanzig
Stellen, achtzehn davon zu Recht so geschrieben. Mit der Marke als Bedingung
bleiben zwei, und beide waren echt.

> **Ein Wächter, der zu viel meldet, wird abgeschaltet — und zwar von dem, der
> ihn gebaut hat.**

**Und ein Handgriff, der beim Gegenprüfen Arbeit vernichtet hat:**
`git checkout --` mit einer gemischten Liste aus verfolgten und unverfolgten
Pfaden stellt **nichts** wieder her und sagt es nicht. Drei Dateien blieben
mitten im Bruch liegen. Gesichert wird seitdem mit `cp`, und nach jedem Eingriff
steht eine belegte Zeile „zurück".

> **Ein Rückweg, der stillschweigend nichts tut, ist schlimmer als keiner.**

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
nichts Rundes in Nutzerkoordinaten gezeichnet) — und aus P7b
`AptResultTest` (wer `apt-get update` ruft, liest die Quellen und nicht den
Rückgabewert; er sucht ausdrücklich **nicht** das Wort `successful()`, sondern
misst die Wirkung an einem selbstgebauten Ergebnis mit Rückgabe 0),
`LastOperatorTest` (der letzte Betreiber lässt sich weder herabstufen noch
sperren — gemessen an beiden Wegen, mit dem Nachweis, dass es keinen dritten
gibt), `AccountMutationTest` (jede ändernde Kontenroute fragt den
Aussperrschutz — oder steht mit Begründung als harmlos da, in beide Richtungen
und ohne Framework), `AdminPayloadTest` (keine Seite überschreibt die geteilte
Fähigkeitsablage, jeder Menüpunkt trägt die Fähigkeit seiner Route, und keine
geheimnisführende Operation sendet Protokollzeilen), `AssertionArgumentTest`
(ein Satz steht nicht dort, wo ein Guard oder eine Verbindung erwartet wird —
das Merkmal ist das Leerzeichen), `DocblockAnchorTest` (ein Dokumentationsblock
mit Marke steht an seiner Methode) , `BaseMethodClashTest` (kein Testfall
deklariert einen Namen, der der Basisklasse gehört — er spiegelt deren
`final`-Methoden, statt eine Liste zu pflegen) und `SfcBlockTest` (ein
`<style>`-Block einer `.vue` steht auf oberster Ebene und nicht im
Vorlagenblock, wo der Übersetzer ihn wegwirft — die Regel, die
`ClassReachTest` nicht halten konnte, weil er eine Zeichenkette suchte statt
eines Blocks) und `OperatorControlTest` (ein Bedienelement, dessen Route strenger ist als die
Seite selbst, steht in einem `v-if` auf ihre Fähigkeit — die Zuordnung
Fähigkeit → Wächtervariable kommt aus der Seite und nicht aus einer Liste im
Test, und die Vorlage wird mit einem Stapel gelesen statt rückwärts; **ein
wörtlicher `href` zählt seit dem 31. August mit**, denn ein Verweis führt
genauso zu einem 403 wie ein Knopf, und was er nicht halten kann, steht in
seinem Kopf als Frage),
`InspectOnlyTest` (dieselbe Grenze an der Tür: vier Griffe geben dem
Administrator 403 und dem Betreiber nicht — gemessen wird „nicht 403" und nicht
„200", sonst prüfte er den Agenten statt der Tür) und `SourceKeyFilterTest`
(der Schlüsselfilter rechnet richtig, **wird gerufen**, und der Agent bekommt
kein Feld dazu, ohne dass jemand entscheidet, ob der Administrator es sehen
darf) und `ShellCheckReachTest` (jedes Shellskript unter `packaging/` kommt bei
shellcheck vorbei, **und** jeder Pfad, den der Schritt nennt, deckt auch etwas —
die zweite Richtung ist die, an der ein toter Eintrag wirklich entsteht) und
`RebootConfirmTest` (der Neustart wird über `systemd-run`
**abgesetzt** und nicht im Agenten ausgeführt; der Rechnername wird auf dem
Server geprüft, und zwar gegen dieselbe Quelle, aus der die Seite ihn zeigt —
gefragt wird der Programmname **an der Aufrufstelle** und nicht die
Zeichenkette „systemctl" irgendwo in der Datei) und `PackageNameTest` (ein
Paketname kommt aus der Antwort, die der Agent selbst gerade gelesen hat, und
nicht aus einem Muster — gemessen an den Namen, die apt **als Option**
schluckt; dazu: der benannte Lauf benutzt kein `--only-upgrade`, weil das ein
noch nicht installiertes Paket wortlos überginge) und `UnattendedStateTest`
(der Zustand der Automatik kommt aus `apt-config dump` und nicht aus der
eigenen Datei; eine fehlende Zeile heisst **an** und nicht aus; das Ausschalten
nimmt das Auffrischen der Listen nicht mit) und `UnitStateTest` (der Leser für
`systemctl show`, mit gemessenen Prüfkörpern statt erfundenen — dazu die
Zuordnung Dienst ↔ Timer: sie kommt aus `Triggers` **am Timer**, überlebt einen
gestoppten Timer, und `markScheduled` **wird gerufen** und rechnet nicht bloss
richtig) und `UnitCatalogTest` (jede paketierte Unit steht im Katalog und jede
im Katalog ist paketiert — beide Richtungen, weil ein toter Eintrag bei einer
Umbenennung entsteht) und `ServicesViewTest` (die Farbe einer Zeile folgt dem
nächsten Termin und nicht `ActiveState`, und ein Dienst, den ein Timer startet,
darf stillstehen — gefragt wird je **Funktionsrumpf**, weil eine Zeichenkette
irgendwo auf der Seite nichts über die Funktion sagt, in der sie wirken soll)
und `NavGroupTest` (jede Gruppe der Navigation trennt an der Grenze, an der auch
die Route trennt) und `UpdateWaitTest` (`srvpanel update` liest das
Urteil seines Laufs nach und gibt den passenden Rückgabewert zurück; geladen
wird **vor** dem Absetzen, weil das Fassungsverzeichnis danach fort ist, und
eine abgelaufene Frist ist kein Erfolg — gemessen wird die **Reihenfolge** und
im Rumpf nach der Schleife, nicht das Wort irgendwo in der Datei; was er nicht
halten kann, steht als M1 in `docs/94` und nicht als Zusage im Test)
und `OperationOriginTest` (von einer
Vorgangsseite führt ein Weg zurück: die Herkunft wird am **Modell** genommen und
von **keiner** der sechzehn anlegenden Stellen selbst — beide Richtungen, denn
die erste allein hat den Befund aus `docs/94 §6b` nicht gesehen; sie kommt aus
der Sitzung und nicht aus einem Helfer mit Rückfall, und jeder Pfad, den
`OperationSubject` nennt, ist eine angemeldete **GET**-Route — gefragt wird
`routes/web.php` als Text, weil der Wächter ohne Framework laufen muss, und die
anlegenden Stellen werden über **alle drei** Schreibweisen gesucht, im
Argumentblock und nicht in der ganzen Datei) und `OriginHeaderTest` (die Herkunft
kommt an — gemessen an der **Wirkung** und nicht am Quelltext: Kopfzeile rein,
Spalte raus, samt der drei Gegenrichtungen; ein Wächter über den Quelltext sagt,
dass die Teile zusammenpassen, nicht dass sie zusammen etwas tun) und
`MobileTableTest` (jede Tabelle nennt ihre Form —
`stacks`, `pairs` oder `rows` —, jede Form ist in `app.css` gestaltet, und jede
gestapelte Zelle trägt ihre Beschriftung; die Zelle mit dem Knopf am Zeilenende
darf ohne, und das wird an ihrem **Inhalt** entschieden und nicht an einer
Ausnahmeliste; **seit dem 31. August** trägt in einer `pairs`-Tabelle die
**Zelle** ihre Kennung und nicht das, was in ihr steht — die Ausnahme
`table.pairs td.right.ident` erreicht ein Kind nicht, und ein Objektschlüssel
`:class="{ ident: … }"` zählt mit, weil eine Zelle einmal einen Satz und einmal
eine Kennung zeigen darf). Der Bruch selbst steht als
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

**Und dieselbe Blindheit noch einmal am 1. September 2026, aus dem Grund, der
dieses Repo besonders trifft: dem eigenen Kommentar.**
`OutcomeTest` verlangte `[ "$mass" = offen ]` in `apt-run` — und blieb grün,
nachdem die Bedingung entfernt war, weil die Zeile, die *erklärt*, dass es sie
nicht mehr gibt, sie wörtlich hinschreibt.

> **Ein Wächter, der eine Zeichenkette sucht, ist grün, sobald sie irgendwo
> steht — und ein Kommentar, der die entfernte Zeile zitiert, stellt sie für ihn
> wieder her.**

Das wiegt hier schwerer als anderswo, weil **jede** Behebung in diesem Repo
ihren Vorzustand im Kommentar festhält. `Tests\Support\WithoutHashComments` gibt
es seit dem 26. August genau dafür; sechs Wächter benutzten ihn, `OutcomeTest`
nicht. **Wer einen Wächter über ein Shellskript oder YAML baut, streift die
Kommentarzeilen ab, bevor er sucht** — genauso wie `WithoutPhpComments` es für
PHP tut. Belegt in beide Richtungen: mit der verbotenen Zeile *nur im Kommentar*
bleibt der Wächter grün, roh gelesen wäre er fälschlich rot.

**Und derselbe Fehler eine Ebene tiefer, am 26. August 2026:** `PartialReloadTest`
las PHP **byteweise** und hielt jedes `"` für den Anfang einer Zeichenkette.
Deutsche Anführungszeichen stehen in diesem Repo als `„…"` — die öffnende ist
U+201E, die schliessende ein gewöhnliches `"` (1214 gegen ein einziges U+201C,
ausgezählt). Ein Kommentar mit einem Zitat mehr verschob alles danach; die
schliessende eckige Klammer eines `Inertia::render` wurde nie gefunden, die
Seite fiel aus der Liste, und der Wächter meldete seine **Untergrenze** — also
rot für einen Grund, der mit seiner Regel nichts zu tun hat.

> **Ein Wächter, der Anführungszeichen zählt, zählt die des Fliesstextes mit —
> und ob er zubeisst, entscheidet die Parität.**

**Und dieselbe Blindheit eine Stunde später, aus einem anderen Grund:** Der
Aufruf von `apt-get update` zog am 26. August aus PHP in ein Shell-Skript unter
`packaging/bin`. `AptResultTest` und `AptLockReachTest` lesen beide **nur
PHP** — sie hätten weiter Grün gemeldet für eine Stelle, die sie gar nicht mehr
sehen. Gefangen hat es keine der beiden Regeln, sondern ihre Untergrenzen: Die
namentlich genannte Stelle „ruft kein `apt-get update` mehr".

> **Ein Aufruf, der in ein Skript umzieht, ist für einen Ausdruck über
> PHP-Quelltext verschwunden — nicht harmlos geworden.**

**Und derselbe ASCII-Anführungsstrich noch einmal, diesmal in einer Shell.**
`tests/waechter-brechen.sh` liess sich eine halbe Stunde lang nicht von `bash`
parsen, weil eine Überschrift `„aus"` schrieb statt `„aus\"`. **Acht Prüfungen
über das Skript blieben dabei grün** — sie lesen seinen Text, statt ihn zu
fahren.

> **Ein Bruchskript, das sich nicht einliest, prüft keine einzige Regel — und
> jede Prüfung darüber bleibt grün.**

`BreakScriptTest::test_the_script_itself_parses` fährt seitdem `bash -n`. Sein
Gegenbeweis steht **im Test** und nicht im Skript: Ein Eingriff müsste das
Bruchskript selbst verändern, und das nimmt der Rückweg zu Recht aus.

Dabei fiel ein Loch auf, das älter war: `apt-get -q update` traf der Ausdruck
nicht, weil er `apt-get` unmittelbar vor `update` verlangte.

> **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit und
> nicht die Regel.**

Die Antwort steht seit langem im Repo: `Tests\Support\WithoutPhpComments` fragt
`token_get_all()`, also den Parser. Zehn Wächter benutzten ihn, dieser nicht.
**Wer einen Wächter über PHP-Quelltext baut, streift die Kommentare ab, bevor er
zählt** — gleich, ob er Klammern, Anführungszeichen oder `//` sucht.

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

Und **`79` die Übergabe an die Adminfunktionen vor P8** — der Stand des
Projekts in Zahlen, die drei Grenzen, was dieser Container kann und was nicht
(mit den fünf Fallen beim Bau des Wegwerf-Gestells), was für sichtbare
Adminfunktionen im Besonderen gilt, und was der neuen Sitzung mitzugeben ist.
Sie ersetzt den Plan der Adminfunktionen nicht — der ist anderswo entstanden.

Und aus P7b: **`85` der Abnahmelauf für A1** — fünfzehn Punkte auf einem echten
Server, **gefahren am 27. August 2026**. Punkt 5 ist der Grund, dass es ihn gibt:
Dass eine transiente Unit den Neustart von `srvpanel-worker` überlebt, wenn
`srvpanel` selbst im Lauf steckt, **behauptet dieses Projekt seit P0** und belegt
hatte es nur der eigene Gebrauch. §5 sagt, was der Lauf ausdrücklich nicht prüft,
§6, welche zwei Punkte als „nicht messbar" ausfallen dürfen und welcher nicht, §7
was danach zu bauen bleibt — und **`86` das Protokoll dazu**: die fünfzehn Punkte
mit ihren gemessenen Werten, vierzehn Befunde und siebzehn Beobachtungen, in
**§5** die Bilanz mit den sechs Resten und in **§6 die Abnahme vom 28. August
2026** samt der Begründung, warum die Familie sie nicht aufhält. Diese Familie
stand als drei Einzelfälle da: Ein Vorgang, der einen Lauf nur **absetzt**,
meldet `fertig` und sagt über dessen Ausgang nichts. · **`80` die Bestandsaufnahme** · **`81` der Plan** (A1 vollständig,
die übrigen als Skizze) · **`82` Rollen und Konten** — der Plan von A9, mit den
zwei Achsen, dem Aussperrschutz und der Netzbeschränkung; §2.4 ist einmal
berichtigt worden, weil er eine Passworterzeugung auf dem Server vorsah, die
dieses Panel seit P1 im Browser macht — und **`83` der Abnahmelauf für A9**:
vierzehn Punkte auf einem echten Server, **gefahren am 25. August 2026**. Zwei davon
sind der Grund, dass es ihn gibt: Punkt 8 geht den Rückweg `srvpanel admin`, den
bisher niemand gegangen ist, und Punkt 9c misst hinter dem echten nginx, ob eine
offene Sitzung endet, wenn ihre Adresse nicht mehr zugelassen ist. §5 sagt, was
er ausdrücklich nicht prüft, §7 was danach zu bauen bleibt — und **`84` das
Protokoll dazu**: die fünfzehn Punkte mit ihren gemessenen Werten, die sechzehn
Befunde mit ihren Lehren, die sechs Beobachtungen, was der Lauf über sich selbst
gelernt hat (§5), was offen bleibt (§6) und die Baureihenfolge nach
Dringlichkeit (§7). Die Nummer des
Protokolls steht bewusst **nicht** im Dokument — `docs/81` hat einmal eine
genannt, die einem anderen Dokument gehörte, und `DocLinkTest` konnte das nicht
sehen.

Und weiter aus P7b: **`87` der Nachlauf zu `0.7.2-rc.5`** — sechs Punkte auf
`cloudsrv24`, die nachsehen, ob die vier Behebungen aus `docs/86 §5` auf einem
echten Server wirken — mit **`88`** als Protokoll dazu: **elf Befunde, sieben im
Prüfmittel und vier im Panel**, alle vier behoben; fünf der sechs Punkte
erfüllt, Punkt 4 wartet auf Paketbestand. Und **`89` die Messrunde vor A2** —
Dienste und Timer, gemessen gegen echtes systemd 255 in einer eigenen Namespace:
§1 wie dieser Container einen Systemmanager bekommt, §2 dass ein Timer nur sechs
der neun Felder von `ServiceStatus` beantwortet, §3 die Messung, die den Entwurf
entscheidet, §6 der eigene Fehler dieser Runde und §7 was auf dem Zielserver
offen bleibt. Und **`90` der Nachlauf zu `0.7.3-rc.1`** — die Abnahme
von A2 auf `cloudsrv24`: elf Punkte, von denen Punkt 4 das Kriterium trägt
(ein Timer ohne Termin ist als kaputt erkennbar) und Punkt 2 ein
Ausschlusskriterium ist (der Mehrfachleser ist gegen **drei** Units gemessen
und fragt auf dem Server **neunzehn**; passt die Blockzahl nicht, gibt die
ganze Seite einen 500er). §13 sagt, was er ausdrücklich nicht prüft, §14, wann
er durch ist und welche zwei Punkte als „nicht herstellbar" ausfallen dürfen —
und **`91` das Protokoll dazu**: §1 der gemessene Ausgangszustand (systemd 255
auf dem Server, sechzehn Zeilen aus neunzehn Kandidaten), §2 die Messrunde, die
Befund 1 nötig gemacht hat, §3 der Befund selbst mit seinen sechs Wächtern und
acht Brüchen, §18 die Bilanz, §19 was ausstand und **§20 die Behebungen vom
31. August**. **A2 ist am 31. August 2026 abgenommen** — auf `cloudsrv24` gegen
`0.7.3-rc.3`, Punkt 4 erfüllt und beide Ausschlusskriterien (2 und 7) grün.
Punkt 6 fiel als „nicht herstellbar" aus, Punkt 11 ist **nicht** erfüllt
(Befund 6).

**Sechs Befunde, fünf davon im Prüfling** — die Umkehrung von `docs/45`,
`docs/48`, `docs/59` und `docs/84`. Der Grund ist kein besseres Auge: Die
Vorschrift war vor dem Lauf ausgeschrieben und das Messmittel lag als geprüftes
Werkzeug im Repo. Was blieb, war eine **neue Seite** — und die hatte ihre Fehler
dort, wo kein Wächter hinsah.

**Die beiden offenen Befunde sind am 31. August gebaut** (`docs/91 §20`), und
**keiner von beiden hat einen Server gesehen** — was zu messen bleibt, steht dort
in §20.5.

**Befund 5** war nicht eine falsche Übersetzung, sondern eine fehlende Stelle:
Die Übersicht druckte `active_state` roh — also „active", wo die Dienste-Seite
„läuft" sagt — und hatte daneben ein eigenes `dienstRang()`, dem die Nachsicht
für `Type=oneshot` fehlte.

> **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
> sondern eine widersprüchliche.**

`WordChoiceTest` konnte es nicht sehen, weil das englische Wort nirgends im
Quelltext steht; es entsteht zur Laufzeit. `useUnitState.ts` trägt jetzt `rang()`
und `zustand()` für beide Seiten, `ServicesViewTest` hält, dass keine der 70
`.vue` einen Rohwert von systemd ausgibt.

**Der Umzug hat einen Wächter stumpf gemacht, ohne ihn rot zu machen** — die
gefährlichere Hälfte der bekannten Aufräumfalle. Die Reihenfolge „Termin vor
Zustand" wurde über die ganze Datei gemessen, und seit dem Umzug steht der
Termin im Helfer `ohneTermin()`, der **oben** definiert ist:

> **Ein Wächter über eine Reihenfolge wird stumpf, sobald einer der beiden
> Ausdrücke in einen Helfer zieht, der weiter oben steht.**

Gemeldet hat es der Bruchlauf und nicht der Wächter: Sein Eingriff fand seinen
Text nicht mehr. Gemessen wird jetzt im Rumpf von `rang()`.

**Befund 6** ist die Spiegelung von M5, dem Befund, mit dem P7b anfing:

> **Ein Rückgabewert, der „nichts zu tun" und „nicht geschafft" gleich benennt,
> ist derselbe Fehler wie einer, der einen Fehlschlag nicht tragen kann — nur in
> die andere Richtung.**

`apt-run` gab `exit 3` für einen Lauf, dem nichts zu tun blieb, und
`Outcome::BAD` liest den Satz nur an seinem Anfang — die Zahl, die den Fall
unterscheidet, stand in der Meldung und wurde weggeworfen. Behoben ist es **im
Skript und nicht im Leser**, und der Leser brauchte dafür keine Zeile:

> **Eine Voreinstellung, die zur sicheren Seite fällt, trägt den Fall, den
> niemand vorhergesehen hat — und den, den jemand vorhergesehen und nicht gebaut
> hat, ebenso.**

**Und der Prüfkörper von `OutcomeTest` hielt den Fehler fest, statt ihn zu
melden** — er stand dort wörtlich als `…vorher wie nachher: 0.`, und die
Behauptung daneben erklärte ihn für einen Fehlschlag.

> **Ein Prüfkörper, der den Fehler enthält, hält ihn fest statt ihn zu melden —
> wenn die Behauptung daneben ihn für richtig erklärt.**

**Und drei Kriterien konnte der Prüfling nicht erfüllen**, alle drei aus
derselben Ursache: Sie standen in der Unit-Datei, die ich nicht gelesen hatte
(`Type=oneshot` bei vieren, `RandomizedDelaySec=1h` bei einem).

> **Wer eine Erwartung an eine Unit aufschreibt, liest vorher ihre Unit-Datei.**

---

## Von einer Vorgangsseite führte kein Weg zurück — 31. August 2026

**Gemeldet hat es der Betreiber beim Erklären**, nicht beim Benutzen: Die Frage
war, wie man denselben Knopf ein zweites Mal drückt, und die Antwort lautete
„mit dem Zurück-Knopf des Browsers". Einundzwanzig Weiterleitungen aus sieben
Controllern enden auf `operations.show`, und der Brotkrümel dort trug genau
**eine** Verknüpfung: die Liste *aller* Vorgänge.

> **Ein Weg, den man nur erklären kann, indem man den Browser zu Hilfe nimmt,
> ist keiner, den die Anwendung anbietet.**

**Zwei Wege, zwei Fragen.** `operations.origin` sagt „wo war ich",
`subject_type`/`subject_id` sagen „worum ging es". Ein Vorgang hat oft beides und
einer der Automatik keines von beiden. Die Herkunft wird an **einer** Stelle
genommen — einundzwanzig Aufrufstellen wären einundzwanzig Gelegenheiten, sie zu
vergessen, und die vergessene fiele niemandem auf, weil eine fehlende Herkunft
aussieht wie ein Vorgang der Automatik. Sie kommt aus der Sitzung und **nicht**
aus `url()->previous()`:

> **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine falsche
> Auskunft.**

**`subject_type` und `subject_id` gibt es seit dem 4. August 2026, und bis zum
31. hat sie keine Oberfläche gelesen** — derselbe Fall wie `context` im
Protokoll (`docs/66`). Das Feld war von aussen nicht von einem zu unterscheiden,
das es nicht gibt.

**Und die Bilderrunde hat zwei Fehler gefunden, die kein Test finden konnte.**
Der erste schob die Seite bei 390 px um **59 px** aus dem Bild: Die neue Zeile
schrieb `<td class="right">` mit einem `<a class="link ident">` darin.

> **Eine Ausnahme, die für die Zelle geschrieben ist, gilt nicht für das, was in
> ihr steht — und beide sehen im Markup gleich aus.**

Das ist die **vierte** Wiederholung desselben Fehlers an derselben Tabelle;
behoben ist er nicht durch eine fünfte Regel in `app.css`, sondern durch die
Klasse an der Zelle. `MobileTableTest` hält die Regel jetzt und hat beim ersten
Lauf sofort eine zweite Stelle gefunden, die es seit P6 gibt.

Der zweite hatte **keine Zahl**: Bei 0 px Überlauf nahm der Brotkrümel drei
Zeilen, weil er den ganzen Pfad mitsamt Filterwerten zeigte.

> **Eine Beschriftung, die den ganzen Zustand nennt, sagt nicht mehr, wo man war
> — sie sagt nur, dass es kompliziert war.**

**Und die erste Fassung dieser Messung war keine.** Der Aufsatz lag als
`public/brotkruemel.html` und lud die Stylesheets über `/build/assets/…`; unter
`file://` zeigt der führende Schrägstrich auf die Wurzel des Dateisystems. Die
Seite war ungestaltet, meldete 66 px, und die Gegenprobe daneben schlug aus.

> **Eine Messung, bei der der Prüfling gar nicht geladen wurde, sieht aus wie
> ein Ergebnis — die Gegenprobe belegt nur, dass die Messung rechnet, nicht dass
> sie ihren Gegenstand hat.**

Gemerkt hat es nicht das Bild und nicht die Zahl, sondern die Frage nach der
**berechneten** Eigenschaft: `overflow-wrap` stand auf `normal`, wo `app.css`
`anywhere` schreibt.

**Und der Nachlauf hat gezeigt, dass die Herkunft an einer von sechzehn Stellen
stand** (`docs/94 §6b`, gemessen am 31. August auf `cloudsrv24`): Vorgang 727
trug `← /updates`, Vorgang 729 nichts, beide von einer Seite ausgelöst.
`Dumps::dispatch()` und vierzehn weitere legen ihre Zeile selbst an.

> **Ein Wächter, der prüft, dass *eine* Stelle es tut, hat nicht geprüft, dass
> es *nur eine* Stelle gibt.**

Der Unterschied zum Gegenstand sagt, wo die Behebung hingehört:

> **Was jede Stelle anders weiss, gehört an die Stelle. Was überall dasselbe
> ist, gehört an eine — und die muss eine sein, an der niemand vorbeikommt.**

Das Modell ist sie: `Operation::booted()` setzt die Herkunft im
`creating`-Ereignis, wie `subscription_name` seit `docs/35`. Dessen Kommentar
nannte **sechs** anlegende Stellen — die Zahl war veraltet, und der erste Wurf
der Herkunft ist nach ihr entworfen worden.

> **Eine Zahl im Kommentar altert mit dem Code, den sie zählt, und nichts meldet
> es.**

**Was das nicht behebt — dass man überhaupt weggetragen wird — steht als
`docs/92` und ist in `docs/20 §9` für P9 vorgemerkt.**

---

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
```
srvpanel setup|update|version|metrics|usage|cron-runs|tls|dns|dns-check
         |db|vhost|admin|access|acceptance|acceptance-web|acceptance-db
```

**`srvpanel update` wartet seit dem 1. September 2026** und gibt den
Rückgabewert seines Laufs zurück; `--no-wait` setzt nur ab. Dass der CLI-Prozess
den Symlink-Wechsel überlebt, ist gemessen (`docs/94 §6`, M1) — der Satz „diese
Sitzung endet vorher" stand seit P0 als ungeprüfte Vermutung da. Dieselbe
Messung sagt auch, dass er danach **nichts mehr nachladen kann**: `agent/` liegt
im Fassungsverzeichnis, und das ist abgeräumt.

> **Ein Satz, den die Oberfläche behauptet und den niemand gemessen hat, ist
> eine Vermutung mit Fussnote.**

Die Wahrheit ist der `case`-Zweig in `packaging/bin/srvpanel`; `PackagingTest`
und `CommandReachTest` lesen ihn genau dort statt aus einer Liste im Test. Hier
stand die Aufzählung bis zum 25. August 2026 mit neun Einträgen, während der
Wrapper sechzehn kannte: `cron-runs`, `dns`, `dns-check`, `db`, `acceptance-db`,
`access` und `version` fehlten.

**Beide Richtungen sind gehalten, und die zweite erst seit dem 25. August.**
`PackagingTest::test_the_wrapper_knows_every_command_of_the_panel` prüft, dass
jedes `srvpanel:`-Kommando der Anwendung im `case`-Zweig steht — es gibt ihn,
seit `admin` dort einmal fehlte und die Ersteinrichtung einen Befehl nannte, den
es auf dem Server nicht gab. `test_every_command_the_wrapper_offers_exists`
prüft die Gegenrichtung: dass ein Eintrag auch ein Kommando nennt, das es gibt.
**So entsteht ein toter Eintrag wirklich** — bei einer Umbenennung trägt man den
neuen Namen nach, die erste Richtung ist danach wieder grün, und der alte bleibt
liegen. Auf dem Server wird daraus „Command not defined" für einen Namen, den
der Wrapper selbst anbietet.

> **Ein Wächter, der eine Richtung prüft, hat über die andere nichts gesagt —
> und welche der beiden fehlt, sieht man erst, wenn man sie braucht.**

**Und der Weg zu dieser Lücke ist die eigentliche Lehre.** Der erste Befund
lautete, *keine* der beiden Richtungen sei gehalten, und er war falsch: Die
Testnamen von `PackagingTest` waren mit einem `| head -20` gelesen und der
Schluss aus der abgeschnittenen Liste gezogen worden.
`test_the_wrapper_knows_every_command_of_the_panel` steht dort an **19.** Stelle.
Aus dem Irrtum wurde beinahe eine Zeile in dieser Datei, die einen vorhandenen
Wächter für nicht vorhanden erklärt — und das ist schlimmer als eine fehlende
Zeile, weil danach jemand einen zweiten baut.

> **Eine abgeschnittene Liste sieht aus wie eine vollständige — sie sagt nicht,
> wo sie aufhört.**

`srvpanel tinker` steht nicht in dieser Liste und funktioniert trotzdem: Es
heisst in artisan `tinker` und nicht `srvpanel:tinker`, und der Durchreicher ist
für diesen einen Fall genau richtig.

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

  **Mit einer Falle, die am 26. August fast eine Messung gekostet hätte: Es sind
  zwei Stylesheets, nicht eines.** Der Eintrag `resources/js/app.ts` im Manifest
  führt beide — in dem einen stehen die Seitenregeln aus `app.css`, in dem
  anderen die `scoped`-Regeln **aller** Komponenten. Wer „das" Stylesheet aus
  dem Manifest nimmt, bekommt eine Seite, auf der jede Komponentenregel fehlt,
  und weil die Seitenregeln da sind, sieht sie aus wie eine Seite.

  > **Ein Aufsatz, der ein Stylesheet von zweien nimmt, misst eine Seite ohne
  > die Regeln jeder Komponente — und das Ergebnis sieht aus wie eines.**

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
- **kein nginx, kein PHP-FPM.** Operationen laufen
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

  **Und `composer install` geht doch — mit drei Einstellungen.** Der Satz oben
  stimmt für den Aufruf, wie man ihn tippt, und er hat neun Monate lang die
  Arbeitsweise dieses Repositorys bestimmt: kein `vendor/`, also kein
  `artisan serve`, kein echter Testlauf, keine Aufnahme einer echten Seite — und
  jede Änderung an `app/`, `agent/` oder `tests/` kostet eine CI-Runde.

  Gemessen am 26. August 2026 ist die Sperre schmaler als der Satz. `git clone
  https://github.com/…` **funktioniert** hier, über dieselbe Leitung, über die
  dieses Repo gepusht wird. Composer benutzt sie nur nicht: Es fragt die
  GitHub-API nach einer Zipball-Adresse, und die sperrt der Proxy. Drei
  Einstellungen drehen das um:

      composer config -g use-github-api false
      composer config -g github-protocols https
      COMPOSER_ALLOW_SUPERUSER=1 composer install --prefer-source --no-dev

  Ergebnis: `vendor/autoload.php`, 403 MB, `php artisan --version` meldet
  Laravel 13.23.0. Damit laufen `artisan serve`, Migrationen, `srvpanel:admin`
  und Aufnahmen **echter Seiten** in diesem Container.

  **`--no-dev` ist kein Nebensatz, sondern der Kern.** Der einzige harte Blocker
  war `phpstan/phpstan`: Es kommt als Zipball über `api.github.com`, der Proxy
  antwortet **403**, und composer deutet 403 als „Anmeldung nötig" und bricht
  **den ganzen Lauf** ab — nachdem 22 Pakete bereits erfolgreich geklont waren.
  Ein einziges Entwicklungspaket verhinderte so alle Laufzeitabhängigkeiten.

  > **Ein Abbruch, der nach dem ersten Fehlschlag alles verwirft, macht aus
  > einem gesperrten Paket eine gesperrte Umgebung.**

  Was `--no-dev` kostet, ist wenig: kein `vendor/bin/phpunit` und kein
  `vendor/bin/pint` — beide gibt es als phar, siehe unten, und PHPStan ohnehin.

  **Und seit dem 26. August läuft der volle Testlauf hier — `php artisan test`
  und alles, was Laravel braucht.** Der einzige harte Blocker war und ist
  `phpstan/phpstan`; er kommt als Zipball über `api.github.com`, und composer
  bricht daran den ganzen Lauf ab. Nimmt man ihn und `larastan` aus
  `composer.json` **und** `composer.lock` heraus, läuft `composer install
  --prefer-source` durch und legt `vendor/bin/phpunit` ab (12.5.33 gemessen).
  Danach beide Dateien mit `git checkout --` zurückholen und mit `git status`
  nachsehen — was hier nicht ins Repo darf, ist die gekürzte Fassung.

  **Was das wert ist, zeigt der erste Lauf:** 2635 Tests, und er fand **vier
  Fehler in einer frisch gebauten Seite**, für die es sonst vier CI-Runden
  gebraucht hätte. `phar.phpunit.de` sperrt der Proxy weiterhin, und die
  GitHub-Releases von PHPUnit tragen kein `phpunit.phar` unter
  `latest/download` — beides gemessen.

  > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
  > braucht einen Versuch.** Derselbe Satz zum sechsten Mal, diesmal an dem
  > Werkzeug, dessen Fehlen die Arbeitsweise dieses Repositorys neun Monate
  > lang bestimmt hat.

- **systemd gibt es hier auch — es ist nur nicht PID 1.** Hier stand
  „kein systemd", und seit dem 27. August genauer „was fehlt, ist systemd als
  PID 1"; das erste ist falsch, das zweite die halbe Wahrheit. Installiert sind
  **systemd 255** samt `systemctl`, `systemd-run`, `busctl` und `dbus-daemon`,
  und unter `/lib/systemd/system` liegen 273 Units. In einer eigenen PID- und
  Mount-Namespace läuft der Systemmanager als PID 1 — `unshare -m -p -f
  --mount-proc bash -c 'mount -t cgroup2 none /sys/fs/cgroup; exec
  /usr/lib/systemd/systemd --system --unit=basic.target'` —, meldet
  `is-system-running: running` und fährt echte Timer mit echten Terminen.
  Gesprochen wird mit ihm über `nsenter -t <pid> -m -p -- systemctl …`.
  Gemessen am 30. August 2026 für A2 (`docs/89 §1`); der Benutzer-Manager
  (`systemd --user`) trägt **nicht** und endet stumm mit Rückgabe 1.

  Drei Handgriffe: Der Manager schreibt sein Log in **seine** Namespace —
  draussen sieht es aus, als sei er gestorben, nachgesehen wird mit `ps`. Die
  Namespace hat **ihr eigenes `/run`**, Unit-Dateien müssen von innen
  geschrieben werden, und genau deshalb verschwinden sie mit ihr. Und der erste
  Anlauf hat `/run/systemd/system` **draussen** angelegt — daran erkennt
  `sd_booted()`, ob systemd läuft, und der Container hielt sich danach für
  gebootet.

  > **Ein Prüfkörper, der zufällig eine Zusage des Systems ist, richtet mehr an
  > als eine Datei zuviel.**

- **Den Agenten gibt es hier auch — er muss nur gestartet werden.** Hier stand
  „kein Agent", und das war eine Aussage über den *laufenden* Dienst, nicht über
  das Programm. `agent/bin/srvpanel-agentd serve --config=<datei>
  --socket=/tmp/…sock --unprivileged` läuft, antwortet über den echten Socket
  und beantwortet echte Operationen; mit `SRVPANEL_AGENT_SOCKET` in der `.env`
  zeigt das Panel darauf. Gemessen am 26. August 2026 gegen
  `system.packages.list` und `system.sources.list` — damit sind Aufnahmen einer
  Seite **mit echten Daten** hier möglich und nicht nur mit „Der Agent antwortet
  nicht".

  Drei Handgriffe, die Zeit gekostet haben: Der `--config`-Pfad muss **absolut**
  sein; der Log-Schlüssel heisst `log` und nicht `journal` (sonst schreibt der
  Dienst nach `/var/log/srvpanel/agent.log` und meldet das in jeder Zeile); und
  `pkill -f srvpanel-agentd` tötet die eigene Shell mit, weil das Muster auf
  deren Kommandozeile passt (Rückgabewert 144).

  **Und drei Tests setzen voraus, dass hier kein Agent läuft.**
  `LoginTest::test_the_health_check_stays_open` erwartet 503,
  `DomainRouteTest::test_only_an_operator_reaches_the_php_page` prüft eine
  `live`-Eigenschaft, `DumpSizeTest::test_a_missing_file_is_reported` greift auf
  den Datenbankserver durch. Mit laufendem Agenten sind alle drei rot, ohne ihn
  grün — gemessen in beide Richtungen. In der CI läuft keiner, dort sind sie
  stabil; wer hier den vollen Lauf fährt, hält den Agenten vorher an.

  > **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher läuft, misst
  > die Umgebung mit.**

  **Und dasselbe ohne einen laufenden Prozess, am 26. August 2026: Es genügt,
  was von einer Messrunde *liegenbleibt*.** `SourceOwnershipTest` war in der CI
  rot und hier grün, mit demselben Code — `Sources::isOwned()` löste über
  `realpath()` auf, und im Container lag ein `srvpanel.sources`, das die
  Messrunde zu A1 Schritt 7 Stunden vorher hinterlassen hatte.

  > **Ein Test, dessen Ergebnis davon abhängt, was gerade nebenher liegt, misst
  > die Umgebung genauso mit — und dagegen hilft kein Anhalten.**

  Wer hier misst, räumt seinen Prüfkörper hinterher weg; wer einen Wächter baut,
  gibt ihm einen Prüfkörper, den die Umgebung nicht liefern kann.

  **Und zwei weitere setzten voraus, dass hier niemand gebaut hat** — bis zum
  26. August 2026. `PreviousUrlTest` schickte `X-Inertia-Version: ''`, und
  Inertia trägt dort den Stand der Bauartefakte ein: Weicht er ab, kommt **409**
  statt der Seite. In der CI läuft `php artisan test` **vor** `npm run build`,
  also gibt es kein `public/build/manifest.json`, die Fassung ist `null`, und
  die leere Kopfzeile passte. Wer hier `npm run build` fährt — und das tut jede
  Bilderrunde —, hatte danach zwei rote Tests ohne einen kaputten Code.
  Gemessen in beide Richtungen: mit Manifest 0 von 2, ohne Manifest 2 von 2.
  Behoben; gefragt wird die Fassung jetzt bei der Mittelschicht, die sie setzt.

  > **Eine Kopfzeile mit einem Wert, den der Browser nie sendet, ist derselbe
  > Fehler wie eine, die er nie sendet — sie fällt nur später auf.**

  **Und ohne Warteschlange bleibt jeder Vorgang auf `wartet` stehen.**
  `QUEUE_CONNECTION=database`, und niemand arbeitet sie ab; die Vorgangsseite
  zeigt dann „wartet" und `Fortschritt 0 %`, was wie ein hängender Agent
  aussieht. Der Griff ist `php artisan queue:work --queue=operations --once` —
  **mit** der Warteschlange, denn ohne `--queue` bedient der Arbeiter `default`
  und findet nichts.

  Eine Falle beim Einstellen: `composer config -g github-protocols git` meint
  das git://-Protokoll auf Port 9418 und nicht „per git klonen". Der Wert wurde
  als **leere Liste** abgelegt, und der Lauf brach mit „Failed to clone … via
  protocols" ab — mit einer Lücke da, wo das Protokoll stehen sollte.

  > **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
  > braucht einen Versuch.** Derselbe Satz wie bei MariaDB, beim `sshd`, bei
  > PowerDNS und bei PHPStan — diesmal an der Aussage, die diese Datei am
  > häufigsten benutzt hat.

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
  - **shellcheck ist hier installiert, und zwar ohne Zutun** — `shellcheck
    0.9.0` liegt als `/usr/bin/shellcheck`. Hier stand dazu nichts, und das hat
    am 1. September 2026 eine CI-Runde gekostet: Der Zweig kam mit rotem
    `Shell-Skripte` zurück (zweimal `SC2317` in `packaging/bin/apt-run`),
    nachdem vor dem Push nur `bash -n` gefahren worden war. Der Aufruf steht
    wörtlich in `ci.yml` und ist einmal Kopieren:

        shellcheck -e SC1091 packaging/bin/*

    > **Ein Werkzeug, das die CI fährt und das lokal daneben liegt, wird nicht
    > durch ein anderes ersetzt, das eine ähnliche Frage stellt.** `bash -n`
    > beantwortet „parst es", shellcheck „stimmt es".

    Und der Befund selbst gilt über die Shell hinaus: Gerufen wurden zwei
    Funktionen über `$($mass)`, einen Namen in einer Variablen. Gemeldet hat
    shellcheck das erst, als das Skript am Ende ausdrücklich mit `exit` endete —
    solange es durchfallen konnte, nahm es an, jemand binde die Datei ein und
    rufe sie selbst (in beide Richtungen gemessen, `docs/94 §8b`).

    > **Ein Aufruf über einen Namen in einer Variablen ist für ein Werkzeug
    > keiner — gemeldet wird er erst, wenn nichts mehr die Annahme trägt, dass
    > jemand ihn von aussen macht.**
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

    **Und die Gegenprobe genügt nicht — gemessen am 27. August 2026.** In einer
    Agentensitzung gibt PHPStan seinen ganzen Bericht als **eine** Zeile JSON
    aus. Ein `grep -v 'not found'` darüber löscht dann nicht die Zeilen, die es
    meint, sondern **den Bericht**; „nach dem Filter steht nichts mehr da" las
    sich wie „sauber" und war „nichts gemessen". Zwei echte Meldungen über
    fehlende Typangaben standen darin.

    > **Ein Filter über eine einzeilige Ausgabe löscht nicht eine Zeile,
    > sondern alles.**

    Die Gegenprobe hat das nicht gefangen: Sie lief über eine Datei, deren
    Bericht das gefilterte Wort zufällig nicht enthielt — der Filter schlug
    also gar nicht zu, und die Zeile kam durch.

    > **Eine Gegenprobe, die den Fall nicht enthält, in dem der Filter
    > zuschlägt, belegt den Filter nicht.**

    Gefiltert wird deshalb **nach dem Zerlegen** und nicht davor: `json.loads`
    auf die Ausgabe, dann je Meldung über `identifier` entscheiden. Dann ist
    „leer" auch wirklich leer, und ein `LEER` ohne Zerlegen ist eine Frage und
    kein Ergebnis.

    **Und das Zerlegen genügt auch nicht — gemessen am 30. August 2026.** In
    einer Agentensitzung verpackt das Gestell die Ausgabe von PHPStan und
    ersetzt sie durch `{"tool":"phpstan","result":"passed","errors":0}` —
    `--error-format=json` kommt gar nicht erst durch. Ein Leser, der darin
    `files` sucht, findet nichts und meldet „0 Meldungen", und das sieht aus
    wie ein sauberer Lauf. Die Antwort ist dieselbe, die
    `tests/waechter-brechen.sh` seit dem 26. August für PHPUnit gibt:
    `env -u AI_AGENT -u CLAUDECODE` davor.

    > **Eine Verpackung, die das angeforderte Format durch ein eigenes ersetzt,
    > ist kein Formatfehler — sie ist ein Ergebnis, das von einem sauberen Lauf
    > nicht zu unterscheiden ist.**

    Gefangen hat es nur die Gegenprobe: ein absichtliches `strlen(42)`, das
    eine Zeile erzeugen **muss**. Ohne sie wäre der Lauf als grün durchgegangen.

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

  **Und das Bruchskript läuft hier — seit dem 26. August im Ganzen.** Hier
  stand, es brauche `vendor/bin/phpunit` und laufe deshalb nicht; mit dem
  `vendor/` von oben ist der volle Lauf ein Aufruf. Gemessen am 26. August:
  **1527 Prüfungen, `FEHLT: 0`, „Alle Wächter beissen."**, Arbeitsbaum davor und
  danach derselbe.
  Er dauert gut zwanzig Minuten und gehört deshalb in den Hintergrund und in
  eine Datei.

  **Und während er läuft, wird nicht am Repo gearbeitet.** `wiederherstellen()`
  fährt nach **jedem** Eingriff ein `git checkout --` über zwölf Bäume; ein
  zweiter Schreiber verliert darin seine Arbeit, und ein `git add -A` in einem
  offenen Bruchfenster nimmt den Eingriff mit. Am 26. August genau so passiert,
  beides in einem Commit: die Ergänzungen an `docs/81` waren fort, und
  `app/Console/Commands/Databases.php` stand mit `$fehlt = null;` statt seiner
  Prüfung im Repo — committet und gepusht.

  > **Ein Werkzeug, das den Arbeitsbaum herstellt, duldet keinen zweiten
  > Schreiber** — es nimmt ihm seine Arbeit weg und schiebt ihm seine eigene
  > unter.

  Gefunden hat es kein Wächter, sondern `git show --stat` — die Dateiliste des
  eigenen Commits, gelesen statt überflogen. Im `git status` sieht beides aus
  wie nichts: Der eine Schaden ist eine Datei, die *fehlt*, der andere eine,
  die *dazugehört*.

  > **Ein Commit, dessen Dateiliste man nicht liest, ist eine Zusage über
  > Änderungen, die man nicht gesehen hat.**

  **Aber nur mit fester Umgebung.** In einer Agentensitzung verpacken `AI_AGENT`
  und `CLAUDECODE` die Ausgabe von PHPUnit als eine Zeile JSON; `pruefe()` sucht
  `OK (` und `FAILURES!` und fällt damit bei **jeder** Prüfung in den Zweig
  „unlesbar". Der Kopf des Skripts nimmt beide seitdem selbst heraus —
  dieselbe Antwort, die `Runner::ENVIRONMENT` seit P0 für den Agenten gibt.

  > **Ein Parser, der zwischen zwei Umgebungen hin- und hergebaut wird, ist
  > nicht falsch geschrieben — er misst eine Umgebung, die niemand festgelegt
  > hat.** Dieser Leser ist genau das zweimal gewesen: einmal auf JSON, weil er
  > in einer Agentensitzung entstand, und zurück auf Text, weil er in der CI
  > nichts fand.

  **Drei Brüche kann das Skript grundsätzlich nicht fahren** — die zu
  `BreakScriptTest` und die beiden zu `ChangelogTest`. Sie ändern das Skript
  selbst beziehungsweise `CHANGELOG.md`, und der Rückweg fasst beides zu Recht
  nicht an. Die Befehlsfolge steht im Kopf des jeweiligen Tests; gefahren werden
  sie von Hand, und **gesichert wird mit `cp`**: Liegt am Ziel eine eigene, noch
  nicht eingecheckte Änderung, nähme `git checkout --` sie mit.

  **Der einzelne Eingriff geht auch ohne all das** — Datei sichern, den
  Python-Block von Hand anwenden, den Wächter im Gestell fahren, Datei
  zurückholen. Am 20. August hat genau das einen Wächter überführt, der
  **wirkungslos** war, obwohl der Eingriff die Datei nachweislich veränderte
  (`docs/64 §2`). Wer einen Eingriff schreibt oder den Wächter dahinter anfasst,
  belegt ihn so, statt ihn ungeprüft zu pushen.

  > **„Das Bruchskript läuft hier nicht" ist keine Ausrede, sondern ein
  > Handgriff mehr.** Und seit dem 26. August ist der Satz selbst nicht mehr
  > wahr — derselbe wie bei MariaDB, beim `sshd`, bei PowerDNS, bei PHPStan und
  > bei Composer.

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
- **Eine Seite mit echten Daten aufzunehmen braucht drei Dinge, und eines
  fehlt hier.** Gemessen am 27. August 2026 für die Bilderrunde der
  Rollenteilung: Der Agent läuft (siehe oben), `artisan serve` läuft, und
  `/usr/lib/srvpanel/apt-run` muss aus `packaging/bin` dorthin installiert
  sein — der Pfad steht absolut in der Positivliste. **Was fehlt, ist systemd
  als PID 1:** `system.packages.list` geht über `systemd-run`, damit
  Simulation und Einspielen von Bauart wegen denselben Weg nehmen, und ohne
  Bus ist die Frage zu Recht unbeantwortbar.

  Der Ausweg ist eine Attrappe in einer **eigenen Mount-Namespace** —
  `unshare -m bash -c 'mount --bind <attrappe> /usr/bin/systemd-run; exec php
  agent/bin/srvpanel-agentd serve …'`. Ausserhalb bleibt die echte Datei
  unangetastet, und gemessen wird ohnehin die **Lage** der Seite und nicht,
  woher die Zahlen kommen.

  > **Ein Werkzeug, das dem Prüfling fehlt, ersetzt man in seiner Namespace und
  > nicht im System — sonst misst der nächste Lauf den Ersatz.**

  **Seit dem 30. August gibt es dafür auch den echten Weg** — ein systemd als
  PID 1 in einer eigenen Namespace (siehe oben). Die Attrappe bleibt trotzdem
  der günstigere Griff, wenn nur die **Lage** einer Seite gemessen wird: Sie
  kostet einen Bind-Mount statt eines laufenden Init.

  **Und der Prüfkörper wird hinterher weggeräumt**, `apt-run` eingeschlossen:
  Genau daran war `SourceOwnershipTest` einen Tag zuvor in der CI rot und hier
  grün.
- **Playwright ist nicht installiert, Chromium schon.**
  `PLAYWRIGHT_SKIP_BROWSER_DOWNLOAD=1 npm install --no-save playwright` holt
  es in neun Sekunden; der ausführbare Pfad ist `/opt/pw-browsers/chromium`
  **selbst** und nicht `…/chromium/chrome-linux/chrome` — der Name ist ein
  Symlink auf die Binärdatei. Liegt das Aufnahmeskript im Scratchpad, löst
  node `playwright` von dort auf und findet nichts: Der Import braucht den
  absoluten Pfad.
- **Die Anmeldung geht über zwei Seiten und nicht über eine.** Das Panel
  verlangt den zweiten Faktor; er sitzt auf `/two-factor` im Feld `name="code"`
  und **nicht** im Anmeldeformular. Gewartet wird auf dieses Feld und nicht auf
  eine Adresse: Inertia navigiert über die History-API, und `waitForURL`
  wartet auf ein `load`, das dabei nie kommt.

  > **Eine Wartebedingung, die auf ein Ereignis zeigt, das der Prüfling nicht
  > auslöst, läuft in die Zeitüberschreitung und sieht aus wie ein Fehler am
  > Prüfling.**

  Der Code wird **im Moment der Eingabe** geholt (`Totp::codeAt($secret,
  intdiv(time(), Totp::PERIOD))`), nicht vorher — und `two_factor_last_step`
  vor jedem Lauf auf `null`, sonst lehnt der zweite Lauf denselben Schritt ab.
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
