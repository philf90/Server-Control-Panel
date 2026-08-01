#!/usr/bin/env bash
# Den Tag für 0.6.0 setzen — NACH dem Merge von PR #13.
#
# Warum ein Skript und kein Satz in einem Kommentar: Der Tag löst die
# Release-Pipeline aus (.github/workflows/release.yml, `on: push: tags: v*`).
# Sie baut, signiert und veröffentlicht. Ein Tag auf dem falschen Commit lässt
# sich zwar verschieben, aber die Veröffentlichung darunter nicht mehr
# zurücknehmen — und `internal/version.Version` kommt aus `git describe`, also
# trägt jedes ausgelieferte Binary danach diese Zahl.
#
# Deshalb prüft dieses Skript zuerst und fragt dann. Es setzt nichts von selbst.
#
#   bash packaging/tag-0.6.0.sh
#
set -euo pipefail

fassung="v0.6.0"

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

if ! git merge-base --is-ancestor origin/claude/repo-planning-future-releases-98y9f3 origin/main 2>/dev/null; then
	echo "ABBRUCH: Der Zweig ist noch nicht in main. Erst PR #13 mergen." >&2
	exit 1
fi

ziel="$(git rev-parse origin/main)"

if git rev-parse "$fassung" >/dev/null 2>&1; then
	echo "ABBRUCH: $fassung gibt es schon." >&2
	exit 1
fi

# ---------------------------------------------------------------------------
# 2. Die Lücke bei 0.5.1 benennen, statt sie zu überspringen.
#
# Der Release-Commit der 0.5.1 liegt auf main (641a382), der Tag wurde nie
# gesetzt. Ohne ihn springt die Tag-Geschichte von 0.5.0 auf 0.6.0, und jedes
# Binary, das zwischen den beiden gebaut wurde, meldet sich als
# „v0.5.0-N-g<hash>". Das ist keine Panne, die man stillschweigend heilt: Wer
# den Tag jetzt nachträgt, veröffentlicht eine Fassung, die es nie gab.
#
# Zwei vertretbare Wege, und die Entscheidung gehört dem Betreiber:
#   a) 0.5.1 nachtragen (der Tag löst die Pipeline aus und veröffentlicht sie).
#   b) Die Lücke lassen und im CHANGELOG vermerken, dass 0.5.1 nie
#      veröffentlicht wurde.
# ---------------------------------------------------------------------------
if ! git rev-parse "v0.5.1" >/dev/null 2>&1; then
	echo
	echo "HINWEIS: v0.5.1 ist nicht getaggt, obwohl ihr Release-Commit auf main"
	echo "liegt (641a382). Die Tag-Geschichte springt damit von 0.5.0 auf 0.6.0."
	echo "Das ist kein Hindernis für diesen Tag — aber eine Entscheidung, die"
	echo "getroffen und nicht übersehen werden sollte."
	echo
fi

# ---------------------------------------------------------------------------
# 3. Zeigen, was passieren würde, und fragen.
# ---------------------------------------------------------------------------
echo "Tag:    $fassung"
echo "Commit: $ziel"
git log --oneline -1 "$ziel"
echo
echo "Der Push löst die Release-Pipeline aus: bauen, signieren, veröffentlichen."
read -r -p "Tag setzen und pushen? [tippen Sie $fassung] " antwort
if [ "$antwort" != "$fassung" ]; then
	echo "Abgebrochen." >&2
	exit 1
fi

# Ein ANNOTIERTER Tag und kein leichter: Er trägt Datum, Urheber und eine
# Meldung, und `git describe` bevorzugt ihn. Ein leichter Tag ist ein Zeiger
# ohne Herkunft.
git tag -a "$fassung" "$ziel" -m "Asylum $fassung — Webserver & Domains

Sites als Domain → Ziel → TLS mit Site-Prüfer, nginx -t und Probe mit Rückweg.
Ein Zertifikat je Site, Wildcards und sieben DNS-01-Anbieter. Verwaltet wird
nginx; jeder andere Webserver wird erkannt und nicht angefasst.

Nicht gegen ein laufendes nginx und nicht gegen eine echte
Zertifizierungsstelle erprobt — siehe docs/18-webserver.md §11a und §12."

git push origin "$fassung"
echo "Gesetzt. Die Pipeline läuft: https://github.com/philf90/Server-Control-Panel/actions"
