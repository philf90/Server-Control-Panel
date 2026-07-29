package httpd

import (
	"net/http"
	"strings"

	"github.com/philf90/asylum/internal/metrics"
)

// Bestätigung vor zerstörenden Aktionen.
//
// Bis hierher stand die Rückfrage in einem Attribut:
//
//	<form … onsubmit="return confirm('Konto wirklich löschen?')">
//
// Das hat nie funktioniert. Die Content-Security-Policy des Panels ist
// `script-src 'self'` ohne 'unsafe-inline'; ein Inline-Handler wird vom Browser
// verworfen, bevor er einmal läuft. Im Browser nachgemessen: Das Attribut stand
// im Markup, `form.onsubmit` war trotzdem keine Funktion, kein Dialog erschien,
// und das Konto war nach einem Klick weg. Dreizehn Stellen im Projekt waren so
// gebaut — jede sah abgesichert aus, keine war es.
//
// Die Rückfrage gehört deshalb dorthin, wo sie nicht verworfen werden kann: in
// den Handler. Er führt nichts aus, solange das Formular nicht `bestaetigt=1`
// mitbringt, und antwortet stattdessen mit einer Seite, die sagt, was passieren
// wird. Diese Seite schickt dasselbe POST erneut, diesmal mit dem Feld.
//
// Der Dialog im Browser (bestaetigen.js, `data-bestaetigen`) ist die bequeme
// Fassung desselben Schritts: ein Klick statt eines Seitenwechsels. Er ist
// Beiwerk — ohne Skript greift die Zwischenseite, und mit einem selbstgebauten
// POST greift die Prüfung hier. Dieselbe Arbeitsteilung wie bei der Pfadwache:
// Die Oberfläche ist bequem, verbindlich ist der Server.
//
// Zwei Stufen:
//
//   - Ohne `Tippen` genügt der zweite Klick. Für alles, was zerstörend, aber
//     nachvollziehbar ist: ein Schlüssel, ein Passkey, ein gestoppter Dienst.
//   - Mit `Tippen` muss der Name des Ziels eingegeben werden. Für das, was
//     unumkehrbar ist oder aussperrt: ein Konto löschen, einen Ordner mit Inhalt
//     löschen, ufw ausschalten, den Server neu starten. Bei systemweiten
//     Aktionen ist das getippte Wort der Hostname — das schützt zusätzlich
//     davor, die richtige Aktion auf dem falschen Server auszuführen.
//
// Was reversibel ist, bekommt keine Rückfrage: sperren, entsperren, starten,
// neu starten, eine einzelne Sitzung beenden. Ein Dialog vor jeder Kleinigkeit
// erzieht zum Wegklicken und entwertet die Rückfrage dort, wo sie zählt.
type bestaetigung struct {
	// Titel ist die Überschrift der Zwischenseite ("Konto löschen").
	Titel string
	// Frage ist der eine Satz, der sagt, was passiert — mit Zahlen, wo es sie
	// gibt. "Ordner wirklich löschen?" befähigt zu keiner Entscheidung,
	// "4132 Dateien, 1,2 GiB" schon.
	Frage string
	// Punkte sind die Folgen, je eine Zeile. Nur auf der Zwischenseite; der
	// Dialog trägt die Zahlen im Satz.
	Punkte []string
	// Knopf beschriftet den bestätigenden Knopf ("endgültig löschen").
	Knopf string
	// Tippen ist das Wort, das eingegeben werden muss. Leer heißt: zweite Stufe
	// genügt.
	Tippen string
	// TippenHinweis erklärt, was zu tippen ist ("Zum Bestätigen den Hostnamen
	// tippen").
	TippenHinweis string
	// Abbruch ist das Ziel des Abbrechen-Knopfes.
	Abbruch string
	// Aktion ist das POST-Ziel. Leer heißt: derselbe Pfad — die Zwischenseite
	// antwortet auf genau die Anfrage, die sie ausgelöst hat.
	Aktion string
	// Felder sind die Werte, die beim zweiten POST wieder mitmüssen. Alles, was
	// im Pfad steht, gehört nicht hierher.
	Felder []bestaetigungFeld
	// Fehler steht über dem Formular, wenn das getippte Wort nicht passte.
	Fehler string
}

type bestaetigungFeld struct{ Name, Wert string }

