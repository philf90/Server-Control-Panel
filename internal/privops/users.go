package privops

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strconv"
	"strings"
)

// uidMin ist die Grenze, ab der ein Konto als regulärer Benutzer gilt.
// Darunter liegen Systemkonten (siehe UID_MIN in /etc/login.defs).
const uidMin = 1000

// Pfade der Systemdateien. Als Variablen, damit Tests sie auf Fixtures zeigen
// lassen können — die Schreiblogik für authorized_keys ist zu heikel, um sie
// nur auf einem echten System zu prüfen.
var (
	passwdPath = "/etc/passwd"
	groupPath  = "/etc/group"
	shadowPath = "/etc/shadow"
)

// SystemUsers liest die Benutzer des Systems.
func (s *System) SystemUsers(ctx context.Context) ([]SystemUser, error) {
	_ = ctx

	passwd, err := os.ReadFile(passwdPath)
	if err != nil {
		return nil, fmt.Errorf("%s: %w", passwdPath, err)
	}
	users := parsePasswd(string(passwd))

	// Gruppenzugehörigkeiten aus /etc/group ergänzen.
	if group, err := os.ReadFile(groupPath); err == nil {
		memberships, primary := parseGroups(string(group))
		for i := range users {
			groups := append([]string{}, memberships[users[i].Name]...)
			if name, ok := primary[users[i].GID]; ok && !contains(groups, name) {
				groups = append([]string{name}, groups...)
			}
			sort.Strings(groups)
			users[i].Groups = groups
		}
	}

	// Sperrzustand steht in /etc/shadow. Ohne Leserecht bleibt das Feld leer,
	// statt die ganze Liste scheitern zu lassen.
	if shadow, err := os.ReadFile(shadowPath); err == nil {
		locked := parseShadowLocks(string(shadow))
		for i := range users {
			users[i].Locked = locked[users[i].Name]
		}
	}

	for i := range users {
		users[i].SSHKeys = countAuthorizedKeys(users[i].Home)
	}
	return users, nil
}

// SystemUserCreate legt einen Benutzer an.
func (s *System) SystemUserCreate(ctx context.Context, spec SystemUserSpec) error {
	if err := ValidateSystemUser(spec.Name); err != nil {
		return err
	}
	if err := ValidateComment(spec.Comment); err != nil {
		return err
	}
	if err := ValidateShell(spec.Shell); err != nil {
		return err
	}
	for _, g := range spec.Groups {
		if err := ValidateGroup(g); err != nil {
			return err
		}
	}
	if spec.SSHKey != "" {
		if _, err := parseAuthorizedKeyLine(spec.SSHKey); err != nil {
			return err
		}
	}

	args := []string{}
	if spec.CreateHome {
		args = append(args, "--create-home")
	} else {
		args = append(args, "--no-create-home")
	}
	if spec.Comment != "" {
		args = append(args, "--comment", spec.Comment)
	}
	if spec.Shell != "" {
		args = append(args, "--shell", spec.Shell)
	}
	if len(spec.Groups) > 0 {
		args = append(args, "--groups", strings.Join(spec.Groups, ","))
	}
	args = append(args, "--", spec.Name)

	res, err := s.run(ctx, Command{Name: "useradd", Args: args})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("useradd %s: %s", spec.Name, firstLine(res.Stderr))
	}

	// Kein Passwort setzen: Das Konto bleibt ohne Anmeldemöglichkeit, bis ein
	// SSH-Schlüssel hinterlegt ist. Ein Startpasswort über das Panel wäre ein
	// Geheimnis, das durch HTTP-Formulare und Logs wandert.
	if _, err := s.run(ctx, Command{Name: "passwd", Args: []string{"--lock", "--", spec.Name}}); err != nil {
		return err
	}

	if spec.SSHKey != "" {
		if err := s.AuthorizedKeyAdd(ctx, spec.Name, spec.SSHKey); err != nil {
			return fmt.Errorf("Konto angelegt, aber der SSH-Schlüssel scheiterte: %w", err)
		}
	}
	return nil
}

