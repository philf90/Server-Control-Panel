# 21 — Signaturschlüssel

Zwei Schlüssel entscheiden darüber, ob eine Freigabe bei den Nutzern ankommt.
Sie sind verschieden alt, sichern Verschiedenes und werden verschieden
getauscht.

| | OpenPGP | minisign |
|---|---|---|
| Signiert | die `Release`-Datei des apt-Repositories | `SHA256SUMS` neben den `.deb`-Dateien |
| Prüft | jedes `apt update` auf jedem installierten Server | wer ein `.deb` von Hand herunterlädt |
| Öffentlich in | `packaging/srvpanel-archive-keyring.gpg` (+ `.asc`) | `packaging/minisign.pub` |
| Beim Nutzer in | `/usr/share/keyrings/srvpanel-archive-keyring.gpg` | nirgends — wird bei Bedarf geholt |
| Secrets | `APT_GPG_KEY`, `APT_GPG_PASSPHRASE` | `MINISIGN_KEY`, `MINISIGN_PASSWORD` |

## Warum jetzt getauscht wird

Das Schlüsselpaar im Repository stammt aus dem Vorgängerprojekt — die User-ID
lautet `Project Asylum Archive Signing Key`. Funktional ist daran nichts
verkehrt, aber:

- Der Name der Signatur passt nicht zum Namen der Software. Wer den Keyring
  ansieht, bevor er ihm vertraut, findet ein fremdes Projekt.
- Der private Schlüssel hat eine Geschichte, die sich von hier aus nicht mehr
  prüfen lässt: wann erzeugt, auf welchem Rechner, wer hatte ihn.

Beides ist genau jetzt kostenlos zu beheben. Unter dem neuen Namen ist noch
nichts veröffentlicht: kein Tag, kein Paket im apt-Repository, keine
Installation. **Der Tausch trifft niemanden.** Nach der ersten Freigabe wäre
er ein Eingriff bei allen Nutzern (siehe unten).

## Durchführung

```bash
packaging/rotate-signing-keys.sh
```

Das Skript läuft **auf dem Rechner des Projektinhabers**, nicht in der CI und
nicht in einer Wegwerf-Umgebung: Es erzeugt privates Schlüsselmaterial, und wo
das entsteht, entscheidet, wer es sehen kann. Gebraucht werden `gnupg` und
`minisign`.

Was es tut:

1. Erzeugt einen OpenPGP-Schlüssel (ed25519, nur Signatur, ohne Ablauf) und
   ein minisign-Paar, jedes mit einer zufälligen 32-stelligen Passphrase.
2. Signiert mit beiden eine Probe und prüft sie gegen den jeweils exportierten
   öffentlichen Teil — aus der Sicht eines Nutzers, mit leerem Schlüsselring.
   Ein Exportfehler fällt hier auf und nicht mitten in der ersten Freigabe.
3. Schreibt **nur die öffentlichen Teile** in den Arbeitsbaum.
4. Legt das private Material in ein frisches Verzeichnis mit `0700`
   **außerhalb** des Arbeitsbaums — damit es nicht versehentlich in einen
   Commit gerät — und nennt den Pfad.

Danach, in dieser Reihenfolge:

1. Passphrasen und beide privaten Dateien in den Passwortspeicher.
2. Die vier Secrets setzen (Settings → Secrets and variables → Actions).
3. Actions → **„Signatur-Secrets prüfen"** von Hand auslösen. Der Lauf
   signiert wirklich und verifiziert gegen die öffentlichen Teile aus dem
   Repository, veröffentlicht aber nichts. Erst wenn er grün ist, passt alles
   zusammen.
4. Das temporäre Verzeichnis löschen.
5. Die drei geänderten Dateien committen.

**Erst danach taggen.** Passt das Secret nicht zum veröffentlichten Schlüssel,
bricht die Freigabe mitten im Veröffentlichen ab — nach dem Bauen, nach dem
Anlegen des GitHub-Release. Die Freigabe prüft den Fingerprint an genau dieser
Stelle und verweigert lieber, als falsch zu signieren.

## Kein Ablaufdatum, und warum

Der OpenPGP-Schlüssel wird ohne Ablaufdatum erzeugt. Das ist keine
Bequemlichkeit, sondern folgt aus dem Auslieferungsweg:

Der öffentliche Schlüssel kommt genau einmal auf den Server des Nutzers —
beim Lauf von `install.sh`, der ihn nach `/usr/share/keyrings` legt. Danach
aktualisiert ihn **nichts**. Er steckt nicht im Paket, es gibt keinen
Mechanismus, der ihn nachzieht.

Ein ablaufender Schlüssel bräche damit an einem Stichtag jedes `apt update`
auf jedem installierten Server gleichzeitig — ohne Zutun, ohne Vorwarnung, mit
einer Fehlermeldung, die auf den Betreiber des Repositories zeigt und nicht
auf den Nutzer. Der Preis für den Verzicht: Ein verlorener oder kompromittierter
Schlüssel kommt nur durch einen Tausch aus der Welt, und der ist Handarbeit.

## Was ein Tausch nach der ersten Freigabe bedeutet

Sobald Server mit dem alten Schlüssel installiert sind, ist ein Tausch kein
interner Vorgang mehr:

- Die Server kennen nur den alten öffentlichen Schlüssel. Ein Repository, das
  nur noch mit dem neuen signiert ist, wird von ihnen abgelehnt — `apt update`
  scheitert mit `NO_PUBKEY`.
- Es gibt keinen automatischen Weg, ihnen den neuen unterzuschieben. Der
  Nutzer müsste `install.sh` erneut laufen lassen oder den Keyring von Hand
  ersetzen.

Ein Tausch braucht dann eine Überlappung: `Release` eine Zeit lang mit beiden
Schlüsseln signieren, den neuen Keyring veröffentlichen, den Wechsel
ankündigen, und erst danach den alten fallen lassen.

**Der saubere Ausweg wäre, den Keyring im Paket auszuliefern** — so macht es
Debian mit `debian-archive-keyring`. Dann trüge ein Update den neuen Schlüssel
von selbst zu allen Servern. Das ist hier bewusst noch nicht gebaut, weil es
eine Frage aufwirft, die Sorgfalt braucht: Ein Paket, das
`/usr/share/keyrings/…` besitzt, nimmt die Datei bei `apt remove` wieder mit —
und hinterlässt eine Paketquelle, die sich nicht mehr prüfen lässt. Das steht
als offener Punkt in `docs/20-hostingpanel-neuplan.md` §15.

Solange nichts veröffentlicht ist, ist all das gegenstandslos. Das ist der
Grund, es jetzt zu tun.
