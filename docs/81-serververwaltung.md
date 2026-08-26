# 81 — Serververwaltung für den Admin

*Die Stufe heisst **P7b** und liegt vor P8 — entschieden am 24. August 2026, siehe §12.1. Geplant wurde sie unter „P9a“.*

Geplant am 24. August 2026. Der Auftrag steht in `docs/80`: Der Betreiber hat
die Admin-Ansicht gegen Plesk und cPanel gehalten und gefragt, was noch
hineingehört. Sechzehn Vorschläge sind daraus geworden; **A1 — Paketquellen und
Systemupdates über apt** war seine eigene Idee und ist der grösste.

**Dieses Dokument plant A1 vollständig und die übrigen als Skizze**, in der
Reihenfolge aus `docs/80 §6.1`. Die Abarbeitung erfolgt in einer eigenen
Sitzung — **P7 ist seit dem 24. August abgenommen** (`docs/78`), und die
Adminfunktionen kommen damit vor P8. Die Übergabe an jene Sitzung ist
`docs/79`; sie trägt den Zustand des Projekts, dieses Dokument den Plan.

> **Umnummeriert am 24. August 2026.** Dieses Dokument hiess `docs/74`, das
> nebenstehende `docs/73` — beide Nummern hat P7 am selben Tag vergeben, in
> einer parallelen Sitzung. Wer in einem Commit oder einer Notiz von vor diesem
> Datum `docs/73` oder `docs/74` liest und Adminfunktionen erwartet, sucht
> `docs/80` und `docs/81`.
>
> **Eine Nummer, die zwei Sitzungen gleichzeitig vergeben, gehört keiner von
> beiden.** Der Rückweg ist billig, solange nur zwei Dokumente daran hängen —
> und teuer, sobald ein Protokoll darauf verweist.

**§2 ist die Messrunde, und ein Teil davon ist gefahren** — gegen Ubuntu 24.04
im Container, mit `tests/apt-messen.sh` als Messmittel. Was dort steht, ist
gemessen; was in §2.3 steht, ist offen und darf im Plan nicht als Tatsache
auftauchen.

> **Ein Messmittel, das man aufhebt, macht die Fehler von letztem Mal nicht noch
> einmal.** Der Satz stammt aus `docs/66` und ist der Grund, warum die
> Messvorschrift diesmal von Anfang an im Repo liegt statt in einem
> Sitzungsverlauf.

---

## 1. Was schon da ist

Ausgezählt am Quelltext. **Der Befund, der diese Stufe billig macht:** Das Panel
spricht an fünf Stellen mit apt, und drei davon lesen den Erfolg nach, statt ihn
zu glauben.

| Vorhanden | Was daraus für A1 folgt |
|---|---|
| `panel.update` — `systemd-run`, eigene transiente Unit, Protokoll in `/var/log/srvpanel/update.log`, Sperre gegen den zweiten Lauf | **Der schwerste Teil ist gelöst.** Ein Lauf, der den eigenen Prozess beendet, hat hier schon seine Form |
| `php.version.install` / `.remove` — Paketname aus zwei Positivlisten, Erfolg über `dpkg-query` | Das Muster für „kein Freitext erreicht apt" und für „Erfolg wird gelesen" |
| `pg.server.install` | dasselbe noch einmal, mit `apt-get update` davor |
| `packaging/php-source.sh` | Die **einzige** Stelle, die heute eine Paketquelle schreibt — aus dem postinst, nicht aus dem Panel |
| `SystemInfo::kernelStale()` | Die Frage „Neustart nötig?" ist halb beantwortet, und ihre Lehre (`null` heisst „nicht nachgesehen") gilt hier weiter |
| `ServiceStatus` liest `NRestarts`, `UnitFileState` | zeigt heute niemand — gehört zu A2 |

Neu sind also **eine Seite und vier Operationen**, nicht ein Verfahren.

---

## 2. Die Messrunde

### 2.1 Was gefahren ist

**`tests/apt-messen.sh`, elf Messungen, jede mit Gegenprobe**, gefahren am
24. August 2026 im Container gegen **Ubuntu 24.04 (noble), apt 2.8.3,
dpkg 1.22.6**. Das Skript schreibt nirgends nach `/etc` und ist damit auf einem
echten Server fahrbar — dafür ist es gebaut.

Der Container taugt dafür ausnahmsweise gut: Er trägt **beide Formen**
nebeneinander (drei `.sources`, eine `.list`), eine Quelle mit **eingebettetem
PGP-Block**, eine mit **mehreren Suiten in einer Zeile**, und mit
`ondrej-ubuntu-php-noble.sources` genau die Quelle, die das Panel selbst
einrichtet.

**M5 ist der teuerste Fund, und er betrifft bestehenden Code.**

> **`apt-get update` gibt 0 zurück, auch wenn jede einzelne Quelle unerreichbar
> war.** Die Fehlschläge stehen als `W:`-Zeilen auf stderr, apt arbeitet mit den
> alten Listen weiter, und der Rückgabewert sagt nichts.

Gemessen mit Gegenprobe gegen eine Quelle auf `127.0.0.1:1`: `rc=0`, zwei
`W:`-Zeilen. Mit `--error-on=any`: `rc=100`, dieselben zwei Zeilen als `E:`.

**Das ist keine Nachlässigkeit von apt, sondern seine Zusage.** Der
Rückgabewert beantwortet nicht „habe ich alle Quellen erreicht", sondern „habe
ich danach einen benutzbaren Zustand" — und eine unerreichbare Quelle ist dafür
kein Hindernis, weil die alte Liste liegen bleibt. Die Meldung sagt es wörtlich:
*„They have been ignored, or old ones used instead."* Die Auskunft ist also
**da**; sie steht auf stderr, und `Result` trennt `stdout` und `stderr` längst.
Sie liest an dieser Stelle nur niemand.

> **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine
> Prüfung — er ist eine Zeile, die aussieht wie eine.**

### 2.1a Was M5 an den vier Aufrufstellen anrichtet

**Nachgetragen am 24. August 2026, auf die Rückfrage des Betreibers.** Die
Fassung oben stand hier als ein Satz für alle vier Stellen, und das war zu
grob: An zweien fängt der bestehende Nachlesecode den Schaden tatsächlich ab.
Der Unterschied entscheidet, was Schritt 1 überhaupt zu tun hat.

| Stelle | Was geschieht | Wie schlimm |
|---|---|---|
| `PhpVersionInstall` | Sury unerreichbar → `apt-get install php8.4-fpm` findet nichts → rc≠0 → Abbruch mit *„Die Installation ist fehlgeschlagen: Unable to locate package"* | **Zustand richtig, Diagnose falsch.** Der Betreiber sucht am Paket, der Fehler sitzt an der Quelle |
| `PgServerInstall` | dasselbe; zusätzlich fängt `describe()` es mit *„apt meldet Erfolg, PostgreSQL fehlt trotzdem"* | dito |
| dieselben, **mit alten Listen** | apt installiert die Fassung, die in der veralteten Liste steht. `missing()` ist zufrieden, weil das Paket danach dasteht | **still.** Man bekommt eine veraltete Fassung und erfährt nichts |
| `PanelUpdate` | `apt-get update -qq && apt-get install --only-upgrade srvpanel` — das `&&` greift nie, weil `update` immer 0 ist. Mit alten Listen findet `--only-upgrade` nichts Neueres, meldet `0 upgraded` und endet mit **rc 0** | **der schlimmste.** Das Panel meldet „Update läuft", die Fassung bleibt stehen, und im Protokoll steht ein erfolgreicher Lauf |

Die beiden Sätze „Erfolg wird gelesen und nicht geglaubt", die in
`PhpVersionInstall` und `PgServerInstall` als Kommentar stehen, tun also ihre
Arbeit — sie fangen den **Zustand**. Was keiner von beiden fängt, ist die
**Ursache**. Und `PanelUpdate` hat die zweite Frage gar nicht, weil es dabei
selbst neu startet.

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

**Für A1 zählt die dritte Zeile.** `system.packages.list` würde nach einem
`refresh` mit toter Sicherheitsquelle **„0 Sicherheitsupdates"** anzeigen. Das
ist die Lehre dieses Repos in Reinform: eine Null, die „nicht nachgesehen"
bedeutet und wie „nichts zu tun" aussieht.

### 2.1b Was daraus zu bauen ist

Drei Teile, und keiner davon ist gross:

1. **Ein Leser statt eines Rückgabewerts.** Eine Stelle — `Apt::refresh()` —
   ruft `apt-get update`, liest `stderr` auf `^W: Failed to fetch <URI>` und
   gibt **je Quelle** einen Ausgang zurück statt eines Wahrheitswerts. Der
   Rückgabewert bleibt als zusätzliche Prüfung stehen: Er ist nicht falsch, nur
   unvollständig.
2. **Die Aufrufer entscheiden verschieden.** `PhpVersionInstall` bricht ab, wenn
   **die Quelle, die es braucht**, unerreichbar war — mit dieser Begründung
   statt der über das Paket. `system.packages.list` bricht nicht ab, sondern
   zeigt die tote Quelle neben der Zahl.
3. **`PanelUpdate` bekommt seine zweite Frage:** nach dem Lauf die Fassung
   nachlesen und melden, wenn sie dieselbe geblieben ist. Das geht erst nach dem
   Neustart, also im postinstall oder beim nächsten Start — **dieser Teil hängt
   deshalb an Schritt 6 und nicht an Schritt 1.**

**Und nicht `--error-on=any` global.** Die Fahne ist die richtige Härte für
einen Lauf, der genau eine Quelle braucht, und die falsche für einen, der alle
nachsieht: Eine vorübergehend unerreichbare Drittquelle würde damit ein
Sicherheitsupdate aus dem Ubuntu-Archiv blockieren.

> **Eine Härte, die nur einheitlich zu haben ist, gehört nicht an eine Stelle,
> an der die Aufrufer verschieden entscheiden müssen.**

Das ist ein Befund und kein Merkmal: Die Teile 1 und 2 gehören **vor** A1
behoben, in Schritt 1.

**M3 ist die Form, an der der Leser hängt.** Zwei Fallen in einer Zeile:

```
Inst coreutils [9.4-3ubuntu6.1] (9.4-3ubuntu6.2 Ubuntu:24.04/noble-updates [amd64])
Inst tar [1.35+dfsg-3build1] (1.35+dfsg-3ubuntu0.4 Ubuntu:24.04/noble-updates, Ubuntu:24.04/noble-security [amd64])
Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])
```

1. **Die eckige Klammer mit der alten Fassung fehlt bei einer Neuinstallation**
   — und die Architektur steht ebenfalls in eckigen Klammern, am Ende, innerhalb
   der Rundklammer. Wer „die eckige Klammer" liest, hält bei `cowsay` das `[all]`
   für die alte Fassung. Gemessen: 146 mit, 0 ohne — **der Fall, der bricht, kam
   in der Messung nicht vor** und musste eigens erzeugt werden.
2. **Die Herkunft ist eine Liste.** 132 von 146 Zeilen tragen zwei Herkünfte,
   `noble-updates` **und** `noble-security`. Ein Sicherheitsupdate erkennt man
   daran, dass **irgendeine** davon auf `-security` endet — nicht die erste.

> **Ein Feld, das meistens genau einen Wert hat, ist kein Feld mit einem Wert.**

