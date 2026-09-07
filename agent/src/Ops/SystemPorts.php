<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\FilterState;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\PortState;
use SrvPanel\Agent\Result;

/**
 * Welche Ports lauschen und welches Regelwerk läuft (A3 erster Wurf, `docs/109 §3.1`).
 *
 * Lesend, ohne ein einziges Argument von aussen — dieselbe Bauart wie
 * `system.time` und `system.diagnose`. Sie **schreibt nichts**; jede Änderung
 * am Regelwerk ist der zweite Wurf und steht in P9b.
 *
 * ## Was diese Antwort ausdrücklich **nicht** sagt
 *
 * Ob ein Port von aussen erreichbar ist. Gemessen (`docs/81 §2.3s` M20) ist der
 * Blick von innen Feld für Feld derselbe, ob eine Sperre davorsteht oder nicht:
 * einmal `LISTEN 0 5 0.0.0.0:19100` mit leerem Regelwerk und erreichbar, einmal
 * dieselben Zeichen und nicht erreichbar.
 *
 * > **Ein Port, der lauscht, und ein Regelwerk, das nichts verbietet, sagen
 * > über die Erreichbarkeit von aussen nichts — und sie sagen es in beiden
 * > Fällen mit denselben Zeichen.**
 *
 * Auf einem gemieteten Server steht regelmässig eine Cloud-Firewall davor, die
 * diese Maschine nicht sieht. Kein Feld dieser Antwort heisst deshalb `open`
 * oder `reachable`; was hier steht, heisst `listeners` und `filter`.
 *
 * ## Warum `privileged` mitfährt
 *
 * Ohne root gibt `ss -ltnp` dieselben Zeilen, `rc=0` und eine wortlos leere
 * Prozessspalte (M4). Der Agent läuft als root und kommt daran — aber die
 * Antwort trägt trotzdem, unter welchen Rechten gefragt wurde, denn ein `null`
 * im Eigentümer bedeutet je nachdem „niemand sichtbar" oder „nicht
 * nachgesehen", und das sind zwei verschiedene Auskünfte.
 *
 * ## Warum vier Programme gefragt werden und nicht eines
 *
 * `nft` sieht drei der vier gemessenen Regelwerke und **iptables-legacy
 * nicht** (M10) — dort gibt es `rc=0` und nichts, also die Antwort für „keine
 * Regeln". Und wer das Regelwerk *verwaltet*, sagt `nft` nie: Eine
 * `table ip filter` sieht bei ufw und bei handgeschriebenen Regeln gleich aus
 * (M18/M19). Deshalb zwei Fragen an zwei Familien und zwei an die Verwalter.
 *
 * **Ein nicht installiertes Programm ist kein Fehlschlag.** `ufw` und
 * `firewalld` gibt es auf den meisten Servern nicht; der Runner wirft dafür
 * `NOT_FOUND`, und das wird hier zu einem `null` — das der Leser von „hat
 * geantwortet und nichts gefunden" unterscheidet.
 */
final class SystemPorts implements Op
{
    public static function name(): string
    {
        return 'system.ports';
    }

    public static function mutating(): bool
    {
        return false;
    }

    public function execute(array $args, Context $context): array
    {
        // `-H` lässt die Kopfzeile weg, und die ist der Grund: In ihr klebt
        // `Peer Address:PortProcess` ohne Leerzeichen (M1).
        $sockel = $context->runner->run('ss', ['-H', '-l', '-t', '-n', '-p'], 10);

        $zustand = PortState::read($sockel, posix_geteuid() === 0);

        if ($zustand['readable'] === false) {
            // Der Wortlaut bleibt im Protokoll des Agenten und geht nicht an
            // die Seite — ein Serverzustand als Meldung beim Leser schickt ihn
            // dorthin, wo nichts zu ändern ist (`docs/59`).
            $context->journal->write('ss nicht lesbar', [
                'code' => $sockel->code,
                'message' => $sockel->message(),
            ]);
        }

        $zustand['filter'] = FilterState::read(
            $this->maybe($context, 'nft', ['list', 'ruleset']),
            $this->maybe($context, 'iptables-legacy', ['-S']),
            $this->maybe($context, 'ufw', ['status']),
            $this->maybe($context, 'firewall-cmd', ['--state']),
        );

        return $zustand;
    }

    /**
     * Ein Programm fragen — oder `null`, wenn es dieses System nicht hat.
     *
     * **Gefangen wird ausschliesslich `NOT_FOUND`.** Ein Zeitablauf oder eine
     * abgewiesene Positivliste sind etwas anderes als „nicht installiert", und
     * sie hier mitzufangen hiesse, einen Fehler als Abwesenheit auszugeben —
     * derselbe Fehler, den `catch (Throwable) { return []; }` in P5b gemacht
     * hat: aus „nicht erreichbar" wurde „der Betreiber bietet es nicht an".
     *
     * @param  list<string>  $args
     */
    private function maybe(Context $context, string $program, array $args): ?Result
    {
        try {
            return $context->runner->run($program, $args, 10);
        } catch (AgentException $fehler) {
            if ($fehler->errorCode === AgentException::NOT_FOUND) {
                return null;
            }

            throw $fehler;
        }
    }
}
