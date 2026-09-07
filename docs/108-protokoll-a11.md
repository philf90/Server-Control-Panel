# A11 — das Protokoll des Abnahmelaufs

Gefahren am **7. September 2026** auf `cloudsrv24`, gegen `0.7.3-rc.24` bis
`0.7.3-rc.26`. Der Plan ist `docs/106`, der Lauf `docs/107`, die Messrunde davor
`docs/81 §2.3r`.

**Der Lauf ist dreimal angesetzt worden**, und die beiden Abbrüche sind das
Wertvollste an ihm: Er hat einen Fehler aus P6 gefunden, der ein Jahr lang
jedem Kunden mit einem nächtlichen Cronjob eine falsche Uhrzeit gezeigt hat.

---

## 1 · Der Ausgangszustand

Vorflug um 08:29 CEST, vor jedem Eingriff — `docs/107 §0b` verlangt ihn, weil
Punkt 3 den Zeitabgleich abschaltet und Punkt 4 einen Dienst maskiert.

    Timezone=Europe/Berlin
    LocalRTC=no
    CanNTP=yes
    NTP=yes
    NTPSynchronized=yes
    TimeUSec=Mon 2026-09-07 08:29:20 CEST
    RTCTimeUSec=Mon 2026-09-07 08:29:20 CEST

`/etc/localtime` → `/usr/share/zoneinfo/Europe/Berlin`; `systemd-timesyncd`
enabled und active; `systemd-timedated` static und beim Lesen frisch gestartet.
`Names::fqdn()` gibt `'cloudsrv24.de'`.

**Drei Dinge standen damit fest, bevor die Seite aufgemacht wurde.**

**`NTPSynchronized=yes` ist hier.** `docs/81 §2.3r` führt den Zustand
ausdrücklich als „hergeleitet und nicht gemessen" — der Container erreicht
keinen Zeitserver. „Uhr abgeglichen: **ja**" ist damit zum ersten Mal wirklich
gemessen.

**Der Server hat einen Schlüssel mehr als der Container.** Die Messrunde sah
sechs, hier sind es sieben: `RTCTimeUSec` kommt dazu. Folgenlos, weil der Leser
nach Schlüssel liest — ein Leser, der die dritte Zeile nähme, hätte hier schon
danebengegriffen.

> **Ein Prüfkörper aus einer Umgebung nennt die Felder dieser Umgebung — dass
> es woanders mehr sind, sagt er nicht.**

---

## 2 · Was der Lauf gefunden hat, bevor er anfing

Ausführlich in `docs/107 §0c` und `§0d`; hier die Kette.

**Erster Anlauf, gegen `rc.24`.** Zwei Zeilen der neuen Seite standen auf
`nicht feststellbar`, während der Rest richtig war. Die Konsole sagte für
dieselbe Frage `Europe/Berlin`.

Gefunden hat es die Gegenprobe, die Punkt 1 vorschreibt — und meine eigene
Vorarbeit war der Fehler: gemessen über `srvpanel tinker`, also als root ohne
`open_basedir`.

> **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den falschen
> Weg.**

**Und getroffen hat es nicht A11, sondern die Cronseite.** Für einen Job „jeden
Tag um 03:15" stand die nächste Fälligkeit als **05:15** — seit es Cronjobs
gibt.

**Zweiter Anlauf, gegen `rc.25`.** Die Ursache war behoben, der falsche Wert
stand weiter da: `next_due` war eine Spalte, geschrieben beim Anlegen und
Ändern und sonst nie.

**Dritter Anlauf, gegen `rc.26`.** `03:15` im Zeitplan, `2026-09-08 03:15:00`
als Fälligkeit.

---

## 3 · Die acht Punkte

### Punkt 1 · Der Bereich steht da — **erfüllt**

| Zeile | gemessen |
|---|---|
| Zeitzone des Servers | `Europe/Berlin — CEST (UTC+02:00)` |
| Zeitabgleich | `eingeschaltet` |
| Uhr abgeglichen | `ja` |
| Hardware-Uhr | `UTC` |

Name **und** Beschriftung, wie `docs/106 §4` verlangt.

