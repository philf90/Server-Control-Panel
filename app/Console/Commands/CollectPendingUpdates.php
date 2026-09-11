<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Client;

/**
 * Zählt die aktualisierbaren Pakete und legt die Zahl ab — für das Abzeichen
 * am Menüpunkt „Updates" (`docs/907`).
 *
 * **Warum es diesen Lauf gibt, obwohl die Seite schon schreibt.** Wer
 * `/updates` öffnet, bezahlt den Aufruf ohnehin, und das Ergebnis wird dort
 * festgehalten. Was die Seite nicht sieht, ist alles, was **ausserhalb** des
 * Panels geschieht: `unattended-upgrades` spielt ein, `apt-daily` frischt die
 * Listen auf, und die Zahl stimmt danach nicht mehr. Dieser Lauf ist der
 * Rückfall dafür und nicht der Normalfall.
 *
 * **Er fragt die dpkg-Sperre nicht.** `system.packages.list` ist die eine
 * Operation, die sie nicht braucht — `apt-get -s` läuft bei gehaltener Sperre,
 * und `AptLockReachTest::EXCEPTIONS` trägt sie mit genau diesem gemessenen
 * Grund. Eine Frage an `AptLock` wäre hier eine Vorkehrung gegen etwas, das
 * nicht passiert.
 *
 * **Und er schreibt nichts, wenn er nichts gemessen hat.** Antwortet der Agent
 * nicht, bleibt die alte Zahl stehen und der Lauf meldet es. Eine `0` zu
 * schreiben, weil man nicht nachsehen konnte, wäre der Fehler, mit dem P7b
 * angefangen hat:
 *
 * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts zu
 * > tun".**
 */
final class CollectPendingUpdates extends Command
{
    protected $signature = 'srvpanel:packages';

    protected $description = 'Zählt die aktualisierbaren Pakete für das Abzeichen in der Navigation';

    public function handle(Client $agent, Settings $settings): int
    {
        try {
            /** @var array<string, mixed> $antwort */
            $antwort = $agent->call('system.packages.list', []);
        } catch (AgentException $exception) {
            $this->error('Der Paketstand ist nicht feststellbar: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (! is_array($antwort['upgradable'] ?? null)) {
            // Der Agent hat geantwortet, aber ohne die Liste. Das ist ein
            // anderer Fall als „er antwortet nicht" und verdient einen eigenen
            // Satz — die alte Zahl bleibt in beiden stehen.
            $this->error('Die Antwort des Agenten führt keine Paketliste.');

            return self::FAILURE;
        }

        $offen = count($antwort['upgradable']);
        $settings->savePendingUpdates($offen);

        $this->info(sprintf('%d aktualisierbare Pakete festgehalten.', $offen));

        return self::SUCCESS;
    }
}
