# 54 — Der Prüflauf für die Schritte 5b bis 6d

**Der Lauf für `cloudsrv24`, nach Schritt 5h und vor Schritt 8.** Geschrieben am
15. August 2026. Er prüft die **Oberfläche** des Dateimanagers auf einem echten
Server — also genau das, was `docs/52` ausdrücklich nicht geprüft hat.

Er steht wie `docs/39`, `docs/43`, `docs/47` und `docs/52` als eigenes Dokument
da, weil er zweimal gebraucht wird: einmal beim Fahren und einmal beim
Nachlesen, warum etwas so entschieden wurde.

---

## 1. Warum er jetzt kommt und nicht nach Schritt 9

Seit `docs/53` (gefahren gegen `v0.6.0-rc.1`) sind **neun Schritte**
dazugekommen: 5b, 5c, 5d, 5e, 5f, 5g, 5h, 6c und 6d. Von der Panel-Seite davon
hat **nichts je einen echten Agenten, ein echtes Chroot oder echte Dateien
gesehen.** Der Entwicklungscontainer hat keinen Agenten, kein nginx und kein
systemd; alles lief gegen Attrappen, Textprüfungen und synthetische
Markup-Seiten.

Der Satz dazu steht in `docs/51 §12`, als Begründung dafür, die Zwischenabnahme
von Schritt 10 auf 6b vorzuziehen:

> **Drei Schritte auf einer ungeprüften Annahme zu bauen ist teurer, als sie
> einmal zu prüfen.**

Hier sind es neun.

**Der teuerste Einzelposten ist 6c**, und er ist es aus einem Grund, der beim
Schreiben dieses Dokuments erst klar geworden ist — siehe §1.1.

**Dazu kommt 5f.** `Files\Scheme` entscheidet, was ein Kunde zerstören darf. Zu
großzügig heißt, seine Seite ist weg; zu streng heißt, er kann vor einem Deploy
nicht aufräumen. Geprüft ist bisher der **Text** des Quelltextes und nicht das
Verhalten an einem Verzeichnis, das root gehört.

**Und Schritt 12 ist im Container grundsätzlich nicht fahrbar.** Die
390px-Zahlen aus den letzten Schritten stammen aus eigenen HTML-Dateien gegen
das gebaute Stylesheet. Sie beantworten „läuft etwas über" und nicht „stimmt die
Seite". `docs/48` und `docs/53` haben beide gezeigt, wo die Fehler liegen, die
kein Wächter findet.

### 1.1 Der Fund beim Schreiben dieses Laufs

Ich habe dem Betreiber gesagt, der geänderte `WebSiteApply` laufe „beim nächsten
vhost-Apply über bestehende Abonnements", und das klang nach: beim Update.
**Nachgesehen: er läuft dabei gar nicht.**

`packaging/scripts/postinstall.sh` ruft nach jedem Umschalten
`srvpanel vhost --no-interaction` — **ohne `--sites`**. Das schreibt den
Server-Block der *Oberfläche* neu und rührt die Kundendomains nicht an.
`WebSiteApply` — und damit die neuen Eigentümer, Gruppen und Modi aus 6c —
läuft erst, wenn eine Kundendomain das nächste Mal angewandt wird.

Daraus folgen zwei Dinge, und das zweite ist das wichtigere:

**Erstens ist das Update harmloser, als ich behauptet habe.** Es fasst den Baum
bestehender Abonnements nicht an.

**Zweitens misst Punkt 1 nichts, wenn der Lauf `--sites` nicht ausdrücklich
auslöst.** Man führte das Update aus, sähe unveränderte Rechte, notierte „keine
Auffälligkeit" — und hätte den Prüfling nie gestartet.

> **Ein Abnahmeschritt, dessen Prüfling nie läuft, meldet Erfolg und misst
> nichts.**

**Und drittens, als Nebenwirkung, die dieser Lauf messen soll:** Nach dem Update
steht auf dem Server eine **gemischte Bevölkerung**. Ein Abonnement, das vor
dieser Fassung angelegt wurde, behält `httpdocs` in altem Modus und alter
Gruppe, bis jemand seine Domain anwendet; ein neues bekommt `2750` und
`www-data`. Der Satz des Rechte-Editors — „Der Webserver kann diese Datei
ausliefern" — hängt am Gruppenbit und ist damit für das eine Abonnement wahr und
für das andere **wortgleich falsch**. Das ist Befund 3 aus `docs/53` an einer
neuen Stelle:

> **Ein Satz, der an einer Stelle stimmt und an der nächsten nicht, ist
> schlechter als kein Satz.**

---

## 2. Was er ausdrücklich nicht prüft

- **Die Grenze selbst.** Sie ist in `docs/53` auf diesem Server gemessen worden.
  Was sich seitdem daran geändert hat, ist `files.tree` — und dass es durch die
  Sandbox geht, hält seit 5g `SandboxReachTest` fest.
- **SFTP und Cron.** Nicht gebaut (Schritte 8 und 9).
- **Den Angriffsdurchgang.** Das ist Schritt 11 und braucht 8 und 9.
- **Die vierzehn PHP-Funktionen auf allen vier Plattformen.** Sie sind auf
  **einer** gemessen (`docs/53` Punkt 2) und bleiben benannt offen.
- **`BlockSpacingTest::OPEN_SEAMS`.** Einunddreissig Fugen, die noch niemand
  angesehen hat. Sie gehören in Schritt 12 und nicht hierher.

---

## 3. Die Fassung, gegen die gefahren wird

| Sache | Wert |
|---|---|
| Zweig | `claude/p6-dateien-zugaenge-cron-efbuvy` |
| Stand | nach Schritt 5h |
| Vorher installiert | `v0.6.0-rc.1` |
| Zu bauen | `v0.6.0-rc.2` |
| Server | `cloudsrv24` |

**Der Lauf braucht ein Paket, und das braucht einen Tag auf `main`.** Freigaben
sind annotierte Tags `v<version>` auf `main` (`CLAUDE.md`); der Freigabelauf
baut daraus die `.deb`-Pakete. Also: PR #130 mergen, `v0.6.0-rc.2` taggen,
dann auf dem Server `srvpanel update`.

**Vor dem Lauf** prüfen, dass die Fassung wirklich umgestellt ist:

```bash
srvpanel --version
```

> Eine Fassungsprüfung, die in der falschen Datei sucht, hat in `docs/47` einen
> halben Lauf gekostet.

**Panel und Agent können dabei nicht auseinanderlaufen** — nachgesehen und nicht
angenommen: `agent/` liegt im selben Release-Baum und im selben `.deb`,
`packaging/build.sh` stempelt beide auf dieselbe Fassung, und
`restart_services()` startet `srvpanel-agentd` **vor** `srvpanel-web`. Die
Formatänderung an `files.compress` (`path` → `paths`) hat damit kein Fenster,
in dem ein neues Panel gegen einen alten Agenten spricht.

---

## 4. Die Fallen, die diesen Lauf stumm scheitern lassen

**Die beiden aus `tinker` stehen in `docs/47 §2`, in `docs/48 §3.8` und in
`docs/52 §4` — und sind beim Fahren von `docs/52` trotzdem wieder passiert.**
Deshalb stehen sie hier ein viertes Mal:

1. **`HOME=/tmp` davor.** Der Wrapper setzt per `setpriv` auf den Benutzer
   `srvpanel` um, `HOME` bleibt auf `/root`, und psysh scheitert am Anlegen von
   `.config/psysh` mit einer blossen `User Notice` — der Code läuft dann gar
   nicht erst, und der Rückgabewert ist 0.
2. **`Tenancy::allowAll()` als erste Zeile.** `Subscription` trägt die Klammer
   auf den eigenen Schlüssel; ohne sie ist `Subscription::first()` **`null`**.

Und zwei, die zu diesem Lauf gehören:

3. **`srvpanel vhost --sites` gehört zu Punkt 1** und nicht in die Vorbereitung.
   Wer es vorher fährt, hat den Vorher-Zustand weggeschrieben, den er messen
   wollte — und merkt es nicht, weil danach alles richtig aussieht.
4. **Die Ratenbegrenzung greift beim Anmelden** (`CLAUDE.md`, §6.4): drei
   Anmeldungen hintereinander sperren die Adresse. Für die Punkte 4 bis 8 gilt:
   **einmal anmelden**, dann im selben Browser bleiben und nur Theme und Breite
   umschalten.

