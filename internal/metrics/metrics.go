// Package metrics liest Systemkennzahlen aus /proc und hält sie in einem
// Ringpuffer.
//
// Bewusst kein Prometheus, keine Zeitreihendatenbank: Für die Live-Ansicht
// genügt ein Ringpuffer im Speicher (24 h in 30-s-Auflösung sind wenige MB).
// Wer echte Langzeitmetriken braucht, exportiert nach Prometheus — dafür gibt
// es bessere Werkzeuge als ein Control Panel.
package metrics

import (
	"sync"
	"time"
)

// Snapshot ist eine Momentaufnahme des Systemzustands.
type Snapshot struct {
	At           time.Time     `json:"at"`
	CPU          CPU           `json:"cpu"`
	Memory       Memory        `json:"memory"`
	Load         [3]float64    `json:"load"`
	Uptime       time.Duration `json:"-"`
	UptimeText   string        `json:"uptime"`
	Filesystems  []Filesystem  `json:"filesystems"`
	Interfaces   []Interface   `json:"interfaces"`
	TopProcesses []Process     `json:"top_processes"`
}

// CPU hält die Auslastung insgesamt und je Kern, jeweils in Prozent.
type CPU struct {
	Total   float64   `json:"total"`
	PerCore []float64 `json:"per_core"`
	IOWait  float64   `json:"iowait"`
	Steal   float64   `json:"steal"`
}

// Memory in Bytes.
type Memory struct {
	Total     uint64  `json:"total"`
	Available uint64  `json:"available"`
	Used      uint64  `json:"used"`
	UsedPct   float64 `json:"used_pct"`
	SwapTotal uint64  `json:"swap_total"`
	SwapUsed  uint64  `json:"swap_used"`
}

// Filesystem beschreibt einen eingehängten Datenträger.
type Filesystem struct {
	Mount      string  `json:"mount"`
	Device     string  `json:"device"`
	Type       string  `json:"type"`
	Total      uint64  `json:"total"`
	Used       uint64  `json:"used"`
	UsedPct    float64 `json:"used_pct"`
	InodesUsed uint64  `json:"inodes_used"`
	InodesPct  float64 `json:"inodes_pct"`
}

// Interface beschreibt eine Netzwerkschnittstelle mit Durchsatz pro Sekunde.
type Interface struct {
	Name    string   `json:"name"`
	RXBytes uint64   `json:"rx_bytes"`
	TXBytes uint64   `json:"tx_bytes"`
	RXRate  float64  `json:"rx_rate"`
	TXRate  float64  `json:"tx_rate"`
	Addrs   []string `json:"addrs"`
}

// Process ist ein Eintrag der Top-Liste.
type Process struct {
	PID     int     `json:"pid"`
	Name    string  `json:"name"`
	User    string  `json:"user"`
	CPUPct  float64 `json:"cpu_pct"`
	RSS     uint64  `json:"rss"`
	RSSPct  float64 `json:"rss_pct"`
	Command string  `json:"command"`
}

// Host sind die Angaben, die sich zur Laufzeit nicht ändern.
type Host struct {
	Hostname string `json:"hostname"`
	// FQDN ist der vollqualifizierte Name — das, was "hostname -f" ausgibt.
	// Er steht neben Hostname und nicht an dessen Stelle, weil beide
	// verschiedene Fragen beantworten: Hostname ist der Name, unter dem sich
	// der Rechner selbst kennt, FQDN der, unter dem ihn andere erreichen.
	// In der Oberfläche zählt der zweite: Nur er lässt sich mit der Adresse
	// im Browser vergleichen.
	FQDN   string `json:"fqdn"`
	Kernel string `json:"kernel"`
	Distro string `json:"distro"`
	Cores  int    `json:"cores"`
	Arch   string `json:"arch"`
}

// Name liefert die Bezeichnung für die Anzeige: den vollqualifizierten Namen,
// solange es einen gibt.
func (h Host) Name() string {
	if h.FQDN != "" {
		return h.FQDN
	}
	return h.Hostname
}

// Sampler erhebt Snapshots und hält die Vorwerte für Deltaberechnungen.
type Sampler struct {
	mu sync.Mutex

	prevCPU     []cpuTimes
	prevNet     map[string]netCounters
	prevProc    map[int]uint64
	prevAt      time.Time
	pageSize    uint64
	clockTicks  float64
	host        Host
	topN        int
	memTotalKiB uint64
}

// NewSampler baut einen Sampler und liest die unveränderlichen Hostangaben.
func NewSampler() *Sampler {
	s := &Sampler{
		prevNet:    make(map[string]netCounters),
		prevProc:   make(map[int]uint64),
		pageSize:   4096,
		clockTicks: 100, // USER_HZ ist auf Linux praktisch immer 100
		topN:       8,
	}
	s.host = readHost()
	return s
}

// Host liefert die unveränderlichen Angaben.
func (s *Sampler) Host() Host { return s.host }

// Ring ist ein Ringpuffer fester Größe für Snapshots.
type Ring struct {
	mu     sync.RWMutex
	items  []Snapshot
	next   int
	filled bool
}

// NewRing legt einen Ringpuffer an.
func NewRing(size int) *Ring {
	if size <= 0 {
		size = 1
	}
	return &Ring{items: make([]Snapshot, size)}
}

// Add hängt einen Snapshot an und überschreibt den ältesten.
func (r *Ring) Add(s Snapshot) {
	r.mu.Lock()
	defer r.mu.Unlock()

	r.items[r.next] = s
	r.next = (r.next + 1) % len(r.items)
	if r.next == 0 {
		r.filled = true
	}
}

// All liefert die Snapshots in chronologischer Reihenfolge.
func (r *Ring) All() []Snapshot {
	r.mu.RLock()
	defer r.mu.RUnlock()

	if !r.filled {
		out := make([]Snapshot, r.next)
		copy(out, r.items[:r.next])
		return out
	}
	out := make([]Snapshot, 0, len(r.items))
	out = append(out, r.items[r.next:]...)
	out = append(out, r.items[:r.next]...)
	return out
}

// Last liefert den jüngsten Snapshot.
func (r *Ring) Last() (Snapshot, bool) {
	r.mu.RLock()
	defer r.mu.RUnlock()

	if r.next == 0 && !r.filled {
		return Snapshot{}, false
	}
	idx := (r.next - 1 + len(r.items)) % len(r.items)
	return r.items[idx], true
}

// Len liefert die Anzahl gespeicherter Snapshots.
func (r *Ring) Len() int {
	r.mu.RLock()
	defer r.mu.RUnlock()
	if r.filled {
		return len(r.items)
	}
	return r.next
}
