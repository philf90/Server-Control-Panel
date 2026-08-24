# Übergabe: die Adminfunktionen vor P8

**Stand: 24. August 2026.** Dieses Dokument ist für eine Sitzung geschrieben,
die mit diesem Repo noch nichts zu tun hatte und die **Serververwaltung für den
Admin** bauen soll — die Stufe, die vor P8 kommt.

**Geplant ist sie bereits.** Die erste Fassung dieses Dokuments war für eine
Sitzung gedacht, die planen *und* bauen sollte; geplant hat es inzwischen eine
eigene Sitzung, und das Ergebnis steht als `docs/80` und `docs/81` im Repo.
Diese Übergabe trägt den Zustand des Projekts, die Regeln, unter denen hier
gebaut wird, **und den Auftrag** (§1 bis §6).

> **Was man zweimal braucht, gehört ins Repo — auch wenn es keine Zeile Code
> ist.** Deshalb steht das hier und nicht in einem Sitzungsverlauf.

> **Ein Dokument, das „der Plan liegt dem Betreiber vor" sagt, ist ein Verweis
> auf nichts.** Genau das stand hier bis zum 24. August — ausgerechnet in dem
> Absatz, der davor warnt, zweimal zu planen.

---

## 1. Der Auftrag

**Die Stufe heisst Serververwaltung** und besteht aus acht Vorschlägen plus
Kleinkram, in dieser Reihenfolge:

| # | Kürzel | Was | Aufwand |
|---|---|---|---|
| 1 | **A5** | Logs an einer Stelle — Positivliste von Quellen, dazu `/var/log/apt/history.log` | 3–5 Tage |
| 2 | **A2** | Dienste und Timer — alle Units, Neustartzähler, **nächster Termin je Timer** | 1 Woche |
| 3 | **A10** | Diagnose des Bestands — Knopf und Nachtlauf | 1 Woche |
| 4 | **A1** | **Paketquellen und Systemupdates über apt** — vollständig ausgeplant | 2–3 Wochen |
| 5 | **A9** | Zwei Verwaltungsrollen, IP-Beschränkung, Sitzungen | 1,5 Wochen |
| 6 | **A7** | Schwellen und Benachrichtigungen | 1,5 Wochen |
| 7 | **A3** | Firewall (nftables), in zwei Würfen | 2 Wochen |
| 8 | **A4** | Anmeldeschutz (fail2ban) | 1 Woche |

Nebenher, je 2–3 Tage: **A11** (Neustart, Zeitzone des Servers, NTP), **A6**
(Zeitpläne des Servers, nur lesen), **A8** (IP-Adressen), **A12**
(Wartungsmodus), **A13** (die billige Hälfte des Malware-Scans).

**Summe rund zehn Wochen.** Nicht alles davon muss vor P8 kommen — §12 von
`docs/81` schlägt einen Schnitt vor.

### 1.1 Wo der Plan steht

| Datei | Wofür |
|---|---|
| **`docs/80-adminflaeche-vergleich.md`** | Die Bestandsaufnahme: was die Admin-Ansicht heute kann, der Vergleich mit Plesk und cPanel in vier Tabellen, die sechzehn Vorschläge mit Begründung, und **sechs Dinge, die ausdrücklich nicht vorgeschlagen werden** |
| **`docs/81-serververwaltung.md`** | Der Plan. **A1 vollständig** (Messrunde, Abnahmekriterium, Operationen, Oberfläche, Fallen, Wächter, zehn Schritte), die übrigen als Skizze mit je einem „Fertig, wenn" |

**Beide liegen auf `main`** — PR #172 ist am 24. August gemergt (`dd1153f`).
Mit ihnen kamen `tests/apt-messen.sh`, `tests/apt-conffile-messen.sh`,
`AccountTypeAxisTest` samt seinen zwei Eingriffen im Bruchskript, das
Rollenmodell in `docs/20 §6.1` und der berichtigte Kopf von `AccountType`.

### 1.2 Die Stufe heisst P7b

**Entschieden am 24. August 2026 vom Betreiber**, und `docs/20 §9` trägt sie
seitdem zwischen P7 und P8. Der Absatz darunter bleibt stehen, weil er die
Begründung trägt und nicht nur das Ergebnis.

Geplant wurde sie unter „P9a", weil `docs/20 §9` die Serververwaltung in P9
führt. **Dieser Name ist falsch geworden**: Dieses Dokument heisst „die
Adminfunktionen **vor P8**", und damit hängt die Arbeit zwischen P7 und P8.
`docs/81 §12.1` schlägt **P7b** vor.

> **Ein Name, der eine Reihenfolge behauptet, wird falsch, wenn die Reihenfolge
> sich ändert — und er wird trotzdem weiterbenutzt, weil er in Überschriften
> steht.**

Das war eine Planänderung an `docs/20 §9`; sie ist gefallen. **Offen geblieben
ist, wohin A3, A4, A7 und A9 gehören** — sie stehen in `docs/20 §9` unter P7b
als „hat noch keine Stufe".

---

## 2. Die vier Dokumente, die vor allem anderen kommen

| Datei | Wofür |
|---|---|
| **`docs/20-hostingpanel-neuplan.md`** | Der Plan. Quelle für Architektur (§4), **Rechtemodell (§6, mit den zwei Verwaltungsrollen in §6.1)**, Gestaltung (§7.2) und die Ausbaustufen (§9). **Wo Plan und irgendein anderes Dokument sich widersprechen, gilt der Plan.** |
| **`CLAUDE.md`** | Rund 1760 Zeilen, und sie sind keine Einleitung, sondern ein Fehlerprotokoll. Fast jeder Absatz ist die Beschreibung eines Fehlers, der teuer war. Wer die Datei überfliegt, macht drei davon noch einmal. |
| **`docs/81-serververwaltung.md`** | Der Plan dieser Stufe. §9 ist die Schrittfolge, §4 das Abnahmekriterium von A1. |
| **`docs/19-sprache-der-oberflaeche.md`** | Bindend für jeden Text, den ein Mensch liest. Wird von `WordChoiceTest` geprüft. |

---

## 3. Der Stand in Zahlen

