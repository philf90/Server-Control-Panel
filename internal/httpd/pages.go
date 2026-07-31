package httpd

import (
	"net/http"

	"github.com/philf90/asylum/internal/auth"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/store"
	"github.com/philf90/asylum/internal/version"
)

// basePage sind die Werte, die jede server-gerenderte Seite braucht.
// Seitenspezifische Daten hängen unter Content.
//
// Es waren einmal deutlich mehr Felder. Sie speisten die Symbolschiene, die
// Statusleiste und die Konsole der alten Oberfläche — Nav, CanWrite, IsOwner,
// FilesOn, Host, Lage, Konsole. Mit diesen Flächen sind sie gegangen. Übrig
// bleiben die Seiten VOR dem Panel, und die brauchen wenig: einen Titel, die
// Themenwahl, gegebenenfalls einen CSRF-Token und die Passwortrichtlinie.
type basePage struct {
	Title string
	User  store.User
	// LoggedIn unterscheidet auf der Fehlerseite den Rückweg: ins Panel oder
	// zur Anmeldung. Mehr hängt nicht mehr daran.
	LoggedIn bool
	CSRF     string
	Version  string
	Flash    string
	Error    string
	// Theme ist "dark", "light" oder leer. Leer heißt: keine ausdrückliche
	// Wahl, es gilt die Systemeinstellung. Der Wert kommt aus dem Cookie
	// asylum_theme und wird ans <html> gerendert, damit die Seite ohne
	// Aufblitzen im richtigen Modus ankommt.
	Theme string
	// Policy ist die geltende Passwortrichtlinie. Drei Seiten verlangen ein
	// neues Passwort (Ersteinrichtung, erzwungener Wechsel, Passkey-Weg) und
	// alle benutzen dieselbe Anzeige. Die Zahlen kommen von hier ins Markup —
	// das Skript für die Prüfliste soll sie nicht ein zweites Mal
	// festschreiben.
	Policy  auth.PasswordPolicy
	Content any
}

func (s *Server) base(r *http.Request, title string) basePage {
	p := basePage{
		Title:   title,
		Version: version.String(),
		Policy:  auth.Policy(),
	}
	if u, ok := userFrom(r.Context()); ok {
		p.User = u
		p.LoggedIn = true
	}
	if sess, ok := sessionFrom(r.Context()); ok {
		p.CSRF = sess.CSRFToken
	}
	// Die Themenwahl steht in einem Cookie. Nur die beiden erwarteten Werte
	// werden übernommen — alles andere zählt als „keine Wahl" und überlässt die
	// Entscheidung der Systemeinstellung. So kann ein manipulierter Cookie-Wert
	// nicht ins Attribut durchschlagen.
	if c, err := r.Cookie("asylum_theme"); err == nil {
		if c.Value == "dark" || c.Value == "light" {
			p.Theme = c.Value
		}
	}
	return p
}

func (b basePage) with(content any) basePage {
	b.Content = content
	return b
}

func (b basePage) withError(msg string) basePage {
	b.Error = msg
	return b
}

func (b basePage) withFlash(msg string) basePage {
	b.Flash = msg
	return b
}

type errorPage struct {
	Message string
}

type loginPage struct {
	Username string
	// WebAuthnOn blendet den Passkey-Knopf ein. Ohne eingeschaltete Funktion
	// gäbe es keinen Endpunkt, den er ansprechen könnte.
	WebAuthnOn bool
}

type setupPage struct {
	Token string
}

type totpPage struct {
	Secret          string
	SecretFormatted string
	URI             string
}

// codesPage zeigt die Wiederherstellungscodes genau einmal.
//
// Es gab hier ein Feld AfterChange, das den Wechsel des zweiten Faktors von der
// Ersteinrichtung unterschied und den Weg zurück zur Kontoseite führte. Den
// Wechsel erledigt jetzt die neue Kontoseite selbst; diese Vorlage sieht nur
// noch die Ersteinrichtung, und danach führt genau ein Weg weiter.
type codesPage struct {
	Codes []string
}

// dashVerdict ist das Urteil in einem Satz ganz oben: Geht es dem Server gut?
type dashVerdict struct {
	Level string // "ok" | "warn"
	Title string
	Sub   string
}

// dashSignal ist ein Punkt auf der Handlungsbedarf-Liste. Die Aktion ist ein
// Link auf die zuständige Seite — keine schreibende Aktion von der Übersicht
// aus, damit die Seite ohne CSRF und ohne Schreibpfad auskommt.
type dashSignal struct {
	Level       string // "crit" | "warn"
	Tag         string // "Dienst" | "Speicher" | "System"
	Title       string
	Detail      string
	ActionLabel string
	ActionHref  string
	Primary     bool
}

// dashSparks hält die fertigen SVG-Pfade der Telemetrie-Verläufe. Serverseitig
// erzeugt, damit die Seite ohne Inline-Skript auskommt (CSP).
type dashSparks struct {
	CPU  spark
	Mem  spark
	Load spark
	Net  spark
}

// spark ist ein einzelner Verlauf: der Pfad, der Endpunkt und die Messpunkte.
type spark struct {
	Path string
	// Dot ist der Endpunkt als eigener Pfad (Segment der Länge null mit runder
	// Kappe), damit ihn die waagerechte Streckung des Feldes nicht verzerrt.
	Dot string
	// Punkte sind die Stützstellen: Stelle im Feld, Uhrzeit, Wert. Die
	// JSON-Schnittstelle gibt sie so weiter, die Kachel zeigt den Wert unter dem
	// Zeiger daraus.
	//
	// Daneben stand ein Feld Points mit denselben Stützstellen als
	// JSON-Zeichenkette. Es stand in einem data-Attribut der alten Vorlage, aus
	// dem spark.js sie las — die CSP erlaubt kein Inline-Skript, das sie
	// mitbrächte. Mit der Vorlage ist das Feld gegangen.
	Punkte []sparkPunkt
	Has    bool
}

type forgotPage struct {
	// WebAuthnOn entscheidet, ob es überhaupt einen Weg gibt. Ohne Passkeys
	// nennt die Seite den Befehl für die Kommandozeile.
	WebAuthnOn bool
}

type forgotNewPage struct {
	Username string
}

// --------------------------------------------------- Seiten der Systemmodule ---

// firewallRow ist eine Zeile im Regelformular.
type firewallRow struct {
	Rule privops.FirewallRule
	// Locked: Port und Protokoll stehen fest. Gilt für den Port, über den das
	// Panel erreichbar ist — ohne ihn sperrt das nächste Einschalten aus.
	Locked bool
	// Proposed: eine Regel, die es noch nicht gibt, aber geben sollte.
	Proposed bool
	// Note erklärt in einem Satz, warum die Zeile so ist, wie sie ist.
	Note string
}

// crumb ist ein Bestandteil des klickbaren Pfads.
// crumb ist ein Glied des klickbaren Pfades. Die JSON-Namen braucht die
// Zielauswahl beim Kopieren und Verschieben (/files/dirs).
type crumb struct {
	Name string `json:"name"`
	Path string `json:"path"`
}
