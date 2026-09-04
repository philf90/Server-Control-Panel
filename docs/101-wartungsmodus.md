# A12 — der Wartungsmodus

Geschrieben am 4. September 2026, **nach** der Messrunde (`docs/81 §2.3p`) und
vor der ersten Zeile Code. Der Punkt steht in `docs/81 §12.1` als eigener Punkt
in P7b **hinter A10** — A10 ist die Stufe, deren erste Regel „schreibt nichts"
lautet, und A12 schreibt.

---

## 1 · Was es ist

Ein Schalter, der **alle Kundenwebsites** auf 503 stellt, während das Panel
erreichbar bleibt. Für den Fall, dass der Betreiber etwas am Server tut, bei dem
eine halb bediente Website schlimmer ist als eine ehrlich abgeschaltete.

**Was es nicht ist:** keine Sperre eines Abonnements (das ist
`subscription.suspend` seit P2), keine Ankündigung im Panel (das ist A14) und
**keine Automatik** — der Betreiber hat sie am 4. September gestrichen.

---

## 2 · Die Entscheidungen des Betreibers

| | Entschieden am 4. September 2026 |
|---|---|
| **Der Text** | **Feste Form**, kein Freitext. Der informative Teil ist die Zeitangabe, und die ist ein typisierter Wert. |
| **Zeitangabe** | Bleibt, als **„voraussichtlich bis"** — ohne Wirkung, nur als Auskunft. |
| **Automatik** | **Entfällt.** Geschaltet wird von Hand, an und aus. |
| **Der Zustand** | Wird bei jeder Anfrage neu abgeleitet, nichts wird gemerkt. |
| **Wer schaltet** | `can:operate-server` — dieselbe Fähigkeit wie `/server/reboot`, und der ist eingreifender. |

**Warum kein Freitext, und das ist keine Bequemlichkeit.** Die Seite steht als
Zeichenkette *im nginx-Block*; ein Apostroph im Freitext beendet sie. Die erste
Grenze dieses Projekts lautet, dass niemals Text der Anwendung zu
Konfiguration wird.

> **Was sich aus einem typisierten Wert erzeugen lässt, muss nicht durch die
> Grenze, an der Text zu Konfiguration wird.**

Käme Freitext später doch, dann als **Datei** und nicht als Konfigurationszeile
— mit einem Ablageort, dessen Rechte gemessen sind (`docs/78`:
`/var/lib/srvpanel` ist `0750`, der nginx-Worker kommt dort nicht durch; die
ACME-Prüfdatei liegt deshalb unter `/var/spool`).

**Warum keine Automatik.** Ein nginx-Block ist eine statische Datei und kennt
die Uhr nicht. Ein Fenster mit Wirkung bräuchte einen Zeitgeber an beiden
Rändern — und fällt der aus, bleibt jede Kundenwebsite unbegrenzt auf 503.
Dieses Projekt hat genau das schon bezahlt: `srvpanel-cron.timer` meldete
`active`, hatte keinen nächsten Termin und lief 22 Stunden nicht (`docs/64`).

> **Ein Fenster, dessen Ende ein Zeitgeber herstellt, endet nicht, wenn der
> Zeitgeber ausfällt — und der Ausfall sieht aus wie ein laufendes Fenster.**

---

## 3 · Wie es funktioniert

**Eine Flagdatei, die nginx selbst prüft.** Schalten ist das Anlegen und
Löschen **einer** Datei — sofort, unteilbar, ohne Warteschlange und ohne
Rundlauf über die Vhost-Dateien.

Die Form steht fest, weil sie gemessen ist (`docs/81 §2.3p`, M24 bis M28). Sie
geht **einmal je Server-Block** direkt hinter `server_name`, für alle vier
Formen identisch:

```nginx
set $wartung 0;
if (-f /var/spool/srvpanel/wartung) { set $wartung 1; }
if ($uri ~ ^/\.well-known/acme-challenge/) { set $wartung 0; }
if ($wartung = 1) { return 503; }

error_page 503 @wartung;
location @wartung {
    add_header Retry-After 3600 always;
    default_type text/html;
    return 503 '…';
}
```

**Jede Zeile hat ihren gemessenen Grund:**

- **Die zweite Zeile nimmt die Prüfadresse zurück, und das ist tragend.** Ohne
  sie gibt `/.well-known/acme-challenge/…` während der Wartung 503 (M24) — jede
  Zertifikatserneuerung stürbe, und zwar lautlos.
- **Es steht auf Serverebene und nicht in `location /`.** Dort deckte es die
  verschachtelte PHP-`location` nicht ab (M25): statische Dateien 503, PHP
  weiter bedient.
