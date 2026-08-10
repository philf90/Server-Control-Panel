# 41 — Die Dateisystem-Quota

**Was sie trägt:** die Speichergrenze jedes Abonnements *und* die Messung des
belegten Platzes. Ohne sie zeigt das Panel eine Grenze, die nichts begrenzt, und
neben jedem Abonnement steht „noch nicht gemessen".

---

## 1. Der Anlass

Am 10. August 2026 hat der Betreiber auf `cloudsrv24` zwei Abonnements angelegt.
Beide Vorgänge meldeten **„fertig, 100 %"**, und in ihrer Ausgabe stand:

    setquota: Cannot find mountpoint for device
    setquota: No correct mountpoint specified.

Gemessen war die Ursache eindeutig: `/var/www/vhosts` liegt auf `/`
(`/dev/vda3`, ext4), und die Mount-Optionen waren `rw,relatime` — **ohne
`usrquota`**. Die Quota war nie eingeschaltet.

**Der Agent hat das gesagt.** `DiskQuota::apply()` gibt seit jeher
`['enforced' => false, 'reason' => …]` zurück und bricht ausdrücklich nicht ab —
ein Abonnement soll nicht scheitern, weil ein Dateisystem keine Quota kann. Nur
hat diese Antwort in `app/` niemand gelesen.

> **Ein Feld, das niemand liest, ist keine Auskunft, sondern Rechenzeit.**

---

## 2. Einschalten — Debian/Ubuntu, ext4

### 2.1 Die Werkzeuge und die `fstab`

```bash
apt install quota

cp /etc/fstab /etc/fstab.$(date +%F)
```

Die Wurzelzeile um `usrquota` ergänzen:

    aus:   UUID=…   /   ext4   defaults           0 1
    wird:  UUID=…   /   ext4   defaults,usrquota  0 1

**Vor dem Neustart prüfen** — eine falsche Zeile hier ist der klassische Weg zu
einem Server, der nicht mehr hochkommt:

```bash
findmnt --verify --fstab
```

Und den Rettungszugang des Anbieters bereithalten. `findmnt --verify` fängt das
meiste ab, nicht alles.

### 2.2 Die Option wirksam machen

Neu starten — oder, ohne Neustart:

```bash
mount -o remount /
```

### 2.3 Und jetzt der Schritt, den man vergisst

**Die Mount-Option schaltet die Quota nicht ein.** Sie erlaubt sie. Gemessen auf
`cloudsrv24`, nachdem `fstab` und `remount` schon stimmten:

```
findmnt -T /var/www/vhosts -o OPTIONS   → rw,relatime,quota,usrquota
quotaon -p /                            → user quota on / (/dev/vda3) is off
repquota -s /                           → Cannot open quotafile //aquota.user
```

> **Eine Option, die etwas erlaubt, ist nicht dasselbe wie ein Zustand, in dem
> es geschieht.** Genau darauf ist dieser Ablauf einmal hereingefallen — und
> deshalb misst das Panel den *Leseversuch* und nicht die Optionszeile.

Es fehlt die Quotadatei:

```bash
quotacheck -cum /      # legt /aquota.user an; -m, weil / eingehängt bleibt
quotaon -v /
```

`-m` überspringt das Umhängen auf nur-lesend. Auf einer laufenden Wurzel zählt
der Scan dadurch möglicherweise ungenau; der nächste Systemstart korrigiert es.
Wer es exakt will, startet nach dem `fstab`-Eintrag neu und lässt `quotacheck`
vom Systemstart erledigen.

### 2.4 Gegenprobe

```bash
quotaon -p /       # muss „is on" sagen
repquota -s /      # der Bericht, mit einer Zeile je Systembenutzer
```

---

## 3. Danach: die Grenzen anwenden

**Die bestehenden Abonnements bekommen ihre Grenze nicht von selbst.**
`SubscriptionController::update()` reiht `subscription.quota` nur ein, wenn sich
der Wert *unterscheidet* — und er unterscheidet sich nicht.

> **Eine Einstellung, die sich nur durch eine Änderung anwenden lässt, hat
> keinen Weg zurück in einen Zustand, den jemand anderes verändert hat.**

Dafür gibt es seit dem 10. August 2026 den Knopf **„Grenze erneut anwenden"** auf
der Abonnementseite. Er erscheint genau dann, wenn die Grenze nachweislich nicht
gilt — nicht immer.

Danach misst `srvpanel usage` (Timer, Viertelstundentakt) den belegten Platz,
und die Übersicht verliert ihren Hinweis.

---

## 4. Woran das Panel es merkt

An **drei** Stellen, und jede beantwortet eine andere Frage:

| Ort | Frage | Quelle |
|---|---|---|
| Übersicht | Führt dieser Server überhaupt Quota? | `subscription.usage`, jede Viertelstunde |
| Abonnement | Gilt *diese* Grenze? | `subscription.provision` / `subscription.quota` |
| Vorgang | Was hat `setquota` gesagt? | wörtlich, in der Ausgabe |

**Keine davon liest die Mount-Optionen** — siehe §2.3. Was zählt, ist der
Leseversuch: `repquota` scheitert, solange die Quota nicht läuft, und dieses
Scheitern *ist* die Antwort.

**Und alle drei schweigen, solange nichts gemessen wurde.** `null` heisst „nicht
nachgesehen" und ist weder ja noch nein — dieselbe Form wie `handed_over` und der
Kernel. Ein Hinweis, der immer erscheint, erzieht dazu, die Seite nicht zu lesen.
