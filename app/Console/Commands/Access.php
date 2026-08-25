<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditResult;
use App\Support\Audit\Audit;
use App\Support\Authorization\AdminNetwork;
use App\Support\Settings\Settings;
use Illuminate\Console\Command;
use SrvPanel\Agent\AgentException;

/**
 * Die Netze der Panel-Anmeldung ansehen, ergänzen, entfernen — und abräumen.
 *
 * ## Wofür es dieses Kommando gibt
 *
 * **`--clear` ist der Grund.** Die Netzbeschränkung ist die einzige Einstellung
 * dieses Panels, die ihren eigenen Betreiber aussperren kann — nicht durch
 * einen Fehler im Formular (dagegen steht {@see AdminNetwork::covers()}),
 * sondern durch die Welt: Ein Anschluss bekommt eine neue Adresse, ein Umzug,
 * ein Anbieter, der neu nummeriert. Danach ist die gespeicherte Liste richtig
 * und trotzdem falsch.
 *
 * `srvpanel admin` holt ein Konto zurück, das sich mit Passwort oder zweitem
 * Faktor ausgesperrt hat. Für die Netzbeschränkung gab es bis zum 25. August
 * 2026 nichts dergleichen — nur einen `tinker`-Einzeiler, der in keiner Hilfe
 * stand.
 *
 * > **Ein Rückweg, den man erst sucht, wenn man ihn braucht, ist keiner.**
 *
 * Aufgefallen ist das nicht beim Bauen, sondern beim **Ausschreiben des
 * Abnahmelaufs** (`docs/83 §2.1`): Der Lauf stellt eine Beschränkung her, und
 * beim Aufschreiben des Rückwegs war keiner da.
 *
 * > **Wer eine Anleitung schreibt, geht die Schritte im Kopf — und merkt, wo
 * > keiner ist.**
 *
 * ## Warum hier **kein** Aussperrschutz greift
 *
 * Das Formular weist eine Liste ab, die den Urheber nicht trägt. Hier nicht, und
 * das ist Absicht: Wer dieses Kommando aufruft, sitzt auf dem Server. Die Frage
 * „deckt diese Liste deine Adresse" hat für ihn keine sinnvolle Antwort — seine
 * Adresse ist die des Terminals, nicht die seines Browsers.
 *
 * > **Eine Schranke, die den Weg um sich herum bewachen soll, bewacht die
 * > falsche Tür.** Wer auf dem Server root ist, hat den Server ohnehin.
 *
 * ## Und jede Änderung steht im Protokoll
 *
 * Auch die von der Kommandozeile, und dort **ohne** handelndes Konto — es war
 * keines angemeldet. Ein Rückweg, den niemand nachvollziehen kann, ist eine
 * stille Rechteänderung.
 *
 * > **Ein Weg, der an der Oberfläche vorbeiführt, gehört erst recht ins
 * > Protokoll.**
 */
final class Access extends Command
{
    protected $signature = 'srvpanel:access
        {--clear : Die Beschränkung aufheben — die Anmeldung ist danach von überall möglich}
        {--add=* : Ein Netz aufnehmen, etwa 192.0.2.0/24 oder 2001:db8::1 — mehrfach möglich}
        {--remove=* : Ein Netz entfernen — mehrfach möglich}';

    protected $description = 'Zeigt und ändert die Netze, aus denen sich Verwaltungskonten anmelden dürfen';

