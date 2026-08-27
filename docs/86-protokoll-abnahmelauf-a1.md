# Protokoll — Abnahmelauf A1 auf `cloudsrv24`

Der Lauf ist `docs/85`, die Vorschrift dazu `docs/81 §4`. Gefahren am
26. August 2026 gegen **`0.7.2-rc.1`**.

**Dieses Protokoll entsteht während des Laufs.** Solange keine Messung darin
steht, ist ein Protokoll eine Gliederung.

**Der Lauf ist in zwei Sitzungen geteilt, und der Grund steht in Punkt 0b.**

---

## 1. Die Fassung, gegen die gemessen wird

    srvpanel version                          0.7.2-rc.1
    dpkg-query -W -f='${Version}' srvpanel    0.7.2~rc.1
    systemctl is-active agentd worker web     active · active · active

Die Tilde ist Debians Konvention für eine Vorabfassung — `0.7.2~rc.1` sortiert
**vor** `0.7.2`. nfpm setzt sie selbst; so steht es seit `0.2.0~rc.4`.

Der dritte Griff ist keine Formsache: Ohne laufenden Agenten stammt jede Zahl
dieses Laufs aus einer Fehlermeldung statt aus einer Messung.

---

## 2. Punkt 0b hat den Lauf geteilt

    apt-get -s dist-upgrade | grep -c '^Inst '     0

**Der Server hat nichts Aktualisierbares.** Nach `docs/85` Punkt 0b wird der
Lauf damit abgebrochen, und der Grund ist gut: Die Punkte 1, 2, 5 und 8b würden
0 gegen 0 vergleichen und dabei aussehen wie ein kaputtes Panel.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Entschieden wurde eine Teilung statt einer Verschiebung.** Acht Punkte hängen
nicht an aktualisierbaren Paketen und sind vollwertig messbar: 3, 4, 6, 7, 8a,
9, 10, 12. Die übrigen warten auf `0.7.2-rc.2` — und **dann ist Punkt 5 die
bessere Messung**, nicht die schlechtere: Ein Upgrade, das `srvpanel` selbst
enthält, ist genau der Fall des Kriteriums; ein beliebiger `dist-upgrade` über
fremde Pakete wäre der schwächere Prüfkörper gewesen.

**Der Lauf ist nach der ersten Sitzung nicht abgeschlossen.** A1 ist erst
abgenommen, wenn Punkt 5 gemessen ist.

---

## 3. Ein Prüfkörper, den die Vorschrift nicht hatte

`apt-get update` liefert auf diesem Server dauerhaft eine `W:`-Zeile:

    W: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease:
       Signature by key 14AA…6C uses weak algorithm (rsa1024)

**Die Quelle ist gesund, und die Zeile ist trotzdem eine `W:`-Zeile.**
`Apt::readFailures()` verlangt `^[WE]: Failed to fetch ` und zählt sie deshalb
zu Recht nicht mit. Damit steht neben dem Prüfkörper von Punkt 3 ein zweiter,
der **nicht** anschlagen darf — die Gegenprobe, die beim Ausschreiben fehlte.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Erwartet ist deshalb bei Punkt 3: **genau eine** gemeldete Quelle, nicht zwei.

---

## 4. Die Punkte

### Punkt 3 — Eine tote Quelle wird benannt (Kriterium 3, M5) · **teilweise erfüllt**

**Der Prüfkörper.** `php-sury.sources` ist `PhpVersions::SOURCE_FILE`, eine der
beiden Dateien, die das Panel besitzt — der Eingriff trifft damit den
Mechanismus und nicht die Nachbarschaft. Umgebogen wurde die Adresse, die Datei
blieb liegen: Eine *umbenannte* Datei wäre eine **fehlende** Quelle, und dazu
meldet apt gar nichts.

    vorher    URIs: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/
    nachher   URIs: https://gibtesnicht.invalid/php/

**M5, gemessen** (unter `LC_ALL=C`, siehe unten):

    rc                                          0
    W: Failed to fetch … gibtesnicht.invalid    1
    W: Some index files failed to download      1

**`rc=0` bei toter Quelle — M5 ist damit auf einem echten Server belegt.** Und
die beiden `W:`-Zeilen sind verschieden: Die Zusammenfassung ist keine Quelle,
und wer sie mitzählte, meldete bei einer toten Quelle zwei.

**Die Seite benennt die Quelle** (Vorgang 690):

    nicht erreicht: https://gibtesnicht.invalid/php/ (Could not resolve 'gibtesnicht.invalid')

---

**Befund 1 — der Vorgang meldet `fertig`.**

Kriterium 3 verlangt *„…und der Lauf meldet nicht Erfolg."* Der Vorgang trägt
ein grünes `fertig`, und die Meldung über die nicht erreichte Quelle steht in
einem **grünen** Kasten daneben.

**In der Vorgangsliste steht nur das Abzeichen.** Dort ist der Teilausfall
unsichtbar — ein Betreiber, der die Liste überfliegt, liest `fertig` und geht
weiter.

> **Ein Erfolgssignal, das einen Teilfehlschlag nicht tragen kann, ist dasselbe
> wie ein Rückgabewert, der es nicht kann.** M5 eine Ebene höher.

**Der Fix ist nicht, den Zustand auf `failed` zu drehen**, und das ist der Kern:
Sechs von sieben Quellen *wurden* aufgefrischt, die Listen sind frischer als
vorher. `failed` behauptete, es sei nichts geschehen, und lüde zu einem
Wiederholen ein, das nichts besser macht. `OperationStatus` kennt vier Zustände
— `queued`, `running`, `succeeded`, `failed` — und keinen für „mit
Einschränkung". Der Zuschnitt der Behebung gehört deshalb entschieden und nicht
nebenbei gewählt.

---

**Befund 2 — die Messvorschrift hat die falsche Umgebung gemessen.**

Der erste Lauf zählte **0** Ausfälle, und das sah aus wie ein Befund am Panel.
apt schrieb aber deutsch — *„W: Fehlschlag beim Holen von …"* —, und
`Apt::readFailures()` sucht `^[WE]: Failed to fetch `.

**Am Panel ist nichts.** `Runner::ENVIRONMENT` setzt `LC_ALL=C` und `LANG=C`,
und `proc_open()` bekommt das Array als **fünftes** Argument — die Umgebung wird
damit ersetzt und nicht ergänzt. Der Agent liest englische Zeilen.

> **Eine Messung, die unter einer anderen Umgebung läuft als der Prüfling, misst
> eine andere Anwendung.**

`docs/85` gehört an dieser Stelle berichtigt: Die Messung zu Punkt 3 läuft unter
`LC_ALL=C`, sonst prüft sie die Locale des Betreibers.

---

**Beobachtung 1 — eine Gegenprobe, die es nicht gab.**

`docs/86 §3` hatte die Schlüsselwarnung der Sury-PPA als zweite `W:`-Zeile
vorgesehen, die **nicht** anschlagen darf. Sie war im Lauf verschwunden: Sie
gehörte **derselben** Quelle, die der Prüfkörper tötet — apt prüft die Signatur
nur, wenn es die Datei auch holt.

> **Ein Prüfkörper und seine Gegenprobe an derselben Quelle sind nicht zwei
> Messungen.**

**Befund 3 — die Messvorschrift zählte Leichen mit.**

Der Griff, mit dem Punkt 3d seinen Prüfkörper suchen sollte, lautete
`dpkg-query -W -f='${Package}\n' 'php*-fpm'` und meldete **fünf** Pakete:
8.1, 8.2, 8.3, 8.4 und `php-fpm`. Installiert sind zwei.

`dpkg-query -W` listet, was dpkg **kennt**, nicht was installiert ist. Ein
entferntes, nicht gepurgtes Paket behält seinen Eintrag mit
`deinstall ok config-files`.

**Am Panel ist nichts** — im Gegenteil: `PhpVersions::DPKG_ARGUMENTS` fragt
`${binary:Package} ${db:Status-Status}` und zählt ausschliesslich `installed`.
Der Kopf der Methode benennt genau diese Falle, und die Locale-Frage ist dort
am 9. August 2026 auf demselben Server gemessen worden: Die *Meldung* von
`dpkg-query` ist übersetzt, das Feld `${db:Status-Status}` nicht.

> **Eine Anweisung, die weniger fragt als der Code, den sie prüft, misst gröber
> als der Prüfling.**

Gemeldet hat es der Betreiber, nicht die Messung — er hat die fünf Zeilen gegen
die Seite gehalten, die zwei zeigt.

### Punkt 3d — die Ursache statt des Zustands · **erfüllt**

Der Weg, an dem der Befund entdeckt wurde: eine PHP-Fassung über die Seite
installieren lassen, während die eigene Quelle tot ist. Vorgang **691**:

    Zustand      fehlgeschlagen
    Fortschritt  10 %
    Argumente    {"php_version": "8.1"}

    Die PHP-Paketquelle https://gibtesnicht.invalid/php/ ist nicht erreichbar:
    Could not resolve 'gibtesnicht.invalid'. Ohne sie kennt apt nur die alten
    Paketlisten — PHP 8.1 käme veraltet oder gar nicht. Die Installation wurde
    deshalb nicht begonnen.

**Vier Erwartungen, alle vier getroffen:** Der Vorgang scheitert, die Quelle
steht mit Namen und Grund da, der Satz sagt dass nicht begonnen wurde — und
*„Unable to locate package php8.1-fpm"* kommt nicht vor. Das ist die Meldung,
die ein Betreiber vor A1 bekommen hätte.

**Und `Fortschritt 10 %` belegt es unabhängig vom Satz.**
`PhpVersionInstall` fragt `hitting(PhpVersions::sourceUris())` bei 10 und
schaltet erst bei 30 auf „Pakete installieren". Die Zahl sagt damit dasselbe wie
der Satz, nur ohne ihm zu glauben.

> **Ein Beleg, der neben dem Satz steht, ist mehr wert als der Satz.**

**Der Kontrast zu Befund 1 ist der eigentliche Wert dieses Punktes.** Hier steht
der Vorgang auf `fehlgeschlagen`, weil er wirklich abgebrochen hat. Der
Zustandsautomat kann einen **ganzen** Fehlschlag tragen — nur den **halben**
nicht.

**Kriterium 3 ist damit in der Sache erfüllt**, mit Befund 1 als benannter
Ausnahme für den Auffrischlauf.

---

**Beobachtung 2 — die alten Listen sind verwaist, nicht fort.**

Der Prüfkörper biegt die `URIs:` um. Danach meldet `apt-cache policy` für
`php8.1-fpm` und `php8.2-fpm` `Candidate: (none)`, mit der einzigen
Versionstabellenzeile `100 /var/lib/dpkg/status` — dem Karteileichen-Eintrag.

**Die Listen liegen trotzdem noch da:**

    /var/lib/apt/lists/ppa.launchpadcontent.net_ondrej_php_ubuntu_dists_noble_InRelease
    …_main_binary-amd64_Packages
    …_main_i18n_Translation-en

apt indiziert über die **URI** der Quelle und nicht über den Dateinamen. Eine
geänderte `URIs:` macht damit aus der Quelle eine andere — die alten Listen
bleiben liegen und gehören zu niemandem mehr.

Das ist beim Ausschreiben zunächst als „der Index ist fort" notiert worden und
war zu schnell. Für Punkt 3 ändert es nichts: Der Prüfkörper unterscheidet
weiterhin, weil das Panel die Quelle prüft, **bevor** es apt fragt.

> **Ein Zustand, den man aus der Wirkung erschliesst, ist eine Vermutung, bis
> jemand die Ursache ansieht.**

### Punkt 3e — der Rückweg · **erfüllt**, und er liefert die fehlende Gegenprobe nach

    URIs:                    wieder ppa.launchpadcontent.net/ondrej/php/ubuntu/
    rc                       0
    Ausfälle                 1 → 0
    Candidate php8.1-fpm     (none) → 8.1.34-8+ubuntu24.04.1+deb.sury.org+1
    Verzeichnis              .abnahme fort

**Das Zahlenpaar macht es zur Messung.** Die 0 allein hiesse nichts; sie war
eben 1.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

**Und die Seite nimmt ihn zurück** (Vorgang 692): *„alle Quellen erreicht"*.

**Damit ist Beobachtung 1 doch noch belegt, einen Punkt später als geplant.**
Vorgang 692 führt in seiner Ausgabe eine `W:`-Zeile — die Schlüsselwarnung der
Sury-PPA — und sagt daneben „alle Quellen erreicht". Der Leser unterscheidet
also wirklich zwischen einer Warnung und einem Ausfall, und nicht nur im
Quelltext.

**Nachgetragen am 27. August: das ist der Anker und nicht die Zeilenart.**
`Apt::readFailures()` sucht `^[WE]: Failed to fetch` und nicht `^W:`. Damit
ist belegt, dass kein **falscher** Treffer entsteht — dass jede Form eines
Fehlschlags **gefunden** wird, hält weiterhin `AptResultTest` mit eigenen
Prüfkörpern.

> **Ein falscher Treffer und ein verpasster Treffer sind zwei Fehler, und
> ein Prüfkörper misst immer nur einen davon.**

---

### Punkt 4 — Ein ablaufender Schlüssel · **nicht messbar**

