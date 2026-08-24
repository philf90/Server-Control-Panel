# A9 — zwei Verwaltungsrollen und die Kontenverwaltung

*Der Plan zu A9 aus `docs/81 §11`, vorgezogen vom Betreiber am 24. August 2026
und damit das zweite Merkmal von P7b. Die Skizze dort trägt die Rollen; was ihr
fehlt, ist die **Kontenverwaltung** — und die ist der Grund für dieses
Dokument.*

---

## 0. Warum dieses Dokument entstanden ist

Der Betreiber hat gefragt: *Wann kann ich weitere Admins anlegen und
festlegen, wer davon Betreiber und wer Administrator ist?*

Beim Nachsehen am Quelltext stellte sich heraus, dass die Antwort in zwei
Teilen fehlt, nicht in einem:

1. **Rollen gibt es nicht** — das ist A9, und das steht in `docs/81 §11`.
2. **Eine Kontenverwaltung gibt es überhaupt nicht.** Adminkonten entstehen
   ausschliesslich über `srvpanel admin` auf der Kommandozeile. Kein Controller
   in `app/Http/Controllers/` nennt `AccountType::Admin`; es gibt weder eine
   Liste noch ein Formular.

`docs/81 §11` führt in seiner Tabelle die Zeile „Konten, Rollen,
IP-Beschränkung — Betreiber ja, Administrator nein" und setzt damit voraus,
dass es diese Seite gibt. **Sie ist nirgends ausgeschrieben.**

> **Eine Tabelle, die eine Fähigkeit einer Rolle zuordnet, setzt voraus, dass es
> die Fähigkeit gibt — und sagt nichts darüber, ob sie jemand gebaut hat.**

---

## 1. Der Bestand, gemessen am Quelltext

**Nicht aus dem Gedächtnis.** Wer hier weiterbaut, fängt bei dieser Tabelle an
und nicht bei null.

| | Zustand |
|---|---|
| `accounts.type` | `admin` · `customer` · `additional` (`AccountType`) |
| `accounts.status` | `active` · `disabled` (`AccountStatus`), mit `canSignIn()` |
| Wer fragt `canSignIn()` | `LoginController`, `TwoFactorChallengeController`, `ImpersonationController` — **drei Stellen, alle vorhanden** |
| Zweiter Faktor | Spalten und Einrichtungsseite da (`/settings/two-factor`), **nirgends erzwungen** |
| Sitzungen | Treiber `database`, Tabelle mit `user_id`, `ip_address`, `user_agent`, `last_activity` — **alles da, was eine Übersicht braucht** |
| Anmeldespuren | `last_login_at`, `last_login_ip` am Konto |
| Adminkonto anlegen | nur `srvpanel admin <adresse>` (`CreateAdmin`), mit `--generate` |
| Kontenverwaltung in der Oberfläche | **gibt es nicht** |
| Rolle | **gibt es nicht** |
| Fähigkeiten | `AdminAbility` mit `operate-server` und `manage-settings`, seit 24. August — **beide lösen auf `isAdmin()` auf** |

**Zwei Dinge sind damit schon da, die A9 sonst hätte bauen müssen:** das
Sperren eines Kontos (`status`) und die Datenlage für die Sitzungsübersicht.

**Und eines ist da, das die Skizze nicht kannte:** die Naht. `AdminAbility`
ordnet jeder Adminfähigkeit ihre Rolle zu, jede Adminroute wählt beim Bauen
ihre Seite, und `AdminAbilityTest` besteht darauf. A9 ändert deshalb **die
Auflösung der Gates** und keine Aufrufstelle.

### 1.1 Der Fund, der den Entwurf entscheidet

`audit_events.account_id` und `operations.account_id` stehen auf
**`nullOnDelete()`**. Der Kommentar daneben sagt, warum:

> *Wer ihn ausgelöst hat. Bleibt beim Löschen des Kontos stehen — ein
> Protokolleintrag, der mit dem Konto verschwindet, ist als Protokoll wertlos.*

Der **Eintrag** bleibt also stehen. Der **Handelnde** nicht: Ein gelöschtes
Adminkonto zieht seine ganze Geschichte auf `null`. Wer danach fragt, wer im
Mai die Paketquellen geändert hat, bekommt eine Zeile ohne Namen.

