# P8 — der Nachlauf: das Protokoll

**Gefahren am 18. September 2026 auf `cloudsrv24`**, gegen `0.7.4-rc.14` und —
ab dem Befund an den Lebensläufen — gegen `0.7.4-rc.15`. Die Vorschrift ist
`docs/120`, der Abnahmelauf davor `docs/118`, sein Protokoll `docs/119`.

**Alle acht Punkte erfüllt**, **Punkt 1 als Ausschlusskriterium darunter**,
keiner als „nicht herstellbar" ausgefallen.

> **P8 ist damit abgenommen.**

`docs/119` hatte Punkt 4 als **nicht erfüllt** gemessen, und daran hing die
ganze Stufe. Er ist hier vollständig neu gefahren — mit der Gegenprobe daneben
und nicht dahinter.

---

## 0 · Zwei Fassungen, und der Wechsel dazwischen ist selbst eine Messung

Der Lauf begann gegen `0.7.4-rc.14`, die die sechs Behebungen aus
`docs/119 §12` trägt. **Punkt 7 hat sie sofort umgeworfen** — die Verdopplung
des Cronjobs war nicht behoben, sondern überhaupt erst verstanden. Der Befund
wurde gebaut, als `0.7.4-rc.15` ausgeliefert und eingespielt, und alles
Übrige lief dagegen.

**Das ist kein Nachteil des Laufs, sondern sein Ertrag.** Dieselbe Maschine,
derselbe Prüfkörper, zwei Fassungen — und damit hat die Behebung eine
Gegenprobe in der Zeit statt eines Eingriffs:

| | `rc.14` | `rc.15` |
|---|---|---|
| `Schlüssel` des Sicherungsvorgangs | … `restored` | **ohne `restored`** |
| Cronjobs nach der Sicherung | **2** | **1** |

> **Zwei Messungen an derselben Stelle belegen eine Änderung. Eine belegt einen
> Zustand.**

---

## 0b · Der Prüfkörper

Abonnement **144**, `p8-nachlauf.invalid`, Systembenutzer `p1143`:

```
501 MB in httpdocs, 501 Dateien plus index.html
mariadb 5000 Zeilen  ·  postgres 5000 Zeilen
Domain p8-nachlauf.invalid (main) + shop.p8-nachlauf.invalid (subdomain)
ein Cronjob
```

**Die 500 MB sind kein Übermut.** `docs/119` liess Punkt 8 an 8,5 MB und zwei
leeren Datenbanken ausfallen: Der Lauf war zu kurz für einen Balken und zu leer
für eine Zeilenzahl. Mit diesem Prüfkörper dauert eine Sicherung **14 Sekunden**
— zweimal gemessen, auf die Sekunde gleich —, und beide Hälften von Punkt 8
sind fahrbar.

> **Ein Punkt, der am Prüfkörper ausfällt, fällt beim nächsten Mal wieder aus,
> wenn der Prüfkörper derselbe bleibt.**

---

## 1 · Die Website antwortet wieder *(Ausschluss)* — erfüllt

Gefahren als `sichern → zurückbauen → zurückspielen`. Das Zurückspielen ist
Vorgang **933**, aus der Sicherung, die der Rückbau selbst angelegt hat;
Laufzeit 12:31:58 bis 12:32:00.

**Der Eigentümer je Bereich des Schemas:**

| Ort | gemessen | erwartet |
|---|---|---|
| `httpdocs` | `p1144:www-data 2750` | ✓ |
| `logs` | `p1144:adm 2750` | ✓ |
| `tmp` | `p1144:p1144 2700` | ✓ |
| `conf` | `root:root 755` | ✓ |
| `httpdocs/index.html` | `p1144:www-data 644` | ✓ |

**Und die Wirkung an der echten Leitung, mit der Gegenprobe daneben:**

```
kunde   200   12 Bytes   →  p8-nachlauf
fremd   200  615 Bytes   →  Vorgabeseite
```

