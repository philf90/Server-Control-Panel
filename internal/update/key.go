package update

import "sync"

// EmbeddedPublicKey ist der öffentliche Signaturschlüssel des Projekts.
//
// Er steht im Klartext im Quelltext und landet damit im Binary. Das ist die
// ganze Vertrauensbasis des Update-Wegs: Weder die Metadatendatei noch der
// Downloadserver noch ein Programm im PATH kann sie ersetzen. Wer den
// Schlüssel austauschen will, muss das Binary austauschen — und wer das kann,
// braucht kein Update mehr zu fälschen.
//
// Derselbe Schlüssel liegt als packaging/minisign.pub im Repository und unter
// https://repo.cloudsrv24.de/minisign.pub zum Vergleich. Ein Test wacht
// darüber, dass die beiden Fassungen nicht auseinanderlaufen.
const EmbeddedPublicKey = "RWQj/sAQQiq7Aa8sPaBSb21Wcbp9n165J+s6z8qqq0GUmB2ZXzDNoNXf"

var (
	embeddedOnce sync.Once
	embeddedKey  PublicKey
	embeddedErr  error
)

// ProjectKey liefert den eingebauten Schlüssel.
func ProjectKey() (PublicKey, error) {
	embeddedOnce.Do(func() {
		embeddedKey, embeddedErr = ParsePublicKey(EmbeddedPublicKey)
	})
	return embeddedKey, embeddedErr
}
