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

## 7. Die Willkommensseite

Das Verzeichnisschema legt `httpdocs` an — den DocumentRoot, auf den ab P3 der
vhost zeigt. Beim Anlegen entsteht darin eine `index.html`.

**Nur, solange das Verzeichnis leer ist.** Das ist die Bedingung dafür, dass
`subscription.provision` wiederholbar bleiben darf: Ein zweiter Lauf — nach
einem abgebrochenen Vorgang, nach einer Kontingentänderung — träfe sonst auf
eine fertige Webseite und legte eine `index.html` daneben, die vor `index.php`
gefunden wird. Der Kunde sähe statt seiner Seite wieder den Platzhalter, und
niemand käme auf den Gedanken, dass das Panel das war. Geprüft wird das ganze
Verzeichnis und nicht nur die Datei: Wer seine `index.html` gelöscht hat und
mit `index.php` arbeitet, hat damit eine Entscheidung getroffen.

**Sie nennt weder den Abonnementnamen noch den Systembenutzer noch das
Panel.** Sobald eine Domain hierher zeigt, ist sie öffentlich, und was
öffentlich ist, sollte über den Server nichts erzählen: Ein Platzhalter, auf
dem „Abonnement kunde-example.de, Systembenutzer p1003" steht, ist eine
Einladung, in der Suchmaschine nach weiteren zu suchen.

Alles in einer Datei — keine Schrift, kein Bild, kein Stylesheet von aussen:
Ein Platzhalter, der beim ersten Aufruf eine fremde Adresse kontaktiert,
verrät deren Betreiber, dass es diese Domain gibt. Dazu `noindex`.

## 8. Der belegte Speicher

Die Kontingente stehen am Plan, der Stand daneben kommt aus der
Dateisystem-Quota — gemessen und nicht gerechnet.

**Ein Aufruf für alle Abonnements, nicht einer je Abonnement.**
`subscription.usage` liest die Quota-Datei des Dateisystems einmal und kennt
danach jeden Systembenutzer darin. Bei hundert Abonnements ist das ein Aufruf
statt hundert; der Unterschied ist nicht Geschmack, sondern hundert
Prozessgründungen je Viertelstunde auf einem Server, der nebenbei Webseiten
ausliefert. Die Operation nimmt deshalb **keine Argumente** — es gibt nichts
auszuwählen.

**Sie meldet nur die Benutzer des Panels.** `repquota` gibt jeden Benutzer des
Dateisystems aus, auch `root` und `www-data`. Herausgegeben wird nur, was der
Form `p` plus vier bis neun Ziffern entspricht: dieselbe Regel, die der Agent
beim Anlegen erzwingt. Eine Operation, die die Benutzerliste des Servers
ausliefert, wäre eine Auskunft, die niemand bestellt hat.

**Messen ist kein Vorgang.** Niemand löst es aus, es ändert nichts, und es
liefe alle fünfzehn Minuten durch das Protokoll und die Vorgangsliste jedes
Kunden. Der Aufruf geht direkt an den Agenten — derselbe Weg, den der
Kennzahlensammler nimmt. Gestartet wird er von `srvpanel-usage.timer`; ein
Dauerlauf, der 899 von 900 Sekunden schläft, wäre ein Prozess, den jemand
überwachen muss.

**Zwei Spalten und nicht eine.** `disk_used_mb` allein wäre eine Zahl ohne
Haltbarkeit: Steht die Messung seit drei Tagen, zeigte die Oberfläche weiter
„412 MB" und sähe dabei aus wie eine Messung von vorhin. Mit
`disk_usage_measured_at` kann sie sagen, wovon sie redet. `null` heisst „noch
nie gemessen" und ist etwas anderes als „0 MB".

**Ohne Quota-Unterstützung wird nichts zurückgesetzt.** Fehlt `usrquota` auf
dem Mount, weiss das Panel nichts Neues — und „nichts Neues" ist kein Grund,
eine Messung von gestern zu verwerfen. Die Oberfläche zeigt „nicht gemessen"
und nennt den Grund.

## 9. Kontingente am Abonnement übersteuern