**Beide antworten mit `200`, und genau deshalb steht die zweite Zeile da.** Die
**Bytes** trennen sie. `docs/120 §0` hatte das vorhergesehen und ist damit die
Stelle, an der ein falsches Grün verhindert wurde.

> **Ein Rückgabewert von 200 sagt, dass jemand geantwortet hat — nicht, dass der
> Gemeinte geantwortet hat.** (`docs/921`)

**Die zwei Sekunden waren kein Befund**, und das ist eigens nachgemessen
worden: Das Ergebnis zählt `files: 502`, `directories: 5`, `owned: 510`, und
`du -sh` sagt 501 MB. Ein `ls -1` über `httpdocs` gibt dabei `2` — die oberste
Ebene, nicht die Zahl der Dateien.

> **Eine Zahl, die kleiner ist als erwartet, ist erst dann ein Befund, wenn sie
> dieselbe Grösse misst.**

---

## 2 · Kein unechter Fehlschlag, und die Subdomain kommt zurück — erfüllt

Im Ergebnis von Vorgang 933:

```
"failures": []
```

Vor der Behebung stand dort genau eine Zeile, *„Diese Sorte Domain lässt sich
nicht anlegen."* für die Hauptdomain.

**Und die zweite Hälfte, die der Abnahmelauf nicht hatte:**

```
Domain : 67  p8-nachlauf.invalid       type=main       parent=NULL
Domain : 68  shop.p8-nachlauf.invalid  type=subdomain  parent=67
```

Die Subdomain ist zurück **und hängt an der richtigen Hauptdomain**. Das ist die
Wirkung, die schwerer wog als die gemeldete Zeile: Vor der Behebung bekam der
Kunde sie gar nicht wieder, mit der Meldung „Die Domain …, unter der sie hängt,
ist nicht angelegt worden" — weil die Zuordnung leer anfing und die Hauptdomain,
die im selben Lauf entstand, dort nie eingetragen wurde.

---

## 3 · Beide Seiten des Paares tragen den Namen — erfüllt

```
"system_user": { "alt": "p1143",             "neu": "p1144" }
"db_prefix":   { "alt": "x021a34c02ffb11fb", "neu": "xef2518aa0e9c98a8" }
```

`alt` trägt `p1143` und nicht die nackte `1143`. Vor der Behebung stand dort
`"alt": 1141` neben `"neu": "p1142"`.

**Abgelesen wurde das Paar und nicht der eine Wert** — die Zahl allein sagt
nichts darüber, ob daneben dieselbe Form steht. Dasselbe gilt für `db_prefix`,
das denselben Schnitt hat und ihn richtig macht.

---

## 4 · Die verwaiste Sicherung lässt sich entfernen — erfüllt, in drei Hälften

Nach dem Rückbau von 145 lagen **sechs** Zeilen unter „Ohne Abonnement", alle
mit `abo=NULL` und erhaltener Abschrift, und **null lebende Abonnements dieses
Namens**. Ohne diese Null misst der ganze Punkt nichts — ein Verzeichnis, dessen
Abonnement lebt, ist zu Recht kein Rest.

**4a — die Gegenprobe, und sie lief fünfmal.** Fünf Zeilen einzeln entfernt:

```
Vorgang 960  backup.remove  succeeded  {"subscription":"…","storage":"…091108-400899cc"}
Vorgang 961  …  {"…","storage":"…101827-43516064"}
Vorgang 962  …  {"…","storage":"…103020-55720f07"}
Vorgang 963  …  {"…","storage":"…113251-a07b0684"}
Vorgang 964  …  {"…","storage":"…113445-5bdc0dbb"}
```

Je ein Vorgang, **kein zweiter**, jeder mit `storage`, das Verzeichnis steht.

**4b — die letzte Zeile, und nur hier:**

```
Vorgang 965  backup.remove  succeeded  {"subscription":"…","storage":"…113808-778627ed"}
Vorgang 966  backup.remove  succeeded  {"subscription":"p8-nachlauf.invalid"}
Sicherungen: 0
```

