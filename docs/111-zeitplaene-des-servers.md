# A6 — die Zeitpläne des Servers

Geschrieben am **7. September 2026**, **nach** der Messrunde (`docs/81 §2.3t`).
A6 ist eine **Leseansicht**: `/etc/crontab`, `/etc/cron.d` und die
`cron.*`-Verzeichnisse, neben den Timern aus A2.

**Warum es sie gibt.** Das Panel schreibt selbst nach `/etc/cron.d/srvpanel-*`
und betreibt fünf Timer. Der Admin sieht davon nichts an einer Stelle — und was
sonst noch auf seinem Server zeitgesteuert läuft, erst recht nicht.

**Lesen, nicht schreiben.** Was der Admin ändern will, ändert er als root; was
das Panel schreibt, schreibt es über seine eigenen Dateien. Ein Editor für
`/etc/crontab` wäre Freitext mit Systemrechten über einen Umweg (`docs/80 §A6`).

---

## 1. Was die Messrunde schon entschieden hat

### 1.1 Drei Gegenstände und nicht einer

| | Was | Zeitplan |
|---|---|---|
| `/etc/crontab` | Zeilen mit **Benutzerfeld** | in der Zeile |
| `/etc/cron.d/*` | dieselbe Form, eine Datei je Sache | in der Zeile |
| `/etc/cron.{hourly,daily,weekly,monthly,yearly}` | **Skripte** | **nicht bei sich** |

Ein Skript in `cron.daily` hat keinen eigenen Zeitplan. In einer gemeinsamen
Tabelle stünde bei ihm die Zeitspalte leer und bei den anderen die Spalte für
das Verzeichnis — dieselbe Überlegung, aus der A2 Dienste und Timer getrennt
hat.

### 1.2 Der Zeitplan eines Verzeichnisses wird gelesen, nicht gewusst

**M1.** Er steht in `/etc/crontab`, und zwei Dinge daran sind Fallen:

**`cron.yearly` steht in keiner Zeile.** Ein Skript dort läuft nie — und das
Verzeichnis sieht aus wie die anderen vier.

> **Ein Verzeichnis, das dasteht und in keinem Zeitplan vorkommt, ist von einem,
> das läuft, nicht zu unterscheiden — ausser man liest den Zeitplan.**

**Drei der vier Zeilen tragen `test -x /usr/sbin/anacron ||`.** Mit anacron tut
cron für daily, weekly und monthly gar nichts; die Zeitpunkte stehen dann in
`/etc/anacrontab`.

> **Eine Zeile, die eine Bedingung trägt, sagt ohne die Bedingung das
> Gegenteil.**

A6 zeigt den Zeitpunkt deshalb **nur, wenn er gilt** — und sonst den Satz, dass
anacron ihn bestimmt. Die Zeiten aus `/etc/anacrontab` sind **nicht** Gegenstand
dieses Wurfs (§8).

### 1.3 Die Namensregeln werden gefragt und nicht nachgebaut

**M2.** `run-parts` übergeht sechs von zwölf Prüfkörpern wortlos — `backup.sh`
und `logrotate.dpkg-new` (Punkt), `alt~`, `mit leerzeichen`,
`nicht-ausfuehrbar`.

> **Ein Skript, das nicht läuft, sieht im Verzeichnis genauso aus wie eines, das
> läuft.**

**Für die Verzeichnisse fragt A6 `run-parts --test`.** Es braucht kein root
(gemessen), unterscheidet ein fehlendes Verzeichnis (`rc=1`, Meldung) sauber von
einem leeren (`rc=0`, nichts), und es kennt seine Regeln besser als jeder
Nachbau.

### 1.4 Für `/etc/cron.d` ist es die Regel, die dieses Repo schon hat

**M3.** cron nennt zwei von drei Fehlern beim Namen — `WRONG FILE OWNER` und
`INSECURE MODE (group/other writable)` — und übergeht den dritten, einen Punkt
im Dateinamen, wortlos.

