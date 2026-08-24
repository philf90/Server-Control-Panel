<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Support\Audit\Audit;
use App\Support\Authorization\LastOperator;
use App\Support\Passwords\Policy;
use App\Support\Time\Clock;
use App\Support\Web\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Die Adminkonten — anlegen, ändern, sperren.
 *
 * ## Wofür es diese Seite gibt
 *
 * Bis zum 24. August 2026 entstand ein Adminkonto **ausschliesslich** über
 * `srvpanel:admin` auf der Kommandozeile. Wer einen zweiten Menschen an das
 * Panel lassen wollte, brauchte dafür SSH und root — also genau die Rechte, die
 * das Panel dem Administrator gerade nicht geben will (`docs/20 §6.1`).
 *
 * > **Ein Rechtemodell, dessen zweite Rolle sich nur mit den Rechten der ersten
 * > anlegen lässt, hat keine zweite Rolle.**
 *
 * ## Nur Adminkonten, und das ist keine Filterfrage
 *
 * Kundenkonten stehen am Kunden. Zwei Listen derselben Zeilen wären zwei Wege
 * zum selben Ort, und der zweite veraltet.
 *
 * **Durchgesetzt wird das an der Routenbindung** und nicht hier: `{admin}` löst
 * über `SrvPanelServiceProvider` nur Adminkonten auf. Eine
 * Prüfung in jeder Methode wäre dieselbe Regel an vier Stellen — und beim
 * fünften Weg zum Konto fehlte sie.
 *
 * ## Was diese Seite **nicht** kann
 *
 * **Die Anmeldeadresse ändern** (`docs/82 §2.4`). Sie ist die Anmeldung und
 * steht im Protokoll; ihr Wechsel ist ein eigener Vorgang mit Bestätigung und
 * gehört nicht in ein Formular, das auch den Namen ändert.
 *
 * **Löschen** (`docs/82 §9`). Solange das Protokoll seinen Handelnden über
 * `nullOnDelete()` verliert, ist Sperren die ehrlichere Antwort: Ein gesperrtes
 * Konto kommt nicht mehr herein, und seine Einträge tragen weiter seinen Namen.
 */
final class AccountController extends Controller
{
    public function index(): Response
    {
        $accounts = Account::query()
            ->where('type', AccountType::Admin)
            ->orderBy('name')
            ->paginate(Page::SIZE)
            ->withQueryString();

        /*
         * **Die Zahl der aktiven Betreiber geht mit auf die Seite**, damit die
         * Liste den Grund zeigen kann, aus dem ein Konto keine Knöpfe hat.
         *
         * Gefragt wird dieselbe Stelle, die es später abweist. Eine zweite
         * Bedingung in der Vue-Datei — „wenn nur eine Zeile Betreiber ist" —
         * wäre eine zweite Fassung von {@see LastOperator}, und die zweite ist
         * die, die veraltet.
         */
        $operators = LastOperator::active();

        return Inertia::render('Accounts/Index', [
            'accounts' => Page::from($accounts, static fn (Account $account): array => [
                'id' => (int) $account->id,
                'name' => $account->name,
                'email' => $account->email,
                'role' => $account->role?->value,
                'role_label' => $account->role?->label(),
                'status' => $account->status->value,
                'status_label' => $account->status->label(),
                'two_factor' => $account->hasTwoFactor(),
                'last_login_at' => Clock::display($account->last_login_at),
                'is_last_operator' => LastOperator::isLast($account),
            ]),
            'operators' => $operators,
        ]);
    }

    public function create(): Response
    {
        return Inertia::render('Accounts/Form', [
            'account' => null,
            'values' => [
                'name' => '',
                'email' => '',

                /*
                 * **Administrator als Vorgabe, und das ist die Entscheidung
                 * dieser Seite.** Der Betreiber gibt es schon — wer hier ein
                 * Konto anlegt, will in aller Regel jemanden, der Kunden
                 * verwaltet, und nicht einen zweiten Menschen mit root.
                 *
                 * Die sichere Richtung ist zugleich die häufige. Wäre der
                 * Betreiber vorgewählt, entstünde die weitere Vollmacht durch
                 * ein Feld, das niemand angesehen hat.
                 */
                'role' => AdminRole::Administrator->value,
            ],
            'roles' => self::roles(),
            'isLastOperator' => false,
        ]);
    }

    public function store(Request $request, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('accounts', 'email')],
            'role' => ['required', Rule::enum(AdminRole::class)],

            /*
             * **Das Passwort kommt aus dem Browser**, erzeugt von
             * `PasswordFields` — derselben Komponente wie beim Anlegen eines
             * Kunden und beim eigenen Passwortwechsel.
             *
             * Der erste Wurf von `docs/82 §2.4` plante das Gegenteil: Der
             * Server erzeugt es und zeigt es einmalig an. Das wäre eine zweite
             * Bauart für etwas gewesen, das dieses Panel seit P1 auf eine Art
             * macht — und die Begründung dagegen steht im Kopf von
             * {@see Policy::generate()}: Ein Passwort, das der Server ausliefert,
             * steht in jedem Puffer auf dem Weg.
             *
             * Geprüft wird es trotzdem gegen dieselbe Richtlinie. Die
             * Kommandozeile war einmal der Weg, an ihr vorbei ein schwaches
             * Passwort für ein Adminkonto zu setzen; ein zweiter soll hier
             * nicht entstehen.
             */
            'password' => ['required', 'confirmed', ...Policy::rules()],

