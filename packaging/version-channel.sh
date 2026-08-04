#!/bin/sh
# Welche Fassung darf erscheinen, und in welchem Kanal?
#
# Aufruf:  packaging/version-channel.sh 0.3.0-rc.1   ->  gibt "beta" aus
# Fehlschlag: Meldung auf stderr, Rückgabewert 1.
#
# **Warum es diesen Wächter gibt.** Der Freigabelauf leitete den Kanal aus
# einem Bindestrich in der Fassung ab: mit Zusatz nach `beta`, ohne nach
# `stable`. Das ist als Regel richtig und als Wächter nichts wert, denn ein
# Tag ohne Zusatz — `v0.3.0` statt `v0.3.0-rc.1` — bricht nichts ab. Der Lauf
# wird grün, das Paket wird gebaut, signiert und veröffentlicht. Nur eben im
# falschen Kanal, und beide Hälften des Fehlers sind still:
#
#   - Die Server im Beta-Kanal sehen das Paket nie. `srvpanel update` meldet
#     „nichts Neues" und bleibt auf der alten Fassung. Niemandem fällt es auf,
#     weil nichts einen Fehler meldet.
#   - Die Server im Stable-Kanal bekommen ein Panel angeboten, dessen
#     Abnahmelauf nie gelaufen ist.
#
# Solange die Entwicklung läuft, erscheint jedes Paket als `-rc.N` im
# Beta-Kanal. Das ist eine Phasenregel und kein Naturgesetz — deshalb hat sie
# einen Ausgang, siehe unten.
#
# **Auch die Form des Tags wird geprüft.** Aus `v.0.3.0-rc.1` würde
# `${GITHUB_REF_NAME#v}` die Fassung `.0.3.0-rc.1` machen; dpkg verlangt eine
# Ziffer am Anfang, und der Lauf bräche beim Paketbau ab — aber erst, nachdem
# der Tag existiert, und einen Tag nimmt man ungern zurück. Hier fällt es im
# ersten Schritt auf, vor dem Bauen und vor jeder Signatur.
#
# **Der Ausgang aus der Beta-Phase** ist `packaging/stable-release`. Dort steht
# die eine Fassung, die stabil erscheinen darf, und sonst nichts. Ohne diesen
# Weg hiesse die erste stabile Freigabe, dass jemand den Wächter entfernt — und
# ein entfernter Wächter nimmt seinen Test mit. So ist das Verlassen der
# Beta-Phase ein Commit, den man liest, und keine Löschung, die niemandem
# auffällt.
set -eu

VERSION="${1:-}"

# Für den Test überschreibbar: Er muss beide Zweige durchlaufen können, ohne
# die echte Marke anzufassen. Dieselbe Machart wie bei den Verzeichnissen von
# `subscription.remove`, die der Aufräumtest in einen Sandkasten umlenkt.
MARKER="${SRVPANEL_STABLE_MARKER:-$(dirname "$0")/stable-release}"

fail() {
    echo "$1" >&2
    exit 1
}

if [ -z "$VERSION" ]; then
    fail "Ohne Fassung: version-channel.sh <fassung ohne führendes v>"
fi

# Die Beta-Fassung. `rc` und eine Zahl — nicht irgendein Zusatz: „0.3.0-beta2"
# und „0.3.0-RC1" landeten zwar auch im Beta-Kanal, aber die Vorgabe lautet
# rc.N, und zwei Schreibweisen derselben Sache sortieren irgendwann falsch.
if echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+-rc\.[1-9][0-9]*$'; then
    echo "beta"
    exit 0
fi

if ! echo "$VERSION" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+$'; then
    fail "Die Fassung „${VERSION}\" hat nicht die Form X.Y.Z oder X.Y.Z-rc.N. Der Tag heisst v<fassung>, ohne Punkt hinter dem v."
fi

# Ab hier: eine Fassung ohne Zusatz. Sie erscheint nur, wenn sie in der Marke
# namentlich steht — nicht „irgendeine stabile Fassung ist erlaubt", sondern
# genau diese. Sonst wäre die Marke einmal gesetzt und danach ein Freibrief.
STABLE=""

if [ -f "$MARKER" ]; then
    STABLE="$(sed -e 's/#.*//' -e 's/[[:space:]]//g' "$MARKER" | grep -v '^$' | head -n 1 || true)"
fi

if [ -z "$STABLE" ]; then
    fail "Die Fassung „${VERSION}\" hat keinen Zusatz und erschiene damit im Kanal stable. Solange die Entwicklung läuft, wird als ${VERSION}-rc.N freigegeben. Soll sie wirklich stabil erscheinen, gehört sie nach packaging/stable-release."
fi

if [ "$STABLE" != "$VERSION" ]; then
    fail "packaging/stable-release nennt „${STABLE}\", freigegeben wird „${VERSION}\". Stabil erscheint nur die Fassung, die dort namentlich steht."
fi

echo "stable"