---

## 5. Der Lauf

**Gefahren wird gegen `p6-b.invalid`** — Systembenutzer `p1136`, Verzeichnis
`/var/www/vhosts/p6-b.invalid`, vom Betreiber am 15. August 2026 benannt. Der
Bestand ist der Prüfling, nicht ein frisch angelegtes Abo.

**Zwei Eigenheiten dieses Abonnements stehen in jedem Befehl unten drin, und
beide sind keine Kosmetik:**

1. **`.invalid` löst nie auf** (RFC 2606). Ein blosses
   `curl https://p6-b.invalid/` scheitert an der Namensauflösung — und zwar mit
   demselben Fehlschlag vor und nach dem Update, also mit einer Zahl, die nichts
   über die Seite sagt. Deshalb steht überall `--resolve` auf `127.0.0.1` und
   `-k`, weil ein Zertifikat für eine `.invalid`-Domain nicht gültig sein kann.

   > **Eine Messung, die vorher und nachher aus demselben fremden Grund
   > fehlschlägt, sieht aus wie ein stabiler Zustand.**

2. **`Subscription::first()` ist hier falsch.** Es nimmt das erste Abo der
   Tabelle, und das muss nicht dieses sein. Gefragt wird nach dem Namen.

### Punkt 1 — das Update, und was es an bestehenden Abonnements ändert

**Der einzige Punkt dieses Laufs, der etwas kaputtmachen kann, das schon läuft.**

**(a) Vor dem Update**, gegen `rc.1`:

```bash
ABO=/var/www/vhosts/p6-b.invalid
stat -c '%n  %U:%G  %u:%g  %a' "$ABO" "$ABO"/httpdocs "$ABO"/logs "$ABO"/tmp "$ABO"/conf "$ABO"/.ssh "$ABO"/mail
curl -sS -k -L -o /dev/null -w 'seite=%{http_code}\n' \
  --resolve p6-b.invalid:80:127.0.0.1 --resolve p6-b.invalid:443:127.0.0.1 \
  http://p6-b.invalid/
```

**`%u:%g` steht neben `%U:%G`, weil eine Zahl allein niemandem gehört und ein
Name allein nichts beweist.** Ein `uid=0`, dessen Name zufällig danebensteht,
rutscht sonst durch (`docs/52`, Punkt 3).

**Und der Statuscode braucht einen Nachbarn, der kein Fehler ist.** Beim ersten
Fahren stand hier `seite=403` — `httpdocs` hatte keine Indexdatei. Ein
Vorher-Wert, der schon ein Fehlercode ist, kann den Fehler nicht anzeigen, auf
den dieser Punkt wartet: Brechen die Rechte durch 6c, antwortet nginx **auch**
mit 403, und die beiden Zahlen sähen gleich aus (`docs/55`, Befund 1).

> **Ein Vorher-Wert, der schon ein Fehler ist, kann den Fehler nicht anzeigen,
> auf den man wartet.**

**Der Grund war nicht, was ich vermutet hatte.** Die Indexdatei war da — sie
trug `p1136:p1136 0640`, also die Gruppe des Abonnements und kein Weltbit, und
nginx kam über die Gruppe `www-data` zwar in das Verzeichnis, aber nicht an die
Datei. **Befund 3 aus `docs/53`, live an einer Kundenseite.**

Behoben wird deshalb nicht mit `0644` — das bewiese nichts über die Gruppe —,
sondern mit der Gruppe selbst, bei unveränderten `0640`:

```bash
chgrp www-data "$ABO"/httpdocs/index.html
ls -l "$ABO"/httpdocs/index.html
curl -sS -k -L -o /dev/null -w 'seite=%{http_code}\n' \
  --resolve p6-b.invalid:80:127.0.0.1 --resolve p6-b.invalid:443:127.0.0.1 \
  http://p6-b.invalid/
```

**Erwartet: 200.** Damit ist zweierlei erledigt: Die Ursache des 403 ist
**gemessen** statt geraten, und Punkt 1 hat einen Vorher-Wert, der kein Fehler
ist. Die Datei steht danach genau so da, wie 6c eine neue anlegen wird.

