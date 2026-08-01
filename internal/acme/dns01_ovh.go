package acme

import (
	"bytes"
	"context"
	"crypto/sha1" //nolint:gosec // von der OVH-API vorgeschrieben, siehe signatur()
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strconv"
	"strings"
	"sync"
	"time"
)

// OVH.
//
// Der größte europäische Hoster und der aufwendigste Eintrag dieser Liste. Der
// Grund ist die Authentifizierung: OVH signiert jeden Aufruf, statt einen Token
// mitzuschicken.
//
//	X-Ovh-Signature: "$1$" + SHA1(secret + "+" + consumerKey + "+" + METHODE +
//	                              "+" + URL + "+" + Körper + "+" + Zeitstempel)
//
// Drei Dinge daran gehören benannt, weil sie sonst wie Nachlässigkeit aussehen:
//
//  1. **SHA-1 ist hier vorgeschrieben.** Das ist keine Wahl dieses Projekts. Die
//     API verlangt es, und ein anderer Hash ergäbe schlicht eine ungültige
//     Signatur. Es ist auch kein Vertraulichkeitsproblem: Die Signatur bestätigt
//     einen Aufruf über TLS, sie schützt nichts Gespeichertes.
//  2. **Der Zeitstempel kommt vom SERVER**, nicht von der eigenen Uhr. OVH
//     lehnt Aufrufe ab, deren Zeitstempel zu weit abweicht, und die Uhr eines
//     frisch aufgesetzten VPS geht gerne daneben. Der Versatz wird einmal
//     ermittelt und danach auf die eigene Uhr gerechnet — eine Abfrage je
//     Aufruf wäre die doppelte Anzahl Anfragen für eine Zahl, die sich nicht
//     ändert.
//  3. **Der Endpunkt ist Teil der Zugangsdaten.** OVH betreibt getrennte Welten
//     (ovh-eu, ovh-ca, kimsufi, soyoustart …), und ein Schlüssel gilt in genau
//     einer. Ihn in die falsche zu schicken ergibt eine unverständliche
//     Fehlermeldung — deshalb steht der Endpunkt in der Zugangsdatei und wird
//     gegen eine feste Liste geprüft.

const providerOVH = "ovh"

// ovhEndpunkte sind die Welten, in denen ein Schlüssel gelten kann.
//
// Eine feste Liste und kein freies Feld: Eine beliebige Adresse hier wäre die
// Stelle, an der die Zugangsdaten dieses Kontos an einen fremden Server gingen.
var ovhEndpunkte = map[string]string{
	"ovh-eu":        "https://eu.api.ovh.com/1.0",
	"ovh-ca":        "https://ca.api.ovh.com/1.0",
	"ovh-us":        "https://api.us.ovhcloud.com/1.0",
	"kimsufi-eu":    "https://eu.api.kimsufi.com/1.0",
	"kimsufi-ca":    "https://ca.api.kimsufi.com/1.0",
	"soyoustart-eu": "https://eu.api.soyoustart.com/1.0",
	"soyoustart-ca": "https://ca.api.soyoustart.com/1.0",
}

func init() {
	registriere(Anbieter{
		Name:   providerOVH,
		Titel:  "OVH",
		Felder: []string{"application_key", "application_secret", "consumer_key"},
		Hinweis: "Schlüssel aus der OVH-API-Konsole (api.ovh.com/createToken) mit Recht auf " +
			"GET/POST/DELETE unter /domain/zone/*. Vier Zeilen: »endpoint = ovh-eu«, " +
			"»application_key = …«, »application_secret = …«, »consumer_key = …«.",
		baue: func(z *Zugang) (dnsSetter, error) {
			endpunkt := z.Wert("endpoint")
			if endpunkt == "" {
				endpunkt = "ovh-eu"
			}
			basis, ok := ovhEndpunkte[strings.ToLower(endpunkt)]
			if !ok {
				return nil, fmt.Errorf("ovh: unbekannter »endpoint« %q (bekannt: %s)",
					endpunkt, strings.Join(ovhEndpunktnamen(), ", "))
			}
			return newOVHSetter(basis,
				z.Wert("application_key"),
				z.Wert("application_secret"),
				z.Wert("consumer_key"),
			), nil
		},
	})
}