- **`add_header` steht in der benannten `location` und nicht im `if`.** Im `if`
  auf Serverebene ist es nicht erlaubt, `nginx -t` gibt `rc=1` (M27).
- **`always` ist nötig**, weil `add_header` sonst bei einem 503 nichts ausgibt.
  Gemessen: mit `always` kommt `Retry-After: 3600` beim Klienten an (M28).

**Und `nginx -t` prüft davon nichts.** Beide kaputten Fassungen bestehen ihn mit
`rc=0` (M26). Der Sollzustand gehört deshalb in die Zusage der Vorlage und nicht
in den Prüfer.

### 3.1 Was der Kunde sieht

Die feste Form, erzeugt aus zwei typisierten Werten:

- ohne Zeitangabe: *„Diese Website ist wegen Wartungsarbeiten vorübergehend
  nicht erreichbar."*
- mit Zeitangabe: *„… Voraussichtlich ab HH:MM Uhr wieder erreichbar."*

Kein „wenden Sie sich an den Betreiber" — das ist die **Sperrseite** und
verlangt vom Leser etwas. Bei einer Wartung gibt es für ihn nichts zu tun.

### 3.2 Wo der Zustand steht

In `Settings` unter `maintenance` — dieselbe Ablage wie `diagnose`,
`admin.networks` und `dns.addresses`, „die eine Stelle für Zustand, den der
Betreiber setzt und der keine eigene Tabelle rechtfertigt". Zwei Felder: ob an,
und die voraussichtliche Endzeit (UTC, `null` erlaubt).

**Die Datei auf der Platte ist die Wahrheit für nginx, die Einstellung die für
das Panel** — und sie können auseinanderlaufen. Genau dafür gibt es §5.

---

## 4 · Die Operation

`web.maintenance.set` mit einem einzigen typisierten Argument: `enabled` als
Boolean. **Die Endzeit geht nicht an den Agenten** — sie steht in der
Seitenvorlage und wird beim nächsten `web.site.apply` mitgeschrieben; der Agent
legt nur die Flagdatei an oder entfernt sie.

Das ist die Trennung, die A12 billig macht: **Schalten** ist eine Datei,
**Beschriften** ist der gewöhnliche Weg über die Vorlage.

Der Ablageort ist `/var/spool/srvpanel/wartung` — dasselbe Verzeichnis wie die
ACME-Prüfdatei, und aus demselben Grund: `/var/lib/srvpanel` ist `0750
srvpanel:srvpanel`, der nginx-Worker läuft als `www-data` und kommt dort nicht
durch (`docs/78`).

---

## 5 · Was die Bestandsdiagnose dazu prüft

Zwei Befunde in der Familie von A10, beide unter dem Schlüssel `web.file`
beziehungsweise einem neuen `web.maintenance`:

| Grund | wann | Zustand |
|---|---|---|
| `guard_missing` | ein Kundenblock trägt die Wache nicht | Kaputt |
| `overdue` | Wartungsmodus an, angekündigtes Ende überschritten | Auffällig |

**Der erste ist der Ersatz für den Prüfer, der nichts sieht.** M26 hat gemessen,
dass `nginx -t` eine fehlende oder halbe Wache durchwinkt. Die Zusage je Form
aus A10 kann es: `PROMISED_BY_FORM` wächst um `set`, `if`, `error_page` und die
benannte `location`, und `Statements::lostInNginx()` rechnet in beide Richtungen
nach.

**Der zweite ist das, was von der gestrichenen Automatik übrigbleibt** — und er
ist ehrlicher als sie. Der Nachtlauf meldet am Morgen, was der Betreiber am
Abend vergessen hat; niemand verlässt sich auf einen Zeitgeber, der ausfallen
kann.

---

## 6 · Die Oberfläche

Ein Bereich auf der Serverseite, unter `can:operate-server`:

- ein Schalter, an/aus
- **zwei** Felder für „voraussichtlich bis" — `type="date"` und `type="time"`,
  beide leer erlaubt oder beide gefüllt, in der Anzeigezeitzone eingegeben und
  als UTC abgelegt — über `Clock` und nicht daran vorbei

  > **Ein Format, das kein Eingabetyp hergibt, ist auf dem Telefon nicht
  > tippbar.** Der erste Wurf war **ein** Textfeld für `Y-m-d H:i` mit
  > `inputmode="numeric"`; die Zifferntastatur von iOS kennt weder Bindestrich
  > noch Doppelpunkt noch Leerzeichen, und das Feld war dort nicht ausfüllbar.
  > Gemeldet hat es der Betreiber am 4. September 2026, `docs/102 §2`.