// SystemUserSetLocked sperrt oder entsperrt ein Konto.
func (s *System) SystemUserSetLocked(ctx context.Context, name string, locked bool) error {
	if err := ValidateSystemUser(name); err != nil {
		return err
	}
	if err := protectedUser(name); err != nil {
		return err
	}

	flag := "--unlock"
	if locked {
		flag = "--lock"
	}
	res, err := s.run(ctx, Command{Name: "usermod", Args: []string{flag, "--", name}})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("usermod %s %s: %s", flag, name, firstLine(res.Stderr))
	}
	return nil
}

// SystemUserDelete entfernt ein Konto.
func (s *System) SystemUserDelete(ctx context.Context, name string, removeHome bool) error {
	if err := ValidateSystemUser(name); err != nil {
		return err
	}
	if err := protectedUser(name); err != nil {
		return err
	}

	args := []string{}
	if removeHome {
		args = append(args, "--remove")
	}
	args = append(args, "--", name)

	res, err := s.run(ctx, Command{Name: "userdel", Args: args})
	if err != nil {
		return err
	}
	if res.ExitCode != 0 {
		return fmt.Errorf("userdel %s: %s", name, firstLine(res.Stderr))
	}
	return nil
}

// protectedUser schützt Konten, deren Verlust das System unbrauchbar macht.
func protectedUser(name string) error {
	switch name {
	case "root", "daemon", "sys", "sync", "systemd-network", "systemd-resolve", "sshd", "asylum":
		return fmt.Errorf("das Konto %q ist geschützt und lässt sich über das Panel nicht verändern", name)
	}
	return nil
}

// ------------------------------------------------------------- SSH-Schlüssel ---

// AuthorizedKeys liest die hinterlegten Schlüssel eines Benutzers.
func (s *System) AuthorizedKeys(ctx context.Context, user string) ([]SSHKey, error) {
	_ = ctx
	if err := ValidateSystemUser(user); err != nil {
		return nil, err
	}

	path, err := authorizedKeysPath(user)
	if err != nil {
		return nil, err
	}
	raw, err := os.ReadFile(path) //nolint:gosec // Pfad aus /etc/passwd, Name validiert
	if errors.Is(err, os.ErrNotExist) {
		return nil, nil
	}
	if err != nil {
		return nil, fmt.Errorf("%s: %w", path, err)
	}

	var keys []SSHKey
	for _, line := range strings.Split(string(raw), "\n") {
		key, err := parseAuthorizedKeyLine(line)
		if err != nil {
			continue
		}
		keys = append(keys, key)
	}
	return keys, nil
}

// AuthorizedKeyAdd hängt einen Schlüssel an.
func (s *System) AuthorizedKeyAdd(ctx context.Context, user, key string) error {
	if err := ValidateSystemUser(user); err != nil {
		return err
	}
	parsed, err := parseAuthorizedKeyLine(key)
	if err != nil {
		return err
	}

	existing, err := s.AuthorizedKeys(ctx, user)
	if err != nil {
		return err
	}
	for _, e := range existing {
		if e.Fingerprint == parsed.Fingerprint {
			return fmt.Errorf("dieser Schlüssel ist bereits hinterlegt")
		}
	}

	lines := make([]string, 0, len(existing)+1)
	for _, e := range existing {
		lines = append(lines, e.Line)
	}
	lines = append(lines, parsed.Line)
	return s.writeAuthorizedKeys(user, lines)
}

// AuthorizedKeyRemove entfernt einen Schlüssel anhand seines Fingerprints.
func (s *System) AuthorizedKeyRemove(ctx context.Context, user, fingerprint string) error {
	if err := ValidateSystemUser(user); err != nil {
		return err
	}

	existing, err := s.AuthorizedKeys(ctx, user)
	if err != nil {
		return err
	}

	lines := make([]string, 0, len(existing))
	found := false
	for _, e := range existing {
		if e.Fingerprint == fingerprint {
			found = true
			continue
		}
		lines = append(lines, e.Line)
	}
	if !found {
		return fmt.Errorf("kein Schlüssel mit diesem Fingerprint")
	}
	return s.writeAuthorizedKeys(user, lines)
}

