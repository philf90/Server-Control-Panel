# 59 — Protokoll der Zwischenabnahme des SFTP-Zugangs

Der Lauf nach `docs/58`, auf `cloudsrv24`, gegen `v0.6.0-rc.10`.

**Dieses Dokument entsteht während des Laufs.** Was hier steht, ist gemessen;
was noch nicht gemessen ist, steht als offen da und nicht als erwartet. Ein
Protokoll, das im Voraus geschrieben wird, hält fest, was jemand erwartet hat.

| | |
|---|---|
| Datum | 16. August 2026 |
| Fassung | `v0.6.0-rc.10` |
| Stand auf `main` | `7ff3096` (PR #138) |
| Gefahren von | Betreiber auf `cloudsrv24`; Auswertung hier |

---

## 0. Der zweite Weg hinein

| | Zustand |
|---|---|
| 0a Konsole beim Anbieter offen **und benutzt** | **erledigt** |
| 0b zweite root-Sitzung offen | **erledigt** |
| 0c `sshd_config` gesichert, Prüfsumme notiert | **erledigt** |

**0a:** netcup-Konsole („Bildschirm"), `cloudsrv24 tty1`, als root angemeldet,
`asd` getippt, `-bash: asd: command not found` gesehen. Ein lokaler Bildschirm
und keine SSH-Sitzung — also ein Weg hinein, der nicht durch die Tür führt, die
dieser Lauf zusperren kann. Ubuntu 24.04.4 LTS, Kernel 6.8.0-137-generic,
Konsolenzeit Mo 17. Aug 09:00 CEST 2026.

**0b:** Eine zweite root-Sitzung über SSH ist offen und bleibt es (Angabe des
Betreibers).

**0c, gemessen am 16. August 2026 auf `cloudsrv24`:**

```
sha256sum /root/sshd_config.vor-p6 /etc/ssh/sshd_config
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /root/sshd_config.vor-p6
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /etc/ssh/sshd_config
```

Beide gleich — die Sicherung ist treu, und der Wert ist der Bezug für Punkt 3
und Punkt 8. **Er ist nach dem Einspielen des Pakets genommen und nicht davor**
(siehe Abweichung unten); für die Frage, die Punkt 3 stellt — schreibt das
blosse Ansehen der Seite in die Datei? — ist genau das der richtige Zeitpunkt.

**0a und 0b sind nicht belegt.** Die Aufnahme zeigt eine Sitzung mit einer
root-Eingabeaufforderung; ob es die Konsole des Anbieters oder eine
SSH-Sitzung ist, geht daraus nicht hervor, und ob eine **zweite** Sitzung offen
bleibt, ebenfalls nicht. Nachgefragt statt angenommen — der ganze Punkt 0
besteht daraus, das nicht zu tun.

### Ein Befund aus Punkt 0, den niemand gesucht hat

Die Konsolenaufnahme zeigt im Kundenbereich den Hinweis **„Neustart
erforderlich"**. Das ist für diesen Lauf keine Nebensache:

| | |
|---|---|
| kaputte Datei + **Reload** | der sshd terminiert (`docs/57 §5`) |
| kaputte Datei + **Neustart** | der Dienst kommt gar nicht hoch (`docs/38`, M17) |

Punkt 8 stellt absichtlich für einen Augenblick einen Zustand her, in dem
`sshd_config` eine unsinnige Zeile trägt. Ein Neustart in genau diesem Fenster
— ausgelöst von einem Paketlauf, einem Wartungsfenster des Anbieters oder
versehentlich — macht aus einem geprüften Zustand einen Ausschluss.

> **Ein ausstehender Neustart ist eine geladene Waffe, die auf den Zeitpunkt
> wartet, an dem jemand anders abdrückt.**

**Auflage für den Rest des Laufs:** kein Neustart, und vor jedem Neustart, der
sich nicht vermeiden lässt, muss `sshd -t` still sein.

---

## Abweichung vom geschriebenen Lauf

`docs/58` Punkt 1 heisst „vor dem Update"; das Paket `v0.6.0-rc.10` war beim
Beginn dieses Laufs bereits eingespielt. Für die Messrunde ist das folgenlos —
sie misst OpenSSH auf dieser Maschine und nicht das Panel. Für 0c heisst es:
Der Bezugswert ist „nach dem Einspielen, **vor der ersten Benutzung**".

Festgehalten statt geradegebogen.

## 1. Die Messrunde vor dem Update

**Befund 1 — der Lauf verlangt ein Werkzeug, das die Auslieferung nicht
mitbringt.** `/opt/srvpanel/current` enthält `agent`, `app`, `artisan`,
`bootstrap`, `config`, `database`, `lang`, `public`, `resources`, `routes`,
`storage`, `vendor` — **kein `tests/`**. Das Paket liefert die Anwendung aus
und nicht die Testsuite, und das ist richtig so.

`docs/58` Punkt 1 lautet `sudo bash tests/sftp-messen.sh`, ausgeführt im
Installationsverzeichnis. Der Schritt war damit **nie fahrbar** — nicht
„gescheitert", sondern von Anfang an unausführbar, und niemandem ist es
aufgefallen, weil ihn bis heute niemand ausgeführt hat.

> **Ein Abnahmelauf, der ein Werkzeug voraussetzt, das die Auslieferung nicht
> enthält, ist an dieser Stelle nicht gefahren worden — er war nie fahrbar.**

Dasselbe Verhältnis wie in `docs/45`, `docs/47` und `docs/48`: Die Mehrheit der
Fehler steckt im Prüfmittel und nicht im Prüfling.

**Behoben für diesen Lauf** durch Holen des Skripts aus dem öffentlichen Repo;
das Skript hängt an keinem Pfad des Repos und läuft von überall.
`docs/58` bekommt den Schritt nachgetragen.

### Die Messung selbst: **42 wie erwartet, 0 abweichend**

Gefahren am 17. August 2026 auf `cloudsrv24`, gegen das Skript aus `main`
(`sha256 876aa368…c134`, gegengeprüft). Alle zehn Gruppen grün — der Zugang
überhaupt (M6/M9/M10), das eigene `authorized_keys` des Kunden (M7), beide
Ketten (M8), wo der Block stehen darf (M1/M2), das Drop-in (M3), was `sshd -t`
sieht und was nicht (M4/M5), die Einschleusung, das Neuladen (M11/M12) und der
Schlüssel selbst (M13).

**Und die Fassung ist zeichengleich mit der im Container gemessenen:**

```
OpenSSH_9.6p1 Ubuntu-3ubuntu13.18, OpenSSL 3.0.13 30 Jan 2024
```

`docs/57` misst damit nicht eine ähnliche Fassung, sondern **dieselbe**. Die
42 Messungen übertragen sich exakt statt nur sinngemäss — insbesondere die
drei, die diesen Schritt tragen: Ein Neuladen mit kaputter Datei tötet den
Dienst; ein `Match`-Block hat kein Ende, nur einen Nachfolger; die
Schlüsseldatei hat eine zweite Kette.

### `docs/50 §8` Punkt 4 — beantwortet

Seit der Messrunde vor P6 offen: wem `/var/www/vhosts` auf dem laufenden
Server gehört. Gemessen, nur gelesen und nie geändert:

| Pfad | Eigentümer | Rechte | Urteil |
|---|---|---|---|
| `/` | root | 755 | taugt |
| `/var` | root | 755 | taugt |
| `/var/www` | root | 755 | taugt |
| `/var/www/vhosts` | root | 755 | taugt |
| `/etc/ssh` | root | 755 | taugt |
| `/etc/ssh/sshd_config` | root | 644 | taugt |
| `/var/www/vhosts/p6-b.invalid` | root | 755 | taugt |

Die Kette trägt. Eine Abweichung hätte jeden SFTP-Zugang gekostet, und zwar
wortlos.

---

## 2. Fassungen

| | Wert |
|---|---|
| `srvpanel --version` | **0.6.0-rc.10** — wir prüfen, was wir zu prüfen glauben |
| `sshd -V` | OpenSSH_9.6p1 Ubuntu-3ubuntu13.18, OpenSSL 3.0.13 |
| `systemctl is-active ssh.service` | active |
| `systemctl is-active sshd.service` | active (Alias derselben Unit) |
| `systemctl is-enabled ssh.socket` | **enabled** |
| `/etc/srvpanel/ssh` | gibt es noch nicht |
| Prüfsumme `sshd_config` | `2b5a070e…6852`, unverändert seit 0c |

**Beide Unitnamen melden `active`**, weil `sshd.service` ein Alias von
`ssh.service` ist. `SftpAccess::reload()` geht seine Liste der Reihe nach durch
und nimmt die erste, die nicht `unknown` sagt — also `ssh.service`. Das ist die
gewollte Wahl, und sie ist hiermit gemessen statt angenommen.

**Und `ssh.socket` ist eingeschaltet, während der Dienst gleichzeitig läuft.**
Für Punkt 9 heisst das: Der Zustand „Dienst ruht" muss erst hergestellt werden,
und das ist bei offenen Sitzungen nicht folgenlos — der Punkt bekommt seine
eigene Vorsicht, wenn er dran ist.

---

## 3. Ohne Schlüssel ist der Zugang aus — und die Datei unberührt

**Die Hauptaussage hält.**

| | gemessen |
|---|---|
| `sha256sum /etc/ssh/sshd_config` | `2b5a070e…6852` — **unverändert gegenüber 0c** |
| `/etc/srvpanel/ssh` | gibt es nicht |

Das blosse Ansehen der Seite schreibt nicht in `sshd_config`. Das war die
eigentliche Frage dieses Punktes, und sie ist beantwortet.

**Der Wortlaut der Seite hält nicht.** Sie meldet den Zustand „noch nichts
eingerichtet" als Defekt — drei Befunde, alle in derselben Fläche, alle
derselbe Denkfehler.

---

## Befunde

### Befund 1 — die Messrunde war nie fahrbar

Siehe Punkt 1. `docs/58` verlangt ein Werkzeug, das die Auslieferung nicht
mitbringt.

### Befund 2 — „kein Schlüssel" wird als Defekt gemeldet

Die Seite schrieb rot: *„Der Zugang kommt so nicht zustande. /etc/srvpanel/ssh
gibt es nicht (Eigentümer ?, Rechte ?)"* — für ein Abonnement, an dem nur noch
niemand etwas eingerichtet hatte.

Die Ursache ist die Reihenfolge zweier Zweige: Der Defekt wurde zuerst geprüft,
und ohne Schlüssel gibt es die Schlüsseldatei nicht, also meldet `Chain` „gibt
es nicht". Erwartet war „Es ist kein Schlüssel eingetragen — damit ist der
Zugang aus."

> **Ein Zustand, in dem noch nichts eingerichtet ist, sieht für eine Prüfung
> genauso aus wie einer, in dem etwas kaputt ist — und nur der Code kennt den
> Unterschied.**

**Der Satz stand schon da.** `SftpCheck` trägt ihn im Kopf: *„Und ‚keine
Schlüsseldatei' ist ein Zustand und kein Defekt."* Er stand in der Klasse, die
die Auskunft *erzeugt*, und nicht in der, die sie *anzeigt*.

**Behoben:** Ohne Schlüssel wird der Zustand gemeldet; die Ketten werden ab dem
ersten Schlüssel beurteilt und nicht davor.

### Befund 3 — `none` wird für eine fremde Angabe gehalten

Daneben stand: *„In sshd_config gilt für diesen Benutzer ein anderes
Verzeichnis als das des Abonnements: **none**. Eine Regel des Betreibers steht
über der des Panels."* Auf einem Server, auf dem schlicht noch kein Block
existierte.

`sshd -T` schreibt `chrootdirectory none`, wenn nichts gesetzt ist. Die Prüfung
sah nur auf „ungleich der Abo-Wurzel".

> **Ein Platzhalter für „nichts" ist ein Wert, und eine Prüfung, die nur auf
> „ungleich" sieht, hält ihn für eine Aussage.**

**Behoben:** `none` zählt nicht als fremde Angabe, und die Meldung gilt erst ab
dem ersten Schlüssel — ohne Block gibt es nichts, was überschrieben sein könnte.

### Befund 4 — ein fehlendes Leerzeichen, und Vue ist schuld

Auf der Seite stand `zustande./etc/srvpanel/ssh` ohne Trennung. Vues Vorgabe
`whitespace: 'condense'` **entfernt** einen Textknoten zwischen zwei Elementen,
wenn er einen Zeilenumbruch enthält — und im Quelltext stand `</b>` und
`<span class="ident">` auf zwei Zeilen.

> **Ein Leerzeichen, das im Quelltext als Zeilenumbruch dasteht, ist für den
> Übersetzer keines.**

**Behoben** mit einem ausdrücklichen `{{ ' ' }}`, und **der Wächter steht**:
`TemplateSpacingTest`, mit beiden Brüchen in `tests/waechter-brechen.sh`.

Gemessen wurde dafür am Übersetzer selbst (`@vue/compiler-dom`, 17. August
2026) statt geschlossen: `</b>\n<span>` erzeugt **keinen** Textknoten zwischen
den beiden Elementen, `</b>{{ ' ' }}\n<span>` einen.

**Und der Wächter fragt nicht überall — das ist seine eigentliche Arbeit.** Im
ganzen Baum stehen **22** Stellen, an denen zwei Elemente nur ein Zeilenumbruch
trennt; **21 davon sind folgenlos**, weil der Behälter eine Flexbox ist und der
Abstand aus `gap` kommt — Beschriftung und Feld in `.field`, die zwei Zweige
eines `v-if`/`v-else`, gestapelte Hinweise in `.toggle > span`.

> **Eine Regel, die einundzwanzigmal Fehlalarm gibt, wird beim ersten Aufräumen
> abgeschaltet.**

Gefragt wird deshalb in den Meldungsklassen — `.hint`, `.empty`,
`.section-note`, `.error` und dem `span` in einem `.notice` —, und der Wächter
**misst diese Voraussetzung nach**: Bekäme eine davon `display: flex`, wird er
rot statt still.

### Ein Fund über das Messmittel, nicht über das Panel

Beim Bau des Wächters sah `<p class="usage">` auf der Abonnementseite nach
demselben Fehler aus: `<strong>1.024 MB</strong>` und `<span>von 10.240 MB</span>`
ohne Textknoten dazwischen. Der Aufsatz aus CLAUDE.md — echtes Markup plus
`public/build/assets/*.css` im Chromium — hat es **bestätigt**: `1.024 MBvon
10.240 MB`, gemessen und fotografiert.

Es war trotzdem falsch. `.usage` steht in einem `<style scoped>`-Block der
Komponente und ist dort `display: flex`. Vite übersetzt das zu
`.usage[data-v-1ecda25a]`, und handgeschriebenes Markup ohne dieses Attribut
trifft die Regel nie. **105 solcher Selektoren aus 19 Komponenten** stehen im
gebauten Stylesheet und gelten in diesem Aufsatz allesamt nicht.

> **Ein Aufsatz, der das gebaute Stylesheet benutzt, hat noch nicht die Regeln,
> die an ein Attribut gebunden sind, das nur der Übersetzer setzt.**

CLAUDE.md sagt über diesen Aufsatz: „misst die echte Seite — und nicht etwas
Ähnliches." Für Bausteine, deren Gestaltung ganz in `app.css` steht, gilt der
Satz weiter — `docs/56` Punkt 5 hat ihn aufs Pixel belegt. Für eine Komponente
mit eigenem `scoped`-Block gilt er nicht.
