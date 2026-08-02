<?php

declare(strict_types=1);

namespace App\Http\Middleware;

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
        return array_merge(parent::share($request), [
            'quelle' => [
                'repository' => config('cloudsrv.quelle.repository'),
                'commit' => config('cloudsrv.quelle.commit'),
                'version' => config('app.version'),
            ],
        ]);
    }
}