// writeAuthorizedKeys schreibt die Datei atomar und mit korrekten Rechten.
//
// sshd verweigert den Dienst, wenn Verzeichnis oder Datei für andere
// beschreibbar sind — ein zu großzügiges umask würde den Zugang lautlos
// unbrauchbar machen.
func (s *System) writeAuthorizedKeys(user string, lines []string) error {
	path, err := authorizedKeysPath(user)
	if err != nil {
		return err
	}
	uid, gid, err := userIDs(user)
	if err != nil {
		return err
	}

	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o700); err != nil {
		return fmt.Errorf("%s: %w", dir, err)
	}
	if err := os.Chown(dir, uid, gid); err != nil && !os.IsPermission(err) {
		return fmt.Errorf("%s: %w", dir, err)
	}
	// 0700 statt 0600: Ein Verzeichnis braucht das Ausführungsrecht, sonst ist
	// es nicht betretbar. sshd besteht zugleich darauf, dass niemand sonst
	// Zugriff hat.
	if err := os.Chmod(dir, 0o700); err != nil { //nolint:gosec // Verzeichnis, kein File
		return fmt.Errorf("%s: %w", dir, err)
	}

	content := ""
	if len(lines) > 0 {
		content = strings.Join(lines, "\n") + "\n"
	}

	tmp := path + ".asylum.tmp"
	if err := os.WriteFile(tmp, []byte(content), 0o600); err != nil {
		return fmt.Errorf("%s: %w", tmp, err)
	}
	if err := os.Chown(tmp, uid, gid); err != nil && !os.IsPermission(err) {
		_ = os.Remove(tmp)
		return fmt.Errorf("%s: %w", tmp, err)
	}
	// rename(2) ist atomar: Ein Abbruch hinterlässt entweder die alte oder die
	// neue Datei, nie eine halbe.
	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)
		return fmt.Errorf("%s: %w", path, err)
	}
	return nil
}

func authorizedKeysPath(user string) (string, error) {
	home, err := userHome(user)
	if err != nil {
		return "", err
	}
	return filepath.Join(home, ".ssh", "authorized_keys"), nil
}

func userHome(user string) (string, error) {
	raw, err := os.ReadFile(passwdPath)
	if err != nil {
		return "", fmt.Errorf("%s: %w", passwdPath, err)
	}
	for _, u := range parsePasswd(string(raw)) {
		if u.Name == user {
			if u.Home == "" || u.Home == "/" {
				return "", fmt.Errorf("Benutzer %q hat kein brauchbares Home-Verzeichnis", user)
			}
			return u.Home, nil
		}
	}
	return "", fmt.Errorf("Benutzer %q existiert nicht", user)
}

func userIDs(user string) (uid, gid int, err error) {
	raw, err := os.ReadFile(passwdPath)
	if err != nil {
		return 0, 0, fmt.Errorf("%s: %w", passwdPath, err)
	}
	for _, u := range parsePasswd(string(raw)) {
		if u.Name == user {
			return u.UID, u.GID, nil
		}
	}
	return 0, 0, fmt.Errorf("Benutzer %q existiert nicht", user)
}

func countAuthorizedKeys(home string) int {
	if home == "" || home == "/" {
		return 0
	}
	raw, err := os.ReadFile(filepath.Join(home, ".ssh", "authorized_keys")) //nolint:gosec // Pfad aus /etc/passwd
	if err != nil {
		return 0
	}
	count := 0
	for _, line := range strings.Split(string(raw), "\n") {
		if _, err := parseAuthorizedKeyLine(line); err == nil {
			count++
		}
	}
	return count
}

// ------------------------------------------------------------------ Parser ---