> **Ein Protokoll, aus dem sich der Handelnde nachträglich entfernen lässt, ist
> kein Protokoll — es ist eine Liste von Ereignissen.**

**Daraus folgt Entscheidung 1 (unten): Adminkonten werden gesperrt, nicht
gelöscht.**

---

## 2. Was A9 baut

### 2.1 Die Rolle am Konto — und ausdrücklich kein vierter `AccountType`

`docs/81 §11` und `docs/79 §5.3` begründen das ausführlich; hier die Kurzform,
weil sie die erste Zeile Code entscheidet:

```php
public function isAdmin(): bool           { return $this === self::Admin; }
public function belongsToCustomer(): bool { return $this !== self::Admin; }
```

Beide sind als **Gleichheit mit einem Fall** geschrieben. Ein vierter Fall
`Superadmin` wäre augenblicklich `isAdmin() === false` und
`belongsToCustomer() === true` — an 52 Stellen. Die Mandantenklammer setzte ihn
auf `whereRaw('0 = 1')`, und der neue Betreiber sähe eine **leere Kundenliste**.

> **Ein Fehler, der zur sicheren Seite fällt, fällt trotzdem — und er fällt
> leise.**

`AccountTypeAxisTest` steht seit dem 24. August als Stolperdraht davor.

**Gebaut wird deshalb:** eine Spalte `role` an `accounts`, `operator` oder
`administrator`, nicht nullable, Vorgabe `operator` für bestehende Konten (siehe
§5.1). `AccountType::Admin` bleibt für beide.

### 2.2 Die Auflösung der Gates

Heute:

```php
foreach (array_keys(AdminAbility::abilities()) as $ability) {
    Gate::define($ability, static fn (Account $account): bool => $account->isAdmin());
}
```

Danach: dieselbe Schleife, und die Auflösung fragt die Rolle der Fähigkeit
gegen die Rolle des Kontos. **Eine Zeile** — keine Aufrufstelle in
`routes/web.php`, kein Schlüssel in einer `can`-Ablage, kein Bild.

### 2.3 Feiner als eine Seite

`docs/81 §11` ist an drei Stellen feiner als „pro Seite":

| Bereich | Betreiber | Administrator |
|---|---|---|
| Pakete und Updates | ja | **sehen ja, einspielen nein** |
| Paketquellen | ja | **sehen ohne Schlüssel, schalten nein** |
| Dienste | ja | **Zustand ja, `restart`/`reload` ja, `stop`/`disable` nein** |

Das sind drei Fähigkeiten mehr, keine neue Bauart: `AdminAbility` nimmt sie auf,
und die betroffene Route trägt sie. **A2 ist die erste Stufe, die das braucht** —
und A2 kommt nach A9, weil der Betreiber A9 vorgezogen hat. Das ist die
Reihenfolge, die sich das spart.

### 2.4 Die Kontenverwaltung — der Teil, der in der Skizze fehlt

Eine Seite **„Konten"** in der Gruppe „Server", **nur für den Betreiber**.

**Liste.** Name, Anmeldeadresse, Rolle, Zustand, zweiter Faktor eingerichtet
ja/nein, letzte Anmeldung. Nur Adminkonten — Kundenkonten stehen am Kunden, und
zwei Listen derselben Zeilen wären zwei Wege zum selben Ort.

**Anlegen.** Name, Adresse, Rolle. **Kein Passwortfeld:** Das Passwort wird
erzeugt und einmalig angezeigt, wie bei `srvpanel admin --generate`. Ein
Betreiber, der das Passwort eines anderen Kontos wählt, kennt es danach.

> **Ein Passwort, das jemand für einen anderen setzt, ist ein gemeinsames
> Geheimnis — und im Protokoll steht später nur einer von beiden.**

**Ändern.** Name, Rolle, Zustand. **Nicht die Adresse** im ersten Wurf: Sie ist
die Anmeldung und steht im Protokoll; ihr Wechsel ist ein eigener Vorgang mit
Bestätigung und gehört nicht in ein Formular, das auch den Namen ändert.

