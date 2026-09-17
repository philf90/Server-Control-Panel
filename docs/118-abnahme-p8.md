# P8 — der Abnahmelauf

Ausgeschrieben am **16. September 2026**, **vor** dem Fahren. Der Plan ist
`docs/117`, die Messrunde davor `docs/116`, die Übergabe `docs/115`. Gefahren
wird auf `cloudsrv24`.

---

## 0 · Was beim Ausschreiben umgefallen ist

**Ein Punkt ist gestrichen, einer war unfahrbar, und zwei Kriterien haben ihre
Fassung gewechselt.** Alle vier sind am Quelltext gefunden worden und nicht beim
Fahren — das ist der Grund, aus dem dieser Lauf vorher entsteht.

### 0.1 Punkt 8 ist fort — es gibt kein Fernziel

`docs/117 §8` verlangte, dass eine Sicherung auf einem S3-Ziel ankommt. **Kein
einziger der zehn Bauschritte aus `§6` stellt eines her**; `§5` führt S3 als
*Vorschlag*.

> **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den Verfasser.**

Entschieden vom Betreiber: Der Punkt fällt, S3 steht in **P9b**. Der Lauf hat
damit **sieben** Punkte.

### 0.2 Die Sicherung war zwischen Punkt 2 und 3 unerreichbar

Punkt 2 löscht das Abonnement vollständig, Punkt 3 spielt die Sicherung zurück.
Dazwischen stand sie in **keiner** Liste dieses Panels: `/backups` wählt ein
Abonnement, `/subscriptions/{id}/backups` braucht eines. Übrig blieb
`/backups/{id}/restore` — eine Adresse, deren Kennung niemand kennt.

> **Vor jedem neuen Merkmal: Wo sucht jemand diese Handlung, und steht sie
> dort?**

Gebaut am selben Tag (`docs/117`, Befund 21): `/backups` trägt den Bereich
**„Ohne Abonnement"**. Punkt 3 geht deshalb ausdrücklich über diesen Bereich und
nicht über eine getippte Adresse — sonst prüfte der Lauf einen Weg, den es für
einen Menschen nicht gibt.

### 0.3 Punkt 2 sagt „die Zeile" und meint nicht jede

„Das Abonnement wird vollständig gelöscht — **Zeile**, Verzeichnis, Unix-Konto,
Datenbanken." Die Zeile des **Abonnements** geht; die Zeilen seiner
**Sicherungen** bleiben, und das ist der Sinn von `nullOnDelete`:

> *Die Sicherung überlebt ihr Abonnement.*

Ohne diesen Satz läse sich ein `backups`-Eintrag nach dem Rückbau wie ein Rest.

### 0.4 Punkt 4 sagt „die Datenbanken" und meint andere Namen

Form A vergibt einen **neuen** Systembenutzer und ein **neues** Präfix
(`docs/117 §3`). Die Datenbanken stehen mit ihrem **Inhalt** wieder da, unter
**anderen Namen**. Genau deshalb ist Punkt 6 ein Ausschlusskriterium: Was sich
geändert hat, muss die Seite sagen.

---

### 0.5 Punkt 4 bekommt den Verweis aus dem Baum hinaus

Nachgetragen am 16. September 2026, und nicht beim Ausschreiben gefunden,
sondern beim Fahren der CI: `BackupRestoreTest` misst, dass der
Eigentümerwechsel keinem Verweis folgt — und die Hälfte davon, die eine
**Kennung** liest, braucht root. Die CI läuft als `runner`, und dort ist sie
still.

Der Wächter trägt die Regel weiterhin überall (am hängenden Verweis und am
Zähler); was ihm in der CI fehlt, ist der Beleg, dass eine Datei **ausserhalb**
des Abonnements ihren Eigentümer behält. Genau das kann ein echter Server, und
deshalb steht es jetzt in Punkt 1 als Prüfkörper und in Punkt 4 als Messung.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage** — und wo ein Lauf sie beantworten kann, gehört sie in den Lauf.

---

### 0.6 `srvpanel diagnose` hat kein `--json`

