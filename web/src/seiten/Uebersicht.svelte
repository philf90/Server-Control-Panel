<script lang="ts">
  // Die Übersicht vollständig: Urteil, Handlungsbedarf, vier Telemetrie-Kacheln,
  // Dateisysteme, Prozesse.
  //
  // Die Reihenfolge ist Grundsatz V — erst das Urteil, dann die Zahlen. Der
  // Handlungsbedarf kommt aus einem eigenen Aufruf, weil seine Erhebung
  // systemctl anfasst; die Kacheln stehen schon da, während er noch läuft.
  import StatCard from "../komponenten/StatCard.svelte";
  import Urteil from "../komponenten/Urteil.svelte";
  import Handlungsbedarf from "../komponenten/Handlungsbedarf.svelte";
  import Dateisysteme from "../komponenten/Dateisysteme.svelte";
  import Prozesse from "../komponenten/Prozesse.svelte";
  import { live } from "../lib/live.svelte";
  import { byteText, hauptSchnittstelle, rateText } from "../lib/formate";
  import { t } from "../lib/texte";
  import type { Signale, Uebersicht, Verlaeufe, Wert } from "../lib/typen";

  let {
    uebersicht,
    verlaeufe,
    signale,
    signalFehler = false,
    erneutErheben,
  }: {
    uebersicht: Uebersicht;
    verlaeufe: Verlaeufe | null;
    signale: Signale | null;
    signalFehler?: boolean;
    erneutErheben?: () => void;
  } = $props();

  // Beim Seitenaufbau gelten die Werte, die der Server fertig formatiert
  // geliefert hat; sobald der Live-Kanal trägt, schreibt er sie fort. So steht
  // in der ersten halben Minute nach einem Neustart nicht „keine Daten" —
  // genau dieser Fehler betraf früher jede frische Installation.
  const snap = $derived(live.snapshot);

  // Die Tabellen brauchen die ganze Messung — und zwar je Liste einzeln
  // entschieden, nicht je Messung.
  //
  // Der Grund steckt im Live-Kanal: Sein erstes Ereignis ist der letzte
  // Ringpuffer-Eintrag, und der Ring hält den Verlauf, nicht zwingend jede
  // Liste. Wer den Live-Stand bedingungslos bevorzugt, tauscht eine
  // vollständige Messung gegen eine dünnere und zeigt „keine Dateisysteme
  // gefunden", während der Server längst geantwortet hat. Ein Linux-Rechner hat
  // immer Dateisysteme und Prozesse; eine leere Liste heißt deshalb nicht
  // „keine", sondern „nicht in dieser Nachricht".
  function nimmVolle<T>(live: T[] | undefined, aufbau: T[] | undefined): T[] {
    if (live && live.length > 0) return live;
    return aufbau ?? [];
  }

  const dateisysteme = $derived(
    nimmVolle(live.snapshot?.filesystems, uebersicht.snapshot?.filesystems),
  );
  const prozesse = $derived(
    nimmVolle(live.snapshot?.top_processes, uebersicht.snapshot?.top_processes),
  );

  /** eineStelle rundet wie die große Zahl der Kachel es tut (kachelZahl in Go).
   *  Zwei Stellen gehören zu den Stützstellen des Verlaufs, nicht hierher. */
  function eineStelle(v: number): string {
    return v.toFixed(1);
  }

  /** teile zerlegt "2.0 KiB/s" wie durchsatzKachel auf der Go-Seite. */
  function teile(text: string): Wert {
    const i = text.lastIndexOf(" ");
    return i > 0
      ? { wert: text.slice(0, i), einheit: text.slice(i + 1) }
      : { wert: text, einheit: "" };
  }

  const cpu = $derived<Wert>(
    snap ? { wert: eineStelle(snap.cpu.total), einheit: "%" } : uebersicht.werte.cpu,
  );
  const mem = $derived<Wert>(
    snap ? { wert: eineStelle(snap.memory.used_pct), einheit: "%" } : uebersicht.werte.memory,
  );
  const load = $derived<Wert>(
    snap ? { wert: eineStelle(snap.load[0]), einheit: "" } : uebersicht.werte.load,
  );

  const ifc = $derived(snap ? hauptSchnittstelle(snap.interfaces) : undefined);
  const netz = $derived<Wert>(ifc ? teile(rateText(ifc.rx_rate)) : uebersicht.werte.netz);

  const cpuUnter = $derived(
    snap ? `iowait ${eineStelle(snap.cpu.iowait)} % · steal ${eineStelle(snap.cpu.steal)} %` : "",
  );
  const memUnter = $derived(
    snap ? `${byteText(snap.memory.used)} ${t.uebersicht.von} ${byteText(snap.memory.total)}` : "",
  );
  const lastUnter = $derived(
    snap
      ? `${uebersicht.host.cores} ${t.uebersicht.kerne} · 5 Min ${eineStelle(snap.load[1])} · 15 Min ${eineStelle(snap.load[2])}`
      : "",
  );
  const netzUnter = $derived(
    ifc
      ? `${ifc.name} · ${t.uebersicht.gesendet} ${rateText(ifc.tx_rate)}`
      : uebersicht.netz_name,
  );
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.system} / {t.ziele.uebersicht}</div>
    <h1 class="h1">{t.ziele.uebersicht}</h1>
  </div>
  <div class="schub"></div>
  <span class="marke">{uebersicht.host.distro} · {uebersicht.host.kernel}</span>