Der Plan ist die Vorlage, das Abonnement der Stand. Ein Kontingent hat deshalb
zwei Zustände und nicht einen Wert: „gilt der Plan" ist etwas anderes als
„gilt zufällig derselbe Wert wie im Plan". Das erste zieht mit, wenn der Plan
geändert wird, das zweite nicht.

**Was fehlt, bleibt weg.** Ein Schlüssel steht nur dann in `quota_overrides`,
wenn er im Formular angehakt war. Die Felder mit Vorgabewerten aufzufüllen
wäre eine stille Loslösung vom Plan: Ein Abonnement, das jedes Kontingent
übersteuert, erreicht keine Planänderung mehr, und niemand sucht den Grund am
Abonnement. Ohne eine einzige Übersteuerung steht `null` in der Spalte und
nicht `{}`.

**Nur `disk_mb` erreicht das System.** Es ist das einzige Kontingent, das
gerade schon durchgesetzt wird — als Dateisystem-Quota des Systembenutzers.
Domains, Datenbanken und FTP-Konten werden beim Anlegen gezählt (P3 und
später), PHP-Versionen wählt eine vhost-Vorlage aus, Traffic wird gemessen.
Für sie gibt es nichts auszuführen.

**Und nur, wenn er sich wirklich ändert.** Verglichen wird der *wirksame* Wert
und nicht die Übersteuerung: Wer eine Übersteuerung von 5120 MB entfernt,
während der Plan ebenfalls 5120 MB sagt, hat nichts geändert.

**`subscription.quota` und nicht `subscription.provision`.** Provision ist
wiederholbar, und sie noch einmal zu rufen wäre der kürzeste Weg gewesen. Sie
rückt dabei aber die Rechte der Chroot-Wurzel auf `0755` zurecht — und genau
dieses Bit nimmt `subscription.suspend` weg. Ein gesperrtes Abonnement wäre
nach einer Kontingentänderung wieder erreichbar gewesen, und im Panel hätte
weiter „gesperrt" gestanden: Die Sperre wäre nicht aufgehoben, sondern
unsichtbar geworden. Die eigene Operation setzt eine Zahl und fasst nichts an,
was eine Sperre trägt.

**Ein gesperrtes Abonnement bekommt seinen Vorgang trotzdem.** Die naheliegende
Bedingung wäre `usable()` gewesen — die heisst „aktiv". Ein gesperrtes
Abonnement hat aber weiterhin Systembenutzer und Quota, und das Entsperren
setzt keine Grenze. Ohne den Vorgang stünde die neue Grenze in der Datenbank
und käme nie an.

**Nicht änderbar sind Name, Systembenutzer, Kunde und Zustand.** Der Name ist
der Verzeichnisname unter /var/www/vhosts, auf den eine Chroot-Wurzel und der
Heimatpfad eines Systembenutzers zeigen. Der Systembenutzer trägt eine UID, an
der auf dem Dateisystem Eigentum hängt. Ein Abonnement umzuhängen ist eine
Vertragsfrage. Und der Zustand hat seine eigenen Aktionen.

## 10. Der Abnahmelauf

Das Kriterium von P2 lautet: hundert Abonnements anlegen und wieder löschen,
ohne dass ein Systembenutzer, ein Verzeichnis oder ein Quota-Eintrag
zurückbleibt.

**Warum das ein Kommando ist und kein Test.** Ein Test läuft gegen SQLite im
Arbeitsspeicher und einen erfundenen Agenten. Das Kriterium fragt nach dem
Gegenteil: nach echten `useradd`-Aufrufen, echten Verzeichnissen, echten
Einträgen in der Quota-Datei — und nach der ganzen Kette Panel →
Warteschlange → Arbeiter → Agent, die es in einem Test gar nicht gibt.

    sudo srvpanel acceptance --count=100

Der Lauf legt an, wartet auf die Vorgänge, baut zurück, wartet wieder und
sucht danach nach drei Sorten Rückstand:

1. ein Systembenutzer **oder eine Gruppe**, die es noch gibt. Getrennt gesucht:
   `userdel` entfernt die Gruppe nicht mit, wenn sie nicht die primäre ist —
   und beim Anlegen steht ausdrücklich `--no-user-group`.
