<script lang="ts">
  // Die Portübersicht: alle veröffentlichten Ports quer über die Container,
  // abgeglichen mit der Firewall.
  //
  // Diese Fläche existiert wegen EINER Auskunft, und die ist unbequem: Ein
  // Container, der auf 0.0.0.0 veröffentlicht, ist aus dem Netz erreichbar —
  // auch wenn ufw läuft und den Port nicht kennt. Docker trägt seine
  // Weiterleitungen vor den Ketten der Firewall ein.
  //
  // Das Urteil rechnet der Server. Der Browser färbt danach und legt keine
  // eigene Auslegung daneben: „ufw ist an" und „der Port ist zu" sind zwei
  // verschiedene Aussagen, und wer sie hier zusammenrechnete, bekäme genau die
  // falsche.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import type { Portliste } from "../lib/typen";

  let daten = $state<Portliste | null>(null);
  let fehler = $state("");

  async function laden() {
    fehler = "";
    try {
      daten = await api.ports();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else if daten.zeilen.length === 0}
  <p class="detail">{t.docker.portsLeer}</p>
{:else}
  <div class="zaehler">
    <span>{t.docker.portsUnbemerkt} <b>{daten.unbemerkt}</b></span>
    <span>{t.docker.portsOffen} <b>{daten.offen}</b></span>
    <span>{t.docker.portsLokal} <b>{daten.lokal}</b></span>
  </div>

  <!-- Der erklärende Satz steht ÜBER der Tabelle und nur dann, wenn er
       zutrifft. Unter der Tabelle wäre er die Fußnote zu einem Befund, den
       niemand ohne ihn versteht. -->
  {#if daten.warnung}
    <p class="warnung">{daten.warnung}</p>
  {:else if !daten.firewall_aktiv}
    <p class="hinweis">{t.docker.portsOhneFirewall}</p>
  {/if}

  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.docker.spaltePort}</th>
          <th>{t.docker.spalteAdresse}</th>
          <th>{t.docker.spalteContainer}</th>
          <th>{t.docker.spalteUrteil}</th>
        </tr>
      </thead>
      <tbody>
        {#each daten.zeilen as p (p.wirt_port + "/" + p.protokoll + p.container)}
          <tr>
            <td data-spalte={t.docker.spaltePort}>
              <span class="mono">{p.wirt_port}/{p.protokoll}</span>
              <span class="leise">→ {p.container_port}</span>
              {#if p.panel_port}
                <span class="leise-marke" title={t.docker.portsPanelWarum}>
                  {t.docker.portsPanel}
                </span>
              {/if}
            </td>
            <td data-spalte={t.docker.spalteAdresse}><span class="mono">{p.adresse}</span></td>
            <td data-spalte={t.docker.spalteContainer}>
              {p.container}
              {#if p.stack}<span class="leise">{p.stack} · {p.dienst}</span>{/if}
            </td>
            <td data-spalte={t.docker.spalteUrteil}>
              <!-- Das kurze Urteil in der Zelle, die Begründung als Titel und
                   einmal über der Tabelle. Der ganze Satz hier wurde am
                   Tabellenrand abgeschnitten — ausgerechnet bei dem Befund,
                   wegen dessen es die Seite gibt.
                   Der Punkt gehört dazu: .zustand färbt ein <i>, nicht den
                   Text. -->
              <span class="zustand {p.stufe}" title={p.satz}><i></i>{p.kurz}</span>
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

<style>
  .zaehler {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
    margin-bottom: 0.6rem;
  }

  .zaehler b {
    color: var(--tx);
  }

  .leise {
    color: var(--tx-faint);
    font-size: 0.76rem;
    margin-left: 0.4rem;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
    border: 1px solid var(--line2);
    border-radius: 999px;
    padding: 0.05rem 0.4rem;
  }
</style>
