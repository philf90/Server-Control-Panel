<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\CustomerStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Audit\Audit;
use App\Support\Passwords\Policy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            ->paginate(50);

        return Inertia::render('Customers/Index', [
            'customers' => [
                'data' => collect($customers->items())->map(static fn (Customer $customer): array => [
                    'id' => (int) $customer->id,
                    'number' => $customer->number,
                    'name' => $customer->displayName(),
                    'email' => $customer->email,
                    'status' => $customer->status->value,
                    'status_label' => $customer->status->label(),
                    'subscriptions' => (int) $customer->getAttribute('subscriptions_count'),
                    'accounts' => (int) $customer->getAttribute('accounts_count'),
                ])->all(),
                'total' => $customers->total(),
            ],
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
                    'last_login_at' => $account->last_login_at?->toDateTimeString(),
                ])->all(),
            'subscriptions' => $customer->subscriptions()->orderBy('name')->get()
                ->map(static fn ($subscription): array => [
                    'id' => (int) $subscription->id,
                    'name' => $subscription->name,
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
     * **Was das nicht leistet:** Wird ein Kunde gelöscht, wird seine Nummer
     * wieder frei. Der Datensatz ist weg, es gibt keine Soft-Deletes. Damit
     * eine Nummer dauerhaft verbraucht bleibt — was sie sein sollte, sobald
     * eine Rechnung sie trägt —, braucht es entweder Soft-Deletes oder einen
     * eigenen Zähler. Beides gehört zur Abrechnung und damit nicht in P1.
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
            ->where('number', 'like', 'K%')
            ->pluck('number')
            ->map(static fn (string $number): int => (int) mb_substr($number, 1))
            ->max();

        return 'K'.max(self::FIRST_NUMBER, ((int) $highest) + 1);
    }
}
