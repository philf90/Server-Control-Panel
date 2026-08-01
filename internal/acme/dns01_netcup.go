package acme

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"sync"
	"time"
)

// netcup (CCP-API).
//
// Verbreitet bei günstigen deutschen VPS — und die einzige API dieser Liste
// mit einer SITZUNG. Das prägt den ganzen Aufbau:
//
//	login  → Sitzungsschlüssel
//	…Aufrufe mit Kundennummer + API-Schlüssel + Sitzungsschlüssel…
//	logout
//
// Drei Dinge folgen daraus, und alle drei sind der Grund, warum diese Datei
// länger ist als Hetzner und DigitalOcean zusammen:
//
//  1. **Der Sitzungsschlüssel wird wiederverwendet.** Ein Login je Record wäre
//     bei einem Wildcard-Bezug vier Anmeldungen für vier Records. netcup
//     begrenzt die Zahl der Anmeldungen, und mehr davon macht nichts besser.
//  2. **Abgemeldet wird auch im Fehlerfall.** Eine offene Sitzung, die
//     ausläuft, ist kein Drama; sie bewusst liegen zu lassen, wenn man weiß,
//     dass man fertig ist, aber unordentlich.
//  3. **Records werden als GANZE LISTE geschrieben, nicht einzeln.** Das ist
//     die gefährliche Eigenheit: `updateDnsRecords` nimmt eine Liste, und was
//     nicht darin steht, bleibt zwar stehen — aber wer die Liste falsch
//     zusammensetzt, kann fremde Records überschreiben. Deshalb wird hier
//     IMMER nur genau ein Record geschickt, und zum Löschen derselbe mit
//     `deleterecord: true`. Ein „alle Records holen, ändern, zurückschreiben"
//     wäre der kürzere Weg und der, bei dem ein Fehler die Zone kostet.
//
// Alle Aufrufe gehen als POST an denselben Endpunkt; das Kommando steht im
// Körper unter „action".

const providerNetcup = "netcup"

const netcupAPI = "https://ccp.netcup.net/run/webservice/servers/endpoint.php?JSON"

func init() {
	registriere(Anbieter{
		Name:   providerNetcup,
		Titel:  "netcup (CCP)",
		Felder: []string{"customer", "apikey", "apipassword"},
		Hinweis: "Kundennummer sowie API-Schlüssel und API-Passwort aus dem netcup-CCP " +
			"(Stammdaten → API). Drei Zeilen: »customer = …«, »apikey = …«, " +
			"»apipassword = …«.",
		baue: func(z *Zugang) (dnsSetter, error) {
			return newNetcupSetter(
				z.Wert("customer"), z.Wert("apikey"), z.Wert("apipassword"),
			), nil
		},
	})
}

type netcupSetter struct {
	kunde    string
	apikey   string
	apipass  string
	basis    string
	http     *http.Client
	mu       sync.Mutex
	sitzung  string
	angemeld time.Time
}

// netcupSitzungsfrist ist die Zeit, nach der eine Sitzung neu geholt wird.
//
// netcup nennt keine verbindliche Dauer. Zehn Minuten sind kürzer als jede
// gemeldete Lebensdauer und länger als jeder Bezug — die Sitzung überdauert
// also einen Bezug und wird zwischen zwei Erneuerungen nicht mitgeschleppt.
const netcupSitzungsfrist = 10 * time.Minute

func newNetcupSetter(kunde, apikey, apipass string) *netcupSetter {
	return &netcupSetter{
		kunde:   kunde,
		apikey:  apikey,
		apipass: apipass,
		basis:   netcupAPI,
		http:    &http.Client{Timeout: 30 * time.Second},
	}
}

func (n *netcupSetter) setTXT(ctx context.Context, domain, record, value string) error {
	return n.schreibe(ctx, domain, record, value, false)
}

func (n *netcupSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	return n.schreibe(ctx, domain, record, value, true)
}

// schreibe legt einen Record an oder entfernt ihn.
//
// Zum Löschen wird derselbe Record geschickt, nur mit deleterecord: true — und
// mit seiner ID, die vorher gesucht wird. Ohne ID löscht netcup nichts, und
// ohne den WERT im Vergleich löschte man womöglich den falschen: Bei einem
// Wildcard-Zertifikat stehen zwei TXT-Records unter demselben Namen.
func (n *netcupSetter) schreibe(ctx context.Context, domain, record, value string, loeschen bool) error {
	zone, err := n.zone(ctx, domain)
	if err != nil {
		return err
	}
	eintrag := map[string]any{
		"hostname":    relativZu(record, zone),
		"type":        "TXT",
		"destination": value,
	}
	if loeschen {
		id, err := n.findeID(ctx, zone, relativZu(record, zone), value)
		if err != nil {
			return err
		}
		if id == "" {
			// Nichts zu tun. Kein Fehler: cleanup läuft auch auf dem Weg heraus
			// aus einem gescheiterten Bezug, und dann gibt es nichts zu löschen.
			return nil
		}
		eintrag["id"] = id
		eintrag["deleterecord"] = true
	}

	return n.ruf(ctx, "updateDnsRecords", map[string]any{
		"domainname":      zone,
		"dnsrecordset":    map[string]any{"dnsrecords": []any{eintrag}},
		"clientrequestid": "",
	}, nil)
}

