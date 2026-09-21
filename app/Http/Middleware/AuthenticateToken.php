<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\ApiToken;
use App\Support\Authorization\AccountAccess;
use App\Support\Tenancy\Tenancy;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Die Wache von `api/v1` — ein Konto aus einer Zugangsmarke (B7).
 *
 * ## Nur aus dem Kopf `Authorization`, nie aus der Adresse
 *
 * Das ist gemessen und keine Vorsicht (`docs/130` A7). nginx schreibt
 * `"$request"` ins Zugriffsprotokoll — die Anfragezeile **mitsamt
 * Abfrageteil**. Ein Token in der Adresse stünde damit vierzehn Tage lang in
 * einer Datei, die `0640 <benutzer> adm` gehört und die dieses Panel auf
 * `/logs` und auf der Domainseite selbst anzeigt. Gemessen: Token im
 * Abfrageteil **1** Treffer im Protokoll, Token im Kopf **0**.
 *
 * > **Ein Geheimnis, das in der Adresse reist, steht in einer Datei, die das
 * > Panel dem Betreiber vorliest.**
 *
 * Deshalb liest diese Wache `$request->bearerToken()` und **nichts sonst**.
 * Eine zweite Quelle „für die Bequemlichkeit" wäre genau der Weg, auf dem das
 * Geheimnis doch in die Adresse kommt.
 *
 * ## Ein Adminkonto kommt hier nicht durch
 *
 * {@see Tenancy::forAccount()} ruft für einen Admin
 * `allowAll()`. Eine Marke an einem Adminkonto wäre damit ein Bearer-Token
 * **ohne Klammer** über den ganzen Server — ohne zweiten Faktor und ohne die
 * Netzbeschränkung aus A9, die beide an der Sitzung hängen.
 *
 * Gefragt wird **hier** und nicht nur dort, wo eine Marke entsteht: Ein Konto
 * kann seinen Typ wechseln, und die Marke trüge dann eine Zusage, die niemand
 * mehr gemacht hat.
 *
 * > **Eine Prüfung beim Anlegen gilt für den Zustand beim Anlegen.**
 *
 * ## Und der Kontozustand wird gefragt
 *
 * Ein gesperrtes oder zurückgezogenes Konto kommt nicht herein. Auf dem
 * Sitzungsweg tut das {@see EnforceAccountAccess} — hier steht dieselbe Frage,
 * weil eine Marke keine Sitzung hat, die man beenden könnte.
 */
final class AuthenticateToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = $request->bearerToken();

        if ($plain === null || $plain === '') {
            throw new AuthenticationException;
        }

        $token = ApiToken::query()
            ->where('token_hash', ApiToken::hashOf($plain))
            ->first();

        if ($token === null) {
            throw new AuthenticationException;
        }

        $account = $token->account;

        if ($account === null || $account->type->isAdmin() || ! AccountAccess::permits($account)) {
            throw new AuthenticationException;
        }

        $token->noteUsage(now());

        Auth::setUser($account);

        return $next($request);
    }
}
