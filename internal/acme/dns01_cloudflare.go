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

const cloudflareAPI = "https://api.cloudflare.com/client/v4"

// cloudflareSetter setzt den TXT-Record über die Cloudflare-API — mit reinem
// net/http, ohne SDK. Der Token kommt aus einer Datei und steht nicht im Klartext
// in der Konfiguration.
type cloudflareSetter struct {
	token   string
	baseURL string
	http    *http.Client
}

func newCloudflareSetter(token string) *cloudflareSetter {
	return &cloudflareSetter{
		token:   token,
		baseURL: cloudflareAPI,
		http:    &http.Client{Timeout: 30 * time.Second},
	}
}

func (c *cloudflareSetter) setTXT(ctx context.Context, domain, record, value string) error {
	zoneID, err := c.zoneID(ctx, domain)
	if err != nil {
		return err
	}
	body := map[string]any{"type": "TXT", "name": record, "content": value, "ttl": 60}
	return c.do(ctx, http.MethodPost, "/zones/"+zoneID+"/dns_records", body, nil)
}

func (c *cloudflareSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	zoneID, err := c.zoneID(ctx, domain)
	if err != nil {
		return err
	}
	records, err := c.findTXT(ctx, zoneID, record)
	if err != nil {
		return err
	}
	for _, r := range records {
		if r.Content != value {
			continue
		}
		if err := c.do(ctx, http.MethodDelete, "/zones/"+zoneID+"/dns_records/"+r.ID, nil, nil); err != nil {
			return err
		}
	}
	return nil
}

// zoneID sucht die Zone zur Domain. Cloudflare verwaltet die Zone (example.com),
// nicht den Hostnamen (panel.example.com) — deshalb von spezifisch nach
// allgemein probieren, bis eine Zone antwortet.
func (c *cloudflareSetter) zoneID(ctx context.Context, domain string) (string, error) {
	labels := strings.Split(strings.TrimSuffix(domain, "."), ".")
	for i := 0; i+1 < len(labels); i++ {
		candidate := strings.Join(labels[i:], ".")
		zones, err := c.listZones(ctx, candidate)
		if err != nil {
			return "", err
		}
		if len(zones) > 0 {
			return zones[0].ID, nil
		}
	}
	return "", fmt.Errorf("keine Cloudflare-Zone für %q gefunden", domain)
}

type cfZone struct {
	ID   string `json:"id"`
	Name string `json:"name"`
}

type cfRecord struct {
	ID      string `json:"id"`
	Name    string `json:"name"`
	Content string `json:"content"`
}

func (c *cloudflareSetter) listZones(ctx context.Context, name string) ([]cfZone, error) {
	q := url.Values{"name": {name}}
	var zones []cfZone
	err := c.do(ctx, http.MethodGet, "/zones?"+q.Encode(), nil, &zones)
	return zones, err
}

func (c *cloudflareSetter) findTXT(ctx context.Context, zoneID, name string) ([]cfRecord, error) {
	q := url.Values{"type": {"TXT"}, "name": {name}}
	var records []cfRecord
	err := c.do(ctx, http.MethodGet, "/zones/"+zoneID+"/dns_records?"+q.Encode(), nil, &records)
	return records, err
}

type cfEnvelope struct {
	Success bool            `json:"success"`
	Errors  []cfError       `json:"errors"`
	Result  json.RawMessage `json:"result"`
}

type cfError struct {
	Code    int    `json:"code"`
	Message string `json:"message"`
}

func (c *cloudflareSetter) do(ctx context.Context, method, path string, body, out any) error {
	var buf io.Reader
	if body != nil {
		b, err := json.Marshal(body)
		if err != nil {
			return err
		}
		buf = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, method, c.baseURL+path, buf)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+c.token)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return err
	}
	defer func() { _ = resp.Body.Close() }()

	var env cfEnvelope
	if err := json.NewDecoder(resp.Body).Decode(&env); err != nil {
		return fmt.Errorf("cloudflare-antwort (HTTP %d) nicht lesbar: %w", resp.StatusCode, err)
	}
	if !env.Success {
		return fmt.Errorf("cloudflare-fehler (HTTP %d): %s", resp.StatusCode, joinCFErrors(env.Errors))
	}
	if out != nil && len(env.Result) > 0 {
		return json.Unmarshal(env.Result, out)
	}
	return nil
}

func joinCFErrors(errs []cfError) string {
	if len(errs) == 0 {
		return "ohne nähere Angabe"
	}
	parts := make([]string, 0, len(errs))
	for _, e := range errs {
		parts = append(parts, fmt.Sprintf("%d %s", e.Code, e.Message))
	}
	return strings.Join(parts, "; ")
}
