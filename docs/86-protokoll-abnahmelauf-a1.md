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

<!-- Wird während des Laufs weitergefüllt. -->
