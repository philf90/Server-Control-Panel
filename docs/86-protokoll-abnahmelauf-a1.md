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

<!-- Wird während des Laufs weitergefüllt. -->
