<script lang="ts">
  // Grundsatz II: Jede Zahl ist ein Griff. Jeder Punkt trägt den Weg dorthin,
  // wo man ihn behebt — von der Übersicht selbst wird nichts geändert, deshalb
  // sind die Aktionen bloße Verweise und die Seite braucht keinen Schreibpfad.
  //
  // Die Liste erscheint nur, wenn es etwas zu tun gibt. Ein leerer Kasten mit
  // „keine offenen Punkte" wäre eine zweite Aussage neben der Urteilszeile, die
  // dasselbe schon sagt.
  import type { Signal } from "../lib/typen";
  import { t } from "../lib/texte";
  import { verweis } from "../lib/weg.svelte";

  let { signale = [] }: { signale?: Signal[] } = $props();
</script>

{#if signale.length > 0}
  <ul class="handlungsbedarf" aria-label={t.uebersicht.handlungsbedarf}>
    {#each signale as sig, i (sig.tag + sig.titel + i)}
      <li>
        <span class="zustand" class:schlecht={sig.level === "crit"} class:warn={sig.level === "warn"}>
          <i aria-hidden="true"></i>
          <span class="nur-vorlese">{sig.level === "crit" ? t.stufe.kritisch : t.stufe.warnung}</span>
        </span>
        <span class="text">
          <b>{sig.titel}</b>
          {#if sig.detail}<span class="detail">{sig.detail}</span>{/if}
        </span>
        {#if sig.aktion_href}
          <!-- Der Verweis wird nur abgefangen, wenn er in die neue Oberfläche
               führt. Signale zu Modulen, die dort noch fehlen, zeigen weiter
               nach / und laden ganz normal — siehe umzug in api_v1.go. -->
          <a class="griff" href={sig.aktion_href} onclick={(e) => verweis(e, sig.aktion_href)}>
            {sig.aktion_label} →
          </a>
        {/if}
      </li>
    {/each}
  </ul>
{/if}

<style>
  .handlungsbedarf {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: var(--r);
    margin-bottom: 1.1rem;
    list-style: none;
  }

  li {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    padding: 0.55rem 0.9rem;
    border-bottom: 1px solid var(--line);
    font-size: 0.85rem;
  }

  li:last-child {
    border-bottom: none;
  }

  .text {
    display: flex;
    flex-wrap: wrap;
    gap: 0.15rem 0.5rem;
    min-width: 0;
  }

  .text b {
    font-weight: 600;
  }

  .detail {
    color: var(--tx-mut);
  }

  .griff {
    margin-left: auto;
    color: var(--accent);
    text-decoration: none;
    font-size: 0.78rem;
    white-space: nowrap;
    flex: none;
  }

  .griff:hover {
    text-decoration: underline;
  }

  .nur-vorlese {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
  }

  @media (max-width: 600px) {
    li {
      flex-wrap: wrap;
    }

    .griff {
      margin-left: 0;
      width: 100%;
    }
  }
</style>
