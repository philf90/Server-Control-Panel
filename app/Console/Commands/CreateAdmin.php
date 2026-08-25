<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\AccountType;
use App\Enums\AdminRole;
use App\Models\Account;
use App\Support\Passwords\Policy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Ein Adminkonto anlegen oder sein Passwort zurücksetzen.
 *
 * **Warum es diesen Befehl gibt, obwohl die Ersteinrichtung später einen Link
 * ausgibt.** Weil der Link ein einziges Mal funktioniert. Wer ihn übersieht,
 * wer sein Passwort vergisst, wer sich mit einem falsch eingerichteten
 * zweiten Faktor aussperrt — für all das braucht es einen Weg über die
 * Kommandozeile, und wer auf dem Server root ist, hat den Server ohnehin.
 *
 * Das Passwort wird nicht als Argument entgegengenommen. Ein Passwort in der
 * Kommandozeile steht in der Shell-Historie, in `ps` und im Protokoll des
 * Aufrufers — es wird abgefragt oder erzeugt.
 */
final class CreateAdmin extends Command
{
    protected $signature = 'srvpanel:admin
                            {email? : Die Anmeldeadresse}
                            {--name= : Anzeigename}
                            {--generate : Ein Passwort erzeugen und einmalig anzeigen}';

    protected $description = 'Legt ein Adminkonto an oder setzt dessen Passwort zurück';

    public function handle(): int
    {
        $email = Str::lower(trim((string) ($this->argument('email') ?? $this->ask('Anmeldeadresse'))));

        $validator = Validator::make(['email' => $email], [
            'email' => ['required', 'email', 'max:255'],
        ]);

        if ($validator->fails()) {
            $this->error('Keine gültige Adresse.');

            return self::FAILURE;
        }

        $existing = Account::query()->where('email', $email)->first();

        if ($existing !== null && ! $existing->type->isAdmin()) {
            // Ein Kundenkonto zum Admin zu machen, wäre eine stille
            // Rechteausweitung — und zwar genau die, die man in einem
            // Protokoll später sucht und nicht findet.
            $this->error('Diese Adresse gehört zu einem Konto, das kein Adminkonto ist. Abgebrochen.');

            return self::FAILURE;
        }

        [$password, $generated] = $this->password();

        if ($password === null) {
            $this->error('Kein Passwort gesetzt. Abgebrochen.');

            return self::FAILURE;
        }

        if ($existing !== null) {
            $existing->forceFill([
                'password' => Hash::make($password),
                'status' => AccountStatus::Active,
            ])->save();

            $this->info("Passwort von {$email} gesetzt, Konto aktiv.");
        } else {
            Account::query()->create([
                'type' => AccountType::Admin,

                /*
                 * **Betreiber und nicht Administrator.** Dieses Kommando ist
                 * der Rückweg, wenn sich jemand ausgesperrt hat (`docs/82 §3`,
                 * Falle 3) — ein Konto, das es anlegt, muss den Server wieder
                 * in die Hand bekommen können. Ein Administrator käme nicht an
                 * die Einstellungen und damit nicht an die Ursache.
                 *
                 * Wer einen Administrator will, legt ihn in der Oberfläche an;
                 * das ist Schritt 3.
                 */
                'role' => AdminRole::Operator,
                'customer_id' => null,
                'name' => (string) ($this->option('name') ?: 'Administrator'),
                'email' => $email,
                'password' => $password,
                'status' => AccountStatus::Active,
            ]);

            $this->info("Adminkonto {$email} angelegt.");
        }

        if ($generated) {
            $this->newLine();
            $this->line('  Passwort: '.$password);
            $this->newLine();
            $this->warn('Es wird nicht noch einmal angezeigt.');
        }

        return self::SUCCESS;
    }

    /**
     * @return array{0: string|null, 1: bool} Passwort und ob es erzeugt wurde
     */
    private function password(): array
    {
        if ($this->option('generate')) {
            return [Policy::generate(), true];
        }

        $password = $this->secret('Passwort (Eingabe bleibt unsichtbar)');

        if (! is_string($password) || $password === '') {
            return [null, false];
        }

        // Die Prüfung kommt aus derselben Klasse wie die Validierung im Panel.
        // Hier stand `mb_strlen($password) < 12` — die Kommandozeile war damit
        // der Weg, an der Richtlinie vorbei ein schwaches Passwort für ein
        // Adminkonto zu setzen. Ausgerechnet für das Konto, das alles darf.
        $unmet = Policy::unmet($password);

        if ($unmet !== []) {
            $this->error('Das Passwort erfüllt die Richtlinie nicht:');

            foreach ($unmet as $requirement) {
                $this->line('  - '.$requirement);
            }

            return [null, false];
        }

        if ($this->secret('Passwort wiederholen') !== $password) {
            $this->error('Die Eingaben stimmen nicht überein.');

            return [null, false];
        }

        return [$password, false];
    }
}