2. ein Verzeichnis unter /var/www/vhosts, das stehen blieb.
3. ein Eintrag in der Dateisystem-Quota, den `subscription.usage` noch sieht.

Der dritte ist der, den man ohne Werkzeug übersieht: Er hat keinen Ort im
Dateisystem und keine Zeile in /etc/passwd. Bleibt er stehen, bekommt das
nächste Abonnement mit derselben UID eine fremde Grenze.

Der Lauf braucht einen Kunden und einen Plan und legt beide **nicht** selbst
an: Eine Kundennummer ist auf Dauer verbraucht, auch nach dem Zurückziehen.
`--keep` lässt die Abonnements stehen, wenn man nachsehen will; `--force`
überspringt die Rückfrage. Angefasst wird ausschliesslich, was der Lauf selbst
angelegt hat.

**Er ist gelaufen.** Am 4. August 2026 auf dem Server des Betreibers, mit
`--count=100`, aus dem Paket `0.2.0~rc.13`: hundert Abonnements angelegt und
zurückgebaut, kein Systembenutzer, keine Gruppe, kein Verzeichnis und kein
Quota-Eintrag geblieben. Damit ist das Abnahmekriterium von P2 nicht mehr eine
Zusage, sondern eine Feststellung.

## 11. Die Kundensperre

Einen Kunden zu sperren heisst, seine Abonnements zu sperren. Ein Kunde, der
„gesperrt" heisst und dessen Webseiten weiterlaufen, ist nicht gesperrt,
sondern anders beschriftet.

**Je Abonnement ein Vorgang.** Ein Sammelvorgang wäre bequemer und
beantwortete die Frage nicht, die man nachher stellt: welches Abonnement es
erwischt hat und welches nicht. Bei zehn Abonnements und einem Fehlschlag ist
„teilweise erfolgreich" keine Auskunft.

**Der Kundenzustand wird sofort gesetzt, der der Abonnements nicht.** Das ist
kein Widerspruch zu §2: Der Kundenzustand ist eine Angabe im Panel und keine
Behauptung über das System — für ihn gibt es nichts auszuführen. Ob ein
Abonnement wirklich aus ist, entscheidet weiterhin der Agent.

**Die Freigabe ist die schwierigere Hälfte.** „Alle gesperrten wieder an" wäre
die naheliegende Umkehrung und wäre falsch: Ein Abonnement, das der Betreiber
vorher einzeln gesperrt hat — wegen Missbrauch, wegen eines Umzugs —, war nie
Teil der Kundensperre. Käme es mit der Freigabe zurück, hätte die Kundensperre
eine Entscheidung aufgehoben, mit der sie nichts zu tun hatte. Am Zustand ist
das nicht zu erkennen: „gesperrt" sieht in beiden Fällen gleich aus.

Deshalb trägt das Abonnement `suspended_with_customer`. Das ist keine zweite
Zustandsspalte — *ob* es gesperrt ist, steht weiterhin in `status`; hier steht
nur, ob es das **wegen des Kunden** ist. Gesetzt wird die Spalte beim Auslösen
und nicht nach dem Vorgang: Sie ist kein Zustand, sondern die Zugehörigkeit
einer Absicht, und die steht fest, bevor der erste Vorgang läuft. Wer ein
Abonnement einzeln sperrt, löscht die Kennzeichnung damit — es gehört ab dann
zu seiner eigenen Sperre.

**Ein einzelnes Abonnement lässt sich nicht entsperren, solange der Kunde
gesperrt ist.** Sonst liesse sich die Kundensperre von unten aushebeln, und
die spätere Freigabe wüsste nicht mehr, was zu ihr gehört. Wer eines
herausnehmen will, gibt den Kunden frei und sperrt danach dieses eine.