Gefragt wurden die Schlüssel, die eine Quelle über `Signed-By:` wirklich
benennt — nicht ein Verzeichnis:

    /etc/apt/keyrings/docker.asc              8D81803C0EBFCD88   läuft nie ab
    /usr/share/keyrings/php-sury-keyring.gpg  4F4EA0AAE5267A6C   läuft nie ab
    /usr/share/keyrings/srvpanel-archive…gpg  7122B7C9C4393E86   läuft nie ab
    /usr/share/keyrings/ubuntu-archive…gpg    3B4FE6ACC0B21F32   läuft nie ab
                                              D94AA3F0EFE21092   läuft nie ab
                                              871920D1991BC93C   läuft nie ab

**Sechs von sechs ohne Ablaufdatum.** Der Zustand, den Kriterium 4 verlangt,
kommt auf diesem Server nicht vor.

> **Ein Zustand, den die Umgebung nicht zulässt, wird nicht dadurch
> hergestellt, dass man nichts tut.**

Der Punkt gilt damit als **nicht messbar** und **nicht** als erfüllt. `docs/85
§6` lässt genau diesen Ausfall zu. Herstellbar wäre er nur mit einem
Wegwerf-Schlüssel samt Ablauf, auf den eine Quelle zeigt — ein Eingriff in die
Signaturkette des Servers, der ungefragt nicht gemacht wird.

---

**Offene Frage 1 — das `W:` steht über zwei Zeilen.**

In der Ausgabe von Vorgang 692:

    W
    : https://ppa.launchpadcontent.net/…/InRelease: Signature by key 14AA…6C
      uses weak algorithm (rsa1024)

`W` allein, dann `: …`. Damit ist die Marke zerrissen, die eine Zeile überhaupt
erst als Warnung erkennbar macht.

**Ob es die Daten sind oder die Anzeige, ist noch nicht gemessen.** `.output`
trägt `white-space: pre-wrap; word-break: break-word` — ein Umbruch nach *einem*
Zeichen ergibt bei dieser Breite keinen Sinn, also steht der Umbruch vermutlich
schon in dem, was apt schreibt. Solange das nicht gemessen ist, steht es hier
als Frage und nicht als Befund.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

### Punkt 6 — Eine Conffile steht mit ihrem Pfad da (Kriterium 6) · **erfüllt**

**Zwei Prüfkörper und nicht einer**, weil ein einzelner im Fehlerfall dasselbe
zeigte wie im Erfolgsfall: einer in der ersten Ebene mit `.dpkg-dist`, einer
**vier Ebenen tief** mit der anderen Endung `.ucf-dist`.

Die Seite meldet vier — die beiden Prüfkörper und zwei echte, die schon lagen:

    4 Konfigurationsdateien unter /etc warten auf eine Entscheidung:
    /etc/default/grub.ucf-dist,
    /etc/srvpanel/abnahme-a1.conf.dpkg-dist,
    /etc/srvpanel/tief/a/b/zweite.conf.ucf-dist,
    /etc/ssh/sshd_config.ucf-dist

**Voller Pfad, beide Endungen, Tiefe 4.** Und die zwei echten sind der Beleg,
dass der Leser nicht nur findet, was der Lauf selbst hingelegt hat.

---

**Befund 4 — das `W:` zerreisst in der Ausgabe, und apt ist es nicht.**

Gemessen unter der Umgebung des Agenten, mit sichtbaren Zeilenenden:

    1:W: https://ppa.launchpadcontent.net/…/InRelease: Signature by key
    14AA…6C uses weak algorithm (rsa1024)$

**Eine Zeile, `$` nur am Ende.** apt schreibt das `W:` zusammenhängend; der
Umbruch entsteht danach — im Panel oder in der Anzeige.

Auf der Vorgangsseite steht `W` allein und `: …` darunter. Damit ist die Marke
zerrissen, an der eine Zeile überhaupt als Warnung erkennbar ist.

> **Ein Format, das für Fliesstext reicht, reicht nicht für eine Marke am
> Zeilenanfang.**

`.output` trägt `white-space: pre-wrap; word-break: break-word`. Ob der Umbruch
von dieser Regel kommt oder aus dem gespeicherten Text, ist noch offen — die
Antwort entscheidet, ob der Fix in `app.css` oder im Weg der Ausgabe steht.

---

**Beobachtung 3 — Punkt 0b hat 0 gemessen, eine Stunde später sind es 7.**

Die Seite zeigt `AKTUALISIERBAR 7`, nachdem der Lauf die Sury-Quelle einmal
getötet, wiederhergestellt und ihre `InRelease` zum Neuholen gezwungen hat.
Punkt 0b hatte nach einem erfolgreichen `apt-get update` **0** gemessen.

**Damit werden die Punkte 1, 2 und 8 heute doch messbar** — die Teilung aus §2
gilt nur noch für Punkt 5, der `srvpanel` selbst in der Liste braucht.

Woher die sieben kommen, ist nicht gemessen: Entweder hat Sury in der Zwischen-
zeit veröffentlicht, oder der zwischengespeicherte Index war älter als das
`OK:` von apt vermuten liess. Der Unterschied ist nicht akademisch — im zweiten
Fall hiesse „OK:" nicht „aktuell", und Punkt 0b hätte an einem veralteten Index
gemessen.

> **Eine Zahl, die sich ändert, ohne dass jemand die Ursache kennt, ist keine
> Messung — sie ist ein Anlass zu einer.**

---

### Punkt 7 — Der Neustart, und was vor ihm steht (Kriterium 7)

**7b — die Meldung nennt die Pakete · erfüllt.**

Hergestellt mit `touch /run/reboot-required` und zwei Namen in
`/run/reboot-required.pkgs`. Die Seite meldet in Bernstein:

    Ein Neustart steht aus — srvpanel, libssl3

**Beide Namen stehen da, und der Rang ist `warn`** — derselbe Wortlaut, den der
Quelltext führt. Die Meldung ist damit nicht „es liegt eine Datei", sondern
„weswegen": Wer sie liest, weiss, ob er den Ausfall heute oder nächste Woche
einplant.

**7c — die Rückfrage verlangt den Namen des Servers · erfüllt.**

Der Text nennt, was der Neustart mitnimmt — alle Websites, Datenbanken und
Postfächer, **und dieses Panel** —, die 60 Sekunden bis zum Anlaufen und den
Griff, mit dem man ihn in dieser Zeit noch stoppt
(`systemctl stop srvpanel-reboot.timer`).

Verlangt wird **`cloudsrv24.de`** und nicht `cloudsrv24`. Mit `asdf` bleibt der
Knopf grau, mit dem vollen Namen wird er frei. Beides gemessen.

**Und das berichtigt meine eigene Anweisung.** Ich hatte „`cloudsrv24` → Knopf
wird frei" hingeschrieben — der kurze Name, den `php_uname('n')` liefert. Die
Seite fragt `Names::host()`, und die gibt den vollen. Der Prüfling hat recht und
die Vorschrift hatte unrecht; wäre sie befolgt worden, hätte der Punkt einen
Fehler gemeldet, den es nicht gibt.

> **Eine Vorschrift, die einen Wert selbst einsetzt statt ihn zu erfragen,
> prüft ihren Verfasser.**

---

**Befund 5 — das Feld der Rückfrage ist kürzer als die Knöpfe darunter.**

Gemeldet vom Betreiber am Bild zu 7c, bei 390 px. Nachgemessen im Container mit
dem gebauten Stylesheet und dem Markup aus `Confirmation.vue`:

    390px   block=358  feld=285  knopf=323  abbrechen=323  differenz=38
    1440px  block=1376 feld=285  knopf=180  abbrechen=121  differenz=-105

**38 px kürzer**, und in einer Spalte sieht man jedes davon. Die breite Ansicht
ist in Ordnung — dort ist der Deckel gewollt.