**M1 und M2 zusammen entscheiden den Aufbau.** `Signed-By:` kann ein ganzer
PGP-Block sein, gefaltet über vierzig Zeilen mit führendem Leerzeichen und `.`
für die Leerzeile; eine Datei trägt mehrere Stanzas; `Suites:` trägt mehrere
Suiten. Vier Dateien werden zu **achtzehn** Zielen.

Daraus folgt die Entscheidung, die den grössten Teil der Arbeit spart:

> **Der deb822-Leser wird nicht gebaut.** `apt-get indextargets` ist apts eigene
> aufgelöste Sicht — mit `Origin`, `Suite`, `Component`, `Codename`, `Base-URI`
> und `Trusted` je Ziel, in einer Form, die für Maschinen gedacht ist.

Was `indextargets` **nicht** kann: eine abgeschaltete Quelle zeigen — sie ist
dort schlicht fort. Deshalb zwei Quellen nebeneinander und getrennt beschriftet:
**was apt benutzt** (`indextargets`) und **was konfiguriert ist** (die Dateien).

> **Zwei Fragen, die verschieden lauten, brauchen zwei Antworten — auch wenn sie
> meistens dasselbe sagen.**

**Hier stand bis zum 26. August auch, `indextargets` könne nicht sagen, aus
welcher Datei ein Ziel stammt.** Das war falsch und nie gemessen: Jeder Block
trägt `Sourcesentry: <datei>:<stanza>`. Die beiden Sichten lassen sich damit
**verbinden** statt nebeneinandergestellt zu werden — §2.3b hat die acht
Messungen dazu, und Q2 nennt die Falle in der Schreibweise.

**Der Rest in Kürze:**

| | Gemessen |
|---|---|
| **M4** zurückgehalten | hier 0; `upgrade` und `dist-upgrade` gleichauf. **Der Fall fehlt** und ist in §2.3 offen |
| **M6** Schlüssel | `gpg --show-keys --with-colons`, Feld 7 = Ablauf als Unixzeit, leer = nie. 8 Bunde, 9 `pub`, **0 mit Ablauf** — auch hier fehlt der Fall, der zählt |
| **M7** Neustart | `update-notifier-common` nicht installiert, `/run/reboot-required` fehlt. **Das heisst nicht „kein Neustart nötig"**, sondern „nicht nachgesehen" — dieselbe Lehre wie `kernelStale()` |
| **M8** unbeaufsichtigt | `unattended-upgrades` nicht installiert. Und: `apt-config dump` meldet `APT::Periodic::Enable "0"`, gesetzt von einem **fremden** Paket (`docker-disable-periodic-update`) |
| **M9** Conffiles | keine `.dpkg-dist` vorhanden — Fall fehlt, §2.3 |
| **M10** Sperren | alle vier vorhanden: `dpkg/lock-frontend`, `dpkg/lock`, `apt/lists/lock`, `archives/lock` |
| **M11** Historie | 107 Blöcke, alle mit `Commandline`, **keiner mit `Requested-By`** |

**M8 trägt einen eigenen Satz**, weil er das Muster dieser ganzen Stufe ist:
`apt.conf.d` wird lexikalisch aufgelöst, die letzte Datei gewinnt, und ein
fremdes Paket hatte hier die Einstellung still überschrieben.

> **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand.**
> Gefragt wird `apt-config dump`, nicht die Datei, die man selbst geschrieben
> hat.

**M11 dazu:** `Requested-By` fehlt in allen 107 Blöcken, weil hier alles als
root lief. Auf einem echten Server steht es dort — und damit ist
`/var/log/apt/history.log` die Auskunft darüber, **wer** ein Paket eingespielt
hat, auch wenn es an der Kommandozeile geschah. Das ist der Grund, warum A5 sie
führt und nicht das Panel-Protokoll.

### 2.1c M12 — Conffiles, nachgemessen am 24. August

**Nachgetragen, weil Frage 3 aus §3 nicht aus dem Gedächtnis zu beantworten
war.** Die Probe ist `tests/apt-conffile-messen.sh`; sie installiert und
entfernt ein selbstgebautes Prüfpaket und gehört deshalb **nicht** in
`apt-messen.sh`, dessen Zusage „nur lesend" der Grund ist, dass es auf
`cloudsrv24` fahren darf.

| Lauf | Ergebnis |
|---|---|
| **M12a** ohne `--force-conf*`, stdin am Dateiende | `end of file on stdin at conffile prompt`, **rc=1**, Paket bleibt `iU`, `.dpkg-new` daneben |
| **M12a2** ohne `--force-conf*`, stdin offen | **rc=124** — der Lauf wartet ohne Zeitgrenze, Paket bleibt `iU` |
| **M12b** mit `--force-confold`, Conffile geändert | rc=0, Paket `ii`, Bestand bleibt, `.dpkg-dist` daneben, **drei `==>`-Zeilen** in der Ausgabe |
| **M12c** Gegenprobe, Conffile unverändert | rc=0, neue Fassung kommt durch, **keine** `.dpkg-dist` |

**Der erste Befund ist der wichtige:** `DEBIAN_FRONTEND=noninteractive`
beantwortet die Conffile-Frage **nicht**. Die Fahne gilt für debconf; diese
Frage stellt dpkg selbst, auf stdin. Beide Ausgänge sind schlecht, und welcher
eintritt, hängt daran, wie stdin steht — bei einem Lauf unter `systemd-run`
also an einer Eigenschaft, die mit dem Paket nichts zu tun hat.

> **Eine Fahne, die Interaktion abschalten soll, schaltet die eines anderen
> Programms ab als die, die im Weg steht.**

**Und der zweite Befund steckte im Messmittel.** Der erste Wurf dieser Probe
hat gemessen, `--force-confold` lasse die Datei *stumm* zurück — mit einem
`grep`, das drei Zeilen zu früh abschnitt. Die drei `==>`-Zeilen stehen da. Der
Satz wäre so in den Plan gegangen und hätte Abnahmekriterium 6 falsch begründet.

> **Eine Messung, die zu früh abschneidet, meldet nicht „nichts gefunden",
> sondern „nicht hingesehen" — und die beiden sehen gleich aus.**

Der Grund, das Dateisystem trotzdem abzusuchen, bleibt bestehen: In einem Lauf
über 146 Pakete gehen drei Zeilen unter. **Untergehen ist aber etwas anderes
als nicht dastehen**, und nur das eine ist wahr.

### 2.2 Was das Messmittel selbst gekostet hat

Zwei Fehler in `tests/apt-messen.sh`, beide beim ersten Lauf gefunden und beide
lehrreich genug, um sie festzuhalten:

1. `$(grep -c … || echo 0)` gibt **zwei** Nullen aus: `grep -c` schreibt seine
   `0` und endet mit 1, dann feuert das `||`. Der Rückfall war für den Fall
   gedacht, dass die Datei fehlt — er greift auch, wenn sie da und leer ist.
   > **Ein Rückfall, der nicht zwischen „nichts gefunden" und „nicht gesucht"
   > unterscheidet, verdoppelt die Antwort.**
2. `basename` über vier Sperrpfade ergab dreimal `lock`. Aus einer vollständigen
   Messung wurde eine, die drei Zeilen als Wiederholung aussehen lässt.

### 2.3 Was offen ist — und im Plan nirgends als Tatsache stehen darf

~~**Auf den drei anderen Zielplattformen ungemessen:** Debian 12, Debian 13,
Ubuntu 22.04.~~ — **gemessen am 26. August 2026, und zwar nicht einmal, sondern
fortlaufend.** Der CI-Job `apt-messrunde` fährt `tests/apt-messen.sh` und
`tests/apt-faelle-messen.sh` bei jedem Lauf in einem Container je Plattform und
legt die Ausgabe als Artefakt ab.

> **Eine Messung, die einmal jemand von Hand macht, ist ein Datum. Eine, die die
> CI macht, ist eine Zusage.**

**Die Zahlen stehen deshalb bewusst nicht hier**, sondern im Artefakt des
jeweiligen Laufs — ein Dokument, das sie festschreibt, veraltet mit dem nächsten
Abbild. Was hier steht, sind die drei Erwartungen, die dieser Abschnitt
aufgestellt hat, und ihre Antwort.

| Erwartung | Antwort |
|---|---|
| Debian 12 liefert noch `/etc/apt/sources.list` mit Inhalt | **falsch.** 0 `.list`-Dateien, 1 `.sources`-Datei; das heutige Abbild ist deb822. |
| Der Name der Sicherheitssuite unterscheidet sich | **richtig** — und feiner als gedacht: `bookworm-security`, `trixie-security`, `jammy-security`. Debian legt sie auf einen eigenen **Pfad** (`deb.debian.org/debian-security`), Ubuntu auf einen eigenen **Rechner** (`security.ubuntu.com`). |
| `--error-on=any` auf apt 2.4 (Ubuntu 22.04) | **es gibt ihn.** Rückgabewert 100 auf Debian 12, Debian 13 und Ubuntu 22.04. |

> **Eine Erwartung im Plan ist keine Messung.** Die erste war falsch, und sie
> hätte den Leser der Quellen auf eine Datei geschickt, die es nicht gibt.

**Und der Befund M5 trägt auf allen vier Plattformen.** `apt-get update` gibt
bei einer unerreichbaren Quelle überall `0` zurück, schreibt überall 0 Bytes auf
stdout und die `W:`-Zeilen überall auf stderr. Das war bis hierher an einer
Plattform gemessen und für die anderen angenommen.

**Ein Nebenbefund am Messmittel, ungefixt und benannt:** M6 meldet auf Debian 12
und 13 „Schlüssel mit Ablaufdatum 48" neben „Gegenprobe: pub-Zeilen gesamt 41".
Die Zahlen widersprechen sich nur scheinbar — die Messung liest drei
Verzeichnisse, die Gegenprobe zwei.

> **Eine Gegenprobe über eine andere Grundgesamtheit als die Messung ist keine.**


**Fälle, die im Container nicht vorkamen und eigens erzeugt werden müssen:**

| Fall | Warum er gebraucht wird |
|---|---|
| eine Neuinstallation in `dist-upgrade` (`Inst` ohne `[alt]`) | die Falle aus M3, Punkt 1 |
| ein zurückgehaltenes Paket (M4) | die Zahl, die in die Anzeige gehört |
| ein Schlüssel **mit** Ablaufdatum (M6) | sonst misst die Ablaufprüfung nichts |
| ~~eine `.dpkg-dist` nach `--force-confold` (M9)~~ | **geschlossen am 24. August**, siehe §2.1c |
| `Requested-By` in der Historie (M11) | die Auskunft, für die A5 sie führt |

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Fünf der elf Messungen standen auf einer Null ohne Nachbarn; **vier
> sind es noch**, seit M12 die `.dpkg-dist` geschlossen hat.

**Alle vier sind hergestellt**, von `tests/apt-faelle-messen.sh` und in jedem
CI-Lauf neu. Von sechzehn Fällen über vier Plattformen fehlt **einer**: Auf
`debian:12` gibt es kein aktualisierbares Paket, also lässt sich „zurückgehalten"
dort nicht herstellen. Das Skript nennt ihn „AUF DIESER PLATTFORM NICHT
HERSTELLBAR" und zählt ihn getrennt.

> **Ein Fall, den die Plattform nicht hergibt, ist kein Fehlschlag — aber er
> darf auch nicht wie ein Erfolg aussehen.**

