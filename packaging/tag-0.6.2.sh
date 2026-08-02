#!/usr/bin/env bash
# Den Tag für 0.6.2 setzen — NACH dem Merge des Pull Requests.
#
# Warum ein Skript und kein Satz in einem Kommentar: Der Tag löst die
# Release-Pipeline aus (.github/workflows/release.yml, `on: push: tags: v*`).
# Sie baut, signiert und veröffentlicht — und weil "v0.6.2" keinen Bindestrich
# trägt, landet sie im Kanal `stable` und nicht in `beta`. Ein Tag auf dem
# falschen Commit lässt sich zwar verschieben, aber die Veröffentlichung
# darunter nicht mehr zurücknehmen; `internal/version.Version` kommt aus
# `git describe`, also trägt jedes ausgelieferte Binary danach diese Zahl.
#
# Deshalb prüft dieses Skript zuerst und fragt dann. Es setzt nichts von selbst.
#
#   bash packaging/tag-0.6.2.sh
#
set -euo pipefail

fassung="v0.6.2"
zweig="claude/repo-planning-future-releases-98y9f3"

# ---------------------------------------------------------------------------
# 1. Der Tag gehört auf den MERGE-Commit, nicht auf den Zweig.
#
# Ein Tag auf dem Zweigkopf zeigt auf einen Commit, den main so nie enthält:
# Nach einem Merge ist der Zweigkopf ein Elternteil, nicht die Spitze. `git
# describe` auf main fände ihn zwar noch, aber die Auslieferung käme von einem
# Stand ohne den Merge-Commit — und wer das später nachvollziehen will, findet
# zwei verschiedene Bäume unter derselben Zahl.
# ---------------------------------------------------------------------------
git fetch origin main --tags

if ! git merge-base --is-ancestor "origin/$zweig" origin/main 2>/dev/null; then
	echo "ABBRUCH: $zweig ist noch nicht in main. Erst den Pull Request mergen." >&2
	exit 1
fi

ziel="$(git rev-parse origin/main)"

if git rev-parse "$fassung" >/dev/null 2>&1; then
	echo "ABBRUCH: $fassung gibt es schon." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 2. Der Vorgänger muss stehen.
#
# 0.6.2 ist eine Patch-Fassung auf 0.6.1. Fehlt deren Tag, springt die
# Tag-Geschichte über eine veröffentlichte Fassung hinweg, und jedes Binary
# dazwischen meldet sich unter der falschen Zahl. Das ist bei 0.5.1 schon
# einmal passiert und deshalb hier eine Sperre und kein Hinweis.
# ---------------------------------------------------------------------------
if ! git rev-parse "v0.6.1" >/dev/null 2>&1; then
	echo "ABBRUCH: v0.6.1 ist nicht getaggt. 0.6.2 ohne ihre Vorgängerin zu" >&2
	echo "veröffentlichen macht die Tag-Geschichte unlesbar." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 3. Zeigen, was passieren würde, und fragen.
# ---------------------------------------------------------------------------
echo "Tag:    $fassung"
echo "Commit: $ziel"
git log --oneline -1 "$ziel"
echo
echo "Der Push löst die Release-Pipeline aus: bauen, signieren, veröffentlichen."
echo "Kanal: stable (kein Bindestrich in der Fassung)."
read -r -p "Tag setzen und pushen? [tippen Sie $fassung] " antwort
if [ "$antwort" != "$fassung" ]; then
	echo "Abgebrochen." >&2
	exit 1
fi

# Ein ANNOTIERTER Tag und kein leichter: Er trägt Datum, Urheber und eine
# Meldung, und `git describe` bevorzugt ihn. Ein leichter Tag ist ein Zeiger
# ohne Herkunft.
git tag -a "$fassung" "$ziel" -m "Asylum $fassung — Paketinstallation wieder möglich

Die mitgelieferte systemd-Unit trug ProtectSystem=true und stellte /usr damit
auch für apt schreibgeschützt, das als Kindprozess des Dienstes läuft. Jede
Paketinstallation und jedes Paket-Update über das Panel scheiterte beim
Auspacken. Beide Units stehen jetzt auf ProtectSystem=no mit
ReadOnlyPaths=-/boot -/efi; MemoryMax geht von 256M auf 768M, weil apt und
dpkg in der Kontrollgruppe der Unit laufen.

BESTEHENDE INSTALLATIONEN: Das Selbstupdate tauscht das Programm, nie die
Unit. Wer über den curl-Installer aufgesetzt hat, braucht den Handgriff aus
UPGRADING.md. Das Panel erkennt den Fall ab dieser Fassung an der apt-Ausgabe
und nennt Ursache und Abhilfe, statt nur den dpkg-Auszug zu zeigen."

git push origin "$fassung"
echo "Gesetzt. Die Pipeline läuft: https://github.com/philf90/Server-Control-Panel/actions"
