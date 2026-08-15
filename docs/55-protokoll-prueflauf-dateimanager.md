# 55 — Protokoll des Prüflaufs aus `docs/54`

**Gefahren auf `cloudsrv24` gegen das Abonnement `p6-b.invalid`**, ab dem
15. August 2026. Der Lauf steht in `docs/54`.

| Sache | Wert |
|---|---|
| Abonnement | `p6-b.invalid` |
| Systembenutzer | `p1136` (uid 1001, gid 1001) |
| Verzeichnis | `/var/www/vhosts/p6-b.invalid` |
| Fassung vorher | `v0.6.0-rc.1` |
| Fassung nachher | `v0.6.0-rc.2` |

**Dieses Protokoll entsteht während des Laufs und nicht danach.** Jeder Punkt
bekommt seine Zeile, sobald er gefahren ist — mit dem gemessenen Wert und nicht
mit „ok".

---

## Punkt 1 (a) — der Baum vor dem Update

Gemessen gegen `v0.6.0-rc.1`, vor `srvpanel update`.

| Verzeichnis | Eigentümer:Gruppe | uid:gid | Modus |
|---|---|---|---|
| *(Abo-Wurzel)* | `root:root` | `0:0` | `755` |
| `httpdocs` | `p1136:www-data` | `1001:33` | `750` |
| `logs` | `p1136:adm` | `1001:4` | `750` |
| `tmp` | `p1136:p1136` | `1001:1001` | `700` |
| `conf` | `root:root` | `0:0` | `755` |
| `.ssh` | `p1136:p1136` | `1001:1001` | `700` |
| `mail` | `p1136:p1136` | `1001:1001` | `700` |

```
seite=403
```

### Was der Vorher-Wert klarstellt, und zwar gegen die Erwartung

**Die Gruppen stimmen schon.** `httpdocs` trägt `www-data`, `logs` trägt `adm` —
auf `rc.1`, also vor 6c. Das war nach `docs/53` Befund 3 nicht selbstverständlich
und schärft, was 6c überhaupt ändert: **nicht die Gruppe, sondern das
setgid-Bit** (750 gegen 2750, 700 gegen 2700) und die bedingungslose Anwendung
in `WebSiteApply`.

Kein Verzeichnis trägt heute setgid. Damit steht der Unterschied fest, den
Punkt 1 (c) messen muss — und Punkt 1 (b) muss ihn **nicht** zeigen.

**Und die Abo-Wurzel ist `root:root 755`.** Das ist die Vorbedingung, die
OpenSSH für `ChrootDirectory` verlangt (`docs/54` Punkt 1): Eigentum bei root,
für Gruppe und Andere nicht schreibbar. Schritt 8 fällt nicht darüber.

---

## Befund 1 — Befund 3 aus `docs/53` steht live auf dem Server

**Mein erster Verdacht zum `seite=403` war „keine Indexdatei". Falsch.**
`ls -la` zeigt sie:

```
drwxr-x--- 3 p1136 www-data     4096  .
-rw-r--r-- 1 p1136 p1136         249  cve-import-vorlage.csv
-rw-r--r-- 1 p1136 p1136     2744320  homematic-3.87.6.20260404-…sbk
-rw-r----- 1 p1136 p1136        1114  index.html
drwxr-x--- 2 p1136 p1136        4096  test2
```

`httpdocs` gehört `p1136:www-data` mit `750` — nginx läuft als `www-data`, ist
in der Gruppe und **kommt durch das Verzeichnis**. Dahinter steht eine
`index.html` mit `p1136:p1136` und `0640`: Gruppe `p1136`, kein Weltbit.

**Das ist Befund 3 aus `docs/53`, wörtlich, an einer echten Kundenseite:**

> Er setzt `0640` — dieselbe Angabe, die die Willkommensseite daneben trägt und
> die dort funktioniert — und bekommt einen 403.

Die beiden anderen Dateien daneben tragen `0644` und wären lesbar. Der
Unterschied ist **nur** das Weltbit, und genau darauf stand die Auslieferung bis
6c: nicht auf der Gruppe, sondern auf „für alle lesbar".

> **Ein Schutz, der nur wirkt, solange die Datei für alle lesbar ist, ist
> keiner — er ist die Abwesenheit einer Einstellung.**

### Und der 403 verschwindet mit dem Update nicht

**Nachgesehen, nicht vermutet.** `WebSiteApply` ruft
`Filesystem::directory($site->documentRoot(), …)`, und die Methode fasst
ausschliesslich das Verzeichnis an:

```php
chown($path, $owner);
chgrp($path, $groupExists ? $group : $owner);
chmod($path, $mode);
```

Kein Abstieg. Und das setgid-Bit wirkt auf **neu angelegte** Einträge, nicht auf
vorhandene. Nach Update und `srvpanel vhost --sites` trägt `index.html` also
weiter `p1136:p1136 0640` und antwortet weiter mit **403**.

**6c repariert den Bestand nicht, sondern nur die Zukunft.** Das ist eine
Eigenschaft und kein Fehler — aber sie war nirgends aufgeschrieben, und sie hat
eine Folge, die der Kunde sieht: Jede Datei, die er vor dieser Fassung über den
Dateimanager angelegt und auf `0640` gestellt hat, bleibt unerreichbar, während
der Rechte-Editor daneben sagt, der Webserver könne sie ausliefern.

Ob daraus eine einmalige Wanderung wird (`chgrp` über den Bestand) oder eine
benannte Grenze, ist eine Entscheidung des Betreibers und kein Nebenprodukt
dieses Laufs. **Hier wird sie gemessen, nicht behoben.**

---

## Befund 2 — der Vorher-Wert von Punkt 1 war schon ein Fehlercode

Unabhängig von der Ursache gilt, was in `docs/54` schon steht: Ein
Vorher-Wert von `403` kann den Fehler nicht anzeigen, auf den Punkt 1 wartet.
Brechen die Rechte durch 6c, antwortet nginx **auch** mit 403.

> **Ein Vorher-Wert, der schon ein Fehler ist, kann den Fehler nicht anzeigen,
> auf den man wartet.**

**Die Behebung ist gleichzeitig die Gegenprobe zu Befund 1.** Statt die Datei
auf `0644` zu stellen — das bewiese nichts über die Gruppe — bekommt sie die
Gruppe und behält `0640`:

```bash
chgrp www-data "$ABO"/httpdocs/index.html
```

### Gefahren, und die Diagnose ist damit gemessen

```
-rw-r----- 1 p1136 www-data 1114 …/httpdocs/index.html
seite=200
```

| | Eigentümer:Gruppe | Modus | Seite |
|---|---|---|---|
| vorher | `p1136:p1136` | `0640` | **403** |
| nachher | `p1136:www-data` | `0640` | **200** |

