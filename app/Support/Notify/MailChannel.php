<?php

declare(strict_types=1);

namespace App\Support\Notify;

use App\Enums\AccountStatus;
use App\Enums\FindingCheck;
use App\Mail\DiagnoseReport;
use App\Mail\QuotaWarning;
use App\Models\Account;
use App\Models\Finding;
use App\Models\Subscription;
use App\Support\Plans\Quota;
use App\Support\Settings\Settings;
use App\Support\Tenancy\Tenancy;
use App\Support\Time\Clock;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Der Weg über das Relay — an den Kunden **und** an den Betreiber.
 *
 * ## Wer gemeint ist, folgt aus dem Befund
 *
 * `quota.exceeded` ist die eine Prüfung, deren Gegenstand einem Kunden gehört:
 * Sie misst **sein** Abonnement, und der Satz dazu steht seit P1 in
 * {@see Quota::TrafficGb} — *„Die Überschreitung erscheint
 * in der Übersicht."* Die übrigen siebzehn messen den Server, und der gehört
 * dem Betreiber.
 *
 * > **Ein Kanal, der alles an alle trägt, hat keinen Empfänger, sondern eine
 * > Verteilerliste.**
 *
 * Bis zum 24. September 2026 trug dieser Kanal **nur** die
 * Kontingentbefunde — B5 hatte keinen zweiten Empfänger, und die übrigen
 * Prüfungen hatten keinen Weg nach draussen. Mit B1 haben sie zwei.
 *
 * ## Die Adresse des Betreibers wird nicht erfunden
 *
 * Sie ist die seines Kontos. Eine eigene Einstellung „Meldeadresse" wäre eine
 * zweite Wahrheit neben `accounts.email` — und die zweite ist die, die
 * veraltet, wenn jemand sein Konto umträgt.
 *
 * > **Eine Angabe, die es schon gibt, bekommt keine zweite Stelle, nur weil
 * > ein neues Merkmal sie braucht.**
 *
 * ## Gesperrte Konten bekommen nichts
 *
 * Ein Konto, das sich nicht anmelden darf, bekommt auch keine Auskunft über
 * den Server — und das gilt für beide Empfänger, nicht nur für den einen.
 * {@see self::addressesOf()} ist die eine Stelle, an der das steht.
 */
final class MailChannel implements Channel
{
    /**
     * Der Schlüssel dieses Kanals — als Konstante und nicht nur als Rückgabe.
     *
     * Wer ihn braucht, hat nicht immer eine Instanz: Die Einstellungsseite des
     * Mailversands fragt „zuletzt erfolgreich zugestellt" für genau diesen
     * Kanal. Ein zweites Mal hingeschriebenes `'mail'` wäre der Verweis ohne
     * Prüfung, an dem dieses Projekt am häufigsten verloren hat.
     */
    public const CHANNEL = 'mail';

    /**
     * Der Bündelschlüssel für alles, was dem Betreiber gehört.
     *
     * **Ein Schlüssel und kein leerer Wert.** Ein `''` oder `null` an dieser
     * Stelle trüge zwei Bedeutungen — „gehört dem Betreiber" und „hat keinen
     * Gegenstand" —, und `docs/901` hat gemessen, was das kostet: Eine Null,
     * die schon eine Bedeutung trägt, kann keine zweite bekommen.
     */
    private const OPERATOR = 'operator';

    /** Und das Gegenstück, damit kein Abonnementname zufällig `operator` heisst. */
    private const SUBSCRIPTION = 'subscription:';

    public function __construct(
        private readonly Tenancy $tenancy,
        private readonly Settings $settings,
    ) {}

    public function key(): string
    {
        return self::CHANNEL;
    }

    /**
     * Ohne eingetragenes Relay wird nichts gemeldet und nichts vermerkt.
     *
     * Eine Buchung zu schreiben hiesse, eine Zustellung zu behaupten, die es
     * nie gab — und die Meldung wäre für immer fort, weil dieselbe Zeile über
     * diesen Kanal nie wieder fällig wird.
     */
    public function usable(): bool
    {
        return $this->settings->mail()->usable();
    }

    public function batchKey(Finding $finding): string
    {
        return $finding->check === FindingCheck::QuotaExceeded
            ? self::SUBSCRIPTION.$finding->subject
            : self::OPERATOR;
    }

    /** @param  non-empty-list<Finding>  $findings */
    public function deliver(array $findings): Delivery
    {
        $erster = $findings[0];

        return $erster->check === FindingCheck::QuotaExceeded
            ? $this->toCustomer($erster->subject, $findings)
            : $this->toOperator($findings);
    }

    /**
     * An den Kunden: eine Nachricht je Abonnement.
     *
     * @param  non-empty-list<Finding>  $findings
     */
    private function toCustomer(string $subscription, array $findings): Delivery
    {
        $empfaenger = $this->customerAddresses($subscription);

        if ($empfaenger === []) {
            return Delivery::WithoutRecipient;
        }

        return $this->send(new QuotaWarning($subscription, self::overruns($findings)), $empfaenger);
    }

    /**
     * An den Betreiber: **eine** Nachricht über alles, was die Nacht gefunden
     * hat.
     *
     * @param  non-empty-list<Finding>  $findings
     */
    private function toOperator(array $findings): Delivery
    {
        $empfaenger = $this->operatorAddresses();

        if ($empfaenger === []) {
            /*
             * **Kein Fehlschlag.** Ein Server, dessen Betreiberkonto keine
             * Adresse trägt, ist nicht kaputt — und die Befunde bleiben
             * fällig, bis eine da ist. Ein `failed` färbte die Unit jede Nacht
             * rot für etwas, das auf der Kontenseite behoben wird.
             */
            return Delivery::WithoutRecipient;
        }

        return $this->send(new DiagnoseReport(self::lines($findings)), $empfaenger);
    }