- solange er an ist, ein Streifen auf **jeder** Seite des Betreibers und des
  Administrators: „Der Wartungsmodus ist eingeschaltet."

**Die Zeitzone ist die Hälfte, die still bricht.** `docs/40` hat das einmal
bezahlt: Eine umgestellte Anzeige ohne mitrechnende Grenze zeigt eine Zeile und
findet sie nicht. Und die **Zeitzone des Servers** ist A11 und offen — die
beiden dürfen nicht verwechselt werden.

---

## 7 · Das Abnahmekriterium

Auf einem echten Server, acht Punkte. **Punkt 3 und Punkt 4 dürfen nicht
ausfallen** — sie sind der Grund, dass es A12 gibt.

1. **Eingeschaltet, und eine Kundendomain antwortet mit 503.** Belegt mit dem
   Statuscode und dem Wortlaut der Seite.
2. **Die Zeitangabe steht im Rumpf**, wenn eine gesetzt ist — und nicht, wenn
   keine gesetzt ist. Beide Richtungen.
3. **`/.well-known/acme-challenge/…` antwortet weiter mit 200** — mit einer
   echten Prüfdatei, nicht mit einem 404. *(Ausschlusskriterium)*
4. **Eine PHP-Domain antwortet ebenfalls mit 503**, nicht mit ihrem Inhalt.
   *(Ausschlusskriterium — der Fall, den M25 gefunden hat)*
5. **Das Panel bleibt erreichbar**, während der Modus an ist.
6. **Ausgeschaltet, und jede Domain liefert wieder aus** — auch eine, die
   *während* der Wartung angelegt wurde.
7. **Ein gesperrtes Abonnement bleibt nach dem Ausschalten gesperrt.** Der
   Zustand wird abgeleitet und nicht zurückgespielt.
8. **Die Diagnose meldet `guard_missing`**, wenn die Wache aus einem Block von
   Hand entfernt wird — und `nginx -t` gibt dabei `rc=0`.

Punkt 8 ist der Beleg, dass A10 die Lücke deckt, die der Prüfer nicht sieht.

---

## 8 · Die Wächter

| | Was er hält |
|---|---|
| `MaintenanceGuardTest` | Die Wache steht in **jeder** Form, und die Ausnahme für die Prüfadresse steht **vor** der Entscheidung — gehalten an der gerenderten Zeichenkette und nicht an einer Liste im Test |
| `MaintenancePromiseTest` | Die vier neuen Anweisungen stehen in `PROMISED_BY_FORM` jeder Form, in beide Richtungen |
| `MaintenanceStateTest` | `suspended` ist `Wartung ODER Abonnement nicht benutzbar` — und wird nirgends gemerkt; gemessen an der Wirkung über `WebLifecycle::payload()` |
| `MaintenanceRouteTest` | Die Route trägt `can:operate-server`, und der Schalter steht in einem `v-if` auf diese Fähigkeit |
| `MaintenanceClockTest` | Die Endzeit geht durch `Clock` — Eingabe in der Anzeigezeitzone, Ablage in UTC, und die Grenze rechnet mit |

Jeder mit einem Bruch in `tests/waechter-brechen.sh`, jeder Bruch einzeln
belegt.

---

## 9 · Was A12 ausdrücklich **nicht** wird

- **Keine Automatik**, kein Zeitgeber, kein Fenster mit Wirkung.
- **Kein Freitext.**
- **Keine Ankündigung im Panel** — das ist A14, und die beiden haben
  verschiedene Publika: Die 503-Seite sieht ein Website-Besucher, die
  Ankündigung ein Panel-Nutzer.
- **Keine Ausnahme je Domain.** Der Modus gilt für alle oder für keine; eine
  einzelne Domain abzuschalten ist die Sperre des Abonnements.
- **Keine Rücknahme nach Zeit.** Wer aussperrt, ist hier niemand: Das Panel
  bleibt erreichbar, also ist der Rückweg immer da.

---

## 10 · Wann er durch ist

Alle acht Punkte gemessen, **3 und 4 erfüllt**. Fällt einer der übrigen als
„nicht herstellbar" aus, wird das mit seinem Grund protokolliert und hält die
Abnahme nicht auf; fällt einer als **nicht erfüllt** aus, ist er ein Befund.

Das Protokoll bekommt die nächste freie Nummer und enthält je Punkt den
**gemessenen** Wert und nicht das Urteil „erfüllt" allein.

> **Ein Protokoll ohne seine Lücken liest sich wie eine Abnahme.**
