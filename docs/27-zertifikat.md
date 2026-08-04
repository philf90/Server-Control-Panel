# Das Zertifikat der Oberfläche

Bis Let's Encrypt (P4) liefert das Panel ein selbstsigniertes Zertifikat aus.
Es ist die Notlösung dafür, dass beim ersten Start noch kein Name auf diesen
Server zeigt — und es ist keine Zierde: Eine Anmeldung über eine ungesicherte
Verbindung wäre ein Passwort im Klartext auf dem Weg.

## 1. Ohne Zertifikat kein Server-Block

`panel.vhost.apply` verweigert die Arbeit, wenn Zertifikat oder Schlüssel
fehlen. Das Panel war damit nie über eine ungesicherte Verbindung erreichbar,
und es kann auch nicht dahin zurückfallen.

## 2. Es trägt einen subjectAltName

Bis August 2026 stand der Name nur im CommonName. Chrome liest den seit 2017
nicht mehr, Firefox und Safari ebenso wenig — der Browser meldete deshalb
nicht „unbekannter Aussteller", sondern „der Name passt nicht", und das
Zertifikat liess sich auch durch Aufnahme in den eigenen Zertifikatsspeicher
nicht brauchbar machen. Dazu kommt: Nach der Einrichtung ruft man das Panel
über die **IP** auf — `srvpanel setup` gibt sie selbst so aus —, und die stand
nirgends darin.

Im subjectAltName stehen: der **vollständige** Name des Rechners, seine kurze
Form, `localhost` und jede Adresse aller Schnittstellen. **Ohne die
link-lokalen** (`169.254.0.0/16`, `fe80::/10`): Die vergibt ein Rechner sich
selbst, wenn er sonst nichts bekommt, sie ändern sich, und unter ihnen ruft
niemand ein Panel auf. Gelesen werden die Adressen über
`net_get_interfaces()` und nicht über das Programm `ip` — ein Programm weniger
auf der Positivliste des Agenten.

### Der vollständige Name steht nicht im Kernel

`php_uname('n')` liefert den Knotennamen, und der ist auf den meisten Servern
der kurze: `cloudsrv24` statt `cloudsrv24.de`. Hier stand zunächst genau das
Falsche — der Knotenname, und aus ihm *abgeleitet* noch eine Kurzform. Auf
einem Server, dessen Knotenname schon kurz ist, kam damit ausschliesslich
`cloudsrv24` ins Zertifikat, und wer `cloudsrv24.de` aufruft, bekommt eine
Warnung über einen Namen, der nicht passt.

**Dieselbe Lektion gab es schon.** Bei der Ersteinrichtung zeigte der Link am
Ende auf den kurzen Namen — auch das fiel erst auf einem echten Server auf,
und auch dort steht seitdem ein Kommentar mit genau diesem Beispiel. Eine
Regel, die an einer Stelle gelernt und an der nächsten neu erfunden wird, ist
keine Regel. Sie steht jetzt in `Names::fqdn()`, und die Einrichtung fragt
dieselbe Funktion.

Gesucht wird in drei Quellen, von der billigsten zur teuersten:

1. Trägt der Knotenname schon einen Punkt, ist er es.
2. `/etc/hosts` — dort legt Debian `127.0.1.1 cloudsrv24.de cloudsrv24` an.
3. Die Rückwärtsauflösung der Adresse, mit der der Rechner nach aussen
   spricht. Sie kostet einen Namensdienst, der auch schweigen kann.

**Ein gefundener Name muss den Knotennamen fortsetzen** — er zählt nur, wenn
er mit `<knotenname>.` beginnt. Ohne diese Bedingung könnte eine fremde Zeile
in `/etc/hosts` oder ein Namensdienst einen beliebigen Namen in das Zertifikat
dieses Servers schreiben, und ein Zertifikat ist eine Behauptung darüber, wer
man ist.

**Findet keine der drei Quellen etwas**, bleibt es beim Knotennamen. Dann weiss
der Rechner seinen vollen Namen selbst nicht, und das ist keine Frage, die das
Panel raten sollte — `hostnamectl set-hostname cloudsrv24.de` oder die Zeile in
`/etc/hosts` ist die Antwort. Welche Namen im Zertifikat stehen, zeigt
`/settings/tls` und `srvpanel tls`.

## 3. CA:FALSE

Ein selbstsigniertes Zertifikat, das gleichzeitig eine Zertifizierungsstelle
sein darf, ist ein Generalschlüssel: Wer den privaten Schlüssel dieses Servers
erbeutet, stellt damit Zertifikate für *jeden* Namen aus, die jede Maschine
akzeptiert, die dieses eine Zertifikat einmal aufgenommen hat. Der Preis
dafür ist, dass die Aufnahme in den Zertifikatsspeicher je nach Betriebssystem
umständlicher ist — für eine Übergangslösung bis P4 ist das die richtige Seite
des Tauschs.

