package acme

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"time"
)

// DigitalOcean.
//
// Sehr verbreitet bei VPS-Betreibern, und die schlankeste API der Liste: ein
// Bearer-Token, eine Ressource je Domain, Records darunter.
//
// Zwei Eigenheiten, die den Code prägen:
//
//  1. **Es gibt keine Zonensuche.** DigitalOcean führt „Domains", und die
//     Abfrage nach einem NAMEN gibt es nicht — man bekommt die Liste und sucht
//     darin. Die Liste ist seitenweise, und ein Konto mit vielen Domains hat
//     mehr als eine Seite. Wer nur die erste liest, findet die Domain auf Seite
//     zwei nie und meldet „nicht gefunden" für etwas, das da ist.
//  2. **Record-IDs sind Zahlen**, keine Zeichenketten — anders als bei
//     Cloudflare und Hetzner.

const providerDigitalOcean = "digitalocean"

const digitalOceanAPI = "https://api.digitalocean.com/v2"

// doSeitengroesse ist die Zahl der Einträge je Seite. Das Höchstmaß der API;
// weniger hieße nur mehr Anfragen für dasselbe Ergebnis.
const doSeitengroesse = 200

func init() {
	registriere(Anbieter{
		Name:   providerDigitalOcean,
		Titel:  "DigitalOcean",
		Felder: nil, // genau ein Geheimnis: der Token
		Hinweis: "Personal Access Token mit Schreibrecht (API → Tokens). Die Datei " +
			"enthält nur den Token, oder eine Zeile »api_token = …«.",
		baue: func(z *Zugang) (dnsSetter, error) {
			token := z.Geheimnis("api_token", "token")
			if token == "" {
				return nil, fmt.Errorf("digitalocean: die Zugangsdatei enthält keinen Token")
			}
			return newDigitalOceanSetter(token), nil
		},
	})
}

type digitalOceanSetter struct {
	token string
	basis string
	http  *http.Client
}

func newDigitalOceanSetter(token string) *digitalOceanSetter {
	return &digitalOceanSetter{
		token: token,
		basis: digitalOceanAPI,
		http:  &http.Client{Timeout: 30 * time.Second},
	}
}

func (d *digitalOceanSetter) setTXT(ctx context.Context, domain, record, value string) error {
	zone, err := d.zone(ctx, domain)
	if err != nil {
		return err
	}
	body := map[string]any{
		"type": "TXT",
		"name": relativZu(record, zone),
		"data": value,
		"ttl":  60,
	}
	return d.ruf(ctx, http.MethodPost, "/domains/"+url.PathEscape(zone)+"/records", body, nil)
}

func (d *digitalOceanSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	zone, err := d.zone(ctx, domain)
	if err != nil {
		return err
	}
	name := relativZu(record, zone)

	var antwort struct {
		DomainRecords []struct {
			ID   int    `json:"id"`
			Type string `json:"type"`
			Name string `json:"name"`
			Data string `json:"data"`
		} `json:"domain_records"`
	}
	q := url.Values{"type": {"TXT"}, "name": {name + "." + zone}, "per_page": {strconv.Itoa(doSeitengroesse)}}
	pfad := "/domains/" + url.PathEscape(zone) + "/records?" + q.Encode()
	if err := d.ruf(ctx, http.MethodGet, pfad, nil, &antwort); err != nil {
		return err
	}
	for _, r := range antwort.DomainRecords {
		if r.Type != "TXT" || r.Name != name || !gleicherTXTWert(r.Data, value) {
			continue
		}
		weg := "/domains/" + url.PathEscape(zone) + "/records/" + strconv.Itoa(r.ID)
		if err := d.ruf(ctx, http.MethodDelete, weg, nil, nil); err != nil {
			return err
		}
	}
	return nil
}

// zone sucht die Domain des Kontos, auf die der Name endet.
//
// Über die vollständige Liste und nicht über eine Namenssuche — die gibt es
// bei DigitalOcean nicht. Gesucht wird die LÄNGSTE passende: Führt jemand
// sowohl example.com als auch sub.example.com, gehört der Record in die
// spezifischere.
func (d *digitalOceanSetter) zone(ctx context.Context, domain string) (string, error) {
	name := strings.TrimSuffix(strings.ToLower(domain), ".")

	beste := ""
	seite := 1
	for {
		var antwort struct {
			Domains []struct {
				Name string `json:"name"`
			} `json:"domains"`
			Links struct {
				Pages struct {
					Next string `json:"next"`
				} `json:"pages"`
			} `json:"links"`
		}
		q := url.Values{
			"page":     {strconv.Itoa(seite)},
			"per_page": {strconv.Itoa(doSeitengroesse)},
		}
		if err := d.ruf(ctx, http.MethodGet, "/domains?"+q.Encode(), nil, &antwort); err != nil {
			return "", err
		}
		for _, dom := range antwort.Domains {
			z := strings.TrimSuffix(strings.ToLower(dom.Name), ".")
			if name != z && !strings.HasSuffix(name, "."+z) {
				continue
			}
			if len(z) > len(beste) {
				beste = z
			}
		}
		// Weiterblättern, solange die API eine nächste Seite nennt. Ohne das
		// fände ein Konto mit mehr als 200 Domains seine eigene nicht.
		if antwort.Links.Pages.Next == "" {
			break
		}
		seite++
	}

	if beste == "" {
		return "", fmt.Errorf("digitalocean: keine Domain für %q im Konto gefunden", domain)
	}
	return beste, nil
}

func (d *digitalOceanSetter) ruf(ctx context.Context, methode, pfad string, koerper, ziel any) error {
	var leib io.Reader
	if koerper != nil {
		b, err := json.Marshal(koerper)
		if err != nil {
			return err
		}
		leib = bytes.NewReader(b)
	}
	req, err := http.NewRequestWithContext(ctx, methode, d.basis+pfad, leib)
	if err != nil {
		return err
	}
	req.Header.Set("Authorization", "Bearer "+d.token)
	if koerper != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	res, err := d.http.Do(req)
	if err != nil {
		return fmt.Errorf("digitalocean: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	roh, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if res.StatusCode/100 != 2 {
		return fmt.Errorf("digitalocean antwortete mit %s: %s", res.Status, gekuerzt(roh))
	}
	if ziel == nil {
		return nil
	}
	if err := json.Unmarshal(roh, ziel); err != nil {
		return fmt.Errorf("digitalocean: Antwort nicht lesbar: %w", err)
	}
	return nil
}
