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

## Befund 1 — der Vorher-Wert der Seite ist schon ein Fehlercode

**`seite=403`, und damit misst Punkt 1 nicht, was er messen soll.**

Der Punkt vergleicht den Statuscode vor und nach dem Update. Brechen die Rechte
durch 6c, antwortet nginx mit **403** — und das ist genau der Wert, der vorher
schon dastand. Die beiden Zahlen wären gleich, und der Punkt meldete
„unverändert".

> **Ein Vorher-Wert, der schon ein Fehler ist, kann den Fehler nicht anzeigen,
> auf den man wartet.**

Das ist derselbe Satz, den `docs/54 §5` über `.invalid` und die Namensauflösung
aufschreibt — dort erkannt, hier eine Zeile tiefer trotzdem gestellt. Die
Fassung dort spricht von einem „fremden Grund"; der HTTP-Status ist keine
fremde Ebene, sondern dieselbe, in der der Befund erwartet wird. Das macht ihn
schlimmer, nicht besser.

**Behoben, bevor das Update läuft:** In `httpdocs` kommt eine `index.html` mit
`0644`. Damit trennt Punkt 1 sauber, was er trennen soll:

| Datei | Rechte | Was sie misst |
|---|---|---|
| `index.html` | `0644` | Kommt nginx **durch das Verzeichnis**? |
| `p6-probe.txt` | `0640` | Trägt die Datei die **Gruppe**, über die er hereinkommt? |

Die zweite ist Punkt 2 und hängt am Prüfling. Die erste darf es ausdrücklich
nicht — sonst hätte Punkt 1 keinen Nachbarn, an dem er sich misst.
