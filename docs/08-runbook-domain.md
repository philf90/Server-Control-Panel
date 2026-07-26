# 08 — Runbook: Domain und GitHub Pages einrichten

Einmalige Einrichtung von `repo.cloudsrv24.de`. Reihenfolge einhalten — Schritt 2
scheitert, wenn Schritt 1 noch nicht propagiert ist.

## Status

| Schritt | Stand |
|---|---|
| CNAME `repo.cloudsrv24.de` → `philf90.github.io.` | ✅ gesetzt, löst auf 185.199.108–111.153 auf |
| Domain-Verifizierung (TXT) | offen |
| Pages-Quelle + Custom Domain im Repository | offen |
| `gh-pages`-Branch | existiert noch nicht |
| Enforce HTTPS | offen |

---

## Schritt 1 — Domain verifizieren (Profil-Ebene)

Das passiert **nicht** in den Repository-Einstellungen, sondern in den
Account-Einstellungen. Das ist die häufigste Verwechslung.

### 1.1 Seite öffnen

<https://github.com/settings/pages>

Manuell: Profilbild oben rechts → **Settings** → linke Seitenleiste, Abschnitt
**Code, planning, and automation** → **Pages**.

Die Seite heißt „GitHub Pages" und enthält nur den Kasten *Verified domains*.

### 1.2 Domain hinzufügen

**Add a domain** → als Domain **`cloudsrv24.de`** eintragen, nicht
`repo.cloudsrv24.de`.

Begründung: GitHub schließt unmittelbare Subdomains in die Verifizierung ein. Aus der
Dokumentation: *„When you verify a domain, any immediate subdomains are also included
in the verification."* Mit einem TXT-Record sind damit `repo.cloudsrv24.de` und jede
weitere Projekt-Subdomain abgedeckt.

### 1.3 TXT-Record anlegen

GitHub zeigt anschließend Name und Wert an. Der Name folgt dem Muster:

```
_github-pages-challenge-philf90.cloudsrv24.de
```

Im DNS-Panel:

| Feld | Wert |
|---|---|
| Typ | `TXT` |
| Name / Host | `_github-pages-challenge-philf90` |
| Wert | der von GitHub angezeigte Code (in Anführungszeichen, falls das Panel sie verlangt) |
| TTL | 3600 |

**Häufiger Fehler:** Die meisten DNS-Panels ergänzen die Zone automatisch. Trägt man
dort den vollen Namen `_github-pages-challenge-philf90.cloudsrv24.de` ein, entsteht
`_github-pages-challenge-philf90.cloudsrv24.de.cloudsrv24.de` und die Verifizierung
schlägt fehl. Im Zweifel den relativen Namen eintragen und danach mit `dig` prüfen,
was tatsächlich ausgeliefert wird.

### 1.4 Propagierung prüfen

```bash
dig _github-pages-challenge-philf90.cloudsrv24.de +short TXT
# erwartet: "<code-von-github>"
```

Ohne `dig` (Windows/PowerShell):

```powershell
Resolve-DnsName -Type TXT _github-pages-challenge-philf90.cloudsrv24.de
```

### 1.5 Verifizieren

Zurück auf <https://github.com/settings/pages> → **Verify**. Der Eintrag wechselt auf
*Verified*.

Der TXT-Record **muss dauerhaft bestehen bleiben**. Wird er gelöscht, verfällt die
Verifizierung.

### Warum das nicht optional ist

Aus der GitHub-Dokumentation: *„Domain takeovers can happen when you delete your
repository, when your billing plan is downgraded, or after any other change which
unlinks the custom domain or disables GitHub Pages while the domain remains configured
for GitHub Pages and is not verified."*

Konkret: Solange die Domain unverifiziert ist und der CNAME auf GitHub zeigt, kann ein
beliebiger anderer GitHub-Account `repo.cloudsrv24.de` als Custom Domain für sein
eigenes Pages-Projekt eintragen, sobald unser Repository die Domain freigibt. Für eine
Domain, die Installations-Skripte mit root-Rechten und Update-Metadaten ausliefert,
wäre das der denkbar schlechteste Ort für eine Übernahme.

---

## Schritt 2 — `gh-pages`-Branch anlegen

Die Pages-Quelle lässt sich erst setzen, wenn der Branch existiert. Als
Waisen-Branch ohne Historie des Hauptprojekts:

