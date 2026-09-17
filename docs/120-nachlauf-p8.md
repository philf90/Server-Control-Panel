# P8 — der Nachlauf zu den sechs Behebungen

**Ausgeschrieben am 17. September 2026, vor dem Fahren.** Der Plan ist
`docs/117`, der Abnahmelauf `docs/118`, sein Protokoll `docs/119`.

**Warum es ihn gibt:** `docs/119` hat Punkt 4 als **nicht erfüllt** gemessen, und
P8 ist damit nicht abgenommen. Sechs der zehn Befunde am Prüfling sind seitdem
gebaut — **und keine dieser Behebungen hat einen Server gesehen.**

> **Ein Befund gilt als behoben, wenn jemand nachgesehen hat — nicht, wenn
> jemand ihn behoben hat.**

> **Eine Behebung ist eine Änderung, und jede Änderung ist ein neuer Anlass zu
> messen.**

**Er ersetzt `docs/118` nicht.** Punkt 4 wird dort vollständig neu gefahren; was
hier steht, ist alles Übrige.

---

## 0 · Was vor dem Lauf gelesen wird

**Drei Zeilen sind beim Ausschreiben umgefallen**, und jede hätte etwas
gekostet.

**Die erste:** Punkt 1 wollte „nach dem Zurückspielen kommt die Website mit 200".
Die Domain ist `p8-abnahme.invalid`, und `.invalid` löst nach RFC 2606 nie auf —
die Anfrage muss deshalb mit `--resolve` oder als `Host:`-Kopfzeile an
`127.0.0.1` gehen, sonst misst sie den Auflöser und nicht den Webserver. Und ein
`200` vom **Vorgabeblock** sähe genauso aus wie eines vom richtigen; gemessen
wird deshalb zusätzlich, dass die ausgelieferten Bytes die des Kunden sind.

> **Ein Rückgabewert von 200 sagt, dass jemand geantwortet hat — nicht, dass der
> Gemeinte geantwortet hat.** (`docs/921`)

**Die zweite:** Punkt 5 wollte die Subdomain am **bestehenden** Prüfkörper
messen. Den gibt es nicht mehr — `docs/119 §8b` hat ihn abgebaut. Der Punkt legt
seinen Gegenstand deshalb selbst an, und zwar **vor** der Sicherung; eine
Subdomain, die erst nach dem Sichern entsteht, steht in keiner Beschreibung.

**Die dritte:** Punkt 6 wollte „das leere Verzeichnis wird gemeldet" an dem
Verzeichnis messen, das `docs/119 §8b` hinterlassen hat. Das ist der richtige
Gegenstand — aber die Prüfung fragt zusätzlich, ob ein **Abonnement** dieses
Namens lebt. Nach Punkt 1 lebt `p8-abnahme.invalid` wieder, und dann ist das
Verzeichnis zu Recht kein Rest. Punkt 6 läuft deshalb **vor** Punkt 1, oder an
einem Namen, den dieser Lauf nicht wiederbelebt.

> **Ein Prüfkörper, der einen Zustand braucht, den der Lauf davor verbraucht,
> gehört an einen Gegenstand, der nachwächst — oder vor ihn.**

**Ausserdem gelesen, bevor gemessen wird:**

- Die Fassung auf dem Server: `srvpanel version` muss die sein, die die sechs
  Behebungen trägt. Eine Messung gegen die installierte Fassung von gestern
  misst den Befund und nicht die Behebung.
- `docs/119 §12` — was offen bleibt und was dieser Lauf **nicht** klärt.

---

## 1 · Befund 4 — die Website antwortet wieder *(Ausschluss)*

**Das ist Punkt 4 aus `docs/118` und der Grund, dass P8 nicht abgenommen ist.**

Prüfkörper anlegen wie in `docs/119 §0b` — ein Abonnement mit einer Datenbank je
System, einer Hauptdomain, einem Cronjob und Dateien in `httpdocs` —, sichern,
zurückbauen, zurückspielen. Dann:

```
# Der Eigentümer je Bereich des Schemas
stat -c '%U %G %a %n' /var/www/vhosts/<neuer-benutzer>/{httpdocs,logs,tmp,conf}
stat -c '%U %G %a %n' /var/www/vhosts/<neuer-benutzer>/httpdocs/index.html

# Und die Wirkung, an der echten Leitung
curl -sS -o /dev/null -w '%{http_code}\n' --resolve <domain>:80:127.0.0.1 http://<domain>/
curl -sS --resolve <domain>:80:127.0.0.1 http://<domain>/ | head -3
```

**Erwartet:**

