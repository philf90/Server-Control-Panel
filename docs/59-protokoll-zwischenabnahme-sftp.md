# 59 — Protokoll der Zwischenabnahme des SFTP-Zugangs

Der Lauf nach `docs/58`, auf `cloudsrv24`, gegen `v0.6.0-rc.10`.

**Dieses Dokument entsteht während des Laufs.** Was hier steht, ist gemessen;
was noch nicht gemessen ist, steht als offen da und nicht als erwartet. Ein
Protokoll, das im Voraus geschrieben wird, hält fest, was jemand erwartet hat.

| | |
|---|---|
| Datum | 16. August 2026 |
| Fassung | `v0.6.0-rc.10` |
| Stand auf `main` | `7ff3096` (PR #138) |
| Gefahren von | Betreiber auf `cloudsrv24`; Auswertung hier |

---

## 0. Der zweite Weg hinein

| | Zustand |
|---|---|
| 0a Konsole beim Anbieter offen **und benutzt** | **erledigt** |
| 0b zweite root-Sitzung offen | **erledigt** |
| 0c `sshd_config` gesichert, Prüfsumme notiert | **erledigt** |

**0a:** netcup-Konsole („Bildschirm"), `cloudsrv24 tty1`, als root angemeldet,
`asd` getippt, `-bash: asd: command not found` gesehen. Ein lokaler Bildschirm
und keine SSH-Sitzung — also ein Weg hinein, der nicht durch die Tür führt, die
dieser Lauf zusperren kann. Ubuntu 24.04.4 LTS, Kernel 6.8.0-137-generic,
Konsolenzeit Mo 17. Aug 09:00 CEST 2026.

**0b:** Eine zweite root-Sitzung über SSH ist offen und bleibt es (Angabe des
Betreibers).

**0c, gemessen am 16. August 2026 auf `cloudsrv24`:**

```
sha256sum /root/sshd_config.vor-p6 /etc/ssh/sshd_config
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /root/sshd_config.vor-p6
2b5a070ed8513f847086e21be1eb50fa1fca79c65782b2b85ef2b0dbcdf56852  /etc/ssh/sshd_config
```

Beide gleich — die Sicherung ist treu, und der Wert ist der Bezug für Punkt 3
und Punkt 8. **Er ist nach dem Einspielen des Pakets genommen und nicht davor**
(siehe Abweichung unten); für die Frage, die Punkt 3 stellt — schreibt das
blosse Ansehen der Seite in die Datei? — ist genau das der richtige Zeitpunkt.

**0a und 0b sind nicht belegt.** Die Aufnahme zeigt eine Sitzung mit einer
root-Eingabeaufforderung; ob es die Konsole des Anbieters oder eine
SSH-Sitzung ist, geht daraus nicht hervor, und ob eine **zweite** Sitzung offen
bleibt, ebenfalls nicht. Nachgefragt statt angenommen — der ganze Punkt 0
besteht daraus, das nicht zu tun.

### Ein Befund aus Punkt 0, den niemand gesucht hat

Die Konsolenaufnahme zeigt im Kundenbereich den Hinweis **„Neustart
erforderlich"**. Das ist für diesen Lauf keine Nebensache:

| | |
|---|---|
| kaputte Datei + **Reload** | der sshd terminiert (`docs/57 §5`) |
| kaputte Datei + **Neustart** | der Dienst kommt gar nicht hoch (`docs/38`, M17) |

Punkt 8 stellt absichtlich für einen Augenblick einen Zustand her, in dem
`sshd_config` eine unsinnige Zeile trägt. Ein Neustart in genau diesem Fenster
— ausgelöst von einem Paketlauf, einem Wartungsfenster des Anbieters oder
versehentlich — macht aus einem geprüften Zustand einen Ausschluss.

> **Ein ausstehender Neustart ist eine geladene Waffe, die auf den Zeitpunkt
> wartet, an dem jemand anders abdrückt.**

**Auflage für den Rest des Laufs:** kein Neustart, und vor jedem Neustart, der
sich nicht vermeiden lässt, muss `sshd -t` still sein.

---

## Abweichung vom geschriebenen Lauf

`docs/58` Punkt 1 heisst „vor dem Update"; das Paket `v0.6.0-rc.10` war beim
Beginn dieses Laufs bereits eingespielt. Für die Messrunde ist das folgenlos —
sie misst OpenSSH auf dieser Maschine und nicht das Panel. Für 0c heisst es:
Der Bezugswert ist „nach dem Einspielen, **vor der ersten Benutzung**".

Festgehalten statt geradegebogen.

## 1. Die Messrunde vor dem Update

**Offen.** Erwartet: 42/0, und die echte Kette `/` → `/var/www/vhosts` als
„taugt".

## 2. Fassungen

**Offen.**

---

## Befunde

Noch keine.