func parsePasswd(content string) []SystemUser {
	var users []SystemUser

	for _, line := range strings.Split(content, "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "#") {
			continue
		}
		// name:passwd:uid:gid:comment:home:shell
		f := strings.Split(line, ":")
		if len(f) < 7 {
			continue
		}
		uid, err := strconv.Atoi(f[2])
		if err != nil {
			continue
		}
		gid, _ := strconv.Atoi(f[3])

		shell := f[6]
		users = append(users, SystemUser{
			Name:     f[0],
			UID:      uid,
			GID:      gid,
			Comment:  strings.SplitN(f[4], ",", 2)[0],
			Home:     f[5],
			Shell:    shell,
			System:   uid < uidMin && f[0] != "root",
			HasShell: shell != "/usr/sbin/nologin" && shell != "/sbin/nologin" && shell != "/bin/false",
		})
	}

	sort.Slice(users, func(i, j int) bool {
		// Reguläre Benutzer zuerst — Systemkonten sind selten der Grund für den
		// Seitenaufruf.
		if users[i].System != users[j].System {
			return !users[i].System
		}
		return users[i].UID < users[j].UID
	})
	return users
}

// parseGroups liefert die Zusatzmitgliedschaften je Benutzer und die Namen der
// Gruppen je GID.
func parseGroups(content string) (members map[string][]string, byGID map[int]string) {
	members = make(map[string][]string)
	byGID = make(map[int]string)

	for _, line := range strings.Split(content, "\n") {
		// name:passwd:gid:member,member
		f := strings.Split(strings.TrimSpace(line), ":")
		if len(f) < 4 {
			continue
		}
		if gid, err := strconv.Atoi(f[2]); err == nil {
			byGID[gid] = f[0]
		}
		for _, m := range strings.Split(f[3], ",") {
			if m = strings.TrimSpace(m); m != "" {
				members[m] = append(members[m], f[0])
			}
		}
	}
	return members, byGID
}

// parseShadowLocks erkennt gesperrte Konten am Präfix "!" des Passwortfelds.
func parseShadowLocks(content string) map[string]bool {
	locked := make(map[string]bool)

	for _, line := range strings.Split(content, "\n") {
		f := strings.Split(strings.TrimSpace(line), ":")
		if len(f) < 2 || f[0] == "" {
			continue
		}
		locked[f[0]] = strings.HasPrefix(f[1], "!") || f[1] == "*"
	}
	return locked
}

// erlaubte Schlüsseltypen. Alles andere ist entweder veraltet (ssh-dss) oder
// hier nicht vorgesehen.
var allowedKeyTypes = map[string]bool{
	"ssh-ed25519":                        true,
	"ssh-rsa":                            true,
	"ecdsa-sha2-nistp256":                true,
	"ecdsa-sha2-nistp384":                true,
	"ecdsa-sha2-nistp521":                true,
	"sk-ssh-ed25519@openssh.com":         true,
	"sk-ecdsa-sha2-nistp256@openssh.com": true,
}

// parseAuthorizedKeyLine prüft eine Zeile und bestimmt ihren Fingerprint.
func parseAuthorizedKeyLine(line string) (SSHKey, error) {
	line = strings.TrimSpace(line)
	if line == "" || strings.HasPrefix(line, "#") {
		return SSHKey{}, errors.New("leere Zeile")
	}
	// Zeilenumbrüche im Eingabefeld würden mehrere Schlüssel auf einmal
	// einschleusen.
	if strings.ContainsAny(line, "\n\r") {
		return SSHKey{}, errors.New("der Schlüssel darf nur eine Zeile umfassen")
	}

	fields := strings.Fields(line)
	if len(fields) < 2 {
		return SSHKey{}, errors.New("das ist kein SSH-Schlüssel")
	}
	// Optionen vor dem Typ (z. B. command="…") werden nicht unterstützt: Sie
	// sind mächtig genug, um beim Anlegen unbemerkt Befehle zu hinterlegen.
	if !allowedKeyTypes[fields[0]] {
		return SSHKey{}, fmt.Errorf("Schlüsseltyp %q wird nicht unterstützt", fields[0])
	}

	key := SSHKey{
		Type:    fields[0],
		Comment: strings.Join(fields[2:], " "),
		Line:    fields[0] + " " + fields[1],
	}
	if key.Comment != "" {
		key.Line += " " + key.Comment
	}

	fp, bits, err := sshFingerprint(fields[0], fields[1])
	if err != nil {
		return SSHKey{}, err
	}
	key.Fingerprint = fp
	key.Bits = bits
	return key, nil
}

func contains(list []string, want string) bool {
	for _, v := range list {
		if v == want {
			return true
		}
	}
	return false
}
