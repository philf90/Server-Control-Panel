<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\SubscriptionStatus;
use App\Models\Account;
use App\Models\Announcement;
use App\Models\Subscription;
use App\Support\Audit\Impersonation;
use App\Support\Authorization\AdminAbility;
use App\Support\Panel\Source;
use App\Support\Passwords\Policy;
use Illuminate\Http\Request;
use Illuminate\Support\ViewErrorBag;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /**
     * Alle Meldungen eines Feldes und nicht nur die erste.
     *
     * ## Der Fehler, den `report()` nicht sehen konnte
     *
     * Inertias Laravel-Anbindung bildet den Fehlerbeutel auf
     * `Feld => erste Meldung` ab. Ein `ValidationException::withMessages([
     * 'path' => [$zahl, $grund1, $grund2]])` kommt damit als **eine**
     * Zeichenkette im Browser an — der Rest fällt weg, bevor die Seite ihn
     * sieht.
     *
     * Gemessen auf `cloudsrv24` am 15. August 2026 (`docs/55`, Befund 12): Die
     * Mehrfachauswahl meldete „Von 3 Einträgen sind 2 entfernt." und **keinen
     * einzigen Grund**. Bei sechs geschützten Verzeichnissen dasselbe: die
     * Zahl, und sechsmal Schweigen darüber, woran es lag.
     *
     * **Es traf nicht nur die Mehrfachauswahl.** Der Mehrfach-Upload aus
     * Schritt 5e baut seine Rückmeldung genauso — die Zeile je Datei ist seit
     * dem ersten Tag unsichtbar gewesen. Ihr Wächter liest den Quelltext des
     * Controllers und konnte das nicht sehen.
     *
     * > **Eine Meldung, die der Controller schreibt, ist damit noch keine, die
     * > jemand liest.**
     *
     * Die Zahl allein ist dabei die schlechtere Hälfte: Sie sagt, dass etwas
     * schiefging, und verschweigt was — und der Kunde kann nichts daraus
     * machen.
     *
     * @return object Ein Wörterbuch `Feld => Sätze`, mit `\n` verbunden.
     */
    public function resolveValidationErrors(Request $request): object
    {
        $beutel = $request->session()->get('errors');

        if (! $beutel instanceof ViewErrorBag) {
            return (object) [];
        }

        /*
         * **Verbunden und nicht verschachtelt.** Ein Feld könnte auch eine
         * Liste zurückgeben; dann müsste jede Stelle, die Fehler liest, mit
         * beiden Formen rechnen — und die eine, die es vergisst, zeigt gar
         * nichts. Ein Zeilenumbruch trägt dieselbe Auskunft und bleibt eine
         * Zeichenkette.
         */
        $verbunden = [];

        /*
         * Von Hand statt über `collect()->map()`: Die Sammlung kennt den Typ
         * ihrer Schlüssel hier nicht, und eine Typangabe, die das behauptet,
         * wäre eine Behauptung über fremden Code. Eine Schleife sagt dasselbe
         * ohne sie.
         *
         * **`toArray()` und nicht `messages()`.** `getBag()` liefert die
         * Schnittstelle `Contracts\Support\MessageBag`, und die kennt
         * `messages()` nicht — das ist eine Methode der konkreten Klasse
         * dahinter. Zur Laufzeit ginge beides, weil dort immer diese Klasse
         * steht; das ist aber eine Zusage, die die Schnittstelle nicht macht.
         *
         * > **Eine Methode, die es zur Laufzeit gibt, steht deshalb noch nicht
         * > im Vertrag.**
         */

        /** @var array<string, list<string>> $meldungen */
        $meldungen = $beutel->getBag('default')->toArray();

        foreach ($meldungen as $feld => $saetze) {
            $verbunden[$feld] = implode("\n", $saetze);
        }

        return (object) $verbunden;
    }

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
            /*
             * **Eine fertige Adresse und keine Zutaten.** Hier standen
             * `repository` und `commit`, und die Vorlage setzte daraus den Link
             * zusammen — eine zweite Fassung der Regel, und zwar in einem
             * Template. {@see Source::url()} entscheidet jetzt, und die
             * Oberfläche zeigt nur noch an.
             */
            'source' => [
                'url' => Source::url(),
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

                /*
                 * Hat dieses Konto ein benutzbares Abonnement?
                 *
                 * **Wofür.** Der Menüpunkt „Domains" steht bei einem Kunden
                 * erst da, wenn es einen Ort gibt, an dem eine Domain entstehen
                 * kann. Ohne aktives Abonnement führte er auf eine leere Liste
                 * ohne Knopf — eine Sackgasse mit Einladung.
                 *
                 * **Warum die Antwort hierher gehört.** Das Menü steht auf
                 * jeder Seite; jede einzelne müsste die Frage sonst selbst
                 * beantworten, und die meisten täten es nicht. Es ist keine
                 * Autorisierung — die sitzt an der Route (`can:viewAny`) —,
                 * sondern dieselbe Auskunft wie `is_admin` daneben: welcher Weg
                 * überhaupt einer ist.
                 *
                 * Für den Betreiber ohne Abfrage `true`: Er sieht die Liste
                 * immer, und eine Zählung über alle Abonnements bei jedem
                 * Seitenaufruf wäre für eine Antwort bezahlt, die feststeht.
                 */
                'has_active_subscription' => $account->isAdmin()
                    || Subscription::query()
                        ->whereIn('id', $account->accessibleSubscriptionIds())
                        ->whereIn('status', SubscriptionStatus::usableValues())
                        ->exists(),
            ] : null,

            /*
             * **Was der Betrachter auf diesem Server darf** — A9 Schritt 5.
             *
             * Die Navigation kam bis hierher aus dem **Kontotyp**, und das war
             * richtig, solange jeder Admin alles durfte. Seit Schritt 2 lösen
             * die Fähigkeiten über die Rolle auf: Ein Administrator sah sieben
             * Menüpunkte, die ihm alle einen 403 gaben.
             *
             * > **Wer eine Aktion zeigt, fragt vorher dieselbe Policy, die sie
             * > später abweist.**
             *
             * Gefragt wird `$account->can()` und damit dasselbe Gate, an dem
             * die Route hängt. Ein `v-if` auf die Rolle im Menü wäre die zweite
             * Fassung der Policy, und die zweite ist die, die veraltet.
             *
             * ## Warum der Schlüssel `abilities` heisst und nicht `can`
             *
             * `can` ist vergeben: **Neun Seiten** schicken eine eigene
             * `can`-Ablage — „was darf ich mit *diesem* Objekt". Seitenwerte
             * überschreiben geteilte, und ein geteiltes `can` wäre auf genau
             * diesen neun Seiten fort. Das Menü verlöre dort seine Einträge,
             * und zwar nur dort.
             *
             * > **Ein geteilter Schlüssel, den eine Seite auch benutzt, ist auf
             * > dieser Seite kein geteilter Schlüssel mehr — und der Ausfall
             * > sieht aus wie ein Rechteproblem.**
             *
             * Die beiden sind auch inhaltlich verschieden: `can` beantwortet
             * eine Frage über ein Objekt, `abilities` eine über den Server.
             */
            'abilities' => $account instanceof Account ? $this->abilities($account) : [],

            // „Anmelden als" muss auf jeder Seite sichtbar sein (§6.3). Ein
            // Admin, der vergisst, in wessen Sicht er ist, tut sonst im Namen
            // eines Kunden Dinge, die er für seine eigenen hält.
            'impersonation' => $this->impersonation($request),

            /*
             * Die Ankündigungen des Betreibers (A14, `docs/103 §7`).
             *
             * **Als Verschluss und nicht als fertiger Wert, und das ist
             * gemessen** (`docs/81 §2.3q` M5). Ein fertiger Wert in `share()`
             * wird auch bei einem partiellen Nachladen berechnet, das ihn gar
             * nicht mitschickt — gezählt an den Abfragen: voller Besuch 2,
             * partielles Nachladen 1, und die eine war die des fertigen Werts.
             *
             * > **Ein fertiger Wert in `share()` läuft bei jeder Anfrage, auch
             * > bei einer, die ihn gar nicht mitschickt. Ein Verschluss läuft
             * > nur, wenn er gesendet wird.**
             *
             * Das ist hier nicht theoretisch: Die Übersichtsseite lädt mit
             * ihrem Selbstlauf alle dreissig Sekunden `only: ['tiles']` nach.
             * Ohne den Verschluss liefe die Abfrage jedes Mal für eine Antwort,
             * die der Server verwirft.
             *
             * Dieselbe Form wie `flash` daneben, und aus demselben Grund.
             */
            'announcements' => fn (): array => $account instanceof Account
                ? Announcement::visibleTo($account)
                    ->map(static fn (Announcement $a): array => [
                        'id' => $a->id,
                        'badge' => $a->category->badge(),
                        'rank' => $a->category->label(),
                        'body' => $a->body,
                    ])
                    ->all()
                : [],

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

            /*
             * **Jeder Schlüssel, den ein Controller schreibt, steht hier.**
             * `error` fehlte bis zum 17. August 2026, und damit fielen sieben
             * Meldungen aus vier Controllern lautlos aus — darunter „Zertifikat
             * abgewiesen" und „Der Versand ist gescheitert" (`docs/59`,
             * Befund 13). `Settings/Mail.vue` las den Schlüssel sogar und
             * renderte ihn; getragen hat ihn niemand.
             *
             * > **Ein Schreiber und ein Leser machen keinen Kanal. Dazwischen
             * > muss jemand tragen.**
             *
             * `FlashChannelTest` prüft beide Richtungen.
             */
            'flash' => [
                'notice' => fn () => $request->session()->get('notice'),
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'recoveryCodes' => fn () => $request->session()->get('recoveryCodes'),
            ],
        ]);
    }

    /**
     * Die Adminfähigkeiten des Betrachters, jede mit ihrer Antwort.
     *
     * **Aus der Registratur und nicht aus einer Liste hier.** Eine Fähigkeit,
     * die {@see AdminAbility::abilities()} dazubekommt, steht damit sofort auch
     * in der Ablage — sonst wäre dies die Stelle, an der jemand sie nachtragen
     * müsste und es vergisst.
     *
     * **Für einen Kunden sind alle `false`, und das ist kein Sonderfall.**
     * `Account::fulfils()` fragt beide Achsen; ein Kundenkonto erfüllt keine
     * Adminrolle. Die Ablage steht trotzdem da — eine fehlende Ablage und eine
     * mit lauter `false` müssen für die Oberfläche dasselbe bedeuten, sonst
     * hängt an ihrem Unterschied irgendwann eine Bedingung.
     *
     * @return array<string, bool>
     */
    private function abilities(Account $account): array
    {
        $abilities = [];

        foreach (array_keys(AdminAbility::abilities()) as $ability) {
            $abilities[$ability] = $account->can($ability);
        }

        return $abilities;
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
