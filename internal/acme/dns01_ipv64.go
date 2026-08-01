package acme

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"sync"
	"time"
)

// IPv64.net.
//
// Ein DynDNS-Dienst aus dem deutschsprachigen Raum, der auch eigene Domains
// führt. Die API ist schlicht — und sie hat zwei Eigenheiten, die den größten
// Teil dieser Datei erklären.
//
// **Erstens: die Zone lässt sich nicht raten.** Die vorhandenen Umsetzungen
// scheitern genau daran. lego verlangt in `splitDomain` mindestens drei Labels
// und leitet die Zone aus der Labelanzahl ab; `meins.ipv64.net` hat drei,
// `example.com` hat zwei und fällt durch — das ist der Fehler, der in Zoraxy
// #351 als „funktioniert bei IPv64-Subdomains, nicht bei eigenen Domains"
// beschrieben ist. Das certbot-Plugin macht denselben Fehler andersherum: Es
// nimmt die letzten zwei Labels, und aus `meins.ipv64.net` würde `ipv64.net` —
// eine Zone, die dem Konto nicht gehört.
//
// Hier wird nicht geraten. `get_domains` liefert die Zonen des Kontos, und die
// Zone wird von spezifisch nach allgemein dagegen abgeglichen — dieselbe
// Methode, die der Cloudflare-Setter benutzt. Damit sind beide Fälle richtig.
//
// **Zweitens: die Ratengrenze ist eng.** IPv64 erlaubt in der Standardklasse
// **64 Anfragen je 24 Stunden** und höchstens 5 innerhalb von 10 Sekunden. Das
// ist keine Fußnote, sondern die Auflage, an der sich der Entwurf ausrichtet:
//
//   - `get_domains` wird zwischengespeichert, nicht je Record neu geholt.
//   - Zwischen den Aufrufen liegt ein Mindestabstand.
//   - Es gibt ein **eigenes Tagesbudget**, das VOR dem Aufruf greift. Der Grund
//     steht unten bei ipv64Budget: Der Manager wiederholt einen gescheiterten
//     Bezug stündlich, und ohne Budget wäre ein falsch eingerichtetes Konto
//     nach einem halben Tag für einen ganzen gesperrt.

const providerIPv64 = "ipv64"

const (
	// ipv64API ist der einzige Endpunkt des Dienstes.
	ipv64API = "https://ipv64.net/api.php"
	// ipv64ZonenFrist ist die Haltbarkeit der Zonenliste.
	//
	// Zehn Minuten: lang genug, dass ein Bezug (zwei Autorisierungen, wenige
	// Minuten) mit einem einzigen Aufruf auskommt, und kurz genug, dass eine im
	// Konto neu angelegte Domain nicht bis zum Neustart des Panels unsichtbar
	// bleibt.
	ipv64ZonenFrist = 10 * time.Minute
	// ipv64Abstand hält die Grenze „5 Anfragen in 10 Sekunden" ein, mit Luft.
	ipv64Abstand = 2500 * time.Millisecond
	// ipv64Tagesbudget ist die Zahl der Anfragen, die dieses Panel sich je 24
	// Stunden zugesteht.
	//
	// Bewusst deutlich unter den 64 der Standardklasse: Das Konto gehört dem
	// Betreiber und nicht dem Panel. Wer daneben noch ein DynDNS-Skript laufen
	// hat, soll nicht feststellen, dass das Panel ihm das Kontingent
	// weggenommen hat. Ein Bezug braucht fünf bis sechs Anfragen — 32 reichen
	// für jeden vernünftigen Betrieb und für mehrere Fehlversuche.
	ipv64Tagesbudget = 32
)

func init() {
	registriere(Anbieter{
		Name:   providerIPv64,
		Titel:  "IPv64.net",
		Felder: nil, // genau ein Geheimnis: der API-Key
		Hinweis: "API-Key aus dem IPv64-Konto (Account → API). Die Datei enthält nur den " +
			"Schlüssel, oder eine Zeile »api_key = …«. Achtung: IPv64 erlaubt in der " +
			"Standardklasse 64 Anfragen je 24 Stunden.",
		baue: func(z *Zugang) (dnsSetter, error) {
			key := z.Geheimnis("api_key", "apikey", "token")
			if key == "" {
				return nil, fmt.Errorf("ipv64: die Zugangsdatei enthält keinen API-Key")
			}
			return newIPv64Setter(key), nil
		},
	})
}

type ipv64Setter struct {
	key   string
	basis string
	http  *http.Client

	mu      sync.Mutex
	zonen   []string
	geholt  time.Time
	letzter time.Time
	budget  ipv64Budget

	// schlafen ist der Wartemechanismus. Als Feld, damit Tests ihn ersetzen
	// können — sonst dauerte jeder Test dieser Datei mehrere Sekunden, und ein
	// langsamer Test wird irgendwann übersprungen.
	schlafen func(ctx context.Context, d time.Duration)
	jetzt    func() time.Time
}

