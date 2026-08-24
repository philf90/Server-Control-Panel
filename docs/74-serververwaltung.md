# 74 — P9a: Serververwaltung für den Admin

Geplant am 22. August 2026. Der Auftrag steht in `docs/73`: Der Betreiber hat
die Admin-Ansicht gegen Plesk und cPanel gehalten und gefragt, was noch
hineingehört. Sechzehn Vorschläge sind daraus geworden; **A1 — Paketquellen und
Systemupdates über apt** war seine eigene Idee und ist der grösste.

**Dieses Dokument plant A1 vollständig und die übrigen als Skizze**, in der
Reihenfolge aus `docs/73 §6.1`. Die Abarbeitung erfolgt nach Abschluss von P7
in einer eigenen Sitzung.

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
22. August 2026 im Container gegen **Ubuntu 24.04 (noble), apt 2.8.3,
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

**Nachgetragen am 22. August 2026, auf die Rückfrage des Betreibers.** Die
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

Was `indextargets` **nicht** kann: sagen, aus welcher Datei ein Ziel stammt, und
eine abgeschaltete Quelle zeigen (sie ist dort schlicht fort). Deshalb zwei
Quellen nebeneinander und getrennt beschriftet: **was apt benutzt**
(`indextargets`) und **was konfiguriert ist** (die Dateien).

> **Zwei Fragen, die verschieden lauten, brauchen zwei Antworten — auch wenn sie
> meistens dasselbe sagen.**

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

**Auf den drei anderen Zielplattformen ungemessen:** Debian 12, Debian 13,
Ubuntu 22.04. Zu holen mit demselben Skript, ein Lauf je Plattform. Erwartet
werden Unterschiede bei: der Voreinstellung `.list` gegen `.sources` (Debian 12
liefert noch `/etc/apt/sources.list` mit Inhalt), dem Namen der Sicherheitssuite
(`bookworm-security` gegen `noble-security`), und `--error-on=any` auf apt 2.4
(Ubuntu 22.04).

**Fälle, die im Container nicht vorkamen und eigens erzeugt werden müssen:**

| Fall | Warum er gebraucht wird |
|---|---|
| eine Neuinstallation in `dist-upgrade` (`Inst` ohne `[alt]`) | die Falle aus M3, Punkt 1 |
| ein zurückgehaltenes Paket (M4) | die Zahl, die in die Anzeige gehört |
| ein Schlüssel **mit** Ablaufdatum (M6) | sonst misst die Ablaufprüfung nichts |
| eine `.dpkg-dist` nach `--force-confold` (M9) | die Anzeige hängt daran |
| `Requested-By` in der Historie (M11) | die Auskunft, für die A5 sie führt |

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Fünf der elf Messungen stehen heute auf einer Null ohne Nachbarn.

**Nur auf einem echten Server messbar:** wie lange ein voller `dist-upgrade`
läuft (die Zahl entscheidet über die Zeitgrenze der Operation), was passiert,
wenn das Upgrade `srvpanel` selbst enthält, und ob `systemd-run` den Lauf
tatsächlich überlebt, wenn `srvpanel-web` dabei neu startet. Der letzte Punkt
ist der einzige, der A1 zum Scheitern bringen kann — `panel.update` behauptet
ihn seit P1 und **belegt hat ihn nur der eigene Gebrauch**.

---

## 3. Was der Betreiber vor dem Bauen entscheiden muss

Vier Fragen. Sie sind hier ausformuliert, weil sie den Zuschnitt ändern und
nicht die Umsetzung.

**Frage 1 — Darf das Panel eine fremde Paketquelle hinzufügen?**

Eine Quelle hinzuzufügen heisst, root über apt weiterzugeben: Wer die Quelle
kontrolliert, kontrolliert jedes Paket, das von dort kommt. Mein Vorschlag ist
**nein für den ersten Wurf** — anzeigen, ein- und ausschalten, und an
hinzufügbaren Quellen nur die drei, die das Panel ohnehin kennt (Sury, PGDG, das
eigene Repo). Freies Hinzufügen ist eine eigene Entscheidung und kein
Nebeneffekt.

**Frage 2 — Wer darf einspielen?**

Nur der Admin, oder auch ein Nur-Lese-Admin (A9)? Mein Vorschlag: **Anzeigen für
jeden Admin, Einspielen nur für den vollen.** Die Trennung fällt sonst später
teurer.

**Frage 3 — Conffiles: `confold` oder fragen?**

`--force-confold` hält den Bestand und lässt `.dpkg-dist` liegen. Die Alternative
wäre, den Lauf abzubrechen, wenn ein Paket eine Conffile-Frage stellt. Mein
Vorschlag: **`confold` und die zurückgelassenen Dateien anzeigen** — ein Abbruch
mitten im Lauf hinterlässt eine halb aktualisierte Maschine, und das ist der
schlechtere von zwei Zuständen.

**Frage 4 — Wie weit darf die Automatik gehen?**

Von „gar nicht" über „Sicherheitsupdates unbeaufsichtigt" bis „alles". Mein
Vorschlag: **`unattended-upgrades` für Sicherheitsupdates einschalten dürfen,
alles andere von Hand.** Das Panel betreibt die Automatik nicht selbst, es
konfiguriert die der Distribution — Leitbild 1.

---

## 4. Das Abnahmekriterium von A1