            /*
             * **Am Feld heisst es „Anmeldeadresse", und die Meldung sagt
             * dasselbe.** Ohne den dritten Wert läse der Betreiber „Das Feld
             * E-Mail-Adresse ist erforderlich" und suchte ein Feld, das auf
             * dieser Seite nicht steht.
             *
             * Der Name gehört an den Aufruf und nicht in die Sprachdatei: Dort
             * bedeutet `email` an fünfzehn anderen Stellen die E-Mail-Adresse
             * eines Kunden, und die heisst dort auch so.
             *
             * > **Ein Wächter über die Vollständigkeit sagt nichts über die
             * > Richtigkeit** (`docs/66`, Befund 15).
             */
        ], [], ['email' => 'Anmeldeadresse']);

        $account = Account::query()->create([
            'type' => AccountType::Admin,
            'role' => AdminRole::from($data['role']),
            'customer_id' => null,
            'name' => $data['name'],
            'email' => Str::lower(trim($data['email'])),
            'password' => $data['password'],
            'status' => AccountStatus::Active,
        ]);

        /*
         * **Mit `context` und nicht nur mit `target`** (`docs/66`, Befund 7).
         * Ein Eintrag `account.created` ohne die Rolle beantwortet die Frage,
         * die niemand stellt — bei einem Adminkonto ist „welche Rolle" die
         * einzige, für die man das Protokoll aufschlägt.
         */
        $audit->success('account.created', $account, [
            'name' => $account->name,
            'email' => $account->email,
            'role' => $account->role?->value,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', "Konto {$account->name} angelegt.");
    }

    public function edit(Account $admin): Response
    {
        return Inertia::render('Accounts/Form', [
            'account' => [
                'id' => (int) $admin->id,
                'name' => $admin->name,
                'email' => $admin->email,
                'two_factor' => $admin->hasTwoFactor(),
                'last_login_at' => Clock::display($admin->last_login_at),
            ],
            'values' => [
                'name' => $admin->name,
                'email' => $admin->email,
                'role' => $admin->role?->value,
                'status' => $admin->status->value,
            ],
            'roles' => self::roles(),

            /*
             * **Mit denselben Augen wie {@see self::update()}.** Zeigte das
             * Formular seine Auswahl an einer anderen Frage als die Prüfung
             * dahinter, stünde dort ein Knopf, den der Aufruf danach abweist —
             * ein Fehler, der in diesem Projekt schon mehrfach teuer war.
             */
            'isLastOperator' => LastOperator::isLast($admin),
        ]);
    }

    public function update(Request $request, Account $admin, Audit $audit): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'status' => ['required', Rule::enum(AccountStatus::class)],
        ]);

        $role = AdminRole::from($data['role']);
        $status = AccountStatus::from($data['status']);

        /*
         * **Eine Frage nach dem Zielzustand und nicht nach der Handlung.**
         * Herabstufen und Sperren sehen im Formular verschieden aus und sind
         * dieselbe Aussperrung; {@see LastOperator::permits()} bekommt deshalb,
         * was nachher gelten soll, und entscheidet daraus.
         */
        if (! LastOperator::permits($admin, $role, $status)) {
            throw ValidationException::withMessages(['role' => LastOperator::refusal()]);
        }

        $before = ['role' => $admin->role?->value, 'status' => $admin->status->value];

        $admin->forceFill([
            'name' => $data['name'],
            'role' => $role,
            'status' => $status,
        ])->save();

        $audit->success('account.updated', $admin, [
            'name' => $admin->name,
            'email' => $admin->email,
            'role' => $before['role'] === $role->value
                ? $role->value
                : $before['role'].' → '.$role->value,
            'status' => $before['status'] === $status->value
                ? $status->value
                : $before['status'].' → '.$status->value,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', "Konto {$admin->name} geändert.");
    }

    /**
     * Ein neues Passwort für ein fremdes Konto.
     *
     * **Ohne das alte**, im Unterschied zum eigenen Passwortwechsel: Der
     * Betreiber kennt es nicht, und das ist der Fall, für den es diesen Weg
     * gibt. Was ihn trägt, ist die Fähigkeit an der Route — wer sie hat, hat
     * den Server ohnehin.
     *
     * **Kein zweiter Faktor wird dabei zurückgesetzt.** Wer sich mit seinem
     * zweiten Faktor ausgesperrt hat, kommt auch mit einem neuen Passwort nicht
     * herein; dafür bleibt `srvpanel:admin` der Rückweg (`docs/82 §3`,
     * Falle 3). Ein Knopf, der beides zugleich abräumt, machte aus dem
     * Passwortwechsel eine Übernahme des Kontos.
     */
    public function password(Request $request, Account $admin, Audit $audit): RedirectResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', ...Policy::rules()],
        ]);

        $admin->forceFill([
            'password' => Hash::make((string) $request->input('password')),
        ])->save();

        $audit->success('account.password.reset', $admin, [
            'name' => $admin->name,
            'email' => $admin->email,
        ]);

        return redirect()->route('accounts.index')
            ->with('success', "Passwort von {$admin->name} gesetzt.");
    }

    /**
     * Die Rollen für das Auswahlfeld.
     *
     * Aus dem Enum und nicht als Liste im Formular: Eine dritte Rolle
     * (`docs/82 §4`) stünde sonst an zwei Stellen, und die zweite ist die, die
     * veraltet.
     *
     * @return list<array{value: string, label: string}>
     */
    private static function roles(): array
    {
        return array_map(
            static fn (AdminRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            AdminRole::cases(),
        );
    }
}
