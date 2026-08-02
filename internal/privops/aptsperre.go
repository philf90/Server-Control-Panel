package privops

// Die Erkennung der eigenen Härtung in einer apt-Ausgabe.
//
// # Der Befund
//
// Bis 0.6.1 trugen beide mitgelieferten Units `ProtectSystem=true`. Das stellt
// /usr, /boot und /efi für den Dienst read-only — mit der Begründung, dort habe
// ein Panel nichts von Hand zu ändern. Der Satz stimmt und übersieht das
// Entscheidende: **Die Einschränkung gilt für jeden Kindprozess**, und apt ist
// ein Kindprozess. Damit scheiterte jede Paketinstallation und jedes
// Paket-Update über das Panel, und zwar erst beim Auspacken:
//
//	Unpacking nginx (1.24.0-2ubuntu7.15) ...
//	dpkg: error processing archive …/nginx_1.24.0-2ubuntu7.15_amd64.deb (--unpack):
//	 unable to create '/usr/sbin/nginx.dpkg-new'
//	   (while processing './usr/sbin/nginx'): Read-only file system
//
// Gefunden hat das kein Test, sondern der erste Lauf auf einem echten Server.
// Kein Test konnte es finden: Die Attrappe führt kein apt aus, und der echte
// Lauf steht in keiner Testumgebung unter dieser Unit.
//
// # Warum die Erkennung bleibt, obwohl die Unit berichtigt ist
//
// Weil die Berichtigung nicht überall ankommt. Das Selbstupdate tauscht das
// Programm, **nie die Unit** — dieselbe Lage wie bei der Härtungsänderung der
// 0.3.0, die deshalb in UPGRADING.md steht. Eine über den curl-Installer
// aufgesetzte Installation trägt ihre Unit selbst geschrieben; sie bleibt
// unverändert, bis jemand sie anfasst. Beim Paket ersetzt der Update-Lauf die
// mitgelieferte Unit, aber auch nur, solange sie niemand angepasst hat.
//
// Diese Installationen laufen weiter in den Fehler. Was sie dann sehen,
// entscheidet sich hier: entweder achtzig Zeilen dpkg-Ausgabe, aus denen der
// Grund nicht hervorgeht — oder ein Satz, der ihn nennt.

import (
	"errors"
	"strings"
)

// aptSperrenHinweis ist der Satz, der aus dem dpkg-Dump eine Handlung macht.
//
// Er nennt drei Dinge, und alle drei sind nötig: dass die Sperre nicht vom
// Dateisystem des Servers kommt, wo sie herkommt, und was sie aufhebt. Ein
// Hinweis ohne den letzten Teil verschöbe das Rätsel nur.
const aptSperrenHinweis = "Die Ursache liegt nicht bei apt: /usr ist für den " +
	"Dienst asylumd schreibgeschützt. Das kommt aus seiner systemd-Unit — " +
	"ProtectSystem=true gilt auch für jeden Kindprozess, und apt ist einer. " +
	"Units bis 0.6.1 tragen die Einstellung; das Selbstupdate tauscht das " +
	"Programm, nie die Unit. Abhilfe: " +
	"`systemctl edit asylumd` und dort `[Service]` mit `ProtectSystem=no` " +
	"eintragen, dann `systemctl restart asylumd`. Danach räumt " +
	"`apt-get --fix-broken install` den abgebrochenen dpkg-Lauf auf. " +
	"Einzelheiten in UPGRADING.md."

// aptSchreibsperre erkennt die eigene Härtung in der Ausgabe eines apt-Laufs.
//
// Zwei Merkmale müssen zusammenkommen, und das ist Absicht: die Meldung des
// Kernels UND ein Pfad unter /usr. „Read-only file system" allein trifft auch
// eine echte, gewollt schreibgeschützt eingehängte Platte — dann ist der
// Hinweis auf die Unit falsch und schickt jemanden in die Irre. Erst der Pfad
// macht daraus die Aussage, die dieser Hinweis behauptet.
//
// Die englische Schreibweise genügt, weil der Runner LC_ALL=C setzt (exec.go).
// Das ist dieselbe Voraussetzung, auf der jeder Parser dieses Pakets steht.
func aptSchreibsperre(ausgabe string) bool {
	if !strings.Contains(ausgabe, "Read-only file system") {
		return false
	}
	for _, zeile := range strings.Split(ausgabe, "\n") {
		if !strings.Contains(zeile, "Read-only file system") {
			continue
		}
		if strings.Contains(zeile, "/usr/") {
			return true
		}
	}
	return false
}

// aptFehler baut die Meldung zu einem gescheiterten apt-Lauf.
//
// Ohne den Sonderfall bleibt es bei der knappen Zeile: Der vollständige Auszug
// steht ohnehin darüber in der Vorgangsanzeige, und ihn hier zu wiederholen
// machte die Meldung nicht genauer.
func aptFehler(was string, res Result) error {
	if aptSchreibsperre(res.Stdout + "\n" + res.Stderr) {
		return errors.New(was + " — " + aptSperrenHinweis)
	}
	return errors.New(was)
}