**`run-parts` ist hier das falsche Werkzeug:** Es hat eigene Regeln, cron hat
seine, und beide nur zufällig ähnlich. Und cron zu starten, um eine Liste zu
bekommen, ist keine Leseansicht.

**Die Regel steht seit P6 im Repo — auf der Schreibseite.**
`SrvPanel\Agent\Cron\CronFile` prüft `\A[A-Za-z0-9_-]+$` gegen den
**Systembenutzer**, mit einem gemessenen Kommentar darüber: `srvpanel.punkt` und
`srvpanel+plus` werden übergangen, `srvpanel_unterstrich` nicht.

**A6 ist die Leseseite derselben Regel.** Sie wird deshalb **an eine Stelle
gehoben**, die beide benutzen — nach dem Vorbild von `Net\Cidr`, das aus
`Pg\Hba` herausgelöst wurde.

> **Zwei Fassungen derselben Regel sind zwei, und die zweite ist die, die
> veraltet.**

### 1.5 Die stillen Fälle sind der Grund für die Ansicht

Vier Zustände, die auf der Platte alle gleich aussehen:

| Zustand | wer sagt es heute |
|---|---|
| Skript mit Punkt im Namen | **niemand** |
| Datei in `/etc/cron.d` mit falschem Eigentümer | cron, im Syslog |
| Datei in `/etc/cron.d` mit `0666` | cron, im Syslog |
| Verzeichnis ohne Zeile in `/etc/crontab` | **niemand** |

---

## 2. Die Fragen an den Betreiber

| | Frage | Vorschlag |
|---|---|---|
| **1** | Wer darf hinsehen — Administrator (`inspect-server`) oder nur der Betreiber? | **Nur der Betreiber.** Anders als eine Portnummer oder eine Paketfassung ist eine Cron-Zeile **beliebiger Text, den root geschrieben hat** — ein Pfad, ein Skriptname, im schlechtesten Fall ein Zugangsdatum in einem Argument. Das ist dieselbe Art Inhalt, deretwegen `/logs` dem Betreiber allein gehört. |
| **2** | Erscheinen die Cronjobs der Kunden (`/etc/cron.d/srvpanel-p1139`) hier? | **Als eine Zeile je Datei, als „vom Panel verwaltet" markiert, mit Verweis auf `/cron`** — nicht mit ihrem Inhalt. Sie haben ihre Seite; sie hier ein zweites Mal auszuschreiben wäre eine zweite Anzeige derselben Sache. |
| **3** | Zeigt die Seite das Kommando im Wortlaut? | **Ja, vollständig** — aber in einer Zelle, die umbrechen darf. Ein gekürztes Kommando ist die Auskunft, die man gerade nicht brauchen kann; und `docs/46 §20.13` hat gemessen, was eine gekürzte Textzelle bei 390 px anrichtet. |
| **4** | Bekommt die Bestandsdiagnose (A10) einen `check` für die stillen Fälle? | **Nicht in diesem Wurf.** A10 ist abgenommen; ein neuer `check` ist eine Änderung an einer abgenommenen Stufe und braucht ihren eigenen Nachlauf. Er ist danach der nächste Schritt — die vier Zustände aus §1.5 sind Befunde in genau der Form, die A10 kennt. |

**Frage 1 ist die, die ich nicht allein entscheide.** Sie fällt anders aus als
bei A3: Dort war der Port die Auskunft und der Prozessname die Landkarte; hier
ist die ganze Zeile fremder Text.

---

## 3. Die Form

### 3.1 Der Agent

Eine Operation, **`system.cron`**, lesend, ohne ein Argument von aussen —
dieselbe Bauart wie `system.time` und `system.ports`.

```
{
  "readable": true,
  "reason": null,
  "tables": [
    { "path": "/etc/crontab", "owned": false, "readable": true,
      "entries": [ { "schedule": "17 * * * *", "user": "root",
                     "command": "cd / && run-parts --report /etc/cron.hourly" } ],
      "env": { "SHELL": "/bin/sh" } }
  ],
  "directories": [
    { "name": "cron.daily", "path": "/etc/cron.daily",
      "schedule": "25 6 * * *", "conditional": "anacron",
      "scripts":  [ "apt-compat", "dpkg", "quota" ],
      "ignored":  [ "backup.sh" ] }
  ],
  "anacron": true
}
```

