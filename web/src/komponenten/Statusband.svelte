<script lang="ts">
  // Grundsatz I aus docs/15-neuordnung.md: Der Zustand geht nie weg. Das Band
  // steht auf jeder Seite, und jede Zahl darin ist ein Griff — kein Text.
  import { live } from "../lib/live.svelte";
  import { hauptSchnittstelle, prozentText, rateText } from "../lib/formate";
  import { t } from "../lib/texte";

  let { name = "", uptime = "" }: { name?: string; uptime?: string } = $props();

  const snapshot = $derived(live.snapshot);

  const cpu = $derived(snapshot ? prozentText(snapshot.cpu.total) : "—");
  const mem = $derived(snapshot ? prozentText(snapshot.memory.used_pct) : "—");
  const netz = $derived.by(() => {
    if (!snapshot) return "—";
    const ifc = hauptSchnittstelle(snapshot.interfaces);
    return ifc ? rateText(ifc.rx_rate + ifc.tx_rate) : "—";
  });

  // Die Laufzeit kommt aus dem Live-Kanal, sobald er trägt — sonst aus der
  // Übersicht beim Seitenaufbau. So steht sie auch in der ersten halben Minute
  // da, in der der Ringpuffer noch leer ist.
  const laufzeit = $derived(snapshot?.uptime || uptime);
</script>

<div class="statusband">
  <span class="marke-band"><i aria-hidden="true"></i>{t.marke}</span>

  <span class="wirt">
    <b>{name || "—"}</b>
    {#if laufzeit}<span class="mut">· {t.uebersicht.seit} {laufzeit}</span>{/if}
  </span>

  <span class="messwerte zahl">
    <a href="/v2/">CPU <b>{cpu}</b></a>
    <a href="/v2/">RAM <b>{mem}</b></a>
    <a href="/v2/">NETZ <b>{netz}</b></a>
  </span>

  <span
    class="live"
    class:an={live.verbunden}
    title={live.verbunden ? t.live.verbunden : t.live.getrennt}
  >
    <i aria-hidden="true"></i>
    <span class="nur-vorlese">
      {live.verbunden ? t.live.verbunden : t.live.getrennt}
    </span>
  </span>
</div>

<style>
  .statusband {
    display: flex;
    align-items: center;
    gap: 1.1rem;
    padding: 0.5rem 1rem;
    background: var(--surface);
    border-bottom: 1px solid var(--line);
    font: 0.74rem var(--mono);
    white-space: nowrap;
    overflow: hidden;
  }

  .marke-band {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font: 650 0.86rem var(--sans);
    letter-spacing: 0.02em;
    flex: none;
  }

  .marke-band i {
    width: 10px;
    height: 10px;
    border-radius: 3px;
    background: var(--accent);
    box-shadow: 0 0 10px rgba(232, 163, 61, 0.7);
  }

  .wirt {
    color: var(--tx-mut);
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .wirt b {
    color: var(--tx);
    font-weight: 600;
  }

  .mut {
    color: var(--tx-mut);
  }

  /* Schmal darf das Band seitlich schiebbar sein — die Werte wegzulassen wäre
   * das Gegenteil von Grundsatz I. */
  .messwerte {
    display: flex;
    gap: 1rem;
    margin-left: auto;
    overflow-x: auto;
    scrollbar-width: none;
  }

  .messwerte a {
    color: var(--tx-mut);
    text-decoration: none;
  }

  .messwerte b {
    color: var(--tx);
    font-weight: 600;
  }

  .live {
    flex: none;
  }

  .live i {
    display: block;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: var(--tx-faint);
  }

  .live.an i {
    background: var(--ok);
    box-shadow: 0 0 8px rgba(76, 195, 138, 0.8);
  }

  .nur-vorlese {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }
</style>