**Sperren statt löschen** (§1.1). Der Zustand `disabled` gibt es bereits, und
drei Stellen fragen ihn schon.

**Passwort zurücksetzen.** Erzeugt ein neues und zeigt es einmalig. Damit
verliert `srvpanel admin` seine Rolle als einziger Weg — bleibt aber als
Rückweg (§3, Falle 3).

### 2.5 Zugang: IP-Beschränkung, erzwungener zweiter Faktor, Sitzungen

Aus der Skizze übernommen, unverändert im Umfang:

- **IP-Beschränkung der Panel-Anmeldung.** Eine Liste von Netzen; leer heisst
  „von überall". **Sie gilt für Adminkonten**, nicht für Kunden — ein Kunde, der
  sich aus dem Urlaub nicht anmelden kann, ist ein Ausfall.
- **Erzwungener zweiter Faktor** für Adminkonten, als Schalter. Wer ihn
  einschaltet und selbst keinen eingerichtet hat, wird zuerst zur Einrichtung
  geführt — sonst sperrt der Schalter seinen eigenen Urheber aus.
- **Sitzungsübersicht** je Konto mit „hier abmelden". Die Daten liegen in
  `sessions`; gezeigt werden IP, Gerät und letzte Aktivität.

---

## 3. Die drei Fallen

Aus `docs/81 §11` übernommen, weil ein Plan, der auf sie verweist statt sie zu
tragen, sie beim Bauen nicht zur Hand hat.

**Erstens: Verbergen ist nicht Schützen.** Wer die DNS-Zugangsdaten nicht
*sieht*, aber eine Zertifikatsbestellung auslösen darf, die sie benutzt, für den
ist das Geheimnis weiterhin wirksam.

> **Eine Seite, die man nicht sieht, ist keine Grenze, solange ein Knopf daneben
> dasselbe bewirkt.**

Geteilt wird nach **Wirkung**, nicht nach Bildschirm. Deshalb steht
„PHP-Versionen installieren" beim Betreiber: `php.version.install` ruft
`apt-get install`.

**Zweitens: Wer Konten anlegt, legt seine eigene Rolle an.** Ein Administrator
darf ohnehin keine Konten verwalten (§2.4) — aber die Prüfung gehört an
**dieselbe** Stelle wie die Rolle und nicht an eine zweite daneben, sonst ist sie
beim nächsten Weg zum Konto nicht dabei.

**Drittens: Die Aussperrung.** Es muss immer mindestens einen **aktiven**
Betreiber geben. Der letzte lässt sich weder herabstufen noch sperren, und die
Meldung sagt warum.

**Und der Rückweg gehört geprüft, nicht angenommen.** `srvpanel admin` gibt es;
ob er ein Konto, das sich selbst ausgesperrt hat, wieder brauchbar macht, hat
niemand nachgesehen. In dieser Stufe wird es gemessen.

> **Ein Rückweg, den niemand gegangen ist, ist eine Zusage und kein Weg.**

---

## 4. Was ausdrücklich **nicht** gebaut wird

**Kein Rechte-Baukasten.** `docs/20 §6.1` legt es fest, und die Begründung
trägt über den Geschmack hinaus:

- **Die Trennlinie ist eine Sicherheitszusage, keine Vorliebe.** Sie lautet:
  verleiht root auf Dauer · nimmt alle Kunden mit · zeigt ein Geheimnis. Ein
  Kästchen, das einem Administrator „DNS-Zugangsdaten sehen" gibt, macht das
  nicht sicher.
- **Ein Baukasten muss in jeder Kombination stimmen.** Falle 1 oben ist genau
  so eine Kombination: Im festen Modell hat sie jemand einmal durchdacht; in
  einer Matrix stellt der Betreiber sie selbst her, und das Panel kann nicht
  warnen, weil es nicht weiss, welche Fähigkeit welche impliziert.
- **`AbilityReachTest` kann seine Regel nur halten, solange die Zuordnung zur
  Prüfzeit bekannt ist.** Mit einer Matrix in der Datenbank prüft er den
  Mechanismus und nicht mehr das Ergebnis.

