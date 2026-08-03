<?php

declare(strict_types=1);

namespace SrvPanel\Agent;

use Socket;

/**
 * Die Identität des Aufrufers, vom Kernel bezeugt.
 *
 * **Warum nicht SO_PEERCRED, wie im Plan angekündigt.** PHPs Socket-Extension
 * kennt die Konstante nicht (geprüft mit 8.4). Was sie kennt, ist SO_PASSCRED
 * zusammen mit SCM_CREDENTIALS: Der Kernel legt pid, uid und gid des Senders
 * als Zusatzdaten an die erste Nachricht. Das ist dieselbe Auskunft aus
 * derselben Quelle — der Absender kann sie nicht fälschen, denn ausgefüllt
 * wird sie beim Senden vom Kernel und nicht vom Programm.
 *
 * Diese Prüfung ist die zweite Schranke. Die erste sind die Rechte am Socket
 * selbst (0660 root:srvpanel); sie hält alles ab, was nicht in der Gruppe ist.
 * Beide zusammen: Auch wenn jemand die Rechte am Socket verstellt, kommt er
 * nicht durch — und wenn diese Prüfung ausfiele, hielten die Rechte.
 */
final class Peer
{
    public function __construct(
        public readonly int $pid,
        public readonly int $uid,
        public readonly int $gid,
    ) {}

    /**
     * Liest die erste Nachricht samt Zusatzdaten.
     *
     * @return array{peer:self,daten:string}
     */
    public static function receive(Socket $connection, int $buffer = 65536): array
    {
        $message = [
            'buffer_size' => $buffer,
            'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_CREDENTIALS),
        ];

        $received = @socket_recvmsg($connection, $message, 0);

        if ($received === false) {
            throw AgentException::badRequest('Verbindung lieferte keine Daten.');
        }

        $peer = null;

        foreach ($message['control'] ?? [] as $ancillary) {
            if (($ancillary['level'] ?? null) === SOL_SOCKET && ($ancillary['type'] ?? null) === SCM_CREDENTIALS) {
                $peer = new self(
                    (int) ($ancillary['data']['pid'] ?? 0),
                    (int) ($ancillary['data']['uid'] ?? -1),
                    (int) ($ancillary['data']['gid'] ?? -1),
                );
            }
        }

        if ($peer === null) {
            throw AgentException::denied('Der Kernel hat keine Identität des Aufrufers geliefert.');
        }

        return ['peer' => $peer, 'daten' => implode('', $message['iov'] ?? [])];
    }

    /**
     * Hat die Gegenseite die Verbindung geschlossen?
     *
     * **Das ist der Abbruch eines Vorgangs.** Das Panel bricht ab, indem es die
     * Verbindung schließt; hier wird es bemerkt, und der Agent beendet
     * daraufhin das laufende Programm. Ein zweiter Weg — eine Operation
     * `operation.cancel` etwa — wäre schlechter: Der Agent müsste sich merken,
     * welcher Auftrag zu welchem Vorgang gehört, und Zustand in einem Prozess
     * als root ist genau das, was die Form „eine Verbindung, ein Auftrag"
     * vermeidet.
     *
     * MSG_PEEK nimmt nichts aus dem Puffer, MSG_DONTWAIT wartet nicht. Ein
     * Rückgabewert von 0 heißt: geschlossen. Ein Fehler mit EAGAIN heißt:
     * nichts da, Verbindung steht — der Normalfall, solange ein Programm
     * läuft, denn nach der Anfrage schickt der Aufrufer nichts mehr.
     *
     * **Ein blinder Fleck, benannt statt verschwiegen:** Liegen noch ungelesene
     * Daten im Puffer, meldet MSG_PEEK diese und nicht das Ende — ein Abbruch
     * bliebe dann unbemerkt, bis sie gelesen sind. Im Protokoll kann das nicht
     * eintreten: Der Aufrufer schickt genau eine Zeile, und die hat der Agent
     * vollständig gelesen, bevor er ein Programm startet. Käme je ein zweiter
     * Schreibweg dazu, muss diese Methode mit. Der Fall steht in
     * tests/Unit/AgentCancelTest.php, damit er sichtbar bleibt.
     *
     * Statisch und hier statt im Steuerungscode der Verbindung, damit sich das
     * gegen ein Socketpaar prüfen lässt: Es ist der Punkt, an dem der Abbruch
     * hängt, und eine Annahme über den Kernel gehört belegt.
     */
    public static function gone(Socket $connection): bool
    {
        $peeked = '';
        $received = @socket_recv($connection, $peeked, 1, MSG_PEEK | MSG_DONTWAIT);

        if ($received === 0) {
            return true;
        }

        if ($received === false) {
            $error = socket_last_error($connection);
            socket_clear_error($connection);

            // Alles außer EAGAIN ist ein echter Fehler an dieser Verbindung —
            // und dann ist der Aufrufer für unsere Zwecke ebenfalls weg.
            return ! in_array($error, [SOCKET_EAGAIN, SOCKET_EWOULDBLOCK], true);
        }

        return false;
    }

    /**
     * Darf dieser Aufrufer den Agenten benutzen?
     *
     * Erlaubt sind genau zwei: der Benutzer der Anwendung und root. Root steht
     * auf der Liste, weil die Bereitschaftsprüfung nach einem Update und der
     * Rauchtest der CI von dort kommen — nicht, weil root eine Abkürzung
     * bräuchte.
     */
    public function mayCall(int $appUid): bool
    {
        return $this->uid === $appUid || $this->uid === 0;
    }

    /** @return array{pid:int,uid:int,gid:int} */
    public function toArray(): array
    {
        return ['pid' => $this->pid, 'uid' => $this->uid, 'gid' => $this->gid];
    }
}