func newIPv64Setter(key string) *ipv64Setter {
	return &ipv64Setter{
		key:   key,
		basis: ipv64API,
		http:  &http.Client{Timeout: 30 * time.Second},
		jetzt: time.Now,
		schlafen: func(ctx context.Context, d time.Duration) {
			t := time.NewTimer(d)
			defer t.Stop()
			select {
			case <-ctx.Done():
			case <-t.C:
			}
		},
	}
}

// ipv64Budget zählt die Anfragen der letzten 24 Stunden.
//
// Warum es das gibt. Der Manager wiederholt einen gescheiterten Bezug stündlich
// (retryInterval). Ein Bezug kostet fünf bis sechs Anfragen. Ein falsch
// eingerichtetes Konto — falscher Key, Domain nicht im Konto, DNS zeigt woanders
// hin — käme damit auf rund 130 Anfragen am Tag und wäre nach einem halben Tag
// gesperrt. Danach ist auch der richtig eingerichtete Zustand nicht mehr
// erreichbar, denn die Sperre trifft das ganze Konto.
//
// Das Budget verhindert nicht den Fehler, sondern seine Ausbreitung: Es hält
// den Bezug an, solange noch Kontingent für den Tag da wäre, und sagt, wann es
// wieder losgeht.
type ipv64Budget struct {
	// zeiten sind die Zeitpunkte der letzten Anfragen, älteste zuerst.
	zeiten []time.Time
}

// nimm bucht eine Anfrage. Der Fehler nennt, wann es weitergeht — „Grenze
// erreicht" ohne den Zeitpunkt befähigt zu keiner Entscheidung.
func (b *ipv64Budget) nimm(jetzt time.Time) error {
	grenze := jetzt.Add(-24 * time.Hour)
	behalten := b.zeiten[:0]
	for _, z := range b.zeiten {
		if z.After(grenze) {
			behalten = append(behalten, z)
		}
	}
	b.zeiten = behalten

	if len(b.zeiten) >= ipv64Tagesbudget {
		frei := b.zeiten[0].Add(24 * time.Hour)
		return fmt.Errorf("ipv64: das Panel hat sein Tagesbudget von %d Anfragen "+
			"aufgebraucht (IPv64 erlaubt in der Standardklasse 64 je 24 Stunden). "+
			"Der nächste Versuch ist ab %s wieder möglich",
			ipv64Tagesbudget, frei.Format("15:04 Uhr"))
	}
	b.zeiten = append(b.zeiten, jetzt)
	return nil
}

func (s *ipv64Setter) setTXT(ctx context.Context, _, record, value string) error {
	zone, praefix, err := s.teile(ctx, record)
	if err != nil {
		return err
	}
	return s.aufruf(ctx, http.MethodPost, url.Values{
		"add_record": {zone},
		"praefix":    {praefix},
		"type":       {"TXT"},
		"content":    {value},
	})
}

func (s *ipv64Setter) removeTXT(ctx context.Context, _, record, value string) error {
	zone, praefix, err := s.teile(ctx, record)
	if err != nil {
		return err
	}
	return s.aufruf(ctx, http.MethodDelete, url.Values{
		"del_record": {zone},
		"praefix":    {praefix},
		"type":       {"TXT"},
		"content":    {value},
	})
}

// teile trennt den vollständigen Namen in Zone und Präfix.
//
// Der Kern dieser Datei. Geraten wird nichts: Die Zonen kommen aus dem Konto,
// und gesucht wird die LÄNGSTE, auf die der Name endet. Die Länge ist die
// entscheidende Zeile — hat jemand sowohl `example.com` als auch
// `sub.example.com` im Konto, gehört der Record in die spezifischere.
func (s *ipv64Setter) teile(ctx context.Context, record string) (zone, praefix string, err error) {
	zonen, err := s.holeZonen(ctx)
	if err != nil {
		return "", "", err
	}
	name := strings.TrimSuffix(record, ".")

	beste := ""
	for _, z := range zonen {
		if name != z && !strings.HasSuffix(name, "."+z) {
			continue
		}
		if len(z) > len(beste) {
			beste = z
		}
	}
	if beste == "" {
		return "", "", fmt.Errorf("ipv64: zu %q gibt es im Konto keine passende Domain "+
			"(vorhanden: %s)", name, nennungOderKeine(zonen))
	}
	if name == beste {
		return beste, "", nil
	}
	return beste, strings.TrimSuffix(name, "."+beste), nil
}

func nennungOderKeine(zonen []string) string {
	if len(zonen) == 0 {
		return "keine"
	}
	return strings.Join(zonen, ", ")
}