**Die Asymmetrie zum Kunden ist Absicht und schon gebaut.** Für
**Zusatzbenutzer** gibt es sehr wohl einen feinen Rechtekatalog mit eigenem
`PermissionEditor`. Dort ist die Sprengweite ein Abonnement; auf der
Adminebene ist sie der ganze Server.

**Wenn zwei Rollen zu grob sind**, ist die ehrliche Erweiterung eine **dritte
benannte Rolle mit fester Zuordnung** — nicht eine freie Matrix. Dann bleibt
jede Kombination eine, über die jemand nachgedacht hat. Diese Entscheidung
gehört dem Betreiber und steht hier, damit sie beim nächsten Anlass nicht neu
verhandelt wird.

**Kein Wechsel der Anmeldeadresse** im ersten Wurf (§2.4). **Kein Löschen von
Adminkonten** (§1.1). **Keine Rollen für Kundenkonten** — dafür gibt es den
Rechtekatalog.

---

## 5. Was beim Bauen schiefgehen kann

### 5.1 Die Migration bestehender Konten

Jedes vorhandene Adminkonto darf heute alles. Es wird deshalb **Betreiber** —
alles andere wäre eine stille Rechteentziehung auf einem laufenden Server.

> **Eine Migration, die Rechte wegnimmt, sperrt jemanden aus, der gestern noch
> hineinkam — und die Meldung dazu sagt nichts über die Migration.**

### 5.2 `isAdmin()` bleibt, was es war

An 52 Stellen heisst es „kein Kunde". Wer es beim Bauen zu „ist Betreiber"
umdeutet, nimmt dem Administrator die Kundenverwaltung — also genau die Arbeit,
für die es ihn gibt.

### 5.3 Der Kommentar an `AccountType` wird falsch

Dort steht: *„Bewusst kein Rollen- und Rechte-Baukasten: Drei feste Ebenen
decken den Bedarf eines Hosting-Panels ab."* Der Satz stimmt weiter für die
**Mandantenebene** und nicht mehr als Ganzes.

> **Ein Kommentar, der eine Entscheidung begründet, wird zur Falschaussage, wenn
> die Entscheidung sich ändert — und er wird nicht mitgeändert, weil er im Diff
> nicht auffällt.**

### 5.4 Was der Administrator nicht sehen darf, wird nicht geschickt

Das Vorbild steht in `OverviewController`: Er verzweigt **serverseitig** und
erhebt die Serverwerte für einen Kunden gar nicht erst — weil ein `v-if` die
Daten trotzdem an den Browser schickt.

---

## 6. Das Abnahmekriterium

Sieben Punkte, gemessen auf einem echten Server.

1. Ein Betreiber legt in der Oberfläche ein zweites Adminkonto mit der Rolle
   **Administrator** an; das Passwort wird **einmalig** angezeigt und ist danach
   nicht wieder abrufbar.
2. Dieses Konto meldet sich an, sieht die Kunden, Abonnements und Domains und
   kann damit arbeiten.
3. Es bekommt auf `/settings/dns`, `/settings/mail`, `/settings/tls`,
   `/settings/database`, `/settings/php` und `/logs` einen **403** — und in der
   Inertia-Antwort der Seiten, die es sehen darf, steht **kein Feld**, das es
   nicht sehen darf. Gemessen an der Antwort, nicht am Bild.
4. Der Menüpunkt „Konten" und die Gruppe der Geheimnisseiten stehen in seiner
   Navigation **nicht** — und die Antwort darauf kommt aus der Policy, nicht aus
   einem `v-if` auf die Rolle.
5. Der **letzte** Betreiber lässt sich weder herabstufen noch sperren; die
   Meldung sagt, warum.
6. `srvpanel admin` macht ein ausgesperrtes Konto wieder brauchbar —
   **ausgeführt, nicht angenommen**.
7. Ein Adminkonto, das gesperrt wird, kann sich nicht mehr anmelden, und seine
   Einträge im Protokoll tragen weiterhin seinen Namen.

**Nicht Gegenstand der Abnahme, aber Teil des Laufs:** ein Bildsatz bei 390 px
und 1440 px in beiden Themes nach `tests/bilder-messen.js`, für Liste, Formular
und die einmalige Passwortanzeige.

---

## 7. Die Schritte

