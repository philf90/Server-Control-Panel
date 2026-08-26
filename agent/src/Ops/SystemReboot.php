<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Ops;

use SrvPanel\Agent\AgentException;
use SrvPanel\Agent\Context;
use SrvPanel\Agent\Op;
use SrvPanel\Agent\Runner;

/**
 * Den Server neu starten — abgesetzt und nicht ausgeführt.
 *
 * ## Warum es diese Operation gibt
 *
 * `docs/81 §7`, Falle 8: **Ein `reboot-required`, das man anzeigt und nicht
 * anstossen kann, ist die halbe Auskunft.** Die Updates-Seite meldet seit
 * Schritt 5 „Ein Neustart steht aus", die Übersicht seit P7 „ein neuerer Kernel
 * ist installiert" — und beide schickten den Betreiber danach auf die
 * Kommandozeile. Mit A9 ist das keine Unbequemlichkeit mehr, sondern eine
 * Lücke: Ein Administrator hat kein SSH.
 *
 * ## Warum über `systemd-run` und nicht geradeheraus
 *
 * **Dies ist die eine Handlung, die das Panel selbst mitnimmt** — und zwar
 * vollständig: Agent, Warteschlange, Webserver, Datenbank. Ein
 * `systemctl reboot` in dieser Methode wäre ein Wettlauf zwischen der Antwort
 * des Agenten und dem SIGTERM, das systemd der eigenen Kontrollgruppe schickt.
 * Wer ihn verliert, hinterlässt einen Vorgang, der für immer auf `running`
 * steht, und ein Protokoll ohne die Zeile, die den Neustart erklärt.
 *
 * > **Ein Vorgang, dessen Antwort nie ankommt, ist von einem, der nie gelaufen
 * > ist, nicht zu unterscheiden.**
 *
 * Abgesetzt wird deshalb ein **Zeitgeber** als transiente Unit. `systemd-run`
 * antwortet sofort und endgültig: Es gibt die Unit an oder es gibt einen
 * Fehlschlag mit Grund. Der Agent hat damit eine echte Auskunft — und die
 * Minute danach reicht dem Vorgang, der Protokollzeile und der Seite.
 *
 * Dieselbe Bauart wie {@see PanelUpdate}, aus derselben Überlegung. **Und
 * dieselbe unbelegte Stelle:** Dass eine transiente Unit den Neustart von
 * `srvpanel-worker` überlebt, ist seit P1 behauptet und nur durch den eigenen
 * Gebrauch belegt. `docs/81` nennt das den einzigen Punkt, der A1 zum Scheitern
 * bringen kann; gemessen wird er in Schritt 6, nicht hier vermutet.
 *
 * ## Was hier ausdrücklich nicht steht
 *
 * **Kein `--when=`.** `systemctl --when=+1min reboot` täte dasselbe in einer
 * Zeile — den Schalter gibt es aber erst ab systemd 250, und Ubuntu 22.04
 * liefert 249 aus. Er ist auf einer der vier Zielplattformen nicht da, und
 * gemessen ist das nicht: Dieser Container trägt 255. Ein Schalter, der auf
 * drei von vier Plattformen funktioniert, ist schlimmer als keiner.
 *
 * **Kein `shutdown`.** `/sbin/shutdown` ist auf diesen Systemen ein Symlink auf
 * `systemctl` (gemessen, 26. August 2026) — ein Eintrag auf der Positivliste
 * wäre eine zweite Schreibweise für ein Programm, das dort schon steht.
 *
 * **Kein Abbruch über das Panel.** Der Weg zurück ist
 * `systemctl stop srvpanel-reboot.timer` auf der Kommandozeile; genau dafür ist
 * die Minute lang genug und der Unitname fest. Ein Knopf dafür setzte voraus,
 * dass das Panel den geplanten Neustart wieder auslesen kann — und wie
 * systemd einen anstehenden Zeitgeber meldet, ist in diesem Container nicht
 * messbar. `docs/81 §4` Punkt 7 verlangt ihn nicht; er bleibt benannt offen.
 */
