# 44 — Der Ausfall vom 11. August 2026: `--bind=::`

**Was hier steht:** ein Panel, das während der Zwischenabnahme des Fernzugriffs
(`docs/43`) ausgefallen ist, die Messung dazu, und die vier Fehler, die daran
beteiligt waren. **Warum es ein eigenes Dokument ist:** Der teuerste von ihnen
stand als *Anweisung* in `docs/43` Punkt 3. Ein Abnahmelauf, der den Server
abschaltet, den er prüfen soll, ist kein Detail im Änderungsprotokoll.

---

## 1. Was passiert ist

Der Lauf war bei Punkt 3 angekommen, dem Freischalten:

```
srvpanel db --remote=on --bind=::
```

Die Ausgabe sah aus wie ein Erfolg, mit einer Auffälligkeit am Ende:

```
/etc/mysql/mariadb.conf.d/60-srvpanel.cnf: geschrieben
Horcht auf :: — Fernzugriff möglich.
PostgreSQL: übersprungen — das Panel bietet es nicht an (srvpanel db --postgresql=on).
```

Die letzte Zeile war falsch: Die Betreiberseite hatte drei Minuten vorher „Wird
angeboten: **ja**" gezeigt, und beide lesen dieselbe Methode. Kurz darauf gab
das Panel auf **jeder** Seite einen 500er.

Gemessen wurde dann Folgendes:

```
$ ss -tlnp | grep 3306
LISTEN 0  80  [::]:3306  [::]:*  users:(("mariadbd",pid=35737,fd=23))

SQLSTATE[HY000] [2002] Connection refused
  (Connection: mariadb, Host: 127.0.0.1, Port: 3306, Database: srvpanel)
```

**MariaDB bindet bei `bind-address = ::` ausschliesslich IPv6.** Es gibt keinen
Eintrag für `0.0.0.0`, und eine Verbindung auf `127.0.0.1:3306` wird abgewiesen.
Das Panel verbindet sich über `127.0.0.1`. Gemessen auf MariaDB
10.11.14-0ubuntu0.24.04.1; der Doppelstapel liegt auf `*`.

Der Server war die ganze Zeit gesund: `systemctl is-active mariadb` sagte
`active`, das Journal meldete `ready for connections`, und über den Unix-Socket
antwortete er. Nur über TCP kam niemand mehr herein.

---

## 2. Die vier Fehler

Sie hängen aneinander, und **jeder einzelne von ihnen hätte den Ausfall
verhindert oder wenigstens erklärt.**

### 2.1 Der Wert `::` galt als Doppelstapel — und war nie gemessen

In `agent/src/Ops/DbRemoteAccess.php` stand wörtlich: *„`::` deckt auf einem
Doppelstapel beides — und scheitert auf einem Rechner, auf dem IPv6 abgeschaltet
ist, beim Start."* Beide Hälften des Satzes beschreiben eine Sorge, die es nicht
gibt, und keine die Lage, die eintritt. Aus derselben Annahme folgte eine
Umrechnung im Kommando (`::` → `*` für PostgreSQL), die nichts umrechnete,
sondern den Unterschied verdeckte.

> **Ein Wert, den nur die Dokumentation kennt, ist eine Vermutung mit Fussnote.**

Das ist die Lehre aus P5b eine Fläche weiter: *Wissen aus zweiter Hand sieht aus
wie Wissen.*

### 2.2 Die Gegenprobe lief über einen anderen Weg als der Betrieb

Die Operation prüft nach dem Neustart, worauf der Server horcht — und fragt ihn
dafür über den **Unix-Socket** (`agent/src/Db/Session.php`, `--protocol=socket`,
damit der Agent kein Passwort braucht). Über den Socket bleibt ein Server
erreichbar, der auf TCP niemanden mehr hereinlässt. Die Antwort lautete
`remote: true`, und sie war richtig: Er horchte. Nur eben nicht dort, wo das
Panel anklopft.

> **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den falschen
> Weg.**

Der vorhandene Rückweg greift nur, wenn `systemctl restart` **scheitert**. Hier
gelang er. Für die Operation war das ein vollständiger Erfolg.

### 2.3 Der Rückweg brauchte, was der Hinweg weggenommen hatte

`srvpanel db --remote=off` — der eine Griff gegen genau diesen Zustand — starb,
bevor er den Agenten erreichte:

```
SQLSTATE[HY000] [2002] Connection refused … select count(*) … from `db_users`
```

Die Zahl der ausgesperrten Zugänge stand unbedingt am Anfang, für **beide**
Richtungen, obwohl nur das Ausschalten sie braucht. Der Betreiber musste die
Include-Datei von Hand löschen und MariaDB von Hand neu starten.

> **Ein Rückweg, der den Bestand braucht, ist keiner für den Fall, dass der
> Bestand weg ist.**

### 2.4 „Nicht nachgesehen" kam als „nein" zurück

`Settings::read()` fing jeden `Throwable` und gab eine leere Ablage zurück —
fehlende Tabelle, gewechselter `APP_KEY` und **nicht erreichbarer
Datenbankserver** endeten in demselben Wert. Daraus wurde die Meldung, der
Betreiber biete PostgreSQL nicht an.

> **Ein Wert, der „nein" und „ich weiss es nicht" nicht auseinanderhält,
> behauptet das eine, wenn das andere gilt.**

**Das Teuerste daran war nicht die falsche Zeile, sondern ihre Plausibilität.**
Sie nannte sogar den Befehl zur Abhilfe (`srvpanel db --postgresql=on`). Wer ihr
folgt, schaltet einen Schalter ein, der längst an ist — und sucht die eigentliche
Ursache nicht. Sie hat die Diagnose um Minuten verzögert, in denen das Panel
unten war.

---

## 3. Was geändert wurde

| Fehler | Änderung | Wächter |
|---|---|---|
| 2.1 | `*` steht in `DbRemoteAccess::ADDRESSES`, `::` heisst dort jetzt „nur IPv6"; beide Systeme nehmen dieselben drei Werte, das Kommando übersetzt nicht mehr | `RemoteAccessTest::test_the_dual_stack_address_is_the_star`, `…::test_both_systems_take_the_same_addresses` |
| 2.2 | Nach dem Umschalten fragt **das Panel selbst**, ob es seine Datenbank noch erreicht (fünf Anläufe, dann Rücknahme über den Agenten) | `RemoteAccessTest::test_the_switch_checks_that_the_panel_still_gets_in` |
| 2.3 | Der Bestand wird nur beim Ausschalten gezählt, und ein Fehlschlag dabei hält den Rückweg nicht auf | `RemoteAccessTest::test_the_way_back_does_not_need_the_inventory` |
| 2.4 | `Settings::probe()` gibt `null` für „konnte nicht nachsehen"; `postgresOffered()` ist dreiwertig, `postgres()` bleibt für die Kundenfläche zweiwertig | `SettingsProbeTest` |

Die Positivliste steht ab jetzt **einmal** — im Agenten. Das Kommando liest sie
von dort, statt sie ein zweites Mal aufzuschreiben; als `*` dazukam, wäre genau
das die Stelle gewesen, die zurückbleibt.

Alle sechs Eingriffe stehen in `tests/waechter-brechen.sh` und greifen.

---

## 4. Was das für `docs/43` heisst

Punkt 3 des Abnahmelaufs schrieb `--bind=::` vor. **Er hat damit jeden, der ihn
fährt, in diesen Ausfall geführt** — und zwar auf einem Server, auf dem er ein
laufendes Panel trifft.

Geändert ist:

- Punkt 3 fährt `--bind=*`.
- Punkt 3 bekommt eine Zeile, die nach dem Umschalten **das Panel** aufruft und
  nicht nur den Datenbankserver fragt.
- Die erwartete Ausgabe nennt `*`.

> **Ein Abnahmelauf, der eine ungeprüfte Annahme als Anweisung führt, prüft sie
> nicht — er führt sie aus.**

---

## 5. Was offen bleibt

- **Der Paketfilter.** Unverändert P9; `bind-address` ist keine Firewall.
- **`--bind=::` ist weiter erlaubt**, und das ist Absicht: Auf einem Rechner, der
  nur IPv6 hat, ist es der richtige Wert. Was ihn gefährlich machte, war nicht
  der Wert, sondern die fehlende Frage danach — und die steht jetzt.
- **Die anderen Schalter mit Neustart sind nicht durchgesehen.** Was hier für
  `--remote` gilt — die Gegenprobe muss den Weg nehmen, den der Betrieb nimmt —,
  gilt für jeden Eingriff, der einen Dienst neu startet, den das Panel selbst
  braucht. Ein Durchgang durch die übrigen steht aus.
