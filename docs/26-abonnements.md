# Abonnements

Die Bedienung zu den vier Operationen des Agenten. Verbindlich, weil geprüft:
`tests/Feature/SubscriptionTest.php`.

## 1. Jede Systemänderung ist ein Vorgang

Anlegen, sperren, entsperren und zurückbauen dauern länger als eine
HTTP-Anfrage und ändern den Server. Sie laufen deshalb über die Warteschlange,
mit sichtbarem Verlauf — nach dem Absenden landet man auf dem Vorgang und sieht
zu.

## 2. Der Zustand folgt dem System, nicht der Absicht

**Das ist die zentrale Entscheidung.** Der naheliegende Weg wäre, beim Klick
auf „Sperren" den Zustand sofort auf `suspended` zu setzen und den Vorgang
nebenher laufen zu lassen. Dann steht in der Liste „gesperrt", während das
Abonnement weiter ausliefert — und niemand sieht den Unterschied, denn genau
danach schaut man ja in der Liste.

`App\Support\Subscriptions\Lifecycle::afterSuccess()` läuft im Arbeiter,
**nachdem** der Agent geantwortet hat. Scheitert die Operation, bleibt der alte
Zustand stehen und der Vorgang ist sichtbar fehlgeschlagen. Beides zusammen ist
die Wahrheit.

Daraus folgt der vierte Zustand **`provisioning`** („wird angelegt"): die Zeit
zwischen dem Absenden des Formulars und dem Ende von `subscription.provision`.
Ohne ihn stünde ein Abonnement als „aktiv" da, das weder Systembenutzer noch
Verzeichnis hat. `usable()` ist dort `false`, damit die Policy gar nicht erst
hineinlässt.

## 3. Kein Wert aus der Anfrage erreicht den Agenten

Dieselbe Regel wie im Aufgabenkatalog (`App\Support\Operations\Task`), nur an
einem Objekt statt an einer festen Liste:

- Der Browser nennt ein **Abonnement**, keine Argumente.
- Die Mandantenklammer entscheidet, ob er es überhaupt sehen darf — vor dem
  Controller.
- `Lifecycle::payload()` liest Name, Systembenutzer und Speichergrenze aus der
  **abgelegten Zeile**.

Beim Anlegen kommt der Name aus dem Formular. Er wird deshalb mit **der
Funktion des Agenten selbst** geprüft — `SubscriptionProvision::subscriptionName()`,
nicht eine zweite Formulierung derselben Regel. Ein eigener Ausdruck im
Controller wäre dieselbe Regel an zwei Orten, und der eine, der beim nächsten
Mal nachgezogen wird, ist erfahrungsgemäß nicht der im Panel. Ein Name, der
hier durchginge und dort scheiterte, ergäbe ein Abonnement, das für immer „wird
angelegt" bliebe.

## 4. Der Systembenutzer wird vergeben und bleibt verbraucht

`p1000`, `p1001`, … — vier bis neun Ziffern, wie der Agent verlangt. Frei
wählbar wäre er ein Weg, über `useradd`/`usermod` ein bestehendes Konto zu
berühren.

**Zurückgezogen statt gelöscht**, aus demselben Grund wie bei der Kundennummer,
aber mit schärferer Folge: An dem Namen hängt eine UID. `userdel` gibt sie frei,
das nächste `useradd` vergibt sie wieder. Wäre die Zeile weg, bekäme ein neuer
Kunde `p1000` ein zweites Mal — und damit alles, was auf dem Dateisystem noch
der alten UID gehört. Genau diese Verwechslung sucht `subscription.remove` am
Ende mit seiner Suche nach verwaisten UIDs.

**Die Vergabe läuft ohne Mandantenklammer.** Der Name ist über den ganzen
Server eindeutig; die Klammer zeigt aber nur, was das anfragende Konto sehen
darf. Ohne `withoutRestriction` bekäme ein Aufruf ohne gesetzten Mandanten
`p1000` zurück, den es längst gibt.

## 5. Die Mandantenklammer am Abonnement selbst

`Subscription` trägt nicht `BelongsToSubscription` — die filtert auf
`subscription_id` und wäre hier eine Klammer um sich selbst. Sie trägt statt
dessen **dieselbe Klammer auf den eigenen Schlüssel** (`booted()`), samt
`whereRaw('0 = 1')` für den Grundzustand „nichts".

Vorher stand im Modell, die Sichtbarkeit regele die Policy. Das war zu wenig,
und es fiel erst mit der ersten Liste auf: Eine Policy entscheidet über *ein*
Objekt. `Subscription::query()` in einem Controller fragt sie nie — und ein
Kunde sah damit jedes Abonnement des Servers.

Eine Folge davon: Eine **Policy darf nicht von der umgebenden Klammer
abhängen.** `PlanPolicy::view()` fragte über `$plan->subscriptions()` und
filterte damit zweimal. Die Abfrage steht jetzt ausdrücklich
`withoutGlobalScope('tenancy')` — eine Policy muss aus sich heraus antworten,
sonst hängt ihr Ergebnis davon ab, was vor ihr lief.

## 6. Der Rückbau

Ohne Sicherung, und die Oberfläche sagt das. Der Plan verlangt „löschen mit
Sicherung davor"; die gehört vor den Aufruf und nicht in ihn — eine Operation,
die sichert *und* löscht, sichert im Fehlerfall vielleicht nicht und löscht
trotzdem.

Die Rückfrage verlangt den Namen zum Abtippen. Ein einzelnes „Wirklich?"
beantwortet man im Vorbeigehen.

## 7. Was noch fehlt

- **Speicherverbrauch** über `repquota` — die Kontingente stehen da, der Stand
  daneben fehlt.
- **Kontingente am Abonnement übersteuern.** Das Datenmodell kann es
  (`quota_overrides`), die Anzeige markiert es bereits als „abweichend vom
  Plan" — ein Formular dafür gibt es noch nicht.
- **Der Abnahmelauf über 100 Abonnements** unter echtem systemd. Erst er macht
  das Abnahmekriterium von P2 prüfbar.