// holeZonen liefert die Domains des Kontos, zwischengespeichert.
func (s *ipv64Setter) holeZonen(ctx context.Context) ([]string, error) {
	s.mu.Lock()
	frisch := s.zonen != nil && s.jetzt().Sub(s.geholt) < ipv64ZonenFrist
	zonen := s.zonen
	s.mu.Unlock()
	if frisch {
		return zonen, nil
	}

	roh, err := s.rohAufruf(ctx, http.MethodGet, "?get_domains", nil)
	if err != nil {
		return nil, err
	}
	// Tolerant gegen mehr, als der Parser kennt: Die Antwort trägt neben den
	// Domains auch Kontodaten, und die gehen dieses Modul nichts an.
	var antwort struct {
		Subdomains map[string]json.RawMessage `json:"subdomains"`
	}
	if err := json.Unmarshal(roh, &antwort); err != nil {
		return nil, fmt.Errorf("ipv64: die Domainliste ließ sich nicht lesen: %w", err)
	}
	zonen = make([]string, 0, len(antwort.Subdomains))
	for name := range antwort.Subdomains {
		zonen = append(zonen, strings.ToLower(strings.TrimSuffix(name, ".")))
	}

	s.mu.Lock()
	s.zonen, s.geholt = zonen, s.jetzt()
	s.mu.Unlock()
	return zonen, nil
}

// aufruf schickt eine ändernde Anfrage und wirft die Antwort weg.
func (s *ipv64Setter) aufruf(ctx context.Context, methode string, felder url.Values) error {
	_, err := s.rohAufruf(ctx, methode, "", felder)
	return err
}

// rohAufruf ist die einzige Stelle, an der dieses Modul das Netz berührt —
// und damit die einzige, an der Abstand und Budget zu beachten sind.
func (s *ipv64Setter) rohAufruf(ctx context.Context, methode, abfrage string, felder url.Values) ([]byte, error) {
	if err := s.warteUndBuche(ctx); err != nil {
		return nil, err
	}

	var koerper io.Reader
	if felder != nil {
		koerper = strings.NewReader(felder.Encode())
	}
	req, err := http.NewRequestWithContext(ctx, methode, s.basis+abfrage, koerper)
	if err != nil {
		return nil, err
	}
	req.Header.Set("Authorization", "Bearer "+s.key)
	if felder != nil {
		req.Header.Set("Content-Type", "application/x-www-form-urlencoded")
	}

	res, err := s.http.Do(req)
	if err != nil {
		return nil, fmt.Errorf("ipv64: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	roh, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if res.StatusCode/100 != 2 {
		return nil, s.fehler(res.StatusCode, res.Status, roh)
	}
	return roh, nil
}

// warteUndBuche hält den Mindestabstand ein und bucht die Anfrage im Budget.
//
// Die Reihenfolge ist Absicht: Erst das Budget prüfen, dann warten. Wer ohnehin
// abgelehnt wird, soll nicht vorher zweieinhalb Sekunden dafür stillstehen.
func (s *ipv64Setter) warteUndBuche(ctx context.Context) error {
	s.mu.Lock()
	if err := s.budget.nimm(s.jetzt()); err != nil {
		s.mu.Unlock()
		return err
	}
	// letzter ist der Zeitpunkt der zuletzt VERGEBENEN Anfrage — nicht der
	// zuletzt abgeschickten. Der Unterschied zählt, sobald mehrere Aufrufe
	// dicht hintereinander kommen: Jeder bucht sich seinen eigenen Platz, statt
	// dass alle auf denselben warten und dann gemeinsam losstürmen.
	var pause time.Duration
	jetzt := s.jetzt()
	if fruehestens := s.letzter.Add(ipv64Abstand); jetzt.Before(fruehestens) {
		pause = fruehestens.Sub(jetzt)
	}
	s.letzter = jetzt.Add(pause)
	s.mu.Unlock()

	if pause > 0 {
		s.schlafen(ctx, pause)
	}
	return ctx.Err()
}

// fehler baut die Meldung zu einer abschlägigen Antwort.
//
// Die Ratengrenze bekommt einen eigenen Satz: „429" allein sieht aus wie ein
// vorübergehendes Zucken, und wer das glaubt, versucht es gleich wieder — genau
// das, was hier nicht passieren soll.
func (s *ipv64Setter) fehler(code int, status string, roh []byte) error {
	text := strings.TrimSpace(string(roh))
	if len(text) > 300 {
		text = text[:300] + "…"
	}
	if code == http.StatusTooManyRequests {
		return fmt.Errorf("ipv64 hat die Anfrage wegen seiner Ratengrenze abgelehnt "+
			"(64 Anfragen je 24 Stunden in der Standardklasse): %s", text)
	}
	return fmt.Errorf("ipv64 antwortete mit %s: %s", status, text)
}
