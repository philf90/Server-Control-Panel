<?php

declare(strict_types=1);

namespace CloudSrv\Agent;

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
 * selbst (0660 root:cloudsrv); sie hält alles ab, was nicht in der Gruppe ist.
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
    public static function empfange(Socket $verbindung, int $puffer = 65536): array
    {
        $nachricht = [
            'buffer_size' => $puffer,
            'controllen' => socket_cmsg_space(SOL_SOCKET, SCM_CREDENTIALS),
        ];

        $gelesen = @socket_recvmsg($verbindung, $nachricht, 0);

        if ($gelesen === false) {
            throw AgentException::badRequest('Verbindung lieferte keine Daten.');
        }

        $peer = null;

        foreach ($nachricht['control'] ?? [] as $zusatz) {
            if (($zusatz['level'] ?? null) === SOL_SOCKET && ($zusatz['type'] ?? null) === SCM_CREDENTIALS) {
                $peer = new self(
                    (int) ($zusatz['data']['pid'] ?? 0),
                    (int) ($zusatz['data']['uid'] ?? -1),
                    (int) ($zusatz['data']['gid'] ?? -1),
                );
            }
        }

        if ($peer === null) {
            throw AgentException::denied('Der Kernel hat keine Identität des Aufrufers geliefert.');
        }

        return ['peer' => $peer, 'daten' => implode('', $nachricht['iov'] ?? [])];
    }

    /**
     * Darf dieser Aufrufer den Agenten benutzen?
     *
     * Erlaubt sind genau zwei: der Benutzer der Anwendung und root. Root steht
     * auf der Liste, weil die Bereitschaftsprüfung nach einem Update und der
     * Rauchtest der CI von dort kommen — nicht, weil root eine Abkürzung
     * bräuchte.
     */
    public function darf(int $anwendungsUid): bool
    {
        return $this->uid === $anwendungsUid || $this->uid === 0;
    }

    /** @return array{pid:int,uid:int,gid:int} */
    public function toArray(): array
    {
        return ['pid' => $this->pid, 'uid' => $this->uid, 'gid' => $this->gid];
    }
}
