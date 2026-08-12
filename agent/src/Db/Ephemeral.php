<?php

declare(strict_types=1);

namespace SrvPanel\Agent\Db;

use SrvPanel\Agent\Context;
use Throwable;

/**
 * Ein Datenbankbenutzer für die Dauer eines Laufs.
 *
 * **Warum es ihn gibt: Das Zurückspielen darf nicht als Datenbank-`root`
 * laufen.** Ein Dump ist beliebiges SQL, und der Kunde lädt ihn hoch. Als
 * `root` über den Socket eingespielt, wäre
 *
 *     GRANT ALL PRIVILEGES ON *.* TO 'p1001_web'@'localhost';
 *
 * in einer Zeile des Dumps genau der Ausbruch, den das Abnahmekriterium von P5
 * ausschliesst — und er stünde nicht einmal in einem Angriff, sondern in einem
 * Dump, den jemand von einem anderen Server mitgebracht hat.
 *
 * Es läuft deshalb als Benutzer mit Rechten auf **genau die eine
 * Zieldatenbank**. Weil das Passwort des Kundenbenutzers nirgends liegt
 * (`docs/36 §4`, Entscheidung 3 des Betreibers), lässt es sich nicht benutzen —
 * also entsteht für die Dauer des Vorgangs ein eigener.
 *
 * Dass ein Dump `CREATE DATABASE` oder `USE andere_datenbank` enthält, ist
 * damit kein Sonderfall mehr, den jemand abfangen muss: Er scheitert an den
 * Rechten, laut und mit der Meldung des Systems.
 *
 * **`finally` und nicht am Ende des Erfolgspfads.** Ein abgebrochener Lauf, der
 * einen Benutzer stehenlässt, ist ein Zugang ohne Besitzer. Und weil auch ein
 * `finally` einen Stromausfall nicht überlebt, sucht `db.server.info` beim
 * nächsten Lauf nach Namen dieser Form, die älter als eine Stunde sind, und
 * **meldet** sie — entfernt werden sie über `db.user.remove` aus dem
 * Aufräumlauf, nicht nebenbei.
 */
final class Ephemeral
{
    public function __construct(private readonly Session $session = new Session) {}

    /**
     * Legt den Benutzer an, führt aus, räumt auf.
     *
     * @template T
     *
     * @param  callable(Credentials): T  $work
     * @return T
     */
    public function with(
        Context $context,
        string $systemUser,
        string $database,
        callable $work,
        string $kind = Names::KIND_RESTORE,
    ): mixed {
        $account = Names::ephemeral($systemUser, $kind);
        $password = self::password();

        $this->session->execute($context, [
            sprintf(
                'CREATE USER %s IDENTIFIED BY %s',
                Sql::account($account, 'localhost'),
                Sql::text($password),
            ),

            // Genau eine Datenbank, Unterstrich maskiert — dieselbe Regel wie
            // in `DbUserCreate`, und aus demselben Grund (`docs/36 §3.1`). Ein
            // befristeter Benutzer ist kein Anlass, sie zu lockern: Er ist der
            // Benutzer, unter dem gleich fremdes SQL läuft.
            sprintf(
                'GRANT ALL PRIVILEGES ON %s TO %s',
                Sql::grantTarget($database),
                Sql::account($account, 'localhost'),
            ),
        ]);

        try {
            return $work(new Credentials($account, $password));
        } finally {
            /*
             * **Ohne Abbruch, wenn das Aufräumen scheitert.** Was hier zählt,
             * ist das Ergebnis des Laufs — eine Ausnahme aus dem `finally`
             * verschluckte die des Laufs, und dann stünde im Vorgang „Benutzer
             * liess sich nicht entfernen" statt „der Dump hat an Zeile 40312
             * abgebrochen". Der stehengebliebene Zugang fällt
             * `db.server.info` auf.
             */
            try {
                $this->session->execute($context, [
                    'DROP USER IF EXISTS '.Sql::account($account, 'localhost'),
                ]);
            } catch (Throwable $error) {
                $context->journal->write('befristeter benutzer blieb stehen', [
                    'user' => $account,
                    'error' => $error->getMessage(),
                ]);
            }
        }
    }

    /**
     * Ein Passwort für ein paar Minuten.
     *
     * Dasselbe Alphabet wie überall in diesem Projekt: Es steht gleich in einer
     * SQL-Anweisung und in einer Optionsdatei, und Zeichen, die in einer der
     * beiden Bedeutung haben, sind hier kein Gewinn an Stärke, sondern eine
     * Fehlerquelle. {@see Credentials} weist alles andere ohnehin ab.
     */
    private static function password(): string
    {
        return substr(strtr(base64_encode(random_bytes(24)), '+/=', 'xyz'), 0, 32);
    }
}