**Eine einzige Veränderliche, und sie hat den Ausschlag gegeben.** Der Modus ist
derselbe geblieben; getauscht wurde die Gruppe. Damit ist Befund 1 keine
Deutung eines Symptoms mehr, sondern eine Messung — und der Weg, den 6c
einschlägt, ist als der richtige belegt: Ein `httpdocs` mit setgid und
`www-data` bringt neue Dateien von selbst in genau diesen Zustand.

> **Zwei Zahlen, zwischen denen sich genau eine Sache geändert hat, sind eine
> Ursache. Zwei, zwischen denen sich das Update geändert hat, sind eine
> Vermutung.**

Und die Datei steht jetzt so da, wie 6c eine neue anlegen wird — damit ist sie
der stabile Nachbar, an dem sich Punkt 1 (b) und (c) messen.

---

## Punkt 1 (b) — nach dem Update, **ohne** `--sites`

`srvpanel --version` → `0.6.0-rc.2`.

| Verzeichnis | Eigentümer:Gruppe | uid:gid | Modus |
|---|---|---|---|
| *(Abo-Wurzel)* | `root:root` | `0:0` | `755` |
| `httpdocs` | `p1136:www-data` | `1001:33` | `750` |
| `logs` | `p1136:adm` | `1001:4` | `750` |
| `tmp` | `p1136:p1136` | `1001:1001` | `700` |
| `conf` | `root:root` | `0:0` | `755` |
| `.ssh` | `p1136:p1136` | `1001:1001` | `700` |
| `mail` | `p1136:p1136` | `1001:1001` | `700` |

```
seite=200
```

**Identisch mit (a), bis auf die Seite** — und die steht auf 200, weil die
Gegenprobe zu Befund 1 sie dorthin gebracht hat, nicht das Update.

**Damit ist `docs/54 §1.1` gemessen statt behauptet.** Kein Verzeichnis trägt
setgid, kein Modus hat sich bewegt: `postinstall` ruft `srvpanel vhost` ohne
`--sites`, und `WebSiteApply` läuft beim Update nicht. Hätte ich diesen Schritt
nicht getrennt gefahren, stünde jetzt nach (c) eine veränderte Tabelle da — und
niemand wüsste, ob das Update sie geschrieben hat oder der Aufruf danach.

> **Zwei Ursachen, die man nicht trennt, sind eine Vermutung mit zwei Namen.**

### Der Hinweis aus dem Update

```
Entpacken von srvpanel (0.6.0~rc.2) über (0.6.0~rc.1) ...
dpkg: Warnung: Altes Verzeichnis »/opt/srvpanel/releases/0.6.0-rc.1«
      kann nicht gelöscht werden: Directory not empty
```

**Erwartet und im Quelltext begründet.** dpkg entfernt beim Update nur die
Dateien aus dem Paket; was zur Laufzeit entstand — `bootstrap/cache` — hält das
Verzeichnis am Leben. `prune_releases()` in `postinstall.sh` räumt danach jedes
Fassungsverzeichnis ab, das nicht `current` ist.

**Das ist gelesen und nicht gemessen**, und deshalb steht es hier als offene
Zeile und nicht als erledigt: Ob `/opt/srvpanel/releases/0.6.0-rc.1` wirklich
fort ist, sagt nur ein Blick auf die Platte.

---

## Punkt 1 (c) — der Aufruf ist gefahren, die Messung nicht

```
Server-Block der Oberfläche geschrieben: /etc/nginx/conf.d/srvpanel.conf
  (Port 8443, Zertifikat von Let's Encrypt).
  1 Server-Blöcke der Kundendomains eingereiht.
  1 davon haben noch kein Zertifikat — für sie wird danach eines bestellt.
```

Die `stat`-Tabelle danach: **unverändert**. `httpdocs` weiter `750`, `logs`
`750`, `tmp`/`.ssh`/`mail` `700`. Kein setgid.

### Befund 3 — die Messung ist der Warteschlange davongelaufen

**„eingereiht", nicht „geschrieben".** `srvpanel vhost --sites` legt je
Kundendomain einen Vorgang in die Warteschlange; ausgeführt wird er vom
Arbeiter. Das `stat` lief unmittelbar danach und hat damit den Zustand
**davor** abgelesen.

> **Eine Messung, die unmittelbar nach dem Einreihen läuft, misst den Zustand
> davor.**

Das ist die dritte Fassung derselben Familie in diesem Projekt. `docs/48` hält
die beiden anderen fest:

> Eine Frage an den Bestand, die beim Einreihen gestellt wird, kennt die anderen
> Vorgänge derselben Reihe nicht.

> Eine Reihenfolge, die erst beim Ausführen entsteht, kann beim Einreihen niemand
> kennen.

**Und der Fehler steckt in `docs/54` und nicht auf dem Server.** Der Punkt
schreibt `srvpanel vhost --sites` und `stat` in denselben Block, als liefe das
eine nach dem anderen. Für den Server-Block der *Oberfläche* stimmt das — der
läuft unmittelbar —, für die Kundendomains nicht. **Zwei Hälften eines Befehls
mit verschiedener Ausführungsart, und die Ausgabe sagt es in einem Wort, das
man überliest.**

Der Punkt bekommt deshalb ein Warten auf den Vorgang und nicht ein `sleep`:
Eine feste Wartezeit ist beim nächsten langsameren Server wieder zu kurz.

### Befund 4 — zwei Zahlen mit dem falschen Wort, in derselben Ausgabe

```
1 Server-Blöcke der Kundendomains eingereiht.
1 davon haben noch kein Zertifikat — für sie wird danach eines bestellt.
```

**„1 Server-Blöcke" und „1 … haben".** Beides ist der Fehler aus `docs/48 §3.3`
— „geschätzt 1 Zeilen" —, und beides steht in einem Kommando und damit an einer
Stelle, an die `CountedNounTest` nicht sieht: Der Wächter liest
`resources/js/**/*.vue`.

> **Ein Wächter, der eine Oberfläche prüft, sagt nichts über die zweite
> Oberfläche.** Ein Konsolenkommando ist eine.

Das ist die vierte Fundstelle dieser Art nach der Konsole, dem Protokoll und der
Planvorlage. Wieder gilt der Satz von damals:

> **Ein Fehler, der an vier Stellen unabhängig gemacht wurde, ist keine
> Unachtsamkeit, sondern eine fehlende Stelle.**

### Erledigt: der dpkg-Hinweis

```
$ ls -1 /opt/srvpanel/releases/
0.6.0-rc.2
$ readlink -f /opt/srvpanel/current
/opt/srvpanel/releases/0.6.0-rc.2
```

**`prune_releases()` hat abgeräumt.** Die Warnung beim Entpacken ist die
erwartete Zwischenstufe und kein Rest. Gemessen statt gelesen; die offene Zeile
aus (b) fällt weg.

