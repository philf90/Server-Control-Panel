#!/usr/bin/env bash
# Den Tag für 0.6.1 setzen — NACH dem Merge des Pull Requests.
#
# Warum ein Skript und kein Satz in einem Kommentar: Der Tag löst die
# Release-Pipeline aus (.github/workflows/release.yml, `on: push: tags: v*`).
# Sie baut, signiert und veröffentlicht — und weil "v0.6.1" keinen Bindestrich
# trägt, landet sie im Kanal `stable` und nicht in `beta`. Ein Tag auf dem
# falschen Commit lässt sich zwar verschieben, aber die Veröffentlichung
# darunter nicht mehr zurücknehmen; `internal/version.Version` kommt aus
# `git describe`, also trägt jedes ausgelieferte Binary danach diese Zahl.
#
# Deshalb prüft dieses Skript zuerst und fragt dann. Es setzt nichts von selbst.
#
#   bash packaging/tag-0.6.1.sh
#
set -euo pipefail

fassung="v0.6.1"
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
# 0.6.1 ist eine Patch-Fassung auf 0.6.0. Fehlt deren Tag, springt die
# Tag-Geschichte über eine veröffentlichte Fassung hinweg, und jedes Binary
# dazwischen meldet sich unter der falschen Zahl. Das ist bei 0.5.1 schon
# einmal passiert und deshalb hier eine Sperre und kein Hinweis.
# ---------------------------------------------------------------------------
if ! git rev-parse "v0.6.0" >/dev/null 2>&1; then
	echo "ABBRUCH: v0.6.0 ist nicht getaggt. 0.6.1 ohne ihren Vorgänger zu" >&2
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
git tag -a "$fassung" "$ziel" -m "Asylum $fassung — Sprache der Oberfläche

Die sichtbaren Texte sind auf technische Begriffe gezogen: installieren statt
einspielen, Version statt Fassung, Rollback statt Rückweg, Login-Shell statt
Anmeldeschale, Host-Pfad statt Wirtspfad. Wo im deutschen Fachgebrauch das
englische Wort gilt, benutzt das Panel es. Die Vorgabe steht in
docs/19-sprache-der-oberflaeche.md, geprüft von internal/ui/wortwahl_test.go.

Behoben: Die Vorgangsanzeige der nginx-Installation blieb leer, weil
webserver-install in der Allowlist der Vorgangsarten fehlte.

Der Vorbehalt der 0.6.0 gilt unverändert: Das Modul Webserver ist nicht gegen
ein laufendes nginx und nicht gegen eine echte Zertifizierungsstelle erprobt —
siehe docs/18-webserver.md §11a und §12."

git push origin "$fassung"
echo "Gesetzt. Die Pipeline läuft: https://github.com/philf90/Server-Control-Panel/actions"
