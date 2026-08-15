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

## Offen, klein, nicht verfolgt

`ls /var/www/vhosts/p6-b.invalid/logs/` und der `tail` darauf haben nichts
ausgegeben. Ob dort keine Protokolle liegen oder nginx woandershin schreibt, ist
für diesen Lauf ohne Belang und bleibt **benannt offen** — nicht als erledigt
gezählt.