### Nebenbei, und es wird fehlschlagen

„1 davon haben noch kein Zertifikat — für sie wird danach eines bestellt."
`p6-b.invalid` ist eine **nicht auflösbare** Domain (RFC 2606); die
ACME-Bestellung dafür kann nicht gelingen. Der fehlgeschlagene Vorgang, der
gleich in der Liste steht, gehört zu diesem Umstand und **nicht** zu einem
Befund dieses Laufs.

---

## Punkt 1 (c) — gefahren, und die Erwartung war falsch

```
Warteschlange leer nach 0s
625  acme.certificate.issue  failed     Die Zertifizierungsstelle lehnte ab …
624  web.site.apply          succeeded  fertig
623  php.pool.apply          succeeded  fertig
622  subscription.remove     succeeded  fertig
621  acme.certificate.issue  failed     … Domain name does not end with a valid public suffix
```

| Verzeichnis | Eigentümer:Gruppe | Modus (b) | Modus (c) |
|---|---|---|---|
| *(Abo-Wurzel)* | `root:root` | `755` | `755` |
| `httpdocs` | `p1136:www-data` | `750` | **`2750`** |
| `logs` | `p1136:adm` | `750` | `750` |
| `tmp` | `p1136:p1136` | `700` | `700` |
| `conf` | `root:root` | `755` | `755` |
| `.ssh` | `p1136:p1136` | `700` | `700` |
| `mail` | `p1136:p1136` | `700` | `700` |

```
seite=200
```

**`web.site.apply` ist gelaufen und gelungen, und `httpdocs` trägt setgid.**
Das ist die Aussage, für die Punkt 1 gebaut ist: 6c erreicht den Bestand, an
der Stelle, an der es zählt. Die Seite liefert weiter aus.

### Befund 5 — die Erwartungstabelle in `docs/54` war falsch, nicht der Server

Sie sagte, `logs` werde `2750` und `tmp`/`.ssh`/`mail` würden `2700`. **Keines
davon trifft zu, und beides ist richtig so.**

`WebSiteApply` fasst genau zwei Verzeichnisse an:

```php
Filesystem::directory($documentRoot, $site->user, DOCUMENT_ROOT_GROUP, DOCUMENT_ROOT_MODE);
Filesystem::directory($site->logDir(), $site->user, 'adm', 0o2750);
```

und `Site::logDir()` ist **`<abo>/logs/<domain>`** — ein Unterverzeichnis je
Domain, nicht `logs` selbst:

```php
return $this->subscriptionRoot().'/logs/'.$this->domain;
```

`logs`, `tmp`, `conf`, `.ssh` und `mail` stehen ausschliesslich in
`SubscriptionProvision::TREE` und werden beim **Anlegen** des Abonnements
gesetzt. Ich habe die beiden Listen gelesen, als wären sie eine.

> **Zwei Listen, die dasselbe Schema beschreiben, werden von zwei verschiedenen
> Vorgängen angewandt — und nur eine davon läuft nachträglich.**

### Und daraus die Grenze, die niemand aufgeschrieben hatte

Für ein **bestehendes** Abonnement erreicht das setgid-Bit ausschliesslich
`httpdocs` und `logs/<domain>`. `logs`, `tmp`, `.ssh` und `mail` behalten ihren
alten Modus **dauerhaft**: `subscription.provision` wird von
`SubscriptionController::store()` beim Anlegen gerufen und von keiner Stelle
noch einmal.

**Funktional ist das folgenlos**, und das steht schon in `TREE`:

> Hier ändert das Bit nichts — Eigentümer und Gruppe sind dieselben —, und es
> steht trotzdem da: Eine Regel, die für „alle Verzeichnisse des Kunden" gilt,
> muss beim nächsten Zuwachs des Schemas nicht neu beurteilt werden.

Bei `tmp`, `.ssh` und `mail` sind Eigentümer und Gruppe dasselbe; das Bit ändert
nichts. Bei `logs` trägt zwar `adm`, aber was darin entsteht, sind die
`logs/<domain>`-Verzeichnisse, und die setzt `WebSiteApply` selbst.

**Was bleibt, ist ein Bestand in zwei Zuständen**, den nichts wieder
zusammenführt: Ein Abonnement von vor dieser Fassung sieht dauerhaft anders aus
als ein neues. Das ist kein Fehler und gehört trotzdem benannt — sonst rätselt
der Nächste vor derselben `stat`-Ausgabe.

### Erledigt: das leere Protokollverzeichnis

Der `tail` aus Punkt 1 (a) lief auf `logs/*error*.log` und fand nichts. **Der
Glob stand eine Ebene zu hoch:** Die Protokolle liegen unter
`logs/<domain>/error.log`. Die offene Zeile fällt weg.

### Der fehlgeschlagene ACME-Vorgang

```
Die Zertifizierungsstelle lehnte ab (rejectedIdentifier). — Cannot issue for
"p6-b.invalid": Domain name does not end with a valid public suffix (TLD)
```

**Erwartet, und die Meldung ist gut.** Sie nennt den Namen, den Grund und die
Stelle, die abgelehnt hat. Kein Befund dieses Laufs.

---

## Punkt 2 — angehalten: „Datei anlegen" ist kaputt

`logs/p6-b.invalid` → `p1136:adm 2750`. **Punkt 1 ist damit vollständig.**

Beim Anlegen von `p6-probe.txt` über den Dateimanager:

```
Das Formular wurde nicht gespeichert.
The content field must be a string.
```

## Befund 6 — eine leere Datei lässt sich nicht anlegen

**Der Griff war seit Schritt 5e gebaut und hat nie funktioniert.**

Laravels globaler Middleware-Stapel enthält `ConvertEmptyStringsToNull`;
`bootstrap/app.php` nimmt ihn nicht heraus. Das Formular schickt
`content: ''`, daraus wird `null` — **bevor** die Prüfung läuft. `present` ist
damit erfüllt (der Schlüssel ist da), `string` nicht.

> **Eine Regel, die den leeren Wert verbietet, verbietet genau den Fall, für den
> der Griff gebaut ist.**

**Es traf beide Wege**: das Anlegen aus der Liste und das Speichern einer Datei,
deren Inhalt jemand im Editor gelöscht hat.

### Warum kein Wächter das gefunden hat

`FileCreationTest` hat drei Prüfungen zu genau diesem Griff, und alle drei sind
grün — sie lesen den **Quelltext**: dass der Knopf da ist, dass der Controller
die Antwort des Agenten liest, dass jede hochgeladene Datei ihren Namen behält.
Keine davon schickt eine Anfrage, und ohne Anfrage läuft keine Middleware.

> **Ein Wächter, der Quelltext liest, sieht nichts, was erst zwischen Browser
> und Controller passiert.**