```bash
git checkout --orphan gh-pages
git rm -rf .
printf '.nojekyll\n' > /dev/null; : > .nojekyll
cat > index.html <<'HTML'
<!doctype html>
<meta charset="utf-8">
<title>Project Asylum</title>
<h1>Project Asylum</h1>
<pre>curl -fsSL --proto '=https' --tlsv1.2 https://repo.cloudsrv24.de/install.sh -o install.sh
sudo bash install.sh</pre>
HTML
git add .nojekyll index.html
git commit -m "chore(pages): Grundgeruest fuer repo.cloudsrv24.de"
git push -u origin gh-pages
git checkout -
```

`.nojekyll` ist wichtig: ohne die Datei ignoriert Jekyll Verzeichnisse mit führendem
Unterstrich, was später die APT-Metadaten betrifft.

---

## Schritt 3 — Pages im Repository aktivieren

Repository → **Settings** → linke Seitenleiste **Pages**
(<https://github.com/philf90/Server-Control-Panel/settings/pages>):

1. **Build and deployment → Source:** `Deploy from a branch`
2. **Branch:** `gh-pages`, Ordner `/ (root)` → **Save**
3. **Custom domain:** `repo.cloudsrv24.de` → **Save**
   GitHub prüft das DNS und legt im `gh-pages`-Branch automatisch eine Datei `CNAME`
   mit diesem Inhalt an. **Diese Datei darf der spätere Release-Workflow nicht
   überschreiben.**
4. Warten, bis unter *Custom domain* steht: *DNS check successful* und darunter das
   TLS-Zertifikat ausgestellt ist. Das dauert je nach Propagierung bis zu einer Stunde.
5. Erst danach: **Enforce HTTPS** anhaken. Die Checkbox ist vorher ausgegraut.

---

## Schritt 4 — Abnahme

```bash
# Auflösung
dig +short repo.cloudsrv24.de
# erwartet: philf90.github.io. sowie 185.199.108.153 … 185.199.111.153

# Verifizierungs-Record
dig +short TXT _github-pages-challenge-philf90.cloudsrv24.de

# Auslieferung und Zertifikat
curl -sSI https://repo.cloudsrv24.de/ | head -n 5

# HTTP muss auf HTTPS weiterleiten (nach "Enforce HTTPS")
curl -sSI http://repo.cloudsrv24.de/ | grep -i '^location:'
```

### CAA-Records prüfen

Falls für `cloudsrv24.de` CAA-Records gesetzt sind, muss Let's Encrypt erlaubt sein,
sonst stellt GitHub kein Zertifikat aus:

```bash
dig +short CAA cloudsrv24.de
# leer  → in Ordnung, jede CA darf ausstellen
# gesetzt → es muss ein Eintrag  0 issue "letsencrypt.org"  vorhanden sein
```

---

## Was danach dauerhaft gilt

| Regel | Grund |
|---|---|
| TXT-Record nie löschen | Verifizierung verfällt sonst |
| `CNAME`-Datei im `gh-pages`-Branch nie überschreiben | sonst verliert Pages die Custom Domain und die Seite ist nur noch unter `philf90.github.io` erreichbar |
| `.nojekyll` behalten | sonst verschwinden Verzeichnisse mit führendem Unterstrich |
| Release-Workflow schreibt nur in `install.sh`, `updates/`, `apt/`, `index.html` | alles andere im Pages-Branch bleibt unangetastet |

## Fehlerbilder

| Symptom | Ursache |
|---|---|
| *„Domain does not resolve to the GitHub Pages server"* | CNAME zeigt auf `philf90.github.io/<repo>` statt auf `philf90.github.io.`, oder DNS noch nicht propagiert |
| *„Unavailable for your site because your domain is not properly configured"* bei Enforce HTTPS | Zertifikat noch nicht ausgestellt — abwarten, notfalls Custom Domain einmal entfernen und neu setzen, das stößt die Ausstellung erneut an |
| Verifizierung schlägt trotz TXT fehl | doppeltes Domain-Suffix im Record (siehe 1.3) oder Wert mit überflüssigen Anführungszeichen gespeichert |
| Seite liefert 404 | `gh-pages`-Branch leer oder falscher Ordner in den Pages-Einstellungen gewählt |