// bestaetigt sagt, ob die Aktion laufen darf.
//
// Ist die Antwort false, wurde bereits eine Seite geschrieben — der Handler muss
// dann ohne weitere Ausgabe zurückkehren. Er darf bis zu diesem Aufruf nichts
// verändert haben; prüfen und lesen ist in Ordnung, damit die Frage die richtigen
// Zahlen tragen kann.
func (s *Server) bestaetigt(w http.ResponseWriter, r *http.Request, b bestaetigung) bool {
	if r.PostFormValue("bestaetigt") == "1" {
		if b.Tippen == "" {
			return true
		}
		// EqualFold, nicht Vergleich auf Gleichheit: Auf einem Telefon macht die
		// Tastatur aus "vm" gern "Vm". Wer den Namen kennt, hat die Rückfrage
		// gelesen — die Groß- und Kleinschreibung ist nicht der Zweck.
		if strings.EqualFold(strings.TrimSpace(r.PostFormValue("tippen")), b.Tippen) {
			return true
		}
		b.Fehler = "Das stimmt nicht mit " + b.Tippen + " überein. Die Aktion wurde nicht ausgeführt."
	}

	if b.Aktion == "" {
		b.Aktion = r.URL.Path
	}
	if b.Knopf == "" {
		b.Knopf = "fortfahren"
	}
	if b.TippenHinweis == "" && b.Tippen != "" {
		b.TippenHinweis = "Zum Bestätigen " + b.Tippen + " eingeben"
	}

	status := http.StatusOK
	if b.Fehler != "" {
		status = http.StatusBadRequest
	}
	titel := b.Titel
	if titel == "" {
		titel = "Bestätigung"
	}
	seite := s.base(r, titel, s.navVon(r)).with(b)
	if b.Fehler != "" {
		seite = seite.withError(b.Fehler)
	}
	s.renderPage(w, r, status, "bestaetigung", seite)
	return false
}

// rechnername des laufenden Servers, siehe die Funktion darunter.
func (s *Server) rechnername() string { return rechnername(s.sampler.Host()) }

// rechnername ist das Wort, das bei systemweiten Aktionen getippt werden muss.
//
// Der kurze Name und nicht der FQDN: "vm" tippt man, bei
// "vm.kunde.example.com" sucht man die Abkürzung. Er steht in der Seitenleiste
// und in der Fußzeile, ist also ablesbar — der Zweck ist nicht Geheimhaltung,
// sondern ein Innehalten mit Blick auf das richtige Feld. Wer zwei Server im
// Browser offen hat, startet so nicht den falschen neu.
//
// Ist kein Name zu ermitteln, bleibt ein festes Wort: Ohne eines fiele die
// dritte Stufe still auf die zweite zurück, und das wäre die schlechteste
// Variante — sie sähe wie eine Sicherung aus.
//
// Als Funktion und nicht nur als Methode, weil zwei Seiten dasselbe Wort
// brauchen: der Handler für die Prüfung und die Vorlage für den Dialog
// (basePage.Rechnername). Stünde die Wahl zweimal, verlangte der Server
// irgendwann ein Wort, nach dem der Dialog nicht fragt.
func rechnername(h metrics.Host) string {
	if h.Hostname != "" {
		return h.Hostname
	}
	if name := h.Name(); name != "" {
		return name
	}
	return "bestaetigen"
}

// navVon hält den Menüpunkt der Seite hervorgehoben, von der die Aktion kam.
// Ohne das sprang die Markierung beim Bestätigen ins Nichts, und die
// Zwischenseite sah aus, als gehöre sie zu keinem Modul.
func (s *Server) navVon(r *http.Request) string {
	pfad := r.URL.Path
	switch {
	case strings.HasPrefix(pfad, "/users"):
		return "users"
	case strings.HasPrefix(pfad, "/system-users"):
		return "sysusers"
	case strings.HasPrefix(pfad, "/services"):
		return "services"
	case strings.HasPrefix(pfad, "/packages"), strings.HasPrefix(pfad, "/system/"):
		return "packages"
	case strings.HasPrefix(pfad, "/firewall"):
		return "firewall"
	case strings.HasPrefix(pfad, "/files"):
		return "files"
	case strings.HasPrefix(pfad, "/update"):
		return "update"
	case strings.HasPrefix(pfad, "/account"):
		return "account"
	}
	return ""
}