Der Fehler sind **zwei richtige Regeln, die niemand aneinandergebunden hat.**
`max-width: 32ch` ist eine Zusage über die breite Ansicht („hier steht ein Wort
und kein Satz"); unter 480 px zieht `.button-row` ihre Knöpfe auf volle Breite,
damit vom zweiten nicht drei Buchstaben übrigbleiben. Jede für sich ist
begründet, und zusammen ergeben sie eine ausgefranste Spalte.

> **Eine Breite, die für die breite Ansicht begründet ist, ist auf der schmalen
> keine Begründung mehr — sie ist ein Rest.**

Behoben: Der Deckel fällt unter 480 px weg. Nachgemessen **0 px Unterschied**
bei 390 px, unverändert bei 1440 px, Dokumentüberlauf `0` in beiden Lagen bei
einer Gegenprobe, die mit 216 und 232 ausschlägt. Bilder in beiden Themes.

**Der Wächter prüft die Bindung und nicht die Zahl.**
`MobileLayoutTest::test_the_confirmation_field_is_not_capped_on_a_phone` liest
den Haltepunkt, an dem die Knöpfe dehnen, und verlangt die Aufhebung des
Deckels an **demselben**. Eine Prüfung über „285 gegen 323" müsste `ch` in Pixel
umrechnen, also eine Schriftart annehmen — und sie hielte den Fall nicht, um den
es geht: dass jemand eine der beiden Zahlen allein verschiebt. Vier Eingriffe,
alle beissen, jeder mit seiner eigenen Meldung; zwei davon stehen als
Dauereingriffe in `tests/waechter-brechen.sh`.

---

**Beobachtung 4 — gefunden hat es der Betreiber am Bild, nicht die Messung.**

Der Dokumentüberlauf stand vorher wie nachher auf `0`. Ein Feld, das 38 px zu
kurz ist, schiebt nichts; es steht nur falsch da.

> **Ein Fehler, der nichts überlaufen lässt, hat keine Zahl — nur einen
> Betrachter.**

Derselbe Satz wie in `docs/46` und `docs/55` — und daneben der aus `docs/59`:
Das Bild zu 7c ist auf die Frage hin angesehen worden, ob die Rückfrage bei
390 px sauber stapelt. Sie tut es, der Überlauf ist `0`, und der Punkt galt als
erfüllt.

> **Ein Bild, das man auf eine Frage hin ansieht, beantwortet die Frage — und
> verdeckt alles, was daneben steht.**

---

### Punkt 7d — die Anzeige nimmt ihren Zustand zurück · **erfüllt**

`rm /run/reboot-required /run/reboot-required.pkgs`, Seite neu geladen: „Kein
Neustart nötig", grün. Kein Rest der bernsteinfarbenen Meldung.

**Zustand 3 ist nicht gemessen worden**, und das ist eine Entscheidung mit
Begründung. `reboot()` fragt `dpkg-query` nach `update-notifier-common`;
herstellen liesse sich der Zustand also nur, indem man das Paket entfernt. Der
Trockenlauf sagt, was das kostet:

    The following packages will be REMOVED:
      ubuntu-server update-notifier-common

`ubuntu-server` ist ein Metapaket ohne eigene Dateien und mit einem Handgriff
zurückzuholen — aber solange es fort ist, gelten dutzende automatisch
installierte Pakete als entbehrlich, und ein `apt autoremove` in diesem Fenster
räumte sie ab. Auf einem Server mit Kundenwebsites ist das der falsche Preis für
eine Messung, die der Quelltext ohnehin belegt.

**Im Quelltext belegt, auf dem Server nicht gemessen.** Der zweite Satz ist der,
auf den es ankommt.

---

### Punkt 9 — die Automatik, vorgezogen

`unattended-upgrades` ist **installiert**, und beide Timer sind scharf:

    apt-daily.timer          Thu 2026-08-27 04:44:53 CEST   8h
    apt-daily-upgrade.timer  Thu 2026-08-27 06:17:32 CEST  10h

Damit ist der Prüfkörper der Punkte 1, 2 und 5 **verderblich**: Vier der
anstehenden Pakete sind Sicherheitsaktualisierungen, und in zehn Stunden spielt
sie der Server selbst ein. Punkt 9 wird deshalb vorgezogen — sein Griff schaltet
die Automatik, und damit misst er sich selbst und rettet die übrigen Punkte.

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**

**Und die Nummer stand hier einmal falsch.** Ich hatte „Punkt 8" geschrieben —
das ist „ein zweiter Lauf wird abgewiesen". Geschrieben aus dem Gedächtnis statt
aus `docs/85`, und das ist derselbe Handgriff, der in `docs/84` eine Anweisung
gekostet hat.

> **Eine Anweisung, die zuerst „nachsehen" sagt und danach den geratenen Wert
> einsetzt, hat das Nachsehen zur Verzierung gemacht.**

---

**Befund 7 — „Zurückgehalten 0", während sieben zurückgehalten werden.**

Die Kacheln der Seite:

    AKTUALISIERBAR 11 · DAVON SICHERHEIT 4 · DAVON NEU 0
    ZURÜCKGEHALTEN 0 · WÜRDE ENTFERNT 0

Und was apt an derselben Stelle sagt (`apt-get -s upgrade`, unter `LC_ALL=C`
gemessen):

    The following upgrades have been deferred due to phasing:
      libproc2-0 libpython3.12-minimal libpython3.12-stdlib libpython3.12t64
      procps python3.12 python3.12-minimal

**Sieben Pakete werden zurückgehalten, und die Kachel sagt `0`.**

Die Ursache ist ein Ausdruck, der einen Wortlaut kennt und nicht die Regel.
`Packages::keptBack()` sucht `have been kept back` — den Satz, den apt für eine
durch Abhängigkeiten blockierte Aktualisierung schreibt. Ubuntu schreibt für ein
zurückgehaltenes **Phasing** einen anderen, und beide bedeuten für den Betreiber
dasselbe: das kommt jetzt nicht.

> **Ein Ausdruck, der die gewohnte Schreibweise kennt, prüft die Gewohnheit und
> nicht die Regel.**

**Und die Auskunft liegt in der Antwort, die der Agent bereits liest.** Sie steht
in derselben Ausgabe von `apt-get -s upgrade`, aus der `keptBack()` seinen einen
Satz zieht — samt der sieben Namen zwei Zeilen darunter. Es fehlt kein Griff,
sondern ein Ausdruck.

> **Eine Auskunft, die entsteht und die niemand weitergibt, ist so gut wie
> keine.**

Derselbe Satz wie in `docs/66` an `context` im Protokoll, hier an einer Stelle,
an der er dem Betreiber sagt, ob sein Server nach dem Einspielen fertig ist.

---

**Offen: Warum zeigt die Seite 11, wo ein Lauf 4 einspielt?**

Gemessen am 26. August, 20:23:34 CEST, in der Shell:

    LC_ALL=C apt-get -s dist-upgrade | grep '^Inst '
    libheif-plugin-libde265 · libheif-plugin-aomenc · libheif-plugin-aomdec · libheif1

**Vier.** Die Kachel `AKTUALISIERBAR` ist `upgradable.length`, `Packages::read()`
füllt `upgradable` ausschliesslich aus diesen `Inst`-Zeilen, und der Controller
reicht das Ergebnis unverändert durch. Elf können dort nicht stehen — und sie
stehen dort.

**Zwei Vermutungen sind gemessen und beide falsch.** Die feste Umgebung des
Agenten ist es nicht: unter `env -i` mit `Runner::ENVIRONMENT` nachgestellt
kommen dieselben **4** heraus, und der `diff` der Namen ist leer. Zusatzoptionen
sind es auch nicht — `SystemPackagesList::apt()` ruft `apt-get -s dist-upgrade`
blank.

**Und die Liste unter den Kacheln beantwortet es.** Sie führt elf Zeilen, und die
sieben zurückgehaltenen stehen namentlich darin:

    libpython3.12t64 · python3.12 · libpython3.12-stdlib · python3.12-minimal
    libpython3.12-minimal · libproc2-0 · procps      → Ubuntu:24.04/noble-updates
    libheif-plugin-libde265 · libheif-plugin-aomenc
    libheif-plugin-aomdec · libheif1     · Sicherheit → Ubuntu:24.04/noble-security

Daneben, 20:28:31 und noch einmal 20:29:01 in der Shell: **vier**.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

Die Zahl allein hätte „elf gegen vier" gesagt und offengelassen, *welche* elf.
Mit den Namen ist es der ernste Fall: Die Seite bietet sieben Pakete an, die ein
Lauf nicht einspielt — **und sie sind alle vorausgewählt, wenn der Betreiber
„Alle installieren" drückt.**

Herkunft und Fassungen der sieben stimmen dabei mit dem überein, was apt in
`apt list --upgradable` zeigt. Der Agent hat sie also aus echten `Inst`-Zeilen
gelesen; sie sind nicht erfunden, sondern von einem Lauf, der sie anbot.

**Was den Unterschied macht, ist ungemessen.** Drei Vermutungen sind geprüft und
alle drei falsch: die feste Umgebung des Agenten (unter `env -i` nachgestellt:
dieselben vier, `diff` leer), Zusatzoptionen am Aufruf (`SystemPackagesList::apt()`
ruft blank), und ein Unterschied zwischen ausgeliefertem Stand und Arbeitsbaum
(`git diff v0.7.2-rc.1..HEAD` über die vier beteiligten Dateien: leer).

> **Wissen aus zweiter Hand sieht aus wie Wissen.** Dreimal an einem Abend, und
> jedes Mal klang die Vermutung beim Aufschreiben wie ein Ergebnis.

---

### Punkt 9a — der Zustand vor dem Schalten · **gemessen**

Aus **apts** Sicht, nicht aus unserer Datei:

    APT::Periodic ""
    APT::Periodic::Update-Package-Lists "1"
    APT::Periodic::Download-Upgradeable-Packages "0"
    APT::Periodic::AutocleanInterval "0"
    APT::Periodic::Unattended-Upgrade "1"

    /etc/apt/apt.conf.d/10periodic       129 Bytes, Feb 10 2026
    /etc/apt/apt.conf.d/20auto-upgrades   80 Bytes, Feb 12 2024

    unattended-upgrades: installed
    apt-daily.timer          Thu 2026-08-27 04:44:53 CEST
    apt-daily-upgrade.timer  Thu 2026-08-27 06:17:32 CEST

**Die Automatik ist an**, der Hauptschalter steht auf leer (also nicht auf `0`),
und beide Timer sind scharf. **`zz-srvpanel-unattended` gibt es nicht** — das
Panel hat hier noch nie geschrieben; der Ausgangszustand ist unberührt und
stammt vom Abbild.

Das ist der Zustand, der beim Schalten verlorengeht, und deshalb steht er hier,
bevor geschaltet wird.

---

**Beobachtung 5 — der leere Griff in die falsche Datei.**

Gefragt war, was der Agent wirklich ausgeführt hat. Der Griff ging an
`journalctl -u srvpanel-agentd` und kam **leer** zurück — was sich wie ein Befund
liest („der Agent hat gar kein apt gerufen") und keiner ist: Der Agent schreibt
nicht ins Journal. `Config::DEFAULT_LOG_FILE` und `packaging/etc/agent.json`
nennen beide `/var/log/srvpanel/agent.log`.

> **Ein leerer Griff in die falsche Datei sieht aus wie ein Befund.**

Derselbe Satz wie in `docs/78`, wo der erste Griff in
`/var/log/nginx/error.log` ging und die Domain ihren eigenen hat. Beide Male
hätte die Antwort im Quelltext gestanden, und beide Male ist zuerst getippt
worden.

---

### Punkt 9b — geschaltet, und der wirksame Zustand folgt · **erfüllt**

Vorgang **693**, `system.packages.unattended`, Argumente `{"enabled": false}`,
Zustand `fertig`, Ausgabe **„ausgeschaltet"**, 20:38:17 begonnen und beendet.

Danach aus **apts** Sicht:

    APT::Periodic::Unattended-Upgrade "0"     ← vorher "1"
    APT::Periodic::Enable "1"                 ← vorher gar nicht aufgeführt
    /etc/apt/apt.conf.d/zz-srvpanel-unattended   550 Bytes, Aug 26 20:38

**Die Seite zeigt, was `apt-config dump` zeigt**, und nicht den Inhalt der
eigenen Datei. Das ist der Kern des Punktes, und er hält.

---

**Beobachtung 6 — beim Ausschalten ist ein Schalter angegangen.**

`APT::Periodic::Enable` stand vor dem Griff in `apt-config dump` **überhaupt
nicht** (nur `APT::Periodic ""`), danach auf `1`. Der Betreiber hat „die
unbeaufsichtigten Updates aus" verlangt und bekommen — und dazu einen zweiten
Schalter gesetzt, nach dem er nicht gefragt hat.

Hier ist es folgenlos: Ohne die Zeile gilt die Vorgabe, und die ist `1`. Der Fall,
in dem es zählt, ist der umgekehrte — ein Betreiber, der die periodische
Maschinerie bewusst auf `0` gestellt hat, bekäme sie durch einen Griff an einem
anderen Schalter zurück.

> **Ein Schalter, der einen zweiten mitnimmt, ist von aussen nicht als zwei
> Handlungen zu erkennen.**

Ob das gewollt ist, entscheidet der Inhalt der Datei; 550 Bytes sind mehr als
zwei Zeilen. **Ungemessen und benannt.**

---

**Der Aufruf des Agenten ist gelesen, nicht vermutet.**
`/var/log/srvpanel/agent.log`:

    "command":["/usr/bin/apt-get","-s","dist-upgrade"],"code":0
    "command":["/usr/bin/apt-get","-s","upgrade"],"code":0
    "command":["/usr/bin/apt-get","indextargets"],"code":0

Blank, ohne Zusatzoption, ein Paar je `system.packages.list`. **Vierte Vermutung
zum Unterschied 11 gegen 4, und die vierte Fehlanzeige.**

Der Log gibt dazu die Zeiten in UTC: Eine Seite hat um `18:28:02Z` gerendert,
also **20:28:02 CEST** — neunundzwanzig Sekunden vor der Shell-Messung von
20:28:31, die vier ergab. Damit ist **Zeit als Erklärung ausgeschlossen**: Es ist
derselbe Index in derselben Minute.

Was übrig bleibt und noch nicht gemessen ist: der **Sandkasten** der Unit.
`srvpanel-agentd.service` läuft mit `PrivateTmp`, `RestrictNamespaces`,
`ProtectKernelTunables`, `ProtectControlGroups`, `LockPersonality` und
`MemoryDenyWriteExecute` — und das ist genau das, was ein `env -i` aus einer
Anmeldeschale **nicht** nachstellen kann.

> **Eine Gegenprobe, die den Prüfling aus einer anderen Umgebung heraus ruft,
> misst die andere Umgebung mit.**

---

**Befund 6 — die Seite bietet an, was ein Lauf nicht einspielt.**
**Reproduziert und eingegrenzt.**

Gemessen am 26. August, in **einem** Aufruf und damit ohne die Zeitfrage:

    ── im Sandkasten des Agenten ──                11
    ── Gegenprobe, direkt daneben, ohne Sandkasten ──   4

Hergestellt mit `nsenter -t $(systemctl show -p MainPID --value srvpanel-agentd)
-m`, also im **Mount-Namensraum** der Unit, bei sonst gleicher fester Umgebung.

Damit ist die Ursache eingegrenzt und vier Vermutungen sind widerlegt: nicht die
Umgebungsvariablen (dieselben in beiden Läufen), nicht die Argumente (blank, im
Agentenlog gelesen), nicht der ausgelieferte Stand (`git diff` leer), nicht die
Zeit (Seitenaufbau und Shell-Messung 29 Sekunden auseinander).

**Die Ursache ist der Mount-Namensraum**, einzeln gemessen statt in einem Zug:

    ohne alles              →  4
    PrivateTmp              → 11
    ProtectKernelTunables   → 11
    ProtectControlGroups    → 11
    RestrictNamespaces      →  4
    Gegenprobe (Shell)      →  4

Die drei, die 11 ergeben, legen einen **Mount-Namensraum** an. `RestrictNamespaces`
tut das nicht — es verbietet nur das *Anlegen* — und gibt 4. Zwei weitere
Vermutungen sind dabei ausgeschieden: Die `machine-id` ist innen wie aussen
`208394f6…1319`, und der `diff` über `apt-config dump` ist **leer**. Es ist keine
Konfiguration und keine fehlende Kennung, sondern die Form des Dateibaums.

> **Eine Gegenprobe, die zwei Wände zugleich wegnimmt, sagt über keine von
> beiden etwas.** (`docs/61 §1`) Fünf Wände auf einmal hätten hier „der
> Sandkasten" ergeben und damit nichts.

**Und die zweite Hälfte steht im Quelltext.** `SystemPackagesUpgrade` setzt
`apt-run` über `systemd-run` ab, und die transiente Unit bekommt **keine**
Härtung mit — nur `--unit`, `--collect`, `--description`, die beiden `Standard*`
und `DEBIAN_FRONTEND`. Das ist genau die Zeile `ohne alles → 4`.

| | Kontext | Antwort |
|---|---|---|
| Was die Seite zeigt | Agent, mit Härtung | **11** |
| Was „Alle installieren" tut | transiente Unit, ohne Härtung | **4** |

**Derselbe Befehl, zwei Orte, zwei Antworten — und dazwischen sitzt der Knopf.**

> **Zwei Läufe desselben Befehls an zwei Orten sind zwei Messungen und nicht
> eine.**

Die Wirkung für den Betreiber: Er sieht elf Zeilen, drückt „Alle installieren",
und `apt-run` schreibt ihm am Ende *„offen: vorher 4, jetzt 0"* — eine Zahl, die
er nie gesehen hat. Beim nächsten Aufruf stehen sieben Zeilen da.

**Und das löst Beobachtung 3 auf.** Die Seite zeigte vor dem `apt update` des
Betreibers **7**, während die Shell dort null aktualisierbare Pakete maß: Es
waren die sieben phasenverzögerten, die der Sandkasten mitzählt und die Shell
nicht. Mit den vier Sicherheitsupdates aus dem `apt update` wurden daraus elf.
Es war nie eine zweite Frage, sondern von Anfang an dieselbe.

---

**Beobachtung 6, aufgelöst — der zweite Schalter ist Absicht, und trotzdem
asymmetrisch.**

Die Datei, die das Panel schreibt:

    // Diese Datei gehört dem Panel und wird bei jeder Änderung neu geschrieben.
    // Der Name beginnt mit zz, weil apt seine Fragmente nach ASCII sortiert liest …

    APT::Periodic::Enable "1";
    APT::Periodic::Update-Package-Lists "1";
    APT::Periodic::Unattended-Upgrade "0";
    Unattended-Upgrade::Automatic-Reboot "false";

Der Hauptschalter steht dort mit Begründung: Beim **Einschalten** nützt
`Unattended-Upgrade "1"` nichts, solange `Enable` auf `0` steht. Beim
**Ausschalten** braucht es ihn nicht — und genau dort ist er die einzige Zeile,
die etwas anschaltet, was der Betreiber nicht verlangt hat. Ein Server, auf dem
`Enable "0"` bewusst gesetzt war, fährt nach einem Griff an einem *anderen*
Schalter wieder täglich los.

> **Ein Schalter, der einen zweiten mitnimmt, ist von aussen nicht als zwei
> Handlungen zu erkennen.**

Kein Kriterium dieses Laufs, benannt für die nächste Fassung.

---

**Der Mechanismus von Befund 6, belegt statt erklärt.**

    ischroot im Sandkasten:   rc=0
    ischroot aussen:          rc=1

apt hält sich in einem Mount-Namensraum für ein **chroot**, und in einem chroot
wendet Ubuntu Phasing grundsätzlich nicht an. Das ist keine fehlende Datei und
keine verlorene Kennung, sondern eine Erkennung, die anschlägt.

> **Eine Härtung, die einem Programm die Form seines Dateibaums ändert, ändert
> seine Antwort — nicht seine Fehlermeldung.**

**Und die vier Läufe zu den beiden Optionen, mit ihrer Gegenprobe:**

    Sandkasten, ohne Option:                11
    Sandkasten, Always-Include=false:       11   ← greift nicht
    aussen, Always-Include=true:            11   ← die Gegenprobe schlägt an
    Sandkasten, Never-Include=true:          4   ← schlägt die Chroot-Erkennung
    aussen, Always=true UND Never=true:     11   ← Always gewinnt gegen Never

Die zweite Zeile ist der Grund, dass dieser Abschnitt fünf Zeilen hat und nicht
zwei: **Der zuerst vorgeschlagene Fix war falsch, und gemessen hat es sich vor
dem Bauen.** Die Chroot-Prüfung steht **vor** der Option; `Always-Include=false`
kann sie nicht zurücknehmen.

> **Eine Option, die etwas erlaubt, ist nicht dasselbe wie ein Zustand, in dem
> es geschieht.**

Die dritte Zeile ist die Gegenprobe, ohne die die zweite nichts bedeutete: Sie
belegt, dass die sieben **genau** die phasenverzögerten sind und dass der
Schalter überhaupt wirkt. Und die fünfte ist eine Rangfolge, die niemand
bestellt hat und die jeder Fix kennen muss.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht** — hier: eine 11 ist nur dann „greift nicht", wenn daneben eine 4 steht,
> die zeigt, dass der Schalter existiert.

---

**Der Fix — zwei Wege, und der billigere ist der schlechtere.**

**A) Die Simulation zieht dorthin, wo der echte Lauf stattfindet.** `apt-run`
bekommt einen Modus zum Nachsehen, und der Agent fragt ihn genauso ab, wie er
`all` absetzt. Dann gibt es **eine** Stelle, an der apt gefragt wird, und beide
Seiten stimmen von Bauart wegen überein statt aus Versehen. Das Panel verhält
sich damit genau wie `apt` auf der Kommandozeile. Kostet je Seitenaufbau eine
transiente Unit.

**C) `Never-Include-Phased-Updates=true` an allen drei Aufrufen.** Eine Option
statt eines Umbaus — und eine **Verhaltensänderung**: Der Schalter heisst nicht
„verhalte dich normal", sondern „halte phasenverzögerte Pakete immer zurück".
Auf einer Maschine, die Ubuntu für die Phase **ausgewählt** hat, bekäme der
Betreiber sie über das Panel später als über die Kommandozeile.