**Der zweite Vorgang hat keinen `storage`.** Das ist der ganze Unterschied
zwischen „noch eine Datei löschen" und „das Verzeichnis abräumen", und von
aussen ist er nur am leeren Payload zu sehen.

Die Wirkung steht zweimal unabhängig da: `p8-nachlauf.invalid` ist fort,
`p8-abnahme.invalid` steht unberührt daneben — **und die Zahl der Verweise auf
die Wurzel ist von 4 auf 3 gefallen.**

> **Eine Liste, die kürzer geworden ist, sagt nicht, um wie viel. Die Zahl der
> Verweise sagt es.**

**4c — die Sicherheitszusage, und sie hält.** Verzeichnis von Hand
wiederhergestellt, eine `fremd.zip` hineingelegt, `removeDirectory()` eingereiht:

```
Vorgang 967  backup.remove  succeeded  {"subscription":"p8-nachlauf.invalid"}
             → {"scope":"directory","removed":false}   "nichts zu entfernen"
fremd.zip    liegt unverändert da
```

`rmdir(2)` scheitert an allem, was noch drinliegt — **der Griff kann den Rest
nicht mitnehmen, den die Diagnose meldet.** Kein Fehlschlag, denn nichts ist
schiefgegangen; der Griff hat sich geweigert. Im Container war das an `rmdir`
gemessen, hier an der Operation.

**Der Prüfkörper musste eine `.zip` sein**, und das ist keine Kosmetik:
`BackupList` zählt über `glob('*.zip')`. Eine `fremd.txt` stünde in keiner
Antwort, das Verzeichnis sähe leer aus, und gemeldet würde `empty_directory`
statt `orphan`.

> **Ein Prüfkörper, der eine andere Form hat als die, nach der der Prüfling
> sucht, misst die falsche Frage.**

**Warum 4c über `tinker` und nicht über die Seite:** Es gab keine Zeile mehr,
die den Lebenslauf hätte auslösen können — dessen Entscheidung ist in 4a und 4b
gerade sechsmal gemessen worden. Der Vorgang nimmt denselben Weg: dieselbe
Operation, dieselbe Warteschlange, derselbe Agent.

---

## 5 · Der Betreiber findet die Sicherungen — erfüllt, in drei Ansichten

Bei **1440 px** und bei **390 px** (dort in der Schublade) steht unter
*Verwaltung* `Sicherungen` und unter *Einstellungen* `Automatische Sicherung`;
die Seite dahinter trägt dieselbe Überschrift mit der Unterzeile „Was der Server
von sich aus sichert".

Die beiden lesen sich nicht als derselbe Eintrag: verschiedene Gruppen,
verschiedene Namen, verschiedene Fragen — *wo liegen meine Sicherungen* gegen
*was sichert der Server von sich aus*.

**Als Administrator** steht `Sicherungen` da und `Automatische Sicherung`
**nicht** — unter *Einstellungen* nur `Allgemein`.

> **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas anwesend ist,
> das dieselbe Hülle braucht.** Ohne die dritte Ansicht wäre die Anwesenheit
> beim Betreiber kein Beleg gewesen.

---

## 6 · Das leere Verzeichnis wird gemeldet — erfüllt

Gefahren **vor** Punkt 1, weil der seinen Gegenstand verbraucht.

```
srvpanel backup-verify
Auffällig: 1
warn  backup.file  empty_directory  p8-abnahme.invalid
```

**Die Gegenprobe entscheidet den Punkt**, und sie stand daneben: drei leere
Verzeichnisse nebeneinander, von denen zwei einem **lebenden** Abonnement
gehören. Gemeldet wurde genau eines. Eines der beiden schweigenden war dabei
einen einzigen Buchstaben vom gemeldeten entfernt.

> **Eine Prüfung, die jede Nacht jedes Abonnement ohne Sicherung meldete, wäre
> von einer, die den Rest findet, an einem einzelnen Fall nicht zu
> unterscheiden.**