| Datei | Rechte | Was sie misst |
|---|---|---|
| `index.html` | `0644` | Kommt nginx **durch das Verzeichnis**? |
| `p6-probe.txt` | `0640` | Trägt die Datei die **Gruppe**, über die er hereinkommt? (Punkt 2) |

**(b) Update fahren, dann sofort dieselbe Messung wiederholen — ohne
`--sites`.**

**Erwartet: identisch.** Das ist die Aussage aus §1.1, und sie ist hier keine
Vermutung mehr, sondern ein gemessener Wert.

**(c) Erst jetzt den Prüfling starten:**

**Der Aufruf reiht ein, er führt nicht aus** — und das ist beim ersten Fahren
übersehen worden (`docs/55`, Befund 3). Die Ausgabe sagt es in einem Wort:
„1 Server-Blöcke der Kundendomains **eingereiht**." Der Server-Block der
Oberfläche entsteht unmittelbar, die der Kundendomains gehen über die
Warteschlange.

> **Eine Messung, die unmittelbar nach dem Einreihen läuft, misst den Zustand
> davor.**

Gewartet wird deshalb auf den **Vorgang** und nicht auf eine Zahl Sekunden — eine
feste Wartezeit ist auf dem nächsten, langsameren Server wieder zu kurz:

```bash
srvpanel vhost --sites

# Warten, bis kein web.site.apply mehr offen ist.
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  for ($i = 0; $i < 120; $i++) {
    $offen = App\Models\Operation::whereIn("status", ["queued", "running"])->count();
    if ($offen === 0) { echo "Warteschlange leer nach ", $i, "s\n"; break; }
    sleep(1);
  }
  foreach (App\Models\Operation::latest("id")->limit(5)->get() as $o) {
    echo $o->id, "  ", $o->type, "  ", $o->status->value, "  ", $o->message, "\n";
  }
'

stat -c '%n  %U:%G  %u:%g  %a' "$ABO" "$ABO"/httpdocs "$ABO"/logs "$ABO"/tmp "$ABO"/conf "$ABO"/.ssh "$ABO"/mail
curl -sS -k -L -o /dev/null -w 'seite=%{http_code}\n' \
  --resolve p6-b.invalid:80:127.0.0.1 --resolve p6-b.invalid:443:127.0.0.1 \
  http://p6-b.invalid/
```

**Die Vorgangsliste steht dabei nicht zur Zierde:** Sie belegt, dass der
`web.site.apply` wirklich gelaufen *und* gelungen ist. Ein leeres
Warteschlangenfenster heisst sonst auch dann „fertig", wenn der Vorgang
fehlgeschlagen ist — und die unveränderte Tabelle sähe genauso aus wie ein
Vorgang, der nie lief.

**Erwartet:**

| Verzeichnis | Eigentümer:Gruppe | Modus | wer setzt es |
|---|---|---|---|
| `httpdocs` | `p1136:www-data` | **`2750`** | `WebSiteApply` — läuft jetzt |
| `logs/p6-b.invalid` | `p1136:adm` | **`2750`** | `WebSiteApply` — läuft jetzt |
| `logs` | `p1136:adm` | `750` *(unverändert)* | `SubscriptionProvision` |
| `tmp` | `p1136:p1136` | `700` *(unverändert)* | `SubscriptionProvision` |
| `conf` | `root:root` | `755` *(unverändert)* | `SubscriptionProvision` |
| `.ssh` | `p1136:p1136` | `700` *(unverändert)* | `SubscriptionProvision` |
| `mail` | `p1136:p1136` | `700` *(unverändert)* | `SubscriptionProvision` |

**Die rechte Spalte ist der Kern, und sie hat beim ersten Fahren gefehlt.**
`WebSiteApply` fasst genau zwei Verzeichnisse an, und `Site::logDir()` ist
`<abo>/logs/<domain>` — ein Unterverzeichnis je Domain, **nicht** `logs`. Alles
andere steht in `SubscriptionProvision::TREE` und wird beim **Anlegen** gesetzt;
für ein bestehendes Abonnement läuft es nie wieder.

> **Zwei Listen, die dasselbe Schema beschreiben, werden von zwei verschiedenen
> Vorgängen angewandt — und nur eine davon läuft nachträglich.**

