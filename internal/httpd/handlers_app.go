package httpd

import (
	"context"
	"fmt"
	"math"
	"strings"
	"time"

	"github.com/philf90/asylum/internal/metrics"
	"github.com/philf90/asylum/internal/privops"
	"github.com/philf90/asylum/internal/ui"
)

// dashboardSignals sammelt die Punkte für „Handlungsbedarf". Bewusst nur aus
// günstigen Quellen und mit kurzem Timeout: Die Übersicht ist die meistbesuchte
// Seite und darf nicht an einem hängenden systemctl kleben bleiben. Jeder
// Fehler wird verschluckt — dann fehlt eben ein Signal, die Seite steht aber.
func (s *Server) dashboardSignals(ctx context.Context, snap metrics.Snapshot) []dashSignal {
	ctx, cancel := context.WithTimeout(ctx, 3*time.Second)
	defer cancel()

	var out []dashSignal

	// Fehlgeschlagene Dienste.
	if svcs, err := s.ops.Services(ctx, privops.ServiceFilter{}); err == nil {
		var failed []string
		for _, sv := range svcs {
			if sv.Failed() {
				failed = append(failed, sv.Unit)
			}
		}
		switch {
		case len(failed) == 1:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Dienst", Title: failed[0] + " ist ausgefallen",
				Detail:      "Der Dienst läuft nicht mehr. Auf der Dienste-Seite lässt er sich neu starten.",
				ActionLabel: "Dienste öffnen", ActionHref: "/dienste", Primary: true,
			})
		case len(failed) > 1:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Dienste", Title: fmt.Sprintf("%d Dienste sind ausgefallen", len(failed)),
				Detail:      strings.Join(failed, " · "),
				ActionLabel: "Dienste öffnen", ActionHref: "/dienste", Primary: true,
			})
		}
	}

	// Plattendruck — aus der bereits vorliegenden Messung, ohne zusätzlichen Aufruf.
	for _, fs := range snap.Filesystems {
		switch {
		case fs.UsedPct >= 95:
			out = append(out, dashSignal{
				Level: "crit", Tag: "Speicher", Title: fmt.Sprintf("%s ist zu %.0f %% belegt", fs.Mount, fs.UsedPct),
				Detail: "Es wird eng — hier drohen Schreibfehler.", ActionLabel: "Pakete öffnen", ActionHref: "/pakete",
			})
		case fs.UsedPct >= 85:
			out = append(out, dashSignal{
				Level: "warn", Tag: "Speicher", Title: fmt.Sprintf("%s ist zu %.0f %% belegt", fs.Mount, fs.UsedPct),
				Detail: "Bei einem größeren Update könnte der Platz knapp werden.", ActionLabel: "Pakete öffnen", ActionHref: "/pakete",
			})
		}
	}

	// Neustart nötig.
	if rb, err := s.ops.RebootRequired(ctx); err == nil && rb.Required {
		detail := "Ein Kernel- oder Bibliotheks-Update wartet auf einen Neustart."
		if len(rb.Packages) > 0 {
			detail = "Ausgelöst durch: " + strings.Join(rb.Packages, ", ")
		}
		out = append(out, dashSignal{
			Level: "warn", Tag: "System", Title: "Ein Neustart steht aus", Detail: detail,
			ActionLabel: "Zu den Paketen", ActionHref: "/pakete",
		})
	}

	// Auffällige Container. Der Aufruf kostet ein "docker ps" — auf einem Server
	// ohne Docker scheitert er sofort und kostet nichts, auf einem mit Docker
	// liegt er im zweistelligen Millisekundenbereich. Beides bleibt in der
	// Drei-Sekunden-Frist dieser Funktion.
	//
	// Die Regel, was auffällig ist, kommt aus containerStufe — derselben
	// Funktion, die auch die Containerliste färbt. Zwei Fassungen liefen
	// auseinander, und dann meldete die Übersicht einen Befund, den die Liste
	// nicht kennt.
	if cs, err := s.ops.DockerContainers(ctx); err == nil {
		var auffaellig []string
		for _, c := range cs {
			if _, warn := containerStufe(c); warn {
				auffaellig = append(auffaellig, c.Name)
			}
		}
		switch {
		case len(auffaellig) == 1:
			out = append(out, dashSignal{
				Level: "warn", Tag: "Container", Title: auffaellig[0] + " braucht Aufmerksamkeit",
				Detail:      "Der Container ist ungesund, unsauber beendet oder startet in einer Schleife neu.",
				ActionLabel: "Docker öffnen", ActionHref: "/docker",
			})
		case len(auffaellig) > 1:
			out = append(out, dashSignal{
				Level: "warn", Tag: "Container", Title: fmt.Sprintf("%d Container brauchen Aufmerksamkeit", len(auffaellig)),
				Detail:      strings.Join(auffaellig, " · "),
				ActionLabel: "Docker öffnen", ActionHref: "/docker",
			})
		}
	}

	return out
}

