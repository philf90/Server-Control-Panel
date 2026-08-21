<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\CustomerStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Passwords\Policy;
use App\Support\Subscriptions\Lifecycle;
use App\Support\Time\Clock;
use App\Support\Web\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Kunden anlegen und ansehen — die Betreiberseite.
 *
 * **Kunde und Konto entstehen zusammen, in einer Transaktion.** Ein Kunde ohne
 * Anmeldekonto ist ein Datensatz, mit dem niemand etwas anfangen kann; ein
 * Konto ohne Kunde hängt in der Luft. Bricht das eine ab, darf das andere
 * nicht stehenbleiben — sonst ist die Kundennummer vergeben und die Adresse
 * belegt, ohne dass sich jemand anmelden könnte.
 */
final class CustomerController extends Controller
{
    /** Die erste Kundennummer. Fünfstellig, damit sie nicht wie eine ID aussieht. */
    private const FIRST_NUMBER = 10001;

    public function index(): Response
    {
        $customers = Customer::query()
            ->withCount(['subscriptions', 'accounts'])
            ->orderBy('last_name')
            ->orderBy('company')
            ->paginate(Page::SIZE)
            ->withQueryString();

        return Inertia::render('Customers/Index', [
            'customers' => Page::from($customers, static fn (Customer $customer): array => [
                'id' => (int) $customer->id,
                'number' => $customer->number,
                'name' => $customer->displayName(),
                'email' => $customer->email,
                'status' => $customer->status->value,
                'status_label' => $customer->status->label(),
                'subscriptions' => (int) $customer->getAttribute('subscriptions_count'),
                'accounts' => (int) $customer->getAttribute('accounts_count'),
            ]),
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Customers/Create', [
            'nextNumber' => $this->nextNumber(),
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            // `number` steht hier nicht mehr.
            //
            // Die Kundennummer ist der Bezeichner, unter dem der Kunde in
            // Rechnungen, Verzeichnisnamen und Systembenutzern auftaucht. Ein
            // Feld, das der Betreiber frei füllt, macht daraus eine
            // Zeichenkette, die alles sein kann: doppelt vergeben, mit
            // Leerzeichen, mit einem Schrägstrich darin. Sie wird jetzt beim
            // Anlegen erzeugt; das Formular zeigt sie nur an.
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],

            // Das Anmeldekonto. Die Adresse muss über alle Konten eindeutig
            // sein, nicht nur über die Kunden — sonst kollidiert sie später
            // mit einem Adminkonto, und die Anmeldung fände zwei Treffer.
            'login_email' => ['required', 'email', 'max:255', Rule::unique('accounts', 'email')],
            'password' => ['required', 'confirmed', ...Policy::rules()],
        ]);

        $customer = DB::transaction(function () use ($data): Customer {
            $customer = Customer::query()->create([
                'number' => $this->nextNumber(),
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
                'status' => CustomerStatus::Active,
            ]);

            Account::query()->create([
                'type' => AccountType::Customer,
                'customer_id' => $customer->id,
                'name' => trim($data['first_name'].' '.$data['last_name']),
                'email' => Str::lower($data['login_email']),
                'password' => $data['password'],
                'status' => AccountStatus::Active,
            ]);

            return $customer;
        });

        $audit->success('customer.created', $customer, ['number' => $customer->number]);

