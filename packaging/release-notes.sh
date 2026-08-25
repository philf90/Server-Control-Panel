#!/bin/sh
# Was steht in der Freigabenotiz — und steht überhaupt etwas darin?
#
# Aufruf:  packaging/release-notes.sh 0.7.1-rc.3 < botschaft
#          gibt die Botschaft unverändert wieder aus
# Fehlschlag: Meldung auf stderr, Rückgabewert 1.
#
# **Warum es diesen Wächter gibt.** Der Freigabelauf setzte als Notiz die feste
# Zeile „Siehe CHANGELOG.md." — und der CHANGELOG führt seit P0 nur den
# Abschnitt [Unbereinigt], also keinen zu irgendeiner Fassung. Damit stand an
# keiner der beiden Stellen, die ein Betreiber vor dem Update liest, was sich
# ändert.
#
# > **Eine Freigabenotiz, die auf ein Dokument verweist, in dem die Fassung
# > nicht vorkommt, verweist ins Leere.**
#
# Aufgefallen ist es an 0.7.1-rc.3, und zwar an einer Fassung, bei der es
# zählt: Ihre Kopfänderung beendet offene Sitzungen gesperrter Konten. Das
# wirkt still, und wer es nicht vorher liest, erfährt es aus der Wirkung. Bei
# einer Freigabe ohne Verhaltensänderung wäre dieselbe Lücke nie aufgefallen.
#
# Die Notiz ist deshalb die Botschaft des Tags. Sie steht dort schon — jede
# Freigabe ist ein annotierter Tag, und die Botschaft wird beim Setzen
# geschrieben, von dem, der weiss, was drin ist.
#
# **Was hier ausdrücklich NICHT geprüft wird, und warum.** Der erste Entwurf
# verlangte einen Rumpf unter der Betreffzeile und ein „SrvPanel <fassung>" als
# Betreff. Nachgemessen an den letzten vierzehn Tags hätte das erste neun davon
# abgewiesen und das zweite zwölf: Bis v0.7.0-rc.8 stand die ganze Notiz in
# einer einzigen Betreffzeile, die längste 1264 Zeichen lang, und v0.7.0-rc.9
# bis rc.11 tragen als Betreff nur die nackte Fassungsnummer.
#
# > **Ein Kriterium, das der Prüfling nicht erfüllen kann, prüft den
# > Verfasser.**
#
# Geprüft wird deshalb die Botschaft als Ganzes und nicht ihre Zeilenaufteilung.
# Die eine Frage, die sich stellen lässt, ohne eine Schreibweise vorzuschreiben:
# Sagt sie etwas anderes als die Fassungsnummer, die im Titel des Releases
# ohnehin schon steht?
set -eu

VERSION="${1:-}"

fail() {
    echo "$1" >&2
    exit 1
}

if [ -z "$VERSION" ]; then
    fail "Ohne Fassung: release-notes.sh <fassung ohne führendes v> < botschaft"
fi

NOTES="$(cat)"

# Entschieden wird am Text ohne Leerraum; ausgegeben wird danach das Original.
# Ein Tag, dessen Botschaft aus einem Zeilenumbruch besteht, ist derselbe Fall
# wie einer ohne Botschaft — nur sieht er in `git tag -l` anders aus.
BARE="$(printf '%s' "$NOTES" | tr -d '[:space:]')"

if [ -z "$BARE" ]; then
    fail "Der Tag v${VERSION} trägt keine Botschaft, und damit hätte die Freigabe keine Notiz. Gesetzt wird sie mit „git tag -a v${VERSION}\"; ein leichtgewichtiger Tag reicht nicht."
fi

if [ "$BARE" = "$VERSION" ] || [ "$BARE" = "v${VERSION}" ]; then
    fail "Die Botschaft des Tags v${VERSION} nennt nur die Fassung. Die steht im Titel des Releases schon — die Notiz soll sagen, was sich ändert."
fi

printf '%s\n' "$NOTES"