| | |
|---|---|
| Ausbaustufen | **P0 bis P7 abgenommen** — P7 am 24. August 2026 auf `cloudsrv24` gegen `0.7.0-rc.8`, alle acht Kriterien aus `docs/72 §3` |
| Letzte Fassung | `v0.7.0-rc.11`, Beta-Kanal |
| `main` | `dd1153f` (Stand 24. August, nach dem Merge von PR #172; davor `8508ef2` mit P7) |
| Wächter | **307** Dateien unter `tests/Unit` und `tests/Feature` |
| Bruchskript | `tests/waechter-brechen.sh`, **728 Eingriffe** |
| Agent-Operationen | **94** registriert. Unter `agent/src/Ops/` liegen 95 Dateien — die 95. ist `SubscriptionState`, die gemeinsame Basisklasse von `SubscriptionSuspend` und `SubscriptionResume`, und keine Operation. Wer die Differenz nachzählt, hat sie damit erklärt |
| Zielplattformen | Debian 12/13, Ubuntu 22.04/24.04 — alle vier laufen in der CI |

**Was in P7 entstand** (für den Fall, dass eine Adminfunktion daran rührt): der
DNS-Abgleich — Sollzustand, Istzustand von den autoritativen Servern, drei
Zustände, CAA, eine regelmässige Messung mit Timer. Der Plan ist `docs/72`, die
Protokolle `docs/74`, `docs/76`, `docs/78`.

> **Vorsicht bei den Nummern 73 und 74.** Sie sind am 24. August **zweimal**
> vergeben worden, von zwei Sitzungen am selben Tag. P7 hat sie behalten
> (`docs/73-zwischenabnahme-p7.md`, `docs/74-protokoll-zwischenabnahme-p7.md`),
> die Planung der Adminfunktionen ist auf 80 und 81 gewandert. Git meldet so
> etwas nicht, weil die Dateinamen verschieden sind.
>
> **Eine Nummer, die zwei Sitzungen gleichzeitig vergeben, gehört keiner von
> beiden.** Wer ein neues Dokument anlegt, sieht vorher `ls docs/` nach — und
> prüft danach, dass kein `docs/NN`-Verweis im Repo ins Leere zeigt.

---

## 4. Womit angefangen wird

**Nicht mit A5, sondern mit einem Befund an bestehendem Code.**

### Schritt 1 — der apt-Befund (M5), und er wartet auf kein Merkmal

> **`apt-get update` gibt 0 zurück, auch wenn jede einzelne Quelle unerreichbar
> war.**

Gemessen mit Gegenprobe gegen eine Quelle auf `127.0.0.1:1`: `rc=0` und zwei
`W:`-Zeilen auf stderr. Mit `--error-on=any`: `rc=100`, dieselben zwei Zeilen
als `E:`.

Das ist keine Nachlässigkeit von apt, sondern seine Zusage: Der Rückgabewert
beantwortet nicht „habe ich alle Quellen erreicht", sondern „habe ich danach
einen benutzbaren Zustand" — und die alte Liste bleibt liegen. Die Auskunft
steht auf stderr, und `Result` trennt `stdout` und `stderr` längst. Sie liest
an dieser Stelle nur niemand.

**Vier Aufrufstellen sind betroffen, und sie sind verschieden schlimm:**

| Stelle | Was geschieht | Wie schlimm |
|---|---|---|
| `PhpVersionInstall` | Sury unerreichbar → `apt-get install` findet nichts → Abbruch mit *„Unable to locate package"* | **Zustand richtig, Diagnose falsch.** Der Betreiber sucht am Paket, der Fehler sitzt an der Quelle |
| `PgServerInstall` | dasselbe; `describe()` fängt es zusätzlich ab | dito |
| dieselben, **mit alten Listen** | apt installiert die Fassung aus der veralteten Liste, `missing()` ist zufrieden | **still** |
| `PanelUpdate` | `apt-get update -qq && apt-get install --only-upgrade srvpanel` — das `&&` greift nie, weil `update` immer 0 ist. Mit alten Listen findet `--only-upgrade` nichts Neueres, meldet `0 upgraded`, endet mit **rc 0** | **der schlimmste:** „Update läuft", die Fassung bleibt stehen, das Protokoll meldet Erfolg |

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

**Was zu bauen ist** (`docs/81 §2.1b`), drei Teile:

1. **`Apt::refresh()`** als die eine Stelle, die `apt-get update` ruft, `stderr`
   auf `^W: Failed to fetch <URI>` liest und **je Quelle** einen Ausgang
   zurückgibt statt eines Wahrheitswerts.
2. **Die Aufrufer entscheiden verschieden.** `PhpVersionInstall` bricht ab, wenn
   *die Quelle, die es braucht*, unerreichbar war — mit dieser Begründung statt
   der über das Paket. `system.packages.list` bricht nicht ab, sondern zeigt die
   tote Quelle neben der Zahl.
3. **`PanelUpdate` bekommt seine zweite Frage:** nach dem Lauf die Fassung
   nachlesen und melden, wenn sie dieselbe geblieben ist. Das geht erst nach dem
   Neustart — **dieser Teil hängt an Schritt 6 und nicht an Schritt 1.**

**Und nicht `--error-on=any` global.** Die Fahne ist die richtige Härte für
einen Lauf, der genau eine Quelle braucht, und die falsche für einen, der alle
nachsieht: Eine vorübergehend tote Drittquelle würde sonst ein Sicherheitsupdate
aus dem Ubuntu-Archiv blockieren.

> **Eine Härte, die nur einheitlich zu haben ist, gehört nicht an eine Stelle,
> an der die Aufrufer verschieden entscheiden müssen.**

Der Wächter dazu heisst `AptResultTest`. **Er darf nicht nach dem Wort
`successful()` suchen** — er wäre grün, sobald irgendwo daneben eine zweite
Prüfung steht. Er sucht die Aufrufe von `apt-get update` und belegt für jeden
einzeln, dass `stderr` gelesen wird.

> **Ein Wächter, der ein Wort sucht statt einer Wirkung, ist grün, sobald das
> Wort irgendwo steht.**

### Schritt 0 — die Messrunde vervollständigen

`tests/apt-messen.sh` ist gefahren, **aber nur gegen Ubuntu 24.04**. Es fehlen:

- **Drei Zielplattformen**: Debian 12, Debian 13, Ubuntu 22.04. Ein Lauf je
  Plattform, dasselbe Skript. Erwartet werden Unterschiede bei der
  Voreinstellung `.list` gegen `.sources`, beim Namen der Sicherheitssuite und
  bei `--error-on=any` auf apt 2.4.
- **Vier Fälle, die im Container nicht vorkamen** und eigens erzeugt werden
  müssen: ein zurückgehaltenes Paket · ein Signaturschlüssel **mit**
  Ablaufdatum · eine Neuinstallation in `dist-upgrade` (`Inst` ohne `[alt]`) ·
  ein `Requested-By` in `/var/log/apt/history.log`.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Vier der elf Messungen stehen heute auf einer Null ohne Nachbarn.

### Danach — die Reihenfolge aus §1

A5 zuerst (kleinster Aufwand, macht jede folgende Stufe billiger), dann A2, A10,
A1. Die Schrittfolge von A1 im Einzelnen steht in `docs/81 §9`.

---

## 5. Was der Betreiber schon entschieden hat

**Nichts davon ist neu zu verhandeln.** Die Begründungen stehen bei jeder
Entscheidung; sie bleiben stehen, weil eine Entscheidung ohne ihren Grund beim
nächsten Anlass neu verhandelt wird.

### 5.1 Die vier Fragen zu A1 (`docs/81 §3`)

| | Entschieden durch | Wie |
|---|---|---|
| **1** Fremde Paketquellen | den Betreiber | **nein** im ersten Wurf. Anzeigen, ein- und ausschalten, und an hinzufügbaren Quellen nur die drei, die das Panel ohnehin kennt: Sury, PGDG, das eigene Repo |
| **2** Wer darf einspielen | den Betreiber | siehe §5.2 — zwei Rollen statt zweier Rechte |
| **3** Conffiles | **eine Messung** | `--force-confold`, und die zurückgelassenen `.dpkg-dist` anzeigen |
| **4** Reichweite der Automatik | den Betreiber | zwei Schalter: Paketlisten auffrischen **immer an**, Sicherheitsupdates unbeaufsichtigt **anbietbar und voreingestellt aus** |

**Zu Frage 1 gehört eine Nachlese**, und sie ist bewusst überstimmt worden: Der
Admin hat SSH und kann eine Quelle in dreissig Sekunden von Hand eintragen. Das
Argument trägt, solange es **einen** Kontotyp gibt — und fällt mit A9, weil der
Administrator kein SSH hat. Wer die Entscheidung später aufmacht, fängt bei
diesem Satz an.

**Zu Frage 3 gibt es keine Alternative mehr**, und das ist gemessen:

> **`DEBIAN_FRONTEND=noninteractive` beantwortet die Conffile-Frage nicht.** Die
> Fahne gilt für debconf; diese Frage stellt dpkg selbst, auf stdin.

Bei offenem stdin **wartet der Lauf ohne Zeitgrenze** (rc=124), bei stdin am
Dateiende bricht er ab (rc=1) — beide Male bleibt das Paket in `iU` mit einer
`.dpkg-new` daneben. Bei 146 Paketen hält damit ein einziges mit angefasster
Conffile den ganzen Vorgang an, an beliebiger Stelle. „Abbrechen, wenn ein Paket
fragt" ist keine Wahl, sondern der Zustand ohne Wahl.

**Zu Frage 4** drei Festlegungen: kein automatischer Neustart
(`Automatic-Reboot false`) · **die eigene Paketquelle bleibt aus der Automatik
draussen**, und das gehört hingeschrieben, damit es niemand später „der
Vollständigkeit halber" ergänzt — ein Panel, das sich unbeaufsichtigt
aktualisiert, kann sich unbeaufsichtigt zerlegen · und der wirksame Zustand wird
aus `apt-config dump` gelesen und **nicht aus der eigenen Datei**, weil ein
fremdes Paket dazwischenschreiben kann (gemessen: `docker-disable-periodic-update`
hatte `APT::Periodic::Enable` auf `0` gesetzt).

> **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand.**

### 5.2 Zwei Verwaltungsrollen (`docs/20 §6.1`, `docs/81 §11` bei A9)

Die Admin-**Ebene** bleibt eine — beide Rollen sehen den ganzen Server, und für
die Mandantenklammer sind sie ununterscheidbar. Verschieden ist nur, was sie
dürfen:

- **Betreiber** — dem `root` dieses Servers nahe. Alles.
- **Administrator** — verwaltet Kunden, Abonnements, Domains, Datenbanken,
  Dateien, Cron, Protokoll. **Kritisches weder sehen noch bedienen.**

**Kritisch ist, was eines dieser drei Merkmale trägt:** es verleiht root auf
Dauer (Paketquellen, unbeaufsichtigte Updates) · es nimmt alle Kunden mit
(Dienste stoppen, Firewall, Neustart, Systemupdates einspielen) · es zeigt ein
Geheimnis (DNS-Zugangsdaten, SMTP-Kennwort, private Schlüssel des Panels).

**Drei Fallen, alle drei ausgeschrieben in `docs/81 §11`:**

1. **Verbergen ist nicht Schützen.** Wer die DNS-Zugangsdaten nicht *sieht*,
   aber eine Zertifikatsbestellung auslösen darf, die sie benutzt, für den ist
   das Geheimnis weiterhin wirksam. Geteilt wird nach **Wirkung**, nicht nach
   Bildschirm. Deshalb steht „PHP-Versionen installieren" beim Betreiber:
   `php.version.install` ruft `apt-get install`.

   > **Eine Seite, die man nicht sieht, ist keine Grenze, solange ein Knopf
   > daneben dasselbe bewirkt.**
2. **Wer Konten anlegt, legt seine eigene Rolle an.** Ein Administrator darf
   keinen Betreiber anlegen und sich nicht selbst befördern — sonst ist die
   Trennung eine Zierde.
3. **Die Aussperrung.** Es muss immer mindestens einen Betreiber geben; der
   letzte lässt sich weder herabstufen noch löschen noch sperren. Der Rückweg
   ist `srvpanel admin` auf der Kommandozeile — den gibt es (`CreateAdmin`), und
   er gehört in dieser Stufe **geprüft und nicht angenommen**.

### 5.3 Die Falle am Datenmodell — sie ist bereits mit einem Wächter versehen

**`AccountType` bekommt keinen vierten Fall.**

```php
public function isAdmin(): bool           { return $this === self::Admin; }
public function belongsToCustomer(): bool { return $this !== self::Admin; }
```

Beide sind als *Gleichheit mit einem Fall* geschrieben, nicht als Zugehörigkeit
zu einer Menge. Ein vierter Fall `Superadmin` wäre augenblicklich
`isAdmin() === false` und `belongsToCustomer() === true` — an **52 Stellen** in
`app/` und `routes/`. Die Mandantenklammer setzte ihn auf `whereRaw('0 = 1')`,
weil er keinen Kunden hat.

> **Ein Fehler, der zur sicheren Seite fällt, fällt trotzdem — und er fällt
> leise.** Der neue Betreiber sähe eine leere Kundenliste, und niemand käme auf
> einen Enum-Fall als Ursache.

Der Grund: In `AccountType` stecken **zwei Fragen**. „Wen sieht dieses Konto"
beantworten beide Rollen gleich. Verschieden ist nur, was sie dürfen. Also
bleibt `AccountType::Admin` für beide, und die Rolle kommt als **eigene Angabe**
am Konto dazu; `isAdmin()` behält an allen 52 Stellen seine heutige Bedeutung.

**`AccountTypeAxisTest` steht seit dem 24. August als Stolperdraht davor**, mit
zwei Eingriffen im Bruchskript. Sein zweiter Fall prüft nicht die Ordnung,
sondern **seinen eigenen Grund**: Vergleicht `isAdmin()` nicht mehr gegen genau
einen Fall, ist das Verbot hinfällig, und der Wächter meldet, dass er überholt
ist.

> **Ein Wächter, der seine eigene Voraussetzung nicht prüft, setzt sie auch dann
> noch durch, wenn sie weggefallen ist.**

**Die Naht für die Durchsetzung ist eine einzige Zeile:**
`Gate::define('manage-settings', fn ($account) => $account->isAdmin())` —
fünfzehnmal in `routes/web.php` benutzt, und sie deckt heute **alle sechs**
Einstellungsseiten ab, die Geheimnisse tragen. Aus dem einen Gate werden
mehrere.

**Und für „auch nicht sehen" gibt es das Vorbild schon.**
`OverviewController` verzweigt **serverseitig** und erhebt die Serverwerte für
einen Kunden gar nicht erst — mit der Begründung, dass ein `v-if` die Daten
trotzdem an den Browser schickt. Das Abnahmekriterium von A9 misst deshalb **die
Inertia-Antwort und nicht das Bild**.

---

## 6. Was schon gemessen ist — und nicht noch einmal gemessen wird

Zwei Messvorschriften liegen im Repo. **Beide sind gefahren**, beide tragen ihre
Gegenprobe.

| Datei | Was | Wo fahrbar |
|---|---|---|
| **`tests/apt-messen.sh`** | Elf Messungen zu Paketquellen, Inst-Zeilen, Schlüsseln, Sperren, Historie, unbeaufsichtigten Updates. **Rein lesend** — schreibt nirgends nach `/etc` | auch auf `cloudsrv24` |
| **`tests/apt-conffile-messen.sh`** | Vier Läufe zum Conffile-Verhalten. **Installiert und entfernt** ein selbstgebautes Prüfpaket | nur Wegwerf-Maschine |

**Die drei Funde, die den Entwurf von A1 entschieden haben** — sie stehen in
`docs/81 §2.1` mit den Rohzeilen:

1. **Die eckige Klammer mit der alten Fassung fehlt bei einer Neuinstallation**,
   und die Architektur steht ebenfalls in eckigen Klammern, am Ende der
   Rundklammer. Wer „die eckige Klammer" liest, hält bei `cowsay` das `[all]`
   für die alte Fassung. Gemessen: 146 mit, 0 ohne — **der Fall, der bricht, kam
   in der Messung gar nicht vor.**
2. **Die Herkunft ist eine Liste.** 132 von 146 Zeilen tragen zwei Herkünfte;
   ein Sicherheitsupdate erkennt man daran, dass **irgendeine** davon auf
   `-security` endet — nicht die erste.

   > **Ein Feld, das meistens genau einen Wert hat, ist kein Feld mit einem
   > Wert.**
3. **`Signed-By:` kann ein ganzer PGP-Block sein**, gefaltet über vierzig Zeilen;
   eine Datei trägt mehrere Stanzas, `Suites:` trägt mehrere Suiten. Vier
   Dateien werden zu achtzehn Zielen. **Daraus folgt: der deb822-Leser wird
   nicht gebaut** — `apt-get indextargets` ist apts eigene aufgelöste Sicht, mit
   `Origin`, `Suite`, `Component` und `Trusted` je Ziel. Was es nicht kann (die
   Herkunftsdatei nennen, eine abgeschaltete Quelle zeigen), steht daneben als
   zweite, **getrennt beschriftete** Auskunft.

   > **Zwei Fragen, die verschieden lauten, brauchen zwei Antworten — auch wenn
   > sie meistens dasselbe sagen.**

**Und zwei Fehler steckten in den Messmitteln selbst**, beide festgehalten,
damit sie nicht wiederkommen:

- `$(grep -c … || echo 0)` gibt **zwei** Nullen aus: `grep -c` schreibt seine
  `0` **und** endet mit 1.
- Der erste Wurf der Conffile-Probe hat gemessen, `--force-confold` lasse die
  Datei *stumm* zurück — mit einem `grep`, das drei Zeilen zu früh abschnitt.
  Die drei `==>`-Zeilen stehen da. Der Satz wäre so in den Plan gegangen und
  hätte ein Abnahmekriterium falsch begründet.

  > **Eine Messung, die zu früh abschneidet, meldet nicht „nichts gefunden",
  > sondern „nicht hingesehen" — und die beiden sehen gleich aus.**

Die Anzeige der `.dpkg-dist` bleibt trotzdem Pflicht, aber aus dem anderen
Grund: In einem Lauf über 146 Pakete gehen drei Zeilen unter. **Untergehen ist
etwas anderes als nicht dastehen**, und nur das eine ist wahr.

---

## 7. Die drei Grenzen — nicht verhandelbar

Sie stehen ausführlich in `CLAUDE.md` und im Plan §4. Kurz:

1. **Der Agent ist die einzige Stelle mit Systemrechten.** `agent/` ist ein
   framework- und abhängigkeitsfreies PHP-CLI hinter einem Unix-Socket. Die
   Anwendung schickt **typisierte Operationen**, niemals Text, der zu einer
   Kommandozeile oder Konfigurationsdatei wird. Programme stehen auf einer
   Positivliste mit absoluten Pfaden. **Nichts Privilegiertes gehört in `app/`**
   — auch nicht „nur kurz".
2. **Zustände folgen dem Agenten, nicht dem Klick.** Ein Vorgang ändert den
   Zustand erst, *nachdem* der Agent geantwortet hat
   (`Lifecycle::afterSuccess()` aus `RunAgentOperation`).
3. **Die Mandantenklammer verweigert im Grundzustand alles.**
   `app/Support/Tenancy/Tenancy.php` klammert Abfragen auf `whereRaw('0 = 1')`.
   `withoutRestriction()` ist die ausdrückliche Ausnahme und will begründet
   sein.

**Für Adminfunktionen ist die dritte die wichtigste — und die missverstandene.**
Sie ist die Regel fürs *Durchsetzen* und war nie eine Erlaubnis, jedem alles
anzubieten:

> **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie später
> abweist.** Die Antwort kommt als `can`-Ablage im Inertia-Payload — **nicht**
> als `v-if` auf den Kontotyp, denn das wäre eine zweite Fassung der Policy, und
> die zweite ist die, die veraltet. `AbilityReachTest` prüft beide Richtungen.

Jede Route trägt `can:` oder steht mit Begründung in
`app/Support/Authorization/RouteGuard.php`. `RouteAuthorizationTest` und
`PolicyReachTest` bestehen darauf.

**Und eine vierte Regel, die für A1 wörtlich gilt:** Kein Freitext erreicht apt.
Paketnamen werden gegen die zuvor gelesene Liste geprüft — **nicht gegen ein
Muster.** Ein Muster liesse `--reinstall` durch, sobald jemand es als Namen
schickt; eine Positivliste aus der eigenen vorigen Antwort nicht.

---

## 8. Die eine Gewohnheit, die dieses Projekt trägt

**Für jede Regel gibt es einen Wächter, und der Wächter wird gegengeprüft.**

Der Fehler, der hier immer wiederkehrt, ist derselbe: *eine Zeichenkette, die
auf etwas verweist, ohne dass ein Typ, ein Test oder ein Werkzeug den Bezug
prüft.* Eine Policy ohne Route. Ein Kommando, das im Startskript fehlt. Ein
Verzeichnisname, der umbenannt wurde. Er ist mindestens sechsmal aufgetreten und
jedes Mal teuer gewesen — zuletzt am 24. August als Dokumentnummer, die zwei
Sitzungen gleichzeitig vergeben hatten.

Deshalb: Wer eine Regel aufstellt, baut den Test dazu — und **bricht die Regel
danach absichtlich, um zu sehen, dass der Test zubeisst.**

> **Ein Wächter, der nie rot war, ist kein Wächter.**

Der Bruch gehört als Eingriff in `tests/waechter-brechen.sh`. `BreakScriptTest`
wacht darüber, dass jeder Eingriff seinen Text noch findet und jeder genannte
Zieltest existiert — er hat am 24. August zwei Eingriffe gefangen, die ein Umbau
stumpf gemacht hatte.

**Und die Falle, in die dieses Vorgehen selbst dreimal gelaufen ist:** Ein
Wächter zählt seine Treffer, damit er merkt, wenn sein Ausdruck ins Leere läuft
— und zählt sie dort, wo die Regel gerade eingehalten wird. Zieht die Regel um,
meldet er Rot für genau die Ordnung, die er durchsetzen soll. Die Untergrenze
zählt deshalb überall mit, wo die Regel stehen *darf*; der Befund kommt nur von
dort, wo sie stehen *soll*.

**Die fünf Wächter, die A1 mitbringt** (`docs/81 §8`), mit ihren Brüchen:
`AptResultTest` · `AptLockReachTest` (jede apt-rufende Operation geht über
**eine** Sperrprüfung — heute steht sie nur in `PanelUpdate`, obwohl drei
weitere Operationen apt rufen) · `InstLineTest` · `SourceOwnershipTest` ·
`PackageNameTest`.

**`InstLineTest` baut seine Prüfkörper selbst, Zeile für Zeile** — so wie
`ArchiveDepthTest` seine Archive baut. Ein Prüfkörper aus `apt-get -s` auf der
Entwicklungsmaschine enthält genau die Fälle nicht, an denen der Leser bricht.

---

## 9. Diese Umgebung — was geht und was nicht

**Der Container ist nicht der Zielserver.** `CLAUDE.md` hat dazu einen langen
Abschnitt; hier das, was am meisten Zeit spart.

### Was fehlt

- **`vendor/` gibt es nicht.** Kein `composer install` — der Proxy sperrt
  `codeload.github.com`. Also kein `phpunit`, kein `artisan`, keine
  Feature-Tests.

  **`ls vendor` genügt für diese Frage nicht** — gefragt wird nach
  `vendor/autoload.php`.
- **Kein nginx, kein PHP-FPM, kein Agent, kein systemd.** Vorlagen werden
  deshalb **als Text** geprüft (`SiteTemplateTest`, `PhpIsolationTest`).

### Was trotzdem geht — und in dieser Reihenfolge probiert werden sollte

> **„Es ist nicht da" und „es geht nicht" sind zwei Sätze, und der zweite
> braucht einen Versuch.** Dieser Satz hat hier MariaDB, OpenSSH, PowerDNS und
> PHPStan freigelegt, die alle jahrelang als unerreichbar galten.

| Werkzeug | Wie |
|---|---|
| **Pint** | `curl -sSL -o pint.phar https://github.com/laravel/pint/releases/latest/download/pint.phar` — dieselbe Fassung wie in der CI, gegengeprüft |
| **PHPStan** | `curl -sSL -o phpstan.phar https://github.com/phpstan/phpstan/releases/latest/download/phpstan.phar`. **Nicht** `phpstan.neon` benutzen (bindet larastan ein) — eine dreizeilige Wegwerfdatei mit `level: 6` und `treatPhpDocTypesAsCertain: false` |
| **npm** | funktioniert vollständig: `npm ci`, `npm run build`, `npm run types` |
| **Chromium** | vorinstalliert unter `/opt/pw-browsers/chromium`, **niemals** `playwright install` |
| **apt** | vollständig. `apt-get -s dist-upgrade`, `apt-get indextargets`, `dpkg-deb --build` — die ganze Messrunde von A1 ist hier gefahren worden |
| **MariaDB, OpenSSH, PowerDNS** | im Ubuntu-Archiv, `apt-get install` holt sie; Wegwerf-Instanzen im Scratchpad |
| **PostgreSQL 16** | vollständig installiert |

**PHPStan taugt nur für framework-freien Code** (`agent/`, `tests/Support`).
Für `app/` fehlt larastan, und dann ist jede Spalte undefiniert. Zwei Regeln
dazu:

- **Die Dateiliste kommt aus dem Zweig und nicht aus dem Gedächtnis:**
  `git diff --name-only origin/main...HEAD`.
- **Die Schnittstellen, die eine Datei umsetzt, gehören in denselben Lauf** —
  sonst meldet PHPStan nicht „ich kenne sie nicht", sondern dass die Klasse sie
  nicht erfüllt.
- Für `app/` lohnt trotzdem ein Lauf mit einem Filter **nach Kennung** (nicht
  nach Wortlaut): `class.notFound`, `method.notFound`, `missingType.*` und
  Verwandte sind Rauschen, alles andere ist echt. **Mit Gegenprobe** — ein
  absichtlicher Typfehler muss eine Zeile erzeugen, sonst misst der Lauf nichts.

### Das Wegwerf-Gestell für die Wächter

**Die framework-freien Wächter laufen hier, ohne PHPUnit.** Wer nur von
`PHPUnit\Framework\TestCase` erbt, braucht davon nur eine Sammlung von
`assert…`-Methoden. Ein Skript im Scratchpad, das diese Klasse selbst definiert
und die Testdatei einbindet, fährt sie. **Das ist keine zweite Fassung der
Tests** — es steht darin keine einzige Behauptung, nur die Maschine, die die
echten ausführt.

Damit laufen hier rund **1635 Testfälle grün**; 118 sind „Löcher" (Klassen, die
Laravel brauchen), 11 sind rot und auf `main` genauso rot.

