<?php

declare(strict_types=1);

namespace App\Support\Operations;

use App\Enums\OperationStatus;
use App\Jobs\RunAgentOperation;
use App\Models\Account;
use App\Models\Operation;
use App\Models\Subscription;

/**
 * Die eine Stelle, an der Vorgänge entstehen.
 *
 * Sie ist schmal, damit niemand versehentlich einen Vorgang anlegt, ohne ihn
 * einzureihen — oder umgekehrt einen Auftrag einreiht, zu dem es keinen
 * sichtbaren Vorgang gibt. Beides führt zu derselben unangenehmen Lage: Der
 * Betreiber sieht etwas anderes, als das System tut.
 */
final class Operations
{
    /**
     * Einen Vorgang anlegen und einreihen.
     *
     * @param  array<string,mixed>  $payload
     */
    public function dispatch(
        string $type,
        array $payload = [],
        ?Subscription $subscription = null,
        ?Account $account = null,
        ?string $message = null,
    ): Operation {
        $operation = new Operation([
            'type' => $type,
            'payload' => $payload === [] ? null : $payload,
            'subscription_id' => $subscription?->id,
            'account_id' => $account?->id,
            'status' => OperationStatus::Queued,
            'progress' => 0,
            'message' => $message,
            'origin' => $this->origin(),
        ]);

        // Ausdrücklich gesetzt statt der Klammer überlassen: Ein Vorgang des
        // Betreibers trägt kein Abonnement, und das darf nicht davon abhängen,
        // wie viele Mandanten gerade aktiv sind.
        $operation->subscription_id = $subscription?->id;
        $operation->save();

        RunAgentOperation::dispatch((int) $operation->id);

        return $operation;
    }

    /**
     * Von welcher Seite aus dieser Vorgang ausgelöst wurde — oder `null`.
     *
     * **Gefragt wird die Sitzung und nicht `url()->previous()`.** Der Helfer
     * fällt der Reihe nach auf den `Referer` und dann auf die Wurzel der
     * Anwendung zurück; ein Vorgang der Zertifikatsautomatik trüge damit `/`
     * als Herkunft, und die Vorgangsseite böte einen Weg zurück zu einer Seite,
     * an der niemand war.
     *
     * > **Ein Rückfall, der immer etwas liefert, macht aus „unbekannt" eine
     * > falsche Auskunft.**
     *
     * Geschrieben wird der Wert von der Mittelschicht `RememberPageUrl` — und
     * zwar nur bei einem echten Seitenaufruf des Panels. Ihr Name steht hier
     * als Fliesstext und nicht als `{@see}`: Pint macht daraus einen
     * `use`-Eintrag, und ein unbenutzter Import ist genau die Zeile, die beim
     * nächsten Aufräumen wieder verschwindet. Eine
     * Weiterleitung, ein Abruf im Hintergrund und der Vorgangskanal stehen dort
     * nicht.
     *
     * **Ein Pfad und keine volle Adresse:** Das Panel ist unter mehreren Namen
     * erreichbar, und eine Adresse mit Rechnernamen wäre unter dem zweiten
     * falsch.
     */
    private function origin(): ?string
    {
        $request = request();

        // Ohne Sitzung gibt es keine Herkunft. Das trifft die Konsole, die
        // Warteschlange und jeden Lauf der Automatik — dort ist `null` die
        // Wahrheit und kein fehlender Wert.
        if (! $request->hasSession()) {
            return null;
        }

        $previous = $request->session()->previousUrl();

        if (! is_string($previous) || $previous === '') {
            return null;
        }

        $pfad = parse_url($previous, PHP_URL_PATH);

        if (! is_string($pfad) || ! str_starts_with($pfad, '/')) {
            return null;
        }

        $frage = parse_url($previous, PHP_URL_QUERY);
        $ganz = is_string($frage) && $frage !== '' ? $pfad.'?'.$frage : $pfad;

        // **Verworfen und nicht abgeschnitten.** Ein halber Pfad führt
        // irgendwohin, und irgendwohin ist schlechter als nirgendwohin.
        return mb_strlen($ganz) > 255 ? null : $ganz;
    }
}
