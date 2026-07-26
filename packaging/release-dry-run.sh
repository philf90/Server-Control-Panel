#!/usr/bin/env bash
# Probelauf für den Schritt der Freigabepipeline, der die Landingpage und die
# Update-Metadaten schreibt.
#
# Warum es das gibt: Dieser Schritt lief bisher erstmals dann, wenn ein Tag
# gesetzt war — also genau dann, wenn ein Fehler teuer ist. Zwei Freigaben sind
# daran gescheitert (eine an einer Ersetzung, die nie in der Datei ankam, eine
# an einem grep, das unter "set -e" ohne Treffer abbricht). Beide Fehler wären
# hier in Sekunden aufgefallen.
#
# Das Skript nimmt den Schritt unverändert aus .github/workflows/release.yml,
# baut eine Attrappe der Ablage darum und lässt ihn für drei Fälle laufen:
# Vorabversion ohne Stable, Freigabe, Vorabversion neben bestehendem Stable.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
workflow="$root/.github/workflows/release.yml"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

# Den Schritt aus dem Workflow holen, statt ihn hier zu wiederholen: Eine
# Kopie liefe früher oder später auseinander und prüfte dann sich selbst.
python3 - "$workflow" "$work/step.sh" <<'PY'
import sys, yaml

workflow, out = sys.argv[1], sys.argv[2]
with open(workflow) as fh:
    doc = yaml.safe_load(fh)

steps = [
    s for s in doc["jobs"]["publish"]["steps"]
    if s.get("name", "").startswith("Installer und Update-Metadaten")
]
if len(steps) != 1:
    sys.exit(f"{len(steps)} passende Schritte in {workflow}, erwartet genau einen")

with open(out, "w") as fh:
    fh.write("set -euo pipefail\n")
    fh.write(steps[0]["run"])
PY

mkdir -p "$work/dist" "$work/src"
ln -s "$root/packaging" "$work/src/packaging"

fake_sums() {
  : > "$work/dist/SHA256SUMS"
  for arch in amd64 arm64; do
    printf '%064d  asylumd_%s_linux_%s.tar.gz\n' 1 "$1" "$arch" >> "$work/dist/SHA256SUMS"
  done
}

fehler=0

# lauf <Fassung> <Kanal> <erwartete Suite> [stable-existiert]
lauf() {
  local version="$1" channel="$2" erwartet="$3" mit_stable="${4:-}"

  rm -rf "$work/pages"
  mkdir -p "$work/pages"
  echo "repo.example" > "$work/pages/CNAME"
  if [ -n "$mit_stable" ]; then
    mkdir -p "$work/pages/apt/dists/stable"
    : > "$work/pages/apt/dists/stable/Release"
  fi
  fake_sums "$version"

  if ! ( cd "$work" && VERSION="$version" CHANNEL="$channel" REPO="beispiel/asylum" \
         bash step.sh > "$work/log" 2>&1 ); then
    echo "FEHLER  $version/$channel: der Schritt brach ab"
    sed 's/^/        /' "$work/log"
    fehler=1
    return
  fi

  local suite
  suite="$(awk '/^Suites:/{print $2; exit}' "$work/pages/index.html")"
  if [ "$suite" != "$erwartet" ]; then
    echo "FEHLER  $version/$channel: Landingpage empfiehlt »$suite«, erwartet »$erwartet«"
    fehler=1
    return
  fi

  # Die Metadaten müssen gültiges JSON mit gefüllten Prüfsummen sein — ein
  # leeres Feld fiele sonst erst beim Nutzer als abgelehntes Update auf.
  python3 - "$work/pages/updates/$channel.json" "$version" <<'PY'
import json, sys

path, version = sys.argv[1], sys.argv[2]
with open(path) as fh:
    meta = json.load(fh)

if meta["version"] != version:
    sys.exit(f"{path}: version={meta['version']!r}, erwartet {version!r}")
for name, art in meta["artifacts"].items():
    if not art["sha256"]:
        sys.exit(f"{path}: {name} ohne Prüfsumme")
    if not art["url"].startswith("https://"):
        sys.exit(f"{path}: {name} ohne https-Adresse")
for key in ("checksums_url", "signature_url", "notes_url"):
    if not meta[key].startswith("https://"):
        sys.exit(f"{path}: {key} fehlt oder ist keine https-Adresse")
PY

  echo "ok      $version/$channel → Suites: $suite"
}

lauf "0.1.0-rc.9" beta   beta
lauf "0.1.0"      stable stable
lauf "0.2.0-rc.1" beta   stable ja

if [ "$fehler" -ne 0 ]; then
  echo
  echo "Der Probelauf ist fehlgeschlagen. Ein Tag würde jetzt dasselbe tun."
  exit 1
fi
echo "Probelauf der Freigabepipeline in Ordnung."