Fünf Dinge, die beim Bau des Gestells Zeit gekostet haben und die eine neue
Sitzung nicht noch einmal bezahlen sollte:

1. `tests/Support/` **nicht blind laden** — nur Traits und Klassen ohne
   `use App\`; sonst zieht es Framework-Interfaces nach.
2. `agent/src/autoload.php` gehört dazu.
3. **Die Attrappe muss die `final`-Methoden der echten Basisklasse tragen**
   (mindestens `run()`, `count()`, `matches()`, `toString()`). Ohne sie meldet
   das Gestell Grün für Code, den `php artisan test` mit Rückgabewert 255
   tötet, bevor ein Test läuft.

   > **Eine Attrappe, die weniger verbietet als das Original, sagt Ja zu Code,
   > den das Original ablehnt.**
4. Die Zahl der Werte eines Datenlieferanten gegen `getNumberOfParameters()`
   prüfen — PHPUnit endet sonst mit Rückgabewert 1, während „alle bestanden"
   dasteht.
5. **Was das Gestell nicht kann, wird gezählt und nicht „übersprungen" genannt.**
   Nach Art (fehlende Klasse, `setUp()`, Datenlieferant, `use App\`) und nicht
   nach dem Wortlaut der Meldung — eine Einteilung nach `not found` gegen
   `does not exist` hat einmal 104 Wächter in die falsche Richtung gekippt.

   > **Ein Loch, das man zählt, ist kein Loch mehr — es ist eine Zahl, die
   > kleiner werden kann.**

**Eine offene Entscheidung für den Betreiber:** Dieses Gestell wird bisher in
jeder Sitzung neu gebaut, und die fünf Punkte oben sind teuer erlernt. Es *nicht*
im Repo zu haben, ist der Grund, warum sie jedes Mal neu bezahlt werden. Dagegen
steht die Sorge vor einem zweiten Testläufer, der von phpunit abdriftet. Der
Satz aus `CLAUDE.md` — *Ein Messmittel, das man aufhebt, macht die Fehler von
letztem Mal nicht noch einmal* — spricht dafür; entschieden ist es nicht.

### Einen einzelnen Eingriff des Bruchskripts fahren

`tests/waechter-brechen.sh` als Ganzes braucht `vendor/bin/phpunit` und läuft
hier nicht — **der einzelne Eingriff schon**: Datei sichern, den Python-Block
von Hand anwenden, den Wächter im Gestell fahren, Datei zurückholen.

> **„Das Bruchskript läuft hier nicht" ist keine Ausrede, sondern ein Handgriff
> mehr.**

**Welche Eingriffe man fährt, sagt der Zweig und nicht das Gedächtnis:** alle,
deren `vorher_datei` eine Datei nennt, die dieser Zweig geändert hat. Zwei
Fallen dabei, beide bezahlt: Ein Lauf über alle Eingriffe braucht mehr als zwei
Minuten und wird abgebrochen — ein Abbruch mitten im Eingriff lässt die Datei
kaputt liegen (`git status` vorher und nachher vergleichen). Und
`sort -u datei | tee datei` leert die Datei, bevor `sort` sie liest.

**Und `BreakScriptTest` läuft im Gestell.** Wer eine Regel anfasst, fährt ihn
mit — unabhängig davon, ob dabei ein Wächter entstanden ist. Ein grüner Lauf
belegt dabei nur etwas, wenn er auch rot werden kann: Zeigt ein Eingriff
versuchsweise auf einen Test, den es nicht gibt, muss
`test_every_check_names_a_test_that_exists` rot werden.

> **Ein Eingriff, der einzeln beisst, beisst nicht unbedingt im Lauf** — er
> steht dort neben anderen, und die verändern seinen Gegenstand.

### Bilder und die 390-px-Messung

**Bei allem Sichtbaren gehört ein Screenshot dazu, in beiden Themes und bei
390 px.** Die Messvorschrift liegt als **`tests/bilder-messen.js`** im Repo,
`OverflowProbeTest` liest sie. Ohne `artisan serve` geht der Aufsatz mit dem
gebauten Stylesheet aus `public/build` plus dem Markup des Bausteins in einer
eigenen HTML-Datei — **das misst die echte Seite und nicht etwas Ähnliches**,
aufs Pixel gegengeprüft (`docs/56`, Punkt 5).

Vier Fallen:

- **`<style scoped>` gilt in diesem Aufsatz nicht.** Vite übersetzt zu
  `.usage[data-v-1ecda25a]`; handgeschriebenes Markup trifft das nie. 105
  Selektoren aus 19 Komponenten sind so gebaut.
- **Jede Messung braucht ihre Gegenprobe.** Ein Prüfkörper muss dort eine Zahl
  erzeugen — und zwar an das Fenster gebunden (`scrollWidth + 200`), sonst fällt
  er bei der grösseren Breite auf `0` zurück.
- **Kein `| head` über dem Messlauf** — `head` schliesst die Leitung, node
  stirbt am SIGPIPE, und die übrigen Aufnahmen sind die des *vorigen* Laufs.
- **Nach jeder Änderung an einer `.vue` erst `npm run build`**, sonst zeigt die
  Aufnahme den vorigen Stand.

> **Ein Bild zeigt, dass etwas fehlt. Die Zahl sagt, ob die Seite schiebt.
> Keines von beiden ersetzt das andere.**

**Für A1 ist das keine Nebensache:** Die Paketliste ist eine lange Tabelle mit
Fassungsnummern, und Fassungsnummern sind Kennungen. Genau daran ist
`docs/64` Befund 1 entstanden.

---

## 10. Was für Adminfunktionen im Besonderen gilt

Die Punkte, an denen dieses Projekt bei sichtbaren Merkmalen am häufigsten
gestolpert ist:

### Der Ort im Menü — dreimal derselbe Fehler

Der Dateimanager lag drei Klicks tief, der SFTP-Zugang danach genauso, und der
Bereich „Job anlegen" auf der Cronseite war der dritte von drei Bereichen mit
zehn Kärtchen dazwischen. Jedes Mal hat es der Betreiber gemeldet, keiner der
Wächter.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?** Nicht „ist sie erreichbar" — erreichbar ist alles, was man findet,
> wenn man lange genug rollt.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

**`docs/80 §6.3` schlägt dafür eine neue Menügruppe „Server" vor** — Dienste ·
Pakete und Updates · Logs · Diagnose · Netz. Und einen Ort, an dem ein Knopf
*nicht* steht: Der Neustart gehört nicht in ein eigenes Menü, sondern dorthin,
wo sein Anlass steht — an die Kernelzeile der Übersicht und an das Ende eines
Paketlaufs.

> **Ein Knopf, den man sucht, wenn man ihn braucht, steht am falschen Ort.**

### Autorisierung

- Route trägt `can:` oder steht begründet in `RouteGuard`.
- Ein Knopf, den der Betrachter nicht drücken darf, wird **nicht gezeigt** —
  und die Antwort darauf kommt aus der Policy, nicht aus dem Kontotyp
  (`AbilityReachTest`).
- **Admins sind über `forAccount()` unbeschränkt** — wer eine Adminansicht baut,
  prüft, dass sie nicht versehentlich die Mandantenklammer umgeht, wo sie gelten
  soll.
- **Neu mit A9:** zwei Verwaltungsrollen (§5.2). Wer eine Adminfunktion baut,
  entscheidet **beim Bauen**, auf welcher Seite sie liegt, und nicht später —
  eine Fähigkeit nachträglich zu spalten ist der Weg, auf dem eine zweite
  Fassung der Policy entsteht.

### Das Protokoll

`record()` nimmt `target:` **und** `context:`. Beides. In P6 wurden 18 von 19
Aufrufen ohne `context` geschrieben, und niemandem fiel es auf, weil keine
Oberfläche das Feld las.

> **Ein Protokoll, das die Art der Handlung nennt und nicht ihren Gegenstand,
> beantwortet die Frage, die niemand stellt.**

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

### Rückmeldungen am Formular

`docs/19 §6`, geprüft von `FieldErrorTest`, alle drei Richtungen:

- Der Satz eines Fehlers steht **oben in der Zusammenfassung**.
- Das Feld trägt nur `aria-invalid`.
- **Erfolg wird nie am Feld gemeldet.**
- Und: **ein roter Rand behauptet, das Feld sei falsch.** Wer ihn für einen
  Zustand des Servers setzt, schickt den Leser dorthin, wo nichts zu ändern ist.

Die 85 deutschen Feldnamen für Fehlermeldungen müssen zur **sichtbaren
Beschriftung** derselben Seite passen — bei der letzten Messung wichen 15 von 68
ab.

> **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**

### Sprache und Gestaltung

- **Kommentare, Dokumentation, alle Texte der Oberfläche: deutsch. Bezeichner:
  englisch** — das schliesst CSS-Klassen, Datenattribute und Komponentennamen
  ein (`ClassNameTest`).
- **Keine Emoji.**
- **Jede Farbe kommt aus `resources/css/app.css`.** Ein Hexwert in einer
  Komponente ist ein Fehler, kein Sonderfall. Ebenso eine Komponente, die ihr
  eigenes `input` oder `table` gestaltet.
- Kontrast wird **gerechnet**: 4,5:1 für Text, **3:1 für die Grenze eines
  Bedienelements**.
- Das Gestaltungssystem heisst **„Kontor"** (Plan §7.2) — hell entworfen, keine
  Karten, Monospace nur für Kennungen.
- Kommentare erklären **warum**, nicht was. Der wertvollste hält fest, was
  schiefging und weshalb es jetzt anders ist.

### Wer etwas anlegt, baut den Weg zurück mit

Zweimal teuer geworden: Zertifikate liessen sich bestellen, aber nicht löschen
(zwölf private Schlüssel blieben liegen), und zuletzt am 24. August überlebte
ein Zertifikat den Rückbau seiner Domain. **Wer eine Adminfunktion baut, die
etwas anlegt, das auf der Platte oder im Bestand bleibt, baut das Entfernen im
selben Schritt.**

---

## 11. Der Ablauf

- Entwickelt wird auf dem zugewiesenen `claude/...`-Zweig, **nie direkt auf
  `main`**. Ist der zugehörige PR gemergt, wird der Zweig unter demselben Namen
  **frisch von `main` gestartet**, statt auf gemergter Historie zu stapeln.
- **`git commit -s`** (DCO) auf jedem Commit.
- **Einen Pull Request nur öffnen, wenn ausdrücklich danach gefragt wurde.**
  `.github/pull_request_template.md` gibt die Gliederung vor.
- **Kein Modellbezeichner** in Commit-Nachrichten, PR-Titeln oder -Rümpfen,
  Codekommentaren oder sonst etwas, das ins Repo geht.
- **Privates Schlüsselmaterial wird in diesem Container nie erzeugt.**
- Freigaben sind annotierte Tags `v<version>` auf `main`.
- Der `CHANGELOG.md` ist kein Protokoll der Commits, sondern der Ort, an dem
  steht, *warum* etwas so ist — und was vorher falsch war (`ChangelogTest`).

**Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium nachweisbar
erfüllt ist — gemessen auf einem echten Server, nicht geschätzt** (Plan §8, §9).
Für A1 steht das Kriterium mit acht Punkten in `docs/81 §4`; der Abnahmelauf
dazu ist noch zu schreiben.

Der Betreiber fährt die Serverbefehle selbst und schickt Ausgaben und Bilder
zurück; diese Sitzung hat keinen Zugriff auf `cloudsrv24`.

---

## 12. Die Sätze, die am häufigsten gebraucht werden

Aus `CLAUDE.md` und aus der Planung dieser Stufe, in der Reihenfolge, in der sie
hier Geld gekostet haben:

> **Die Mehrheit der Fehler steckt nicht im Prüfling, sondern im Prüfmittel.**
> In vier Abnahmeläufen hintereinander war es die Mehrheit; in einem die
> Gesamtheit.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

> **Eine Messung, die zu früh abschneidet, meldet nicht „nichts gefunden",
> sondern „nicht hingesehen" — und die beiden sehen gleich aus.**

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

> **Ein Fehler, den man an einer Stelle vermieden hat, ist an der nächsten
> wieder da, wenn die Vermeidung nicht die Regel wurde.** Viermal aufgetreten.

> **Zwei Fassungen derselben Regel laufen auseinander — und die falsche ist die,
> die man bekommt.**

> **Ein Rückgabewert, der einen Fehlschlag nicht tragen kann, ist keine Prüfung
> — er ist eine Zeile, die aussieht wie eine.**

> **Eine Prüfung, die den Zustand fängt, hat über die Ursache nichts gesagt —
> und der Leser sucht dort, wohin die Meldung zeigt.**

> **Eine Auskunft aus der eigenen Datei ist keine über den wirksamen Zustand.**

> **Ein Wächter, der ein Wort sucht statt einer Wirkung, ist grün, sobald das
> Wort irgendwo steht.**

> **Ein Prüfkörper, der ohne seinen Gegenstand misst, misst etwas anderes und
> sieht dabei aus wie ein Ergebnis.**

> **Ein Wächter, der die eigene Änderung nicht im Blick hatte, wird nicht
> gefahren — man denkt an das Gebaute und nicht an das Berührte.**

---

## 13. Was benannt offen bleibt

Nichts davon ist Schuld einer abgenommenen Stufe; alles ist bewusst stehen
gelassen und steht am jeweiligen Ort ausgeschrieben.

**Aus der Planung dieser Stufe** (`docs/81 §13`):

- **Drei Zielplattformen** und **vier Messfälle** — §4, Schritt 0. Wer A1
  anfängt, fängt dort an und nicht bei null.
- **Der eine Punkt, der A1 zum Scheitern bringen kann:** dass `systemd-run` den
  Neustart von `srvpanel-web` überlebt, ist seit P1 behauptet und **nur durch
  den eigenen Gebrauch belegt**. Er gehört in Schritt 6 gemessen und nicht in
  Schritt 10 erlebt.
- ~~Der Name der Stufe~~ — **am 24. August entschieden: P7b** (§1.2). Offen
  bleibt der Ort von A3, A4, A7 und A9 (`docs/81 §12.1`).
- Die überstimmte Nachlese zu Frage 1 (§5.1).

**Aus P7** (`docs/72 §11`, `docs/78 §5`):

- Die DENIC-Frage aus `docs/72 §1.4` — ungemessen, und sie gehört beantwortet,
  *bevor* jemand die Entscheidung gegen einen eigenen Nameserver aufmacht.
- Die zwei Servermessungen aus `docs/70 §14`.
- Das Schreiben fremder Zonen über die Anbieter-Zugangsdaten — nach `docs/72 §4`
  ausdrücklich nicht diese Stufe.
- Kein Aufstieg zur CAA-Elternzone (eine Grenze, kein Mangel); „Nameserver
  uneinig" und „kein Sollzustand bekannt" als nicht herstellbare Zustände; die
  Grenze des Durchgangs.

**Aus P6** (`docs/69 §3`, `docs/67 §3`): Wand 2 aus Punkt 11, Befund 23, die
neunzehn ungeprüften Griffe in `RevealTest::UNEXAMINED`, die vollständige
Umkehrung der Abstandsregel, und die Entscheidung zu `packaging/testbed.sh`.

**Aus P5b** (`docs/42 §5`): der `template1`-Beleg und die Frage, ob ein
PostgreSQL-Zugang ohne jede Datenbank überhaupt entstehen kann — beide **nie
gemessen**. Wer sie anfasst, fängt dort an und nicht bei null.

---

## 14. Was der neuen Sitzung konkret mitzugeben ist

1. **Den Zweignamen**, auf dem entwickelt werden soll.
2. ~~Ob die Stufe P7b heissen soll~~ — **entschieden, sie heisst P7b** (§1.2).
   Mitzugeben ist statt dessen, dass A3, A4, A7 und A9 noch keinen Ort haben.
3. Den Hinweis, **`CLAUDE.md` zu lesen und nicht zu überfliegen** — und
   `docs/20` für alles, was Architektur, Rechte oder Gestaltung berührt.
4. Die Ansage, dass **der Betreiber die Serverbefehle fährt** und Ausgaben und
   Bilder zurückschickt — insbesondere für Schritt 0 (die Messrunde auf den
   drei fehlenden Plattformen) und für jeden Abnahmelauf.
5. Dass **Bilder in beiden Themes bei 390 und 1440 px** dazugehören, sobald
   etwas Sichtbares entsteht, und dass `tests/bilder-messen.js` die Vorschrift
   dafür ist.
6. Die Entscheidung aus §9, ob das Wegwerf-Gestell ins Repo soll.

**Der erste Handgriff ist nicht A5, sondern Schritt 1 aus §4** — der apt-Befund
an bestehendem Code. Er wartet auf kein Merkmal.
