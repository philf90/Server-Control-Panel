# Rückfragen an den Betreiber vor P7 (DNS)

Angelegt am 21. August 2026, unmittelbar nach der Abnahme von P6
(`docs/69`). Dies ist Schritt 1 der Stufe: die Fragen, die vor dem Plan
beantwortet sein müssen, jede mit ihrer Messgrundlage und meiner Einschätzung
daneben — damit entschieden und nicht recherchiert werden muss.

**Die Messrunde selbst kommt als eigenes Dokument** und wird erst nach den
Antworten gefahren; Frage 1 entscheidet, was daran überhaupt zu messen ist. Sie
bekommt die nächste freie Nummer — hier steht sie noch nicht, weil ein Verweis
auf ein Dokument, das es nicht gibt, `DocLinkTest` rot macht.

Was hier als *gemessen* steht, ist heute im Container gegen ein Wegwerf-PowerDNS
gefahren worden — Fassung **4.8.3**, `gsqlite3`-Backend, API auf
`127.0.0.1:8081`. Was als *ungemessen* steht, steht so da und wird nicht
vermutet.

---

## 0. Was heute gemessen wurde

Zwölf Messungen, alle gegen den laufenden Dienst. Sie sind die Grundlage der
Fragen 1, 2, 3 und 7.

| # | Frage | Ergebnis |
|---|---|---|
| M1 | Lässt sich hier ein PowerDNS hochziehen? | **Ja** — `pdns-server` 4.8.3 aus `noble/universe`, `gsqlite3`-Backend, Wegwerf-Dienst auf Port 5300 |
| M2 | Sockelpfad | Wie bei PostgreSQL: der Scratchpad reisst die 107-Byte-Grenze, `socket-dir` muss kurz sein |
| M3 | Zone anlegen über die API | 201, SOA und NS entstehen von selbst |
| M4 | Spricht die API HTTPS? | **Nein** — `curl` bricht mit 35 ab, HTTP am selben Port liefert 200 (327 Byte) |
| M5 | Kennt PowerDNS 4.8.3 eine TLS-Option für seinen Webserver? | **Nein** — in der Optionsliste steht keine für Zertifikat oder Schlüssel |
| M6 | Kaputte A-Adresse (`999.1.2.3`) | Abgewiesen, 422, mit lesbarer Begründung aus dem Parser |
| M7 | MX ohne Priorität | Abgewiesen, 422 |
| M8 | CNAME neben A am selben Namen | Abgewiesen: „Conflicts with pre-existing RRset" |
| M9 | Ein gutes und ein kaputtes RRset in **einem** PATCH | **Atomar** — 422, und das gute ist nicht angekommen |
| M10 | Liefert der Dienst die Zone aus? | `rcode=0`, `aa=1`, eine Antwort |
| M11 | DNSSEC über die API | Ein PUT, 204 — ein CSK mit `ECDSAP256SHA256`, DS in drei Digest-Arten kommt aus der API zurück |
| M12 | Überlebt der Dienst die abgewiesenen Änderungen? | Ja — die API antwortet nach jeder mit 200 |

**Die Gegenprobe zu M4 gehört dazu:** Ein `curl`, das an HTTPS scheitert, sieht
genauso aus wie eines, das den Port gar nicht findet. Daneben steht deshalb der
HTTP-Aufruf an denselben Port mit 200 und 327 gelesenen Bytes.

> **Eine Null ist nur dann eine Messung, wenn daneben etwas anderes als Null
> steht.**

---

## 1. Die Frage, die den Zuschnitt entscheidet: HTTP-API, RFC 2136 — oder beides?