**Und die Vorschrift hat hier einen Umweg gekostet, der vor dem Lauf berichtigt
worden ist** — `docs/120 §6` hält ihn fest: Sie nannte zuerst `srvpanel
diagnose`, und das fährt die acht Prüfungen des Bestands, nicht
`Checks\Backups`. Unterschieden hat die beiden Fälle nicht die Zahl, sondern die
Frage, ob der **Agent** den Gegenstand überhaupt herausgibt.

---

## 7 · Die eine Ablesung — erfüllt, und sie hat den grössten Befund gefunden

`docs/120 §7` liess diesem Punkt ausdrücklich offen, ohne Befund auszugehen. Er
ist nicht ohne Befund ausgegangen.

**Gegen `rc.14`**, unmittelbar nach dem Zurückspielen: Cronjob **32 und 33**,
beide aktiv, **zwei** Zeilen in `/etc/cron.d/srvpanel-p1143`.

Nach dem Wortlaut der Vorschrift hiesse das „die Verdopplung entsteht beim
Zurückspielen". **Das war falsch**, und was sie wirklich entstehen liess, steht
als Befund 1 weiter unten.

**Gegen `rc.15`:** `Cronjobs danach: 1` nach der Sicherung, und nach dem
Zurückspielen **eine** Zeile in `/etc/cron.d/srvpanel-p1144`:

```
0 9 * * 1-5   p1144   /usr/lib/srvpanel/cron-run 34
```

Beide Wege gemessen, beide einfach.

---

## 8 · Der Fortschritt und die Zeilenzahl — erfüllt, beide Hälften

**Der Fortschritt**, an Vorgang 952:

```
läuft    Fortschritt  40 %   500 Dateien gepackt — httpdocs/masse/f405.bin
fertig   Fortschritt 100 %   13:34:46 → 13:35:00
```

Verlangt war „ein Wert zwischen 0 und 100". Gemessen ist mehr: die **40** mit
ihrem **mitlaufenden Text**, der die gerade gelesene Datei nennt. `Packer`
meldet alle 200 Einträge neu, und `f405.bin` bei 500 Dateien ist genau diese
Rechnung.

> **Ein Zwischenwert allein belegt, dass etwas läuft. Ein Zwischenwert, der sich
> mit seinem Gegenstand ändert, belegt, dass er gemessen wird.**

Die Erwartung war vorher ausgerechnet und nicht geschätzt: `BackupCreate` meldet
5, 10, 40, 80, 85, 90, 100. Sähe man nur 0 und 100, wäre das ein Befund am
Prüfling — die Stufen gibt es im Agenten, und dann käme keine an.

**Die Zeilenzahl:** `mariadb 5000` · `postgres 5000`, dieselbe Zahl wie vor dem
Sichern, je System gezählt — und unter **neuen** Namen
(`p1144_testmariadb`, `xef2518aa0e9c98a8_testpostgres`). Das ist der Punkt von
Form A und nicht sein Nebeneffekt.

---

## 8b · Der Abbau, und er ist eine Messung

```
rm fremd.zip · rmdir p8-nachlauf.invalid · rmdir p8-abnahme.invalid
srvpanel backup-verify   →   Keine Befunde an den Sicherungen.
```

**Beide Befunde sind verschwunden, weil ihr Grund verschwunden ist.**

> **Ein Befund, der verschwindet, wenn sein Grund verschwindet, ist gemessen.
> Ein Befund, der nur entsteht, ist abgelegt.** (`docs/913`)

`fail · tls.file · expired · p6-b.invalid` bleibt stehen und soll es: `.invalid`
ist nach RFC 2606 nie ausstellbar, und `docs/913 §15` hat ihn als Rest des
**Prüfstands** entschieden.

---

## 9 · Die Befunde