**Der Fall `Requested-By` hat dabei am meisten gelehrt.** apt schreibt die Zeile
nur, wenn `SUDO_UID` gesetzt ist **und** auf einen Benutzer auflöst — `SUDO_USER`
allein genügt nicht, und eine unbekannte Kennung erzeugt nichts. Der erste Lauf
setzte `SUDO_UID=1000` fest; in `debian:12` gibt es diesen Benutzer nicht, und
das las sich wie „diese Plattform schreibt die Zeile nicht".

> **Eine Kennung, die auf niemanden zeigt, erzeugt keine Zeile — und das sieht
> aus wie ein Merkmal der Plattform.**

### 2.3a Zwei Fallen, die erst der Leser gefunden hat (26. August 2026)

Beide beim Bau von Schritt 3, beide an echter apt-Ausgabe, und **keine von
beiden stand in M1 bis M12.**

**Die erste war die teuerste, weil sie wie ein Ergebnis aussah.** apt hängt
hinter der schliessenden runden Klammer einer `Inst`-Zeile an, welche Pakete
diese Zeile ausgelöst haben — als eine oder mehrere eckige Gruppen, manchmal
leer:

    … [amd64]) []
    … [amd64]) [perl:amd64 ]
    … [amd64]) [libpam-modules:amd64 on libpam-modules-bin:amd64] [libpam-modules:amd64 ]

Der erste Leser endete mit `\)$` und warf jede solche Zeile wortlos weg.
Gemessen: **145 `Inst`-Zeilen, davon 56 mit Anhang, gelesen wurden 89** — und
die Operation meldete 89 aktualisierbare Pakete gegen 145 auf der
Kommandozeile.

> **Eine Zeile, die der Leser verwirft, fehlt in keiner Summe — sie fehlt nur
> im Ergebnis.** 89 ist eine Zahl, die niemand für einen Fehler hält.

Gefunden hat sie **nicht** der Wächter, sondern das Abnahmekriterium dieses
Schritts: „die Zahlen stimmen mit der Kommandozeile überein". Ein Wächter über
selbstgebaute Zeilen kann eine Form nicht kennen, die niemand gesehen hat.

> **Ein Prüfkörper, den man selbst baut, enthält die Fälle, an die man gedacht
> hat.** Deshalb steht neben `InstLineTest` der Vergleich mit der
> Kommandozeile und nicht statt ihm.

**Die zweite kam heraus, weil ein Bruch nicht gebissen hat.** `Packages::security()`
trennte die Suite hinter dem letzten Schrägstrich ab, begründet mit
`foo-security:1/stable` — ein Anbieter, der auf `-security` endet, solle nicht
für ein Sicherheitsupdate gehalten werden. Die Begründung war falsch: Die Suite
ist ein **Suffix** der Herkunft, `str_ends_with` kann über beiden also gar nicht
verschieden antworten. Die Trennung war Verzierung, und der Kommentar daneben
behauptete eine Wirkung, die sie nicht hatte.

> **Ein Eingriff, der nicht beisst, sagt entweder etwas über den Wächter oder
> etwas über die Regel.**

Die Fassung, die trägt, prüft am Ende und der Bruch setzt ein `str_contains` —
den Fehler, den jemand wirklich machen würde. Gemessen über die drei
Herkunftsformen dieses Containers, und die dritte war auch neu:
`Docker CE:noble` trägt ein **Leerzeichen** im Anbieter und hat **keinen**
Schrägstrich. Wer die Herkünfte am Leerzeichen trennt statt am Komma, macht
daraus zwei.

---

### 2.3b Die Messrunde vor Schritt 4 (26. August 2026)

Acht Fragen an `apt-get indextargets` und die Quelldateien, gemessen bevor eine
Zeile entstand. **Zwei davon werfen eine Aussage dieses Plans um.**

| | Frage | Gemessen |
|---|---|---|
| Q1 | Sagt `indextargets`, aus welcher Datei ein Ziel stammt? | **Ja.** `Sourcesentry: <datei>:<n>`, in **allen 18** Blöcken, für `.sources` wie für `.list`. §2 sagt das Gegenteil — siehe unten. |
| Q2 | Ist `n` eine Zeilennummer? | **Nein**, ein Stanza-Index. In `ubuntu.sources` stehen die Stanzas auf Zeile **32 und 40** und heissen `:1` und `:2`. |
| Q3 | Verschiebt sich der Index, wenn eine Stanza abgeschaltet wird? | **Nein.** Stanza 1 auf `Enabled: no` — die Sicherheitsstanza bleibt `:2`. |
| Q4 | Verschwindet eine abgeschaltete Quelle aus den Zielen? | **Ja**, 18 → 17, die Datei bleibt. Das Abnahmekriterium ist herstellbar. |
| Q5 | Heisst „kein Ziel" also „abgeschaltet"? | **Nein.** Eine Quelle ohne geholten Index fehlt genauso. |
| Q6 | Zählen Kommentarblöcke als Stanza? | **Nein** — nur Blöcke mit mindestens einer Feldzeile. `ubuntu.sources` beginnt mit 31 Kommentarzeilen, und die erste Feld-Stanza ist trotzdem `:1`. |
| Q7 | Welche Formen hat `Signed-By:`? | **Drei**, alle drei auf dieser Maschine — siehe unten. |
| Q8 | Und im `.list`-Format? | `signed-by=<pfad>` in den Optionsklammern, nicht als Feld. |

**Q1 ist eine Berichtigung dieses Dokuments.** §2 schreibt: *„Was `indextargets`
nicht kann: sagen, aus welcher Datei ein Ziel stammt, und eine abgeschaltete
Quelle zeigen."* Die zweite Hälfte stimmt, die erste nicht — `Sourcesentry`
trägt Datei **und** Stanza-Index, und Q3 sagt, dass dieser Index stabil bleibt.
Damit lassen sich die beiden Sichten **verbinden** statt nebeneinanderzustellen,
und der Betreiber muss sie nicht mit dem Auge vergleichen.

> **Wissen aus zweiter Hand sieht aus wie Wissen.** Der Satz stand hier seit dem
> Ausschreiben des Plans und ist nie an `indextargets` selbst geprüft worden.

**Q2 ist die Falle daneben.** `datei:1` sieht aus wie eine Zeilenangabe — das ist
die Schreibweise, die jedes Werkzeug dieser Welt dafür benutzt. Wer dorthin
springt, landet in `ubuntu.sources` auf einem Kommentar.

> **Eine Zahl hinter einem Doppelpunkt sieht aus wie eine Zeilennummer.**

**Q5 entscheidet die Anzeige.** Aus den Zielen allein sind zwei sehr verschiedene
Zustände nicht zu unterscheiden: eine Quelle, die der Betreiber **abgeschaltet**
hat, und eine, für die apt **keinen Index hat** — weil sie neu ist oder weil das
Holen scheitert. Gemessen an beidem: eine frisch angelegte Datei erscheint nicht
in den Zielen, und die zwei PPAs dieses Containers erscheinen seit einem
`apt-get update` nicht mehr, weil der Proxy sie mit 403 abweist. `Enabled:`
gehört deshalb aus der **Datei** gelesen; ohne dieses Feld meldet die Anzeige
„abgeschaltet" für eine Quelle, die niemand abgeschaltet hat.

> **Zwei Zustände, die von einer Seite gleich aussehen, brauchen die zweite
> Seite — nicht eine Vermutung.**

**Q7, die drei Formen von `Signed-By:`**, alle drei in `/etc/apt/sources.list.d`
dieser Maschine:

    Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg      ← ein Pfad
    Signed-By:                                                     ← leer, dann
     -----BEGIN PGP PUBLIC KEY BLOCK-----                             gefaltet
    Signed-By: -----BEGIN PGP PUBLIC KEY BLOCK-----                ← Wert in
     .                                                                derselben
     mQINBGYo0vEBEAC0Semxy5I2b8exRUxJfTKkHR4f5uyS0dTd9vYgMI5T…         Zeile

Ein Leser, der „nicht leer heisst Pfad" annimmt, hält bei der dritten Form
`-----BEGIN PGP PUBLIC KEY BLOCK-----` für einen Dateinamen. Die Unterscheidung
ist nicht die Leere, sondern die **Faltung**: Eine Fortsetzungszeile beginnt mit
einem Leerzeichen, und `.` steht für die Leerzeile darin.

> **Ein Wert, der auch leer sein darf, unterscheidet sich nicht dadurch von
> einem Pfad, dass er nicht leer ist.**

**Und eine Warnung, die Zustand gekostet hat.** `apt-get update` mit
`-o Dir::Etc::sourceparts=-` aktualisiert nicht *eine* Quelle — es macht apts
Sicht der Welt zu dieser einen und **räumt die Indexdateien aller übrigen ab**.
Aus 18 Zielen wurden so 1, und die zwei PPAs kamen danach nicht wieder, weil der
Proxy dieses Containers sie sperrt.

> **Eine Einschränkung des Blickfelds ist keine Einschränkung der Wirkung.**

---

### 2.3c Der Befund, den die Bilderrunde nicht sehen konnte (26. August 2026)

Die Seite war in allen vier Lagen grün — `dokument=0`, Gegenprobe 200/200,
`schiebt=0`, `rollt` nur die zwei gewollten Behälter. Und sie war bei 390 px
**29 412 px hoch**: 145 Paketzeilen als gestapelte Kärtchen, rund 203 px je
Zeile, **fünfunddreissig Telefonschirme**.

> **Eine Messung, die nur waagerecht misst, sagt über die Höhe nichts.**

Das ist derselbe Schnitt wie bei der Baumansicht aus `docs/46 §11.1`: Der
waagerechte Überlauf war dort in jedem Entwurf 0, und entschieden wurde die
Frage senkrecht (4992 px gestapelt gegen 964 px als Baum). Gefunden hat es
beide Male kein Messwert, sondern ein Blick auf das Bild.

Geblättert wird jetzt mit `Page::SIZE` — derselben 50, mit der jede andere
Liste dieses Panels blättert; die Zahl reist aus dem Controller und steht nicht
in der Vorlage. Danach: **11 467 px**.

**Und die Blätterung war beim ersten Wurf auch noch falsch bemessen.**
`Page::SIZE` (50) ist die Seitengrösse der blätternden **Tabellen** dieses
Panels, in denen eine Zeile eine Zeile ist. Gemessen an der echten Seite:

    eine Zeile bei 1440 px:    41 px    50 Zeilen =  3 Bildschirme
    eine Zeile bei  390 px:   179 px    50 Zeilen = 14 Bildschirme

Das **4,4-fache**. Dieselbe Zahl ergibt am Schreibtisch eine bequeme Liste und
auf dem Telefon eine Wanderung.

> **Eine Seitengrösse, die für eine einzeilige Tabelle stimmt, stimmt nicht für
> ein Kärtchen mit vier Feldern.**

> **Zwei Zahlen, die zufällig gleich sind, sind keine gemeinsame Zahl.**

Zwanzig folgt aus der Messung — rund viereinhalb Telefonschirme, bei 1440 px
knapp einer — und steht in der Vorlage statt in `Page::SIZE`. **Nicht** an der
Fensterbreite: Wer beim Drehen des Telefons eine andere Seite vor sich hat,
sucht die Zeile wieder, die er gerade gelesen hat.

