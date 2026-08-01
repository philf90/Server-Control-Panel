package acme

import (
	"bufio"
	"errors"
	"fmt"
	"os"
	"strings"
)

// Zugangsdaten der DNS-Anbieter.
//
// Ein gemeinsames Muster für alle, und das ist eine Entscheidung mit Grund:
// Mit sieben Anbietern hätte die Konfiguration sonst sieben Blöcke mit
// zusammen zwanzig Feldern, davon die Hälfte Geheimnisse. Stattdessen hat
// jeder Anbieter genau ein Feld — den Pfad zu einer Datei —, und in der Datei
// steht, was er braucht.
//
// Drei Eigenschaften folgen daraus, und alle drei sind der eigentliche Zweck:
//
//  1. **In der Konfiguration steht kein Geheimnis.** `config.yaml` liegt in
//     /etc, wird gelesen, gesichert, in Fehlerberichte kopiert. Ein API-Token,
//     mit dem sich Zertifikate für eine ganze Zone ausstellen lassen, gehört
//     dort nicht hinein. Das galt für Cloudflare seit jeher; jetzt gilt es für
//     alle.
//  2. **Die Rechte lassen sich prüfen.** Eine Datei ist ein Objekt mit einem
//     Modus — eine YAML-Zeile ist es nicht.
//  3. **Die Oberfläche braucht ein Feld je Anbieter statt vier.**
//
// Das Format ist absichtlich anspruchslos: `schlüssel = wert` je Zeile, `#`
// leitet einen Kommentar ein. Anbieter mit genau EINEM Geheimnis nehmen auch
// eine Datei, die nur den Token enthält — so, wie die Cloudflare-Datei bisher
// aussah. Wer von 0.5 kommt, muss nichts ändern.

// Zugang ist der Inhalt einer Zugangsdatei.
type Zugang struct {
	// werte sind die benannten Einträge.
	werte map[string]string
	// roh ist der Inhalt ohne Kommentare und Rand — für Anbieter mit genau
	// einem Geheimnis, deren Datei nur den Token enthält.
	roh string
	// pfad für Fehlermeldungen. Eine Meldung ohne den Dateinamen schickt
	// jemanden auf die Suche.
	pfad string
	// Warnung ist eine Anmerkung zu den Rechten, die den Bezug nicht aufhält.
	// Leer, wenn alles in Ordnung ist.
	Warnung string
}

// LadeZugang liest eine Zugangsdatei und prüft ihre Rechte.
//
// Die Rechteprüfung ist der Grund, warum diese Funktion existiert und die
// Anbieter nicht einfach os.ReadFile aufrufen:
//
//   - **Für andere lesbar → Fehler.** Ein DNS-Token stellt Zertifikate für die
//     ganze Zone aus. Auf einem Server mit mehreren Konten ist eine
//     weltlesbare Datei damit kein Schönheitsfehler, sondern die Übergabe der
//     Domain. Das ist eine Verhaltensänderung gegenüber 0.5 und gehört in den
//     CHANGELOG — die Dokumentation nannte 0600 allerdings seit jeher.
//   - **Für die Gruppe lesbar → Warnung, kein Abbruch.** Eine Gruppe für die
//     Betreiber ist eine übliche und bewusste Einrichtung. Sie zu verbieten
//     hieße, eine Entscheidung zu treffen, die dem Betreiber gehört.
func LadeZugang(pfad string) (*Zugang, error) {
	if pfad == "" {
		return nil, errors.New("kein Pfad zur Zugangsdatei")
	}
	fi, err := os.Stat(pfad)
	if err != nil {
		return nil, fmt.Errorf("Zugangsdatei: %w", err)
	}
	if fi.IsDir() {
		return nil, fmt.Errorf("%s ist ein Verzeichnis, keine Zugangsdatei", pfad)
	}

	z := &Zugang{werte: map[string]string{}, pfad: pfad}
	switch modus := fi.Mode().Perm(); {
	case modus&0o004 != 0:
		return nil, fmt.Errorf("%s ist für alle lesbar (%04o). Ein DNS-Token stellt "+
			"Zertifikate für die ganze Zone aus — »chmod 600 %s«", pfad, modus, pfad)
	case modus&0o040 != 0:
		z.Warnung = fmt.Sprintf("%s ist für die Gruppe lesbar (%04o)", pfad, modus)
	}

	b, err := os.ReadFile(pfad) //nolint:gosec // Pfad aus der Konfiguration des Betreibers
	if err != nil {
		return nil, fmt.Errorf("Zugangsdatei: %w", err)
	}
	z.lies(string(b))
	if z.roh == "" && len(z.werte) == 0 {
		return nil, fmt.Errorf("%s ist leer", pfad)
	}
	return z, nil
}

// lies zerlegt den Inhalt.
//
// Zeilen ohne Gleichheitszeichen sammeln sich in roh — daraus wird der Token
// für Anbieter mit genau einem Geheimnis. Beides nebeneinander zu erlauben ist
// Absicht: Eine Datei mit nur dem Token bleibt gültig, und eine mit benannten
// Feldern braucht kein anderes Format.
func (z *Zugang) lies(inhalt string) {
	var lose []string
	sc := bufio.NewScanner(strings.NewReader(inhalt))
	for sc.Scan() {
		zeile := strings.TrimSpace(sc.Text())
		if zeile == "" || strings.HasPrefix(zeile, "#") {
			continue
		}
		name, wert, hatGleich := strings.Cut(zeile, "=")
		if !hatGleich {
			lose = append(lose, zeile)
			continue
		}
		z.werte[strings.ToLower(strings.TrimSpace(name))] = strings.TrimSpace(wert)
	}
	z.roh = strings.Join(lose, "")
}

// Wert liefert einen benannten Eintrag.
func (z *Zugang) Wert(name string) string { return z.werte[name] }

// Geheimnis liefert das einzige Geheimnis einer Datei.
//
// Erst der benannte Eintrag, dann der lose Inhalt: So funktioniert sowohl eine
// Datei mit `api_token = …` als auch eine, die nur den Token enthält.
func (z *Zugang) Geheimnis(namen ...string) string {
	for _, n := range namen {
		if w := z.werte[n]; w != "" {
			return w
		}
	}
	return z.roh
}

// Pflicht prüft, dass alle genannten Einträge da sind.
//
// Die Meldung nennt ALLE fehlenden auf einmal und nicht den ersten. Wer eine
// Zugangsdatei anlegt, soll sie einmal ergänzen und nicht dreimal — jeder
// Versuch dazwischen ist ein Fehlversuch beim CA-Server.
func (z *Zugang) Pflicht(namen ...string) error {
	var fehlen []string
	for _, n := range namen {
		if z.werte[n] == "" {
			fehlen = append(fehlen, n)
		}
	}
	if len(fehlen) == 0 {
		return nil
	}
	return fmt.Errorf("%s fehlt %s (je Zeile »schlüssel = wert«)",
		z.pfad, strings.Join(fehlen, ", "))
}
