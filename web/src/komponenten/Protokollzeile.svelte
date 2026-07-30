<script lang="ts">
  // Grundsatz IV: Das Panel verschweigt nichts. Die Zeile zeigt den zuletzt
  // ausgeführten Systembefehl im Klartext, mit Rückgabewert und Dauer — dieselbe
  // Quelle wie die Konsole der alten Oberfläche (internal/privops/journal.go).
  //
  // Aufklappen zur Vollansicht kommt mit dem Job-Modell; solange es nur den
  // letzten Befehl gibt, wäre eine Schublade mit einem Eintrag darin eine
  // Schublade zu viel.
  import type { Befehl } from "../lib/typen";
  import { t } from "../lib/texte";

  let { befehl = null }: { befehl?: Befehl | null } = $props();
</script>

<div class="protokollzeile">
  <span class="pfeil" aria-hidden="true">›</span>
  {#if befehl}
    <span class="zeile">{befehl.zeile}</span>
    <span class="ausgang zahl" class:schlecht={befehl.gescheitert}>
      {t.protokoll.exit} {befehl.exit}
    </span>
    <span class="dauer zahl">{befehl.dauer_text}</span>
  {:else}
    <span class="leer">{t.protokoll.leer}</span>
  {/if}
</div>

<style>
  .protokollzeile {
    display: flex;
    align-items: center;
    gap: 0.9rem;
    padding: 0.42rem 1rem;
    background: var(--surface);
    border-top: 1px solid var(--line);
    font: 0.73rem var(--mono);
    color: var(--tx-mut);
    white-space: nowrap;
    overflow: hidden;
  }

  .pfeil {
    flex: none;
  }

  .zeile {
    color: var(--tx);
    overflow: hidden;
    text-overflow: ellipsis;
  }

  .ausgang {
    color: var(--ok);
    flex: none;
  }

  .ausgang.schlecht {
    color: var(--err);
  }

  .dauer,
  .leer {
    flex: none;
  }
</style>