**Dazu vier Filter**, weil Blättern allein die Frage nicht beantwortet, mit der
jemand diese Seite öffnet: „Zeigen" (alle · nur Sicherheit · nur neue Pakete),
„Herkunft" aus den Daten statt aus einer gepflegten Liste, und ein Namensfeld.
Der Zustand bleibt **lokal** und reist nicht in der Adresse — anders als bei der
Logs-Seite, wo der Filter zum Agenten muss: Hier läge in einem Serverumlauf ein
zweites `apt-get -s dist-upgrade`, also Sekunden für eine Auswahl, die im
Browser eine Millisekunde kostet.

Gemessen an der echten Seite: alle 145 → `1–20 von 145`; nur Sicherheit →
`1–20 von 124`; nur neue Pakete → leer mit **eigener** Meldung; Herkunft
`Docker CE:noble` → 5 Zeilen ohne Blätterung; Name `libssl` → 2. Und der Fall,
der sonst still bricht: auf Seite 3 (`41–60 von 145`) gefiltert → zurück auf
`1–20 von 124` statt auf eine leere Seite 3.

**Und `fresh` war ein Feld, das der Agent rechnet und niemand liest** — dieselbe
Stufe hat den Satz einen Commit vorher noch zitiert. Es steht jetzt als Kachel
„davon neu" da.

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

### 2.3d Schritt 4b — ein Abnahmepunkt ohne Schritt (26. August 2026)

**Schritt 4 war nach seinem „fertig, wenn" abgehakt und nach seiner
Beschreibung nicht.** §6 sagt über `system.sources.list`: *„…getrennt
beschriftet; **je Schlüssel Fingerabdruck und Ablauf**"*. Gebaut war beides
nicht — das „fertig, wenn" fragt nur nach der abgeschalteten Quelle, und danach
war gemessen worden.

> **Ein „fertig, wenn", das weniger verlangt als die Beschreibung daneben,
> lässt die Hälfte durchgehen.**

Daran hängt **Abnahmepunkt 4** — „ein Schlüssel, der in weniger als dreissig
Tagen abläuft, wird gemeldet, bevor ein Lauf daran scheitert". Der Punkt hat in
§9 keinen eigenen Schritt; er wohnte in Schritt 4, und dort fehlte er.

**`gpg` steht seitdem auf der Positivliste des Agenten**, und das ist die erste
Grenze dieses Projekts — sie gehört begründet. Gebraucht wird es für genau eine
Frage, aufgerufen wird ausschliesslich lesend (`--show-keys --with-colons`),
und **nie mit einem Pfad aus einem Formular**: Die Pfade stammen aus
`Signed-By:` der Quelldateien, die eingebetteten Blöcke gehen über stdin.

**Es liegt auf allen vier Zielplattformen** — gemessen und nicht angenommen:
`tests/apt-faelle-messen.sh` meldet „gpg fehlt" als Ausfall, und alle vier
Messrunden der CI sind grün.

**Sechs Messungen vor der ersten Zeile:**

| | Frage | Gemessen |
|---|---|---|
| K1 | Wo steht der Ablauf? | Feld 7 der `pub`-Zeile als Unixzeit; **leer heisst „läuft nie ab"**. Auf Debian 12 in der CI: eine Zeile leer, eine mit `1819259803`. |
| K2 | Wo der Fingerabdruck? | In der `fpr`-Zeile darunter — und die gehört zur zuletzt gesehenen `pub` **oder `sub`**. Hier: 12 `fpr` bei 11 `pub` und 1 `sub`. |
| K3 | Braucht `gpg` ein Heimverzeichnis? | **Ja, auch nur zum Lesen** — und es legt es an. Ohne beschreibbares HOME stirbt es mit `rc=2`. Einen nur-lesenden Aufruf gibt es nicht. |
| K4 | Liest es einen Block von stdin? | **Ja**, aufgefaltet nach deb822: rc 0, eine `pub`- und eine `fpr`-Zeile. |
| K5 | Ein Pfad, den es nicht gibt? | `rc=2`, keine Zeilen — und „keine Schlüssel gefunden" sähe aus wie „diese Quelle hat keinen". |
| K6 | Wie viele Schlüssel je Bund? | Bis zu **drei** (`ubuntu-archive-keyring.gpg`). |

**K3 entscheidet den Ablageort.** Eine lesende Frage soll keinen Schlüsselbund
in root's Heimverzeichnis anlegen, den danach niemand erklären kann — `gpg`
bekommt deshalb `--homedir` auf einen eigenen Ort unter `/var/lib/srvpanel`.

**Und die Meldung ist der Punkt, nicht die Spalte.** Eine Spalte steht da und
wartet, dass jemand hinsieht; ein abgelaufener Schlüssel bricht `apt-get
update`, und weil das mit `0` endet (M5), meldet der Server danach „nichts zu
tun". Über der Quellentabelle steht deshalb ein Satz, sobald ein Schlüssel
fällig oder unlesbar ist.

**Zum dritten Mal in dieser Runde: zwei Fassungen einer Regel, die einander
decken.** Der Fingerabdruck-Leser trug drei — eine Prüfung auf die Art der
letzten Zeile, ein `$offen['fingerprint'] === null`, und die Zeile, die `$offen`
bei jeder `sub` schliesst. Gemessen: jede allein grün, **erst ohne alle rot.**
Zwei Eingriffe nacheinander bissen nicht, bevor das auffiel.

> **Ein Eingriff, der nicht beisst, sagt entweder etwas über den Wächter oder
> etwas über die Regel.**

**Und M6 im Messmittel ist gerichtet.** Der Nebenbefund aus §2.3 lautete „48 mit
Ablaufdatum neben 41 pub-Zeilen gesamt" — mehr ablaufende als vorhandene. Die
Schleife las drei Verzeichnisse, die Gegenprobe zwei. Eine Liste für beide, und
die Zahl, die zählt, ist dazugekommen: nicht „hat einen Ablauf", sondern „läuft
in unter dreissig Tagen ab".


---

**Und ein zweiter Befund aus demselben Blick.** In der ersten Fassung stand die
Spalte „Zustand" der Quellentabelle am Ende — bei 1440 px ausserhalb des
Bildes. Sie ist die Antwort dieser Tabelle: „kein Index" gegen „11 Ziele" ist
genau die Unterscheidung, für die Schritt 4 gebaut wurde. Die Tabelle rollte
also für die eine Auskunft, deretwegen es sie gibt.

> **Eine Spalte, die man wegrollen muss, ist keine Antwort.**

---

**Nur auf einem echten Server messbar:** wie lange ein voller `dist-upgrade`
läuft (die Zahl entscheidet über die Zeitgrenze der Operation), was passiert,
wenn das Upgrade `srvpanel` selbst enthält, und ob `systemd-run` den Lauf
tatsächlich überlebt, wenn `srvpanel-web` dabei neu startet. Der letzte Punkt
ist der einzige, der A1 zum Scheitern bringen kann — `panel.update` behauptet
ihn seit P1 und **belegt hat ihn nur der eigene Gebrauch**.

---

## 3. Die vier Fragen — entschieden

Vier Fragen, die den Zuschnitt ändern und nicht die Umsetzung. Ausformuliert am
24. August 2026 auf Verlangen des Betreibers — **und am selben Tag alle vier
entschieden.**

| | Entschieden durch | Wie |
|---|---|---|
| **1** Fremde Paketquellen | den Betreiber, 24. August | Vorschlag angenommen: **nein** im ersten Wurf |
| **2** Wer darf einspielen | den Betreiber, 24. August | **eigener Zuschnitt** — zwei Rollen statt zweier Rechte, §11 |
| **3** Conffiles | die Messung, §2.1c | `--force-confold`; die Alternative gibt es nicht |
| **4** Reichweite der Automatik | den Betreiber, 24. August | Vorschlag angenommen: zwei Schalter, verschieden scharf |

**Was unter jeder Frage steht, ist damit Beschluss und nicht Annahme.** Die
Begründungen bleiben stehen, weil eine Entscheidung ohne ihren Grund beim
nächsten Anlass neu verhandelt wird — und weil zwei von ihnen einen Einwand
enthalten, den man kennen muss, wenn man sie später ändern will.

### Frage 1 — Darf das Panel eine fremde Paketquelle hinzufügen?

> **Entschieden am 24. August 2026: nein.** Der Betreiber hat den Vorschlag
> angenommen.

**Anzeigen, ein- und ausschalten, und an hinzufügbaren Quellen nur die drei,
die das Panel ohnehin kennt** — Sury, PGDG, das eigene Repo.

Der Grund ist nicht die Vorsicht, sondern der Hebel: Wer eine Quelle
kontrolliert, kann ein Paket mit höherer Fassungsnummer ausliefern, das ein
beliebiges anderes ersetzt — `libc6`, `openssh-server`, `srvpanel` selbst. Eine
Quelle hinzuzufügen ist damit nicht eine Handlung neben den anderen, sondern
**die Handlung, die alle künftigen umfasst.**

Dagegen steht ein naheliegender Einwand, und er ist ernst zu nehmen: Der Admin
hat SSH und root. Er kann die Quelle in dreissig Sekunden von Hand eintragen —
was schützt ein Panel, das es ihm verbietet?

**Die Antwort steht im Auftrag des Plans und nicht in der Sicherheitslehre.**
Das Panel ist kundenfähig gedacht, und A9 bringt die zweite Rolle: einen
**Administrator**, der verwalten darf und Kritisches nicht (§11). Ab diesem Tag
ist „Quelle hinzufügen" der kürzeste Weg von *Administrator* nach *root auf
Dauer* — über eine Browsersitzung, die gestohlen werden kann. Der heutige
Zustand mit genau einem Konto ist nicht der, für den gebaut wird.

Das ist auch der Grund, warum die Antwort auf diese Frage nicht „der Admin hat
ja ohnehin SSH" sein kann: **Der Administrator hat kein SSH.** Genau er ist die
Rolle, die es hier gibt.

> **Eine Handlung, die heute nichts hinzufügt, weil es nur einen Benutzer gibt,
> fügt an dem Tag etwas hinzu, an dem es zwei gibt — und niemand sieht dann
> nach.**

**Und es gibt einen besseren Weg, den dieses Repo schon geht.** Die Sury-Quelle
kommt als Paket (`srvpanel-php-source`, `packaging/php-source.sh`): Definition
und Schlüssel stecken in einem Artefakt, das das Projekt selbst signiert, statt
in einem Formularfeld. Wer später PGDG will, bekommt `srvpanel-pgdg-source` —
eine Freigabe, kein Textfeld. Das ist dieselbe Grenze wie überall hier: **nicht
Freitext, sondern eine Positivliste, die im Code wächst.**

### Frage 2 — Wer darf einspielen?

**Beantwortet am 24. August, und vom Betreiber am selben Tag umgeworfen — zu
Recht.** Meine Antwort lautete „voller Admin gegen Nur-Lese-Admin". Der
Betreiber hat zwei Rollen vorgeschlagen, von denen die zweite **verwalten darf
und Kritisches weder sieht noch bedient**. Das ist eine andere Achse:

> **Meine Aufteilung trennte nach dem Verb, seine nach dem Gegenstand.** Ein
> Nur-Lese-Admin ist für ein Hosting-Panel keine brauchbare Rolle — wer Kunden
> betreut, muss anlegen und ändern dürfen. Was ihn von der Betreiberrolle
> trennt, ist nicht *ob* er handelt, sondern *woran*.

Das Rollenmodell steht deshalb ausgeschrieben in **§11 bei A9**, weil es über
A1 hinausgeht. Für A1 folgt daraus:

| | Betreiber | Administrator |
|---|---|---|
| Zahlen und Liste sehen | ja | **ja** |
| `refresh` (Listen auffrischen) | ja | ja |
| Updates einspielen | ja | nein |
| Paketquellen sehen | ja | ja, ohne Schlüsselmaterial |
| Paketquellen schalten | ja | nein |
| Unbeaufsichtigte Updates schalten | ja | nein |
| Neustart | ja | nein |

**`refresh` wandert damit auf die Seite des Administrators**, und meine
Begründung dagegen fällt: Sie lautete, „nur lesen" dürfe keine Ausnahme haben,
sonst sei es keine Kategorie mehr. Diese Rolle heisst aber gar nicht „nur
lesen" — sie darf schreiben, nur woanders. Die Ausnahme, gegen die ich
argumentiert habe, gibt es in diesem Modell nicht.

> **Ein Einwand gegen eine Kategorie fällt mit der Kategorie.**

**Und die Liste sieht er vollständig.** Ein Administrator, der nicht weiss, dass
vierzig Sicherheitsupdates offen sind, kann einen Kundenausfall nicht deuten.
Verborgen wird die Handlung, nicht der Zustand — und das ist die Regel, an der
sich in §11 alles ausrichtet.

### Frage 3 — Conffiles: `confold` oder abbrechen?

**`--force-confold`, und die zurückgelassenen Dateien anzeigen.** Diese Frage
war offen und ist jetzt gemessen (§2.1c); die Messung hat die Alternative
erledigt.

**„Abbrechen, wenn ein Paket fragt" ist keine Wahl, sondern der Zustand ohne
Wahl** — und er ist der schlechteste von dreien. Ohne `--force-conf*` stellt
dpkg seine Frage auf stdin, und `DEBIAN_FRONTEND=noninteractive` beantwortet
sie nicht. Gemessen: Bei offenem stdin **wartet der Lauf ohne Zeitgrenze**, bei
stdin am Dateiende bricht er mit rc=1 ab. In beiden Fällen bleibt das Paket
`iU` — ausgepackt, nicht eingerichtet — und die Maschine ist mitten im Lauf
halb aktualisiert.

Bei einem Lauf über 146 Pakete heisst das: Ein einziges Paket mit einer
angefassten Conffile hält den ganzen Vorgang an, an einer beliebigen Stelle.

**`--force-confnew` fällt aus** — es überschreibt, was der Betreiber von Hand
geändert hat, und widerspricht Leitbild 1 wörtlich.

Bleibt `confold`: Der Bestand bleibt, die neue Fassung liegt als `.dpkg-dist`
daneben, und die Ausgabe sagt es in drei `==>`-Zeilen. **Die Anzeige bleibt
trotzdem Pflicht**, aber aus einem anderen Grund als gedacht: nicht weil nichts
dasteht, sondern weil drei Zeilen in einem Lauf über 146 Pakete untergehen.
Deshalb sucht `system.packages.list` das Dateisystem ab und zeigt jede
`.dpkg-dist` mit ihrem Pfad — Abnahmekriterium 6.

### Frage 4 — Wie weit darf die Automatik gehen?

> **Entschieden am 24. August 2026: zwei Schalter.** Der Betreiber hat den
> Vorschlag angenommen, mitsamt den drei Festlegungen darunter.

**Sie sind verschieden scharf:**

1. **Paketlisten auffrischen: immer an.** `APT::Periodic::Update-Package-Lists`
   ändert nichts am System und ist die Bedingung dafür, dass die Anzeige nicht
   lügt. Eine Zahl, die drei Wochen alt ist, ist schlimmer als keine.
2. **Sicherheitsupdates unbeaufsichtigt: anbietbar, voreingestellt aus.** Der
   Betreiber schaltet es ein, wenn er es will.

**Alles darüber hinaus nicht.** Ein unbeaufsichtigtes `dist-upgrade` nimmt
irgendwann ein Paket mit, das eine Fassung wechselt, während niemand hinsieht.

Drei Festlegungen dazu, jede mit ihrem Grund:

- **Kein automatischer Neustart.** `Unattended-Upgrade::Automatic-Reboot` bleibt
  `false`. Der Neustart wird **angezeigt** (M7) und von Hand ausgelöst — ein
  Hosting-Server, der nachts um drei von selbst neu startet, ist ein Ausfall mit
  guter Absicht.
- **Die eigene Quelle bleibt draussen.** `unattended-upgrades` nimmt nur die
  konfigurierten Herkünfte, voreingestellt `${distro_id}:${distro_codename}-security`
  — das eigene Repo ist damit ohnehin nicht dabei. Das ist gut so und gehört
  hingeschrieben, damit es niemand später „der Vollständigkeit halber" ergänzt:
  **Ein Panel, das sich selbst unbeaufsichtigt aktualisiert, kann sich
  unbeaufsichtigt zerlegen**, und für diesen Weg gibt es `panel.update` mit
  seiner Bereitschaftsprüfung.
- **Das Panel betreibt die Automatik nicht selbst**, es konfiguriert die der
  Distribution. `unattended-upgrades` ist auf keinem der vier Ziele
  vorinstalliert (M8), das Einschalten ist also ein `apt-get install` und damit
  ein ausdrücklicher Akt — kein stiller Nebeneffekt.

**Und der Zustand wird aus `apt-config dump` gelesen, nicht aus der eigenen
Datei.** M8 hat gemessen, warum: Ein fremdes Paket hatte `APT::Periodic::Enable`
auf `0` gesetzt, und die eigene Datei hätte weiter „an" gemeldet.

## 4. Das Abnahmekriterium von A1

Acht Punkte, gemessen auf einem echten Server. Der Lauf dazu wird eigens
geschrieben, nach dem Muster von `docs/58` und `docs/65`.

> **Hier stand `docs/76`, und die Nummer gehört seit P7 der Bilderrunde**
> (`docs/76-protokoll-bilderrunde-p7.md`). Der Verweis zeigte damit auf ein
> fremdes Dokument — und `DocLinkTest` konnte das nicht sehen, weil er prüft,
> ob die Nummer *existiert*, und nicht, ob sie das Gemeinte trägt.
>
> **Eine Nummer, die man vergibt, bevor das Dokument existiert, ist genau der
> Vorgang, der `docs/73` und `docs/74` doppelt vergeben hat.** Deshalb steht
> hier keine mehr: Der Lauf bekommt seine Nummer, wenn er geschrieben wird, und
> zwar nach einem `ls docs/`.

1. Die Seite nennt die Zahl der aktualisierbaren Pakete, **die Zahl der
   Sicherheitsupdates getrennt** und die Zahl der zurückgehaltenen — und alle
   drei stimmen mit `apt-get -s dist-upgrade` auf der Kommandozeile überein.
2. Eine **Neuinstallation** in derselben Liste wird als solche gezeigt und nicht
   mit einer alten Fassung `[all]`.
3. Eine unerreichbare Quelle wird **als solche benannt**, mit ihrem Namen — und
   der Lauf meldet nicht Erfolg. (Das ist M5, von der anderen Seite.)
4. Ein Schlüssel, der in weniger als dreissig Tagen abläuft, wird gemeldet,
   **bevor** ein Lauf daran scheitert.
5. Ein Upgrade, das `srvpanel` selbst enthält, läuft durch; das Panel ist danach
   erreichbar, und das Protokoll des Laufs ist **vollständig** lesbar — auch der
   Teil nach dem Neustart.
6. Nach einem Lauf, der eine Conffile zurücklässt, steht diese Datei mit ihrem
   Pfad auf der Seite.
7. Die Seite zeigt, ob ein Neustart nötig ist, und unterscheidet „nein" von
   „nicht nachgesehen". Der Neustart lässt sich anstossen und verlangt die
   Eingabe des Rechnernamens.
8. Ein zweiter Lauf, während einer läuft, wird abgewiesen — mit einem Satz über
   den laufenden Vorgang und nicht mit der Meldung von dpkg.

**Nicht Gegenstand der Abnahme, aber Teil des Laufs:** ein Bildsatz bei 390 px
und 1440 px in beiden Themes, nach `tests/bilder-messen.js`. Die Paketliste ist
eine lange Tabelle mit Fassungsnummern, und Fassungsnummern sind Kennungen —
`docs/64` Befund 1 ist genau daran entstanden.

---

## 5. Die Operationen

Fünf neue, alle im Agenten, alle typisiert.

| Operation | mutierend | Was sie tut |
|---|---|---|
| `system.packages.list` | nein | `apt-get -s dist-upgrade` und `-s upgrade`, geparst; dazu Neustartbedarf und zurückgelassene Conffiles |
| `system.packages.refresh` | **ja** | `apt-get update`; liefert **je Quelle** den Ausgang, nicht einen Rückgabewert |
| `system.packages.upgrade` | **ja** | `all` · `security` · benannte Pakete; über `systemd-run` wie `panel.update` |
| `system.sources.list` | nein | `apt-get indextargets` **und** die Dateien, getrennt beschriftet; je Schlüssel Fingerabdruck und Ablauf |
| `system.sources.toggle` | **ja** | schaltet eine Quelle über `Enabled: no`; nur in Dateien, die das Panel angelegt hat, plus die drei bekannten |

**Was sich an bestehendem Code ändert:**

`AptLock` wird die eine Stelle, die „läuft gerade ein apt-Lauf?" beantwortet —
heute steht die Prüfung in `PanelUpdate` und nirgends sonst, obwohl drei weitere
Operationen apt rufen.

> **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine von beiden
> ist der Ort, an dem man nachsieht.** Der Satz stammt aus `docs/47` und gilt
> hier für eine Prüfung statt für eine Argumentliste.

Und `apt-get update` läuft künftig über **eine** Stelle, `Apt::refresh()`, die
`stderr` je Quelle liest statt eines Rückgabewerts (§2.1b). Was der Aufrufer
daraus macht, entscheidet er selbst: `PhpVersionInstall` bricht an einer toten
Sury ab, `system.packages.list` zeigt sie neben der Zahl. Die dritte Hälfte —
`PanelUpdate` liest nach dem Lauf seine eigene Fassung nach — geht erst nach dem
Neustart und hängt deshalb an Schritt 6.

**Kein Freitext erreicht apt.** Paketnamen werden gegen die zuvor gelesene Liste
geprüft — nicht gegen ein Muster. Ein Muster liesse `--reinstall` durch, sobald
jemand es als Namen schickt; eine Positivliste aus der eigenen vorigen Antwort
nicht.

---

## 6. Die Oberfläche

Eine Seite **„Pakete und Updates"** in der neuen Menügruppe **„Server"**
(`docs/80 §6.3`), in vier Bereichen:

1. **Zustand** — wann zuletzt aktualisiert, wie viele Pakete, davon Sicherheit,
   davon zurückgehalten, Neustart nötig. Mit dem Knopf „Jetzt nachsehen".
2. **Die Liste** — Paket, alte und neue Fassung, Herkunft, Marke „Sicherheit".
   Filter auf Sicherheit. Auswahl je Zeile.
3. **Paketquellen** — je Quelle: Herkunft, Suite, Komponenten, Schlüssel mit
   Ablauf, erreichbar, und ob das Panel sie verwaltet. Schalter nur bei den
   eigenen.
4. **Unbeaufsichtigte Updates** — was der **wirksame** Zustand ist (§2.1, M8),
   wann es zuletzt lief, und der Schalter.