| # | Schritt | Fertig, wenn |
|---|---|---|
| 0 | Bestand messen (§1) | **erledigt** — die Tabelle oben |
| 1 | Rolle am Konto: Migration, Enum, Model, `AccountTypeAxisTest` erweitern | ein bestehendes Adminkonto ist danach Betreiber, und `isAdmin()` bedeutet unverändert „kein Kunde" |
| 2 | Auflösung der Gates über die Rolle; `AdminAbility` um die drei feineren Fähigkeiten (§2.3) | ein Administrator bekommt auf den sechs Geheimnisseiten 403 |
| 3 | Kontenverwaltung: Liste, Anlegen mit erzeugtem Passwort, Ändern, Sperren | ein zweites Adminkonto entsteht ohne SSH |
| 4 | Aussperrschutz (§3, Falle 3) und die Messung von `srvpanel admin` | der letzte Betreiber lässt sich nicht herabstufen, und der Rückweg ist **gegangen** |
| 5 | Die Fläche: Menü, `can`-Ablage, nichts Verbotenes im Payload (§5.4) | Punkt 3 und 4 des Kriteriums, gemessen an der Antwort |
| 6 | Bilder bei 390 und 1440 px in beiden Themes | `tests/bilder-messen.js` meldet 0 px mit ausschlagender Gegenprobe |
| 7 | Erzwungener zweiter Faktor, IP-Beschränkung, Sitzungsübersicht | ein Schalter, der seinen eigenen Urheber nicht aussperrt |
| 8 | Wächter brechen, Lauf von `tests/waechter-brechen.sh` | jeder Eingriff beisst — einzeln **und** im Lauf |
| 9 | Der Abnahmelauf auf `cloudsrv24` | die sieben Punkte aus §6 |

**Aufwand: 1,5 bis 2 Wochen.** Die Schritte 1 bis 5 sind der Kern und
beantworten die Frage des Betreibers; 7 ist der Zugangsteil und liesse sich
abtrennen, wenn die Stufe kürzer werden soll.

---

## 8. Die Wächter

| Wächter | Regel | Der Bruch |
|---|---|---|
| `AccountTypeAxisTest` (erweitert) | `AccountType` bekommt keinen vierten Fall, und die Rolle steht nicht darin | einen Fall `Superadmin` ergänzen |
| `AdminAbilityTest` (erweitert) | Jede Adminroute gehört einer Rolle; die Voreinstellung ist der Betreiber | eine Geheimnisseite auf die schwächere Fähigkeit legen |
| `RoleResolutionTest` | Die Gates lösen über die Rolle auf und nicht über `isAdmin()` | die Auflösung auf `isAdmin()` zurückdrehen |
| `LastOperatorTest` | Der letzte aktive Betreiber lässt sich nicht herabstufen, sperren oder löschen | die Prüfung an einer der drei Stellen entfernen |
| `AdminPayloadTest` | Was eine Rolle nicht sehen darf, steht nicht in der Inertia-Antwort | ein verbotenes Feld bedingungslos mitschicken |

**`LastOperatorTest` prüft alle drei Wege**, und das ist der Punkt: Eine
Aussperrung, die über das Sperren statt über das Herabstufen läuft, ist
dieselbe Aussperrung.

> **Eine Prüfung, die einen von drei Wegen kennt, ist keine Schranke, sondern
> ein Hinweisschild an einer von drei Türen.**

---

## 9. Was benannt offen bleibt

- **Der Wechsel der Anmeldeadresse** (§2.4) — bewusst nicht in diesem Wurf.
- **Das Löschen von Adminkonten** (§1.1) — solange das Protokoll den Handelnden
  über `nullOnDelete()` verliert, ist Sperren die ehrlichere Antwort. Wer es
  später bauen will, löst zuerst die Frage, wie der Name im Protokoll bleibt.
- **A3, A4 und A7** haben weiterhin keine Stufe (`docs/20 §9`). A9 ist mit
  dieser Entscheidung aus dieser Gruppe herausgelöst.
- **Ob zwei Rollen genügen** (§4). Die Entscheidung ist getroffen und
  begründet; sie ist nicht endgültig, und der Weg für eine dritte steht da.