**Die Gegenprobe durch die Schranke** — nicht über `srvpanel tinker`, aus dem
Grund in §2:

    php -d open_basedir="$(…fpm.conf…)" -r '…ServerZone::name();'
    → string(13) "Europe/Berlin"

Und dieselbe Zone nennt die Cronseite über ihrer Jobliste. **Es gibt eine
Antwort auf „in welcher Zone steht dieser Server" und nicht zwei.**

### Punkt 2 · Die Brücke — **erfüllt**

Gemessen mit einer Anzeigezone, die **nicht** die des Servers ist; mit gleicher
Zone zeigten beide Zeilen dieselbe Zahl und der Punkt hätte nichts gemessen.

| | Anzeigezone `Europe/Berlin` | Anzeigezone `Asia/Kolkata` |
|---|---|---|
| Jetzt auf dem Server | `2026-09-07 12:21` | `2026-09-07 12:26` |
| Dasselbe in der Anzeigezeit | `12:21 CEST (UTC+02:00)` | `15:56 IST (UTC+05:30)` |

12:26 + 3:30 = 15:56 — genau der Versatz. **Und beide Zeilen auf die Minute
genau**: Der Befund der Bilderrunde (oben `H:i`, unten `H:i:s`) ist damit auf
dem Server als behoben belegt.

### Punkt 3 · NTP in seinen Zuständen — **erfüllt** *(Ausschluss)*

| Eingriff | Seite sagt |
|---|---|
| `timedatectl set-ntp false` | `ausgeschaltet` |
| `timedatectl set-ntp true` | `eingeschaltet` |

Zwei verschiedene Sätze, keiner davon „nicht feststellbar".

**Der dritte Zustand fällt aus und wird benannt:** „kein Zeitdienst installiert"
hiesse, `systemd-timesyncd` von einem laufenden Server zu entfernen — ein
Eingriff und keine Messung. Er ist in der Messrunde gegen echtes systemd 255
belegt (`docs/81 §2.3r` M8) und hier nicht wiederholt.

**Und der Punkt hat einen Fund am Messmittel gebracht.** Die Konsole
widersprach der Seite: `set-ntp false` und unmittelbar danach `show` gab
`NTP=yes`, während die Seite `ausgeschaltet` zeigte. Die dritte Messung hat es
entschieden:

    timedatectl set-ntp false; timedatectl show -p NTP  → NTP=yes
    sleep 2;                   timedatectl show -p NTP  → NTP=no

> **Ein `show` unmittelbar nach einem `set` misst den Übergang und nicht den
> Zustand.**

Derselbe Satz wie am 4. September bei `srvpanel.target`, an einem anderen
Werkzeug. Beide Seitenmessungen waren richtig, beide Konsolenwerte veraltet.

> **Zwei Messungen, die auseinandergehen, entscheidet keine Überlegung, sondern
> die dritte.**

### Punkt 4 · Nicht feststellbar — **erfüllt** *(Ausschluss)*

**Der Weg war ungemessen und ist zuerst geprüft worden**, wie `docs/107 §4` es
vorschreibt:

    systemctl mask systemd-timedated
    systemctl stop systemd-timedated
    timedatectl show; echo "rc=$?"
    → Failed to parse bus message: Operation not possible due to RF-kill
    → rc=1

`rc != 0`, der Weg trägt. Die Seite danach:

| Zeile | gemessen |
|---|---|
| Zeitabgleich | `nicht feststellbar` |
| Uhr abgeglichen | `nicht feststellbar` |
| Hardware-Uhr | `nicht feststellbar` |
| **Zeitzone des Servers** | **`Europe/Berlin — CEST (UTC+02:00)`** |
| Jetzt auf dem Server | `2026-09-07 13:54` |

**Die letzten beiden Zeilen sind die angesagte Ausnahme und der eigentliche
Beleg.** Die Zone kommt aus `ServerZone` und nicht aus `timedatectl`; sie ist
von dessen Ausfall nicht betroffen. Wäre sie aus dem Agenten gekommen — wie
`docs/106 §5` es ursprünglich vorsah —, stünde dort jetzt ebenfalls „nicht
feststellbar", und der Betreiber wüsste nicht mehr, in welcher Zone seine
Cronjobs laufen, bloss weil ein Dienst maskiert ist.