**Wo der Neustart-Knopf steht:** nicht in einem eigenen Menüpunkt, sondern
zweimal dort, wo sein Anlass steht — an der Kernelzeile der Übersicht und am
Ende eines Laufs.

> **Ein Knopf, den man sucht, wenn man ihn braucht, steht am falschen Ort.**

**Und die Frage, die dieses Projekt dreimal bezahlt hat, gehört hierher und
nicht ans Ende:** Wo sucht ein Serveradministrator „Updates einspielen"? Meine
Antwort ist: im Menü, oberste Ebene der Gruppe „Server", **und** auf der
Übersicht als Zeile mit Zahl. Wer auf die Übersicht kommt und vierzig offene
Sicherheitsupdates hat, soll es dort sehen und nicht suchen müssen.

---

## 7. Die Fallen

Acht, gesammelt aus der Messrunde und aus dem, was dieses Repo teuer gelernt
hat.

1. **Der Rückgabewert von `apt-get update` trägt nichts** (§2.1, M5). Gelesen
   werden die `W:`-Zeilen je Quelle.
2. **Zwei apt-Läufe enden in der dpkg-Sperre.** Die Prüfung gehört an **eine**
   Stelle.
3. **Ein Upgrade kann das Panel mitnehmen.** Der Lauf geht über `systemd-run`,
   die Ausgabe in eine Datei, und die Seite muss überleben, dass die Verbindung
   abreisst.
4. **`[alt]` fehlt bei einer Neuinstallation, und `[arch]` sieht genauso aus.**
5. **Die Herkunft ist eine Liste**, und `-security` kann an zweiter Stelle
   stehen.
6. **Erfolg wird gelesen, nicht geglaubt.** Nach dem Lauf wird die Liste neu
   erhoben — dasselbe wie bei `php.version.install`.
7. **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand**
   (§2.1, M8).
8. **Ein `reboot-required`, das man anzeigt und nicht anstossen kann, ist die
   halbe Auskunft** — und ein Neustart über das Panel ist die eine Handlung, die
   das Panel selbst mitnimmt. Bestätigung durch Eingabe des Rechnernamens.

---

## 8. Die Wächter und ihre Brüche

Fünf neue. Jeder wird nach dem Bauen absichtlich gebrochen, und der Bruch kommt
in `tests/waechter-brechen.sh`.

| Wächter | Regel | Der Bruch |
|---|---|---|
| `AptResultTest` | Jeder Aufruf von `apt-get update` liest `stderr`; der Rückgabewert ist nie die einzige Auskunft | `successful()` als einzige Bedingung wieder einsetzen |
| `AptLockReachTest` | Jede apt-rufende Operation geht über `AptLock` | eine Operation daran vorbeiführen |
| `InstLineTest` | Der Leser trennt `[alt]` von `[arch]` und liest **alle** Herkünfte | die Zeile ohne `[alt]` aus dem Prüfkörper nehmen |
| `SourceOwnershipTest` | Geschrieben wird nur in Dateien, die das Panel angelegt hat | einen fremden Pfad in die Schreibliste setzen |
| `PackageNameTest` | Paketnamen kommen aus der vorigen Antwort, nicht aus einem Muster | die Prüfung durch ein `preg_match` ersetzen |

**Zwei Hinweise, beide aus `CLAUDE.md` bezahlt:**

`InstLineTest` baut seine Prüfkörper **selbst**, Zeile für Zeile — so wie
`ArchiveDepthTest` seine Archive baut. Ein Prüfkörper aus `apt-get -s` auf dieser
Maschine enthält genau die Fälle nicht, an denen der Leser bricht (§2.3).

**`AptResultTest` darf dabei nicht nach dem Wort `successful()` suchen.** Er
wäre grün, sobald irgendwo daneben eine zweite Prüfung steht — derselbe Fehler
wie der Wächter aus `docs/62` Punkt 12, der einen Satz suchte statt seiner
Erreichbarkeit. Er sucht die Aufrufe von `apt-get update` und belegt für jeden
einzeln, dass `stderr` gelesen wird.

> **Ein Wächter, der ein Wort sucht statt einer Wirkung, ist grün, sobald das
> Wort irgendwo steht.**

Er ist ausserdem der einzige der fünf, der eine **echte** Regression nachstellt
und keine erfundene: Der Zustand vor der Behebung sah genau so aus, wie sein
Bruch ihn herstellt.

Und: Diese fünf Wächter erben nur von `TestCase` und sind damit **hier fahrbar**,
im Gestell ohne PHPUnit. Wer sie baut, belegt ihre Brüche hier und nicht in der
CI.

---

## 9. Die Schritte

| # | Schritt | Fertig, wenn |
|---|---|---|
| 0 | ~~Die Messrunde auf den drei fehlenden Plattformen und die fünf fehlenden Fälle (§2.3)~~ **erledigt am 26. August 2026** | ✔ Der CI-Job `apt-messrunde` fährt sie auf allen vier Plattformen bei jedem Lauf; fünfzehn von sechzehn Fällen hergestellt, der sechzehnte auf `debian:12` nicht herstellbar und als solcher benannt |
| 1 | **Der Befund M5 behoben, Teile 1 und 2** (§2.1b) — `Apt::refresh()` und die drei lesenden Aufrufer, mit `AptResultTest` | eine unerreichbare Sury lässt `php.version.install` mit einer Meldung **über die Quelle** scheitern, nicht über das Paket |
| 2 | `AptLock` als die eine Stelle; `PanelUpdate` zieht um | `AptLockReachTest` ist grün und sein Bruch rot |
| 3 | ~~`system.packages.list` mit dem Leser und `InstLineTest`~~ **erledigt am 26. August 2026** | ✔ Über drei Läufe gegen die Kommandozeile gemessen (`dist-upgrade` ganz, mit Sperrmarkierung, gemischt mit `Remv` und Neuinstallation): alle fünf Zahlen gleich, und **jeder** Zähler mindestens einmal ungleich null |
| 4 | ~~`system.sources.list` über `indextargets` **und** die Dateien~~ **erledigt am 26. August 2026** | ✔ `ubuntu.sources:1` auf `Enabled: no` — der Eintrag steht weiter in den Dateien (`AUS`, 0 Ziele), die Ziele fallen von 16 auf 4 für diese Datei, und `:2` behält Nummer **und** Ziele. Dazu die dritte Lage: zwei eingeschaltete Quellen ohne Ziel (PPAs, 403 am Proxy)  **4b am selben Tag nachgezogen:** Fingerabdruck und Ablauf je Schlüssel — sie standen in der Beschreibung der Operation und fehlten im „fertig, wenn“. Damit ist Abnahmepunkt 4 gebaut. |
| 5 | ~~Die Seite, beide Themes, 390 px gemessen~~ **erledigt am 26. August 2026** | ✔ Vier Lagen gegen die **echte** Seite mit laufendem Agenten: `dokument=0`, Gegenprobe 200/200, `schiebt=0`. Und ein Befund, den diese Messung nicht sieht — siehe §2.3c |
| 6 | `system.packages.upgrade` über `systemd-run`; dazu **Teil 3 von M5** — `PanelUpdate` liest nach dem Neustart seine eigene Fassung nach | ein Upgrade mit `srvpanel` darin läuft durch, Protokoll vollständig — und ein Lauf, der nichts bewirkt hat, meldet das statt Erfolg |
| 7 | `system.sources.toggle` ~~und der Neustart-Knopf~~ | **Die Quelle am 26. August 2026 erledigt**: `SourceOwnershipTest` grün, sechs Brüche, alle beissend. Gemessen durch echtes apt — 16 → 5 → 16 Ziele, und der Rückweg belegt: bei kaputtem apt kommt die Datei byte-identisch zurück. **Der Neustart-Knopf ist offen.** |
| 8 | `unattended-upgrades` — Zustand aus `apt-config dump`, Schalter | der wirksame Zustand stimmt, wenn ein fremdes Paket dazwischenschreibt |
| 9 | Die Wächter brechen, voller Lauf von `tests/waechter-brechen.sh` | jeder der fünf Eingriffe beisst — einzeln **und** im Lauf |
| 10 | Der Abnahmelauf (eigenes Dokument, §4) auf `cloudsrv24` | die acht Punkte aus §4 |

**Schritt 0 und Schritt 1 kommen vor allem anderen.** Schritt 1 ist ein Befund
an bestehendem Code und wartet nicht auf ein neues Merkmal.

**Aufwand: 2–3 Wochen.**

---

## 10. Was A1 ausdrücklich **nicht** wird

- **Kein Hinzufügen beliebiger Fremdquellen** (§3 Frage 1, entschieden). Anzeigen, schalten, und
  die drei bekannten.
- **Kein Paketsuchfeld und kein Installieren beliebiger Pakete.** Was das Panel
  installiert, installiert es über ein Merkmal (PHP-Version, PostgreSQL) — nicht
  über ein Textfeld. Das wäre `apt-get install $freitext` mit zwei Zwischenschritten.
- **Kein Entfernen von Paketen.** Was das Panel nicht angelegt hat, entfernt es
  nicht.
- **Kein Distributions-Upgrade** (`do-release-upgrade`). Ein Lauf, der eine Stunde
  dauert und interaktiv fragt, gehört nicht hinter einen Knopf.
- **Keine eigene Automatik.** `unattended-upgrades` wird konfiguriert, nicht
  nachgebaut.
- **Kein Zurückrollen eines Upgrades.** apt kann es nicht ehrlich, und ein
  Knopf, der es verspricht, ist schlimmer als keiner. Was es gibt, ist die
  Historie (A5) und die Sicherung (P8).

---

## 11. Die übrigen Punkte, in der Reihenfolge aus `docs/80 §6.1`

Skizzen, kein Plan. Jede Stufe bekommt ihr eigenes Dokument, wenn sie drankommt.

### A5 — Logs an einer Stelle · 3–5 Tage · **zuerst**

**Was.** Eine Seite „Logs" mit einer Positivliste von Quellen im Agenten:
`laravel.log`, `/var/log/srvpanel/update.log`, das Journal ausgewählter Units,
**`/var/log/apt/history.log`**, `/var/log/auth.log`, und das nginx-Log, das zu
keiner Domain gehört. Je Quelle Tail, Filter, Herunterladen.

**Warum zuerst.** Kleinster Aufwand, und jede folgende Stufe wird damit
billiger — A1 erzeugt ein Protokoll, das jemand lesen können muss.

**Aus der Messrunde:** `history.log` hat 107 Blöcke der Form `Start-Date` /
`Commandline` / `Install:`/`Upgrade:` / `End-Date`, und auf einem echten Server
zusätzlich `Requested-By`. Das ist die Auskunft, **wer** ein Paket eingespielt
hat — auch an der Kommandozeile, also auch an diesem Panel vorbei.

**Fertig, wenn** eine Änderung an der Kommandozeile im Panel sichtbar ist, ohne
dass das Panel sie ausgelöst hat.

**Die Falle.** Kein Dateipfad aus dem Formular. `web.logs.tail` zeigt die Form.

---

### A2 — Dienste und Timer · 1 Woche

**Was.** Alle Units, die das Panel betreibt oder braucht — heute drei fest
verdrahtet (`OverviewController` Zeile 503) und vier Muster in
`ServiceAction::ALLOWED_UNITS`. Es fehlen `postgresql`, `ssh`, `cron` und die
vier eigenen Timer. Je Unit Zustand, seit wann, **Neustartzähler** (liest
`ServiceStatus` längst, zeigt niemand), die letzten Journalzeilen, die Aktionen.