**Sechs, und drei davon hat der Betreiber beim Benutzen gemeldet** (1, 2 und
4). Keinen hat ein Test gefunden, und **keiner der sechs steckt im Prüfmittel** —
die Vorschrift war vor dem Fahren ausgeschrieben, die Messmittel lagen als
geprüfte Werkzeuge im Repo. Was blieb, war neuer Code und die Oberfläche darum.
Was der Lauf an eigenen Fehlgriffen hatte, steht in §10 und ist kein Befund.

### Befund 1 · Nach jeder Sicherung lief eine Wiederherstellung *(behoben in rc.15)*

**Der grösste Befund dieses Laufs, und er erklärt Befund 9 aus `docs/119`.**

`Lifecycles::afterSuccess()` rief **jeden** Handler für **jeden** Vorgang.
`handles()` gab es, es heisst genau danach, und gelesen hat es allein `handled()`
für einen Wächter. `RestoreLifecycle` prüfte `task` nicht — und bei einem
`backup.create` sind Abonnement *und* Sicherung da, denn der Gegenstand **ist**
die Sicherung. Nach jeder Sicherung lief deshalb eine vollständige
Wiederherstellung gegen das lebende Abonnement, `rebuildCron()` eingeschlossen.

> **Ein Verteiler, der jedem alles gibt, ist von einem, der richtig zuordnet,
> nur an dem Fall zu unterscheiden, in dem ein Zweiter zuständig zu sein
> scheint.**

Und die Frage der Vorschrift war dadurch falsch gestellt: Sie liess „beim
Zurückspielen" gegen „später" messen, und die Antwort war **„beim Sichern"**.

> **Ein Kriterium, das zwei Ausgänge zulässt, misst keinen von beiden.**
> (`docs/84`)

Behoben im **Verteiler** und nicht im Empfänger: `zustaendig()` fragt
`handles()`, und `LifecycleDispatchTest` hält beide Richtungen samt der
Gegenrichtung, dass der zuständige Lebenslauf weiterhin läuft.

### Befund 2 · Von der Abonnementseite führt kein Weg zu ihren Sicherungen

Gemeldet vom Betreiber beim Benutzen: *„/subscriptions/145 hat keinen Button
Jetzt sichern."*

Ausgezählt: `Subscriptions/Show.vue` verweist auf
`/subscriptions/{id}/files` und `/subscriptions/{id}/sftp`, auf `backups`
**null Mal**. „Jetzt sichern" steht allein in `Subscriptions/Backups.vue`, und
dorthin führt nur `/backups` → Abonnement wählen.

Bitter ist die Zeile daneben: Die Abonnementseite zeigt unter **Freigaben**
„Sicherungen anlegen — frei". Sie sagt also, dass es die Handlung gibt, und
bietet keinen Weg zu ihr.

**Das ist die fünfte Wiederholung derselben Familie** — Dateimanager
(`docs/55`), SFTP (`docs/59`), „Job anlegen" (`docs/64`), das Abzeichen
(`docs/907`). Und der Kommentar über `Route::get('/backups')` zitiert die Regel
wörtlich; er hat sie nur halb angewandt: Der schlüssellose Menüpunkt beantwortet
die **globale** Frage *„wo sind meine Sicherungen"*, nicht die von der
Abonnementseite aus gestellte *„dieses Abonnement sichern"*.

> **Zwei Geschwister mit demselben Zuschnitt, von denen eines auf der Seite
> steht und das andere nicht, sind keine Entwurfsentscheidung — es ist eine
> vergessene Zeile.**

### Befund 3 · Die Sicherungsvorgänge tragen keinen Handelnden

Die Vorgangsseite eines von Hand gedrückten `backup.create` sagt **„Ausgelöst
von: System"**. Daneben, derselbe Nachmittag und dieselbe Person:
`backup.restore` sagt „Administrator", `subscription.remove` auch.

`Backups::dispatch()` setzt `account_id` **nirgends**; `Operation::booted()`
füllt `origin` und `subscription_name`, aber nicht den Handelnden. Betroffen ist
alles, was durch diesen Helfer geht: `backup.create`, `backup.verify` und beide
`backup.remove`. Ausgezählt nennt `grep -rn "'account_id' =>" app/` vierzehn
Stellen — `Backups.php` steht nicht darunter.