> **Ein Griff, der zwei Seiten zur Übereinstimmung bringt, indem er beide
> verschiebt, hat die Frage nicht beantwortet, sondern verlegt.**

Empfohlen ist **A**, und die Entscheidung liegt beim Betreiber. Was in jedem
Fall dazugehört, ist **Befund 7**: Sobald die Simulation dort läuft, wo Phasing
wirkt, schreibt apt „deferred due to phasing" — und liest `keptBack()` den Satz
nicht, zeigt die Seite dann vier statt elf und verschweigt die sieben ganz.

> **Ein Leser, der richtig liest, sagt nichts über eine Quelle, die nichts
> sagt** — und umgekehrt.

---

## Gebaut am 26. August — Befund 6 und 7, und was dabei herausfiel

**Der Weg ist A**, entschieden vom Betreiber: Die Simulation zieht dorthin, wo
eingespielt wird, statt beide Seiten mit einer Option zu verschieben.

`apt-run` bekommt den Modus **`simulate`** — beide Läufe (`-s dist-upgrade` und
`-s upgrade`) in einem Aufruf, getrennt durch eine Marke, abgesetzt über
dieselbe transiente Unit wie `apt-run all`. `Apt::simulate()` ruft ihn,
**`Apt::sections()` ist die Naht** und besteht auf genau den erwarteten
Abschnitten: Ein fehlender wäre sonst eine leere Ausgabe, und die liest sich wie
„nichts zu aktualisieren".

**Beide Aufrufstellen ziehen mit, und die zweite ist die wichtigere.**
`SystemPackagesUpgrade::upgradable()` baut aus derselben Simulation die
**Positivliste**, gegen die `names()` prüft. Aus ihr heraus käme ein einzeln
ausgewähltes phasenverzögertes Paket über `install <name>` durch — ein
ausdrücklicher Name kennt kein Phasing. Die beiden Knöpfe derselben Seite hätten
verschieden entschieden, aus derselben Liste.

**Befund 7 ist mitgebaut**, und `held` trägt seitdem den **Grund**: Gegen ein
abhängigkeitsbedingt stehengebliebenes Paket hilft `dist-upgrade` — also der
Knopf —, gegen ein phasenverzögertes hilft warten. Die Seite zeigt zwei Sätze
statt einer Zahl mit Sternchen.

**Neun Eingriffe, alle beissen**, jeder mit eigener Meldung; vier davon stehen
als Dauereingriffe im Bruchskript. Voller Testlauf im Container, `pint` sauber,
PHPStan über die Dateien dieses Zweiges leer — mit Gegenprobe, weil ein leerer
Filter genauso aussieht wie eine saubere Datei.

---

**Beobachtung 7 — der achte Eingriff hat nicht gebissen, und das lag am
Prüfkörper.**

Er stellte den alten `break` wieder her, und `test_both_reasons_stand_side_by_side`
blieb grün: In seinem Prüfkörper standen die beiden Überschriften unmittelbar
untereinander, und eine Überschrift wird **vor** der Einrückungsprüfung erkannt
— die Schleife kam gar nicht bis zum `break`. Zwischen den beiden steht jetzt,
was auf `cloudsrv24` wirklich dazwischenstand.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

---

**Beobachtung 8 — ein bestehender Eingriff hat seine Zielstelle verloren.**

Aus dem `break` wurde ein Zurücksetzen mit `continue`; ein Eingriff von P6 zeigte
weiter auf das alte `break`. Gemeldet hat es `BreakScriptTest` im selben Lauf, in
dem die Änderung entstand — nicht das Nachdenken.

> **Ein Eingriff, dessen Zielstelle umzieht, prüft nichts mehr — und sieht dabei
> aus, als wäre die Regel abgesichert.**

---

**Befund 8 — der Agent importierte eine Testklasse, und niemand hat sie
geschrieben.**

Pint macht aus `{@see \Tests\Unit\…}` in einem Dokumentblock einen
`use`-Eintrag. Nachgesehen, ob das schon einmal passiert ist:
`agent/src/PhpSettings.php` trug seit P6

    use Tests\Unit\AnchoredPatternTest;

— in dem Prozess, der als root Pakete installiert und Systembenutzer anlegt.

> **Ein Werkzeug, das eine Schreibweise vereinheitlicht, verschiebt damit eine
> Abhängigkeit — und niemand hat sie geschrieben.**

Folgenlos war es nur, weil ein Dokumentblock nichts lädt. Derselbe Griff an einer
Marke im Rumpf wäre ein `Class not found` auf einem echten Server und **erst
dort** — im Container liegt `vendor/` daneben und alles löst auf.

> **Eine Abhängigkeit, die in der Entwicklungsumgebung vorhanden ist, fällt erst
> dort auf, wo sie fehlt.**

`AgentIndependenceTest` hält die erste der drei Grenzen seitdem mechanisch, als
**Positivliste**. Sein erster Lauf hat vier weitere Einträge gefunden —
`OpenSSLAsymmetricKey`, `CurlHandle` und ihresgleichen —, und die gehören dazu:
Sie kommen mit PHP und nicht mit `vendor/`.

---

**Beobachtung 9 — zwei Handgriffe an mir selbst, beide in dieser Datei
beschrieben.**

Ein Ausdruck mit `(?:.*\n)*?` sollte eine private Hilfsmethode entfernen und
nahm `execute()` mitsamt 234 Zeilen mit. Gemeldet hat es kein Nachdenken,
sondern `AptLockReachTest` — mit der Frage „fasst diese Datei noch apt an?", und
die Antwort war nein, weil die Datei keinen Rumpf mehr hatte.

> **Ein Wächter über eine Regel fängt auch, was mit seiner Regel nichts zu tun
> hat — wenn er am Gegenstand misst und nicht am Wort.**

Und beim Gegenprüfen eines frisch gebauten Wächters holte `git checkout --` eine
**unverfolgte** Datei nicht zurück, sondern tat wortlos nichts — der Satz aus
`CLAUDE.md`, gelesen und trotzdem getippt.

> **Ein Rückweg, der stillschweigend nichts tut, ist schlimmer als keiner.**

---

**Was damit für den Lauf offen bleibt.** Die Punkte 1, 2, 2b, 5, 8, 10, 11 und 12
sind ungemessen. Sie brauchen `0.7.2-rc.2` auf dem Server — Punkt 5 ausserdem
`srvpanel` selbst in der Liste. **Auf dem Server nachgesehen ist nichts davon**:
Gebaut und gemessen ist im Container, und das ist zweierlei.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

---

## Nachgesehen gegen `0.7.2-rc.2` — 26. August 2026

`srvpanel version` meldet **`0.7.2-rc.2`**. Die Kacheln der Updates-Seite:

    AKTUALISIERBAR 4 · DAVON SICHERHEIT 4 · DAVON NEU 0
    ZURÜCKGEHALTEN 7 · WÜRDE ENTFERNT 0

Gegen `0.7.2-rc.1` standen dort **11** und **0**. Beide Befunde greifen also, und
zwar auf dem Server und nicht im Container.

**Und die `7` belegt mehr als sich selbst.** Sie kann unter rc.1 gar nicht
entstehen: Der Leser kannte damals nur `have been kept back`, und im Sandkasten
des Agenten hielt apt ohnehin nichts zurück — beides musste sich ändern, damit
diese Zahl dasteht. Damit ist die Frage beantwortet, die die Freigabenotiz
ausdrücklich als ungemessen führte:

> **`systemd-run --pipe --wait` trägt seine Ausgabe aus dem Agenten heraus
> zurück.**

Der Container konnte das grundsätzlich nicht zeigen — dort gibt es kein systemd.
Belegt hat es keine eigene Messung, sondern eine Zahl, die ohne diesen Weg nicht
dastünde.

**Was hier noch nicht steht: die Namen.** Gesehen sind die Kacheln, nicht die
Liste darunter und nicht die Meldung mit den sieben Namen. Ob der Grund richtig
zugeordnet ist — Phasing und nicht Abhängigkeit —, ist damit **ungemessen**.

> **Ein Kriterium, das nach einer Anzahl fragt, prüft nicht, was gezählt wurde.**

Derselbe Satz hat in diesem Lauf schon zweimal gegolten, und beide Male hat erst
die Liste die Frage entschieden.

---

**Die Namen sind nachgesehen — Befund 6 und 7 sind auf dem Server erfüllt.**

Die Liste unter den Kacheln führt **vier** Zeilen, alle `libheif`, alle mit der
Marke „Sicherheit" aus `Ubuntu:24.04/noble-security`. Die sieben stehen **nicht**
darin, sondern in ihrer eigenen Meldung — und mit dem richtigen Grund:

    Ubuntu spielt 7 Pakete stufenweise aus und hält es auf diesem Server noch
    zurück: libproc2-0, libpython3.12-minimal, libpython3.12-stdlib,
    libpython3.12t64, procps, python3.12, python3.12-minimal

Sieben Namen, dieselben, die `apt-get -s upgrade` als „deferred due to phasing"
führt. Der Grund ist als Phasing zugeordnet und nicht als Abhängigkeit; damit
schickt die Seite den Betreiber nicht mehr auf den Knopf, der sie nicht holt.

---

**Befund 9 — „hält **es** zurück" bei sieben Paketen, und vier ältere Geschwister.**

