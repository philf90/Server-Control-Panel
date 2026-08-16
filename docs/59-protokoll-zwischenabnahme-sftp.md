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

**Offen.** Der Lauf fängt erst an, wenn 0a gesehen ist.

| | Zustand |
|---|---|
| 0a Konsole beim Anbieter offen **und benutzt** | offen |
| 0b zweite root-Sitzung offen | offen |
| 0c `sshd_config` gesichert, Prüfsumme notiert | offen |

## 1. Die Messrunde vor dem Update

**Offen.** Erwartet: 42/0, und die echte Kette `/` → `/var/www/vhosts` als
„taugt".

## 2. Fassungen

**Offen.**

---

## Befunde

Noch keine.
