package httpd

import (
	"github.com/philf90/asylum/internal/metrics"
)

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
