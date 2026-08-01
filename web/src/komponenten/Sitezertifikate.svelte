<script lang="ts">
  // Zertifikate je Site — die Fläche, die den Satz aus docs/16 §2 einlöst:
  // „mach ihn unter einem Namen mit TLS erreichbar".
  //
  // Sie beantwortet drei Fragen, und die dritte ist die, wegen der es sie gibt:
  // Hat diese Site ein Zertifikat? Wie lange gilt es noch? Und wenn keins da ist
  // — WARUM nicht? Vier verschiedene Gründe kommen in Frage, und sie liegen an
  // vier verschiedenen Stellen: die Site ist frisch, die DNS-Zone antwortet
  // nicht, der Anbieter lehnt die Zugangsdaten ab, oder für das Panel selbst
  // läuft gar kein ACME. „Kein Zertifikat" allein schickt jemanden auf eine
  // Suche über alle vier.
  //
  // Stufe und Satz rechnet der SERVER. Der Browser färbt danach und legt keine
  // eigene Auslegung daneben — dasselbe Muster wie bei der Portübersicht.
  import { AbgemeldetFehler, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { verweis } from "../lib/weg.svelte";
  import type { Sitezertifikate } from "../lib/typen";

  let daten = $state<Sitezertifikate | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let arbeitet = $state("");

  async function laden() {
    fehler = "";
    try {
      daten = await api.webserverZertifikate();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  async function beziehen(site: string) {
    arbeitet = site;
    fehler = "";
    meldung = "";
    try {
      meldung = (await api.siteZertBeziehen(site)).meldung;
      await laden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
      // Auch nach einem Fehlschlag neu laden: Der Stand am Server trägt jetzt
      // den letzten Versuch samt Grund, und der gehört in die Zeile.
      await laden();
    } finally {
      arbeitet = "";
    }
  }

  const zeilen = $derived(daten?.zertifikate ?? []);
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

{#if !daten}
  <p class="detail">{t.webserver.zertLaedt}</p>
{:else}
  <p class="hinweis">{t.webserver.zertWesen}</p>
  {#if daten.anmerkung}<p class="hinweis">{daten.anmerkung}</p>{/if}
  {#if daten.fehler}<p class="warnung">{daten.fehler}</p>{/if}

  <!-- Ohne ACME fürs Panel führt der Weg auf die Zertifikatsseite und nicht auf
       einen Knopf, der zuverlässig scheitert. -->
  {#if !daten.acme_aktiv}
    <a class="knopf leise" href="/zertifikate" onclick={(e) => verweis(e, "/zertifikate")}>
      {t.webserver.zuZertifikaten}
    </a>
  {/if}

  {#if zeilen.length === 0}
    <p class="detail">{t.webserver.zertLeer}</p>
  {:else}
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.webserver.spalteZertSite}</th>
            <th>{t.webserver.spalteZertNamen}</th>
            <th>{t.webserver.spalteZertZustand}</th>
            <th>{t.webserver.spalteZertAussteller}</th>
            <th>{t.webserver.spalteZertBezug}</th>
          </tr>
        </thead>
        <tbody>
          {#each zeilen as z (z.site)}
            <tr>
              <td data-spalte={t.webserver.spalteZertSite}>{z.site}</td>
              <td data-spalte={t.webserver.spalteZertNamen}>
                <span class="mono">{(z.domains ?? []).join(" · ")}</span>
              </td>
              <td data-spalte={t.webserver.spalteZertZustand}>
                <!-- Der Punkt trägt die Stufe, der Satz die Begründung. Eine
                     Farbe ohne Satz wäre eine Behauptung. -->
                <span class="zustand {z.stufe}"><i></i>{z.vorhanden
                    ? t.webserver.tls
                    : t.webserver.zertKeins}</span>
                <span class="satz">{z.satz}</span>
              </td>
              <td data-spalte={t.webserver.spalteZertAussteller}>
                {z.aussteller || "—"}
              </td>
              <td data-spalte={t.webserver.spalteZertBezug}>
                {#if daten.darf_aendern && daten.acme_aktiv}
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={arbeitet !== "" || z.laeuft}
                    onclick={() => void beziehen(z.site)}
                  >
                    {arbeitet === z.site || z.laeuft
                      ? t.webserver.zertLaeuft
                      : t.webserver.zertBeziehen}
                  </button>
                {/if}
              </td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}
{/if}

<style>
  .hinweis + .hinweis {
    margin-top: 0.5rem;
  }

  /* Der Satz unter dem Zustand: eigene Zeile, damit er nicht am Tabellenrand
     abgeschnitten wird. Er ist die eigentliche Auskunft dieser Fläche — der
     farbige Punkt daneben ist nur ihre Zusammenfassung. */
  .satz {
    display: block;
    color: var(--tx-mut);
    font-size: 0.76rem;
    line-height: 1.5;
    margin-top: 0.15rem;
    max-width: 34rem;
  }
</style>
