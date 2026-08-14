# 53 — Das Protokoll der Zwischenabnahme von P6

**Gefahren auf `cloudsrv24` gegen `v0.6.0-rc.1`**, nach dem Lauf aus `docs/52`.
Dieses Dokument entsteht **während** des Laufs und nicht danach.

| Sache | Wert |
|---|---|
| Fassung | `v0.6.0-rc.1` |
| Server | `cloudsrv24` |
| Betriebssystem | Linux 6.8.0-137-generic (Ubuntu 24.04) |
| PHP | 8.4.24, SAPI `cli` |

---

## Punkt 1 — die Grenze, gemessen statt gelesen

**Rückgabewert `0`: Die Grenze hält, gemessen und gegengeprobt.**

| # | Abschnitt | Ergebnis |
|---|---|---|
| 1 | alle vierzehn Funktionen vorhanden | **ja** |
| 1 | läuft als root | ja |
| 2 | Wurzel `root:root 0755`, Inhalt dem Abo | ja |
| 3 | `uid` ist nicht 0 | ja — `uid=1005` |
| 3 | keine Gruppe 0 | ja — `gruppen=1005` |
| 3 | eine gültige Datei wird gelesen | ja — „innen" |
| 4 | Symlink auf `/etc/passwd` | hält |
| 4 | Symlink auf ein fremdes Verzeichnis | hält |
| 4 | `..`-Ausbruch | hält |
| 4 | absoluter Pfad | hält |
| 4 | `conf/` (`root:root 0640`) lesen | hält |
| 5 | Tausch während des Zugriffs | hält — **scharf 0, stumpf 2175 von 30 000** |
| 6 | Rückbau gegen den Tausch | hält — **scharf 0 von 60, stumpf 1 nach 4 Durchgängen** |
| 7 | ausserhalb der Vhost-Wurzel abgewiesen | ja |
| 7 | die Vhost-Wurzel selbst abgewiesen | ja |
| 7 | ein Systembenutzer, den es nicht gibt | abgewiesen |

### Der Befund, der in keiner Erwartung stand

**Das Zeitfenster ist auf dem echten Server fast dreimal so weit wie im
Container.** Gemessen mit demselben Skript, demselben Angreifer und derselben
Rundenzahl:

| Maschine | stumpf getroffen | Anteil |
|---|---|---|
| Entwicklungscontainer (Kernel 6.18) | 759 von 30 000 | 2,5 % |
| **`cloudsrv24` (Kernel 6.8)** | **2175 von 30 000** | **7,25 %** |

Dasselbe beim Rückbau: Im Container brauchte die Gegenprobe zwischen 5 und 68
Durchgängen, bis sie traf — hier **vier**.

Die Richtung war erwartet, die Grössenordnung nicht. `docs/52 §4` hatte
vorsorglich den umgekehrten Fall beschrieben (die Gegenprobe trifft dort
*seltener*, weil die Maschine langsamer ist) und dafür einen Ausweg vorgesehen.
Gebraucht wurde er nicht — im Gegenteil.

> **Eine Messung, die man vom Entwicklungsrechner auf den Zielserver überträgt,
> überträgt auch ihre Grössenordnung — und die stimmt nicht.** Der Fehler wäre
> hier zur harmlosen Seite gegangen; er hätte genauso gut andersherum liegen
> können.

Praktisch heisst das: Die Prüfung, die bis P6 im Rückbau stand, war auf dem
Produktivsystem **durchlässiger** als die 31 % aus `docs/50 §3` vermuten liessen
— nicht weniger.

### Was dieser Punkt nicht sagt

`cloudsrv24` ist **Ubuntu 24.04 mit PHP 8.4** — dieselbe Plattform wie der
Entwicklungscontainer. Die vierzehn Funktionen sind damit auf **einer** der vier
Zielplattformen belegt; Debian 12 (PHP 8.2), Debian 13 und Ubuntu 22.04
(PHP 8.1) bleiben ungemessen.

Das war der Hauptgrund, diese Zwischenabnahme vorzuziehen (`docs/52 §1`), und er
ist damit nur zu einem Viertel erledigt. Die vier „Installation auf …"-Läufe der
CI fahren auf allen vier Plattformen — dort gehört diese Prüfung hin.

---

*Die Punkte 2 bis 8 folgen, während sie gefahren werden.*
