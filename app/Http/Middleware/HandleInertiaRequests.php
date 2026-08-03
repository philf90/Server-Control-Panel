<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
use App\Support\Audit\Impersonation;
use App\Support\Passwords\Policy;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Was auf jeder Seite verfügbar ist.
     *
     * Der Quellenlink gehört dazu, weil er auf jeder Seite steht — die Auflage
     * aus Abschnitt 13 der AGPL gilt für die Oberfläche, nicht für eine
     * Unterseite davon.
     *
     * @return array<string,mixed>
     */
    public function share(Request $request): array
    {
        $account = $request->user();

        return array_merge(parent::share($request), [
            'source' => [
                'repository' => config('srvpanel.source.repository'),
                'commit' => config('srvpanel.source.commit'),
                'version' => config('app.version'),
            ],

            // Nur, was die Oberfläche wirklich braucht.
            //
            // Nicht das ganze Konto: Ein Modell, das man hierher reicht,
            // wächst mit der Zeit um Felder, die niemand angesehen hat — und
            // steht dann als JSON im Quelltext jeder Seite. Passwort-Hash und
            // 2FA-Geheimnis wären zwar über $hidden ausgenommen, aber die
            // nächste Spalte ist es nicht.
            'account' => $account instanceof Account ? [
                'name' => $account->name,
                'email' => $account->email,
                'type' => $account->type->value,
                'is_admin' => $account->isAdmin(),
            ] : null,

            // „Anmelden als" muss auf jeder Seite sichtbar sein (§6.3). Ein
            // Admin, der vergisst, in wessen Sicht er ist, tut sonst im Namen
            // eines Kunden Dinge, die er für seine eigenen hält.
            'impersonation' => $this->impersonation($request),

            // Die Passwortrichtlinie steht auf jeder Seite bereit, weil ein
            // Passwortfeld überall auftauchen kann — beim Anlegen eines
            // Kunden, beim Ändern des eigenen, später beim Zurücksetzen. Sie
            // hier zu teilen ist billiger als jede Seite daran zu erinnern,
            // und sie kommt aus derselben Klasse wie die Validierung: Was der
            // Browser als Prüfliste zeigt, ist damit keine Behauptung über die
            // Regeln, sondern die Regeln.
            'passwordPolicy' => [
                'minimum' => Policy::MINIMUM_LENGTH,
                'requirements' => Policy::requirements(),
            ],

            'flash' => [
                'notice' => fn () => $request->session()->get('notice'),
                'success' => fn () => $request->session()->get('success'),
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
            ],
        ]);
    }

    /**
     * Läuft gerade ein „Anmelden als"? Und wenn ja, wer hat es begonnen?
     *
     * @return array<string, mixed>|null
     */
    private function impersonation(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $adminId = $request->session()->get(Impersonation::SESSION_KEY);

        if (! is_numeric($adminId)) {
            return null;
        }

        $admin = Account::query()->find((int) $adminId);

        return [
            'active' => true,
            'admin' => $admin->name ?? 'unbekannt',
        ];
    }
}