**Und der Wortlaut ist ein Fund für sich.** „Operation not possible due to
RF-kill" auf einem Server ohne Funk. Er erschien exakt mit der Maskierung; die
genaue Zuordnung im systemd-Quelltext ist ungemessen und muss es nicht sein.

> **Ein Wortlaut, den ein Werkzeug für einen Zustand wählt, muss mit dem
> Zustand nichts zu tun haben — „RF-kill" für einen maskierten Dienst ist wahr
> und unbrauchbar.**

Genau dieser Satz wäre auf der Seite gelandet, hätte A11 den Wortlaut
durchgereicht statt einer geschlossenen Grundmenge. Das ist die nachträgliche
Bestätigung von Entscheidung 2 aus `docs/106 §2`.

### Punkt 5 · Der Rechnername — **erfüllt**

`Names::fqdn()` gibt `'cloudsrv24.de'`, die Seite zeigt es, und der Satz
darunter sagt sichtbar, warum sich der Name hier nicht ändern lässt. Der Fall
„kein vollständiger Name" — im Container gemessen — kommt hier nicht vor und
ist der triviale von beiden.

### Punkt 6 · Die Tür — **erfüllt**

**Berichtigt vor dem Lauf** (`docs/107 §0`): Das Kriterium lautete „ein
Administrator bekommt 403, der Betreiber nicht", und `/settings/general` gehört
seit A9 dem Administrator.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Gemessen wurde, was die Tür wirklich zusagt:

| Konto | `/settings/general` |
|---|---|
| Rolle **Administrator** | **200**, Bereich „Zeit des Servers" vollständig |
| **Kundenkonto** | **403 „Kein Zutritt"** |

**Und das Menü ist der zweite Beleg.** Beim Administrator steht unter
„Einstellungen" **nur** „Allgemein" — kein Zertifikat, kein Mailversand, kein
DNS-Zugang, keine Konten, kein Wartungsmodus, keine Logs. Beim Kunden fehlt der
Punkt ganz. Das ist `AbilityReachTest` in der Wirkung: Ein Knopf, den der
Betrachter nicht drücken darf, wird gar nicht erst gezeigt.

Die 403-Seite ist dabei die **entworfene** aus A9 und nicht Laravels englische
Vorgabe — ein Befund aus `docs/84`, hier nebenbei nachgemessen.

### Punkt 8 · Kosten — **erfüllt**

    0,046 s   ← erster Lauf
    0,006 s
    0,006 s
    0,005 s
    0,005 s

Der erste ist achtmal so teuer: `systemd-timedated` ist `static` und wird vom
ersten Aufruf über D-Bus gestartet.

> **Eine Messung, die man nur einmal fährt, misst den Zwischenspeicher mit —
> und ob sie ihn kalt oder warm erwischt, sagt sie nicht.**

Und die Erwartung war zu pessimistisch: `docs/106 §7` sagte 10–12 ms, gemessen
sind **5–6 ms**. Die Zahl stammte aus dem Container.

> **Eine Erwartung aus einer Messung unter anderen Bedingungen ist eine
> Vermutung, auch wenn sie aus einer Messung stammt.**

Kein Befund — die Erwartung stand als Grössenordnung da.

**Und die Messvorschrift selbst war falsch.** Sie verlangte `/usr/bin/time`;
das ist ein eigenes Paket und auf einem Debian- oder Ubuntu-Server nicht im
Grundbestand. Fünfmal `No such file or directory`.

> **Eine Messvorschrift, die ein Werkzeug voraussetzt, das der Server nicht
> hat, misst nicht — sie meldet einen Fehler an sich selbst.**

Gemessen wurde dann mit dem `time` von bash.

---

## 4 · Der Prüfstand ist abgeräumt

Belegt um 13:56, Zeile für Zeile gegen den Vorflug:

| | Vorflug 08:29 | nachher 13:56 |
|---|---|---|
| `Timezone` | `Europe/Berlin` | `Europe/Berlin` |
| `LocalRTC` | `no` | `no` |
| `CanNTP` | `yes` | `yes` |
| `NTP` | `yes` | `yes` |
| `NTPSynchronized` | `yes` | `yes` |

`rc=0`, die Maskierung entfernt, die Anzeigezone zurück auf `Europe/Berlin`.