Dazu `keyUsage` auf Signatur und Schlüsselaustausch, `extendedKeyUsage` auf
`serverAuth`, und eine **zufällige Seriennummer**: Zwei selbstsignierte
Zertifikate desselben Rechners hätten sonst denselben Aussteller *und*
dieselbe Seriennummer, und für einen Zertifikatsspeicher sind das zwei
Fassungen desselben Zertifikats.

## 4. Erneuert wird vor dem Ablauf, nicht danach

397 Tage Laufzeit, erneuert ab 30 Tagen Restlaufzeit. Angestossen von
`srvpanel-tls.timer`, täglich, mit einer Stunde Streuung.

**Der Timer ist der eigentliche Fix.** Die Prüfung gab es von Anfang an — sie
lief nur nie: Aufgerufen wurde `panel.tls.ensure` ausschliesslich von
`srvpanel setup`. Nach der Einrichtung rührte sie niemand mehr an, und das
Zertifikat wäre eines Tages abgelaufen, ohne dass etwas passiert.

**Nicht an `srvpanel update` gehängt.** Das Kommando stösst dort nur eine
systemd-Unit an; ein Update kann Monate auseinanderliegen. Eine Erneuerung,
die an Updates hängt, erneuert genau dann nicht, wenn ein Server lange
unangetastet läuft — also im einzigen Fall, der zählt.

Zwei Gründe erneuern:

1. **Die Restlaufzeit** unterschreitet 30 Tage.
2. **Der Rechner heisst nicht mehr so wie damals.** Ein Zertifikat auf einen
   alten Hostnamen ist auf diesem Server so brauchbar wie keines.

**Eine geänderte IP erneuert nicht.** Sie wäre der dritte naheliegende Grund
und ein schlechter: Auf einem Server mit Docker oder libvirt kommen und gehen
Adressen, und jede Änderung ergäbe ein neues Zertifikat samt neuer
Browserwarnung. Die Seite im Panel zeigt statt dessen an, welche Adresse
fehlt, und der Betreiber stellt mit einem Klick neu aus.

## 5. Nach dem Tausch wird nginx neu geladen

Ohne diesen Schritt wäre die Erneuerung wirkungslos: nginx liest Zertifikat
und Schlüssel beim Start und behält sie im Speicher. Ein Zertifikat, das
erneuert ist und trotzdem abläuft, ist schlimmer als eines, das nie erneuert
wurde — danach sieht niemand mehr hin.

Erst `nginx -t` (das liest die Zertifikatsdateien mit), dann der Reload; bei
einem Fehlschlag kommt der vorige Stand zurück und nginx wird erneut geladen.
Dieselbe Reihenfolge wie beim Server-Block, aus demselben Grund: Ein Panel,
das sich mit dem eigenen Zertifikat aussperrt, wäre nur noch über SSH zu
retten.

## 6. Im Panel sichtbar

`/settings/tls` zeigt Name, Aussteller, Laufzeit, abgedeckte Namen und
Adressen — und warnt, wenn eine Adresse dieses Rechners nicht abgedeckt ist
oder die Laufzeit knapp wird. Ein Zertifikat, das man nirgends ansehen kann,
läuft ab, ohne dass jemand hinsieht.

`panel.tls.info` ist **nicht verändernd** und läuft deshalb ohne Vorgang;
sonst stünde für jedes Nachsehen eine Zeile im Protokoll. Das Neuausstellen
ist ein Vorgang, immer mit `force`: Wer den Knopf drückt, hat einen Grund,
den das Panel nicht kennt, und die Prüfung „gilt ja noch" würde genau diesen
Fall abweisen.

## 7. Was mit P4 dazukommt — und eine Falle darin

Let's Encrypt über ACME. Diese Seite ist dann der Ort, an dem steht, welches
der beiden gerade gilt; die Angabe `self_signed` beantwortet das schon heute.

**Die Falle heisst HSTS.** Der Server-Block setzt
`Strict-Transport-Security: max-age=31536000`. Solange das Zertifikat nicht
vertraut ist, verwerfen Browser den Header — er ist heute wirkungslos. Sobald
aber ein Zertifikat von Let's Encrypt gilt und der Header einmal gespeichert
ist, lässt sich ein Zertifikatsfehler auf diesem Host **nicht mehr
wegklicken**. Ein Rückfall auf das selbstsignierte Zertifikat — abgelaufenes
ACME-Zertifikat, gescheiterte Erneuerung, umgezogene Domain — sperrt den
Betreiber dann aus seinem eigenen Panel aus. Wer P4 baut, muss dafür eine
Antwort haben: entweder HSTS erst setzen, wenn ein vertrautes Zertifikat
vorliegt, oder einen zweiten Weg hinein, der nicht über diesen Namen läuft.
