# Übergabe: A10 und der Rest von P7b

Geschrieben am **2. September 2026**, nachdem der Nachlauf zu `0.7.3-rc.8`
(`docs/95`, Protokoll `docs/96`) durch war und `0.7.3-rc.10` auf `cloudsrv24`
läuft.

**Dieses Dokument ersetzt den Plan nicht.** Der Plan von P7b ist `docs/81`, der
Zuschnitt der Stufe steht in dessen §12.1, und `CLAUDE.md` trägt die Lehren. Hier
steht, was eine neue Sitzung wissen muss, um bei **A10** anzufangen, ohne die
letzten zwei Wochen nachzulesen.

---

## 1. Wo P7b steht

`docs/81 §12.1` schneidet die Stufe so zu: **A5, A9, A2, A10, A1, A11, A6, A8,
A3 (erster Wurf)**.

**Abgenommen, jeweils auf `cloudsrv24` und nicht geschätzt:**

| | Was | Wann | Protokoll |
|---|---|---|---|
| **A5** | Protokolle des Servers an einer Stelle | 25. August | `docs/84`, Punkt 12 |
| **A9** | Zwei Rollen, Konten, Netze, Sitzungen | 25. August | `docs/84` |
| **A1** | Paketverwaltung, Quellen, Updates | 28. August | `docs/86` |
| **A2** | Dienste und Timer | 31. August | `docs/91` |

**Offen:**

| | Was | Umfang |
|---|---|---|
| **A10** | Diagnose des Bestands — Knopf und Nachtlauf | ~1 Woche |
| **A3 (1)** | nur Anzeige: welche Ports lauschen, welches Regelwerk läuft | ~4 Tage |
| **A6** | Leseansicht von `/etc/crontab`, `cron.d`, `cron.daily`, `cron.weekly` | 2–3 Tage |
| **A8** | welche Adressen der Server hat, welche der DNS-Abgleich als Soll nimmt | 2–3 Tage |
| **A11-Rest** | Serverzeitzone und NTP **neben** der Anzeigezeitzone, Rechnername anzeigen | 2–3 Tage |

Der Neustart-Teil von A11 ist am 26. August gebaut (A1 Schritt 7).
`timedatectl` kommt in `agent/` und `app/` nirgends vor — nachgesehen am
2. September.

**Eine Buchhaltungsfrage, die der Betreiber entscheiden muss:** `docs/81 §11`
verortet **A12** (Wartungsmodus, Kundenseiten auf 503) mit „mit A1" und **A13**
(die billige Hälfte des Malware-Scans) mit „mit A10". A1 ist durch, A12 steht
damit in keiner Stufenzeile. A13 ritte mit A10 mit, wenn man will.

---

## 2. Was A10 ist

Aus `docs/81 §11`:

> **Ein Knopf und ein Nachtlauf:** `nginx -t`, `php-fpm -t`, `sshd -t`, Agent
> erreichbar, **jeder Timer mit nächstem Termin**, Zertifikate gültig und für den
> richtigen Namen, Quota **gemessen** und nicht aus den Mount-Optionen geraten
> (`docs/41`), verwaltete Blöcke unversehrt, Systembenutzer vorhanden, verwaiste
> Zeilen, Signaturschlüssel gültig (aus A1).

**Warum.** Die Mehrzahl der teuren Befunde aus `docs/45`, `docs/62` und `docs/66`
waren Zustände, die ein regelmässiger Bestandslauf gefunden hätte.

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat.** Ein Bestandslauf
> ist das „jemand", das jede Nacht nachsieht.

**Fertig, wenn** ein von Hand erzeugter Schaden — ein gelöschter verwalteter
Block, ein gestoppter Timer — im nächsten Lauf benannt auftaucht, **mit dem Ort**.

**Die Falle, die der Plan selbst nennt:** Ein Diagnoselauf, der bei jedem Lauf
etwas meldet, wird nach zwei Wochen nicht mehr gelesen. Was er meldet, muss
**behebbar** sein und nicht nur wahr.

### Was A10 aus den letzten Läufen mitnehmen sollte

- **Timer ohne Termin** sind schon einmal teuer gewesen: `srvpanel-cron.timer`
  meldete `active`, `NEXT` stand auf `-`, der letzte Lauf lag 22 Stunden zurück
  (`docs/64`). A2 hat den Leser dafür gebaut — `SrvPanel\Agent\ServiceStatus`
  samt `Triggers` am Timer. **A10 baut das nicht neu, es fragt es.**
