package auth

import (
	"strings"
	"testing"
	"time"
)

// ------------------------------------------------------------- Passwörter ---

func TestHashAndVerify(t *testing.T) {
	hash, err := HashPassword("ein hinreichend langes Passwort")
	if err != nil {
		t.Fatalf("HashPassword: %v", err)
	}
	if !strings.HasPrefix(hash, "$argon2id$") {
		t.Fatalf("unerwartetes Format: %q", hash)
	}

	ok, err := VerifyPassword("ein hinreichend langes Passwort", hash)
	if err != nil {
		t.Fatalf("VerifyPassword: %v", err)
	}
	if !ok {
		t.Error("korrektes Passwort wurde abgelehnt")
	}

	ok, err = VerifyPassword("etwas anderes", hash)
	if err != nil {
		t.Fatalf("VerifyPassword: %v", err)
	}
	if ok {
		t.Error("falsches Passwort wurde angenommen")
	}
}

func TestHashIsSalted(t *testing.T) {
	a, err := HashPassword("dasselbe Passwort xyz")
	if err != nil {
		t.Fatal(err)
	}
	b, err := HashPassword("dasselbe Passwort xyz")
	if err != nil {
		t.Fatal(err)
	}
	if a == b {
		t.Error("zwei Hashes desselben Passworts sind identisch — das Salt fehlt")
	}
}

func TestVerifyRejectsBrokenHash(t *testing.T) {
	for name, hash := range map[string]string{
		"leer":            "",
		"kein argon2id":   "$argon2i$v=19$m=32768,t=3,p=2$AAAA$BBBB",
		"zu wenig Felder": "$argon2id$v=19$AAAA",
		"kein base64":     "$argon2id$v=19$m=32768,t=3,p=2$!!!$???",
	} {
		t.Run(name, func(t *testing.T) {
			if _, err := VerifyPassword("egal", hash); err == nil {
				t.Error("unlesbarer Hash muss einen Fehler ergeben")
			}
		})
	}
}

// Der Zeitausgleich bei unbekannten Konten hängt daran, dass dieser Hash
// syntaktisch gültig ist und tatsächlich eine Argon2-Berechnung auslöst.
func TestDummyHashShapeIsUsable(t *testing.T) {
	const dummy = "$argon2id$v=19$m=32768,t=3,p=2$" +
		"AAAAAAAAAAAAAAAAAAAAAA$AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA"

	ok, err := VerifyPassword("irgendwas", dummy)
	if err != nil {
		t.Fatalf("Dummy-Hash ist unbrauchbar: %v", err)
	}
	if ok {
		t.Error("Dummy-Hash darf niemals passen")
	}
}

func TestPasswordPolicy(t *testing.T) {
	if err := CheckPasswordPolicy("kurz"); err == nil {
		t.Error("zu kurzes Passwort muss abgelehnt werden")
	}
	if err := CheckPasswordPolicy(strings.Repeat("a", MinPasswordLength)); err != nil {
		t.Errorf("Passwort mit Mindestlänge abgelehnt: %v", err)
	}
	if err := CheckPasswordPolicy(strings.Repeat("a", 2000)); err == nil {
		t.Error("übermäßig langes Passwort muss abgelehnt werden")
	}
}

// -------------------------------------------------------------------- TOTP ---

// Testvektoren aus RFC 6238, Anhang B (SHA-1, Geheimnis "12345678901234567890").
// Der RFC führt achtstellige Codes auf; sechsstellig sind es die letzten sechs
// Ziffern.
func TestTOTPRFC6238Vectors(t *testing.T) {
	const secret = "GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ" // Base32 von "12345678901234567890"

	tests := []struct {
		unix int64
		want string
	}{
		{59, "287082"},
		{1111111109, "081804"},
		{1111111111, "050471"},
		{1234567890, "005924"},
		{2000000000, "279037"},
	}

	for _, tc := range tests {
		got, err := TOTPCode(secret, time.Unix(tc.unix, 0))
		if err != nil {
			t.Fatalf("TOTPCode(%d): %v", tc.unix, err)
		}
		if got != tc.want {
			t.Errorf("TOTPCode(%d) = %s, erwartet %s", tc.unix, got, tc.want)
		}
	}
}

func TestVerifyTOTP(t *testing.T) {
	secret, err := GenerateTOTPSecret()
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()

	code, err := TOTPCode(secret, now)
	if err != nil {
		t.Fatal(err)
	}
	if !VerifyTOTP(secret, code, now) {
		t.Error("aktueller Code wurde abgelehnt")
	}

	// Ein Fenster Toleranz in beide Richtungen ist gewollt …
	if !VerifyTOTP(secret, code, now.Add(30*time.Second)) {
		t.Error("Code aus dem vorherigen Fenster wurde abgelehnt")
	}
	// … zwei Fenster nicht mehr.
	if VerifyTOTP(secret, code, now.Add(120*time.Second)) {
		t.Error("deutlich veralteter Code wurde angenommen")
	}
	if VerifyTOTP(secret, "000000", now) && code != "000000" {
		t.Error("beliebiger Code wurde angenommen")
	}
	if VerifyTOTP(secret, "", now) {
		t.Error("leerer Code wurde angenommen")
	}
	if VerifyTOTP("kein base32!", code, now) {
		t.Error("unlesbares Geheimnis muss abgelehnt werden")
	}
}