func ovhEndpunktnamen() []string {
	aus := make([]string, 0, len(ovhEndpunkte))
	for name := range ovhEndpunkte {
		aus = append(aus, name)
	}
	// Sortiert, damit die Fehlermeldung bei jedem Aufruf gleich aussieht.
	for i := range aus {
		for j := i + 1; j < len(aus); j++ {
			if aus[j] < aus[i] {
				aus[i], aus[j] = aus[j], aus[i]
			}
		}
	}
	return aus
}

type ovhSetter struct {
	basis    string
	appKey   string
	appGeh   string
	consumer string
	http     *http.Client

	mu      sync.Mutex
	versatz time.Duration
	geeicht bool

	jetzt func() time.Time
}

func newOVHSetter(basis, appKey, appGeheimnis, consumer string) *ovhSetter {
	return &ovhSetter{
		basis:    basis,
		appKey:   appKey,
		appGeh:   appGeheimnis,
		consumer: consumer,
		http:     &http.Client{Timeout: 30 * time.Second},
		jetzt:    time.Now,
	}
}

func (o *ovhSetter) setTXT(ctx context.Context, domain, record, value string) error {
	zone, err := o.zone(ctx, domain)
	if err != nil {
		return err
	}
	body := map[string]any{
		"fieldType": "TXT",
		"subDomain": relativZu(record, zone),
		"target":    value,
		"ttl":       60,
	}
	if err := o.ruf(ctx, http.MethodPost, "/domain/zone/"+zone+"/record", body, nil); err != nil {
		return err
	}
	// Ohne refresh bleibt der Record in der API stehen und geht nie ins DNS.
	// Das ist die Eigenheit, die bei OVH am meisten Zeit kostet: Der Aufruf
	// glückt, der Record steht in der Oberfläche, und die Prüfung findet
	// trotzdem nichts.
	return o.ruf(ctx, http.MethodPost, "/domain/zone/"+zone+"/refresh", nil, nil)
}

func (o *ovhSetter) removeTXT(ctx context.Context, domain, record, value string) error {
	zone, err := o.zone(ctx, domain)
	if err != nil {
		return err
	}
	name := relativZu(record, zone)

	// OVH sucht nach Feldtyp und Subdomain und gibt IDs zurück. Der Wert steht
	// nicht in der Suche — er muss je Kandidat geholt werden.
	var ids []int64
	pfad := "/domain/zone/" + zone + "/record?fieldType=TXT&subDomain=" + name
	if err := o.ruf(ctx, http.MethodGet, pfad, nil, &ids); err != nil {
		return err
	}
	var entfernt bool
	for _, id := range ids {
		var eintrag struct {
			Target string `json:"target"`
		}
		weg := "/domain/zone/" + zone + "/record/" + strconv.FormatInt(id, 10)
		if err := o.ruf(ctx, http.MethodGet, weg, nil, &eintrag); err != nil {
			return err
		}
		// Nach dem WERT: Bei einem Wildcard-Zertifikat stehen zwei TXT-Records
		// unter demselben Namen, und der zweite gehört noch zur laufenden
		// Prüfung.
		if !gleicherTXTWert(eintrag.Target, value) {
			continue
		}
		if err := o.ruf(ctx, http.MethodDelete, weg, nil, nil); err != nil {
			return err
		}
		entfernt = true
	}
	if !entfernt {
		return nil
	}
	return o.ruf(ctx, http.MethodPost, "/domain/zone/"+zone+"/refresh", nil, nil)
}

// zone sucht die Zone des Kontos, auf die der Name endet.
func (o *ovhSetter) zone(ctx context.Context, domain string) (string, error) {
	var zonen []string
	if err := o.ruf(ctx, http.MethodGet, "/domain/zone", nil, &zonen); err != nil {
		return "", err
	}
	name := strings.TrimSuffix(strings.ToLower(domain), ".")

	beste := ""
	for _, z := range zonen {
		z = strings.TrimSuffix(strings.ToLower(z), ".")
		if name != z && !strings.HasSuffix(name, "."+z) {
			continue
		}
		if len(z) > len(beste) {
			beste = z
		}
	}
	if beste == "" {
		return "", fmt.Errorf("ovh: keine Zone für %q im Konto gefunden", domain)
	}
	return beste, nil
}