| Ort | Eigentümer | Modus |
|---|---|---|
| `httpdocs` | `<benutzer>:www-data` | `2750` |
| `logs` | `<benutzer>:adm` | `2750` |
| `tmp` | `<benutzer>:<benutzer>` | `2700` |
| `conf` | `root:root` | `0755` |
| `httpdocs/index.html` | `<benutzer>:www-data` | unverändert |

und **`200`** mit den Bytes des Kunden — nicht der Vorgabeseite.

**Die Gegenprobe steht daneben und nicht dahinter:** `curl` auf einen Namen, den
es dort nicht gibt, muss etwas anderes geben. Ohne sie ist das `200` von einem
`200` des Vorgabeblocks nicht zu unterscheiden.

**Dieser Punkt darf nicht ausfallen.** Er ist der einzige, der belegt, dass die
Behebung von Befund 4 auf einem Server wirkt — im Container ist sie an der
**Kennung** gemessen und nicht an nginx.

---

## 2 · Befund 5 — kein unechter Fehlschlag, und die Subdomain kommt zurück

Am selben Vorgang wie Punkt 1, auf der Vorgangsseite von `backup.restore`:

```
"failures": []
```

**Erwartet: leer.** Vor der Behebung stand dort genau eine Zeile,
`"grund": "Diese Sorte Domain lässt sich nicht anlegen."` für die Hauptdomain.

**Und die zweite Hälfte, die der Abnahmelauf nicht hatte:** Vor dem Sichern eine
**Subdomain** unter der Hauptdomain anlegen (`shop.<domain>`). Nach dem
Zurückspielen:

```
srvpanel tinker
>>> Domain::withoutGlobalScopes()->where('subscription_id', <neu>)->get(['name','type','parent_domain_id'])
```

**Erwartet:** zwei Zeilen, die Subdomain mit `parent_domain_id` auf die
Hauptdomain — und `failures` weiterhin leer.

**Das ist die Wirkung, die schwerer wog als die gemeldete Zeile:** Vor der
Behebung bekam der Kunde die Subdomain gar nicht zurück, mit der Meldung „Die
Domain …, unter der sie hängt, ist nicht angelegt worden".

---

## 3 · Befund 6 — beide Seiten des Paares tragen den Namen

Auf derselben Vorgangsseite:

```
"system_user": { "alt": "p<alt>", "neu": "p<neu>" }
"db_prefix":   { "alt": "<alt>",  "neu": "<neu>"  }
```

**Erwartet:** `alt` beginnt mit `p` und ist keine nackte Zahl. Vor der Behebung
stand dort `"alt": 1141` neben `"neu": "p1142"`.

**Abgelesen wird das Paar und nicht nur der eine Wert** — die Zahl allein sagt
nichts darüber, ob daneben dieselbe Form steht.

---

## 4 · Befund 8 — die verwaiste Sicherung lässt sich entfernen

Nach dem Rückbau aus Punkt 1 steht die Sicherung unter „Ohne Abonnement" auf
`/backups`. Als **Betreiber**:

1. Die Zeile trägt eine Spalte „Aktion" mit einem Knopf „Entfernen".
2. Der Knopf fragt zurück und nennt den Ablagenamen in der Frage.
3. Nach dem Bestätigen entsteht ein Vorgang `backup.remove`.
4. Der Vorgang steht auf `succeeded`, die Zeile ist fort, und die Datei auch:

```
ls -l /var/lib/srvpanel/backups/<abo>/
```

**Gemessen wird der Vorgang und nicht das Verschwinden der Zeile.** Ein
`delete()` ohne den Agenten liesse genau den Rest liegen, den Punkt 6 sucht.

> **Ein Griff, den es gibt und zu dem kein Weg führt, ist von einem, den es
> nicht gibt, nicht zu unterscheiden.**

---

## 5 · Befund 7 — der Betreiber findet die Sicherungen

Als **Betreiber**, in der Navigation:

- Unter **Verwaltung** steht `Sicherungen` und führt auf `/backups`.
- Unter **Einstellungen** steht `Automatische Sicherung` und führt auf
  `/settings/backups`.
- Die Seite dahinter trägt die Überschrift `Automatische Sicherung`.

**Und bei 390 px**, weil die Leiste dort eine Schublade ist: Beide Einträge sind
nach dem Öffnen sichtbar und lesen sich nicht als derselbe.

> **Ein Hinweis, der in einer Schublade liegt, erreicht nur den, der die
> Schublade ohnehin öffnet.**

Als **Administrator** (Rolle `admin`, nicht `operator`): `Automatische
Sicherung` steht **nicht** da — es trägt `operate-server`. `Sicherungen` steht
da.

---

## 6 · Befund 10 — das leere Verzeichnis wird gemeldet

**Vor Punkt 1 fahren** (siehe §0, dritte Zeile).