Das ist die vierte Stufe in Folge, in der ein Fehler nur im Browser auf einem
echten Server sichtbar wurde — `docs/45`, `docs/48`, `docs/53` und jetzt hier.

**Behoben** (`'present', 'nullable', 'string'` und `?? ''`), mit Wächter
(`FileCreationTest::test_an_empty_file_may_be_created`) und zwei Brüchen, beide
zubeissend. **Nachzuprüfen im Browser gegen die nächste Fassung** — hier gilt
derselbe Satz wie in `docs/48`: Ein behobener Befund ist erst behoben, wenn er
am selben Ort noch einmal gemessen wurde.

## Befund 7 — die Meldung ist englisch

`The content field must be a string.` steht unter der deutschen Zeile „Das
Formular wurde nicht gespeichert." Es gibt **kein `lang/`-Verzeichnis** in
diesem Repo; Laravels eingebaute Prüfmeldungen kommen damit auf Englisch.

`docs/19 §4a` ist bindend: **alle Texte der Oberfläche sind deutsch.** Bisher
ist das nie aufgefallen, weil jede Meldung, die ein Kunde zu sehen bekam, aus
diesem Projekt stammte und von Hand geschrieben war — `ValidationException::withMessages()`
mit eigenem Satz. Eine Regel wie `string`, `max` oder `array` formuliert Laravel
selbst.

> **Eine Sprachvorgabe, die nur für selbstgeschriebene Sätze gilt, hält, bis der
> erste fremde Satz durchkommt.**

Der Befund ist **grösser als dieser Lauf**: Er betrifft jede Prüfregel dieses
Panels, nicht nur `content`. Er wird hier benannt und nicht nebenbei behoben —
**Entschieden am 15. August: jetzt, mit den anderen Fixes.** Gebaut sind
`lang/de/validation.php` und `ValidationLanguageTest`, der die benutzten Regeln
aus `app/` **abzählt** statt sie aufzulisten — eine Liste im Test wäre beim
nächsten `mimes:` unvollständig, und zwar lautlos, weil eine fehlende
Übersetzung nicht scheitert, sondern auf Englisch zurückfällt.

> **Ein Rückfall, der lesbar ist, meldet sich nie.**

### Und der zweite Teil des Befundes wog schwerer als der erste

**`config/app.php` stand auf `'locale' => env('APP_LOCALE', 'en')`.** Die Datei
`lang/de` hätte dagelegen und wäre nie gelesen worden.

`.env.example` setzt zwar `APP_LOCALE=de` — aber die Datei, die auf dem Server
gilt, ist `/etc/srvpanel/panel.env`, und die schreibt `PanelProvision`, **ohne
`APP_LOCALE` zu setzen**. Auf jedem installierten Panel griff damit die
Voreinstellung, und das Gebietsschema stand seit P0 auf Englisch.

> **Eine Beispieldatei und die Datei, die gilt, sind zwei Quellen — und die
> zweite ist die, nach der niemand sieht.**

Die Voreinstellung steht jetzt auf `de`, und
`ValidationLanguageTest::test_the_application_speaks_german` hält sie fest.
Ohne diesen zweiten Wächter wäre die Übersetzung genau der Fehler, den dieses
Projekt am häufigsten macht: eine Zeichenkette, die auf etwas verweist, ohne
dass jemand den Bezug prüft.

**Was benannt offen bleibt:** `attributes` ist leer. „Das Feld content muss eine
Zeichenkette sein." ist besser als der englische Satz und noch nicht richtig.
Es sind **106** Feldnamen; sie bekommen ihre eigene Runde, damit die Zuordnung
vollständig ist und nicht halb.

## Befund 8 — der Dateimanager steht in keinem Menü

**Vom Betreiber gemeldet, im selben Atemzug.** Er ist über
`Abonnements → Name → Dateien` erreichbar; `Domains` und `Datenbanken` stehen
dagegen seit P3 und P5 im Menü, sobald ein **aktives Abonnement** da ist — mit
genau der Begründung, die hier wieder gilt:

> Drei Klicks für die Sache, wegen der er das Panel überhaupt öffnet.