- **Quota misst den Leseversuch**, nicht die Mount-Option: Auf `cloudsrv24`
  stand `usrquota` in den Optionen und `quotaon -p /` sagte `is off`
  (`docs/41`).
- **Zertifikate für den richtigen Namen**: Ein `openssl` ohne SNI läuft gegen
  den Vorgabeblock und liefert ein gültig aussehendes Zertifikat mit dem
  falschen Namen (`docs/78`).
- **Verwaltete Blöcke**: `Hba::ensure()` und der Fernzugriff haben sich in
  `pg_hba.conf` schon einmal gegenseitig überschrieben (`docs/42`).

---

## 3. Was **vor** dem Plan zu messen ist

**Dieses Projekt schreibt keinen Plan ohne Messrunde davor.** A1 hatte eine
(`docs/81 §2`), A2 hatte eine (`docs/89`), A9 hatte eine. Für A10 ist sie noch
nicht gefahren, und der Grund ist derselbe wie immer:

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

Zu messen, jedes mit Gegenprobe:

1. **Was `nginx -t`, `php-fpm -t` und `sshd -t` bei einem echten Schaden
   sagen** — Rückgabewert, Kanal, Wortlaut, und ob der Wortlaut übersetzt ist.
   `Inst` war sprachunabhängig, *„is already the newest version"* nicht
   (`docs/94`).
2. **Ob `sshd -t` ohne root etwas anderes sagt als mit** — der Agent läuft als
   root, die CI nicht.
3. **Was `quotaon -p` auf einem Dateisystem ohne Quota sagt** und wie sich das
   von „Quota an, aber Nutzer ohne Grenze" unterscheidet.
4. **Welche verwalteten Blöcke es überhaupt gibt** — `SiteTemplate`, `Hba`,
   `PgRemoteAccess`, `sshd_config`, `srvpanel.conf` — und woran man ihre
   Unversehrtheit erkennt, ohne sie neu zu schreiben.
5. **Was ein Nachtlauf kostet**: Wie lange dauert die Runde auf einem Server mit
   vier Domains? Ein Lauf, der Minuten braucht, gehört nicht in einen Timer, der
   stündlich feuert.

**Und die Frage, die A10 im Kern entscheidet:** Was ist die Form eines Befundes?
Ein Text? Eine Zeile mit Ort, Schwere und einer Handlung? Davon hängt ab, ob die
Seite später eine Liste zeigt oder eine Prosa-Ausgabe, und das lässt sich
hinterher nicht mehr billig ändern.

---

## 4. Die Fallen, die der Lauf vom 1./2. September gerade gelehrt hat

Sie stehen alle in `CLAUDE.md`, aber diese vier sind **frisch** und betreffen
jede Messrunde, die als Nächstes gefahren wird:

- **Ein `sed`, das nichts findet, meldet Erfolg** — und ein `|| printf …`, das
  daran hängt, läuft nie. Zweimal hat ein Prüfkörper damit seinen Zustand nicht
  hergestellt, und beide Male sah das Ergebnis wie der erwartete Befund aus
  (`docs/96 §4b`, Befund 14).
  → **Wer eine Vorbereitung von Hand trifft, belegt sie, bevor er misst.**
- **Ein Prüfmittel, das eine Zeichenkette sucht, zählt die Kommentare mit** —
  und in diesem Repo zitiert **jede** Behebung ihren Vorzustand im Kommentar.
  `grep -c 'apt-get.*update'` über `apt-run` ergab 5, alle fünf Kommentare
  (`docs/96 §3`, Befund 11).
  → Eine Messvorschrift gibt die **Zeilen** aus und keine Zahl.
- **Eine Frage nach dem neuesten Datensatz beantwortet, welcher der neueste
  ist** — nicht, ob der gesuchte darunter ist. Eine Gegenprobe las den letzten
  Vorgang und fand einen vom Vortag (`docs/96 §2`, Befund 9).
- **Ein Prüfkörper, dessen Länge man annimmt, statt sie am Quelltext
  nachzusehen, ist eine Vermutung mit Fussnote** (`docs/96 §8`, Befund 13).

> **Von sieben Befunden dieses Laufs steckten vier im Prüfmittel, und alle vier
> wären durch Nachsehen am Quelltext zu vermeiden gewesen.**

---

## 5. Der Zustand von `cloudsrv24`

- **`0.7.3-rc.10`** läuft, beide Units aktiv.
- Vier Kundendomains, zwei davon ohne Zertifikat (Stand 2. September,
  `srvpanel vhost --sites`).
