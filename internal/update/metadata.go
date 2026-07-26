package update

import (
	"context"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"runtime"
	"strings"
	"time"
)

// DefaultBaseURL ist der Ort der Update-Metadaten.
const DefaultBaseURL = "https://repo.cloudsrv24.de"

// Kanäle. "stable" bekommt nur Freigaben, "beta" auch Vorabversionen.
const (
	ChannelStable = "stable"
	ChannelBeta   = "beta"
)

// ValidChannel prüft einen Kanalnamen.
func ValidChannel(c string) bool { return c == ChannelStable || c == ChannelBeta }

// maxMetadataSize begrenzt, was als Metadatendatei akzeptiert wird. Ohne
// Grenze könnte ein Server das Panel mit einem endlosen Datenstrom
// beschäftigen — der Rest der Kette ist signaturgeschützt, dieser Schritt
// nicht.
const maxMetadataSize = 64 << 10

// Release beschreibt eine veröffentlichte Fassung, so wie sie in
// updates/<kanal>.json steht.
//
// Diese Datei ist *nicht* signiert. Sie ist ein Wegweiser, keine Quelle von
// Vertrauen: Wer sie fälscht, kann höchstens auf eine andere — ebenfalls echt
// signierte — Fassung zeigen oder das Update verhindern. Was tatsächlich
// installiert wird, entscheidet allein die minisign-Signatur über SHA256SUMS.
type Release struct {
	Version           string              `json:"version"`
	ReleasedAt        time.Time           `json:"released_at"`
	MinUpgradableFrom string              `json:"min_upgradable_from"`
	NotesURL          string              `json:"notes_url"`
	Severity          string              `json:"severity"`
	ChecksumsURL      string              `json:"checksums_url"`
	SignatureURL      string              `json:"signature_url"`
	Artifacts         map[string]Artifact `json:"artifacts"`
}

// Artifact ist ein Archiv für eine Plattform.
type Artifact struct {
	URL    string `json:"url"`
	SHA256 string `json:"sha256"`
}

// Platform liefert den Schlüssel für die laufende Plattform, etwa "linux_amd64".
func Platform() string { return runtime.GOOS + "_" + runtime.GOARCH }

// ArtifactFor sucht das Archiv für eine Plattform.
func (r Release) ArtifactFor(platform string) (Artifact, error) {
	a, ok := r.Artifacts[platform]
	if !ok {
		return Artifact{}, fmt.Errorf("für %s gibt es in Fassung %s kein Archiv", platform, r.Version)
	}
	if err := a.validate(); err != nil {
		return Artifact{}, fmt.Errorf("archiv für %s: %w", platform, err)
	}
	return a, nil
}

func (a Artifact) validate() error {
	if err := requireHTTPS(a.URL); err != nil {
		return err
	}
	sum, err := hex.DecodeString(a.SHA256)
	if err != nil || len(sum) != 32 {
		return fmt.Errorf("prüfsumme %q ist kein SHA-256", a.SHA256)
	}
	return nil
}

// validate prüft die Metadaten so weit, dass der weitere Ablauf mit ihnen
// rechnen kann. Alles hier ist Plausibilität, keine Sicherheitsprüfung.
func (r Release) validate() error {
	if _, err := ParseVersion(r.Version); err != nil {
		return fmt.Errorf("version in den Metadaten: %w", err)
	}
	if r.MinUpgradableFrom != "" {
		if _, err := ParseVersion(r.MinUpgradableFrom); err != nil {
			return fmt.Errorf("min_upgradable_from: %w", err)
		}
	}
	if len(r.Artifacts) == 0 {
		return fmt.Errorf("die Metadaten nennen kein einziges Archiv")
	}
	for _, u := range []string{r.ChecksumsURL, r.SignatureURL} {
		if u == "" {
			continue
		}
		if err := requireHTTPS(u); err != nil {
			return err
		}
	}
	return nil
}

