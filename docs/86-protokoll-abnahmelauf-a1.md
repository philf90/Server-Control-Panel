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

`srvpanel-agentd.service` trägt `PrivateTmp`, `RestrictNamespaces`,
`ProtectKernelTunables` und `ProtectControlGroups` — jede davon legt einen
Mount-Namensraum an. Ubuntu entscheidet über das Zurückhalten eines Pakets
(*Phasing*) anhand einer Kennung dieser Maschine; sieht apt sie nicht, hält es
**nichts** zurück und bietet alles an. Welche der Eigenschaften es ist und
welche Datei dabei fehlt, ist **noch nicht gemessen**.

> **Eine Härtung, die einem Programm eine Auskunft über die Maschine nimmt,
> ändert seine Antwort — nicht seine Fehlermeldung.**

Die Wirkung für den Betreiber steht dagegen fest: Er sieht elf Zeilen, drückt
„Alle installieren", bekommt „fertig" — und sieben davon stehen beim nächsten
Aufruf unverändert wieder da.

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

<!-- Wird während des Laufs weitergefüllt. -->