Zwei Leser bekommen dadurch eine falsche Auskunft: die Vorgangsseite, und
`RunAgentOperation::actor()`, das bei `null` gar nichts weitergibt — **im
Protokoll des Agenten steht für jede Sicherung keine handelnde Person.**
`/audit` ist nicht betroffen; der Controller schreibt seinen Eintrag selbst.

Warum es mehr ist als eine leere Spalte:

> **Eine Null, die schon eine Bedeutung trägt, kann keine zweite bekommen — die
> beiden Fälle sehen danach gleich aus.** (`docs/901`)

`account_id = NULL` heisst „Kommandozeile oder Automatik". Der nächtliche
Sicherungslauf ist genau dieser Fall und soll `System` heissen — und ist von
einer Sicherung von Hand jetzt nicht zu unterscheiden.

### Befund 4 · Das Entfernen einer Sicherung wird von keiner Seite verfolgt

Gemeldet vom Betreiber: *„/backups aktualisiert sich nicht automatisch wenn das
Backup entfernt wurde. Es wird auch nicht auf die entsprechende operation
umgeleitet."*

- `BackupPick.vue` hat **keinen** Takt — kein `setInterval`, kein `reload`.
- `destroyOrphan()` leitet auf dieselbe Seite zurück mit „Die Sicherung wird
  entfernt." Der Satz ist richtig — der Agent arbeitet noch —, und die Zeile
  daneben steht bis zum Neuladen von Hand da, mitsamt ihrem Knopf.
- `Subscriptions/Backups.vue` hat einen Takt, aber er hängt an
  `status === 'pending'`. `Backups::remove()` ändert den Zustand der Zeile
  **nicht**; sie bleibt `ready`, bis der Lebenslauf sie löscht.

Das Anlegen **ist** verfolgt — dieselbe Runde hat es gemessen, die Zeile
erschien ohne Neuladen mit `wird erstellt`. Damit ist auch Befund 2 aus
`docs/119` belegt, den `docs/120 §9` als dreimal verpasst führte.

> **Zwei Wege, denselben Zustand zu zeigen — die Seite aktuell halten oder zum
> Vorgang führen —, und das Entfernen geht keinen von beiden.**

### Befund 5 · „nichts zu entfernen" steht für drei verschiedene Gründe

`Store::removeDirectory()` gibt `false` zurück, wenn es das Verzeichnis nicht
gibt, wenn es ein **Symlink** ist, und wenn `rmdir(2)` scheitert. Alle drei
werden zu `removed: false` und der Meldung **„nichts zu entfernen"** — und für
den in 4c gemessenen Fall ist der Satz falsch: Es *gab* etwas zu entfernen, und
genau deshalb blieb es liegen.

Der schwerste ist der mittlere. Sich zu weigern, einem Symlink zu folgen, ist
eine **Sicherheitsentscheidung**, und sie liest sich als „da war nichts". Im
selben Rumpf steht die Gegenprobe: Eine Abweichung von `realpath` **wirft** mit
Begründung, der Symlink gibt still `false`.

> **Ein Griff, der sich weigert, und einer, der nichts zu tun findet, geben
> dieselbe Antwort — und nur der erste ist eine Auskunft, die jemand braucht.**

Das wiegt, weil das Verzeichnis liegenbleibt und die Diagnose es jede Nacht
weiter meldet. Wer dann den Vorgang ansieht, liest „nichts zu entfernen" und
sucht den Fehler bei der Diagnose.

### Befund 6 · Die Sicherungszeile zeigt denselben Zeitpunkt zweimal in zwei Zonen

Jede Zeile auf `/backups` zeigt nebeneinander den Ablagenamen
`…-20260918-103020-…` und die Spalte „Angelegt" mit `2026-09-18 12:30:20` —
**derselbe Augenblick, zwei Stunden auseinander**, und nichts sagt, dass das so
gemeint ist.