**Und die Seite liefert weiter aus** — derselbe Statuscode wie in (a).

> **Der Unterschied zwischen (b) und (c) ist die Messung.** Stünde in beiden
> dasselbe, wäre nicht bewiesen, dass 6c wirkt, sondern nur, dass nichts
> kaputtging. Eine Null ist erst dann eine Messung, wenn daneben etwas anderes
> als Null steht.

**Und `.ssh` bleibt hier eine Zahl und keine Probe** — das ist eine bewusste
Einschränkung und kein Vergessen.

Der erste Entwurf dieses Punktes hatte hier ein `ssh -o BatchMode=yes
p1136@localhost`. **Das misst nichts:** SFTP ist Schritt 8 und nicht gebaut, es
gibt weder einen `Match`-Block noch einen hinterlegten Schlüssel. Der Aufruf
scheiterte mit „Permission denied (publickey)" — vor und nach dem Update
gleich, aus einem Grund, der mit dem Modus nichts zu tun hat. Genau die Falle,
vor der §5 zwei Absätze weiter oben warnt, im selben Dokument noch einmal
gestellt.

> **Eine Gegenprobe, die aus einem fremden Grund fehlschlägt, belegt nichts —
> und sieht aus wie ein Befund.**

Gemessen wird deshalb nur der Wert (`2700`, `p1136:p1136`). Ob OpenSSH ihn
annimmt, ist eine Messung von Schritt 8; `safe_path()` prüft `st_mode & 022`,
und `02000` fällt nicht darunter — das ist nachgelesen und bleibt es bis dahin.

**Was sich dagegen jetzt schon ablesen lässt, und für Schritt 8 zählt:** die
**Abo-Wurzel** aus derselben `stat`-Zeile. OpenSSH verlangt für
`ChrootDirectory`, dass sie `root` gehört und für Gruppe und Andere nicht
schreibbar ist. Steht dort etwas anderes als `root:root` und ein Modus ohne
`022`, fällt Schritt 8 darüber — und zwar dann und nicht heute.

### Punkt 2 — setgid von Ende zu Ende

**Die Behebung von Befund 3 aus `docs/53`, nie gemessen.** Der Fall dort: Der
Kunde setzt `0640` — dieselbe Angabe, die die Willkommensseite daneben trägt —
und bekommt einen 403.

Im Panel, als Kunde des Abonnements: eine Datei namens `p6-probe.txt` nach `httpdocs` **hochladen**
(nicht per `scp`, der Weg ist der Prüfling). Dann:

```bash
stat -c '%n  %U:%G  %u:%g  %a' "$ABO"/httpdocs/p6-probe.txt
curl -sS -k -L -o /dev/null -w 'vor-chmod=%{http_code}\n' \
  --resolve p6-b.invalid:80:127.0.0.1 --resolve p6-b.invalid:443:127.0.0.1 \
  http://p6-b.invalid/p6-probe.txt
```

**Erwartet:** Gruppe `www-data`.

Dann im Panel über den Rechte-Editor auf **`0640`** stellen und noch einmal:

```bash
stat -c '%n  %U:%G  %u:%g  %a' "$ABO"/httpdocs/p6-probe.txt
curl -sS -k -L -o /dev/null -w 'nach-chmod=%{http_code}\n' \
  --resolve p6-b.invalid:80:127.0.0.1 --resolve p6-b.invalid:443:127.0.0.1 \
  http://p6-b.invalid/p6-probe.txt
```

**Erwartet: 200.** Vor 6c wäre hier 403 gekommen.

**Die Gegenprobe, und sie ist der eigentliche Fund dieses Punktes:** Gibt es auf
dem Server ein Abonnement, dessen Domain seit dem Update **nicht** angewandt
wurde, dann trägt dessen `httpdocs` noch die alte Gruppe. Dieselbe Datei, dieselbe
`0640`, dort ein **403** — und der Rechte-Editor sagt in beiden Fällen denselben
Satz. Das ist §1.1 Punkt drei, gemessen statt behauptet.

Fällt der Server sonst nirgends darunter, lässt es sich herstellen:

```bash
chgrp p1136 "$ABO"/httpdocs && chmod 2750 "$ABO"/httpdocs
```

