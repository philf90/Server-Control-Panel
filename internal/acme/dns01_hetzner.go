package acme

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"
)

// Hetzner DNS.
//
// Der Anbieter mit der größten Trefferquote bei deutschen VPS. Die API ist eine
// gewöhnliche REST-Schnittstelle mit einem Token in der Kopfzeile.
//
// Wie bei Cloudflare und IPv64 gilt: Die Zone wird ABGEFRAGT und nicht aus der
// Labelanzahl geraten. Hetzner verwaltet `example.com`, nicht
// `_acme-challenge.example.com` — und wer die Zone aus den letzten zwei Labels
// ableitet, liegt bei `example.co.uk` und bei delegierten Subzonen falsch.

const providerHetzner = "hetzner"

const hetznerAPI = "https://dns.hetzner.com/api/v1"

func init() {
	registriere(Anbieter{
		Name:   providerHetzner,
		Titel:  "Hetzner DNS",
		Felder: nil, // genau ein Geheimnis: der API-Token
		Hinweis: "API-Token aus der Hetzner-DNS-Konsole (dns.hetzner.com → API-Tokens). " +
			"Die Datei enthält nur den Token, oder eine Zeile »api_token = …«.",
		baue: func(z *Zugang) (dnsSetter, error) {
			token := z.Geheimnis("api_token", "token")
			if token == "" {
				return nil, fmt.Errorf("hetzner: die Zugangsdatei enthält keinen Token")
			}
			return newHetznerSetter(token), nil
		},
	})
}

type hetznerSetter struct {
	token string
	basis string
	http  *http.Client
}

func newHetznerSetter(token string) *hetznerSetter {
	return &hetznerSetter{
		token: token,
		basis: hetznerAPI,
		http:  &http.Client{Timeout: 30 * time.Second},
	}
}

func (h *hetznerSetter) setTXT(ctx context.Context, domain, record, value string) error {
	zone, err := h.zone(ctx, domain)
	if err != nil {
		return err
	}
	// Hetzner will den Namen RELATIV zur Zone: "_acme-challenge" und nicht
	// "_acme-challenge.example.com". Ein absoluter Name landet sonst als
	// "_acme-challenge.example.com.example.com" — der Record entsteht, und die
	// Prüfung findet ihn trotzdem nie.
	body := map[string]any{
		"zone_id": zone.ID,
		"type":    "TXT",
		"name":    relativZu(record, zone.Name),
		"value":   value,
		"ttl":     60,
	}
	return h.ruf(ctx, http.MethodPost, "/records", body, nil)
}

func (h *hetznerSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	zone, err := h.zone(ctx, domain)
	if err != nil {
		return err
	}
	name := relativZu(record, zone.Name)

	var antwort struct {
		Records []struct {
			ID    string `json:"id"`
			Name  string `json:"name"`
			Type  string `json:"type"`
			Value string `json:"value"`
		} `json:"records"`
	}
	q := url.Values{"zone_id": {zone.ID}}
	if err := h.ruf(ctx, http.MethodGet, "/records?"+q.Encode(), nil, &antwort); err != nil {
		return err
	}
	for _, r := range antwort.Records {
		// Nach Name UND Wert: Bei einem Wildcard-Zertifikat stehen zwei
		// TXT-Records unter demselben Namen, und der zweite gehört noch zur
		// laufenden Prüfung.
		if r.Type != "TXT" || r.Name != name || !gleicherTXTWert(r.Value, value) {
			continue
		}
		if err := h.ruf(ctx, http.MethodDelete, "/records/"+r.ID, nil, nil); err != nil {
			return err
		}
	}
	return nil
}

type hetznerZone struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

// zone sucht die Zone zur Domain — von spezifisch nach allgemein, bis eine
// antwortet. Dieselbe Methode wie bei Cloudflare, und aus demselben Grund:
// Geraten wird nichts.
func (h *hetznerSetter) zone(ctx context.Context, domain string) (hetznerZone, error) {
	labels := strings.Split(strings.TrimSuffix(domain, "."), ".")
	for i := 0; i+1 < len(labels); i++ {
		kandidat := strings.Join(labels[i:], ".")
		var antwort struct {
			Zones []hetznerZone `json:"zones"`
		}
		q := url.Values{"name": {kandidat}}
		if err := h.ruf(ctx, http.MethodGet, "/zones?"+q.Encode(), nil, &antwort); err != nil {
			return hetznerZone{}, err
		}
		for _, z := range antwort.Zones {
			if strings.EqualFold(z.Name, kandidat) {
				return z, nil
			}
		}
	}
	return hetznerZone{}, fmt.Errorf("hetzner: keine Zone für %q im Konto gefunden", domain)
}

func (h *hetznerSetter) ruf(ctx context.Context, methode, pfad string, koerper, ziel any) error {
	var leib io.Reader
	if koerper != nil {
		b, err := json.Marshal(koerper)
		if err != nil {
			return err
		}
		leib = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, methode, h.basis+pfad, leib)
	if err != nil {
		return err
	}
	req.Header.Set("Auth-API-Token", h.token)
	if koerper != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	res, err := h.http.Do(req)
	if err != nil {
		return fmt.Errorf("hetzner: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	roh, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if res.StatusCode/100 != 2 {
		return fmt.Errorf("hetzner antwortete mit %s: %s", res.Status, gekuerzt(roh))
	}
	if ziel == nil {
		return nil
	}
	if err := json.Unmarshal(roh, ziel); err != nil {
		return fmt.Errorf("hetzner: Antwort nicht lesbar: %w", err)
	}
	return nil
}