Der Zustand ist der, den `docs/119 §8b` hinterlassen hat: ein leeres
`/var/lib/srvpanel/backups/p8-abnahme.invalid/` ohne Zeile und ohne Abonnement.

```
ls -la /var/lib/srvpanel/backups/
srvpanel diagnose
```

**Erwartet:** eine Zeile `warn backup.file · empty_directory ·
p8-abnahme.invalid`, und im Text der Pfad.

**Die Gegenprobe gehört dazu, und sie entscheidet den Punkt:** Ein Verzeichnis
eines **lebenden** Abonnements ohne Sicherungen darf **nicht** gemeldet werden.
Steht nach Punkt 1 ein solcher Fall da, muss die Zeile dafür fehlen — sonst
meldete die Prüfung jede Nacht jedes Abonnement, das gerade keine Sicherung hat.

> **Eine Abwesenheit belegt eine Grenze erst, wenn daneben etwas anwesend ist,
> das dieselbe Hülle braucht.**

**Und danach ist der Befund nicht klärbar**, und das gehört mitgeschrieben: Der
Griff (`backup.remove` ohne `storage`) hat keinen Aufrufer im Panel. Wie der
Betreiber die Zeile loswird, ist eine offene Entscheidung aus `docs/119 §12`.

---

## 7 · Befund 9 — **eine** Ablesung, und sie entscheidet nichts anderes

Unmittelbar nach dem Zurückspielen aus Punkt 1, **bevor** irgendetwas anderes
läuft:

```
srvpanel tinker
>>> CronJob::withoutGlobalScopes()->where('subscription_id', <neu>)->get(['id','label','command','active'])
cat /etc/cron.d/srvpanel-<neuer-benutzer>
```

**Erwartet:** genau **eine** Zeile je Job der Beschreibung.

**Steht dort schon zweimal, entsteht die Verdopplung beim Zurückspielen.** Steht
dort einmal, entsteht sie später — und dann sagt dieser Lauf, *wo nicht*, und
die Ursache bleibt offen.

> **Zwei Messungen, die auseinandergehen, entscheidet keine Überlegung, sondern
> die dritte.**

**Dieser Punkt darf ohne Befund ausgehen.** Er misst eine Frage und prüft keine
Behebung; es gibt keine.

---

## 8 · Der Fortschritt und die Zeilenzahl — was `docs/119` offenliess

Beides scheiterte dort am **Prüfkörper** und nicht am Gegenstand: 8,5 MB und
zwei leere Datenbanken.

- **Der Fortschritt:** Der Prüfkörper dieses Laufs trägt genug Dateien, dass
  Sicherung und Wiederherstellung je über zehn Sekunden brauchen. Gemessen wird,
  dass der Balken auf der Vorgangsseite zwischen 0 und 100 einen Wert dazwischen
  zeigt — abgelesen und nicht erinnert.
- **Die Zeilenzahl:** Vor dem Sichern in **beide** Datenbanken Zeilen schreiben.
  Nach dem Zurückspielen dieselbe Zahl, je System gezählt.

**Beide fallen aus, wenn der Prüfkörper sie nicht hergibt** — und dann steht das
so da und nicht als „erfüllt".

---

## 9 · Was dieser Lauf ausdrücklich nicht prüft

- **Punkt 4 aus `docs/118` in seiner ganzen Breite** — die übrigen Zeilen dort
  (Systembenutzer, Präfix, Datenbanknamen, Modi, Cronjob, Verweis) sind am
  17. September gemessen und erfüllt. Gefahren wird hier die eine, die es nicht
  war, und alles, was die Behebungen berührt haben.
- **Den Rest aus P7/A6** (`tls.file / expired / p6-b.invalid`). `.invalid` ist
  nach RFC 2606 nicht ausstellbar; der Befund ist ein Rest des Prüfstands.
- **Die `3 issues` auf `/backups/<id>/restore`** — sie stehen in `docs/119 §12`
  und sind weiterhin nicht nachgesehen.
- **Ob die Zeile auf der Sicherungsseite ohne Neuladen springt** (Befund 2). Sie
  ist dreimal verpasst worden; wer sie diesmal sehen will, bleibt nach „Jetzt
  sichern" auf der Seite stehen.

---

## 10 · Wann er durch ist

**Alle Punkte 1 bis 6 erfüllt**, und **Punkt 1 ist Ausschlusskriterium** — ohne
ihn ist P8 nicht abgenommen, gleich was die übrigen sagen.

**Punkt 7 darf ohne Befund ausgehen**, Punkt 8 darf am Prüfkörper ausfallen. Kein
anderer.

> **Ein Kriterium, das man beim letzten Punkt weicher liest als beim ersten, ist
> keines mehr — es ist eine Zusammenfassung.**

**Was dieser Lauf nicht abnimmt**, steht in §9 und bleibt benannt offen.
