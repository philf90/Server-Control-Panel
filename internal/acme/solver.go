package acme

import "context"

// challengeSolver macht eine ACME-Challenge-Antwort verfügbar und räumt sie
// wieder ab. HTTP-01 serviert sie über Port 80; DNS-01 (Phase 3) setzt einen
// TXT-Record. Der Wert wird vom acme.Client berechnet und hier nur bereitgestellt.
type challengeSolver interface {
	// challengeType ist der ACME-Typ, den dieser Löser bedient, etwa "http-01".
	challengeType() string
	// present stellt value für die Prüfung der Domain bereit. Bei HTTP-01 ist
	// value der Body unter /.well-known/acme-challenge/<token>, bei DNS-01 der
	// Inhalt des TXT-Records.
	present(ctx context.Context, domain, token, value string) error
	// cleanup entfernt die zuvor bereitgestellte Antwort wieder.
	cleanup(ctx context.Context, domain, token, value string) error
}
