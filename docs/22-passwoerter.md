# 22 — Passwörter

> **Kurzfassung.** Die Richtlinie steht an genau einer Stelle:
> `App\Support\Passwords\Policy`. Von dort kommen die Validierungsregeln, die
> Prüfung in der Kommandozeile und — über Inertia — die Prüfliste im Browser.
> Jedes Feld, in das jemand ein *neues* Passwort eingibt, benutzt
> `resources/js/Components/PasswordFields.vue`.

## 1. Warum das eine eigene Vorgabe braucht

Vor dieser Seite stand die Regel an drei Stellen:

- `'min:12'` in `CustomerController::store()`,
- `mb_strlen($password) < 12` im Kommando `srvpanel:admin`,
- der Satz „Mindestens zwölf Zeichen. Der Kunde kann es später ändern." unter
  dem Feld in `Customers/Create.vue`.

Drei Stellen, dieselbe Zahl, keine Verbindung. Wer die Richtlinie verschärft,
ändert zwei davon und übersieht die dritte — und die dritte ist die, die der
Benutzer liest. Genauso konnte die Kommandozeile ein Passwort setzen, das die
Oberfläche abgelehnt hätte: für ein Adminkonto, also für das Konto, das alles
darf.

Dazu kam, was gar nicht dastand. „Mindestens zwölf Zeichen" ist keine
Richtlinie, sondern eine Länge. `passwortpasswort` erfüllt sie.

## 2. Die Anforderungen

| Schlüssel | Anforderung |
|---|---|
| `length` | Mindestens 12 Zeichen |
| `lowercase` | Ein Kleinbuchstabe |
| `uppercase` | Ein Großbuchstabe |
| `digit` | Eine Ziffer |
| `symbol` | Ein Sonderzeichen |

Der **Schlüssel** ist der Vertrag zwischen Server und Oberfläche. `Policy`
liefert ihn samt Beschriftung; `PasswordFields.vue` bildet ihn auf eine Prüfung
im Browser ab. Kommt eine Anforderung dazu, die die Komponente nicht kennt,
schlägt `PasswordPolicyTest` an — sonst stünde in der Prüfliste eine
Anforderung, die sich nie erfüllen lässt, weil niemand sie prüft.

Die Obergrenze von 1024 Zeichen ist keine Schikane. Sie ist die Schranke gegen
ein Passwort, dessen Hashen mit Argon2id den Prozess minutenlang beschäftigt.

## 3. Was nicht geprüft wird, und warum

**Kein Abgleich gegen bekannte Leaks.** Laravels `uncompromised()` fragt dafür
die API von haveibeenpwned an. Ein Panel, das beim Anlegen eines Kunden auf eine
fremde Website wartet, ist ein Panel, das ohne Internetzugang keine Kunden
anlegt — und ein Hosting-Server steht nicht selten hinter einer Firewall, die
ausgehend nichts durchlässt. Die Prüfung wäre wertvoll; sie gehört an eine
Stelle, an der ihr Ausfall folgenlos ist, nicht in den Weg beim Anlegen.

**Kein erzwungener Wechsel nach N Tagen.** Das NIST hat die Empfehlung 2017
zurückgenommen (SP 800-63B): Ein erzwungener Wechsel führt zu `Sommer2024!` →
`Sommer2025!`, also zu vorhersagbaren Passwörtern. Gewechselt wird bei Verdacht.

**Keine Wortliste in der Stärkeanzeige.** Die Anzeige rechnet Entropie aus Länge
und benutztem Zeichenvorrat, mit einem Abschlag für Wiederholung. Das ist eine
Untergrenze und keine Note: `Sommer2024!` bekommt dort rund 72 Bit und fällt
gegen eine Wörterbuchliste in Sekunden. Eine ehrliche Anzeige braucht zxcvbn
oder Verwandte — rund 400 KiB Wortlisten im Bundle. Das ist eine Abhängigkeit
mit Ansage, keine, die nebenbei hereinkommt. Bis dahin steht unter der Leiste,
worauf sie beruht. **Eine Anzeige, die mehr behauptet, als sie weiß, ist
schlechter als keine.**

## 4. Erzeugen

Zwei Wege, und sie erzeugen an unterschiedlichen Orten:

- **Im Browser** (`PasswordFields.vue`, Knopf „Passwort erzeugen"), über
  `crypto.getRandomValues`. Ein Passwort, das der Server erzeugt und ausliefert,
  steht in jedem Puffer auf dem Weg: im Zugriffslog eines Reverse-Proxys, in der
  Antwort, im Speicher des Browsers. Es bleibt auf dem Gerät.
- **Auf dem Server** (`Policy::generate()`), für `srvpanel:admin --generate`.
  Dort gibt es keinen Browser, und die Ausgabe geht auf ein Terminal, auf dem
  ohnehin root sitzt.

Beide nehmen aus jeder Zeichengruppe mindestens eines und mischen danach.
Ohne das Mischen stünden die vier Pflichtzeichen immer vorn — ein Passwort,
dessen erste vier Stellen ihre Zeichenklasse verraten, ist um genau diese
Information schwächer. Beide ziehen gleichverteilt und nicht über `% n`: Bei
26 Buchstaben sind die ersten Reste sonst messbar häufiger.

Ausgelassen sind `l`, `I`, `O`, `0` und `1`. Wer ein erzeugtes Passwort abtippt
oder durchtelefoniert, verwechselt sie — und ruft dann an.

## 5. Wo die Komponente hingehört

`PasswordFields.vue` ist für jedes Feld zuständig, in das jemand ein **neues**
Passwort eingibt: Kunde anlegen, eigenes Passwort ändern, Zurücksetzen.

Für die **Anmeldung** gilt sie nicht. Dort ist das Passwort entweder das
richtige oder nicht; eine Prüfliste daneben sähe aus, als könne man es an dieser
Stelle ändern. Was die Anmeldemaske übernimmt, ist allein das Augensymbol — auf
einem Handy vertippt man sich an einem langen Passwort aus dem Passwortspeicher
sonst dreimal.

## 6. Wenn sich etwas ändert

Eine Änderung gehört an zwei Stellen gleichzeitig:

1. `App\Support\Passwords\Policy` — Anforderung samt Schlüssel und Beschriftung,
2. `CHECKS` in `resources/js/Components/PasswordFields.vue` — die Prüfung dazu.

Die Tabelle in Abschnitt 2 und beide Stellen prüft
`tests/Feature/PasswordPolicyTest.php` gegeneinander. Der Test ist die einzige
Verbindung zwischen PHP und TypeScript, die es hier gibt; ohne ihn wäre die
Prüfliste im Browser wieder eine Behauptung über die Validierung.