**Für einen gesperrten Kunden lässt sich kein Abonnement anlegen.** Es käme
aktiv aus dem Anlegen heraus: Die Kaskade sperrt, was es beim Klick gab, und
ein Abonnement im Zustand „wird angelegt" hat noch keinen Systembenutzer, den
man sperren könnte. Danach stünde beim Kunden „gesperrt" und darunter eine
laufende Webseite. Die Regel gilt auch für den Betreiber — anlegen kann
ohnehin nur er, und wer für einen gesperrten Kunden etwas anlegen will, gibt
ihn vorher frei. Dann ist die Freigabe eine Entscheidung und kein Nebeneffekt.
Im Formular steht ein gesperrter Kunde weiterhin in der Liste, nur abgeblendet
und mit dem Grund daneben: Wer ihn herausfiltert, lässt jemanden nach einem
Kunden suchen, den er gestern angelegt hat.

**Die Anmeldung bleibt offen.** Ein gesperrter Kunde kommt weiterhin in das
Panel und sieht, dass seine Abonnements gesperrt sind — er kann darin nichts
mehr tun, weil `SubscriptionPolicy::useFeature` einen unbenutzbaren Zustand
abweist. Ihn auszusperren wäre die härtere Auslegung; sie nähme ihm die
Auskunft darüber, warum nichts mehr geht. Ein zurückgezogener Kunde kommt
dagegen nicht mehr herein — das ist keine Sperre, sondern das Ende.

## 12. Der Bestand auf der Übersicht

Die Startseite des Betreibers zeigte bis August 2026 ausschliesslich die
Maschine: Auslastung, Dienste, Dateisysteme, Prozesse. Das ist die halbe
Auskunft — ein Betreiber öffnet sein Panel nicht, um zu erfahren, wie viel RAM
belegt ist, sondern um zu sehen, ob mit dem, was er hostet, etwas nicht
stimmt. Kunden und Abonnements gab es nur auf ihren eigenen Listenseiten, und
dort sieht man sie erst, wenn man den Verdacht schon hat.

Jetzt stehen darauf: Zahl der Kunden (und wie viele davon gesperrt sind), Zahl
der Abonnements nach Zustand, und die fünf Abonnements, die ihrer
Speichergrenze am nächsten sind.

**Die vollsten und nicht die grössten.** Ein Abonnement mit 40 GB Verbrauch
und 200 GB Kontingent ist unauffällig; eines mit 4,8 GB und 5 GB ist der Anruf
von morgen. Sortiert wird deshalb nach dem Verhältnis — und das kennt erst
`Subscription::diskUsagePercent()`, weil es an `quota_overrides` und am Plan
hängt und sich in SQL nicht ohne Weiteres ausdrücken lässt.

**Gezählt wird in der Datenbank, gerechnet nur, wo es sein muss.** Die
Zustände kommen als `GROUP BY`; für die Rangfolge werden die fünfzig
verbrauchsstärksten Abonnements mit ihrem Plan geladen und daraus die fünf
vollsten gewählt. Ein Server ohne Messung lädt gar nichts.

**Ein Kunde bekommt das nicht.** Nicht ausgeblendet, sondern gar nicht erst
erhoben — wie viele Kunden es auf diesem Server gibt, geht ihn nichts an.

## 13. Was noch fehlt

- **Sicherung vor dem Rückbau.** Der Plan verlangt sie in P2 („löschen mit
  Sicherung davor"); sie ist bewusst nach P8 verschoben. Eine Operation, die
  sichert *und* löscht, sichert im Fehlerfall vielleicht nicht und löscht
  trotzdem — und ohne Sicherungsziele, Aufbewahrung und einen Weg zurück wäre
  „Sicherung" ohnehin nur ein Verzeichnis daneben. Solange es sie nicht gibt,
  ist der Rückbau endgültig, und die Rückfrage sagt das.
- **Traffic messen.** Das Kontingent steht im Katalog und ist als „gemessen,
  nicht erzwungen" beschrieben — gemessen wird es noch nicht. Dafür braucht es
  die Zugriffsprotokolle der Domains, und die gibt es ab P3.
- **Die übrigen Kontingente durchsetzen.** Domains, Subdomains, Datenbanken,
  FTP-Konten und Cronjobs werden gezählt, sobald es die Objekte gibt, die sie
  zählen.