**Vier Dinge daran sind aus den Messungen abgeleitet und nicht gewählt.**

`ignored` ist die **Differenz** zwischen dem Verzeichnisinhalt und
`run-parts --test`, nicht eine eigene Prüfung. Was dort steht, läuft nicht — und
warum, sagt der Nachbau nicht besser als das Werkzeug.

`conditional` trägt den anacron-Vorbehalt aus der Zeile. `schedule` ohne ihn
wäre auf jedem Server mit anacron falsch.

`anacron` sagt, ob das Programm da ist. Erst beide zusammen ergeben eine
Auskunft.

`owned` markiert die Dateien des Panels. Die Liste kommt aus
`Diagnose\Host::cronFiles()` und nicht aus einem zweiten Präfixvergleich.

### 3.2 Die Seite

**Drei Bereiche**, weil es drei Gegenstände sind (§1.1):

1. **Zeitpläne** — `/etc/crontab` und `/etc/cron.d` in einer Tabelle, mit
   Spalte „Datei". Die Dateien des Panels stehen als eine Zeile mit Verweis.
2. **Verzeichnisse** — je Verzeichnis der Zeitpunkt (oder der anacron-Satz) und
   die Skripte.
3. **Übergangen** — was `run-parts` nicht ausführt, mit dem Grund. **Der
   Bereich steht nur da, wenn es etwas zu zeigen gibt**; leer wäre er eine
   Beruhigung, die niemand bestellt hat.

**Die Wortwahl.** „läuft nicht" und nicht „ungültig": Die Datei ist in Ordnung,
sie hat nur einen Namen, den cron übergeht.

---

## 4. Wo es liegt

Eine eigene Seite **„Zeitpläne"** unter **Betrieb**, neben „Dienste".

**Und nicht als Bereich auf `/services`.** Dort stehen seit A2 Dienste und Timer
und seit A3 Ports und Regelwerk; ein vierter und fünfter Bereich machten aus der
Seite eine Halde. Die Frage „was läuft zeitgesteuert" ist ausserdem eine andere
als „was läuft".

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

---

## 5. Die Wächter

| Wächter | Regel |
|---|---|
| `CronTableTest` | Der Leser für `/etc/crontab` und `/etc/cron.d` trennt an **Leerraum** und nicht an einem Leerzeichen (gemessen: die Felder sind mit Tabulatoren gesetzt), nimmt das Benutzerfeld mit, und `SHELL=`/`PATH=`/`MAILTO=` sind Umgebung und keine Zeile. |
| `RunPartsSeamTest` | `ignored` ist die Differenz aus Verzeichnis und `run-parts --test` — gemessen an der **Wirkung** mit den zwölf Prüfkörpern aus `§2.3t`, und der Leser baut die Namensregeln nirgends nach. |
| `CronScheduleTest` | Ein Verzeichnis ohne Zeile in `/etc/crontab` trägt **keinen** Zeitpunkt, und einer mit anacron-Vorbehalt trägt ihn nur zusammen mit `conditional`. In beide Richtungen. |
| `CronNameRuleTest` | Es gibt **eine** Stelle, die sagt, welchen Dateinamen cron liest — Schreibseite (`CronFile`) und Leseseite fragen dieselbe. Gemessen an `srvpanel.punkt`, `srvpanel+plus`, `srvpanel_unterstrich`. |
| `CronPayloadTest` | Die Seite trägt die Fähigkeit ihrer Route, und die Dateien des Panels kommen aus `Host::cronFiles()` und nicht aus einem zweiten Präfixvergleich. |

Jeder mit seinem Bruch in `tests/waechter-brechen.sh`, jeder einmal rot gesehen.

---

## 6. Die Fallen, die schon dastehen