</div>

<p class="vorschau">{t.vorschau}</p>

<Urteil urteil={signale?.urteil ?? null} fehler={signalFehler} erneut={erneutErheben} />
<Handlungsbedarf signale={signale?.signale ?? []} />

{#if !snap && !uebersicht.snapshot}
  <p class="hinweis">{t.uebersicht.keineDaten}</p>
{:else}
  <div class="kacheln">
    <StatCard
      label={t.kacheln.cpu}
      wert={cpu.wert}
      einheit={cpu.einheit}
      unterzeile={cpuUnter}
      verlauf={verlaeufe?.cpu ?? null}
    />
    <StatCard
      label={t.kacheln.memory}
      wert={mem.wert}
      einheit={mem.einheit}
      unterzeile={memUnter}
      verlauf={verlaeufe?.memory ?? null}
    />
    <StatCard
      label={t.kacheln.load}
      wert={load.wert}
      einheit={load.einheit}
      unterzeile={lastUnter}
      verlauf={verlaeufe?.load ?? null}
    />
    <StatCard
      label={t.kacheln.netz}
      wert={netz.wert}
      einheit={netz.einheit}
      unterzeile={netzUnter}
      verlauf={verlaeufe?.netz ?? null}
    />
  </div>

  <div class="listen">
    <Dateisysteme {dateisysteme} />
    <Prozesse {prozesse} />
  </div>
{/if}

<style>
  .vorschau {
    background: rgba(232, 163, 61, 0.1);
    border: 1px solid var(--accent-dim);
    border-radius: var(--r);
    padding: 0.55rem 0.9rem;
    font-size: 0.85rem;
    color: var(--tx);
    margin-bottom: 1.1rem;
  }

  .kacheln {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 0.9rem;
  }

  /* Untereinander und nicht nebeneinander: Die Dateisystemtabelle trägt fünf
   * Spalten mit Pfaden und Größen. In einer halben Breite wurde die letzte
   * abgeschnitten — und ein Wert, den man nicht sieht, ist schlimmer als eine
   * längere Seite. Der Entwurf zeigte die Tabellen nebeneinander, hatte dort
   * aber je drei Spalten. */
  .listen {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: 1.1rem;
    align-items: start;
    margin-top: 1.1rem;
  }

  .hinweis {
    font-size: 0.85rem;
    color: var(--tx-mut);
  }

  /* minmax(0, 1fr) statt 1fr: Eine lange Zahl in einer Kachel zieht sonst ihre
   * Spur auf und die Kacheln werden verschieden breit — dieselbe Lektion wie
   * bei der alten Übersicht mit einer IPv6-Adresse. */
  @media (max-width: 900px) {
    .kacheln {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }

  }

  @media (max-width: 600px) {
    .kacheln {
      grid-template-columns: minmax(0, 1fr);
    }
  }
</style>