Das ist die Fortsetzung von Befund 6 aus `docs/53` („der Dateimanager ist gebaut
und von nirgendwo aus erreichbar"). Damals bekam er **einen** Weg; dass dieser
Weg drei Klicks tief liegt, war damit nicht beantwortet.

**Die Frage, die dabei zu entscheiden war:** `Domains` und `Datenbanken` sind
mandantengeklammerte **Listen** unter einer festen Adresse. Der Dateimanager ist
es nicht — er hängt an *einem* Abonnement, weil jedes sein eigenes Chroot hat.
Ein Menüpunkt braucht also eine Antwort auf „welches".

**Entscheidung des Betreibers vom 15. August: direkt hinein, sonst Auswahl.**
Gebaut als `GET /files` → `FileController::pick()`: Bei genau einem erreichbaren
Abonnement führt der Punkt sofort in dessen Dateimanager, bei mehreren auf eine
schmale Auswahlseite.

> **Eine Frage, die nur eine mögliche Antwort hat, ist keine Frage.**

Die Route trägt kein `can:` und steht mit Begründung in `RouteGuard`: Sie hätte
kein Objekt, an dem eine Fähigkeit ansetzen könnte. Gefiltert wird je Abonnement
über **dieselbe** Policy, die die Zielseite später anwendet — nicht über eine
zweite Fassung der Regel.

**Nur im Kundenmenü, nicht im Betreibermenü.** Für einen Admin ist „welches" bei
tausend Kunden keine Auswahlliste mehr, sondern die Abonnementsliste, die es
schon gibt.

**Und `MobileLayoutTest` hat beim Bauen zugebissen:** Die Leerzeile der neuen
Seite trug weder `data-column` noch `colspan` und hätte auf dem Telefon ohne
Beschriftung dagestanden.

---

## Befund 9 — die Behebung von Befund 8 hat den Wächter von Befund 6 entwaffnet

Gefunden vom Bruchlauf in der CI zu PR #131, nicht auf dem Server.

`LinkReachTest` ist in Schritt 5b **genau für Befund 6 aus `docs/53`** gebaut
worden: „Der Dateimanager ist gebaut und von nirgendwo aus erreichbar." Sein
Bruch nimmt dem Abonnement-Bildschirm den Verweis weg und erwartet Rot.

**Seit der Dateimanager ausserdem im Menü steht, gibt es einen zweiten Weg** —
und der Bruch entfernte nur den ersten. Der Wächter war grün, und zwar zu
Recht: Die Seite *war* erreichbar.

> **Ein Bruch, der einen von zwei Wegen entfernt, prüft nicht die Erreichbarkeit
> — er prüft, dass es den zweiten Weg gibt.**

Und die Lehre darüber, die über diesen Fall hinausgeht:

> **Die Behebung eines Befundes kann den Wächter eines älteren entwaffnen** —
> ohne dass jemand eine Zeile an ihm ändert.

### Und dahinter lag ein zweiter, der schwerer wiegt

Der Bruch entfernte danach **beide** Wege — und der Wächter blieb **immer noch**
grün. Der Grund steht in meinem eigenen Kommentar von heute Vormittag, in
`PanelLayout.vue`:

> Die Adresse ist `/files` und nicht `/subscriptions/…/files`

`LinkReachTest` liest die **ganze** Datei — mit Absicht, denn eine Adresse steht
genauso oft in einem `router.get(…)` wie in einem `:href`. Sie steht aber auch
in einem Erklärtext, und der führt nirgendwohin.

> **Ein Wächter, der Quelltext nach Adressen durchsucht, findet sie auch dort,
> wo jemand über sie schreibt.**

**Das hatte dieses Projekt schon einmal**, an `PanelRequestTest`: Sein erster
Wurf fand die gesuchte Kopfzeile im eigenen Klassenkopf. Die Lösung —
`withoutComments()` — steht dort seit Schritt 5g im Repo. Sie war da; sie stand
nur an einer Stelle, an die beim Bauen von `LinkReachTest` niemand gesehen hat.

> **Eine Lösung, die im Repo steht, ist nicht dieselbe wie eine, die angewandt
> wird.**

Behoben: `LinkReachTest` streicht jetzt Kommentare, der Bruch entfernt beide
Wege, und beides ist gegengeprüft — vorher grün, nachher rot, danach wieder
grün. Alle zweiunddreissig Seiten bleiben erreichbar.

---

## Nachprüfung gegen `v0.6.0-rc.3`

**Ein behobener Befund ist erst behoben, wenn er am selben Ort noch einmal
gemessen wurde** (`docs/48`). Gefahren im Browser auf `cloudsrv24`.

### Befund 8 — erfüllt

„Dateien" steht in der Navigation, zwischen „Datenbanken" und „Vorgänge", mit
dem Blatt als Zeichen. Der Klick führt **direkt** in `p6-b.invalid` — keine
Auswahlseite, weil es nur ein Abonnement gibt. Genau das war die Entscheidung
des Betreibers.

### Befund 6 — erfüllt, und zwar in **beiden** Hälften

1. **Anlegen:** „Die Datei ist angelegt.", und der **Editor** ist offen mit
   `/httpdocs/p6-probe.txt`. Nicht die Liste — das war die zweite Zusage dieses
   Griffs.
2. **Speichern mit leerem Inhalt:** „Die Datei ist gespeichert.", die Datei
   steht mit **0 B** in der Liste.

Die zweite Hälfte war nicht verlangt und ist der wertvollere Beleg: Genau dieser
Weg — eine Datei mit leerem Inhalt speichern — scheiterte vor dem Fix an
derselben Regel wie das Anlegen. Beide sind gemessen.

### Und was die Liste nebenbei zeigt

`p6-probe.txt` trägt `rw-r--r--`, also **0644** — das legt `files.write` an. Die
Mehrfachauswahl aus 5h steht da: Haken in der Kopfzeile und je Zeile, Baum
links, Krümel oben.

**Der Modus ist für Punkt 2 die Warnung, nicht das Ergebnis.** Bei `0644` liest
nginx die Datei über das **Weltbit**, und ein `curl` gäbe 200 — unabhängig von
der Gruppe. Das wäre wieder Befund 1: eine Messung, die aus einem anderen Grund
das erwartete Ergebnis liefert.

> **Ein Beleg, der auch ohne die geprüfte Sache zustande kommt, belegt sie
> nicht.**

Punkt 2 misst deshalb zweistufig: erst die **Gruppe** der neu angelegten Datei
(hat das setgid-Bit gewirkt?), dann `0640` und `curl` (kommt nginx über die
Gruppe heran?).

### Befund 7 — noch nicht gemessen

Steht aus. Er braucht eine Laravel-Prüfregel, die zuschlägt; im Browser am
einfachsten über „Umbenennen" mit einem Namen über 255 Zeichen.

---

## Punkt 2 — erfüllt, und zwar von Ende zu Ende

| Zustand | Eigentümer:Gruppe | Modus | Seite |
|---|---|---|---|
| `p6-probe.txt`, neu über den Dateimanager | `p1136:www-data` | `644` | — |
| dieselbe, nach dem Rechte-Editor | `p1136:www-data` | `640` | **200** |

**Die Gruppe ist geerbt.** Die Datei ist durch `files.write` in einem
`httpdocs` entstanden, das seit Punkt 1 (c) setgid trägt — vor 6c hätte sie
`p1136:p1136` getragen, wie die `index.html` daneben es bis heute Vormittag tat.

**Und bei `0640` liefert nginx aus.** Kein Weltbit, kein Rückfall auf „für alle
lesbar" — der Webserver kommt über die **Gruppe** heran. Das ist genau der Fall,
an dem Befund 3 aus `docs/53` gescheitert ist.

Zusammen mit Befund 1 sind das zwei Messungen mit einer Veränderlichen, in beide
Richtungen:

| | Gruppe | Modus | Seite |
|---|---|---|---|
| `index.html` (alt, vor 6c angelegt) | `p1136` | `0640` | 403 |
| `index.html` nach `chgrp www-data` | `www-data` | `0640` | 200 |
| `p6-probe.txt` (neu, mit setgid) | `www-data` | `0640` | 200 |

Der Satz des Rechte-Editors — „Der Webserver kann diese Datei ausliefern." — ist
damit zum ersten Mal **nachweislich** wahr.

---

## Befund 10 — der Abstand fehlt zum siebten Mal, und diesmal war er unsichtbar

**Vom Betreiber gemeldet**, im selben Atemzug wie das Ergebnis von Punkt 2:
Zwischen „Speichern"/„Abbrechen" des Rechte-Editors und der Liste darunter war
nichts.

Gemessen im gebauten Stylesheet: **0px**. Mit der Behebung 24px bei 390px und
26px ab 800px — das ist `--block-gap`, derselbe Abstand wie unter jeder Meldung.

### Warum `BlockSpacingTest` ihn nicht sehen konnte

**Aus zwei Gründen gleichzeitig, und beide sind Löcher zwischen seinen
Rastern.** Das Formular des Rechte-Editors trug **keine Klasse** — der Wächter
paart Klassen, also passte es in keine der beiden Listen. Und sein letztes Kind,
die Knopfreihe, hat **kein Geschwister**, weil es das letzte ist.

> **Ein Baustein ohne Klasse steht in keiner Liste — auch nicht in der der
> Fehler.**

### Behoben an beiden Enden

**Die Gestaltung:** Das Formular heisst jetzt `.block` und bringt seine Luft in
**beide** Richtungen mit. Kein Nachbarschaftseintrag — ein Block, der oben und
unten Abstand hat, braucht keine Liste von Nachbarn, und genau solche Listen
sind sechsmal hintereinander unvollständig gewesen.

**Der Wächter:** Ein klassenloser Behälter reicht seine **Kante** durch — er
endet, wo sein letztes Kind endet, und fängt an, wo sein erstes anfängt. Damit
sieht er neun Fugen mehr; die Liste wächst von 31 auf 40, und **keine einzige
ist weggefallen**. Sie wächst, weil der Wächter mehr sieht, nicht weil die
Gestaltung schlechter wurde.

### Und der erste Wurf der Behebung hat den Satz gebrochen, der im selben Kopf steht

Ein **benannter Platz** (`<template #actions>`) ist auch ein klassenloser
Behälter — und er verschiebt seinen Inhalt in die Kopfzeile der Seite. Die
Durchsichtigkeit hat ihn prompt aufgemacht: Der letzte Knopf aus `#actions`
wurde wieder zum Nachbarn dessen, was im Quelltext darunter steht. **Dieselben
drei Scheinnachbarn**, die der Umbau vom 14. August schon einmal beseitigt
hatte.

> **Zwei Dinge, die im Quelltext gleich heissen, sind im Browser nicht
> dasselbe.** Der Satz steht im Kopf dieses Wächters, und ich habe ihn eine
> Methode weiter unten gebrochen.

Der benannte Platz trägt deshalb jetzt eine Marke und bleibt undurchsichtig. Drei
Brüche dazu, alle zubeissend — der zweite über die Sperrklinke der Liste, die
meldet, wenn eine bekannte Fuge verschwindet.

---

## Befund 11 — die Übersetzung lag im Repo und nicht im Paket

**Befund 7 war nicht behoben.** Gegen `v0.6.0-rc.3` steht unter der deutschen
Zeile weiter:

```
Das Formular wurde nicht gespeichert.
The name field must not be greater than 255 characters.
```

Die Regel greift — es ist `max:255` aus dem neuen `rename`, also genau der
gesuchte Fall. Nur der Satz kommt weiter von Laravel.

### Zwei richtige Prüfungen, und der Fehler dazwischen

`lang/de/validation.php` liegt im Repo. `config/app.php` steht auf `de`. Beide
Wächter sind grün. **Und `packaging/build.sh` führt eine Positivliste der
Verzeichnisse, die ins Paket wandern:**

```sh
agent app bootstrap config database public resources/views routes vendor …
```

`lang` stand nicht darin. Die Datei hat den Server nie erreicht.

> **Eine Datei, die ein Wächter im Repo prüft, ist damit noch nicht auf dem
> Server.**

Das ist derselbe Schnitt wie bei `PackagingTest` — dort ruft eine systemd-Unit
ein Kommando über eine Zeichenkette auf —, nur eine Ebene tiefer: Hier lädt das
Framework ein Verzeichnis über eine **Konvention**. Beide Male hält nichts den
Bezug ausser einem Test, der ihn nachrechnet.

**Und die Liste schweigt, wenn etwas fehlt.** `build.sh` überspringt jeden
Eintrag, den es nicht findet (`if [ -e … ]`). Ein Tippfehler im Namen baut ein
Paket ohne die Datei und meldet Erfolg — deshalb ist der neue Wächter ein Test
über den **Text** der Liste und nicht über das Ergebnis des Baus.

### Was dieser Befund über die Reihenfolge sagt

Er wäre **nicht** gefunden worden, wenn ich Befund 7 im Container für erledigt
erklärt hätte. Drei Wächter waren grün, die Datei war da, die Konfiguration
stimmte — und der Kunde las weiter Englisch.

> **Drei grüne Prüfungen über drei Stufen sagen nichts über die vierte.**

Behoben: `lang` steht in der Positivliste, und
`ValidationLanguageTest::test_the_translations_are_shipped` rechnet es nach.
Gegengeprüft — ohne den Eintrag ist er rot.

**Nachzuprüfen gegen die nächste Fassung.** Bis dahin bleibt Befund 7 offen und
zählt nicht als erledigt.

---

## Punkt 3 — der Schema-Schutz, erfüllt

Acht Aufrufe, acht Absagen — und **jede mit dem Verb ihres Vorgangs**:

```
/httpdocs: … und wird nicht entfernt. Sein Inhalt lässt sich ändern.
/logs:     … und wird nicht entfernt. …
/conf:     … /.ssh: … /tmp: … /mail: …
move:      … und wird nicht verschoben oder umbenannt. …
chmod:     … und wird nicht in seinen Rechten geändert. …
```

Das ist kein Schmuck: `Scheme::protect()` bekommt das Verb vom Aufrufer, und
eine Absage, die „wird nicht entfernt" sagt, während jemand `chmod` versucht
hat, wäre eine Auskunft über den falschen Vorgang.

**Und der zweite Satz steht in jeder Absage:** „Sein Inhalt lässt sich ändern."
Ein blosses „darf nicht" liesse den Lesenden mit der Frage zurück, was er denn
tun soll.

### Die Gegenprobe — der eigentliche Zweck von 5f

```
total 2700
drwxr-s--- 3 p1136 www-data     4096 Aug 15 11:51 .
-rw-r--r-- 1 p1136 p1136         249 cve-import-vorlage.csv
-rw-r--r-- 1 p1136 p1136     2744320 homematic-…sbk
-rw-r----- 1 p1136 www-data     1114 index.html
-rw-r----- 1 p1136 www-data        0 p6-probe.txt
drwxr-x--- 2 p1136 p1136        4096 test2
/var/www/vhosts/p6-b.invalid/httpdocs 2750
```

**Fünf Einträge, unverändert.** Nichts leergeräumt, nichts halb entfernt.

Vor 5f lief `Filesystem::removeTree()` erst durch — jedes `unlink` gelang, weil
der Inhalt dem Kunden gehört — und scheiterte dann am abschliessenden `rmdir`,
weil die Vhost-Wurzel `root:root` gehört. Gemeldet wurde „liess sich nicht
vollständig entfernen", und die Webseite war weg.

> **Eine Absage, die erst nach der Wirkung kommt, ist keine.**

### Zwei Dinge, die dieselbe Ausgabe nebenbei belegt

**`drwxr-s---`** — das `s` an der Gruppenstelle ist das setgid-Bit. Punkt 1 (c)
ist damit ein zweites Mal sichtbar, aus einer anderen Richtung.

**`index.html` und `p6-probe.txt` tragen beide `www-data`**, `test2` und die
beiden alten Dateien nicht. Das ist die gemischte Bevölkerung aus §1.1, an einem
einzigen `ls` ablesbar: Was 6c erreicht hat, und was nicht.

### Die zweite Gegenprobe — der Inhalt ist frei

`makeDirectory`, `write` und `remove` gelingen alle drei, und die Liste danach
zeigt wieder fünf Einträge: `p6-inhalt` ist fort.

**Ohne diese Hälfte wäre der Schutz schlimmer als keiner** — `httpdocs`
leerzuräumen ist genau das, was jemand vor einem neuen Deploy tut.

### Und ein Fund, den ich nicht gesucht habe: setgid vererbt sich nach unten

Das neu angelegte `p6-inhalt` kam mit `mode 1512` zurück — dezimal für **`2750`**
— und `gid 33`, also `www-data`. **Ein Unterverzeichnis erbt das setgid-Bit vom
Elternverzeichnis**, nicht nur die Gruppe. Die darin geschriebene `x.txt` trug
`420` (`0644`) und ebenfalls `gid 33`.

Das ist die gute Nachricht: 6c wirkt nicht nur eine Ebene tief.

**Und daraus folgt eine Frage, die niemand gestellt hat.** `FilesChmod` ruft
`@chmod($path, $mode)` mit **neun** Bits; das ist der Systemaufruf und nicht
GNU-`chmod`, also wird das zehnte Bit gesetzt — auf null. Stellt ein Kunde im
Rechte-Editor ein `httpdocs/bilder` auf `0755`, fällt das geerbte setgid
**lautlos** weg, und jede Datei, die danach darin entsteht, trägt wieder die
Gruppe des Abonnements.

`Scheme::protect()` fängt das nicht ab: Es schützt die sechs Verzeichnisse des
Schemas und ausdrücklich **nicht** ihren Inhalt — und ein `httpdocs/bilder` ist
Inhalt.

> **Ein Bit, das man erbt, aber nicht anzeigt, verschwindet beim ersten Klick auf
> etwas anderes.**

Damit wäre Befund 3 aus `docs/53` eine Ebene tiefer zurück. **Gemessen ist das
noch nicht** — es steht hier als Frage und nicht als Befund.

---

## Punkt 4 — die Mehrfachauswahl, drei von vier Zusagen erfüllt

**Die Rückfrage nennt, was in der Tabelle nicht steht:**

```
3 Einträge entfernen?
Darunter ist ein Verzeichnis — sein Inhalt geht mit.
```

Und in der Abo-Wurzel, mit allen sechs:

```
6 Einträge entfernen?
Darunter sind 6 Verzeichnisse — ihr Inhalt geht mit.
/httpdocs liefert eine Domain aus. Die Seite ist danach nicht mehr erreichbar.
```

**Die DocumentRoot-Warnung wirkt.** Sie ist der Teil, den der Agent nicht leisten
kann — er müsste dafür die vhost-Dateien lesen — und das Panel weiss es.

**Die Zahl steht im ersten Satz:** „Von 3 Einträgen sind 2 entfernt.", und bei
den sechs geschützten „Von 6 Einträgen sind 0 entfernt." Der Baum stand danach
unversehrt da — **5f durch die Oberfläche belegt**, nicht nur durch `tinker`.

**Die Gegenprobe ohne Fehlschlag:** zwei gewöhnliche Dateien allein → grün, „2
Einträge sind entfernt.", keine Fehlerliste.

## Befund 12 — die Gründe je Eintrag erreichen den Browser nicht

**Was fehlt, ist die Zeile darunter.** Bei drei Einträgen wie bei sechs steht nur
der Zählsatz da — kein Pfad, kein Grund.

`report()` baut die Zahl und je Fehlschlag eine Zeile, alle unter dem Feld
`path`. **Inertias Laravel-Anbindung bildet den Fehlerbeutel auf „Feld => erste
Meldung" ab.** Alles nach der Zahl fällt weg, bevor die Seite es sieht.

> **Eine Meldung, die der Controller schreibt, ist damit noch keine, die jemand
> liest.**

### Es traf nicht nur die Mehrfachauswahl

**Der Mehrfach-Upload aus Schritt 5e baut seine Rückmeldung genauso** — „Von 20
Dateien sind 19 hochgeladen." plus je Datei eine Zeile. Die Zeilen waren **seit
dem ersten Tag unsichtbar**. Sein Wächter
(`FileCreationTest::test_a_partly_failed_upload_does_not_report_success`) ist
grün und liest den Quelltext des Controllers.

Das ist dieselbe Familie wie Befund 6:

> **Ein Wächter, der Quelltext liest, sieht nichts, was erst zwischen Browser
> und Controller passiert.**

**Und die Zahl allein ist die schlechtere Hälfte.** Sie sagt, dass etwas
schiefging, und verschweigt was — der Kunde kann nichts daraus machen. Bei den
sechs geschützten Verzeichnissen wäre der Satz „gehört zum Aufbau des
Abonnements … Sein Inhalt lässt sich ändern" genau die Auskunft gewesen, die er
braucht.

**Behoben an beiden Enden:** `HandleInertiaRequests` überschreibt
`resolveValidationErrors()` und verbindet die Meldungen eines Feldes mit `\n`;
`FormErrors` zerlegt sie wieder in Zeilen. Ein verschachteltes Feld wäre die
Alternative gewesen und die schlechtere: Dann müsste **jede** Stelle, die Fehler
liest, mit zwei Formen rechnen, und die eine, die es vergisst, zeigt gar nichts.

Wächter: `BulkActionTest::test_every_reason_survives_the_way_to_the_browser`,
und er prüft **beide** Enden — eines ohne das andere ist eine halbe Kette.

## Befund 13 — der Rechte-Editor nimmt das geerbte setgid-Bit weg

Die Frage aus Punkt 3 ist **im Container** gemessen, nicht auf dem Server —
siehe die Korrektur weiter unten. Ein Unterverzeichnis von `httpdocs` erbt
`2750`; PHPs `chmod($p, 0755)` macht daraus `755`. Das Bit ist fort, und jede
Datei, die der Kunde danach darin anlegt, trägt wieder die Gruppe des
Abonnements. Bei `0640` ist sie für den Webserver unerreichbar: **Befund 3 aus
`docs/53`, eine Ebene tiefer.**

### Die Begründung stand seit 6c im Quelltext

`FilesChmod` erklärt über seinem `Scheme::protect()` genau diese Gefahr — für
`httpdocs`. Dieselbe Überlegung gilt für jedes Verzeichnis **darin**, und dort
schützt `Scheme` ausdrücklich nicht: `httpdocs/bilder` ist Inhalt und kein
Gerüst.

> **Eine Begründung, die für einen Fall aufgeschrieben ist, gilt oft für mehr —
> und wird trotzdem nur dort angewandt.**

**Behoben:** `files.chmod` führt das setgid-Bit eines **Verzeichnisses** mit.
Nicht bei Dateien — dort bedeutet dasselbe Bit die Ausführung unter fremder
Gruppe, und dieses Panel setzt es nirgends. Was es nicht setzt, muss es auch
nicht bewahren.

> **Ein Griff, der neun Bits anbietet, darf das zehnte nicht anfassen.**

`docs/51 §8.2` nennt setgid ausdrücklich als das, was der Rechte-Editor **nicht**
anbietet. Genau deshalb darf er es auch nicht löschen.

### Befund 14 — der Beleg für Befund 13 traf den falschen Gegenstand

Aufgefallen beim Ausschreiben von Punkt 5, an einer Zeile, die schon dreimal
über den Bildschirm gegangen war:

```
-rwxr-xr-x 1 p1136 www-data    0 Aug 15 12:27 p6-bit
```

**`p6-bit` ist eine Datei.** Das führende `-`, die Grösse 0, ein Verweiszähler
von 1 — und in der Liste des Panels steht der Name ohne Schrägstrich und mit
allen drei Griffen, während `p6-fremd/` und `test2/` beides anders zeigen.

Der Beleg für Befund 13 war `stat -c '%U:%G %a'`, und dessen Ausgabe
`p1136:www-data 755` sieht für eine Datei genauso aus wie für ein Verzeichnis.
**Der Typ stand nicht darin.**

Damit belegt er nichts: Eine Datei erbt in einem setgid-Verzeichnis nur die
**Gruppe** und nie das Bit — hier im Container gemessen, `644` in einem
`2750`-Elternverzeichnis. Sie hatte also nie ein setgid-Bit, das ein `chmod 755`
hätte wegnehmen können.

> **Ein Formatbefehl, der den Typ nicht ausgibt, macht aus zwei Gegenständen
> einen.** `%a` zeigt neun Bits, und die stimmen für beide.

**Der Fehler selbst bleibt echt**, und er ist jetzt zum ersten Mal gemessen —
nur eben nicht dort, wo es im Protokoll stand:

```
eltern (2750) → kind erbt 2755, datei erbt 644
chmod($kind, 0755)         → 755      ← das Bit ist fort
chmod($kind, 0755 | 02000) → 2755     ← und so bleibt es
```

### Und der Wächter dazu prüfte die Schreibweise einer Vermutung

`SchemeProtectionTest::test_a_chmod_keeps_the_inherited_setgid_bit` las den
**Quelltext** von `FilesChmod` — dass dort `$mode | $geerbt` steht und `$geerbt`
nur bei Verzeichnissen gefüllt wird. Das ist richtig und war grün, während der
einzige Beleg für die Notwendigkeit dieser Rechnung auf einer Datei stand.

> **Ein Wächter, der Quelltext gegen eine ungemessene Behauptung liest, prüft
> die Schreibweise einer Vermutung.**

Er misst jetzt zuerst und liest danach: dass ein Unterverzeichnis das Bit erbt
(sonst gäbe es nichts zu bewahren), dass eine Datei es **nicht** erbt (genau die
Annahme, die den Beleg umgeworfen hat), dass PHPs `chmod` es nimmt (sonst wäre
die Rechnung grundlos) und dass `| 0o2000` es zurückgibt. Vier Messungen ohne
Chroot und ohne Rechte, dazu die beiden Quelltextprüfungen von vorher.

**Und die erste Fassung dieser Messung war selbst falsch:** Sie verglich den
ganzen Modus des Kindes gegen `2750` statt das Bit gegen `2000`. Geerbt wird das
Bit, nicht der Modus — die neun Rechtebits kommen aus `mkdir` und der umask.
Gefunden hat es der erste Lauf, nicht das Nachdenken.

### Was das für die Nachprüfung heisst

Die Nachprüfung von Befund 13 gegen `rc.4` läuft nicht über `p6-bit`. Sie
braucht ein **Verzeichnis**, über den Dateimanager angelegt, und ein `ls -ld`
statt eines `stat -c %a` — die Ausgabe muss den Typ zeigen, sonst wiederholt sie
denselben Fehler.

## Punkt 5 — Kopieren und Verschieben mit dem Baum, zwei von drei Teilen

### (a) Kopieren — erfüllt

Drei Dateien in `httpdocs` angehakt, „Kopieren", im Baum `tmp` gewählt.

```
3 Einträge sind kopiert.

/var/www/vhosts/p6-b.invalid/tmp/:
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k1.txt
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k2.txt
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k3.txt
```

**Drei Dateien, jede unter ihrem eigenen Namen.** Der Fehler, den der
Mehrfach-Upload einmal gemacht hat — ein vollständiger Zielpfad ist für mehrere
Quellen *ein* Pfad für alle —, ist hier nicht zurück. Läge dort eine Datei mit
dem Inhalt `drei`, wäre er es.

Die Gruppe steht auf `p1136` statt `www-data`, und das ist **richtig**: `tmp`
trägt kein setgid-Bit, also erbt der neue Eintrag die Gruppe des anlegenden
Prozesses. Nur `httpdocs` vererbt `www-data`, und nur dort braucht es das.

### (b) Die Gegenprobe — es wird nicht überschrieben

Dieselben drei, diesmal „Verschieben", wieder nach `tmp`:

```
Das Formular wurde nicht gespeichert.
Von 3 Einträgen sind 0 verschoben.
```

Und beide Seiten unverändert:

```
-rw-r----- 1 p1136 www-data 5 …/httpdocs/p6-k1.txt
-rw-r----- 1 p1136 www-data 5 …/httpdocs/p6-k2.txt
-rw-r----- 1 p1136 www-data 5 …/httpdocs/p6-k3.txt

/var/www/vhosts/p6-b.invalid/tmp/:
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k1.txt
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k2.txt
-rw-r----- 1 p1136 p1136 5 Aug 15 12:46 p6-k3.txt
```

**Die Null ist hier eine Messung und kein Fehler.** Sie bekommt ihre Bedeutung
durch das `ls` daneben: drei Quellen liegen noch da, drei Ziele sind
unangetastet, kein Inhalt ist vertauscht. Ohne die zweite Ausgabe stünde da nur
eine Zahl, die genauso gut von einem abgestürzten Vorgang käme.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.** Derselbe Satz wie in `docs/48`, hier zum vierten Mal.

Die drei Zeilen mit Namen und Grund („Am Ziel steht schon etwas.") fehlen unter
dem Satz — **das ist Befund 12** und nicht ein neuer. Er ist behoben und wartet
auf `rc.4`; diese Runde belegt die Zahl, die Nachprüfung belegt die Gründe.

---

---

## Offen, klein, nicht verfolgt

`ls /var/www/vhosts/p6-b.invalid/logs/` und der `tail` darauf haben nichts
ausgegeben. Ob dort keine Protokolle liegen oder nginx woandershin schreibt, ist
für diesen Lauf ohne Belang und bleibt **benannt offen** — nicht als erledigt
gezählt.