Gefunden am **17. September 2026**, vor dem ersten Befehl: Die Vorschrift rief
an drei Stellen `srvpanel diagnose --json`. Die Signatur des Kommandos ist
`srvpanel:diagnose` **ohne Optionen** — der Aufruf wäre an jeder der drei
gescheitert, und zwar erst im Lauf.

> **Eine Messvorschrift, die ein Werkzeug voraussetzt, das der Server nicht
> hat, misst nicht — sie meldet einen Fehler an sich selbst.** (`docs/108`)

Gelesen werden die Befunde jetzt dort, wo sie stehen: in `findings`, über
`srvpanel tinker`. Die Tabelle trägt kein `BelongsToSubscription`, die
Mandantenklammer greift also nicht — `withoutGlobalScopes()` wäre hier die
zweite Falle und ist nicht nötig (gemessen am Modell, nicht vermutet).

**Und der Vorflug hatte eine zweite:** `srvpanel diagnose --json | tee … |
head -40` schneidet die Leitung nach vierzig Zeilen ab. `head` schliesst sie,
und was `tee` danach schreiben wollte, ist fort.

> **Kein `| head` über dem Messlauf.** (CLAUDE.md, bezahlt am 23. August)

---

## 0b · Der Vorflug — was vorher dasteht und hinterher wieder

Dieser Lauf **legt ein Abonnement an und löscht es**. Vorher festhalten:

```bash
srvpanel diagnose
srvpanel tinker --execute='
  foreach (App\Models\Finding::query()->orderBy("check")->orderBy("subject")->get() as $f) {
    printf("%-4s %-22s %-20s %s\n", $f->state()->value, $f->check->value, $f->reason, $f->subject);
  }
  printf("%d Befunde\n", App\Models\Finding::query()->count());'
ls -la /var/lib/srvpanel/backups/
repquota -s / > /root/p8-vorher-quota.txt; head -20 /root/p8-vorher-quota.txt
srvpanel tinker --execute='echo App\Models\Subscription::withoutGlobalScopes()->count()," Abos, ",
  App\Models\Backup::withoutGlobalScopes()->count()," Sicherungen";'
```

**Und der Schalter, an dem Schritt 10 hängt**, wird gelesen und nicht gesetzt:

```bash
srvpanel tinker --execute='print_r(app(App\Support\Settings\Settings::class)->backups());'
```

Steht `before_removal` auf `false`, wird er für diesen Lauf eingeschaltet — und
am Ende auf den **gelesenen** Wert zurückgestellt.

> **Ein Prüfkörper, der den Zustand herstellt, statt ihn zu suchen, ändert den
> Server für eine Zeile, die vielleicht schon dasteht.** (`docs/902`)

---

## 1 · Eine Sicherung entsteht und zählt nicht gegen die Quota

**Der Prüfkörper:** ein Abonnement mit Dateien, **zwei** Datenbanken (eine
MariaDB, eine PostgreSQL), mindestens einer Domain und einem Cronjob.

```bash
# vorher — die Quota des Kunden
repquota -s / | grep "^p1[0-9]*" | tee /root/p8-quota-vorher.txt
```

**Und der Verweis aus dem Baum hinaus** — der Prüfkörper zu 0.5. Er wird
angelegt, **bevor** gesichert wird, und zeigt auf eine Datei, die es nur für
diesen Lauf gibt:

```bash
install -m 600 -o root -g root /dev/null /root/p8-opfer.txt
echo 'gehoert root' > /root/p8-opfer.txt
ln -s /root/p8-opfer.txt /var/www/vhosts/<abo>/httpdocs/hinaus
stat -c '%U:%G %a %n' /root/p8-opfer.txt
```

**Kein `/etc/shadow`.** Schlägt die Regel fehl, wechselt die Datei ihren
Eigentümer — dann soll das eine Wegwerfdatei sein und keine, an der die
Anmeldung hängt. Der Befund ist derselbe.

Dann über die Oberfläche: `/subscriptions/<id>/backups` → **Jetzt sichern**.
Warten, bis der Vorgang `succeeded` meldet.

```bash
ls -la /var/lib/srvpanel/backups/<abo>/
stat -c '%U:%G %a %s' /var/lib/srvpanel/backups/<abo>/*.zip
repquota -s / | grep "^p1[0-9]*"
```

