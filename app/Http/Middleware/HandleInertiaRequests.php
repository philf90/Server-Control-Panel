<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Account;
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

            'flash' => [
                'hinweis' => fn () => $request->session()->get('hinweis'),
                'erfolg' => fn () => $request->session()->get('erfolg'),
            ],
        ]);
    }
}
