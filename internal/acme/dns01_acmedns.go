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

// acme-dns: die Delegation.
//
// Der Anbieter, der als einziger in dieser Liste JEDEN DNS-Anbieter abdeckt —
// und der einzige, bei dem das Panel kein Geheimnis für die eigentliche Zone
// hält. Beides hängt zusammen.
//
// Wie es funktioniert. Der Betreiber legt bei einer acme-dns-Instanz ein Konto
// an und bekommt eine zufällige Subdomain wie
// `a1b2c3d4-….auth.example.org`. Bei seinem echten DNS-Anbieter trägt er
// EINMAL von Hand einen CNAME ein:
//
//	_acme-challenge.example.com. CNAME a1b2c3d4-….auth.example.org.
//
// Danach fragt Let's Encrypt weiter `_acme-challenge.example.com` ab, landet
// über den CNAME bei acme-dns, und das Panel setzt den TXT-Record dort. Es
// braucht dafür Zugangsdaten für **diese eine Subdomain** — und keine für die
// Zone example.com.
//
// Warum das der beste Weg der Liste ist. Jeder andere Anbieter verlangt einen
// API-Token, mit dem sich beliebige Records der Zone ändern lassen. Wer den
// Token hat, kann Mail umleiten und sich Zertifikate für jeden Namen der Zone
// ausstellen lassen. Dieses Panel läuft als root und ist aus dem Netz
// erreichbar; ein solcher Token darauf ist eine Erweiterung des Schadens, den
// eine Lücke anrichtet. Bei acme-dns beschränkt sich der Schaden auf die
// Fähigkeit, TXT-Records in einer Wegwerf-Subdomain zu setzen.
//
// Und deshalb steht er als erster: Er ist zugleich der kleinste — eine einzige
// HTTP-Anfrage, kein Zonensuchen, kein Signieren.
//
// Die Gegenrechnung, die dazugehört: Der Betreiber braucht eine
// acme-dns-Instanz (die öffentliche unter auth.acme-dns.io oder eine eigene)
// und muss den CNAME einmal von Hand setzen. Dafür ist es der einzige Weg, bei
// dem ein Einbruch ins Panel die Domain nicht mitnimmt.

const providerAcmeDNS = "acme-dns"

func init() {
	registriere(Anbieter{
		Name:   providerAcmeDNS,
		Titel:  "acme-dns (Delegation über CNAME)",
		Felder: []string{"server", "username", "password", "subdomain"},
		Hinweis: "Registrierung bei einer acme-dns-Instanz (»curl -X POST https://…/register«). " +
			"Der CNAME _acme-challenge.<domain> → <subdomain>.<acme-dns-domain> wird einmal " +
			"von Hand gesetzt; danach hält das Panel KEINE Zugangsdaten für die eigene Zone.",
		baue: func(z *Zugang) (dnsSetter, error) {
			basis, err := pruefeAcmeDNSServer(z.Wert("server"))
			if err != nil {
				return nil, err
			}
			return &acmeDNSSetter{
				basis:     basis,
				username:  z.Wert("username"),
				password:  z.Wert("password"),
				subdomain: z.Wert("subdomain"),
				http:      &http.Client{Timeout: 30 * time.Second},
			}, nil
		},
	})
}

// pruefeAcmeDNSServer nimmt die Adresse der Instanz.
//
// Nur https: Über diese Verbindung gehen Zugangsdaten. Eine acme-dns-Instanz
// über http zu erlauben hieße, sie bei jedem Bezug im Klartext über das Netz zu
// schicken — und der Betreiber merkte es nie.
func pruefeAcmeDNSServer(roh string) (string, error) {
	if roh == "" {
		return "", fmt.Errorf("acme-dns: »server« fehlt")
	}
	u, err := url.Parse(strings.TrimSuffix(roh, "/"))
	if err != nil {
		return "", fmt.Errorf("acme-dns: »server« ist keine Adresse: %w", err)
	}
	if u.Scheme != "https" || u.Host == "" {
		return "", fmt.Errorf("acme-dns: »server« muss eine https-Adresse sein, ist %q", roh)
	}
	return u.String(), nil
}

// acmeDNSSetter spricht mit einer acme-dns-Instanz.
type acmeDNSSetter struct {
	basis     string
	username  string
	password  string
	subdomain string
	http      *http.Client
}

// setTXT trägt den Wert ein.
//
// Der Name des Records geht NICHT mit: acme-dns kennt genau eine Subdomain je
// Konto und setzt den TXT-Record dort. Das ist der Grund, warum ein Konto je
// Domain nötig ist — und zugleich der Grund, warum ein gestohlenes Konto nur
// diese eine Subdomain betrifft.
func (a *acmeDNSSetter) setTXT(ctx context.Context, _, _, value string) error {
	return a.sende(ctx, value)
}

// removeTXT tut nichts — und das ist kein Versäumnis.
//
// acme-dns hält je Subdomain zwei TXT-Werte und überschreibt den ältesten beim
// nächsten Update. Es gibt keinen Endpunkt zum Löschen, und es braucht keinen:
// Der Record steht in einer Wegwerf-Subdomain, die nichts anderes trägt. Ein
// Fehler daraus zu machen hieße, jeden Bezug mit einer Warnung zu beenden, die
// nichts bedeutet.
func (a *acmeDNSSetter) removeTXT(context.Context, string, string, string) error {
	return nil
}

func (a *acmeDNSSetter) sende(ctx context.Context, value string) error {
	body, err := json.Marshal(map[string]string{
		"subdomain": a.subdomain,
		"txt":       value,
	})
	if err != nil {
		return err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodPost, a.basis+"/update", bytes.NewReader(body))
	if err != nil {
		return err
	}
	req.Header.Set("Content-Type", "application/json")
	req.Header.Set("X-Api-User", a.username)
	req.Header.Set("X-Api-Key", a.password)

	res, err := a.http.Do(req)
	if err != nil {
		return fmt.Errorf("acme-dns: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	if res.StatusCode/100 != 2 {
		// Die Antwort mitnehmen, aber begrenzt: acme-dns antwortet mit einer
		// kurzen JSON-Meldung, ein Proxy davor womöglich mit einer ganzen
		// Fehlerseite.
		meldung, _ := io.ReadAll(io.LimitReader(res.Body, 512))
		return fmt.Errorf("acme-dns antwortete mit %s: %s",
			res.Status, strings.TrimSpace(string(meldung)))
	}
	return nil
}