Acht Punkte, gemessen auf einem echten Server. Der Lauf dazu wird eigens
geschrieben (`docs/76`), nach dem Muster von `docs/58` und `docs/65`.

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
(`docs/73 §6.3`), in vier Bereichen:

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
| 0 | Die Messrunde auf den drei fehlenden Plattformen und die fünf fehlenden Fälle (§2.3) | `tests/apt-messen.sh` ist viermal gelaufen, und die fünf Fälle haben einen Wert neben ihrer Null |
| 1 | **Der Befund M5 behoben, Teile 1 und 2** (§2.1b) — `Apt::refresh()` und die drei lesenden Aufrufer, mit `AptResultTest` | eine unerreichbare Sury lässt `php.version.install` mit einer Meldung **über die Quelle** scheitern, nicht über das Paket |
| 2 | `AptLock` als die eine Stelle; `PanelUpdate` zieht um | `AptLockReachTest` ist grün und sein Bruch rot |
| 3 | `system.packages.list` mit dem Leser und `InstLineTest` | die Zahlen stimmen mit der Kommandozeile überein |
| 4 | `system.sources.list` über `indextargets` **und** die Dateien | eine abgeschaltete Quelle erscheint bei den Dateien und nicht bei den Zielen |
| 5 | Die Seite, beide Themes, 390 px gemessen | `tests/bilder-messen.js` meldet 0 px, mit ausschlagender Gegenprobe |
| 6 | `system.packages.upgrade` über `systemd-run`; dazu **Teil 3 von M5** — `PanelUpdate` liest nach dem Neustart seine eigene Fassung nach | ein Upgrade mit `srvpanel` darin läuft durch, Protokoll vollständig — und ein Lauf, der nichts bewirkt hat, meldet das statt Erfolg |
| 7 | `system.sources.toggle` und der Neustart-Knopf | `SourceOwnershipTest` ist grün und sein Bruch rot |
| 8 | `unattended-upgrades` — Zustand aus `apt-config dump`, Schalter | der wirksame Zustand stimmt, wenn ein fremdes Paket dazwischenschreibt |
| 9 | Die Wächter brechen, voller Lauf von `tests/waechter-brechen.sh` | jeder der fünf Eingriffe beisst — einzeln **und** im Lauf |
| 10 | Der Abnahmelauf (`docs/76`) auf `cloudsrv24` | die acht Punkte aus §4 |

**Schritt 0 und Schritt 1 kommen vor allem anderen.** Schritt 1 ist ein Befund
an bestehendem Code und wartet nicht auf ein neues Merkmal.

**Aufwand: 2–3 Wochen.**

---

## 10. Was A1 ausdrücklich **nicht** wird

- **Kein Hinzufügen beliebiger Fremdquellen** (Frage 1). Anzeigen, schalten, und
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

## 11. Die übrigen Punkte, in der Reihenfolge aus `docs/73 §6.1`

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

### A9 — Admins, Rollen, Zugang · 1 Woche

**Was.** Ein zweiter Admin (Vertretung), ein Admin mit Nur-Lese-Recht (Support),
IP-Beschränkung für die Panel-Anmeldung, erzwungene 2FA für Admins, und eine
Sitzungsübersicht mit „hier abmelden".

**Warum.** Heute gibt es genau **einen** Admin. Fällt er aus oder verliert er
sein zweites Merkmal, ist niemand mehr da.

**Wie es passt.** Das Rechtemodell trägt es: Autorisierung sitzt an der Aktion,
jede Route trägt `can:`, `AbilityReachTest` prüft beide Richtungen. Es fehlt die
Fläche.

**Fertig, wenn** ein Nur-Lese-Admin jede Seite sieht und **keinen** Knopf — und
`AbilityReachTest` das für alle Routen belegt, nicht für die geprüften.

**Die Falle.** Eine IP-Beschränkung ist das zweite Merkmal, mit dem man sich
aussperrt (nach der Firewall). Die eigene Adresse gehört vorbelegt und der
Zustand vor dem Speichern angezeigt.

---

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
| **A8** | Welche Adressen der Server hat, welche der DNS-Abgleich als Soll nimmt | mit P7 |
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
Betreiber, nicht dieses Dokument:

| Stufe | Inhalt | Dauer |
|---|---|---|
| **P9a** Serververwaltung | A5, A2, A10, A1, A11, A6 | ~6 Wochen |
| **P9b** Kundenfähigkeit | Statistik, Kundenbenachrichtigungen, Branding, API, Dokumentation | ~4 Wochen |
| **P9c** Absicherung | A3, A4, A7, A9 | ~5 Wochen |

**Und eine kleinere Empfehlung: A5, A2 und A10 vor P8 vorziehen.** Zusammen
zweieinhalb Wochen, und sie machen jede Stufe danach billiger — ein
Diagnoselauf, der jede Nacht den Bestand prüft, hätte in `docs/45`, `docs/62`
und `docs/66` Befunde gefunden, bevor ein Abnahmelauf sie gefunden hat.

---

## 13. Was offen bleibt und benannt ist

- **Die drei Plattformen aus §2.3** und die fünf Fälle ohne Nachbarn. Wer A1
  anfängt, fängt dort an und nicht bei null.
- **Die vier Fragen aus §3.** Ohne sie ist der Zuschnitt geraten.
- **Der einzige Punkt, der A1 zum Scheitern bringen kann** (§2.3, letzter
  Absatz): dass `systemd-run` den Neustart von `srvpanel-web` überlebt, ist seit
  P1 behauptet und nur durch den eigenen Gebrauch belegt. Er gehört in Schritt 6
  gemessen und nicht in Schritt 10 erlebt.