// urteilAus fasst den Handlungsbedarf in einen Satz.
//
// Grundsatz V aus docs/15-neuordnung.md: erst das Urteil, dann die Zahlen. Der
// Anlass war eine Rückmeldung, kein Testbefund — die Übersicht wirkte „fad und
// wenig aufschlussreich", weil der Betrachter aus einem Gitter gleichrangiger
// Kacheln selbst herauslesen musste, ob dem Server etwas fehlt.
//
// Ausgelagert, damit beide Oberflächen denselben Satz sagen: Die alte rendert
// ihn in eine Vorlage, die neue holt ihn über /api/v1/signals. Zwei Fassungen
// derselben Zählung liefen früher oder später auseinander, und dann behauptet
// eine Seite „alles läuft normal", während die andere zwei offene Punkte nennt.
func urteilAus(signals []dashSignal) dashVerdict {
	switch n := len(signals); n {
	case 0:
		return dashVerdict{Level: "ok", Title: "Alles läuft normal", Sub: "Keine offenen Punkte."}
	case 1:
		return dashVerdict{Level: "warn", Title: "1 Ding braucht Aufmerksamkeit", Sub: "Alles übrige läuft normal."}
	default:
		return dashVerdict{
			Level: "warn",
			Title: fmt.Sprintf("%d Dinge brauchen Aufmerksamkeit", n),
			Sub:   "Alles übrige läuft normal.",
		}
	}
}

// dashboardSparks baut die Verläufe der letzten 24 Stunden aus dem Ringpuffer.
//
// Der Netzverlauf zählt nur die Hauptschnittstelle. Vorher war er die Summe
// über alle — mit Docker also der echten Karte plus einer Brücke, die
// stillsteht. Was dabei herauskam, gehörte zu keiner Zahl auf der Kachel.
func (s *Server) dashboardSparks() dashSparks {
	all := s.ring.All()
	at := make([]time.Time, 0, len(all))
	cpu := make([]float64, 0, len(all))
	mem := make([]float64, 0, len(all))
	load := make([]float64, 0, len(all))
	net := make([]float64, 0, len(all))
	for _, sn := range all {
		at = append(at, sn.At)
		cpu = append(cpu, sn.CPU.Total)
		mem = append(mem, sn.Memory.UsedPct)
		load = append(load, sn.Load[0])
		var n float64
		if ifc, ok := sn.PrimaryInterface(); ok {
			n = ifc.RXRate + ifc.TXRate
		}
		net = append(net, n)
	}
	return dashSparks{
		CPU:  buildSpark(at, cpu, prozentText, 5),
		Mem:  buildSpark(at, mem, prozentText, 5),
		Load: buildSpark(at, load, lastText, 0.5),
		Net:  buildSpark(at, net, durchsatzText, 4096),
	}
}

// Die Texte der Messpunkte entstehen hier und nicht im Browser: Einheit,
// Rundung und Sprache stehen im Panel an einer Stelle, und das Skript für den
// Mouseover bleibt eine Anzeige ohne eigene Rechnung.
func prozentText(v float64) string { return fmt.Sprintf("%.1f %%", v) }

// Die Last wird auf zwei Stellen genannt. Die große Zahl der Kachel zeigt eine;
// im Verlauf eines ruhigen Servers wäre damit jeder Punkt "0.0".
func lastText(v float64) string { return fmt.Sprintf("%.2f", v) }

// "gesamt", weil der Verlauf empfangen und gesendet zusammenfasst, die große
// Zahl der Kachel aber nur das Empfangene nennt. Ohne das Wort sähe der
// Messpunkt neben der Kachel wie ein Widerspruch aus.
func durchsatzText(v float64) string { return ui.FormatRate(v) + " gesamt" }

const (
	sparkBreite = 100.0
	sparkHoehe  = 34.0
	sparkRand   = 2.0
	// sparkPunkte begrenzt die Zahl der gezeichneten Stützstellen.
	//
	// Der Ringpuffer hält 24 Stunden in 30-Sekunden-Auflösung, also bis zu 2880
	// Messungen. Auf 100 Einheiten Breite liegen die 0,03 Einheiten
	// auseinander — bei rund 270 Pixeln Kachelbreite etwa zehn Punkte je Pixel.
	// Daraus wird kein Verlauf, sondern ein Band: Der Strich überzeichnet sich
	// selbst und sieht ausgelaufen aus. 60 Stützstellen sind je eine für 24
	// Minuten und in einer Kachel dieser Größe noch unterscheidbar.
	sparkPunkte = 60
)