    /** @param  list<string>  $empfaenger */
    private function send(QuotaWarning|DiagnoseReport $nachricht, array $empfaenger): Delivery
    {
        try {
            Mail::to($empfaenger)->send($nachricht);
        } catch (Throwable) {
            // Keine Buchung: Was nicht ankam, bleibt fällig. Der nächste Lauf
            // versucht es wieder, und bis dahin steht „zuletzt erfolgreich
            // zugestellt" unverändert da.
            return Delivery::Failed;
        }

        return Delivery::Sent;
    }

    /**
     * Die Zeilen einer Kundennachricht — Beschriftung und gemessener Wert.
     *
     * Die Beschriftung kommt aus {@see FindingCheck::sentence()} und nicht aus
     * einer zweiten Liste hier: Was der Betreiber auf der Diagnoseseite liest,
     * liest der Kunde in seiner Mail.
     *
     * @param  list<Finding>  $findings
     * @return list<array{label: string, detail: string}>
     */
    private static function overruns(array $findings): array
    {
        return array_map(static fn (Finding $f): array => [
            'label' => $f->check->sentence($f->reason),
            'detail' => (string) ($f->detail ?? '—'),
        ], $findings);
    }

    /**
     * Die Zeilen der Betreibernachricht.
     *
     * **Sie trägt den Gegenstand mit**, weil sie im Gegensatz zur Kundenmail
     * über mehrere Prüfungen und mehrere Orte zugleich spricht: „Dienst" ohne
     * den Namen der Unit ist kein Befund, sondern eine Stimmung.
     *
     * > **Ein Protokoll, das die Art der Handlung nennt und nicht ihren
     * > Gegenstand, beantwortet die Frage, die niemand stellt.**
     *
     * **Und `seit` statt einer Dauer.** Ein Zeitpunkt bleibt wahr, wenn die
     * Mail einen Tag später gelesen wird; „seit 14 Stunden" ist ab dem
     * nächsten Augenblick falsch.
     *
     * @param  non-empty-list<Finding>  $findings
     * @return non-empty-list<array{label: string, subject: string, detail: string, since: string}>
     */
    private static function lines(array $findings): array
    {
        return array_map(static fn (Finding $f): array => [
            'label' => $f->check->label().': '.$f->check->sentence($f->reason),
            'subject' => $f->check->subjectLabel().' '.$f->subject,
            'detail' => (string) ($f->detail ?? '—'),
            'since' => (string) Clock::display($f->first_seen_at),
        ], $findings);
    }

    /**
     * An wen die Kundennachricht geht.
     *
     * **An die Konten des Kunden und nicht an eine Adresse am Abonnement** —
     * eine solche gibt es nicht, und sie zu erfinden hiesse, eine zweite
     * Wahrheit neben `accounts.email` zu pflegen.
     *
     * @return list<string>
     */
    private function customerAddresses(string $subscription): array
    {
        return $this->withoutClamp(static function () use ($subscription): array {
            $abo = Subscription::query()->where('name', $subscription)->first();

            return self::addressesOf($abo?->customer?->accounts());
        });
    }

    /**
     * An wen die Betreibernachricht geht.
     *
     * **An die Betreiber und nicht an jeden Administrator.** Der Unterschied
     * ist seit A9 gebaut: Ein Administrator sieht den Server über
     * `inspect-server`, gedreht wird über `operate-server`. Eine Meldung über
     * einen toten Dienst ist eine Aufforderung zu handeln, und handeln darf
     * hier der Betreiber.
     *
     * @return list<string>
     */
    private function operatorAddresses(): array
    {
        return $this->withoutClamp(static fn (): array => self::addressesOf(Account::operators()));
    }

    /**
     * Die brauchbaren Adressen einer Kontenabfrage.
     *
     * **Eine Stelle für beide Empfänger.** Die Bedingung ist dieselbe — ein
     * Konto, das sich nicht anmelden darf, bekommt auch keine Auskunft über
     * den Server —, und sie zweimal hinzuschreiben hiesse, sie beim nächsten
     * Mal an einer der beiden Stellen zu vergessen.
     *
     * @param  Builder<Account>|Relation<Account, *, *>|null  $konten
     * @return list<string>
     */
    private static function addressesOf(mixed $konten): array
    {
        if ($konten === null) {
            return [];
        }

        /** @var list<string> $adressen */
        $adressen = $konten
            ->where('status', AccountStatus::Active->value)
            ->whereNotNull('email')
            ->orderBy('id')
            ->pluck('email')
            ->map(static fn (mixed $mail): string => (string) $mail)
            ->all();

        return array_values(array_filter($adressen, static fn (string $mail): bool => $mail !== ''));
    }

    /**
     * Die Mandantenklammer für diese eine Frage lösen.
     *
     * Dieser Lauf hat kein angemeldetes Konto; im Grundzustand käme eine leere
     * Liste zurück, und das sähe aus wie „dieser Kunde hat kein Konto".
     *
     * > **Eine Frage, die im Grundzustand alles verweigert, antwortet mit
     * > einer leeren Liste und nicht mit einem Fehler.**
     *
     * @param  callable(): list<string>  $frage
     * @return list<string>
     */
    private function withoutClamp(callable $frage): array
    {
        /** @var list<string> $adressen */
        $adressen = [];

        $this->tenancy->withoutRestriction(static function () use ($frage, &$adressen): void {
            $adressen = $frage();
        });

        return $adressen;
    }
}