### Punkt 3 — der Schema-Schutz an echten Verzeichnissen

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::where("name", "p6-b.invalid")->firstOrFail();
  $f = app(App\Support\Files\Files::class);
  foreach (["/httpdocs", "/logs", "/conf", "/.ssh", "/tmp", "/mail"] as $p) {
    try { $f->remove($abo, $p, true); echo $p, ": DURCHGELASSEN\n"; }
    catch (SrvPanel\Agent\AgentException $e) { echo $p, ": ", $e->getMessage(), "\n"; }
  }
  try { $f->move($abo, "/httpdocs", "/httpdocs-alt"); echo "move: DURCHGELASSEN\n"; }
  catch (SrvPanel\Agent\AgentException $e) { echo "move: ", $e->getMessage(), "\n"; }
  try { $f->chmod($abo, "/httpdocs", 0750); echo "chmod: DURCHGELASSEN\n"; }
  catch (SrvPanel\Agent\AgentException $e) { echo "chmod: ", $e->getMessage(), "\n"; }
'
```

**Erwartet:** sechsmal, dann zweimal derselbe Satz — „Das Verzeichnis … gehört
zum Aufbau des Abonnements und wird nicht … Sein Inhalt lässt sich ändern."

**Die erste Gegenprobe ist der ganze Zweck von 5f:** Es darf nichts geschehen
sein.

```bash
ls -la "$ABO"/httpdocs/ "$ABO"/logs/ | head -30
stat -c '%n %a' "$ABO"/httpdocs
```

> `Filesystem::removeTree()` räumte bis 5f erst leer und scheiterte dann am
> `rmdir`. Die Meldung sagte „liess sich nicht vollständig entfernen", und die
> Webseite war weg. **Eine Absage, die erst nach der Wirkung kommt, ist keine.**

**Die zweite Gegenprobe ist die andere Hälfte der Regel** — der Inhalt bleibt
frei:

```bash
HOME=/tmp srvpanel tinker --execute='
  app(App\Support\Tenancy\Tenancy::class)->allowAll();
  $abo = App\Models\Subscription::where("name", "p6-b.invalid")->firstOrFail();
  $f = app(App\Support\Files\Files::class);
  print_r($f->makeDirectory($abo, "/httpdocs/p6-inhalt"));
  print_r($f->write($abo, "/httpdocs/p6-inhalt/x.txt", "x\n"));
  print_r($f->remove($abo, "/httpdocs/p6-inhalt", true));
  print_r(array_column($f->list($abo, "/httpdocs")["entries"], "name"));