- Die Paketquelle des Panels ist `https://repo.cloudsrv24.de/apt`, Kanal `beta`.
- Die letzten Vorgangsnummern liegen bei ~745.
- `/root/srvpanel.sources.bak` liegt dort noch von den 4b-Läufen.

**Was auf dem Server belegt ist und was nicht:**

| | |
|---|---|
| Befund 8 (`exit` statt `return` in der Warteschleife) | **belegt** beim Sprung rc.9 → rc.10, `rc=0` |
| Befund 10 (der Vorbehalt hängt am Urteil) | **belegt**, als Paar mit und ohne Installation |
| Befund 12 (abgeschaltete eigene Quelle) | **Behebung belegt**, der Fehler bleibt hergeleitet |

> **Der Prüfling einer Aktualisierung ist die installierte Fassung und nicht die
> eingespielte.** Eine Behebung an `srvpanel update` lässt sich grundsätzlich
> erst eine Fassung später belegen; **was behoben ist, lässt sich nicht mehr
> kaputt vorführen.**

---

## 6. Was der Container kann — und was oft falsch erinnert wird

`CLAUDE.md`, Abschnitt „Diese Umgebung", ist die Quelle. Vier Dinge, die dort
jahrelang als unmöglich standen und es nicht sind:

- **`composer install` geht** — mit `use-github-api false`,
  `github-protocols https`, `--prefer-source`. `vendor/` liegt hier, `phpunit`
  und `pint` sind unter `vendor/bin`, `phpstan.phar` und `pint.phar` daneben.
- **Der volle Testlauf geht** (2857 Tests) und **das Bruchskript auch**
  (~15 Minuten, 1005 Eingriffe).
- **systemd läuft als PID 1 in einer eigenen Namespace**, mit echten Timern und
  echten Terminen (`docs/89 §1`) — für A10 relevant, weil `nginx -t` und
  Timerabfragen damit hier messbar sind.
- **Der Agent läuft hier**, mit `--unprivileged` und eigenem Socket.

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.**

**Was der Container nicht hat:** kein nginx, kein PHP-FPM. Für A10 heisst das:
`nginx -t` ist hier nur messbar, wenn nginx installiert wird — und nach dem
Muster von MariaDB, `sshd` und PowerDNS ist genau das der erste Versuch und
nicht die erste Ausrede.

---

## 7. Was ausserhalb der A-Liste offen ist

- **Der Nachlauf zu Befund 5, 6 und 7 aus `docs/91 §20`** — die A2-Behebungen
  vom 31. August haben keinen Server gesehen.
- **`docs/86 §5`:** Befund 14 (die Fusszeile von `/logs`, gehört zu A5) und die
  ungemessene Laufzeit über 142 Pakete.
- **`docs/87` Punkt 4** ist am 2. September **neu geschrieben** und wartet auf
  einen Lauf — über das Panel, nicht über die Kommandozeile.
- **`docs/96 §4b`:** Die Rücknahme der Quelldatei ist mit `cp -a` gelaufen, die
  Gegenprobe `diff … && echo 'zurueck'` steht nicht in der Aufnahme.
- Ältere, benannt offene Reste: `docs/59` (Wand 2, Befund 23), `docs/42 §5`
  (zwei Punkte zu PostgreSQL), `docs/92` (ein Vorgang, der wegträgt — in P9
  vorgemerkt).

---

## 8. Was die neue Sitzung zuerst tut

1. **`CLAUDE.md` lesen** — sie ist lang und sie ist die Regel, nicht die Notiz.
2. **`docs/81 §11` (A10) und §12.1** lesen, dann **`docs/89`** als Muster, wie
   eine Messrunde vor einer Stufe dieses Projekts aussieht.
3. **Die Messrunde aus §3 fahren**, jede Messung mit Gegenprobe, und sie in
   `docs/81` eintragen — nicht in eine neue Datei, die niemand wiederfindet.
4. **Erst danach den Plan schreiben**, mit Abnahmekriterium und den Fragen, die
   der Betreiber entscheiden muss.

**Nicht anfangen mit:** dem Bau einer Seite. A10 entscheidet sich an der Form
des Befundes, und die entscheidet sich an dem, was die Werkzeuge wirklich sagen.

> **Eine Regel, an die man sich erinnern muss, ist keine Regel, sondern eine
> Gewohnheit.** Wer hier eine Regel aufstellt, baut den Wächter dazu und bricht
> ihn danach absichtlich.
