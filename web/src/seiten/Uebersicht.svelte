<script lang="ts">
  // Die vier Telemetrie-Kacheln mit echten Daten. Urteilszeile, Handlungsbedarf,
  // Dateisysteme und Prozessliste kommen in der nächsten Stufe — sie brauchen
  // Signale, die Systemaufrufe auslösen, und die gehören hinter das Job-Modell.
  import StatCard from "../komponenten/StatCard.svelte";
  import { live } from "../lib/live.svelte";
  import { byteText, hauptSchnittstelle, rateText } from "../lib/formate";
  import { t } from "../lib/texte";
  import type { Uebersicht, Verlaeufe, Wert } from "../lib/typen";

  let {
    uebersicht,
    verlaeufe,
  }: { uebersicht: Uebersicht; verlaeufe: Verlaeufe | null } = $props();

  // Beim Seitenaufbau gelten die Werte, die der Server fertig formatiert
  // geliefert hat; sobald der Live-Kanal trägt, schreibt er sie fort. So steht
  // in der ersten halben Minute nach einem Neustart nicht „keine Daten" —
  // genau dieser Fehler betraf früher jede frische Installation.
  const snap = $derived(live.snapshot);

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