'
```

**Ohne diese zweite Hälfte wäre der Schutz schlimmer als keiner:** `httpdocs`
leerzuräumen ist genau das, was jemand vor einem neuen Deploy tut.

### Punkt 4 — die Mehrfachauswahl, mit einem Fehlschlag darin

**Im Browser**, denn die Rückmeldung ist der Prüfling.

**Der erste Entwurf dieses Punktes war nicht fahrbar**, und zwar gefährlich
nicht: Er sagte „in `/` des Abonnements `conf` und zwei gewöhnliche Einträge
anhaken". In der Abo-Wurzel gibt es **keine** gewöhnlichen Einträge — dort
liegen ausschliesslich die sechs Verzeichnisse des Schemas. Die Auswahl hätte
also auf `httpdocs` gezeigt, und die Zahl im ersten Satz wäre 0 gewesen statt 2.
Der Punkt hätte nicht das gemessen, wofür er dasteht.

> **Ein Schritt, der eine Teilmenge braucht, muss vorher wissen, dass es sie
> gibt.**

Gebraucht wird ein Eintrag, der **innerhalb** von `httpdocs` scheitert. Ein
root-eigenes Unterverzeichnis mit Inhalt tut das zuverlässig: Der Kunde darf
`p6-fremd` selbst entfernen (`httpdocs` gehört ihm), aber nicht die Datei darin.

```bash
mkdir -p "$ABO"/httpdocs/p6-fremd && echo x > "$ABO"/httpdocs/p6-fremd/x.txt
chown -R root:root "$ABO"/httpdocs/p6-fremd && chmod 755 "$ABO"/httpdocs/p6-fremd
touch "$ABO"/httpdocs/p6-eins.txt "$ABO"/httpdocs/p6-zwei.txt
chown p1136:p1136 "$ABO"/httpdocs/p6-eins.txt "$ABO"/httpdocs/p6-zwei.txt
```

Dann im Dateimanager in `httpdocs`: `p6-fremd`, `p6-eins.txt` und `p6-zwei.txt`
anhaken, „Entfernen".

**Erwartet:** die Rückfrage nennt die Zahl der Verzeichnisse; danach eine
Meldung, deren **erster Satz** die Zahl trägt — „Von 3 Einträgen sind 2
entfernt." — und darunter **eine** Zeile mit Pfad und Grund.

**Die erste Gegenprobe:** zwei gewöhnliche Dateien allein. Dann steht dort eine
Erfolgsmeldung mit der Zahl und **keine** Fehlerliste.

**Die zweite, und sie prüft 5f durch die Oberfläche:** in der Abo-Wurzel **alle
sechs** Verzeichnisse anhaken und „Entfernen". Erwartet: „Von 6 Einträgen sind 0
entfernt.", sechs Zeilen mit demselben Satz — und danach steht der Baum
unversehrt da (`ls "$ABO"`).

> **Eine fehlgeschlagene Anfrage darf die Beschriftung nicht so lassen, als wäre
> sie durchgelaufen.** (`docs/48 §3.5`)

**Und der Blick daneben**, weil er nicht messbar ist: Fällt die Auswahl beim
Wechsel des Verzeichnisses weg? Anhaken, in den Baum klicken, zurück — die
Leiste muss fort sein.

### Punkt 5 — Kopieren und Verschieben mit dem Baum als Zielwähler

Drei Dateien in `httpdocs` anhaken, „Kopieren", im Baum `tmp` wählen.

**Erwartet:** drei Dateien in `tmp`, **jede unter ihrem eigenen Namen**.

```bash
ls -l "$ABO"/tmp/
```

> Das ist der Fehler, den der Mehrfach-Upload schon einmal gemacht hat: ein
> vollständiger Zielpfad für mehrere Quellen ist **ein** Pfad für alle. Läge
> dort **eine** Datei, wäre er zurück.

**Die Gegenprobe:** Die Quellen liegen noch in `httpdocs`. Danach dieselben drei
**verschieben** — dann sind sie dort fort und in `tmp` doppelt vorhanden? Nein:
Am Ziel steht schon etwas, also muss der Vorgang je Eintrag mit genau dieser
Begründung scheitern, und die Zahl im ersten Satz lautet 0. **Das ist eine
Messung und kein Fehler** — sie belegt, dass nicht überschrieben wird.

Danach `tmp` leeren und die drei wirklich verschieben.

### Punkt 6 — Packen

Drei Einträge anhaken, „Als Zip packen", Name `auswahl.zip`.

```bash
unzip -l "$ABO"/httpdocs/auswahl.zip
```

**Erwartet:** drei Einträge, jeder mit seinem eigenen Namen.

**Die erste Gegenprobe:** ein **Verzeichnis** anhaken und das Archiv in dasselbe
Verzeichnis legen wollen. Erwartet: „Das Archiv kann nicht in einem Verzeichnis
liegen, das es selbst enthalten soll."

**Die zweite:** zwei gleich heissende Einträge aus verschiedenen Verzeichnissen.
**Über die Oberfläche geht das nicht** — die Auswahl lebt je Verzeichnis und
fällt beim Navigieren weg, zwei gleiche Namen liegen aber immer in verschiedenen
Verzeichnissen. Gemessen wird deshalb über `srvpanel tinker` auf demselben Weg
darunter (`Files::compress()`); der Satz oben stand hier bis zum 15. August 2026
und war nie fahrbar. Erwartet: „Zwei ausgewählte Einträge heissen … — im Archiv
bliebe nur einer übrig." und **kein** Archiv daneben.

### Punkt 7 — Anlegen und der Mehrfach-Upload

**Datei anlegen** in `httpdocs`: Erwartet, dass danach der **Editor** offen ist
und nicht die Liste.

**Drei Dateien auf einmal hochladen** — nach `conf`, das root gehört.

**Erwartet:** „Von 3 Dateien sind 0 hochgeladen." und darunter drei Zeilen mit
Namen und Grund.

**Die Gegenprobe:** dieselben drei nach `httpdocs`. Dann liegen drei Dateien da,
**jede unter ihrem eigenen Namen**, und die Meldung nennt die Zahl.

### Punkt 8 — die Bilderrunde

**Einmal anmelden** (§4 Punkt 4), dann nur Theme und Breite umschalten. Bei
**390 px** und **1440 px**, in **beiden Themes**, mit der Zahl daneben:

```js
document.documentElement.scrollWidth - document.documentElement.clientWidth
```

Angesehen werden:

1. die Liste mit einem langen Dateinamen,
2. die **Auswahlleiste** mit allen sechs Knöpfen (gemessen im Container:
   228 px hoch bei 390 px, Überlauf 0 — hier gegen die echte Seite),
3. der **Rechte-Editor** mit seinen neun Ankreuzfeldern,
4. der **Baum** mit einem tiefen Pfad,
5. der **Editor** mit einer echten Datei — Breite und Hervorhebung, die
   Befunde 9 aus `docs/53`.

**Und die Gegenprobe zur Messung selbst:** Ein absichtlich eingefügter
900px-Block muss dort eine Zahl erzeugen. Tut er es nicht, misst nichts, und
die Nullen bedeuten nichts.

**Was hier ausserdem zu sehen sein wird und kein Fehler dieses Schrittes ist:**
Im dunklen Theme malt der Browser leere Ankreuzfelder weiss, weil nirgends
`color-scheme: dark` steht. Das gilt panelweit für jedes `.toggle`, seit es sie
gibt. Es gehört in Schritt 12 und wird hier nur notiert.

---

## 6. Wie das Protokoll entsteht

**Während des Laufs und nicht danach**, als eigenes Dokument. Jeder Punkt
bekommt seine Zeile, sobald er gefahren ist — mit dem gemessenen Wert und nicht
mit „ok".

Und die Erwartung aus fünf Läufen: **Die Mehrheit der Befunde wird diesen Lauf
selbst betreffen und nicht den Prüfling.** In `docs/45`, `47`, `48` und `53` war
es jedes Mal etwa die Hälfte bis zwei Drittel.

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

Ein Fehler dieser Bauart steckt schon in diesem Dokument und ist vor dem ersten
Lauf gefunden worden: §1.1. Er hätte Punkt 1 zu einer Messung ohne Prüfling
gemacht.

---

## 6.1 Aufräumen

**Das Abonnement ist ein Prüfling und kein Wegwerfartikel.** Was der Lauf
angelegt hat, geht danach wieder fort — von Hand, weil `p6-fremd` root gehört
und der Dateimanager es zu Recht nicht anfasst:

```bash
ABO=/var/www/vhosts/p6-b.invalid
rm -rf "$ABO"/httpdocs/p6-fremd
rm -f  "$ABO"/httpdocs/p6-probe.txt "$ABO"/httpdocs/p6-eins.txt \
       "$ABO"/httpdocs/p6-zwei.txt "$ABO"/httpdocs/auswahl.zip
# index.html bleibt: sie ist der Nachbar, an dem sich jede weitere Messung misst.
rm -rf "$ABO"/tmp/p6-*
ls -la "$ABO"/httpdocs/ "$ABO"/tmp/
```

**Die letzte Zeile ist die Gegenprobe und nicht Zierde.** Ein `rm`, dessen
Muster nicht passt, schweigt — und `docs/52` hat genau dafür den Satz: Ein
Rückbau, den niemand nachzählt, meldet Erfolg auch dann, wenn nichts geschehen
ist.

Und falls Punkt 2 die Gruppe von `httpdocs` von Hand zurückgedreht hat, stellt
`srvpanel vhost --sites` sie wieder her.

---

## 7. Was danach kommt

Halten die acht Punkte, gehen die Schritte 8 (SFTP) und 9 (Cron) weiter, danach
der Angriffsdurchgang aus `docs/51 §4` und die vollständige Bilderrunde als
Schritt 12.

Hält Punkt 1 nicht, ist das kein Detailfehler: Dann schreibt `WebSiteApply`
bestehenden Abonnements etwas, das ihre Seite nicht mehr ausliefert — und der
Weg zurück ist eine Fassung und kein Fix.
