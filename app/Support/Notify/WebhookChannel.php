<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Models\Finding;
use SrvPanel\Agent\AgentException;

/**
 * Der Kanal an den **Betreiber** — B1, `docs/129 §7`.
 *
 * **Der Weg nach draussen liegt im Agenten, und das ist Grenze 1.** Das Panel
 * schickt hier einen Befund und keine Adresse: Wer die Adresse mitgeben dürfte,
 * gäbe irgendwann `http://127.0.0.1:…` mit, und das Panel spräche als
 * `srvpanel` mit jedem Dienst dieses Servers. Die Adresse holt
 * {@see \SrvPanel\Agent\Notify\Delivery} aus der Ablage des Agenten.
 *
 * **Er trägt jeden beurteilten Befund und nicht nur die des Betreibers.** Ein
 * überzogenes Kontingent geht den Kunden an — und den Betreiber auch, denn auf
 * seinem Server wächst die Platte. Der Unterschied zu {@see MailChannel} ist
 * nicht die Schwere, sondern der Empfänger: Ein Ziel je Server gehört dem
 * Betreiber, und der sieht ohnehin jede Zeile der Diagnoseseite.
 *
 * **Warum es hier kein „kein Empfänger" gibt.** Es gibt genau ein Ziel, oder
 * {@see usable()} ist `false`. {@see Delivery::WithoutRecipient} wäre ein
 * Ausgang, den dieser Kanal nicht herstellen kann — und ein Ausgang, der nie
 * eintritt, ist eine Zeile, die niemand prüfen kann.
 */
final class WebhookChannel implements Channel
{
    public function __construct(private readonly NotifyTarget $target) {}

    /** Der Schlüssel dieses Kanals — siehe {@see MailChannel::CHANNEL}. */
    public const CHANNEL = 'webhook';

    public function key(): string
    {
        return self::CHANNEL;
    }

    /**
     * Steht ein Ziel da?
     *
     * **Ein schweigender Agent ist `false` und kein Fehlschlag.** Er kann heute
     * nacht angehalten sein; dann wird nichts gebucht, und die Befunde bleiben
     * fällig. Eine Buchung wäre eine behauptete Zustellung, und die Meldung
     * wäre für immer fort.
     */
    public function usable(): bool
    {
        return $this->target->describe() !== null;
    }

    public function carries(Finding $finding): bool
    {
        return true;
    }

    /** @param  list<Finding>  $findings */
    public function deliver(string $subject, array $findings): Delivery
    {
        try {
            $this->target->send([
                'kind' => 'findings',
                'subject' => $subject,
                'findings' => self::lines($findings),
            ]);
        } catch (AgentException) {
            return Delivery::Failed;
        }

        return Delivery::Sent;
    }

    /**
     * Eine Probezustellung — für den Knopf auf der Einstellungsseite.
     *
     * **Sie geht denselben Weg wie eine echte Meldung**, durch dieselbe
     * Operation und dieselbe Signatur. Ein eigener Weg zum Ausprobieren wäre
     * die zweite Fassung des Kanals, und sie bliebe grün, während der echte
     * nicht durchkommt.
     *
     * > **Eine Gegenprobe über einen anderen Weg als den benutzten prüft den
     * > falschen Weg.**
     */
    public function probe(): Delivery
    {
        try {
            $this->target->send(['kind' => 'test', 'subject' => '', 'findings' => []]);
        } catch (AgentException) {
            return Delivery::Failed;
        }

        return Delivery::Sent;
    }

    /**
     * Was von einem Befund hinausgeht.
     *
     * **Kennung, Urteil und Wortlaut — und `since` statt einer Dauer.** Ein
     * Empfänger, der die Meldung morgen noch einmal liest, rechnet die Dauer
     * aus dem Zeitpunkt aus; eine mitgeschickte Dauer wäre ab dem nächsten
     * Augenblick falsch. Derselbe Fehler wie die Spalte `next_due`, die ein
     * Jahr lang eine falsche Uhrzeit getragen hat.
     *
     * > **Ein Wert, der aus „jetzt" folgt und abgelegt wird, ist ab dem
     * > nächsten Augenblick falsch.**
     *
     * @param  list<Finding>  $findings
     * @return list<array{check: string, reason: string, state: string, label: string, detail: string|null, since: string}>
     */
    private static function lines(array $findings): array
    {
        return array_map(static fn (Finding $f): array => [
            'check' => $f->check->value,
            'reason' => $f->reason,
            'state' => $f->state()->value,
            'label' => $f->sentence(),
            'detail' => $f->detail,
            'since' => $f->first_seen_at->toAtomString(),
        ], $findings);
    }
}
