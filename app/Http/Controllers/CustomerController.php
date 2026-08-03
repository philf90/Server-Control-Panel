<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\CustomerStatus;
use App\Models\Account;
use App\Models\Customer;
use App\Support\Audit\Audit;
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
            'suggestedNumber' => $this->suggestNumber(),
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32', Rule::unique('customers', 'number')],
            'company' => ['nullable', 'string', 'max:255'],
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],

            // Das Anmeldekonto. Die Adresse muss über alle Konten eindeutig
            // sein, nicht nur über die Kunden — sonst kollidiert sie später
            // mit einem Adminkonto, und die Anmeldung fände zwei Treffer.
            'login_email' => ['required', 'email', 'max:255', Rule::unique('accounts', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:1024', 'confirmed'],
        ]);

        $customer = DB::transaction(function () use ($data): Customer {
            $customer = Customer::query()->create([
                'number' => $data['number'],
                'company' => $data['company'] ?? null,
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
     * Ein Vorschlag für die nächste Kundennummer.
     *
     * Nur ein Vorschlag: Die Nummer kommt aus dem Formular, und der eindeutige
     * Index in der Datenbank entscheidet. Zwei Betreiber, die gleichzeitig ein
     * Formular öffnen, bekämen sonst dieselbe Nummer und der zweite eine
     * Fehlermeldung, die er nicht versteht.
     */
    private function suggestNumber(): string
    {
        $highest = Customer::query()
            ->where('number', 'like', 'K%')
            ->orderByDesc('id')
            ->value('number');

        $digits = is_string($highest) ? (int) preg_replace('/\D/', '', $highest) : 10000;

        return 'K'.max(10001, $digits + 1);
    }
}
