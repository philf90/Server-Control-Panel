<script lang="ts">
  // Die Sitesliste — was nginx WIRKLICH ausliefert.
  //
  // Die Quelle ist `nginx -T` und nicht das Verzeichnis sites-enabled: Dieselbe
  // Entscheidung wie beim Compose-Prüfer (docs/17-docker.md E4). Eine
  // Konfiguration mit `include` zeigt in den Dateien nicht, was der Server
  // daraus macht — und ein Serverblock, den niemand sieht, ist genau der, der
  // eine Domain wegnimmt.
  //
  // Die Trennung verwaltet/fremd ist die Zusage des Moduls und nicht Zierrat:
  // Dieselbe Trennung wie bei nftables, fremden Crontabs und fremden
  // Compose-Projekten. Was das Panel nicht geschrieben hat, zeigt es an und
  // fasst es nicht an.
  //
  // Der Fall, wegen dessen es das Feld `gelesen` gibt: `nginx -T` läuft nur bei
  // gültiger Konfiguration. Ist sie kaputt, kommt eine LEERE Liste zurück — und
  // die sieht aus wie ein Server ohne Sites. Die beiden verlangen
  // entgegengesetzte Handgriffe, deshalb sagt der Server, welcher der beiden
  // Fälle vorliegt, und die Fläche zeigt es an erster Stelle.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { verweis } from "../lib/weg.svelte";
  import type { Siteliste } from "../lib/typen";

  let daten = $state<Siteliste | null>(null);
  let fehler = $state("");

  async function laden() {
    fehler = "";
    try {
      daten = await api.webserverSites();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  const sites = $derived(daten?.sites ?? []);
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}

{#if !daten}
  <p class="detail">{t.webserver.sitesLaedt}</p>
{:else}
  <!-- Bei unlesbarer Konfiguration steht die Meldung von nginx ZUERST und als
       Warnung. Sie ist die einzige Auskunft, mit der sich der Fehler finden
       lässt — „unknown directive" nennt Datei und Zeile. -->
  {#if !daten.gelesen}
    <p class="warnung" role="alert">{daten.anmerkung}</p>
    {#if daten.fehler}
      <pre class="meldung">{daten.fehler}</pre>
    {/if}
    <a class="knopf leise" href="/dateien" onclick={(e) => verweis(e, "/dateien")}>
      {t.webserver.zuDateien}
    </a>
  {:else}
    <!-- Erst die Sätze, dann die Zahlen, dann die Tabelle. Die Zähler stehen
         unmittelbar über der Liste, auf die sie sich beziehen — zwischen zwei
         Absätze geklemmt las sie niemand als deren Zusammenfassung. -->
    <p class="hinweis">{t.webserver.sitesNurLesend}</p>

    {#if daten.anmerkung}
      <p class="hinweis">{daten.anmerkung}</p>
    {/if}

    {#if sites.length}
      <div class="zaehler">
        <span>{t.webserver.zaehlerVerwaltet} <b>{daten.zaehler.verwaltet}</b></span>
        <span>{t.webserver.zaehlerFremd} <b>{daten.zaehler.fremd}</b></span>
      </div>
    {/if}

    {#if sites.length}
      <div class="tabelle-rahmen">
        <table class="tabelle">
          <thead>
            <tr>
              <th>{t.webserver.spalteSite}</th>
              <th>{t.webserver.spalteDomains}</th>
              <th>{t.webserver.spalteZiel}</th>
              <th>{t.webserver.spaltePorts}</th>
              <th>{t.webserver.spalteHerkunft}</th>
            </tr>
          </thead>
          <tbody>
            {#each sites as s (s.datei + "|" + s.name)}
              <tr>
                <td data-spalte={t.webserver.spalteSite}>
                  {s.name}
                  <span class="leise">{s.datei}</span>
                </td>
                <td data-spalte={t.webserver.spalteDomains}>
                  <!-- Ein Serverblock ohne server_name ist kein Fehler: Er ist
                       der Vorgabeblock für alles, was sonst nicht passt. Ihn
                       leer zu lassen sähe nach einem Lesefehler aus. -->
                  {#if s.domains?.length}
                    <span class="mono">{s.domains.join(" · ")}</span>
                  {:else}
                    <span class="leise">{t.webserver.ohneDomain}</span>
                  {/if}
                </td>
                <td data-spalte={t.webserver.spalteZiel}>{s.zielsatz}</td>
                <td data-spalte={t.webserver.spaltePorts}>
                  <span class="mono">{(s.ports ?? []).join(", ")}</span>
                  {#if s.tls}<span class="leise-marke">{t.webserver.tls}</span>{/if}
                </td>
                <td data-spalte={t.webserver.spalteHerkunft}>
                  <!-- Das Wort kommt vom Server. Der Punkt davor färbt: eigene
                       Sites grün, fremde neutral — fremd ist kein Mangel. -->
                  <span class="zustand {s.herkunft === 'verwaltet' ? 'gut' : 'info'}">
                    <i></i>{s.herkunft}
                  </span>
                  {#if s.anmerkung}<span class="leise">{s.anmerkung}</span>{/if}
                </td>
              </tr>
            {/each}
          </tbody>
        </table>
      </div>
    {/if}
  {/if}
{/if}

<style>
  /* Zwei Hinweise hintereinander brauchen einen Abstand, sonst lesen sie sich
     als ein Absatz — und der zweite trägt die Zusage des Moduls. */
  .hinweis + .hinweis {
    margin-top: 0.5rem;
  }

  .zaehler {
    display: flex;
    flex-wrap: wrap;
    gap: 0.75rem;
    font-size: 0.78rem;
    color: var(--tx-mut);
    margin: 0.8rem 0 0.5rem;
  }

  .zaehler b {
    color: var(--tx);
  }

  .leise {
    display: block;
    color: var(--tx-faint);
    font-size: 0.76rem;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
    border: 1px solid var(--line2);
    border-radius: 999px;
    padding: 0.05rem 0.4rem;
  }

  /* Die Meldung von nginx im Klartext und umbruchfähig: Sie enthält Pfad und
     Zeilennummer, und ein am Rand abgeschnittener Pfad ist keine Auskunft. */
  .meldung {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 10px;
    padding: 0.7rem 0.9rem;
    font: 0.78rem/1.5 var(--mono);
    color: var(--tx-mut);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-width: 52rem;
    margin-bottom: 0.8rem;
  }
</style>