Der Satz oben ist der Prüfstein: `counted()` entscheidet über das Zahlwort, das
Fürwort daneben stand fest auf `es`. Beim Nachzählen fiel auf, dass das die
kleinere Hälfte war — **fünf** Aufrufe von `counted()` übergaben eine Einzahl
**mit eigenem Artikel**:

    counted(n, 'ein Paket', 'Pakete')                       → „1 ein Paket"
    counted(n, 'Eine Konfigurationsdatei unter /etc wartet') → „1 Eine …"
    counted(n, 'Ein Signaturschlüssel', …)                   → „1 Ein …"

`counted()` schreibt die Zahl **immer** davor, auch bei eins. Vier der fünf sind
älter als dieser Tag; aufgefallen ist die eine, die gerade neu war.

> **Ein Fehler, der an fünf Stellen unabhängig gemacht wurde, ist keine
> Unachtsamkeit, sondern eine fehlende Stelle.**

**Und der bestehende Wächter konnte ihn nicht sehen.** `CountedNounTest` fragt,
ob eine Zahl an einer *Mehrzahl* klebt — „1 Zeilen". Hier klebt sie an einer
richtigen Einzahl, die bloss zu viel mitbringt.

Dabei kam ein sechster Fall heraus, den keine Zahl trägt: Die Meldung über den
fälligen Signaturschlüssel entschied ihr Zeitwort über `some(...)` und blieb
damit im Singular — „2 Signaturschlüssel **ist** abgelaufen".

> **Ein Zeitwort, das von einer anderen Frage abhängt als die Zahl daneben,
> stimmt mit ihr nur zufällig überein.**

Alle sechs behoben, der Wächter erweitert, ein Dauereingriff im Bruchskript.
**Nachgesehen auf dem Server ist das noch nicht** — es geht mit der nächsten
Fassung mit.

---

### Punkt 1 — Die drei Zahlen stimmen (Kriterium 1) · **erfüllt**

Gemessen gegen `0.7.2-rc.2`:

    aktualisierbar           4     Seite: 4
    davon Sicherheit         4     Seite: 4
    zurückgehalten           7     Seite: 7
    Gegenprobe: showhold     0

**Und die Vorschrift war an einer Stelle falsch.** `docs/85` verlangt als dritte
Messung `apt-mark showhold | wc -l`. Das misst seit Befund 7 etwas anderes als
die Kachel: `showhold` kennt nur ausdrücklich festgehaltene Pakete und sieht
Phasing nie. Der Vergleich hätte `0` gegen `7` ergeben — und das liest sich wie
ein Befund am Prüfling.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Gemessen wird stattdessen dieselbe Ausgabe, die auch der Agent liest: die Namen
unter **beiden** Überschriften von `apt-get -s upgrade`. `showhold` steht als
Gegenprobe daneben und muss `0` sein — sonst wäre die `7` womöglich aus einer
ganz anderen Quelle.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

### Punkt 2 — Eine Neuinstallation ist als solche zu sehen (Kriterium 2) · **halb**

    LC_ALL=C apt-get -s dist-upgrade | grep '^Inst ' | grep -v '\['
    (Ende)

**Auf diesem Server gibt es heute keine Neuinstallation** — kein anstehendes
Update zieht ein neues Paket mit, und „davon neu" steht deshalb zu Recht auf `0`.
Herstellen liesse sich der Zustand nur, indem wirklich etwas installiert wird,
und dafür ist ein Server mit Kundenwebsites der falsche Ort.

**Die Form ist trotzdem belegt**, und zwar gegen echtes apt statt gegen eine
ausgedachte Zeile:

    Inst cowsay (3.03+dfsg2-8 Ubuntu:24.04/noble [all])

Diese Zeile, wörtlich vom Server genommen, durch `Packages::inst()` gelesen:

    name: cowsay · old: NULL · new: 3.03+dfsg2-8
    origins: [Ubuntu:24.04/noble] · architecture: all · security: false

Und mit der Gegenprobe daneben — dieselbe Frage an eine Zeile **mit** alter
Fassung ergibt `old: '1.17.6-1ubuntu4.7'`. Über beide zusammen zählt `read()`
**upgradable 2, fresh 1, security 1**.

Das ist die Stelle, an der der Leser einen gemessenen Kommentar trägt: `old`
steht bei einer Neuinstallation als **leere Zeichenkette** da und ist nicht fort,
weil hinter ihr noch eine Gruppe mitspielt; `architecture` fehlt wirklich, weil
sie am Ende steht. Beides gilt für diese echte Zeile.

**Was damit nicht gemessen ist: die Anzeige.** Ob die Seite eine solche Zeile als
„neu" zeigt statt mit einer erfundenen alten Fassung, ist offen — dafür müsste
sie in `dist-upgrade` stehen, und das tut sie heute nicht.

**Und die Vorschrift war hier irreführend.** `docs/85` nennt `apt-get install -s
cowsay` als Ersatz und schreibt dazu, „die Liste der Seite entsteht aus derselben
Simulation". Das stimmt nicht: Die Seite liest `-s dist-upgrade`, und `cowsay`
erschiene dort nie.

> **Ein Prüfkörper, der die Seite ohne den Gegenstand misst, misst die Seite und
> nicht den Gegenstand.**

Zwei Sätze, und der zweite ist der, auf den es ankommt: **Die Form ist auf dem
Server belegt. Die Anzeige einer Neuinstallation ist nicht gemessen.** Die
nächste Gelegenheit kommt von allein — sobald ein Upgrade eine neue Abhängigkeit
mitbringt, steht sie in der Liste, und dann ist es ein Blick.

---

### Punkt 2b — Eine Quelle aus- und wieder einschalten · **erfüllt**

Geschaltet wurde `php-sury.sources` und **nicht** `srvpanel.sources`: Die eigene
Quelle abzuschalten nähme dem Panel den Blick auf seine eigenen Updates, und
Punkt 5 hängt daran.

Vorgang **694**, `system.sources.toggle`, Argumente
`{path: …/php-sury.sources, stanza: 1, enabled: false}`, Ausgabe
„Paketquelle ausschalten: php-sury.sources", fertig in einer Sekunde.

    Ziele insgesamt      53  →  51  →  53
    Datei                ohne  →  Enabled: no  →  Enabled: yes
    Seite                an    →  abgeschaltet →  an

**Die Datei bleibt stehen und wird nicht auskommentiert.** `Enabled: no` kommt
als Zeile hinzu, alles andere — `URIs`, `Suites`, `Components`, `Signed-By` —
steht unverändert da. Und beim Wiedereinschalten wird die Zeile nicht entfernt,
sondern auf `yes` gesetzt: ausdrücklich statt abwesend.

> **Eine Anzeige, die einen Zustand meldet, muss ihn auch wieder zurücknehmen —
> sonst hat sie ihn nicht gemessen, sondern behalten.**

---

**Beobachtung 10 — meine Gegenprobe zählte eine andere Einheit als die Messung.**

Erwartet hatte ich, die Gesamtzahl falle um die Zahl aus
`apt-get indextargets | grep -c 'ondrej\|sury'`, also um **16**. Gemessen sind
**2**.

Der Fehler ist meiner: `grep -c` zählt **Zeilen**, `indextargets` gibt aber je
Ziel ein Stanza aus, und der Name der Quelle steht darin mehrfach — URI,
Repo-URI, Beschreibung, Kennung. Sechzehn Zeilen sind zwei Ziele.

> **Eine Gegenprobe, die eine andere Einheit zählt als die Messung, bestätigt
> nichts — sie nennt eine zweite Zahl.**

**Die richtige Gegenprobe stand die ganze Zeit auf der Seite.** Die Quellenliste
nennt die Ziele je Eintrag: `1 + 1 + 37 + 12 = 51`, und genau 51 misst
`indextargets` im abgeschalteten Zustand. Die Seite rechnet damit Quelle für
Quelle mit apt überein — eine schärfere Aussage als die, die der Lauf verlangt
hat.

> **Eine Zahl, die der Prüfling selbst je Zeile ausweist, ist eine bessere
> Gegenprobe als eine, die man daneben baut.**

---

### Punkt 8a — Ein fremder Halter wird benannt (Kriterium 8) · **erfüllt**

Vorgang **696**, `system.packages.upgrade`, `{mode: all, packages: []}`,
**fehlgeschlagen** in derselben Sekunde:

    Ein anderer Vorgang hält gerade die Paketsperre /var/lib/dpkg/lock-frontend
    (python3, PID 575363) — das kann ein Lauf des Panels sein oder ein apt auf
    der Kommandozeile. Der nächste Versuch geht erst, wenn er fertig ist.

**Programmname und PID sind der Beleg**, nicht die Ablehnung: Sie zeigen, dass
`/proc/locks` gelesen **und der Halter aufgelöst** wurde. „Etwas ist gesperrt"
könnte auch ein Rateschluss sein. Die PID ist dieselbe, die der Prüfkörper
selbst ausgegeben hat.

---

**Befund 10 — der Prüfkörper der Vorschrift nimmt die falsche Sperrfamilie.**

`docs/85` schreibt für 8a `flock /var/lib/dpkg/lock-frontend sleep 90` vor.
Gemessen am selben Inode, beide Familien nacheinander:

    (1) flock   →   8: FLOCK  ADVISORY  WRITE 575346 fd:03:131426 0 EOF
    (2) fcntl   →  14: POSIX  ADVISORY  WRITE 575363 fd:03:131426 0 EOF

`AptLock::CONFLICTING` führt `POSIX` und `OFDLCK` und **nicht** `FLOCK` — mit
der Begründung, dass die andere Familie apt gar nicht blockiert. Die Vorschrift
hätte also eine Sperre gehalten, die niemanden stört; das Panel wäre losgelaufen,
und das läse sich wie ein Befund am Prüfling.

> **Ein Prüfkörper, der eine andere Sperre nimmt als die gemeinte, prüft die
> gemeinte nicht.**

Gemessen wird deshalb mit `fcntl.lockf` — derselben Familie, die dpkg nimmt.
Die `FLOCK`-Zeile daneben ist die Gegenprobe: Ohne sie wäre „POSIX steht in
`/proc/locks`" nur eine Zeile und kein Unterschied.

---

### Punkt 8b — Der eigene Lauf weist den zweiten ab · **nicht gemessen**

Der Lauf lief durch, bevor ein zweiter Druck möglich war:

    apt-run: 4 von 4 Aktualisierungen eingespielt, 0 bleiben offen.

Genau die Zeile, die `apt-run` zusagt — Vorher und Nachher an derselben Frage
gemessen, nicht am Rückgabewert. Die Unit war während des Laufs **einmal** in
`systemctl list-units 'srvpanel-update-*'` und danach fort (`--collect`).

**Und ein zweiter Anlauf geht heute nicht**, aus einem Grund im Quelltext: Bei
`upgradable.length === 0` ersetzt die Seite die ganze Knopfreihe durch „Es steht
keine Aktualisierung an." Es gibt keinen Knopf mehr, den man ein zweites Mal
drücken könnte.

**Das passt mit Punkt 5 zusammen.** Mit einer Fassung, in der `srvpanel` selbst
in der Liste steht, ist Punkt 5 ein langlaufendes Upgrade des Panels — also
genau das Fenster, das 8b braucht. Die beiden werden zusammen gemessen und
nicht einzeln.

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**

**Nachgetragen am 27. August:** Punkt 5 ist gefahren, und das Fenster ist
ausgeblieben — der Lauf war nach **14 Sekunden** fertig. 8b wartet damit
weiter, und seit dem Lauf auch Punkt 5d; beide stehen bei Punkt 5.

---

### Punkt 9 — vollständig, mitsamt der Nachlesung · **erfüllt**

**Die Automatik ist wieder an.** Vorgang **700**, `{enabled: true}`, fertig,
„eingeschaltet". Danach aus apts Sicht:

    APT::Periodic::Enable "1" · Update-Package-Lists "1"
    APT::Periodic::Unattended-Upgrade "1"
    beide Timer wieder mit nächstem Termin (4h22min und 23h)

Und der Fall, für den die Nachlesung gebaut ist, ist gemessen. Mit einer fremden
Datei `zzz-abnahme-a1`, die `APT::Periodic::Enable "0"` setzt, meldet
Vorgang **699** **fehlgeschlagen** bei Fortschritt **80 %**:

    Die Einstellung ist geschrieben und wirkt nicht. Gewollt war „aus", apt
    meldet: Hauptschalter aus, Listen alle 1 Tage, unbeaufsichtigt alle 0 Tage.
    Diese Dateien setzen den Hauptschalter, und die letzte gewinnt:
    /etc/apt/apt.conf.d/zz-srvpanel-unattended,
    /etc/apt/apt.conf.d/zzz-abnahme-a1

**Besser als die Vorschrift verlangt hat.** `docs/85` erwartet „ein Fehlschlag
mit dem Namen `zzz-abnahme-a1`". Gekommen sind **beide** Dateien, die den
Hauptschalter setzen, samt der Regel, welche gewinnt — der Betreiber sieht
damit nicht nur den Störer, sondern warum er stört.

Und **80 %** ist die ehrliche Zahl: Geschrieben ist geschrieben, gescheitert ist
die Nachlesung. Ein Rückbau an dieser Stelle wäre ein zweiter Schreibvorgang,
der selbst scheitern kann.

> **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand.**

---

**Befund 11 — „Listen alle 1 Tage", und der Wächter las nur `.vue`.**