// requireHTTPS lässt nur https zu. Ohne diese Prüfung könnte eine manipulierte
// Metadatendatei den Download auf file:// oder http:// umlenken.
func requireHTTPS(raw string) error {
	u, err := url.Parse(raw)
	if err != nil {
		return fmt.Errorf("unbrauchbare Adresse %q: %w", raw, err)
	}
	if u.Scheme != "https" {
		return fmt.Errorf("nur https ist zulässig, %q verwendet %q", raw, u.Scheme)
	}
	if u.Host == "" {
		return fmt.Errorf("adresse %q nennt keinen Host", raw)
	}
	return nil
}

// checksumURLs liefert die Adressen von SHA256SUMS und Signatur. Fehlen sie in
// den Metadaten, werden sie aus der Archivadresse abgeleitet — beide liegen im
// selben Release-Verzeichnis.
func (r Release) checksumURLs(a Artifact) (sums, sig string, err error) {
	sums, sig = r.ChecksumsURL, r.SignatureURL
	if sums == "" {
		i := strings.LastIndexByte(a.URL, '/')
		if i < 0 {
			return "", "", fmt.Errorf("aus %q lässt sich kein Release-Verzeichnis ableiten", a.URL)
		}
		sums = a.URL[:i+1] + checksumFile
	}
	if sig == "" {
		sig = sums + ".minisig"
	}
	return sums, sig, nil
}

// Client holt Metadaten und Artefakte.
type Client struct {
	BaseURL string
	HTTP    *http.Client
}

// NewClient liefert einen Client mit knappen Zeitgrenzen. Ein hängender
// Update-Server darf keinen Handler blockieren.
func NewClient() *Client {
	return &Client{
		BaseURL: DefaultBaseURL,
		HTTP: &http.Client{
			Timeout: 60 * time.Second,
			CheckRedirect: func(req *http.Request, via []*http.Request) error {
				if len(via) >= 5 {
					return fmt.Errorf("zu viele Weiterleitungen")
				}
				// GitHub leitet Release-Downloads auf einen Speicherdienst um;
				// der Zielort darf aber niemals unverschlüsselt sein.
				return requireHTTPS(req.URL.String())
			},
		},
	}
}

// Latest lädt die Metadaten eines Kanals.
func (c *Client) Latest(ctx context.Context, channel string) (Release, error) {
	if !ValidChannel(channel) {
		return Release{}, fmt.Errorf("unbekannter Kanal %q", channel)
	}
	base := strings.TrimSuffix(c.BaseURL, "/")
	body, err := c.get(ctx, base+"/updates/"+channel+".json", maxMetadataSize)
	if err != nil {
		return Release{}, err
	}

	// Bewusst ohne DisallowUnknownFields: Ein neuerer Server darf mehr
	// schreiben, als dieses Binary kennt. Ein Update daran scheitern zu
	// lassen wäre der falsche Umgang mit einer erweiterten Datei.
	var rel Release
	if err := json.Unmarshal(body, &rel); err != nil {
		return Release{}, fmt.Errorf("metadaten für %q sind unlesbar: %w", channel, err)
	}
	if err := rel.validate(); err != nil {
		return Release{}, err
	}
	return rel, nil
}

// get lädt eine Adresse mit Größengrenze.
func (c *Client) get(ctx context.Context, rawURL string, limit int64) ([]byte, error) {
	if err := requireHTTPS(rawURL); err != nil {
		return nil, err
	}
	req, err := http.NewRequestWithContext(ctx, http.MethodGet, rawURL, nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("User-Agent", userAgent())
	req.Header.Set("Accept", "*/*")

	resp, err := c.httpClient().Do(req)
	if err != nil {
		return nil, fmt.Errorf("abruf von %s: %w", rawURL, err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		return nil, fmt.Errorf("abruf von %s: HTTP %s", rawURL, resp.Status)
	}

	body, err := io.ReadAll(io.LimitReader(resp.Body, limit+1))
	if err != nil {
		return nil, fmt.Errorf("abruf von %s: %w", rawURL, err)
	}
	if int64(len(body)) > limit {
		return nil, fmt.Errorf("abruf von %s: mehr als %d Byte", rawURL, limit)
	}
	return body, nil
}

func (c *Client) httpClient() *http.Client {
	if c.HTTP != nil {
		return c.HTTP
	}
	return http.DefaultClient
}