1. **Die Felder sind mit Tabulatoren gesetzt** (gemessen, `cat -A`). Getrennt
   wird an `\s+`.
2. **`run-parts --test` gibt volle Pfade aus**, nicht Namen. Wer sie mit dem
   Ergebnis von `scandir` vergleicht, vergleicht zwei verschiedene Dinge.
3. **`cron -x load` allein druckt nur Ablehnungen.** Für eine Messung, die
   „geladen" belegen soll, braucht es `-x load,pars,sch` — sonst ist die
   Abwesenheit einer Zeile kein Beleg.
4. **`/etc/cron.d` kann Dateien enthalten, die dem Panel gehören**, und die
   stehen bereits auf `/cron`. Zweimal dieselbe Sache anzuzeigen ist keine
   doppelte Auskunft.

---

## 7. Das Abnahmekriterium — acht Punkte

Gefahren auf `cloudsrv24`. **Die Punkte 2 und 4 dürfen nicht ausfallen.**

| | Was | Erfüllt, wenn |
|---|---|---|
| **1** | Die Zeitpläne stehen da | Jede Zeile aus `/etc/crontab` und `/etc/cron.d` erscheint mit Zeitplan, Benutzer und Kommando — gezählt gegen die Dateien selbst. |
| **2** | **Ein übergangenes Skript wird benannt** *(Ausschluss)* | Ein für den Lauf angelegtes `zz-probe.sh` in `/etc/cron.daily` steht im Bereich „Übergangen" mit dem Grund — und **nicht** in der Liste der Skripte. Ohne diesen Punkt ist M2 beschrieben und nicht behoben. |
| **3** | Der Zeitpunkt eines Verzeichnisses stimmt | `cron.hourly` trägt `17 * * * *` ohne Vorbehalt; `cron.daily` trägt ihn **mit** dem anacron-Satz, falls anacron da ist — und ohne, falls nicht. Gemessen gegen `command -v anacron`. |
| **4** | **`cron.yearly` trägt keinen Zeitpunkt** *(Ausschluss)* | Das Verzeichnis erscheint, und die Zeitspalte sagt, dass es keinen Zeitplan hat — nicht „—" ohne Erklärung. |
| **5** | Die Dateien des Panels sind erkennbar | `/etc/cron.d/srvpanel-*` steht als eine Zeile je Datei mit Verweis auf `/cron` und nicht mit Inhalt. |
| **6** | Nicht feststellbar bleibt nicht feststellbar | Mit angehaltenem Agenten steht `nicht feststellbar` und nicht „keine Zeitpläne". Zurück über `systemctl start srvpanel.target`. |
| **7** | Die Tür | Betreiber 200; Administrator und Kundenkonto nach der Entscheidung aus §2 Frage 1. |
| **8** | 390 px | Vier Lagen, `dokument = 0`, Gegenprobe 200/200 — **mit dem echten Bestand** und einem langen Kommando, denn `docs/46 §20.13` hat gemessen, was eine lange Textzelle anrichtet. |

---

## 8. Was A6 ausdrücklich **nicht** wird

- **Es schreibt nichts.** Kein Editor, kein Schalter, kein Anlegen. Was der
  Admin ändern will, ändert er als root.
- **Die Cronjobs einzelner Benutzer** (`crontab -l -u`, `/var/spool/cron/crontabs`)
  stehen nicht drin. Das ist ein eigener Bestand mit eigenen Rechten und
  gehörte eigens gemessen.
- **`/etc/anacrontab`** steht nicht drin. A6 sagt, **dass** anacron den
  Zeitpunkt bestimmt, und nicht welchen — dafür bräuchte es eine eigene
  Messrunde.
- **Es rechnet keine nächste Fälligkeit.** Die Cronseite tut das für die Jobs
  der Kunden über `Occurrence`; hier wäre es eine Zusage über fremde Zeilen,
  deren Zone und Vorbehalte A6 nicht kennt.
- **Es bekommt keinen `check` in der Bestandsdiagnose** (Frage 4) — das ist der
  nächste Schritt und nicht dieser.