// sparkPunkt ist eine Stützstelle des Verlaufs, wie sie der Mouseover braucht:
// die Stelle im Feld und der Text dazu.
type sparkPunkt struct {
	X float64 `json:"x"`
	Y float64 `json:"y"`
	T string  `json:"t"`
	V string  `json:"v"`
}

// buildSpark erzeugt den SVG-Pfad eines Verlaufs in einem 100×34-Feld, den
// Endpunkt und die Messpunkte für den Mouseover. Weniger als zwei Werte ergeben
// keinen Verlauf (Has=false) — dann zeigt die Kachel nur die Zahl.
//
// minSpan ist die kleinste Spanne, über die skaliert wird. Ohne sie zieht die
// Min-Max-Skalierung jeden Bruchteil auf die volle Kachelhöhe: Eine CPU, die
// zwischen 0,1 und 0,3 Prozent pendelt, sah aus wie ein Gebirge, und ein
// ruhiger Server wirkte unruhig. Mit ihr bleibt flach, was flach ist.
func buildSpark(at []time.Time, vals []float64, text func(float64) string, minSpan float64) spark {
	if len(vals) < 2 || len(at) != len(vals) {
		return spark{}
	}
	at, vals = verdichten(at, vals, sparkPunkte)

	minV, maxV := vals[0], vals[0]
	for _, v := range vals {
		if v < minV {
			minV = v
		}
		if v > maxV {
			maxV = v
		}
	}
	if maxV-minV < minSpan {
		mitte := (minV + maxV) / 2
		minV, maxV = mitte-minSpan/2, mitte+minSpan/2
		// Keine Messgröße dieser Seite ist negativ. Liegt die Spanne im
		// Nullbereich, wird sie dort verankert, statt Platz unter der Null zu
		// verschenken.
		if minV < 0 {
			minV, maxV = 0, minSpan
		}
	}
	span := maxV - minV
	if span <= 0 {
		span = 1
	}

	dx := (sparkBreite - 2*sparkRand) / float64(len(vals)-1)
	var b strings.Builder
	punkte := make([]sparkPunkt, 0, len(vals))
	for i, v := range vals {
		x := einstellig(sparkRand + float64(i)*dx)
		y := einstellig(sparkHoehe - sparkRand - ((v-minV)/span)*(sparkHoehe-2*sparkRand))
		if i == 0 {
			fmt.Fprintf(&b, "M%.1f %.1f", x, y)
		} else {
			fmt.Fprintf(&b, " L%.1f %.1f", x, y)
		}
		punkte = append(punkte, sparkPunkt{X: x, Y: y, T: at[i].Local().Format("15:04"), V: text(v)})
	}

	letzter := punkte[len(punkte)-1]
	return spark{
		Path: b.String(),
		// Der Endpunkt ist ein Segment der Länge null mit runder Kappe. Ein
		// <circle> würde von derselben waagerechten Streckung getroffen wie der
		// Strich und käme als liegende Ellipse heraus; ein Strich mit
		// non-scaling-stroke ergibt einen runden Punkt.
		Dot:    fmt.Sprintf("M%.1f %.1f L%.1f %.1f", letzter.X, letzter.Y, letzter.X, letzter.Y),
		Punkte: punkte,
		Has:    true,
	}
}

// verdichten mittelt einen Verlauf auf höchstens so viele Stützstellen. Der
// Zeitstempel einer Stützstelle ist der aus der Mitte ihres Abschnitts.
func verdichten(at []time.Time, vals []float64, stuetzstellen int) ([]time.Time, []float64) {
	if stuetzstellen < 2 || len(vals) <= stuetzstellen {
		return at, vals
	}
	ausAt := make([]time.Time, 0, stuetzstellen)
	ausV := make([]float64, 0, stuetzstellen)
	for i := 0; i < stuetzstellen; i++ {
		von := i * len(vals) / stuetzstellen
		bis := (i + 1) * len(vals) / stuetzstellen
		if bis <= von {
			continue
		}
		var summe float64
		for _, v := range vals[von:bis] {
			summe += v
		}
		ausV = append(ausV, summe/float64(bis-von))
		ausAt = append(ausAt, at[von+(bis-von)/2])
	}
	return ausAt, ausV
}

// einstellig rundet auf eine Nachkommastelle — dieselbe Genauigkeit, mit der
// der Pfad geschrieben wird. Sonst nennt das JSON für den Mouseover eine
// Stelle, die im Bild nicht existiert.
func einstellig(v float64) float64 { return math.Round(v*10) / 10 }

// ------------------------------------------------------ Benutzerverwaltung ---