**Erwartet:** `root:srvpanel 640`, und die Quota-Zahl des Kunden ist **dieselbe
wie vorher**.

**Die Gegenprobe gehört dazu:** `repquota` misst überhaupt etwas. Ohne eine
zweite Zahl, die sich bewegt, ist „unverändert" von „nicht gemessen" nicht zu
unterscheiden — also eine Datei im Kundenverzeichnis anlegen, nachmessen,
löschen.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 2 · Das Abonnement wird vollständig gelöscht

Über die Oberfläche: `/subscriptions/<id>` → **Zurückbauen**.

**Zuerst entsteht eine zweite Sicherung** — das ist Schritt 10. Erst wenn sie
`succeeded` ist, läuft `subscription.remove`.

```bash
id -u <benutzer>            # erwartet: "no such user"
ls -la /var/www/vhosts/      # das Verzeichnis ist fort
mysql -e 'show databases;' | grep <praefix>   # leer
su postgres -c "psql -lqt" | grep <praefix>   # leer
ls -la /var/lib/srvpanel/backups/<abo>/       # ZWEI Archive, sie bleiben
srvpanel tinker --execute='
  $b = App\Models\Backup::withoutGlobalScopes()->get(["id","subscription_id","subscription_name"]);
  foreach ($b as $z) { printf("%d sub=%s name=%s\n", $z->id, var_export($z->subscription_id, true), $z->subscription_name); }'
```

**Erwartet:** Konto, Verzeichnis und Datenbanken fort; **beide** Sicherungen mit
`subscription_id = NULL` und ihrem abgeschriebenen Namen.

---

## 3 · Die Wiederherstellung läuft durch einen Vorgang

**Über die Oberfläche und über den Bereich aus §0.2:** `/backups` → **Ohne
Abonnement** → der Name des Abonnements → Kunde und Plan wählen → **Zurückspielen**.

**Erwartet:**

- die Seite springt auf `/operations/<id>`,
- der Fortschritt bewegt sich (nicht nur 0 und 100),
- der Vorgang endet `succeeded`,
- und **vorher** ist ein `subscription.provision` gelaufen: zwei Vorgänge, in
  dieser Reihenfolge.

```bash
srvpanel tinker --execute='
  foreach (App\Models\Operation::withoutGlobalScopes()->latest("id")->take(4)->get() as $o) {
    printf("%d %-22s %-10s %s\n", $o->id, $o->task, $o->status->value, $o->message);
  }'
```

---

## 4 · Danach steht das Abonnement wieder

```bash
id <neuer-benutzer>
ls -la /var/www/vhosts/<neues-abo>/
find /var/www/vhosts/<neues-abo> -type l -printf '%p -> %l\n'
stat -c '%a %U:%G %n' /var/www/vhosts/<neues-abo>/httpdocs/<prüfdatei>
mysql -e 'show databases;' | grep <neues-praefix>
mysql <neue-db> -e 'select count(*) from <tabelle>;'
crontab -l -u <neuer-benutzer> 2>/dev/null; cat /etc/cron.d/srvpanel-<neuer-benutzer>
```

**Erwartet:** Rechte und Verweisziele wie im Verzeichnis der Sicherung, die
Datenbanken mit ihrem Inhalt (Zeilenzahl!), Domains und Cronjobs wieder da.

**Der Eigentümer ist der neue** — das ist Form A und kein Mangel.

**Und die Datei ausserhalb behält ihren** (0.5):

```bash
stat -c '%U:%G %a %n' /root/p8-opfer.txt
stat -c '%U:%G %n' /var/www/vhosts/<neues-abo>/httpdocs/hinaus
readlink /var/www/vhosts/<neues-abo>/httpdocs/hinaus
```

**Erwartet:** `/root/p8-opfer.txt` weiterhin `root:root 600`, der **Verweis
selbst** dem neuen Benutzer, und sein Ziel unverändert `/root/p8-opfer.txt`.

Steht dort der neue Benutzer, ist der Eigentümerwechsel dem Verweis gefolgt —
und ein Kunde bekäme über ein Archiv jede Datei dieses Servers. Das ist ein
Ausfall und kein Mangel an Schönheit.