Der Satz oben ist der Beleg für Punkt 9 **und** ein Befund: In derselben
Meldung steht „alle **1** Tage" und „alle **0** Tage". Die Null ist dabei die
schlimmere von beiden — `apt.systemd.daily` liest `0` als **gar nicht**, und
„alle 0 Tage" legt das Gegenteil nahe.

> **Eine Zahl, die in ihrer Schreibweise das Gegenteil nahelegt, ist schlimmer
> als eine falsche Mehrzahl.**

`Unattended::rhythm()` macht daraus einen Satz: `nie` · `täglich` ·
`alle N Tage`. Die Meldung liest sich jetzt „Hauptschalter aus, Listen täglich,
unbeaufsichtigt nie."

**Beim Auszählen kamen zwei weitere ans Licht**, beide operatorseitig und beide
älter: „es läuft in **1 Tagen** ab" am Panelzertifikat (wo `ceil` aus jeder
angefangenen Stunde einen Tag macht — dort ist die Wahrheit „weniger als
einer") und „noch **1 Tage** gültig" in `srvpanel tls`.

**Und `CountedNounTest` konnte keine davon sehen: Er liest ausschliesslich
`.vue`.**

> **Ein Wächter, der eine Fläche liest, sagt über die andere nichts — und meldet
> für sie „alles in Ordnung".**

Er liest jetzt auch `agent/src`, mit vier benannten Ausnahmen, deren Zahl aus
einer Konstante kommt oder deren Zweig bei eins gar nicht erreicht wird — und
mit der Gegenrichtung, die eine Ausnahme meldet, die nichts mehr deckt.

**Was benannt offen bleibt: dreizehn Fundstellen unter `app/`.** Derselbe
Ausdruck über `app/` meldet sie — `Acceptance`, `FileController`,
`AuditController`, `CustomerController`, `CheckDns`. Sie gehören nicht zu A1 und
werden nicht nebenbei mitgenommen; sie sind **gezählt**, und das ist der
Unterschied.

> **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die kleiner
> werden kann.**

---

**Beobachtung 11 — `git checkout --` hat zum dritten Mal an einem Tag Arbeit
gekostet.**

Beim Gegenprüfen eines Bruchs wurde die Datei mit `git checkout --`
zurückgeholt — und damit auch die **eigene, noch nicht committete** Korrektur
darin. Gefallen ist es dem Wächter auf, der danach rot blieb, obwohl der Bruch
zurückgenommen war.

Dreimal derselbe Handgriff an einem Tag heisst: Es fehlt keine Erinnerung,
sondern eine Gewohnheit. **Gesichert wird mit `cp`, immer** — auch dann, wenn
der Eingriff nur eine Zeile umfasst.

> **Ein Rückweg, der stillschweigend etwas anderes mitnimmt, ist schlimmer als
> keiner.**

---

## Gefahren gegen `0.7.2-rc.2` — 27. August 2026

Der Lauf dieses Tages hat zwei Gegenstände, und der erste bedingt den zweiten.
Punkt 5 ist nur mit einer Fassung im Depot zu messen, in der `srvpanel` selbst
steht, und die gab es erst mit `0.7.2-rc.3`. Punkt 10 misst danach den Ausgang,
den es **nur nach** Punkt 5 gibt: den Lauf, der nichts mehr zu tun findet.

Beide sind vom Telefon aus gefahren — reines SSH, ohne Browser. Für Punkt 11
gilt das nicht.

---

### Punkt 5 — Ein Upgrade, das das Panel enthält (Kriterium 5) · **erfüllt**

**Das ist der Punkt, für den `docs/85` überhaupt geschrieben wurde.** Er belegt
einen Satz, den dieses Projekt seit P0 behauptet und den nur der eigene Gebrauch
gestützt hat: dass eine transiente Unit den Neustart von `srvpanel-worker`
überlebt, wenn `srvpanel` selbst im Lauf steckt.

Gemessen am 27. August 2026 gegen `0.7.2-rc.2`, aufgezeichnet von einem
Beobachterskript, das vorher, während und nachher misst — und das Protokoll ab
dem **Byteversatz vom Laufbeginn** ausgibt statt mit `tail -20`. Der Teil vor
dem Neustart ist genau der, um den es geht; ein `tail` hätte ihn weggeschnitten.

    ══ VORHER ══   11:08:31 · Fassung 0.7.2~rc.2 · Protokoll 3022 Bytes
    ══ WÄHREND ══  11:09:16–11:09:30, acht Abtastungen im Zweisekundentakt
                   Units: 1 durchgehend · Protokoll 3022 → 6965 Bytes (+3943)
    ══ NACHHER ══  Dauer 14 s · Fassung 0.7.2~rc.3
                   srvpanel-web · -worker · -agentd: active active active
    ══ BILANZ ══   apt-run: 6 von 6 Aktualisierungen eingespielt, 0 bleiben offen.

**Die drei Erwartungen aus `docs/85`, einzeln.**

**1. Genau eine transiente Unit, und das Protokoll wächst.** Beides gemessen —
`1` in allen acht Abtastungen, danach fort (`--collect`), und +3943 Bytes. Die
Zahl daneben ist die Gegenprobe: Eine Unit, die steht und nichts schreibt, sieht
in `list-units` genauso aus wie eine, die arbeitet.

**2. Die letzte Zeile ist die Bilanzzeile.** Sie ist es, und sie ist der Beleg
für den ganzen Punkt — aber nicht für sich allein, sondern durch das, was vor
ihr steht:

    srvpanel (0.7.2~rc.3) wird eingerichtet …
    SrvPanel 0.7.2-rc.3 läuft.
    python3.12-minimal … wird eingerichtet …
    libpython3.12-stdlib … python3.12 … libpython3.12t64 …
    Trigger werden verarbeitet · needrestart
    apt-run: 6 von 6 Aktualisierungen eingespielt, 0 bleiben offen.

Der Neustart liegt **zwischen** der ersten und der zweiten Zeile, und das ist
nicht erschlossen, sondern nachgelesen: `packaging/scripts/postinstall.sh` ruft
auf dem Update-Pfad einer eingerichteten Installation `restart_services` auf,
und die startet `srvpanel-agentd`, `srvpanel-web`, **`srvpanel-worker`** und
`srvpanel-metrics` neu. Danach stehen im Protokoll noch vier eingerichtete
Pakete, die Trigger, `needrestart` — und erst dann die Zeile, die `apt-run`
schreibt, **nachdem** `apt-get` zurückgekommen ist.

> **Ein Beleg für den Weg ist keiner für das Ziel.** Dass die Unit abgesetzt
> wurde, sagt nichts darüber, dass sie zu Ende gelaufen ist. Vier Pakete hinter
> dem eigenen Neustart sagen es.