final class SystemReboot implements Op
{
    /**
     * Der Name der transienten Unit — **fest und nicht zufällig.**
     *
     * Er tut drei Dinge, die ein zufälliger nicht täte. Er macht den geplanten
     * Neustart auf dem Server auffindbar (`systemctl list-timers
     * 'srvpanel-reboot*'`), er gibt dem Rückweg eine Zeile, die man aufschreiben
     * kann — und er lässt **systemd selbst** den zweiten Anlauf abweisen,
     * solange der erste steht. Das ist eine Ablehnung ohne zweiten Mechanismus:
     * Wer sie im Panel nachbaute, hätte eine Frage an einen Zustand, den nur
     * systemd kennt.
     *
     * **Nicht mit `SrvPanel\Agent\AptLock::UNIT_PREFIX` verwandt.** Der
     * lautet `srvpanel-update-`, und dessen Suche nach laufenden apt-Läufen
     * darf diesen Zeitgeber nicht mitzählen.
     */
    public const UNIT = 'srvpanel-reboot';

    /**
     * Wie lange zwischen dem Absetzen und dem Neustart liegt.
     *
     * **Die Minute ist keine geschätzte Wartezeit, sondern die Zusage.** Sie
     * ist lang genug, dass die Antwort des Agenten, der Lebenslauf des Vorgangs
     * und die Protokollzeile geschrieben sind, bevor die Maschine geht — und
     * lang genug, dass ein Fehlgriff sich noch stoppen lässt.
     *
     * Und sie ist die Form, die jeder Administrator kennt: `shutdown -r +1`.
     */
    public const DELAY_SECONDS = 60;

    public static function name(): string
    {
        return 'system.reboot';
    }

    public static function mutating(): bool
    {
        return true;
    }

    public function execute(array $args, Context $context): array
    {
        $context->progress(10, 'Neustart wird abgesetzt');

        /*
         * **Der Pfad kommt aus der Positivliste und steht nicht hier.**
         *
         * `systemd-run` startet das Programm **ausserhalb** des Agenten, in
         * einer Unit mit der Umgebung von systemd — ein blosser Name würde dort
         * über einen fremden `PATH` aufgelöst. Gebraucht wird also der absolute
         * Pfad, und den gibt es in diesem Programm genau einmal.
         *
         * > **Zwei Listen, die dasselbe meinen, laufen auseinander — und keine
         * > von beiden ist der Ort, an dem man nachsieht.**
         */
        $result = $context->runner->run('systemd-run', [
            '--unit='.self::UNIT,
            '--collect',
            '--description=Neustart über das Panel',
            '--on-active='.self::DELAY_SECONDS,
            Runner::path('systemctl'),
            'reboot',
        ], 30);

        /*
         * **Gelesen wird der Rückgabewert und die Meldung dazu.** Ohne systemd
         * als PID 1 endet `systemd-run` mit 1 und schreibt „System has not been
         * booted with systemd as init system" auf **stderr** — nichts auf
         * stdout (gemessen, 26. August 2026). Ein Blick allein auf die Ausgabe
         * sähe hier eine leere Zeile und meldete Erfolg.
         *
         * > **Eine Null, die „nicht nachgesehen" bedeutet, sieht aus wie „nichts
         * > zu tun".**
         */
        if (! $result->successful()) {
            throw AgentException::execFailed(
                'Der Neustart ließ sich nicht absetzen: '.$result->message(),
            );
        }

        $context->progress(100, 'abgesetzt');

        return [
            'unit' => self::UNIT.'.timer',
            'delay' => self::DELAY_SECONDS,

            /*
             * **Der Zeitpunkt wird hier gerechnet und nicht von systemd
             * erfragt.** Ein zweiter Aufruf, um zu erfahren, was man gerade
             * selbst bestellt hat, wäre eine Antwort auf eine Frage, die schon
             * beantwortet ist — und er käme aus einer Ausgabe, die niemand
             * gemessen hat.
             */
            'at' => time() + self::DELAY_SECONDS,
            'cancel' => 'systemctl stop '.self::UNIT.'.timer',
        ];
    }
}