> **Ein Verweis, dessen Ziel man nicht prüft, ist harmlos, solange niemand ihm
> folgt — und ein rekursiver Griff folgt ihm, ohne es zu sagen.**

---

## 5 · Die Vhost-Datei besteht die Bestandsdiagnose *(Ausschluss)*

**Der Punkt, an dem sich „erzeugt" von „zurückgespielt" unterscheidet.**

```bash
srvpanel diagnose
srvpanel tinker --execute='
  foreach (App\Models\Finding::query()->where("check", "like", "web.%")->get() as $f) {
    printf("%-4s %-22s %-20s %s\n", $f->state()->value, $f->check->value, $f->reason, $f->subject);
  }'
nginx -t
```

**Erwartet:** **kein** `web.file / directive_lost` und kein `promise_*` für die
Domains des wiederhergestellten Abonnements — auch nicht im Nachtlauf der Nacht
darauf.

> **Eine Sicherung, die erzeugte Dateien aufhebt, sichert den Ausgang einer
> Rechnung und nicht ihre Eingaben.**

**Die Gegenprobe:** eine Zeile aus der frisch erzeugten Vhost-Datei entfernen,
`srvpanel diagnose` erneut, den Befund lesen, Zeile zurückholen, Befund fort.
Ohne sie belegt ein leeres Ergebnis nur, dass die Prüfung nichts gefunden hat —
nicht, dass sie hingesehen hat.

---

## 6 · Die Seite sagt, was sich geändert hat *(Ausschluss)*

Auf `/operations/<id>` des Wiederherstellungsvorgangs:

**Erwartet** — die Zuordnung **alt → neu** für jede Datenbank, der neue
Systembenutzer, und der Satz, dass jeder Datenbankzugang **ein neues Passwort
braucht**.

> **Eine Wiederherstellung, die schweigt, lässt den Kunden vor einem Auftritt
> stehen, der nicht läuft, ohne ihm zu sagen warum.**

**Und der Schlüssel des hochgeladenen Zertifikats:** Trug das Abonnement eines,
liegt es wieder da.

```bash
ls -la /etc/srvpanel/tls/certs/<name>/
stat -c '%a %U:%G %n' /etc/srvpanel/tls/certs/<name>/privkey.pem   # 600
srvpanel tinker --execute='
  foreach (App\Models\Certificate::withoutGlobalScopes()->where("source","uploaded")->get() as $c) {
    printf("%d sub=%s %s\n", $c->id, var_export($c->subscription_id, true), $c->storage_name); }'
```

**Erwartet:** Datei **und** Zeile — eine ohne die andere ist ein Rest
(`orphan.row / certificate` beziehungsweise ein Zertifikat ohne Schlüssel).

---

## 7 · `backup.verify` urteilt in beide Richtungen

**Die heile zuerst**, sonst misst die kaputte nichts:

```bash
time srvpanel backup-verify
srvpanel tinker --execute='
  foreach (App\Models\Finding::query()->where("check", "backup.file")->get() as $f) {
    printf("%-4s %-20s %s\n", $f->state()->value, $f->reason, $f->subject);
  }
  printf("%d backup.file-Befunde\n", App\Models\Finding::query()->where("check", "backup.file")->count());'
```

**Erwartet:** keine `backup.file`-Befunde.

Dann ein Byte **im Inhalt** eines Archivs umdrehen — nicht im Kopf, sonst ist es
`unreadable` und nicht `corrupt`:

```bash
cp /var/lib/srvpanel/backups/<abo>/<stand>.zip /root/p8-heil.zip
printf '\x00' | dd of=/var/lib/srvpanel/backups/<abo>/<stand>.zip bs=1 seek=2048 conv=notrunc
srvpanel backup-verify
# derselbe Leser wie oben                        # erwartet: corrupt
cp /root/p8-heil.zip /var/lib/srvpanel/backups/<abo>/<stand>.zip
srvpanel backup-verify                          # erwartet: Befund fort
```

**Und die dritte Richtung** (`docs/117 §9` Punkt 7): eine Datei ohne Zeile.