**Die Timer eigens und mit ihrem nächsten Termin.**

> **Ein Dienst, der „active" meldet und keinen nächsten Termin hat, ist
> abgeschaltet und sieht aus wie eingeschaltet.**

Der Satz steht seit dem 19. August in `CLAUDE.md` und in `TimerRearmTest`. Der
Wächter fängt den Bau, die Anzeige fängt den Betrieb — das ist zweierlei.

**Fertig, wenn** ein Timer ohne nächsten Termin auf der Seite als kaputt
erkennbar ist, und zwar ohne dass man die Zahl deuten muss.

**Die Falle.** Die Unit-Liste an zwei Stellen (Übersicht und neue Seite) ist
dieselbe Falle wie die doppelte Argumentliste in `Db\Session`. Sie gehört einmal
in den Agenten.

---

### A10 — Diagnose des Bestands · 1 Woche

**Was.** Ein Knopf und ein Nachtlauf: `nginx -t`, `php-fpm -t`, `sshd -t`, Agent
erreichbar, **jeder Timer mit nächstem Termin**, Zertifikate gültig und für den
richtigen Namen, Quota **gemessen** und nicht aus den Mount-Optionen geraten
(`docs/41`), verwaltete Blöcke unversehrt, Systembenutzer vorhanden, verwaiste
Zeilen, Signaturschlüssel gültig (aus A1).

**Warum.** Die Mehrzahl der teuren Befunde aus `docs/45`, `docs/62` und
`docs/66` waren Zustände, die ein regelmässiger Bestandslauf gefunden hätte.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat.** Ein Bestandslauf
> ist das „jemand", das jede Nacht nachsieht.

**Fertig, wenn** ein von Hand erzeugter Schaden — ein gelöschter verwalteter
Block, ein gestoppter Timer — im nächsten Lauf benannt auftaucht, mit dem Ort.

**Die Falle.** Ein Diagnoselauf, der bei jedem Lauf etwas meldet, wird nach zwei
Wochen nicht mehr gelesen. Was er meldet, muss behebbar sein und nicht nur wahr.

---

### A9 — Zwei Rollen, Zugang · 1,5 Wochen

> **Vorgezogen am 24. August 2026 und ausgeschrieben: der Plan ist `docs/82`.**
> Diese Skizze bleibt als Begründung stehen; was ihr fehlte, ist dort
> nachgetragen — vor allem die **Kontenverwaltung**. Die Tabelle unten führt
> „Konten, Rollen, IP-Beschränkung" als Fähigkeit und setzt damit voraus, dass
> es diese Seite gibt; ausgeschrieben war sie nirgends, und Adminkonten
> entstehen bis heute ausschliesslich über `srvpanel admin`.
>
> **Eine Tabelle, die eine Fähigkeit einer Rolle zuordnet, setzt voraus, dass es
> die Fähigkeit gibt — und sagt nichts darüber, ob sie jemand gebaut hat.**

**Zuschnitt vom Betreiber, 24. August 2026.** Nicht „Admin und Nur-Lese-Admin",
sondern zwei Rollen mit verschiedenem Gegenstand:

- **Betreiber** — ist dem `root` dieses Servers nahe. Alles.
- **Administrator** — verwaltet Kunden, Abonnements, Domains, Datenbanken. Was
  zu kritisch ist, **sieht** er nicht und bedient er nicht.

Dazu IP-Beschränkung für die Panel-Anmeldung, erzwungene 2FA, und eine
Sitzungsübersicht mit „hier abmelden".

#### Was „kritisch" heisst

Drei Merkmale, und eines genügt:

1. **Es verleiht root auf Dauer.** Paketquellen, unbeaufsichtigte Updates.
2. **Es nimmt alle Kunden mit.** Dienste stoppen, Firewall, Neustart,
   Systemupdates einspielen.
3. **Es zeigt ein Geheimnis.** DNS-Zugangsdaten, SMTP-Kennwort, private
   Schlüssel des Panels.

| Bereich | Betreiber | Administrator |
|---|---|---|
| Kunden, Pläne, Abonnements, Domains | ja | **ja — das ist die Arbeit** |
| Datenbanken, Konsole, Dateien, Cron, SFTP | ja | ja |
| Vorgänge, Protokoll | ja | ja |
| Pakete und Updates | ja | sehen ja, einspielen nein |
| Paketquellen | ja | sehen ohne Schlüssel, schalten nein |
| Dienste | ja | Zustand ja, `restart`/`reload` ja, `stop`/`disable` nein |
| Firewall, Anmeldeschutz | ja | nein — auch nicht sehen |
| Neustart, Zeitzone des Servers | ja | nein |
| PHP-Versionen installieren | ja | nein (§ siehe unten) |
| Datenbank-Fernzugriff schalten | ja | nein |
| Mailversand, DNS-Zugang, Panel-Zertifikat | ja | **nein — auch nicht sehen** |
| Konten, Rollen, IP-Beschränkung | ja | nein |

#### Die drei Fallen

**Erstens: Verbergen ist nicht Schützen.** Wenn der Administrator die
DNS-Zugangsdaten nicht *sieht*, aber eine Zertifikatsbestellung auslösen darf,
die sie benutzt, ist das Geheimnis für ihn weiterhin wirksam. Geteilt wird nach
**Wirkung**, nicht nach Bildschirm.

> **Eine Seite, die man nicht sieht, ist keine Grenze, solange ein Knopf
> daneben dasselbe bewirkt.**

Deshalb steht „PHP-Versionen installieren" beim Betreiber: `php.version.install`
ruft `apt-get install`. Der Paketname kommt zwar aus zwei Positivlisten und ist
damit gebunden — aber es bleibt ein Weg, über den Pakete aus einer fremden
Quelle auf die Maschine kommen. Wer Quellen nicht schalten darf, soll nicht über
die Hintertür daraus installieren.

**Zweitens: Wer Konten anlegt, legt seine eigene Rolle an.** Ein Administrator,
der ein Konto anlegen darf, darf keinen Betreiber anlegen — sonst ist die
Trennung eine Zierde. Und er darf sich nicht selbst befördern. Beides gehört an
**dieselbe** Prüfung wie die Rolle selbst und nicht an eine zweite daneben.

**Drittens: Die Aussperrung.** Es muss immer mindestens einen Betreiber geben.
Der letzte lässt sich weder herabstufen noch löschen noch sperren, und die
Meldung dazu sagt, warum. Der Rückweg, wenn es doch passiert, ist `srvpanel
admin` auf der Kommandozeile — den gibt es (`CreateAdmin`), und er gehört in
dieser Stufe geprüft, nicht angenommen.

#### Die Naht ist vorbereitet — seit dem 24. August 2026

**Gebaut wird A9 hier; die Aufrufstellen sind schon geteilt.** Der Grund steht
oben in §11 selbst: *Wer eine Adminfunktion baut, entscheidet beim Bauen, auf
welcher Seite sie liegt.* P7b baut vier davon, bevor A9 drankommt — käme die
Teilung erst danach, müsste jede Seite ihre `can`-Ablage und ihre Bilder ein
zweites Mal bekommen, weil `AbilityReachTest` darauf besteht, dass ein Knopf,
den der Betrachter nicht drücken darf, gar nicht gezeigt wird.

Deshalb gibt es seit dem 24. August **zwei Fähigkeiten statt einer**:

| Fähigkeit | Rolle | Heute |
|---|---|---|
| `operate-server` | Betreiber | 11 Routen: PHP-Versionen, Datenbank-Fernzugriff, Mailversand, Panel-Zertifikat, DNS-Zugang |
| `manage-settings` | Administrator | 2 Routen: die Anzeigezeitzone (`docs/40`) |

**Beide lösen auf `isAdmin()` auf**, weil es nur eine Rolle gibt. Was A9 ändert,
ist **eine Zeile** — die Auflösung in `SrvPanelServiceProvider` —, und keine
einzige Aufrufstelle, kein Schlüssel in einer `can`-Ablage und kein Bild.

Die Zuordnung wohnt in `App\Support\Authorization\AdminAbility`, gebaut nach
dem Vorbild von `RouteGuard`: **Die Voreinstellung ist der Betreiber**, und eine
Einstellungsseite, die ihm nicht gehört, steht dort mit ihrer Begründung.
`AdminAbilityTest` hält beide Richtungen — kein Eintrag überlebt seine Route,
und keine Seite entkommt der Entscheidung.

> **Der Fehler fällt damit zur sicheren Seite.** Eine Seite, die versehentlich
> zu streng ist, meldet sich beim Administrator; eine, die versehentlich zu
> offen ist, meldet sich nie.

Was A9 dann noch zu tun hat: die Rolle am Konto, die Auflösung der beiden Gates,
die drei Fallen unten — und die Fähigkeiten, die P7b bis dahin dazugelegt hat,
in derselben Registratur.

#### Was das am Datenmodell ändert — und was ausdrücklich nicht

**`AccountType` bekommt keinen vierten Fall.** Das ist die wichtigste Zeile
dieses Abschnitts, und sie ist am Quelltext nachgesehen:

```php
public function isAdmin(): bool          { return $this === self::Admin; }
public function belongsToCustomer(): bool { return $this !== self::Admin; }
```

Beide sind als **Gleichheit mit einem Fall** geschrieben, nicht als
Zugehörigkeit zu einer Menge. Ein vierter Fall `Superadmin` wäre damit
augenblicklich `isAdmin() === false` und `belongsToCustomer() === true` — an
**52 Stellen** in `app/` und `routes/`, die `isAdmin()` rufen. Die
Mandantenklammer würde ihn auf `whereRaw('0 = 1')` setzen, weil er keinen
Kunden hat.

Es fiele also zur sicheren Seite — und das ist kein Trost:

> **Ein Fehler, der zur sicheren Seite fällt, fällt trotzdem, und er fällt
> leise.** Der neue Betreiber sähe eine leere Kundenliste, und niemand käme auf
> die Idee, dass ein Enum-Fall daran schuld ist.

**Der Grund dahinter ist, dass zwei Fragen in einem Feld stecken.**
`AccountType` beantwortet „wen sieht dieses Konto" — die Mandantenfrage. Für
Betreiber und Administrator lautet die Antwort **gleich**: den ganzen Server.
Verschieden ist nur, was sie tun dürfen. Das sind zwei Achsen, und wer sie in
ein Feld legt, macht `isAdmin()` an 52 Stellen zweideutig.

Deshalb: **`AccountType::Admin` bleibt für beide**, und die Rolle kommt als
eigene Angabe am Konto dazu. `isAdmin()` behält an allen 52 Stellen seine
heutige Bedeutung — „kein Kunde" —, und die neue Unterscheidung taucht
ausschliesslich dort auf, wo eine Fähigkeit sie braucht.

**Und der Kommentar an `AccountType` wird falsch.** Dort steht heute: *„Bewusst
kein Rollen- und Rechte-Baukasten: Drei feste Ebenen decken den Bedarf eines
Hosting-Panels ab."* Der Satz stimmt weiterhin für die Mandantenebene und nicht
mehr als Ganzes. Er gehört im selben Schritt umgeschrieben, in dem die Rolle
entsteht.