**Zwei Stellen im Repo zeigen auseinander.** `docs/20 §9` schreibt die HTTP-API
vor („nicht über die Datenbank"). `docs/34 §6` sagt über RFC 2136: „Der
Standard, kein Anbietercode — er bedient BIND, Knot und PowerDNS, und **damit
die eigene Zone aus P7 ohne zweite Umsetzung**."

**Was ich beim Nachsehen im Quelltext gefunden habe, hebt den zweiten Satz
auf.** Der RFC-2136-Klient dieses Projekts kann **einen** Satztyp: TXT.
`Packet::TYPE_TXT` trägt den Kommentar „der einzige Satztyp, den diese Prüfung
braucht", und `UpdateMessage` baut genau eine Art von Rdata. Für die neun
Satztypen aus `docs/20 §9` — A, AAAA, CNAME, MX, TXT, SRV, CAA, NS, dazu der
PTR-Hinweis — wären acht weitere Drahtformate von Hand zu schreiben, jedes mit
seiner eigenen Prüfung der Darstellungsform.

Der Satz aus `docs/34` war eine Vorhersage, geschrieben, bevor jemand
nachgesehen hat, was ein Eintragseditor braucht. **Für TXT stimmt er, für neun
Satztypen nicht.**

Dazu kommt, was RFC 2136 grundsätzlich nicht kann: eine Zone anlegen oder
löschen, DNSSEC schalten, Schlüssel wechseln, Metadaten setzen. Das sind vier
der sieben Punkte aus `docs/20 §9`.

Und der Unterschied, der beim Abnahmekriterium bezahlt wird — „ein Zonenfehler
wird nicht übernommen": **Die API prüft selbst, serverseitig und atomar**
(M6–M9). Bei RFC 2136 läge diese Prüfung bei uns, und ein falsch gebautes
Rdata schreibt einen Eintrag, den niemand abweist.

> **Ein Weg, der die Prüfung mitbringt, und einer, bei dem wir sie nachbauen
> müssen, sind nicht zwei Wege zu demselben Gegenstand.**

**Meine Einschätzung: die HTTP-API, ausschliesslich.** Der RFC-2136-Klient
bleibt, wo er ist — als einer von acht Anbietern für **fremde** Zonen in
ACME DNS-01. Er bekommt für P7 keine zweite Rolle. Das ist auch die Antwort auf
die Sorge vor zwei Wegen zu demselben Gegenstand: Es sind zwei Gegenstände.

**Sollte der Betreiber „beides" wollen**, gehört in den Plan, welcher Weg bei
einem Widerspruch gewinnt — und wer die neun Rdata-Prüfungen für den zweiten Weg
schreibt.

### 1a. Die Nebenfrage, die daran hängt — und die es nicht vorher gab

**Die API von PowerDNS spricht kein HTTPS** (M4, M5). `agent/src/Acme/Curl.php`
ist der einzige Ort, an dem der Agent nach draussen spricht, und seine erste von
vier Zusagen lautet **„Nur https"** — eine Adresse ohne TLS wird abgewiesen,
bevor curl sie sieht.

Damit stösst die Vorgabe aus `docs/20 §9` auf eine bestehende, absichtliche
Zusage. Drei Wege:

- **(a) Eine benannte Ausnahme in `Curl` für die Rückschleife.** Aus „nur https"
  wird „https, oder eine Adresse auf `127.0.0.1`/`::1`". Die Zusage bekommt
  einen Riss, aber einen, der aufgeschrieben, geprüft und auf eine
  Operationsfamilie eingegrenzt ist.
- **(b) Ein zweiter Ausgang neben `Curl`.** Genau das Muster, gegen das `Curl`
  überhaupt gebaut wurde: „eine zweite Stelle, die dieselben vier Optionen setzt
  … die zweite ist die, in der eine davon irgendwann fehlt."
- **(c) Ein Unix-Socket statt TCP.** Fällt aus: Der Webserver von 4.8.3 bindet
  nur an eine IP-Adresse, eine Sockeloption gibt es nicht (M5).

**Meine Einschätzung: (a)** — als ausdrückliche, geprüfte Ausnahme mit Wächter,
nicht als stille Lockerung. Ein Ausgang, der die Rückschleife erlaubt, ist
weniger Angriffsfläche als zwei Ausgänge, von denen einer die Zusagen verliert.

**Ausdrücklich nicht vorgeschlagen: `pdnsutil`.** Das Programm stünde als
lokales Werkzeug sauber auf der Positivliste — aber es schreibt in die
**Datenbank** hinter dem Dienst, und das ist es, was `docs/20 §9` untersagt. Es
wäre ausserdem ein zweiter Schreiber in demselben Bestand:

> **Ein zweiter Schreiber in derselben Datei ist kein zweiter Schreiber, solange
> nur einer die Sperre nimmt.**

---

## 2. Welche PowerDNS-Fassung liegt auf den vier Zielplattformen?

**Zwei gemessen, zwei nicht** — und die zwei nicht, weil dieser Container sie
nicht erreicht, nicht weil ich nicht nachgesehen hätte:

| Plattform | `pdns-server` | Woher |
|---|---|---|
| Ubuntu 24.04 (noble) | **4.8.3-4build3** | installiert und gefahren |
| Ubuntu 22.04 (jammy) | **4.5.3-1** | aus dem Paketindex gelesen |
| Debian 12 (bookworm) | **ungemessen** | `deb.debian.org` gibt dem Proxy 403 |
| Debian 13 (trixie) | **ungemessen** | dieselbe Sperre |

Die Gegenprobe steht daneben: Der Ubuntu-Index hat mit derselben Befehlsfolge
geantwortet, das Verfahren taugt also — gesperrt ist Debian und nicht das
Vorgehen.

**Die Spanne 4.5 bis 4.8 ist kein Detail.** Zwischen diesen Fassungen liegen
Änderungen an der API und am DNSSEC-Verhalten. Steht die Stufe auf einer
Mindestfassung, gehört sie in den Quelltext und in eine CI-Messung — so wie
`Server::MIN_VERSION` für PostgreSQL seit P5b in der CI gegen den laufenden
Dienst gehalten wird.

**Zu messen, nicht zu vermuten** — auf einem Debian 12 und einem Debian 13:

```bash
apt-cache policy pdns-server pdns-backend-mysql pdns-tools
```

**Meine Einschätzung:** Erst die vier Zahlen, dann die Entscheidung über eine
Mindestfassung. Fällt eine Zielplattform unter das, was die API für DNSSEC
braucht, ist das eine Planentscheidung und kein Fund im Abnahmelauf.

> **Wissen aus zweiter Hand sieht aus wie Wissen.**

---

## 3. Welches Backend?

`gmysql`, `gpgsql`, LMDB oder BIND-Dateien. Das Panel führt MariaDB und
PostgreSQL bereits beide.

**Meine Einschätzung: `gmysql` auf der Datenbank, die das Panel ohnehin
betreibt.** Drei Gründe:

1. **P8 erbt die Sicherung umsonst.** Die Zonendaten liegen dann dort, wo die
   Sicherung schon hinsieht. LMDB wäre ein dritter Datenbestand mit eigener
   Sicherungsgeschichte.
2. **BIND-Dateien holen genau das Problem zurück**, das P5b und P6 teuer bezahlt
   haben — eine Datei, in die zwei Schreiber schreiben.
3. **Der Bestand ist von aussen lesbar**, wenn etwas nicht stimmt; ein
   LMDB-Bestand ist es nicht.

**Was dagegen spricht und gesagt gehört:** Damit hängt der Nameserver an der
Panel-Datenbank. Ist MariaDB weg, ist die Zone weg — und anders als das Panel
ist ein Nameserver etwas, dessen Ausfall Dritte sehen. Ob das gegen einen
eigenen Bestand abzuwägen ist, ist eine Betriebsentscheidung.

---

## 4. Läuft PowerDNS auf demselben Host wie das Panel?

Port 53 kollidiert mit `systemd-resolved`, das auf den Zielplattformen
üblicherweise `127.0.0.53:53` hält. **Ungemessen auf `cloudsrv24`** — hier gibt
es kein systemd.

**Zu messen, nicht zu vermuten:**

```bash
ss -lnup 'sport = :53'; ss -lntp 'sport = :53'
systemctl is-active systemd-resolved
```

**Meine Einschätzung:** Derselbe Host, und PowerDNS bindet ausdrücklich an die
öffentlichen Adressen des Servers statt an `0.0.0.0` — dann stehen beide
nebeneinander, ohne dass am Auflöser des Systems etwas geändert werden muss.
Der Auflöser des Systems anzufassen wäre ein Eingriff, dessen Fehlschlag den
ganzen Server von der Namensauflösung trennt.

Das setzt voraus, dass die öffentlichen Adressen bekannt sind — und der
Rechnername gehört in dieselbe Quelle wie überall sonst:
`SrvPanel\Agent\Names::fqdn()`, geprüft von `HostnameSourceTest`.

---

## 5. Wer ist Nameserver — einer oder mehrere?

AXFR und NOTIFY aus `docs/20 §9` sind nur dann etwas, wenn es Slaves gibt.

**Meine Einschätzung:** Die Frage ist nicht technisch, sondern eine Zusage an
den Kunden. Viele Registries verlangen zwei Nameserver in verschiedenen Netzen,
und ein Panel, das Zonen mit einem einzigen NS ausliefert, produziert Domains,
die bei der Delegierung abgewiesen werden. **Wenn es keinen zweiten Server
gibt**, gehört das in die Zonenvorlage (Frage 6) und in die Oberfläche — und
AXFR/NOTIFY fallen aus P7 heraus, statt ungeprüft mitgebaut zu werden.

> **Was ein Test nicht halten kann, gehört als Frage aufgeschrieben und nicht
> als Zusage.**

---

## 6. Was steht in der Zonenvorlage?

Zu entscheiden, jeweils mit Wert:

- **SOA:** primärer NS, Verantwortlicher, `refresh`, `retry`, `expire`,
  `minimum`. PowerDNS setzt beim Anlegen selbst etwas (M3) — das ist die
  Vorgabe des Dienstes und nicht unsere.
- **TTL** der erzeugten Einträge.
- **NS-Sätze** — hängt an Frage 5.
- **A/AAAA:** Für `@` und `www` auf die Adresse des Servers. Und: ein
  Platzhalter `*` oder nicht?
- **CAA:** für Let's Encrypt — oder keines. Ein CAA-Satz, der die eigene
  Zertifizierungsstelle vergisst, nimmt dem Kunden die Erneuerung.
- **Was nicht hineingehört:** MX, SPF, DMARC. Mailversand ist laut `docs/20`
  eine spätere Stufe; ein MX auf einen Server, der keine Mail annimmt, ist
  schlimmer als keiner.

**Meine Einschätzung:** So wenig wie möglich, und jeder Eintrag mit einem Grund.
Was die Vorlage setzt, muss der Kunde später entweder ändern dürfen (Frage 9)
oder erklärt bekommen — jeder Eintrag ohne beides ist ein Rätsel auf seiner
Seite.

---

## 7. DNSSEC — standardmässig an oder aus?

**Gemessen (M11):** Ein `PUT {"dnssec":true}` genügt. PowerDNS 4.8.3 legt einen
**CSK** mit `ECDSAP256SHA256` an und liefert die DS-Angaben in drei Digest-Arten
über die API zurück. Für den Punkt „DS-Angaben zum Weitergeben" aus `docs/20 §9`
ist damit alles da.

Zwei Dinge, die daran hängen:

- Es ist ein **CSK** und kein KSK/ZSK-Paar. Was „Schlüsselwechsel" aus
  `docs/20 §9` dann genau heisst, ist zu entscheiden — bei einem CSK wechselt
  jeder Wechsel den Schlüssel, dessen DS beim Registrar steht.
- **Das Panel kann den DS beim Registrar nicht setzen.** Es kann ihn nur
  anzeigen. Der Weg für den Kunden ist damit: Panel zeigt an, Kunde trägt beim
  Registrar ein — und **erst danach** darf signiert ausgeliefert werden, sonst
  ist die Domain für validierende Auflöser weg.

**Meine Einschätzung: standardmässig aus, je Zone einzuschalten** — und zwar in
zwei Schritten, nicht in einem: signieren, DS anzeigen, und der Kunde bestätigt,
dass er ihn eingetragen hat. Eine Zone, die signiert wird, während ihr Elternteil
nichts davon weiss, ist nicht kaputt; eine Zone, deren DS beim Registrar steht
und deren Schlüssel wir wechseln, ist es. Das ist der Fall mit der höchsten
Aussenwirkung in dieser ganzen Stufe, und `docs/20 §10` nennt genau ihn als
Grund, warum DNS spät kommt.

---

## 8. „Externer DNS" — je Domain oder je Abonnement?

**Meine Einschätzung: je Domain.** Ein Abonnement kann eine Domain führen, deren
Zone hier liegt, und daneben eine, die anderswo liegt; eine Umschaltung am
Abonnement zwänge beide in dieselbe Betriebsart.

**Und die zweite Hälfte der Frage ist die wichtigere:** Was geschieht mit einer
Zone, die schon geführt wurde, wenn jemand auf „extern" umschaltet? Meine
Einschätzung: **stehenlassen und nicht mehr ausliefern**. Löschen ist der
unumkehrbare Weg, und wer zurückschaltet, hätte seine Einträge sonst verloren.
Dass eine Zone liegengeblieben ist, gehört dann aber sichtbar hingeschrieben —

> **Ein Feld, das geschrieben und nie gelesen wird, ist von aussen nicht von
> einem zu unterscheiden, das es nicht gibt.**

---

## 9. Was darf ein Kunde mit `Feature::DnsEdit` ändern — und was nie?

**Vorab ein Fund, der die Frage verschiebt.** `Feature::DnsEdit` (`dns_edit`)
gibt es bereits, und es bedeutet heute etwas anderes als das, was P7 braucht:
Es gibt einem Abonnement ein **eigenes DNS-01-Profil für ACME** — also
Zugangsdaten für einen fremden Anbieter (`app/Support/Tls/DnsProfile.php`,
`SubscriptionPolicy`). Sein Hilfetext lautet „Ohne diese Freigabe verwaltet der
Betreiber die Zone; das Abonnement sieht sie nur."

Wer dieselbe Freigabe für das Bearbeiten eigener Zoneneinträge benutzt, hat
**eine Fahne mit zwei Bedeutungen**: Ein Plan, der einem Kunden erlauben will,
seine A-Einträge zu setzen, gibt ihm damit zugleich die Ablage eigener
Registrar-Token — und umgekehrt.

**Meine Einschätzung: eine zweite Freigabe**, und `Feature::DnsEdit` behält
seine heutige Bedeutung. Der Hilfetext dort passt zufällig auf beides, und genau
daran fällt es sonst niemandem auf.

**Und was nie geändert werden darf**, meine Einschätzung als Vorschlag zum
Abnicken oder Streichen:

- der **SOA**-Satz,
- die **NS**-Sätze der Zone,
- die **A/AAAA-Einträge, die das Panel selbst gesetzt hat** — sie zeigen auf den
  Server, auf dem die Website des Kunden liegt; wer sie ändert, nimmt seine
  eigene Seite vom Netz und ruft danach den Betreiber an,
- **DNSSEC** und alles, was an Schlüsseln hängt,
- Einträge **ausserhalb der eigenen Zone**.

Offen und zu entscheiden: Darf ein Kunde eine **Unterzone delegieren** (eigene
NS unterhalb seiner Zone)? Das ist der Fall, in dem „nie NS ändern" zu weit
greift.

---

## 10. Vorrang, wenn beides da ist

Ein Abonnement hat DNS-01-Zugangsdaten für einen fremden Anbieter hinterlegt,
und die Zone liegt jetzt lokal. Wer gewinnt?

**Meine Einschätzung: die lokale Zone** — sie ist die, die dieser Server
tatsächlich ausliefert, und ein TXT-Eintrag bei einem Anbieter, der die Zone
nicht mehr führt, wird von der Zertifizierungsstelle nie gesehen. Der Fehlschlag
verbraucht dabei einen der fünf Fehlversuche je Konto und Stunde, und die gelten
für **jeden** Kunden dieses Servers (`docs/34 §11`).

**Aber nicht still.** Die hinterlegten Zugangsdaten sind damit für diese Domain
wirkungslos, und das gehört hingeschrieben statt weggelassen — sonst sucht
jemand den Fehler bei einem Token, das gar nicht mehr gefragt wird.

Damit löst P7 nebenbei den Faden aus P4 ein, den `docs/20` als „DNS-01 gegen die
eigene Zone (nach P7 automatisch)" führt.

---

## 11. Was mir beim Lesen zusätzlich aufgefallen ist

Keine Fragen an den Betreiber, aber Punkte für den Plan:

1. **Es gibt keine Zonen- oder Eintragstabelle.** Die `domains`-Tabelle trägt
   nichts DNS-Eigenes. Ob das Panel die Einträge **spiegelt** oder bei jeder
   Ansicht die API fragt, ist eine Planentscheidung mit Folgen: Ein Spiegel ist
   ein zweiter Bestand, der veralten kann; eine Abfrage bei jeder Ansicht macht
   die Seite von einem Dienst abhängig, der auch stillstehen kann.
2. **Wo sucht jemand diese Handlung?** Dreimal in P6 ist ein Merkmal drei Klicks
   tief gelandet und musste verlegt werden. Für „Einträge bearbeiten" ist das
   vorab zu beantworten und nicht in der Bilderrunde.
3. **Der Nameserver-Name gehört zu `Names::fqdn()`** und nirgendwo sonst hin —
   die Quelle ist viermal neu erfunden worden, seither hält `HostnameSourceTest`
   sie fest.
4. **Die Zonenvorlage ist eine Vorlage.** Ändert sie sich, ändern sich damit
   nicht die Zonen, die schon stehen. Ob und wie nachgezogen wird, gehört in den
   Plan und nicht in den Abnahmelauf.

---

## 12. Was als Nächstes geschieht

1. Der Betreiber beantwortet die zehn Fragen — **Frage 1 zuerst**, sie
   entscheidet den Zuschnitt.
2. Die Messrunde wird als eigenes Dokument angelegt und gegen ein echtes
   PowerDNS gefahren, in dem Umfang, den die Antworten setzen. Der Wegwerf-Dienst dafür steht in diesem
   Container und ist heute gefahren worden.
3. Erst danach der Plan mit dem neu gefassten Abnahmekriterium, den Schritten,
   den Risiken und dem Abschnitt „Was P7 ausdrücklich **nicht** wird".

> **Eine Ausbaustufe gilt erst als fertig, wenn ihr Abnahmekriterium
> nachweisbar erfüllt ist — gemessen auf einem echten Server, nicht geschätzt.**
