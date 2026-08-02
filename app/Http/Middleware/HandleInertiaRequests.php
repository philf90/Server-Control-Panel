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
            'source' => [
                'repository' => config('cloudsrv.source.repository'),
                'commit' => config('cloudsrv.source.commit'),
                'version' => config('app.version'),
            ],
        ]);
    }
}