        return redirect()
            ->route('customers.index')
            ->with('success', "Kunde {$customer->number} angelegt.");
    }

    /**
     * Die Stammdaten ändern.
     *
     * **Ohne Kundennummer und ohne Zustand.** Die Nummer ist der Bezeichner,
     * unter dem der Kunde in Rechnungen steht — sie zu ändern hiesse, zwei
     * Belege desselben Vorgangs unter zwei Nummern zu führen. Der Zustand
     * (aktiv, gesperrt) hat seine eigene Aktion — {@see self::suspend()} —,
     * weil er die Abonnements mitnimmt. Als Auswahlfeld unter der
     * Telefonnummer sähe er aus wie eine Angabe und wäre ein Schalter, der
     * Webseiten abschaltet.
     *
     * **Und ohne die Anmeldeadresse.** Die gehört dem Konto, nicht dem
     * Vertragspartner: Ein Kunde kann mehrere Konten haben, und welches davon
     * hier gemeint wäre, ist nicht zu erraten. Sie ändert der Kontoinhaber
     * unter „Mein Konto" — mit seinem Passwort als Nachweis.
     */
    public function edit(Customer $customer): Response
    {
        return Inertia::render('Customers/Edit', [
            'customer' => [
                'id' => (int) $customer->id,
                'number' => $customer->number,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'street' => $customer->street,
                'postal_code' => $customer->postal_code,
                'city' => $customer->city,
                'country' => $customer->country,
                'notes' => $customer->notes,
            ],
        ]);
    }

    public function update(Request $request, Customer $customer, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'street' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:255'],

            // Zwei Buchstaben nach ISO 3166-1, oder gar nichts. Ein freies
            // Feld ergäbe „DE", „Deutschland" und „de" nebeneinander — und
            // spätestens die erste Rechnungsvorlage müsste raten.
            'country' => ['nullable', 'string', 'size:2', 'alpha'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ], [], [
            /*
             * **Der Name muss heissen wie das Feld auf der Seite** (`docs/66`, Befund 3).
             * Die Liste in `lang/de/validation.php` trägt den Namen, der über alle Seiten
             * passt; wo eine Seite ein anderes Wort benutzt, steht es hier. Sonst sucht der
             * Leser ein Feld, das er nicht sieht.
             *
             * > **Ein Wächter über die Vollständigkeit sagt nichts über die Richtigkeit.**
             */
            'postal_code' => 'PLZ',
            'notes' => 'Vermerk',
        ]);

        $before = $customer->only(array_keys($data));

        $customer->update([...$data, 'country' => $this->country($data['country'] ?? null)]);

        // Im Protokoll steht, welche Felder sich geändert haben — nicht ihr
        // Inhalt. Eine Anschrift gehört in den Datensatz und nicht zusätzlich
        // in jeden Protokolleintrag, der sie je berührt hat.
        $audit->success('customer.updated', $customer, [
            'number' => $customer->number,
            'changed' => array_keys(array_diff_assoc($customer->only(array_keys($data)), $before)),
        ]);

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', "Kunde {$customer->number} gespeichert.");
    }

    /**
     * Einen Kunden sperren — und seine Abonnements mit.
     *
     * **Die Kaskade ist der Inhalt dieser Aktion.** Ein Kunde, der „gesperrt"
     * heisst und dessen Webseiten weiterlaufen, ist nicht gesperrt, sondern
     * anders beschriftet. Gesperrt wird deshalb, was er hat: für jedes aktive
     * Abonnement ein Vorgang `subscription.suspend`.
     *
     * **Jedes bekommt seinen eigenen Vorgang.** Ein Sammelvorgang wäre
     * bequemer und beantwortete die Frage nicht, die man nachher stellt:
     * welches Abonnement es erwischt hat und welches nicht. Bei zehn
     * Abonnements und einem Fehlschlag ist ein Vorgang mit „teilweise
     * erfolgreich" keine Auskunft.
     *
     * **Der Zustand des Kunden wird sofort gesetzt, der der Abonnements
     * nicht.** Das ist kein Widerspruch zu docs/26 §2: Der Kundenzustand ist
     * eine Angabe im Panel und keine Behauptung über das System — für ihn gibt
     * es nichts auszuführen. Ob ein Abonnement wirklich aus ist, entscheidet
     * weiterhin der Agent.
     *
     * Abonnements, die gerade angelegt werden, bleiben aussen vor: Sie haben
     * noch keinen Systembenutzer, den man sperren könnte. Sie kommen als aktiv
     * aus dem Anlegen heraus — der Kunde bleibt gesperrt, das Abonnement
     * nicht. Das ist eine bekannte Kante und in docs/26 §13 notiert.
     */
    public function suspend(Customer $customer, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        if ($customer->status === CustomerStatus::Suspended) {
            throw ValidationException::withMessages([
                'customer' => 'Der Kunde ist bereits gesperrt.',
            ]);
        }

        $affected = DB::transaction(function () use ($customer, $lifecycle): array {
            $customer->update(['status' => CustomerStatus::Suspended]);

            $names = [];

            foreach ($customer->subscriptions()->where('status', SubscriptionStatus::Active)->get() as $subscription) {
                // Die Kennzeichnung entsteht hier und nicht nach dem Vorgang:
                // Sie ist kein Zustand, sondern die Zugehörigkeit dieser
                // Sperre — und die steht fest, bevor der Agent antwortet.
                $subscription->forceFill(['suspended_with_customer' => true])->save();

                $lifecycle->dispatch($subscription, 'subscription.suspend', 'Kunde gesperrt');

                $names[] = (string) $subscription->name;
            }

            return $names;
        });

        $audit->success('customer.suspended', $customer, [
            'number' => $customer->number,
            'subscriptions' => $affected,
        ]);

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', $this->cascadeMessage($customer->number, $affected, 'gesperrt'));
    }

    /**
     * Freigeben — und genau die Abonnements zurückholen, die mitgingen.
     *
     * **`suspended_with_customer` und nicht „alle gesperrten".** Ein
     * Abonnement, das der Betreiber vorher einzeln gesperrt hat — wegen
     * Missbrauch, wegen eines Umzugs —, war nie Teil der Kundensperre. Käme es
     * mit der Freigabe zurück, hätte die Kundensperre eine Entscheidung
     * aufgehoben, mit der sie nichts zu tun hatte. Am Zustand allein ist das
     * nicht zu erkennen: „gesperrt" sieht in beiden Fällen gleich aus.
     */
    public function resume(Customer $customer, Audit $audit, Lifecycle $lifecycle): RedirectResponse
    {
        if ($customer->status !== CustomerStatus::Suspended) {
            throw ValidationException::withMessages([
                'customer' => 'Der Kunde ist nicht gesperrt.',
            ]);
        }

        $affected = DB::transaction(function () use ($customer, $lifecycle): array {
            $customer->update(['status' => CustomerStatus::Active]);

            $names = [];

            foreach ($customer->subscriptions()->where('suspended_with_customer', true)->get() as $subscription) {
                $subscription->forceFill(['suspended_with_customer' => false])->save();

                $lifecycle->dispatch($subscription, 'subscription.resume', 'Kunde freigegeben');

                $names[] = (string) $subscription->name;
            }

            return $names;
        });

        $audit->success('customer.resumed', $customer, [
            'number' => $customer->number,
            'subscriptions' => $affected,
        ]);

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', $this->cascadeMessage($customer->number, $affected, 'freigegeben'));
    }

    /**
     * Die Rückmeldung zur Kaskade.
     *
     * Sie nennt die Zahl, weil „Kunde gesperrt" allein die Frage offen lässt,
     * die man danach hat: Ist etwas mitgegangen? Null Abonnements sind eine
     * Antwort und kein Fehler.
     *
     * @param  list<string>  $affected
     */
    private function cascadeMessage(string $number, array $affected, string $verb): string
    {
        return match (count($affected)) {
            0 => "Kunde {$number} {$verb}. Es gab kein Abonnement, das mitgeht.",
            1 => "Kunde {$number} {$verb}. Ein Abonnement wird {$verb} — der Vorgang läuft.",
            default => sprintf(
                'Kunde %s %s. %d Abonnements werden %s — die Vorgänge laufen.',
                $number, $verb, count($affected), $verb,
            ),
        };
    }

    /**
     * Einen Kunden zurückziehen.
     *
     * **Zurückziehen und nicht löschen.** Ein `DELETE` gäbe die Kundennummer
     * wieder frei, und der nächste Kunde bekäme sie — danach trügen zwei
     * Vertragspartner in zwei Rechnungen dieselbe. Die Zeile bleibt mit
     * `deleted_at` stehen, der eindeutige Index gilt weiter für sie, und die
     * Vergabe fragt als einzige Stelle im Panel `withTrashed()`. Die Konten
     * des Kunden bleiben ebenfalls stehen und kommen trotzdem nicht mehr
     * herein: Die Anmeldung weist Konten eines zurückgezogenen Kunden ab.
     *
     * **Die Anmeldeadresse wird dabei frei — und nur sie.** Gesperrt bleibt
     * die Kundennummer, denn sie steht in Rechnungen. Die Adresse gehört
     * dagegen einem Menschen, und wer einen Kunden zurückzieht und neu anlegt,
     * hat denselben vor sich. Sie blieb bis hierher belegt, und weil
     * `accounts.email` einen Unique-Index trägt, war das kein Versehen in der
     * Validierung, sondern eine Sperre in der Datenbank: Das Anlegen schlug
     * fehl, und im Formular sah es aus wie nichts.
     *
     * **Freigegeben heisst `null`**, nicht „irgendeine Adresse, die keiner
     * hat". Was sie war, hält der Eintrag im Prüfprotokoll fest — dort gehört
     * sie hin, denn ab jetzt ist sie eine Tatsache der Vergangenheit und keine
     * Eigenschaft des Kontos.
     *
     * **Nicht, solange Abonnements laufen.** Der bequeme Weg wäre, sie mit
     * zurückzubauen. Dann wäre dieser Knopf einer, der als Nebenwirkung fünf
     * Verzeichnisbäume als root löscht — und die Rückfrage davor spräche von
     * einem Kunden. Wer kündigt, baut die Abonnements zuerst zurück und sieht
     * dabei jedes einzeln. Dieselbe Regel wie beim Plan mit gebundenen
     * Abonnements, aus demselben Grund.
     *
     * Gezählt wird ohne die zurückgebauten: Ein gekündigtes Abonnement ist
     * `deleted_at` und damit aus dieser Zählung heraus — sonst liesse sich ein
     * Kunde, der einmal ein Abonnement hatte, nie wieder zurückziehen.
     */
    public function destroy(Customer $customer, Audit $audit): RedirectResponse
    {
        $running = $customer->subscriptions()->count();

        if ($running > 0) {
            $audit->denied('customer.withdrawn', $customer, [
                'number' => $customer->number,
                'reason' => 'laufende Abonnements',
                'subscriptions' => $running,
            ]);

            // Mit Einzahl: „hängen noch 1 Abonnements" ist der Satz, an dem man
            // merkt, dass niemand die Meldung gelesen hat, die er baut.
            throw ValidationException::withMessages([
                'customer' => $running === 1
                    ? 'An diesem Kunden hängt noch ein Abonnement. Es muss zuerst zurückgebaut werden.'
                    : "An diesem Kunden hängen noch {$running} Abonnements. Sie müssen zuerst zurückgebaut werden.",
            ]);
        }

        $number = $customer->number;

        // Beides zusammen oder gar nicht: Ein Kunde, der zurückgezogen ist,
        // dessen Konten aber noch ihre Adresse belegen, ist genau der Zustand,
        // aus dem dieser Fehler entstanden ist.
        $released = DB::transaction(function () use ($customer): array {
            $addresses = [];

            foreach ($customer->accounts()->whereNotNull('email')->get() as $account) {
                $addresses[] = (string) $account->email;

                $account->forceFill(['email' => null])->save();
            }

            $customer->delete();

            return $addresses;
        });

        $audit->success('customer.withdrawn', $customer, [
            'number' => $number,
            // Ohne diese Zeile wäre die Adresse weg, ohne dass irgendwo
            // stünde, welche es war.
            'released_addresses' => $released,
        ]);

        return redirect()
            ->route('customers.index')
            ->with('success', $released === []
                ? "Kunde {$number} zurückgezogen. Die Nummer bleibt vergeben."
                : "Kunde {$number} zurückgezogen. Die Nummer bleibt vergeben, die Anmeldeadresse ist wieder frei.");
    }

    /** Der Ländercode einheitlich in Grossbuchstaben — oder gar keiner. */
    private function country(?string $value): ?string
    {
        $code = mb_strtoupper(trim((string) $value));

        return $code === '' ? null : $code;
    }

    public function show(Customer $customer): Response
    {
        return Inertia::render('Customers/Show', [
            'customer' => [
                'id' => (int) $customer->id,
                'number' => $customer->number,
                'name' => $customer->displayName(),
                'company' => $customer->company,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'status' => $customer->status->value,
                'status_label' => $customer->status->label(),
            ],
            'accounts' => $customer->accounts()->orderBy('id')->get()
                ->map(static fn (Account $account): array => [
                    'id' => (int) $account->id,
                    'name' => $account->name,
                    'email' => $account->email,
                    'type' => $account->type->value,
                    'type_label' => $account->type->label(),
                    'status_label' => $account->status->label(),
                    'last_login_at' => Clock::display($account->last_login_at),
                ])->all(),
            'subscriptions' => $customer->subscriptions()->orderBy('name')->get()
                ->map(static fn ($subscription): array => [
                    'id' => (int) $subscription->id,
                    'name' => $subscription->name,
                    'status' => $subscription->status->value,
                    'status_label' => $subscription->status->label(),
                ])->all(),
        ]);
    }

    /**
     * Die nächste freie Kundennummer.
     *
     * **Sie wird zweimal gebildet, und das ist Absicht.** Einmal für die
     * Anzeige im Formular und einmal beim Anlegen, innerhalb der Transaktion.
     * Zwischen dem Öffnen des Formulars und dem Absenden kann ein zweiter
     * Betreiber einen Kunden angelegt haben; die Zahl im Formular ist deshalb
     * eine Vorschau und keine Zusage. Verbindlich ist allein die, die hier in
     * der Transaktion entsteht — und darüber wacht der eindeutige Index.
     *
     * **Gesucht wird das Maximum über die Nummern, nicht die Nummer des
     * jüngsten Datensatzes.** Hier stand `orderByDesc('id')->value('number')`.
     * Das stimmt nur, solange Nummer und ID in derselben Reihenfolge wachsen —
     * und genau das war nicht garantiert, solange der Betreiber die Nummer im
     * Formular frei setzen konnte. Ein einziger Kunde mit „K90000" hätte
     * gereicht: Der nächste bekäme „K90001", der übernächste wieder eine aus
     * der niedrigen Reihe, und irgendwann läuft die Vergabe in eine Nummer,
     * die es schon gibt. Der eindeutige Index fängt das ab — mit einer
     * Fehlermeldung, die der Betreiber nicht deuten kann.
     *
     * **`withTrashed()` ist der Kern und keine Feinheit.** Kunden werden
     * zurückgezogen und nicht gelöscht (siehe `Customer`), damit ihre Nummer
     * verbraucht bleibt — sobald eine Rechnung sie trägt, darf sie nie wieder
     * an jemand anderen gehen. Ohne diese Zeile wäre der ganze Soft-Delete
     * wirkungslos: Die Vergabe sähe die zurückgezogene Zeile nicht, gäbe die
     * Nummer erneut aus, und der eindeutige Index — der zurückgezogene Zeilen
     * sehr wohl kennt — wiese das Anlegen mit einer Meldung ab, die niemand
     * mit einem vor Monaten gelöschten Kunden in Verbindung bringt.
     *
     * Das ist die einzige Stelle im Panel, die zurückgezogene Kunden sieht.
     */
    private function nextNumber(): string
    {
        // Die höchste Zahl wird in PHP gesucht und nicht in SQL. Ein `MAX(CAST(
        // SUBSTRING(number, 2) AS UNSIGNED))` läuft auf MariaDB und nicht auf
        // SQLite — die Tests liefen dann gegen etwas anderes als der Server,
        // und zwar ausgerechnet bei der Vergabe eines Bezeichners. Ein Panel
        // für einen einzelnen Server hat Kunden in einer Größenordnung, in der
        // eine Spalte zu laden nichts kostet.
        $highest = Customer::query()
            ->withTrashed()
            ->where('number', 'like', 'K%')
            ->pluck('number')
            ->map(static fn (string $number): int => (int) mb_substr($number, 1))
            ->max();

        return 'K'.max(self::FIRST_NUMBER, ((int) $highest) + 1);
    }
}
