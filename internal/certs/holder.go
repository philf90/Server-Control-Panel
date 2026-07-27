package certs

import (
	"crypto/tls"
	"errors"
	"sync"
)

// Holder hält das aktive TLS-Zertifikat hinter einem Lock und erlaubt, es zur
// Laufzeit auszutauschen.
//
// Das ist die Grundlage dafür, dass eine spätere ACME-Erneuerung das Zertifikat
// wechseln kann, ohne den Prozess neu zu starten: tls.Config.GetCertificate
// fragt bei jedem Handshake den Halter, nicht eine beim Start eingefrorene
// Liste. Bis dahin trägt der Halter schlicht das selbstsignierte Paar — das
// Verhalten ändert sich dadurch nicht.
type Holder struct {
	mu   sync.RWMutex
	cert *tls.Certificate
}

// NewHolder legt einen Halter mit einem Anfangszertifikat an.
func NewHolder(cert tls.Certificate) *Holder {
	c := cert
	return &Holder{cert: &c}
}

// Set tauscht das Zertifikat atomar aus. Jeder Handshake danach verwendet es.
func (h *Holder) Set(cert tls.Certificate) {
	c := cert
	h.mu.Lock()
	h.cert = &c
	h.mu.Unlock()
}

// GetCertificate passt auf die Signatur von tls.Config.GetCertificate. Der
// ClientHello wird bewusst ignoriert: Das Panel führt genau ein Zertifikat,
// es gibt keine SNI-Auswahl zwischen mehreren Hosts.
func (h *Holder) GetCertificate(*tls.ClientHelloInfo) (*tls.Certificate, error) {
	h.mu.RLock()
	defer h.mu.RUnlock()
	if h.cert == nil {
		return nil, errors.New("kein TLS-Zertifikat im Halter")
	}
	return h.cert, nil
}
