# Pläne und Kontingente

Ergänzt §5.2 des Plans um das, was beim Bauen entschieden wurde. Verbindlich,
weil geprüft: `tests/Unit/QuotaCatalogTest.php` und `tests/Feature/PlanTest.php`.

## 1. Ein Katalog, keine Zeichenketten

Die Kontingente liegen als JSON an Plan und Abonnement — das muss so sein,
weil ein Abonnement einzelne Werte übersteuert und dabei „nicht gesetzt" von
„auf 0 gesetzt" unterscheiden muss. Die **Schlüssel** sind deshalb trotzdem
nicht frei: Sie stehen in `App\Support\Plans\Quota`, die Freigaben in
`App\Support\Plans\Feature`, und beide tragen Beschriftung, Hinweis, Einheit,
Grenzen und Vorgabewert gleich mit.

Vorher standen sie an vier Stellen als Literale — Factory, Policy, Formular,
Prüfung. Ein Tippfehler in einer davon fällt nicht auf: `datenbanken` statt
`databases` liefert kein Kontingent, und kein Kontingent sieht aus wie
unbegrenzt.

Die Oberfläche kennt kein Kontingent beim Namen. `Plans/Form.vue` rendert, was
im Katalog steht; ein neues Kontingent braucht keine Zeile Vue.

## 2. Was `null` heißt

**Kein Wert heißt unbegrenzt. `0` heißt null Stück.** Das ist eine Aussage und
keine Lücke — „keine Datenbanken" ist ein gültiges Paket. Genau deshalb liest
`Subscription::quota()` mit `array_key_exists` und nicht mit `??`: Eine
Übersteuerung auf `0` oder `null` darf nicht stillschweigend auf den Planwert
zurückfallen.

## 3. Zwei Kontingente dürfen nicht unbegrenzt sein

**Speicherplatz** und **FPM-Prozesse**. Beide teilen sich eine Ressource, die
der ganze Server teilt: Ein Abonnement ohne Speichergrenze füllt das
Dateisystem und nimmt jedes andere mit, ein FPM-Pool ohne Obergrenze belegt den
Arbeitsspeicher und ebenso. Die übrigen kosten im schlimmsten Fall Ordnung.

Ein leeres Feld wird deshalb bei diesen beiden abgewiesen und fällt nicht auf
„unbegrenzt" — die Prüfregel lässt `null` dort gar nicht zu. Kommt eine weitere
Ausnahme dazu, zwingt `test_only_shared_resources_have_no_unlimited` dazu, den
Grund aufzuschreiben.

**Inzwischen sind es fünf**, nicht zwei: Mit P3 kamen die drei PHP-Deckel dazu
(`php_memory_mb`, `php_upload_mb`, `php_execution_seconds`), aus demselben
Grund. Der Test heisst seitdem `test_only_shared_resources_have_no_unlimited`;
in dieser Zeile stand bis P5 sein alter Name. Folgenlos, aber es ist genau das
Muster aus CLAUDE.md — *eine Zeichenkette, die auf etwas verweist, ohne dass
etwas den Bezug prüft* —, diesmal in einem Dokument statt im Quelltext.

**`database_mb` ist ausdrücklich keine dritte Ausnahme** (P5, `docs/36 §9`): Es
darf unbegrenzt sein, weil es nichts durchsetzt. MariaDB kennt keine Obergrenze
je Schema. Was den Datenträger begrenzt, ist `disk_mb` — und `/var/lib/mysql`
liegt ausserhalb der Dateisystem-Quota des Systembenutzers. Ein Kunde kann
seinen Speicherplatz einhalten und den Datenträger über seine Datenbank füllen;
P5 misst es und macht es sichtbar, erzwungen wird es dort nicht.

## 4. Der Standardplan

Es gibt genau einen. Er ist der Plan, den ein neues Abonnement bekommt.

- Das **Setzen** an einem Plan nimmt ihn dem bisherigen — in einer Transaktion.
- Das **Abwählen** tut nichts. Ohne Standardplan bekäme ein neues Abonnement
  keinen, und der Fehler zeigte sich beim nächsten Anlegen statt bei dem
  Häkchen, das ihn verursacht hat. Der Standard wechselt, indem man ihn
  woanders setzt.
- Beim **Löschen** rückt der älteste verbliebene Plan nach.
- `srvpanel setup` legt einen an, wenn keiner da ist. Das ist kein
  Testdatensatz — er trägt keine Zugangsdaten, und ohne ihn stünde der
  Betreiber vor einem Formular, das nach einem Plan fragt, den es nicht gibt.

## 5. Eine Planänderung wirkt sofort — und löscht nichts

Das ist der Sinn einer Vorlage und zugleich das, was sie gefährlich macht. Wer
die Datenbanken von fünf auf zwei setzt, tut das für jeden Kunden in diesem
Plan. Deshalb steht die Zahl der gebundenen Abonnements in der Liste **und**
über dem Formular.

Gesenkte Grenzen verbieten das Anlegen des nächsten Objekts; vorhandene bleiben
bestehen. Wer zwei Datenbanken über der neuen Grenze liegt, behält sie und kann
keine dritte anlegen. Alles andere wäre eine Datenlöschung durch eine
Formularänderung.

Ein Plan mit gebundenen Abonnements lässt sich nicht löschen. Der Fremdschlüssel
weist es ohnehin ab — die Prüfung im Controller ist dieselbe Aussage in
verständlich.

## 6. Freigabe und Recht

Die **Freigabe** (`Feature`) sagt, ob ein Abonnement eine Funktion überhaupt
hat; sie ist Vertragssache und steht im Plan. Das **Recht** (`Permission`)
sagt, wer innerhalb des Abonnements sie benutzen darf; das entscheidet der
Kunde für seine Zusatzbenutzer. Beides muss zutreffen —
`SubscriptionPolicy::useFeature()` prüft Zugang, Freigabe und Recht.

Die Zuordnung zwischen beiden steht in `Feature::permission()` und nicht mehr
als `match` mit vier Zeichenketten in der Policy. Was keiner Freigabe
zugeordnet ist, ist keine planabhängige Funktion und damit immer erlaubt —
Dateien lesen gehört zu jedem Abonnement.

## 7. PHP-Versionen

`Quota::PHP_VERSIONS` ist kein Betreiberwunsch, sondern eine Zusage: Für jede
Version darin muss es eine FPM-Vorlage, einen Paketnamen und einen Handler
geben. Eine Version hinzunehmen heißt, diese drei Dinge mitzuliefern.

Ein Plan ohne erlaubte Version wird abgewiesen. Ein Abonnement ohne Handler
liefert nichts aus; das ist kein kleines Paket, sondern ein kaputtes.