func (o *ovhSetter) ruf(ctx context.Context, methode, pfad string, koerper, ziel any) error {
	var leib string
	if koerper != nil {
		b, err := json.Marshal(koerper)
		if err != nil {
			return err
		}
		leib = string(b)
	}
	adresse := o.basis + pfad

	stempel, err := o.zeitstempel(ctx)
	if err != nil {
		return err
	}

	req, err := http.NewRequestWithContext(ctx, methode, adresse, bytes.NewReader([]byte(leib)))
	if err != nil {
		return err
	}
	req.Header.Set("X-Ovh-Application", o.appKey)
	req.Header.Set("X-Ovh-Consumer", o.consumer)
	req.Header.Set("X-Ovh-Timestamp", stempel)
	req.Header.Set("X-Ovh-Signature", o.signatur(methode, adresse, leib, stempel))
	if koerper != nil {
		req.Header.Set("Content-Type", "application/json")
	}

	res, err := o.http.Do(req)
	if err != nil {
		return fmt.Errorf("ovh: %w", err)
	}
	defer func() { _ = res.Body.Close() }()

	roh, _ := io.ReadAll(io.LimitReader(res.Body, 1<<20))
	if res.StatusCode/100 != 2 {
		return fmt.Errorf("ovh antwortete mit %s: %s", res.Status, gekuerzt(roh))
	}
	if ziel == nil {
		return nil
	}
	if err := json.Unmarshal(roh, ziel); err != nil {
		return fmt.Errorf("ovh: Antwort nicht lesbar: %w", err)
	}
	return nil
}

// signatur baut den Wert für X-Ovh-Signature.
//
// SHA-1 ist von der API vorgeschrieben — siehe der Kopf dieser Datei. Die
// Reihenfolge der Bestandteile ist ebenso vorgegeben und darf sich nicht
// ändern; ein vertauschtes Paar ergibt eine gültig aussehende Signatur, die
// abgelehnt wird, und die Fehlermeldung von OVH sagt nur „invalid signature".
func (o *ovhSetter) signatur(methode, adresse, koerper, stempel string) string {
	roh := strings.Join([]string{
		o.appGeh, o.consumer, methode, adresse, koerper, stempel,
	}, "+")
	summe := sha1.Sum([]byte(roh)) //nolint:gosec // von der OVH-API vorgeschrieben
	return "$1$" + fmt.Sprintf("%x", summe)
}

// zeitstempel liefert die Zeit, die OVH erwartet.
//
// Beim ersten Aufruf wird der Versatz zwischen Serverzeit und eigener Uhr
// ermittelt und danach angewandt. Ohne das scheitert jeder Aufruf auf einem
// Server, dessen Uhr um mehr als ein paar Sekunden danebengeht — und das ist
// auf einem frisch aufgesetzten VPS keine Seltenheit. Die Fehlermeldung von
// OVH lautet dann „invalid signature" und zeigt in die falsche Richtung.
func (o *ovhSetter) zeitstempel(ctx context.Context) (string, error) {
	o.mu.Lock()
	geeicht, versatz := o.geeicht, o.versatz
	o.mu.Unlock()

	if !geeicht {
		req, err := http.NewRequestWithContext(ctx, http.MethodGet, o.basis+"/auth/time", nil)
		if err != nil {
			return "", err
		}
		res, err := o.http.Do(req)
		if err != nil {
			return "", fmt.Errorf("ovh: Serverzeit nicht abrufbar: %w", err)
		}
		defer func() { _ = res.Body.Close() }()

		roh, _ := io.ReadAll(io.LimitReader(res.Body, 64))
		serverzeit, err := strconv.ParseInt(strings.TrimSpace(string(roh)), 10, 64)
		if err != nil {
			return "", fmt.Errorf("ovh: Serverzeit nicht lesbar: %q", gekuerzt(roh))
		}
		versatz = time.Unix(serverzeit, 0).Sub(o.jetzt())

		o.mu.Lock()
		o.versatz, o.geeicht = versatz, true
		o.mu.Unlock()
	}
	return strconv.FormatInt(o.jetzt().Add(versatz).Unix(), 10), nil
}