```bash
cp /root/p8-heil.zip /var/lib/srvpanel/backups/<abo>/niemandes-stand.zip
chown root:srvpanel /var/lib/srvpanel/backups/<abo>/niemandes-stand.zip
srvpanel backup-verify                          # erwartet: orphan (warn)
rm /var/lib/srvpanel/backups/<abo>/niemandes-stand.zip
srvpanel backup-verify                          # erwartet: Befund fort
```

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.**

---

## 8 · 390 px

`tests/bilder-messen.js` in der Browserkonsole, **je Lage eine frisch geladene
Seite**, auf:

- `/backups` (mit dem Bereich „Ohne Abonnement" — er braucht Punkt 2),
- `/subscriptions/<id>/backups`,
- `/backups/<id>/restore`,
- `/operations/<id>` des Wiederherstellungsvorgangs,
- `/settings/backups`.

**Erwartet:** `dokument = 0`, Gegenprobe `200/200`, `schiebt` leer.

**Und der Knopf „Herunterladen" wird als Administrator gegengeprüft:** Er steht
nicht da, und die Adresse gibt 403 (`docs/117 §4`).

---

## 8b · Der Abbau, und er wird belegt

```bash
# Das wiederhergestellte Abonnement zurückbauen (es legt dabei eine Sicherung an).
# Danach die Sicherungen dieses Laufs entfernen — über das Panel, nicht mit rm.
ls -la /var/lib/srvpanel/backups/
srvpanel diagnose
rm -f /root/p8-heil.zip /root/p8-vorher-*.json /root/p8-*.txt
```

Und der Schalter aus `§0b` zurück auf seinen **gelesenen** Wert.

**Erwartet:** Die Diagnose steht danach da, wo sie in `§0b` stand — gezählt und
nicht geschätzt.

---

## 9 · Was dieser Lauf ausdrücklich **nicht** prüft

- **Ein Fernziel.** Es gibt keines; der Punkt ist gestrichen (§0.1), S3 steht in
  P9b.
- **Dass die Webseiten danach laufen.** Nach Form A nennt die `wp-config.php`
  des Kunden eine Datenbank, die es nicht mehr gibt — das Panel fasst sie nicht
  an und **sagt** es (Punkt 6). `docs/117 §0` hat das Kriterium deshalb neu
  gefasst.
- **Eine Wiederherstellung, die auf halbem Weg scheitert.** Sie räumt nicht auf;
  der Lauf sagt in seinem Ergebnis, was misslungen ist (`docs/117 §15`).
- **Die Laufzeit über echte Kundenarchive** jenseits der einen Messung in
  Punkt 7. `TimeoutStartSec=7200` ist gegen die Platte dieses Containers
  gerechnet (`docs/117 §9` Punkt 6).
- **`retry_after` gegen einen Lauf von 1800 s** (`docs/117 §9` Punkt 4).
- **Ob ein Kunde seine eigene Sicherung herunterladen kann.** Der Lauf fährt als
  Betreiber; die Grenze hält `BackupDownloadTest` an der Tür.
- **Die Aufbewahrung über mehrere Nächte.** Punkt 1 bis 7 laufen an einem Tag;
  dass die älteste nach der vierten Sicherung geht, hält `BackupRetentionTest`.

---

## 10 · Wann er durch ist

**Alle sieben Punkte erfüllt**, und **5 und 6 dürfen nicht ausfallen**. Fällt
einer der übrigen als „nicht herstellbar" aus, wird er benannt — und „nicht
herstellbar" heisst am **Gegenstand** gescheitert und nicht am Werkzeug.

> **Ein Punkt, der am Werkzeug scheitert und nicht am Gegenstand, ist nicht
> „nicht herstellbar" — und ihn so zu nennen wäre die bequemere von zwei
> falschen Auskünften.**

Ins Protokoll gehören:

- die **Fassung**, gegen die gemessen wurde,
- je Punkt der **gemessene** Wert und nicht „erfüllt",
- die Zuordnung alt → neu aus Punkt 6 im Wortlaut,
- der Beleg aus §8b, dass der Prüfstand abgeräumt ist,
- und was danach **offen bleibt**.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
