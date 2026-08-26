# Protokoll — Abnahmelauf A1 auf `cloudsrv24`

Der Lauf ist `docs/85`, die Vorschrift dazu `docs/81 §4`. Gefahren am
26. August 2026 gegen **`0.7.2-rc.1`**.

**Dieses Protokoll entsteht während des Laufs.** Solange keine Messung darin
steht, ist ein Protokoll eine Gliederung.

**Der Lauf ist in zwei Sitzungen geteilt, und der Grund steht in Punkt 0b.**

---

## 1. Die Fassung, gegen die gemessen wird

    srvpanel version                          0.7.2-rc.1
    dpkg-query -W -f='${Version}' srvpanel    0.7.2~rc.1
    systemctl is-active agentd worker web     active · active · active

Die Tilde ist Debians Konvention für eine Vorabfassung — `0.7.2~rc.1` sortiert
**vor** `0.7.2`. nfpm setzt sie selbst; so steht es seit `0.2.0~rc.4`.

Der dritte Griff ist keine Formsache: Ohne laufenden Agenten stammt jede Zahl
dieses Laufs aus einer Fehlermeldung statt aus einer Messung.

---

## 2. Punkt 0b hat den Lauf geteilt

    apt-get -s dist-upgrade | grep -c '^Inst '     0

**Der Server hat nichts Aktualisierbares.** Nach `docs/85` Punkt 0b wird der
Lauf damit abgebrochen, und der Grund ist gut: Die Punkte 1, 2, 5 und 8b würden
0 gegen 0 vergleichen und dabei aussehen wie ein kaputtes Panel.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

**Entschieden wurde eine Teilung statt einer Verschiebung.** Acht Punkte hängen
nicht an aktualisierbaren Paketen und sind vollwertig messbar: 3, 4, 6, 7, 8a,
9, 10, 12. Die übrigen warten auf `0.7.2-rc.2` — und **dann ist Punkt 5 die
bessere Messung**, nicht die schlechtere: Ein Upgrade, das `srvpanel` selbst
enthält, ist genau der Fall des Kriteriums; ein beliebiger `dist-upgrade` über
fremde Pakete wäre der schwächere Prüfkörper gewesen.

**Der Lauf ist nach der ersten Sitzung nicht abgeschlossen.** A1 ist erst
abgenommen, wenn Punkt 5 gemessen ist.

---

## 3. Ein Prüfkörper, den die Vorschrift nicht hatte

`apt-get update` liefert auf diesem Server dauerhaft eine `W:`-Zeile:

    W: https://ppa.launchpadcontent.net/ondrej/php/ubuntu/dists/noble/InRelease:
       Signature by key 14AA…6C uses weak algorithm (rsa1024)

**Die Quelle ist gesund, und die Zeile ist trotzdem eine `W:`-Zeile.**
`Apt::readFailures()` verlangt `^[WE]: Failed to fetch ` und zählt sie deshalb
zu Recht nicht mit. Damit steht neben dem Prüfkörper von Punkt 3 ein zweiter,
der **nicht** anschlagen darf — die Gegenprobe, die beim Ausschreiben fehlte.

> **Eine Liste, die auch das Gewollte nennt, ist ein Hinweis und kein Urteil.**

Erwartet ist deshalb bei Punkt 3: **genau eine** gemeldete Quelle, nicht zwei.

---

## 4. Die Punkte

<!-- Wird während des Laufs gefüllt. -->
