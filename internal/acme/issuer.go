package acme

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"log/slog"

	xacme "golang.org/x/crypto/acme"
)

// acmeIssuer besorgt ein Zertifikat über einen echten ACME-Server. Die Sequenz
// folgt RFC 8555 in genau der Reihenfolge, die auch der Beispieltest von
// x/crypto/acme geht: Konto, Order, je Autorisierung eine Challenge lösen und
// bestätigen, auf die Order warten, CSR einreichen, Kette abholen.
type acmeIssuer struct {
	dir          string
	email        string
	directoryURL string // leer = Let's-Encrypt-Produktion
	newSolver    func(ctx context.Context) (challengeSolver, error)
	log          *slog.Logger
}

func (a *acmeIssuer) obtain(ctx context.Context, domains []string) (certPEM, keyPEM []byte, err error) {
	accountKey, err := loadOrCreateAccountKey(a.dir)
	if err != nil {
		return nil, nil, fmt.Errorf("kontoschlüssel: %w", err)
	}
	client := &xacme.Client{Key: accountKey}
	if a.directoryURL != "" {
		client.DirectoryURL = a.directoryURL
	}

	// Konto registrieren. Ein bereits registrierter Schlüssel ist kein Fehler.
	if _, err := client.Register(ctx, &xacme.Account{Contact: []string{"mailto:" + a.email}}, xacme.AcceptTOS); err != nil {
		if !errors.Is(err, xacme.ErrAccountAlreadyExists) {
			return nil, nil, fmt.Errorf("acme-konto: %w", err)
		}
	}

	solver, err := a.newSolver(ctx)
	if err != nil {
		return nil, nil, err
	}
	if c, ok := solver.(io.Closer); ok {
		defer func() { _ = c.Close() }()
	}

	order, err := client.AuthorizeOrder(ctx, xacme.DomainIDs(domains...))
	if err != nil {
		return nil, nil, fmt.Errorf("order anlegen: %w", err)
	}

	for _, authzURL := range order.AuthzURLs {
		authz, err := client.GetAuthorization(ctx, authzURL)
		if err != nil {
			return nil, nil, fmt.Errorf("autorisierung lesen: %w", err)
		}
		if authz.Status != xacme.StatusPending {
			continue // bereits gültig (aus einer früheren Ausstellung)
		}

		chal := challengeByType(authz.Challenges, solver.challengeType())
		if chal == nil {
			return nil, nil, fmt.Errorf("der Server bietet keine %s-Challenge für %s", solver.challengeType(), authz.Identifier.Value)
		}
		value, err := challengeValue(client, solver.challengeType(), chal.Token)
		if err != nil {
			return nil, nil, err
		}
		if err := solver.present(ctx, authz.Identifier.Value, chal.Token, value); err != nil {
			return nil, nil, fmt.Errorf("challenge bereitstellen: %w", err)
		}
		if _, err := client.Accept(ctx, chal); err != nil {
			_ = solver.cleanup(ctx, authz.Identifier.Value, chal.Token, value)
			return nil, nil, fmt.Errorf("challenge bestätigen: %w", err)
		}
		_, waitErr := client.WaitAuthorization(ctx, authzURL)
		_ = solver.cleanup(ctx, authz.Identifier.Value, chal.Token, value)
		if waitErr != nil {
			return nil, nil, fmt.Errorf("autorisierung fehlgeschlagen für %s: %w", authz.Identifier.Value, waitErr)
		}
	}

	order, err = client.WaitOrder(ctx, order.URI)
	if err != nil {
		return nil, nil, fmt.Errorf("order abwarten: %w", err)
	}
	if order.Status != xacme.StatusReady {
		return nil, nil, fmt.Errorf("order ist %q statt ready", order.Status)
	}

	certKey, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		return nil, nil, err
	}
	csr, err := x509.CreateCertificateRequest(rand.Reader, &x509.CertificateRequest{DNSNames: domains}, certKey)
	if err != nil {
		return nil, nil, fmt.Errorf("csr: %w", err)
	}
	chain, _, err := client.CreateOrderCert(ctx, order.FinalizeURL, csr, true)
	if err != nil {
		return nil, nil, fmt.Errorf("zertifikat abholen: %w", err)
	}

	certPEM = encodeChain(chain)
	keyDER, err := x509.MarshalECPrivateKey(certKey)
	if err != nil {
		return nil, nil, err
	}
	keyPEM = pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER})
	return certPEM, keyPEM, nil
}

func challengeByType(challenges []*xacme.Challenge, typ string) *xacme.Challenge {
	for _, c := range challenges {
		if c.Type == typ {
			return c
		}
	}
	return nil
}

// challengeValue berechnet den bereitzustellenden Wert. Bei HTTP-01 ist es der
// Antwort-Body, bei DNS-01 der TXT-Inhalt.
func challengeValue(client *xacme.Client, typ, token string) (string, error) {
	switch typ {
	case "http-01":
		return client.HTTP01ChallengeResponse(token)
	case "dns-01":
		return client.DNS01ChallengeRecord(token)
	default:
		return "", fmt.Errorf("unbekannter challenge-typ %q", typ)
	}
}

func encodeChain(chain [][]byte) []byte {
	var out []byte
	for _, der := range chain {
		out = append(out, pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der})...)
	}
	return out
}