`Backups.php` baut den Namen mit `gmdate('Ymd-His')`, also UTC; die Spalte geht
über `Clock` und zeigt die Anzeigezeitzone. Beide sind für sich richtig.

> **Dieselbe Grösse in zwei Fassungen anzuzeigen ist keine doppelte Auskunft,
> sondern eine widersprüchliche.** (`docs/91`)

Das trifft genau die Handlung, für die es die Seite gibt: Wer auswählt, aus
welcher Sicherung zurückgespielt wird, liest beide Werte in derselben Zeile.
**Der Name ist dabei nicht der Fehler** — ein zonenfreier UTC-Bezeichner
sortiert, überlebt einen Zonenwechsel und steht auch auf der Platte.

---

## 10 · Was der Lauf über sich selbst gelernt hat

### Ein Merkmal, das kein Kriterium bestellt hat, ist nebenbei belegt

„Vor dem Rückbau sichern" war angehakt, und die Vorgangskette des Rückbaus zeigt
es vollständig:

```
953 db.dump.create · 954 pg.dump.create · 955 backup.create
956 db.database.remove · 957 pg.database.remove · 958 db.dump.remove
959 subscription.remove
```

Die Sicherung läuft **vor** dem Rückbau fertig, wie die Einstellungsseite es
zusagt — und **aus ihr** ist in Punkt 1 zurückgespielt worden. Der Griff, der
„nichts zurücklässt", lässt jetzt eine Sicherung zurück, und sie hat den ganzen
Lauf getragen.

### Zwei Prüfkörper haben an der falschen Stelle gefragt

Der erste las `$s->db_prefix` an der **Subscription** — die Spalte gehört zu
`system_users`, und die Migration sagt das in ihrer ersten Zeile. Heraus kam
ein leerer Wert, der wie ein Befund aussah.

Der zweite interpolierte `{$f->check}` — die Spalte ist auf `FindingCheck`
gegossen, und der Lauf starb an „could not be converted to string".

> **Ein Prüfkörper, der eine Grösse an der falschen Stelle fragt, meldet eine
> Abwesenheit, die es nicht gibt.**

Beide entstanden aus dem Gedächtnis der Spaltennamen statt aus einem Blick in
`casts()`. Das kostete zwei Runden und keinen Befund.

### Eine Zahl ist keine Messung, auch wenn sie stimmt

`srvpanel backup-verify` sagte `Auffällig: 2`. Zwei erwartete Zeilen und zwei
beliebige sehen darin gleich aus; belegt hat den Punkt erst die **Liste**
dahinter, mit `orphan` und `empty_directory` als zwei verschiedenen Urteilen.

> **Eine Zahl, die um eins gestiegen ist, belegt keine Zunahme um eins — sie
> belegt eine Summe.** (`docs/913`)

**Und die Begründung dazu war beim ersten Mal falsch**: Ich hatte die Zahl für
die Summe über alle Befunde gehalten. `VerifyBackups::zusammenfassung()` filtert
auf `check = backup.file`, die Zahl war also richtig zugeschnitten. Der Schluss
stimmte, die Begründung nicht.

> **Ein Satz, der eine Begründung nennt, die niemand gemessen hat, ist auch dann
> falsch, wenn der Handgriff daneben richtig ist.**

### Der Balken steht auf einer Seite, zu der die Zeile nicht führt

Punkt 8 liess sich nur mit zwei Reitern messen: „Jetzt sichern" lässt den
Betrachter auf der Sicherungsseite stehen — richtig und gewollt —, und die Zeile
dort nennt den Zustand, aber nicht den Vorgang, der ihn erzeugt. Das ist
dieselbe Lücke wie Befund 2, eine Ebene kleiner.

### Die Vorschrift hat zweimal ein falsches Grün verhindert