> **Ein Kommentar, der eine Entscheidung begründet, wird zur Falschaussage, wenn
> die Entscheidung sich ändert — und er wird nicht mitgeändert, weil er im
> Diff nicht auffällt.**

#### Wo die Rolle durchgesetzt wird

`Gate::define('manage-settings', fn ($account) => $account->isAdmin())` — **eine
Zeile, fünfzehnmal in `routes/web.php` benutzt**, und sie deckt heute alle sechs
Einstellungsseiten ab, die Geheimnisse tragen. Das ist die Naht: Aus dem einen
Gate werden mehrere, entlang der Tabelle oben.

**Und die Fläche folgt derselben Prüfung, nicht einer zweiten.** Ein Menüpunkt,
den der Administrator nicht bedienen darf, wird nicht gezeigt — und die Antwort
darauf kommt als `can`-Ablage aus derselben Policy, nicht als `v-if` auf die
Rolle. `AbilityReachTest` prüft beide Richtungen; er bekommt die neue Rolle als
zweiten Durchgang.

**Für „auch nicht sehen" gibt es das Vorbild schon.** `OverviewController`
verzweigt **serverseitig** und erhebt die Serverwerte für einen Kunden gar nicht
erst — mit der Begründung, dass ein `v-if` die Daten trotzdem an den Browser
schickt und wer die Antwort ansieht, sie liest. Dasselbe gilt hier: Was der
Administrator nicht sehen darf, wird nicht ausgeblendet, sondern **nicht
geschickt**.

**Fertig, wenn** ein Administrator jede Seite seiner Tabelle bedienen kann, auf
den übrigen einen 403 bekommt **und in der Inertia-Antwort dieser Seiten kein
Feld steht, das er nicht sehen darf** — gemessen an der Antwort, nicht am Bild.
Und wenn der letzte Betreiber sich nicht herabstufen lässt.

### A7 — Schwellen und Benachrichtigungen · 1,5 Wochen

**Was.** Schwellen je Kennzahl, zwei Kanäle (Mail, Webhook): Platte, RAM, Load,
Dienst tot, **Timer ohne nächsten Termin**, Zertifikat läuft ab, Sicherung
fehlgeschlagen, **Sicherheitsupdates offen** (aus A1), Signaturschlüssel läuft ab.

**Warum.** Die Kennzahlen sind da, der Mailversand ist eingerichtet und testbar
— und niemand wird geweckt.

**Fertig, wenn** eine volllaufende Platte **eine** Meldung erzeugt und nicht
vierhundert, und wenn ein ausgefallener Mailversand als solcher sichtbar ist.

**Die Fallen.** Zwei, und die zweite ist die wichtigere:

1. Entprellung. Eine Kennzahl, die um die Schwelle pendelt, sind vierhundert Mails.
2. **Eine Meldung über einen Ausfall, die über den ausgefallenen Weg geht, kommt
   nicht an.** Der Mailversand läuft über diesen Server. Deshalb der Webhook als
   zweiter Kanal — und eine Anzeige „zuletzt erfolgreich zugestellt", weil ein
   Kanal, der schweigt, von einem, der nichts zu melden hat, sonst nicht zu
   unterscheiden ist.

---

### A3 — Firewall (nftables) · 2 Wochen, davon 4 Tage für den ersten Wurf

**Zwei Würfe, und der erste schreibt nicht.** Wurf 1: welche Ports lauschen
(`ss -ltnp`), welches Regelwerk läuft, und ob die Ports, die das Panel geöffnet
hat, von aussen erreichbar sind. Wurf 2: eine eigene nftables-Tabelle, die den
Bestand nicht anfasst.

**Vorher zu messen.** Welche vier Möglichkeiten es gibt (`nft`, `iptables-nft`,
`ufw`, `firewalld`), wie man sie unterscheidet, und ob überhaupt eine läuft. Auf
einem gemieteten Server steht oft eine Cloud-Firewall davor, die das Panel nicht
sieht — eine Anzeige „Port offen", die das verschweigt, ist falsch.

**Die Falle.** Jede Änderung braucht eine **Rücknahme nach Zeit**: Der neue
Regelsatz gilt, und wenn niemand innerhalb von zwei Minuten bestätigt, stellt
eine transiente Unit den alten wieder her.

> **Ein Rückweg, der voraussetzt, dass man noch drankommt, ist keiner für den
> Fall, dass genau dieser Vorgang einen aussperrt.**

**Fertig, wenn** eine Regel, die den eigenen Zugang schliesst, sich nach zwei
Minuten von selbst zurücknimmt — belegt, nicht behauptet.

---

### A4 — Anmeldeschutz (fail2ban) · 1 Woche

**Was.** Jails lesen, Sperren zählen, entsperren, eine eigene Jail für das
Panel-Log.

**Warum.** Ein SFTP-Zugang, den das Panel anlegt, ist ein Ziel, das das Panel
geschaffen hat. Der Schutz dafür ist nicht Sache des Kunden.

**Die Falle.** Ein Entsperren-Knopf ohne Prüfung ist „beliebige Zeichenkette an
fail2ban". Die Adresse gehört validiert, der Jailname kommt aus der gelesenen
Liste.

---

### Der Kleinkram · je 2–3 Tage

| | Was | Wo |
|---|---|---|
| **A11** | Neustart, Zeitzone des Servers und NTP **neben** der Anzeigezeitzone aus `docs/40`, Rechnername nur anzeigen | mit A1, Schritt 7 |
| **A6** | Leseansicht von `/etc/crontab`, `/etc/cron.d`, `cron.daily` und `cron.weekly` | mit A2 |
| **A8** | Welche Adressen der Server hat, welche der DNS-Abgleich als Soll nimmt | eigenständig; P7 ist fertig |
| **A12** | Wartungsmodus: alle Kundenseiten auf 503, Panel erreichbar | mit A1 |
| **A13** | Die billige Hälfte des Malware-Scans: 0777, frisch geänderte PHP-Dateien, `eval(base64_decode` als Textsuche | mit A10 |

---

## 12. Einordnung in den Plan

`docs/20 §9` führt P9 mit **3–4 Wochen** und trägt darin: Statistik je Abo und
Domain, Logauswertung, Ressourcenüberwachung mit Benachrichtigungen,
Kundenbenachrichtigungen, Branding, Serververwaltung mit Diensten, Paketen,
Systemupdates, Firewall, Fail2ban und Logs, API v1 mit OpenAPI, Dokumentation.

Allein der Serververwaltungsteil ist nach dieser Aufstellung **acht bis zehn
Wochen**.

**Vorschlag zur Teilung** — eine Änderung an `docs/20`, und die trifft der
Betreiber, nicht dieses Dokument. _Überholt: §12.1 darunter trägt, was
entschieden wurde. Die Tabelle bleibt als Zuschnitt richtig — welche Vorschläge
zusammengehören und was sie kosten —, nur ihre Namen nicht._

| Stufe | Inhalt | Dauer |
|---|---|---|
| **P9a** Serververwaltung | A5, A2, A10, A1, A11, A6 | ~6 Wochen |
| **P9b** Kundenfähigkeit | Statistik, Kundenbenachrichtigungen, Branding, API, Dokumentation | ~4 Wochen |
| **P9c** Absicherung | A3, A4, A7, A9 | ~5,5 Wochen |

**Und eine kleinere Empfehlung: A5, A2 und A10 vor P8 vorziehen.** Zusammen
zweieinhalb Wochen, und sie machen jede Stufe danach billiger — ein
Diagnoselauf, der jede Nacht den Bestand prüft, hätte in `docs/45`, `docs/62`
und `docs/66` Befunde gefunden, bevor ein Abnahmelauf sie gefunden hat.

### 12.1 Reihenfolge und Name sind entschieden — der Rest nicht

**Nachgetragen am 24. August 2026, nach dem Merge von P7.** `docs/79` heisst
„Übergabe: die Adminfunktionen **vor P8**" — der Betreiber hat die Reihenfolge
damit entschieden, und weiter als die Empfehlung oben reicht: nicht nur A5, A2
und A10, sondern die Adminfunktionen insgesamt kommen vor die Sicherungen.

**Damit ist der Name „P9a" falsch geworden.** Er stammt aus der Annahme, diese
Arbeit hänge hinten an P9; sie hängt jetzt zwischen P7 und P8. Die Tabelle
darüber bleibt als **Zuschnitt** richtig — welche Vorschläge zusammengehören
und was sie kosten —, nur ihre Namen nicht.

**Der Betreiber hat am 24. August 2026 entschieden: die Stufe heisst P7b.**
`docs/20 §9` trägt sie seitdem zwischen P7 und P8, und der
Serververwaltungssatz in P9 zeigt dorthin statt ihn ein zweites Mal zu führen.

| Stufe | Inhalt | Wann |
|---|---|---|
| **P7b — Serververwaltung** | A5, A2, A10, A1, A11, A6 | **entschieden**, vor P8 |
| **P8** | Sicherungen und Wiederherstellung | unverändert |
| **P9** | Kundenfähigkeit nach `docs/20 §9`, **ohne** den Serververwaltungssatz | unverändert |
| _(ohne Stufe)_ | A3, A4, A7 — Firewall, Fail2ban, Schwellen | **offen**, Vorschlag: eigene Stufe nach P9 |

**Drei der vier absichernden Vorschläge haben weiterhin keinen Ort.** Sie
stehen in `docs/20 §9` unter P7b als „hat noch keine Stufe" — benannt und ohne
Stufe, statt stillschweigend irgendwohin geschoben.

**A9 ist am 24. August daraus herausgelöst und in P7b vorgezogen worden**, an
die zweite Stelle nach A5. Der Grund ist der Satz aus §11 selbst: Wer eine
Adminfunktion baut, entscheidet beim Bauen, auf welcher Seite sie liegt — jede
Woche später sind das mehr Funktionen, die die Entscheidung nachtragen müssten.
Der ausgeschriebene Plan ist `docs/82`.

> **Ein Name, der eine Reihenfolge behauptet, wird falsch, wenn die Reihenfolge
> sich ändert — und er wird trotzdem weiterbenutzt, weil er in Überschriften
> steht.** Deshalb steht das hier und nicht als stille Umbenennung.

---

## 13. Was offen bleibt und benannt ist

- ~~**Die drei Plattformen aus §2.3** und die **vier** verbliebenen Fälle ohne
  Nachbarn~~ — **erledigt am 26. August 2026.** Beides fährt die CI, und §2.3
  trägt die Antworten auf die drei Erwartungen, die dort standen. Eine davon war
  falsch.
- **Die vier Fragen aus §3 sind entschieden** (24. August, Tabelle dort) und
  stehen damit nicht mehr offen. Was sie hinterlassen, ist eine Nachlese, kein
  Rest: Frage 1 hat einen Einwand, der bewusst überstimmt wurde — der Admin
  hat SSH und kann die Quelle von Hand eintragen. Er trägt, solange es **einen**
  Kontotyp gibt, und fällt mit A9, weil der Administrator kein SSH hat. Wer die
  Entscheidung später aufmacht, fängt bei diesem Satz an.
- **Der einzige Punkt, der A1 zum Scheitern bringen kann** (§2.3, letzter
  Absatz): dass `systemd-run` den Neustart von `srvpanel-web` überlebt, ist seit
  P1 behauptet und nur durch den eigenen Gebrauch belegt. Er gehört in Schritt 6
  gemessen und nicht in Schritt 10 erlebt.