// findeID sucht den eigenen Record. Nach Name UND Wert — siehe schreibe.
func (n *netcupSetter) findeID(ctx context.Context, zone, name, value string) (string, error) {
	var antwort struct {
		DNSRecords []struct {
			ID          string `json:"id"`
			Hostname    string `json:"hostname"`
			Type        string `json:"type"`
			Destination string `json:"destination"`
		} `json:"dnsrecords"`
	}
	err := n.ruf(ctx, "infoDnsRecords", map[string]any{"domainname": zone}, &antwort)
	if err != nil {
		// Eine Zone ohne Records ist bei netcup ein Fehler und kein leeres
		// Ergebnis. Für das Aufräumen ist das dasselbe: Es gibt nichts zu tun.
		if strings.Contains(err.Error(), "not find") || strings.Contains(err.Error(), "5029") {
			return "", nil
		}
		return "", err
	}
	for _, r := range antwort.DNSRecords {
		if r.Type == "TXT" && strings.EqualFold(r.Hostname, name) && gleicherTXTWert(r.Destination, value) {
			return r.ID, nil
		}
	}
	return "", nil
}

// zone sucht die Domain des Kontos, auf die der Name endet.
//
// netcup führt Domains ohne eine Suchfunktion nach Namen; abgefragt wird die
// Liste. Gesucht wird die längste passende — dieselbe Regel wie überall.
func (n *netcupSetter) zone(ctx context.Context, domain string) (string, error) {
	var antwort struct {
		Domains []struct {
			DomainName string `json:"domainname"`
		} `json:"domains"`
	}
	if err := n.ruf(ctx, "listallDomains", map[string]any{}, &antwort); err != nil {
		return "", err
	}
	name := strings.TrimSuffix(strings.ToLower(domain), ".")

	beste := ""
	for _, d := range antwort.Domains {
		z := strings.TrimSuffix(strings.ToLower(d.DomainName), ".")
		if name != z && !strings.HasSuffix(name, "."+z) {
			continue
		}
		if len(z) > len(beste) {
			beste = z
		}
	}
	if beste == "" {
		return "", fmt.Errorf("netcup: keine Domain für %q im Konto gefunden", domain)
	}
	return beste, nil
}

// ruf schickt ein Kommando mit gültiger Sitzung.
func (n *netcupSetter) ruf(ctx context.Context, aktion string, param map[string]any, ziel any) error {
	sitzung, err := n.holeSitzung(ctx)
	if err != nil {
		return err
	}
	voll := map[string]any{
		"customernumber":  n.kunde,
		"apikey":          n.apikey,
		"apisessionid":    sitzung,
		"clientrequestid": "",
	}
	for k, v := range param {
		voll[k] = v
	}
	return n.roh(ctx, aktion, voll, ziel)
}

// holeSitzung meldet an, wenn nötig.
func (n *netcupSetter) holeSitzung(ctx context.Context) (string, error) {
	n.mu.Lock()
	if n.sitzung != "" && time.Since(n.angemeld) < netcupSitzungsfrist {
		s := n.sitzung
		n.mu.Unlock()
		return s, nil
	}
	n.mu.Unlock()

	var antwort struct {
		APISessionID string `json:"apisessionid"`
	}
	err := n.roh(ctx, "login", map[string]any{
		"customernumber": n.kunde,
		"apikey":         n.apikey,
		"apipassword":    n.apipass,
	}, &antwort)
	if err != nil {
		return "", err
	}
	if antwort.APISessionID == "" {
		return "", fmt.Errorf("netcup: die Anmeldung lieferte keine Sitzung")
	}

	n.mu.Lock()
	n.sitzung, n.angemeld = antwort.APISessionID, time.Now()
	n.mu.Unlock()
	return antwort.APISessionID, nil
}

// roh ist der eigentliche Aufruf.
//
// netcup antwortet IMMER mit HTTP 200 — auch bei einem Fehler. Der Zustand
// steht im Körper unter „status". Wer nur den HTTP-Code prüft, hält jeden
// Fehlschlag für einen Erfolg, und der Bezug scheitert erst später an einer
// Stelle, die nichts damit zu tun hat.
func (n *netcupSetter) roh(ctx context.Context, aktion string, param map[string]any, ziel any) error {
	b, err := json.Marshal(map[string]any{"action": aktion, "param": param})
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, n.basis, bytes.NewReader(b))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")

	res, err := n.http.Do(req)
	if err != nil {
		return fmt.Errorf("netcup: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	roh, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if res.StatusCode/100 != 2 {
		return fmt.Errorf("netcup antwortete mit %s: %s", res.Status, gekuerzt(roh))
	}

	var huelle struct {
		Status       string          `json:"status"`
		StatusCode   int             `json:"statuscode"`
		ShortMessage string          `json:"shortmessage"`
		LongMessage  string          `json:"longmessage"`
		ResponseData json.RawMessage `json:"responsedata"`
	}
	if err := json.Unmarshal(roh, &huelle); err != nil {
		return fmt.Errorf("netcup: Antwort nicht lesbar: %w", err)
	}
	if !strings.EqualFold(huelle.Status, "success") {
		meldung := huelle.LongMessage
		if meldung == "" {
			meldung = huelle.ShortMessage
		}
		return fmt.Errorf("netcup lehnte %q ab (%d): %s", aktion, huelle.StatusCode, meldung)
	}
	if ziel == nil || len(huelle.ResponseData) == 0 {
		return nil
	}
	if err := json.Unmarshal(huelle.ResponseData, ziel); err != nil {
		return fmt.Errorf("netcup: Nutzdaten nicht lesbar: %w", err)
	}
	return nil
}