**3. Das Protokoll ist vollständig lesbar, auch der Teil vor dem Neustart.**
Für die **Datei** ist das gemessen: 3022 Bytes standen vorher da, 6965 nachher,
und die Ausgabe ab Byte 3023 beginnt beim ersten Wort dieses Laufs und endet mit
der Bilanzzeile. Es fehlt kein Stück. Die Lesung derselben Datei **über die
Seite** (`/logs`, Quelle „Aktualisierungen installieren") ist damit nicht
mitgemessen und steht als eigener Handgriff aus.

**Punkt 2 aus `docs/81 §2.3h` ist damit beantwortet:** Ja, der Lauf geht nach
dem Neustart weiter.

**Punkt 1 ist es nicht.** Dort steht die Frage nach einem vollen Lauf über
**142** Pakete; gemessen sind **14 Sekunden für sechs**. Das ist eine Zahl und
keine Antwort — sie sagt, dass das Absetzen und das Weiterlaufen funktionieren,
und nichts darüber, wie lange ein Betreiber im schlimmsten Fall wartet.

> **Eine Messung an sechs Fällen beantwortet keine Frage, die nach
> hundertzweiundvierzig gestellt ist — sie sieht nur aus wie eine Antwort,
> solange die Zahl daneben nicht dasteht.**

---

**Befund 12 — Eine Vorprüfung, die vor dem Auffrischen läuft, misst den alten
Index.**

Das Beobachterskript begann mit der Frage aus `docs/85`, ob `srvpanel` in
`apt-get -s dist-upgrade` steht. Um 11:08:31 stand es **nicht** darin.
Fünfundvierzig Sekunden später hat derselbe Server es eingespielt.

Beide Messungen stimmen. `apt-run all` frischt die Listen nicht auf — das ist
eine Entscheidung im Skript, weil das Auffrischen auf der Seite dem Knopf
„Jetzt nachsehen" folgt. Zwischen der Vorprüfung und dem Druck lag genau dieser
Knopf, und erst danach führte der Index `srvpanel 0.7.2~rc.3`.

**Die Anweisung daneben hätte Schaden angerichtet.** `docs/85` schreibt für den
Fall „steht nicht in der Liste" vor, den Zustand herzustellen — eine Fassung
zurückzusetzen. Wörtlich befolgt hätte der Lauf das Panel auf einer Maschine
zurückgerollt, auf der die neue Fassung schon bereitlag.

> **Eine Vorprüfung, die vor dem Schritt läuft, der ihren Gegenstand herstellt,
> misst den Zustand davor — und ihre Anweisung zeigt in die falsche Richtung.**

Die Prüfung gehört **hinter** „Jetzt nachsehen" und nicht davor. Ein Wächter
kann das nicht halten; es ist eine Reihenfolge in einer Vorschrift, und die
steht deshalb hier.

---

**Punkt 5d und Punkt 8b warten auf dasselbe Fenster.**

Nach dem Lauf sind **0** Aktualisierungen offen. Damit ersetzt die Seite die
ganze Knopfreihe durch „Es steht keine Aktualisierung an." — es gibt keinen
Knopf mehr, den man ein zweites Mal drücken könnte, weder gleich danach (5d)
noch während eines Laufs (8b).

Und 14 Sekunden sind auch für 8b zu kurz gewesen: Zwischen Druck und Bilanzzeile
lag kein Fenster, in dem ein zweiter Druck den ersten noch angetroffen hätte.
Das ist **kein** Befund am Panel — ein Lauf, der schnell fertig ist, ist ein
guter Lauf —, aber es heisst, dass beide Punkte eine neue Gelegenheit brauchen:
die nächste Fassung im Depot oder die beiden phasenverzögerten Pakete
(`libproc2-0`, `procps`), sobald Ubuntu sie freigibt.

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**
> Diesmal andersherum: Er wird auch nicht nach ihr gemessen.

---

### Punkt 10 — `apt-run panel` vergleicht die Fassung · **erfüllt**

Gemessen am 27. August 2026 auf `cloudsrv24`, in drei Teilen. Der mittlere ist
der, ohne den die `3` des ersten nur eine Zahl wäre.

**10a — das Kriterium.** `apt-run panel` frischt die Listen auf (sieben Quellen,
alle `OK`), findet nichts Neueres und fällt sein eigenes Urteil:

    srvpanel ist schon die neueste Version (0.7.2~rc.3).
    0 aktualisiert, 0 neu installiert, 0 zu entfernen und 2 nicht aktualisiert.
    apt-run: Der Lauf hat nichts verändert — Fassung vorher wie nachher: 0.7.2~rc.3.
    rc=3

Das ist der zweite der beiden zugelassenen Ausgänge. `docs/85` hat ihn
vorhergesehen: Kommt Punkt 5 zuerst, ist die Fassung aktuell — gefragt ist
nicht, ob aktualisiert wird, sondern ob **verglichen** wird.

**10b — die Gegenprobe, und sie ist der eigentliche Beleg.** Derselbe apt-Lauf
ohne den Vergleich, unmittelbar danach:

    0 aktualisiert, 0 neu installiert, 0 zu entfernen und 2 nicht aktualisiert.
    rc=0

**Zwei Läufe, dieselbe Ausgabe, entgegengesetzte Urteile.** Das ist M5 an seiner
vierten Stelle, nicht als Beschreibung, sondern als Messung: Der Rückgabewert
von apt sagt „gut" zu einem Lauf, der nichts bewirkt hat. Genau darauf hat
`panel.update` bis zum 26. August seine Erfolgsmeldung gestützt.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Hier steht die Null der Gegenprobe neben der Drei des Prüflings, und
> erst dadurch ist die Drei ein Unterschied und keine Behauptung.

**10c — der Weg, den der Betreiber wirklich geht.** `srvpanel update` setzt den
Lauf als transiente Unit ab und kehrt sofort zurück:

    Das Update läuft als srvpanel-update-7e8742ef.
    Unit gesehen: ja · Zyklen: 3

und `/var/log/srvpanel/update.log` endet mit derselben Bilanzzeile. Damit ist
belegt, dass das Urteil dort ankommt, wo es gelesen wird — und nicht nur dort,
wo es gefällt wird.

**Was 10c nicht zeigen kann, und warum das kein Mangel ist:** den
Rückgabewert. `--collect` räumt die Unit auch im Fehlerzustand ab, `systemctl
status` findet danach nichts mehr. Eben deshalb schreibt `apt-run` sein Urteil
als **Zeile** und verlässt sich nicht auf den Zustand der Unit — und eben
deshalb wird die `rc` in 10a gemessen, wo sie unmittelbar dasteht.

> **Ein Urteil, das nur im Zustand eines Prozesses steht, ist fort, sobald der
> Prozess fort ist.**

---

**Beobachtung 12 — `2 nicht aktualisiert` und `0 bleiben offen` sind beide
richtig.**

Punkt 5 endete mit *„0 bleiben offen"*, und apt sagt hier in derselben Stunde
*„2 nicht aktualisiert"*. Der Widerspruch ist keiner, und er war vorhergesagt:
Im Kopf von `apt-run` steht seit dem 26. August, dass die beiden Zahlen
verschiedene Fragen beantworten — apt zählt, was **dieser Aufruf** nicht
angefasst hat, `offen()` zählt, was ein `dist-upgrade` noch täte. Die Differenz
sind die zurückgehaltenen Pakete, hier die beiden phasenverzögerten
(`libproc2-0`, `procps`).

Vorhergesagt war es aus einer Messung im Container (142 gegen 140). Hier steht
dieselbe Aussage auf einem echten Server mit einer anderen Ursache — dort
Abhängigkeiten, hier Phasing.

> **Eine Erklärung, die nur den Fall deckt, an dem sie entstand, ist eine
> Beschreibung. Erst der zweite Fall macht sie zur Regel.**

---

**Beobachtung 13 — ich habe eine Beobachtung ein zweites Mal aufgeschrieben.**

Hier stand nach dem ersten Wurf eine „Beobachtung 13" über die
Schlüsselwarnung der Sury-PPA: eine `W:`-Zeile, die wie ein Fehlschlag
aussieht und keiner ist. Sie ist richtig — und sie steht seit dem
26. August in **Beobachtung 1** und ihrer Auflösung bei Punkt 3e, dort
sogar besser belegt, weil Vorgang 692 die Zeile führt und daneben „alle
Quellen erreicht" sagt.

Entstanden ist sie, weil ich die Ausgabe von Punkt 10 gelesen habe und
nicht das Dokument, an das ich sie anhänge. Genau so entstehen die zwei
Fassungen, an denen dieses Projekt seit P5 zahlt — und die zweite ist die,
die veraltet.

> **Wer an ein Protokoll anbaut, liest es vorher. Sonst ist die neue
> Beobachtung eine alte mit einer neuen Nummer.**

Was aus dem zweiten Wurf **bleibt**, gehört zu Beobachtung 1 und ist dort
eingetragen: dass der Anker im Quelltext `^[WE]: Failed to fetch` lautet
und nicht `^W:`. Beobachtung 1 hatte die Wirkung, nicht die Ursache.

---

### Punkt 12 — Aufräumen · **erfüllt**

Gemessen am 27. August 2026, in drei Teilen: hinsehen, abräumen, nachmessen.
Der dritte ist der, den die Vorschrift nicht hatte — Abräumen ist eine Änderung,
und jede Änderung ist ein neuer Anlass zu messen.

**Die Zahl ist ein Paar und keine Null.**

    vorher   4 Konfigurationsreste unter /etc
    nachher  2 — /etc/default/grub.ucf-dist, /etc/ssh/sshd_config.ucf-dist

**Vier wäre „ein Prüfkörper steht noch", null wäre „das Löschen war zu breit".**
Die zwei, die bleiben, sind dieselben, die in Punkt 6 belegt haben, dass der
Leser auch findet, was der Lauf nicht hingelegt hat. Deshalb ist über den vollen
Pfad gelöscht worden und über kein Muster: `rm *.ucf-dist` hätte genau den Beleg
mitgenommen, den Punkt 6 gebraucht hat.

**Zwei Prüfkörper waren schon fort, und das Abräumen sagt es.**

    war nicht da: /etc/apt/apt.conf.d/zzz-abnahme-a1
    war nicht da: /etc/srvpanel/abnahme-a1.conf

Beide hatten ihre eigenen Punkte zurückgenommen — Punkt 9 die Datei der
Automatik, Punkt 3e die umbenannte Quelle. Ein stilles `rm -f` hätte hier
dasselbe ausgegeben wie ein erfolgreiches Löschen, nämlich nichts.

> **Ein Aufräumschritt, der „war nicht da" sagt, unterscheidet „schon
> zurückgenommen" von „nie angelegt". Ein stiller sagt zu beidem nichts.**

**Der wirksame Zustand der Automatik steht wieder auf an** — `Enable "1"`,
`Update-Package-Lists "1"`, `Unattended-Upgrade "1"`, gefragt über `apt-config
dump` und nicht über die eigene Datei.

**Und das Auffrischen als Paar:**

    rc=0 · Failed to fetch: 0 · W: insgesamt: 1

Die Null bekommt ihre Bedeutung von der Eins daneben. `Failed to fetch: 0`
allein hiesse auch „gar nicht hingesehen"; mit der Warnzeile daneben heisst es
„gelesen und nichts gefunden". Die eine Zeile ist die Schlüsselwarnung der
Sury-PPA aus Beobachtung 1.

**`/run/reboot-required` fehlt, und das ist kein Rest.** Punkt 7b hatte die
Datei mit `touch` hergestellt, Punkt 7d sie entfernt; 12a bestätigt das einen
Tag später. Dass Punkt 5 sie nicht neu angelegt hat, passt zu seinen sechs
Paketen — `srvpanel` und vier Python-Pakete rechtfertigen keinen Neustart, und
`update-notifier-common` ist auf diesem Server installiert (belegt in Punkt 7d
durch den Trockenlauf seiner Entfernung). Die fehlende Datei heisst hier also
wirklich „kein Neustart nötig" und nicht „niemand schreibt sie".

---

**Befund 13 — der Dateifilter der Quellenliste ist richtig und von nichts
gehalten.**

In `/etc/apt/sources.list.d` liegt auf diesem Server eine fünfte Datei:

    ubuntu.sources.curtin.orig

Sie stammt vom Ubuntu-Installer und ist **keine Quelle** — apt liest in diesem
Verzeichnis nur `*.list` und `*.sources`. Der Leser des Panels tut dasselbe
(`glob(…'/*.{list,sources}', GLOB_BRACE)` in `SystemSourcesList`), und die
Rechnung geht auf: vier gelesene Dateien ergeben sieben Ziele, und genau sieben
`OK:`-Zeilen schreibt apt bei jedem Auffrischen dieses Laufs.

**Der Befund ist nicht, dass es falsch wäre — sondern dass nichts es hält.**
`SourceListTest` prüft zehn Fragen, alle über das *Zerlegen* einer Datei:
Stanzas, die drei Formen von `Signed-By`, Kommentare, `Enabled`. **Welche
Dateien überhaupt gelesen werden, prüft keine davon.** Der Ausdruck steht
alleinstehend in einer Ops-Datei, wo ihn kein Wächter erreicht.

Damit ist es dieselbe Fehlerklasse, die dieses Projekt seit P0 mindestens
sechsmal bezahlt hat: eine Angabe, die auf etwas verweist, ohne dass ein Typ,
ein Test oder ein Werkzeug den Bezug prüft. Ein `*` an dieser Stelle — beim
Aufräumen, beim Umbau, beim „ich will auch `.list.d`-Fragmente sehen" — meldete
dem Betreiber `ubuntu.sources.curtin.orig` als abgeschaltete Quelle, und
niemand würde rot.

**Bemerkenswert ist, wer den Prüfkörper stellt.** Diese Datei liegt seit der
Installation da; sie ist die Gegenprobe, die eine Regression sofort sichtbar
machen würde — nur sieht kein Test sie an. Derselbe Bau wie bei Beobachtung 1,
wo die Warnzeile der Sury-PPA die Gegenprobe für den Fehlschlag-Anker stellt,
und mit demselben Rest: Ein Prüfkörper, den nur der Server hat, ist in der CI
nicht da.

> **Ein Filter, der stimmt, und ein Filter, den etwas hält, sehen heute gleich
> aus — und morgen nicht mehr.**

**Gebaut am 27. August.** `Sources::files()` ist die Naht, `Sources::EXTENSIONS`
die einzige Stelle mit den Endungen, `SourceFileTest` der Wächter — mit acht
Prüfkörpern, die gegen echtes apt gemessen sind (2.8.3, eigenes
`Dir::Etc::sourceparts`): zwei gelesen, sechs **stumm** ignoriert, kein Wort auf
stderr. Fünf Eingriffe in `tests/waechter-brechen.sh`, alle beissend, einzeln
und im Lauf.

**Und der Wächter hat sich beim Brechen zweimal selbst berichtigt** — das ist
der Teil, der ohne die Brüche nicht passiert wäre.

Der erste Prüfkörper legte `sources.list` **in** `sources.list.d`. Die
Hauptdatei stand danach zweimal in der Liste, und das sah nach einem Fehler im
Leser aus; es war einer im Prüfkörper, denn auf keinem Server liegt sie in ihrem
eigenen Teilverzeichnis.

> **Ein Prüfkörper, der eine Lage herstellt, die es nicht geben kann, verlangt
> eine Änderung, die niemand braucht.**

Der zweite wog schwerer, weil er nur beim Brechen sichtbar wurde: Der Fall über
die Reihenfolge blieb **grün**, als das abschliessende `sort()` entfernt wurde.
`glob()` sortiert von sich aus, und ein Lauf je Endung ergibt `[alle .list][alle
.sources]` — mit `docker.list` neben `ubuntu.sources` war die falsche Folge
zufällig auch die richtige. Erst `zz-docker.list` lässt die beiden Fassungen
auseinandergehen.

> **Ein Prüfkörper, der im Fehlerfall dasselbe zeigt wie im Erfolgsfall, misst
> nicht.**

Zweimal derselbe Satz an einem Wächter, den ich gerade als Antwort auf einen
Befund gebaut habe. Ein Wächter, den man nicht bricht, ist eine Behauptung —
hier wären es zwei gewesen.

**Nebenbei fiel `GLOB_BRACE` weg**: Gesucht wird je Endung einmal, damit die
Konstante die einzige Stelle bleibt. Die Fahne hat PHP nicht auf jeder Bauart,
und wo sie fehlt, gibt `glob()` `false` zurück — daraus wäre hier lautlos „gar
keine Quelle" geworden.

---

### Punkt 11a — Bilder, erster Teil: `/updates` · **erfüllt**

Gefahren am 27. August gegen `0.7.2-rc.3`, mit `tests/bilder-messen.js`, vier
Lagen je Ansicht.

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| 390 hell | 0 | **200/200** | 0 | 0 | 6 |
| 390 dunkel | 0 | **200/200** | 0 | 0 | 6 |
| 1440 hell | 0 | **200/200** | 0 | 1 · 480px | 0 |
| 1440 dunkel | 0 | **200/200** | 0 | 1 · 480px | 0 |

**Die Gegenprobe schlägt in allen vier Lagen mit genau 200 aus.** Erst dadurch
sind die Nullen daneben Messungen; ohne sie sähe eine Seite, die nicht schiebt,
genauso aus wie ein Messmittel, das nichts misst. `stand` überall `2026-08-21` —
also kein alter Aufsatz aus der Zwischenablage —, und `thema` trennt `light` von
`dark`, womit auch die Falle aus A5 ausgeschlossen ist.

**Der eine Roller ist benannt und nicht bloss gezählt.** Bei 1440 px:

    480px  div > div.frame > main.content > div.sections:3 > section.section:2
           > div.scrolls:2
    <div class="scrolls"><table class="stacks"><thead><tr><th>Datei</th>
    <th>Zustand</th><th>Adresse</th><th>Suiten</th><th>S…

Das ist die **Quellenliste**, nicht die Paketliste, und der Behälter ist der
dafür vorgesehene. `app.css` nimmt den Fall wörtlich vorweg: `.stacks` wirkt
erst unter 720 px, darüber ist die Tabelle eine Tabelle, „und eine Tabelle mit
sechs Spalten will auch auf 1024 px rollen können statt sich zu quetschen".
Die Breite ist durch den Inhalt begrenzt — eine Adresse hat eine natürliche
Länge.

**Nachgesehen wurde er trotzdem, und aus einem Grund.** `rollt` heisst „darf",
nicht „ist in Ordnung", und in P5c hat genau diese Kategorie ein echtes Loch
verdeckt: eine bei 512 Zeichen gekürzte Textzelle machte den Inhalt 5710 px
breit, und die Messung war grün.

> **Eine Zelle, die rollen darf, hat keine Obergrenze — sie hat nur keine Zahl,
> die sich beschwert.**

**Die Asymmetrie zwischen den Breiten ist die erwartete.** Bei 390 px gibt es
die breite Tabelle nicht — dort steht die Kärtchenform aus `docs/24`, die nicht
rollt; dieselbe Ursache trägt `versteckt 6` gegen `0`, denn die Kärtchen führen
ihre Spaltenbeschriftungen für die Vorlesesoftware mit.

**Im Bild bei 390 px:** die Phasing-Meldung mit beiden Namen und dem Grund, die
Conffile-Zeile mit zwei vollen Pfaden, beide sauber umbrochen — `grub.ucf-` /
`dist` mitten in der Kennung, also die Regel aus `docs/46 §20.11` in ihrer
dritten Fassung. Dazu „Kein Neustart nötig" grün, einen Tag nach Punkt 7d.

**Was in dieser Runde noch aussteht:** `/logs` mit der Quelle
„Aktualisierungen installieren" und der Bestätigungsdialog aus 7c.

---

**Beobachtung 14 — der rote Fehler in der Konsole gehört nicht zum Panel.**

In drei der vier Aufnahmen stand ein `Uncaught Error` neben den Issues:

    Uncaught Error: Search engine null is not supported.
        getSearchEngineAnalyzer @ searchAnalyzer.js:2

`searchAnalyzer.js` kommt in diesem Repo **nirgends** vor — nicht in `.js`,
`.ts`, `.vue` oder `.php` —, und die Konsole führt die Datei mit dem Zeichen für
eine Erweiterung. Es ist eine Browsererweiterung dieses Rechners und keine Zeile
des Panels.

**Aufgeschrieben wird es trotzdem**, weil ein roter Fehler auf einem Bild eines
Abnahmelaufs sonst beim nächsten Durchsehen wieder verfolgt wird. Und mit einer
Grenze: Das gilt für **diesen** Browser. Ein Fehler, den eine Erweiterung wirft,
sagt über das Panel nichts — in beide Richtungen.

> **Ein Bild aus einem fremden Browser trägt dessen Erweiterungen mit ins
> Protokoll.**

---

**Der Zustand hat sich seit Punkt 12 geändert, und das öffnet zwei Punkte
wieder.**

Nach Punkt 5 stand `AKTUALISIERBAR` auf 0; bei dieser Runde sind es **5**, alle
aus `noble-security`. Damit ist die Paketliste samt Knopfreihe fotografierbar —
11a wird also vollständig und braucht kein 11b — und **5d und 8b sind zum ersten
Mal seit dem Upgrade messbar**.

> **Ein Prüfkörper, der eine Haltbarkeit hat, wird nicht vor ihr hergestellt.**
> Diesmal ist er von selbst gekommen, und die Reihenfolge hängt daran: Wer
> installiert, schliesst das Fenster wieder.

---

### Punkt 11a, zweiter Teil: `/logs` mit der Quelle „Aktualisierungen installieren" · **erfüllt**

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| 390 hell | 0 | **200/200** | 0 | 1 · 850px | 0 |
| 390 dunkel | 0 | **200/200** | 0 | 1 · 850px | 0 |
| 1440 hell | 0 | **200/200** | 0 | 1 · 68px | 0 |
| 1440 dunkel | 0 | **200/200** | 0 | 1 · 68px | 0 |

Der Roller ist in allen vier Lagen derselbe:

    div > div.frame > main.content > div.sections:2 > section.section:2 > pre.output

**850 px bei 390 px sind eine getroffene Entscheidung und kein Rest.** Der
scoped Block von `Logs/Index.vue` schreibt sie aus, und zwar mit ihrem Grund:
Eine umgebrochene Zeile eines Protokolls ist unlesbar, weil man nicht mehr
erkennt, wo ein Eintrag anfängt. `overflow: auto` und `white-space: pre` stehen
dort ausdrücklich, wortgleich noch einmal in `Domains/Logs.vue`.

**Und das ist der Grund, warum diese Zeile hier steht.** In `app.css` hat
`.output` weder `overflow-x` noch `white-space`; die einzige Umbruchregel dort
ist `.output > div`, und die erreicht ein `<pre>` mit blossem Text nicht. Wer
nur das gemeinsame Stylesheet liest, hält das waagerechte Rollen für einen
Nebeneffekt von `overflow-y: auto` — und meldet einen Befund, wo eine
Entscheidung steht.

> **Eine Regel, die im Bauteil steht, fehlt im gemeinsamen Stylesheet nicht —
> sie steht woanders.**

**Der Kopf der Seite belegt nebenbei Punkt 5.**

    /var/log/srvpanel/upgrade.log · 7 KB · zuletzt 2026-08-27 11:09:29

7 KB ist der Stand, den der Beobachter am Ende von Punkt 5 gemessen hat (6965
Bytes), und `11:09:29` ist dessen letzte Sekunde. Die Seite zeigt die Datei **von
vorn** — sichtbar ist der frühere Lauf über vier Pakete, der Lauf aus Punkt 5
steht darunter. Damit ist die Hälfte nachgeholt, die Punkt 5 offengelassen hat:
Das Protokoll ist nicht nur als Datei vollständig, sondern auch über die Seite.
`lines=200` bei rund hundert Zeilen schneidet nichts ab.

---

### Punkt 11a, dritter Teil: das Ende des Protokolls und der Dialog · **erfüllt**

**Die Bilanzzeile steht im Bild.** Das Ende von `/logs` zeigt den Lauf aus
Punkt 5 bis zur letzten Zeile:

    libpython3.12t64:amd64 (3.12.3-1ubuntu0.16) wird eingerichtet ...
    Trigger für systemd, man-db, libc-bin werden verarbeitet ...
    Running kernel seems to be up-to-date.
    Restarting services... · systemctl restart fail2ban.service
    apt-run: 6 von 6 Aktualisierungen eingespielt, 0 bleiben offen.

Damit ist die dritte Erwartung von Punkt 5 auch über die Seite belegt und nicht
nur an der Datei: Der Teil **vor** dem Neustart, der Neustart selbst und die
Bilanzzeile danach stehen zusammenhängend in einer Ansicht.

**Der Bestätigungsdialog aus 7c**, vier Lagen:

| Lage | `dokument` | `gegenprobe` | `schiebt` | `rollt` | `versteckt` |
|---|---|---|---|---|---|
| 390 hell | 0 | **200/200** | 0 | 0 | 7 |
| 390 dunkel | 0 | **200/200** | 0 | 0 | 7 |
| 1440 hell | 0 | **200/200** | 0 | 1 · 480px | 1 |
| 1440 dunkel | 0 | **200/200** | 0 | 1 · 480px | 1 |

Der Roller ist wieder die Quellenliste unter dem Dialog, also derselbe wie im
ersten Teil.

**Und Befund 5 hält.** Bei 390 px stehen Feld, „Neustart auslösen" und
„Abbrechen" auf **derselben** Breite — der Deckel `max-width: 32ch` fällt unter
480 px weg, und die ausgefranste Spalte von damals ist fort. Der Knopf ist grau,
solange das Feld leer ist; der Text nennt, was der Neustart mitnimmt, die
60 Sekunden und den Griff, mit dem man ihn in dieser Zeit noch stoppt.

**Damit ist Punkt 11a vollständig** — die Paketliste eingeschlossen, weil der
Server zwischenzeitlich wieder fünf offene Aktualisierungen hatte. Ein 11b
entfällt.

---

**Befund 14 — die Fusszeile von `/logs` nennt ein Fenster, das es nicht gibt,
und bietet Zeilen an, die es nicht gibt.**

Gemessen an `upgrade.log` mit **118** Zeilen:

    118 Zeilen · gelesen wurden die letzten 500 Zeilen
    [ Mehr Zeilen (200 → 400) ]

**Beide Angaben sind falsch, und beide aus demselben Grund: Die Seite weiss
nicht, wie viele Zeilen die Datei hat.**

`window` ist keine Messung, sondern die Konstante `SystemLogsTail::MAX_LINES`.
Der Agent liest immer ein Fenster von 500 Zeilen vom Ende her und sendet die
500 unverändert mit — auch dann, wenn die Datei 118 Zeilen lang ist und das
Fenster nie voll war. Der Satz behauptet damit einen Ausschnitt, wo der Leser
das Ganze vor sich hat.

> **Eine Grenze, die als Zahl mitgesendet wird, ohne dass jemand nachsieht, ob
> sie erreicht wurde, ist eine Behauptung über die Datei und keine über den
> Lauf.**

Der Knopf hat dieselbe Wurzel: Er steht unter `props.lines < 500` und fragt
nicht, ob es mehr zu holen gibt. Bei 118 Zeilen und einem Deckel von 200 sind
bereits alle da; `mehr()` verdoppelt auf 400, der Agent liefert dieselben 118,
und der Knopf heisst danach „Mehr Zeilen (400 → 500)". Dreimal drücken,
dreimal nichts.

> **Ein Knopf, der etwas anbietet, was es nicht gibt, ist schlimmer als keiner
> — er sagt dem Leser, dass ihm etwas fehlt.**

**Warum die naheliegende Ableitung nicht reicht.** „`matched < window` heisst,
das Fenster war nicht voll" gilt **nur ohne Filter**: Mit Filter ist `matched`
die Zahl der Treffer und nicht die der gelesenen Zeilen, und zwölf Treffer aus
vollen 500 sähen genauso aus wie zwölf aus einer Datei mit zwölf Zeilen. Der
Agent muss also mitsenden, wie viele Zeilen er **tatsächlich** gelesen hat; erst
daran hängen beide Entscheidungen.

**Nicht gebaut.** Der Befund gehört zu A5 und nicht zu A1 — gefunden hat ihn die
Bilderrunde dieses Laufs, und das ist der Grund, dass sie nicht nur aus Zahlen
besteht.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

---

### Punkt 8b — Der eigene Lauf weist den zweiten ab · **erfüllt**

Gemessen am 27. August 2026, 20:50 Uhr, mit drei Klicks aus drei Tabs und einem
Beobachter daneben.

    ══ VORHER ══ 20:50:09 · Protokoll 6965 Bytes · offen 5
    20:50:11  units=0  log=6965
    20:50:40  units=1  log=6965
    20:50:49  units=0  log=9709
    ══ NACHHER ══ 20:53:12 · Protokoll 9709 Bytes (+2744) · offen 0
    ══ HÖCHSTZAHL GLEICHZEITIGER UNITS: 1 ══   (erwartet: 1)

**Beide Hälften des Kriteriums stehen da, und sie sind zweierlei.**

Die erste ist die Meldung. Vorgang **705** (20:50:41) und **706** (20:50:44),
beide `fehlgeschlagen`:

    Es läuft bereits ein Lauf des Panels, der Pakete anfasst
    (srvpanel-update-291ffeda.service). Der nächste Versuch geht erst,
    wenn er fertig ist.

**Sie nennt die Unit und nicht eine PID**, und darauf kommt es an: `AptLock` hat
zwei Zweige, und getroffen hat der auf den **eigenen laufenden Lauf** — nicht
der auf die dpkg-Sperre, den Punkt 8a erzeugt hat. Zweimal dieselbe Unit, also
kein Zufall.

Die zweite ist die Zahl. **Höchstens eine Unit zu jedem Zeitpunkt**, über 180
Sekunden im Sekundentakt abgetastet, obwohl dreimal gedrückt wurde. Der
Zeitverlauf passt lückenlos: Die Unit erscheint um 20:50:40, die beiden
abgewiesenen Drücke liegen um 20:50:41 und 20:50:44 mitten in ihrer Lebenszeit,
und um 20:50:49 ist sie fort.

> **Eine Meldung sagt, dass das Panel abgelehnt hat. Sie sagt nicht, dass nichts
> entstanden ist.** Das ist eine zweite Frage und braucht eine zweite Messung.

**Vorgang 704 ist dabei kein Widerspruch.** Er steht auf `fertig` nach drei
Sekunden (20:50:37 → 20:50:40), weil er den Lauf **absetzt** und nicht ausführt;
die Unit lebte danach noch acht Sekunden weiter. Genau deshalb ist die Frage von
`AptLock` an `systemctl` gestellt und nicht an die Vorgangstabelle — dort stünde
704 längst auf „fertig", während apt noch arbeitet.

---

**Beobachtung 15 — Punkt 5 ist ein zweites Mal belegt, auf einem anderen Weg.**

Im Protokoll dieses Laufs steht:

    Restarting services...
      systemctl restart php8.3-fpm.service php8.4-fpm.service
      postgresql@16-main.service srvpanel-agentd.service
      srvpanel-metrics.service srvpanel-web.service srvpanel-worker.service
    ...
    apt-run: 5 von 5 Aktualisierungen eingespielt, 0 bleiben offen.

**`needrestart` hat `srvpanel-worker` und `srvpanel-agentd` neu gestartet — und
danach hat der Lauf noch seine Bilanzzeile geschrieben.**

Das ist derselbe Nachweis wie in Punkt 5 und doch ein anderer. Dort steckte
`srvpanel` selbst im Lauf, und der Neustart kam aus seinem eigenen
postinst-Skript. Hier wurden **fünf Perl-Pakete** eingespielt, `srvpanel` war
gar nicht dabei — `needrestart` hat die Dienste neu gestartet, weil sich
Bibliotheken unter ihnen geändert haben, und der abgesetzte Lauf hat auch das
überlebt. Diesmal traf es zusätzlich den **Agenten**.

> **Ein Beleg, der zweimal auf verschiedenen Wegen entsteht, ist keine
> Wiederholung — der zweite schliesst aus, dass der erste an seinem Weg hing.**

Gesucht war das nicht. Es fiel aus einem Lauf heraus, dessen Zweck ein ganz
anderer war.

---

**Punkt 5d bleibt offen, und das Fenster ist wieder zu.** Der dritte Klick kam
drei Sekunden nach dem zweiten und war damit ein zweites 8b statt eines 5d; der
Lauf war um 20:50:49 fertig, und seitdem steht `offen` auf 0. Es braucht eine
Seite aus dem Verlauf, deren Knopfreihe noch steht — oder den Weg über die
Kommandozeile, mit der Einschränkung, dass er das Skript misst und nicht den
Knopf.

<!-- Wird während des Laufs weitergefüllt. -->
