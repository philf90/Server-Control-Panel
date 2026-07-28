package auth

import (
	"strconv"
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
	const name = "philipp"

	faelle := []struct {
		was      string
		passwort string
		erlaubt  bool
	}{
		{"zu kurz", "kurz", false},
		{"gerade lang genug", "korrekt pferd batterie", true},
		{"genau die Mindestlänge", "abcdefg hijk", true},
		{"übermäßig lang", strings.Repeat("ab", 1000), false},

		// Dasselbe Zeichen zwölf Mal bestand die alte Regel — Länge allein sagt
		// nichts über Ratbarkeit.
		{"nur Wiederholung", strings.Repeat("a", MinPasswordLength), false},
		{"durchgehende Folge", "abcdefghijklmn", false},
		// Absteigend heißt Schritt für Schritt: "987654321098" springt an der
		// Null zurück auf die Neun und ist damit keine durchgehende Folge.
		{"absteigende Folge", "zyxwvutsrqponm", false},
		{"Ziffern mit Umbruch", "987654321098", true},
		{"Folge nur als Teil", "xkcd abcdefg zzz", true},

		// Der Anmeldename ist das Erste, was ein Angreifer probiert.
		{"ist der Anmeldename", name + name, false},
		{"enthält den Anmeldenamen", "meinPhilippPasswort", false},
		{"nur ähnlich", "philosophie im regen", true},
	}

	for _, f := range faelle {
		t.Run(f.was, func(t *testing.T) {
			err := CheckPasswordPolicy(name, f.passwort)
			if f.erlaubt && err != nil {
				t.Errorf("%q wurde abgelehnt: %v", f.passwort, err)
			}
			if !f.erlaubt && err == nil {
				t.Errorf("%q wurde angenommen", f.passwort)
			}
		})
	}

	// Ohne Anmeldenamen entfällt nur diese eine Prüfung, die übrigen gelten.
	if err := CheckPasswordPolicy("", "korrekt pferd batterie"); err != nil {
		t.Errorf("ohne Namen abgelehnt: %v", err)
	}
	if err := CheckPasswordPolicy("", "kurz"); err == nil {
		t.Error("ohne Namen darf die Längenregel nicht entfallen")
	}
}

// Die Richtlinie ist auch Anzeige: Jede Regel, die ablehnen kann, braucht einen
// Satz in der Oberfläche — sonst erfährt man sie erst durch die Ablehnung.
func TestPolicyBeschreibtJedeRegel(t *testing.T) {
	p := Policy()
	if p.MinLength != MinPasswordLength || p.MaxBytes != MaxPasswordBytes {
		t.Errorf("Policy nennt andere Zahlen als die Konstanten: %+v", p)
	}

	gewollt := []PasswordRuleKey{RuleLength, RuleNotUsername, RuleNotTrivial, RuleMaxBytes}
	if len(p.Rules) != len(gewollt) {
		t.Fatalf("%d Regeln, erwartet %d: %+v", len(p.Rules), len(gewollt), p.Rules)
	}
	for i, key := range gewollt {
		if p.Rules[i].Key != key {
			t.Errorf("Regel %d = %q, erwartet %q", i, p.Rules[i].Key, key)
		}
		if strings.TrimSpace(p.Rules[i].Text) == "" {
			t.Errorf("Regel %q hat keinen Text", key)
		}
	}
	// Die Zahl im Satz muss die geltende sein, nicht eine abgeschriebene.
	if !strings.Contains(p.Rules[0].Text, strconv.Itoa(MinPasswordLength)) {
		t.Errorf("der Text zur Längenregel nennt die Mindestlänge nicht: %q", p.Rules[0].Text)
	}
}

func TestPasswordIsTrivial(t *testing.T) {
	trivial := []string{"aaaa", "1111111111", "abcdef", "fedcba", "123456789", "..."}
	for _, p := range trivial {
		if !PasswordIsTrivial(p) {
			t.Errorf("%q gilt nicht als trivial", p)
		}
	}
	harmlos := []string{"", "a", "ab", "korrekt pferd batterie", "aabbcc", "abcx"}
	for _, p := range harmlos {
		if PasswordIsTrivial(p) {
			t.Errorf("%q gilt als trivial", p)
		}
	}
}