`docs/120 §0` nennt drei Zeilen, die beim Ausschreiben umgefallen sind. Zwei
haben im Lauf getragen: die Gegenprobe zu `curl` (sonst hätte das `200` des
Vorgabeblocks wie ein Erfolg ausgesehen) und die Reihenfolge von Punkt 6 vor
Punkt 1 (sonst hätte Punkt 1 den Gegenstand von Punkt 6 wiederbelebt).

> **Ein Abnahmelauf ist Code, den niemand ausführt, bis es darauf ankommt.**

---

## 11 · Die Bilanz

| Punkt | | |
|---|---|---|
| **1** · Website antwortet wieder | **erfüllt** | *Ausschluss* |
| 2 · kein unechter Fehlschlag, Subdomain zurück | erfüllt | |
| 3 · beide Seiten des Paares tragen den Namen | erfüllt | |
| 4 · verwaiste Sicherung, Abräumen, Sicherheitszusage | erfüllt | a, b, c |
| 5 · der Betreiber findet die Sicherungen | erfüllt | 1440 px, 390 px, Administrator |
| 6 · das leere Verzeichnis wird gemeldet | erfüllt | |
| 7 · die eine Ablesung | erfüllt | mit dem grössten Befund |
| 8 · Fortschritt und Zeilenzahl | erfüllt | beide Hälften |

**Keiner ist als „nicht herstellbar" ausgefallen. P8 ist abgenommen.**

Sechs Befunde, **alle sechs im Prüfling** — die deutlichste Umkehrung von
`docs/45`, `docs/48`, `docs/59` und `docs/84`, und dieselbe Lage wie bei A10, A2
und A14, aus demselben Grund: Die Vorschrift war vor dem Lauf ausgeschrieben,
die Messmittel lagen als geprüfte Werkzeuge im Repo. Was blieb, war neuer Code.

**Drei der sechs hat der Betreiber beim Benutzen gemeldet und keine Messung**
(1, 2 und 4); die anderen drei fand das Nachlesen am Quelltext neben einer
Aufnahme. Drei davon (2, 4, 6) hängen daran, was ein Betrachter **erwartet** —
und das kann kein Wächter halten.

---

## 12 · Was offen bleibt

- **Die sechs Befunde sind gebaut, wenn sie gebaut sind.** Keiner davon ist
  während des Laufs behoben worden, ausser Befund 1 — eine Behebung ist eine
  Änderung am Prüfling.

  **Stand 18. September 2026, nach dem Lauf: 1, 2 und 3 sind gebaut.** Befund 2
  hat dabei eine Regel bekommen statt einer Zeile — `SubscriptionReachTest` —,
  und die hat beim ersten Lauf die **sechste** Stelle derselben Familie
  gemeldet: Auch zu den Cronjobs eines Abonnements führte von seiner Seite kein
  Weg. **Offen bleiben 4, 5 und 6**, und keiner davon hat einen Server gesehen.

  > **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
  > jemand ihn behoben hat.**
- **`fail · tls.file · expired · p6-b.invalid`** bleibt und gehört dem
  Prüfstand (`docs/913 §15`).
- **Die `3 issues` auf `/backups/<id>/restore`** aus `docs/119 §12` sind
  weiterhin nicht nachgesehen.
- **Die Frage aus `docs/117 §3`** — ob eine Wiederherstellung ihre **eigene**
  Reservierung zurückholen darf — hat eine gemessene Vorfrage bekommen und
  bleibt offen. Nach dem Lauf stehen zwei Zeilen in `system_users` mit
  derselben Abschrift:

  ```
  p1143  prefix=x021a34c02ffb11fb  abo=p8-nachlauf.invalid  seit=08:56:49
  p1144  prefix=xef2518aa0e9c98a8  abo=p8-nachlauf.invalid  seit=10:31:57
  ```

  > **Eine Abschrift, die zweimal denselben Namen trägt, kann nicht sagen,
  > welche der beiden Zeilen gemeint ist.** Wer den alten Namen zurückgeben
  > will, braucht ein Merkmal, das die tote von der lebenden Reservierung
  > trennt — der Name ist es nicht.