func TestProvisioningURI(t *testing.T) {
	uri := TOTPProvisioningURI("ABCDEF", "philipp", "Project Asylum")
	for _, want := range []string{"otpauth://totp/", "secret=ABCDEF", "issuer=Project+Asylum", "digits=6", "period=30"} {
		if !strings.Contains(uri, want) {
			t.Errorf("URI %q enthält %q nicht", uri, want)
		}
	}
}

func TestFormatSecret(t *testing.T) {
	if got, want := FormatSecret("ABCDEFGH"), "ABCD EFGH"; got != want {
		t.Errorf("FormatSecret = %q, erwartet %q", got, want)
	}
}

// ------------------------------------------------------------------ Tokens ---

func TestNewTokenIsUnique(t *testing.T) {
	seen := make(map[string]bool, 100)
	for i := 0; i < 100; i++ {
		tok, err := NewToken()
		if err != nil {
			t.Fatal(err)
		}
		if len(tok) < 40 {
			t.Fatalf("Token zu kurz: %q", tok)
		}
		if seen[tok] {
			t.Fatal("Token doppelt erzeugt")
		}
		seen[tok] = true
	}
}

func TestHashTokenIsStable(t *testing.T) {
	if HashToken("abc") != HashToken("abc") {
		t.Error("gleiche Eingabe ergibt unterschiedliche Hashes")
	}
	if HashToken("abc") == HashToken("abd") {
		t.Error("verschiedene Eingaben ergeben denselben Hash")
	}
	if HashToken("abc") == "abc" {
		t.Error("der Token darf nicht im Klartext zurückkommen")
	}
}

func TestRecoveryCodes(t *testing.T) {
	codes, hashes, err := NewRecoveryCodes()
	if err != nil {
		t.Fatal(err)
	}
	if len(codes) != RecoveryCodeCount || len(hashes) != RecoveryCodeCount {
		t.Fatalf("%d Codes und %d Hashes, erwartet je %d", len(codes), len(hashes), RecoveryCodeCount)
	}

	// Der gespeicherte Hash muss zur normalisierten Eingabe passen — sonst
	// funktioniert kein einziger Code beim Einlösen.
	if got := HashToken(NormalizeRecoveryCode(codes[0])); got != hashes[0] {
		t.Error("Hash passt nicht zur normalisierten Form des Codes")
	}

	seen := make(map[string]bool, len(codes))
	for _, c := range codes {
		if seen[c] {
			t.Error("Code doppelt erzeugt")
		}
		seen[c] = true
	}
}

func TestNormalizeRecoveryCode(t *testing.T) {
	want := "abcdefghijkl"
	for _, in := range []string{"abcd-efgh-ijkl", "ABCD-EFGH-IJKL", "  abcd efgh ijkl  ", "abcdefghijkl"} {
		if got := NormalizeRecoveryCode(in); got != want {
			t.Errorf("NormalizeRecoveryCode(%q) = %q, erwartet %q", in, got, want)
		}
	}
}

// ------------------------------------------------------------- Ratenlimit ---

func TestLimiter(t *testing.T) {
	l := NewLimiter()
	now := time.Now()
	l.now = func() time.Time { return now }

	if ok, _ := l.Allowed("ip:test"); !ok {
		t.Fatal("erster Versuch muss zulässig sein")
	}

	for i := 0; i < l.MaxAttempts-1; i++ {
		l.Fail("ip:test")
	}
	if ok, _ := l.Allowed("ip:test"); !ok {
		t.Error("unterhalb der Schwelle darf nicht gesperrt werden")
	}

	l.Fail("ip:test")
	ok, retry := l.Allowed("ip:test")
	if ok {
		t.Fatal("nach Erreichen der Schwelle muss gesperrt werden")
	}
	if retry <= 0 {
		t.Error("Sperrzeit muss positiv sein")
	}

	// Nach Ablauf der Sperre wieder zulässig.
	now = now.Add(retry + time.Second)
	if ok, _ := l.Allowed("ip:test"); !ok {
		t.Error("nach Ablauf der Sperre muss wieder zugelassen werden")
	}

	// Weitere Fehlversuche verlängern die Sperre.
	l.Fail("ip:test")
	_, retry2 := l.Allowed("ip:test")
	if retry2 <= retry {
		t.Errorf("Sperrzeit steigt nicht an: %v → %v", retry, retry2)
	}
}

func TestLimiterResetAndCleanup(t *testing.T) {
	l := NewLimiter()
	now := time.Now()
	l.now = func() time.Time { return now }

	for i := 0; i < l.MaxAttempts; i++ {
		l.Fail("user:philipp")
	}
	l.Reset("user:philipp")
	if ok, _ := l.Allowed("user:philipp"); !ok {
		t.Error("nach Reset muss wieder zugelassen werden")
	}

	l.Fail("ip:alt")
	if l.Size() == 0 {
		t.Fatal("Zähler wurde nicht angelegt")
	}
	now = now.Add(2 * l.Window)
	l.Cleanup()
	if l.Size() != 0 {
		t.Error("verfallene Zähler wurden nicht aufgeräumt")
	}
}

func TestLimiterSeparatesKeys(t *testing.T) {
	l := NewLimiter()
	for i := 0; i < l.MaxAttempts; i++ {
		l.Fail("ip:1.2.3.4")
	}
	if ok, _ := l.Allowed("ip:5.6.7.8"); !ok {
		t.Error("die Sperre einer Adresse darf keine andere treffen")
	}
}