func TestPasswordUnlikeUsername(t *testing.T) {
	if PasswordUnlikeUsername("philipp", "Philipp1234567") {
		t.Error("die Prüfung darf nicht auf Groß- und Kleinschreibung hereinfallen")
	}
	if !PasswordUnlikeUsername("philipp", "korrekt pferd batterie") {
		t.Error("ein Passwort ohne den Namen wurde abgelehnt")
	}
	if !PasswordUnlikeUsername("", "irgendetwas langes hier") {
		t.Error("ohne Namen gibt es nichts zu prüfen")
	}
	if !PasswordUnlikeUsername("   ", "irgendetwas langes hier") {
		t.Error("ein leerer Name mit Leerzeichen darf nicht jedes Passwort sperren")
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
	first, second := HashToken("abc"), HashToken("abc")
	if first != second {
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

// TestNewTemporaryPassword prüft die drei Zusagen des Einmalpassworts: Es genügt
// der Passwortregel (sonst wäre es beim Wechsel nicht einmal als aktuelles
// Passwort brauchbar), es besteht aus verwechslungsarmen Zeichen (es wird
// abgeschrieben oder durchgesagt, nicht kopiert), und zwei Aufrufe liefern nicht
// dasselbe.
func TestNewTemporaryPassword(t *testing.T) {
	pw, err := NewTemporaryPassword()
	if err != nil {
		t.Fatal(err)
	}

	// Vier Gruppen à vier Zeichen, durch Bindestriche getrennt.
	groups := strings.Split(pw, "-")
	if len(groups) != 4 {
		t.Fatalf("%q hat %d Gruppen, erwartet 4", pw, len(groups))
	}
	for _, g := range groups {
		if len(g) != 4 {
			t.Errorf("Gruppe %q ist %d Zeichen lang, erwartet 4", g, len(g))
		}
	}

	// Keine Vokale und kein 0/1/l/o — nichts, was beim Abschreiben verwechselt
	// wird.
	const erlaubt = "abcdefghjkmnpqrstuvwxyz23456789"
	for _, r := range strings.ReplaceAll(pw, "-", "") {
		if !strings.ContainsRune(erlaubt, r) {
			t.Errorf("%q enthält das Zeichen %q, das nicht im Alphabet steht", pw, r)
		}
	}

	// Die Passwortregel muss es erfüllen: Auf der Wechselseite wird es als
	// aktuelles Passwort geprüft.
	if err := CheckPasswordPolicy("", pw); err != nil {
		t.Errorf("das Einmalpasswort %q verstößt gegen die Regel: %v", pw, err)
	}

	zweites, err := NewTemporaryPassword()
	if err != nil {
		t.Fatal(err)
	}
	if pw == zweites {
		t.Error("zwei Aufrufe liefern dasselbe Passwort")
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

// TestCheckTOTPWiederholung deckt den Wiederholungsschutz auf Paketebene ab.
func TestCheckTOTPWiederholung(t *testing.T) {
	secret, err := GenerateTOTPSecret()
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	code, err := TOTPCode(secret, now)
	if err != nil {
		t.Fatal(err)
	}

	first := CheckTOTP(secret, code, now, 0)
	if !first.Valid || first.Reused {
		t.Fatalf("erste Prüfung = %+v", first)
	}
	if first.Counter == 0 {
		t.Fatal("kein Zeitfenster zurückgegeben")
	}

	// Mit demselben Zähler als verbraucht: derselbe Code gilt nicht mehr,
	// und der Grund ist unterscheidbar.
	again := CheckTOTP(secret, code, now, first.Counter)
	if again.Valid {
		t.Error("ein verbrauchter Code wurde erneut angenommen")
	}
	if !again.Reused {
		t.Error("der Grund wird nicht als Wiederverwendung gemeldet")
	}

	// Ein falscher Code ist etwas anderes als ein verbrauchter.
	wrong := CheckTOTP(secret, "000000", now, 0)
	if wrong.Valid || wrong.Reused {
		t.Errorf("falscher Code = %+v", wrong)
	}

	// Der Code des nächsten Fensters gilt trotz verbrauchtem Vorgänger.
	next := now.Add(30 * time.Second)
	nextCode, err := TOTPCode(secret, next)
	if err != nil {
		t.Fatal(err)
	}
	if got := CheckTOTP(secret, nextCode, next, first.Counter); !got.Valid {
		t.Errorf("der nächste Code wurde abgewiesen: %+v", got)
	}
}

// TestCheckTOTPToleranzfensterVerbraucht: Die Toleranz von einem Fenster darf
// den Schutz nicht aushebeln. Ein Code aus dem vorherigen Fenster gilt nicht
// mehr, sobald das aktuelle angenommen wurde.
func TestCheckTOTPToleranzfensterVerbraucht(t *testing.T) {
	secret, err := GenerateTOTPSecret()
	if err != nil {
		t.Fatal(err)
	}
	now := time.Now()
	vorher, err := TOTPCode(secret, now.Add(-30*time.Second))
	if err != nil {
		t.Fatal(err)
	}
	jetzt := CheckTOTP(secret, mustCode(t, secret, now), now, 0)
	if !jetzt.Valid {
		t.Fatal("der aktuelle Code wurde nicht angenommen")
	}
	if got := CheckTOTP(secret, vorher, now, jetzt.Counter); got.Valid {
		t.Error("der Code des vorherigen Fensters gilt weiterhin")
	}
}

func mustCode(t *testing.T, secret string, at time.Time) string {
	t.Helper()
	c, err := TOTPCode(secret, at)
	if err != nil {
		t.Fatal(err)
	}
	return c
}