    public function handle(Settings $settings, Audit $audit): int
    {
        $before = $settings->adminNetworks();

        /** @var list<string> $add */
        $add = (array) $this->option('add');
        /** @var list<string> $remove */
        $remove = (array) $this->option('remove');
        $clear = (bool) $this->option('clear');

        // Ohne Auftrag wird nur gezeigt. Ein Kommando, das ohne Schalter etwas
        // ändert, ist eine Falle für den, der nachsehen wollte.
        if (! $clear && $add === [] && $remove === []) {
            $this->show($before);

            return self::SUCCESS;
        }

        if ($clear && ($add !== [] || $remove !== [])) {
            $this->error('--clear räumt alles ab; zusammen mit --add oder --remove ergibt es keinen Sinn.');

            return self::FAILURE;
        }

        $after = $clear ? [] : $this->apply($before, $add, $remove);

        if ($after === null) {
            return self::FAILURE;
        }

        if ($after === $before) {
            $this->line('Nichts zu ändern.');
            $this->show($before);

            return self::SUCCESS;
        }

        $settings->saveAdminNetworks($after);

        /*
         * **Ohne handelndes Konto, und das ist keine Lücke.** Auf der
         * Kommandozeile ist niemand angemeldet; ein Eintrag, der sich einen
         * Handelnden ausdenkt, wäre schlechter als einer ohne.
         */
        $audit->record(
            'settings.access',
            AuditResult::Success,
            context: [
                'quelle' => 'Kommandozeile',
                'vorher' => $before === [] ? 'keine Beschränkung' : implode(', ', $before),
                'nachher' => $after === [] ? 'keine Beschränkung' : implode(', ', $after),
            ],
        );

        $this->show($after);

        if ($after === []) {
            $this->newLine();
            $this->warn('Die Anmeldung ist jetzt von überall möglich.');
        }

        return self::SUCCESS;
    }

    /**
     * Die neue Liste — oder `null`, wenn eine Angabe unbrauchbar war.
     *
     * **Geprüft wird alles, bevor etwas gespeichert wird.** Ein Aufruf mit drei
     * Netzen, von denen das zweite falsch ist, soll nicht das erste eintragen
     * und dann abbrechen — sonst steht die Liste danach in einem Zustand, den
     * niemand gewollt hat.
     *
     * > **Eine Änderung, die zur Hälfte durchläuft, ist schlimmer als eine, die
     * > gar nicht läuft.**
     *
     * @param  list<string>  $before
     * @param  list<string>  $add
     * @param  list<string>  $remove
     * @return list<string>|null
     */
    private function apply(array $before, array $add, array $remove): ?array
    {
        $after = $before;

        foreach ($add as $entry) {
            try {
                $normalized = AdminNetwork::normalize($entry);
            } catch (AgentException $error) {
                $this->error($error->getMessage());

                return null;
            }

            if (! in_array($normalized, $after, true)) {
                $after[] = $normalized;
            }
        }

        foreach ($remove as $entry) {
            /*
             * **Auch beim Entfernen normalisiert.** Wer `192.0.2.7` tippt,
             * meint die Zeile `192.0.2.7/32`, die in der Liste steht — ein
             * Vergleich der rohen Eingabe fände sie nicht und meldete „nichts
             * zu ändern".
             *
             * Eine unbrauchbare Angabe ist hier trotzdem ein Fehler und kein
             * Achselzucken: Wer ein Netz entfernen will, das es so nicht gibt,
             * hat sich vertippt — und das Ergebnis „nichts geschehen" liest
             * sich wie Erfolg.
             */
            try {
                $normalized = AdminNetwork::normalize($entry);
            } catch (AgentException $error) {
                $this->error($error->getMessage());

                return null;
            }

            $after = array_values(array_filter(
                $after,
                static fn (string $network): bool => $network !== $normalized,
            ));
        }

        return array_values($after);
    }

    /** @param  list<string>  $networks */
    private function show(array $networks): void
    {
        if ($networks === []) {
            $this->info('Keine Beschränkung — Verwaltungskonten können sich von überall anmelden.');

            return;
        }

        $this->info(sprintf(
            'Verwaltungskonten dürfen sich aus %d %s anmelden:',
            count($networks),
            count($networks) === 1 ? 'Netz' : 'Netzen',
        ));

        foreach ($networks as $network) {
            $this->line('  '.$network);
        }

        $this->newLine();
        $this->line('Kundenkonten sind davon nicht betroffen.');
    }
}
