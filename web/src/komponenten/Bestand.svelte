<script lang="ts">
  // Der Bestand: was Docker auf der Platte belegt, und die Handgriffe, es
  // loszuwerden.
  //
  // Die Seite beantwortet eine Frage, und die steht deshalb ganz oben: Was
  // bringt das Aufräumen? „docker system df" nennt je Art den freigebbaren
  // Platz, und dieselbe Zahl steht in der Rückfrage — „alle 12, davon 5 in
  // Gebrauch, 1.5 GB freigebbar" statt „alle".
  //
  // Was in Gebrauch ist, rechnet der Server aus der Containerliste aus. Das
  // erspart den Fehlversuch: Docker weigert sich, ein benutztes Image oder ein
  // eingehängtes Volume zu löschen, und ein Knopf, der zuverlässig in diese
  // Weigerung läuft, ist selbst der Fehler.
  import Rueckfrage from "./Rueckfrage.svelte";
  import Vorgangsplatte from "./Vorgangsplatte.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import type { Bestaetigung, Bestand } from "../lib/typen";

  let daten = $state<Bestand | null>(null);
  let fehler = $state("");
  let meldung = $state("");
  let arbeitet = $state(false);

  const vorgang = new Vorgang("docker-prune");

  let offeneFrage = $state<{
    frage: Bestaetigung;
    tun: (getippt: string) => Promise<void>;
  } | null>(null);

  async function laden() {
    fehler = "";
    try {
      const frisch = await api.bestand();
      daten = frisch;
      vorgang.setzen(frisch.job);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    }
  }

  void laden();

  $effect(() => () => vorgang.loesen());

  // Nach dem Ende des Vorgangs neu laden: Was danach noch da ist, sagt der
  // Server. Die Seite selbst weiß nur, dass der Lauf vorbei ist.
  let liefZuvor = $state(false);
  $effect(() => {
    const laeuft = vorgang.job?.laeuft ?? false;
    if (liefZuvor && !laeuft) void laden();
    liefZuvor = laeuft;
  });

  /** starten führt eine Aktion aus und fängt die Rückfrage des Servers ab.
   *
   *  Eine Funktion für alle vier Handgriffe, weil der Ablauf für alle derselbe
   *  ist: versuchen, bei 409 fragen, mit der Antwort erneut versuchen. Die
   *  Stufe entscheidet der Server — hier steht keine zweite Auslegung davon,
   *  was gefährlich ist. */
  async function starten(tun: (bestaetigt: boolean, getippt: string) => Promise<unknown>) {
    arbeitet = true;
    fehler = "";
    meldung = "";
    try {
      await tun(false, "");
      offeneFrage = null;
      await laden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = {
          frage: e.bestaetigung,
          tun: async (getippt: string) => {
            arbeitet = true;
            try {
              await tun(true, getippt);
              offeneFrage = null;
              await laden();
            } catch (e2) {
              if (e2 instanceof BestaetigungNoetig) {
                // Ein falsches Wort: Die Frage kommt zurück, mit dem Hinweis
                // darin. Sie zu schließen wäre die schlechtere Antwort — der
                // Bediener wollte die Aktion und hat sich vertippt.
                offeneFrage = { frage: e2.bestaetigung, tun: offeneFrage!.tun };
                return;
              }
              offeneFrage = null;
              fehler = e2 instanceof Error ? e2.message : t.fehler.laden;
            } finally {
              arbeitet = false;
            }
          },
        };
        return;
      }
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      arbeitet = false;
    }
  }

  const darfAendern = $derived(daten?.darf_aendern ?? false);
  const laeuftVorgang = $derived(vorgang.job?.laeuft ?? false);
  const gesperrt = $derived(arbeitet || laeuftVorgang);
</script>

{#if fehler}<p class="warnung" role="alert">{fehler}</p>{/if}
{#if daten?.fehler}<p class="warnung">{daten.fehler}</p>{/if}
{#if meldung}<p class="meldung" role="status">{meldung}</p>{/if}

{#if !daten}
  <p class="detail">{t.docker.laedt}</p>
{:else}
  <!-- Der Platzbedarf steht oben: Er ist die Frage, mit der jemand diese Seite
       öffnet, und die Spalte „freigebbar" ist die Antwort darauf. -->
  {#if daten.platte.length}
    <div class="tabelle-rahmen">
      <table class="tabelle">
        <thead>
          <tr>
            <th>{t.docker.posten}</th>
            <th>{t.docker.anzahl}</th>
            <th>{t.docker.inGebrauchSpalte}</th>
            <th>{t.docker.groesse}</th>
            <th>{t.docker.freigebbar}</th>
          </tr>
        </thead>
        <tbody>
          {#each daten.platte as p (p.art)}
            <tr>
              <td data-spalte={t.docker.posten}>{p.art}</td>
              <td data-spalte={t.docker.anzahl}><span class="mono">{p.anzahl}</span></td>
              <td data-spalte={t.docker.inGebrauchSpalte}><span class="mono">{p.aktiv}</span></td>
              <td data-spalte={t.docker.groesse}><span class="mono">{p.groesse}</span></td>
              <td data-spalte={t.docker.freigebbar}><span class="mono">{p.freigebbar}</span></td>
            </tr>
          {/each}
        </tbody>
      </table>
    </div>
  {/if}

  <Vorgangsplatte {vorgang} />

  {#if darfAendern}
    <!-- Jeder Knopf sagt, was er trifft. „aufräumen" allein befähigt zu keiner
         Entscheidung — und die gefährlichste Zeile steht als solche da. -->
    <div class="aktionen">
      <button type="button" class="knopf leise klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("images", false, b, g))}>
        {t.docker.verwaisteWeg}
      </button>
      <button type="button" class="knopf leise klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("images", true, b, g))}>
        {t.docker.alleUnbenutztenWeg}
      </button>
      <button type="button" class="knopf leise klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("container", false, b, g))}>
        {t.docker.gestoppteWeg}
      </button>
      <button type="button" class="knopf leise klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("netze", false, b, g))}>
        {t.docker.netzeWeg}
      </button>
      <button type="button" class="knopf leise klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("cache", false, b, g))}>
        {t.docker.cacheWeg}
      </button>
      <button type="button" class="knopf gefahr klein" disabled={gesperrt}
        onclick={() => starten((b, g) => api.aufraeumen("volumes", false, b, g))}>
        {t.docker.volumesWeg}
      </button>
    </div>
  {/if}

  <h3>{t.docker.images}</h3>
  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.docker.spalteImage}</th>
          <th>{t.docker.groesse}</th>
          <th>{t.docker.spalteAlter}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each daten.images as i (i.id)}
          <tr>
            <td data-spalte={t.docker.spalteImage}>
              {#if i.verwaist}
                <span class="mono">{i.kurz}</span>
                <span class="leise-marke">{t.docker.ohneNamen}</span>
              {:else}
                <span class="mono">{i.name}</span>
              {/if}
              {#if i.in_gebrauch}<span class="leise-marke">{t.docker.inGebrauch}</span>{/if}
            </td>
            <td data-spalte={t.docker.groesse}><span class="mono">{i.groesse}</span></td>
            <td data-spalte={t.docker.spalteAlter}>{i.alter}</td>
            <td data-spalte="">
              <!-- Kein Knopf an einem benutzten Image: Docker weigert sich,
                   und der Knopf wäre dann selbst der Fehler. -->
              {#if darfAendern && !i.in_gebrauch}
                <button type="button" class="knopf leise klein" disabled={gesperrt}
                  onclick={() => starten((b) => api.imageEntfernen(i.id, b))}>
                  {t.docker.entfernen}
                </button>
              {/if}
            </td>
          </tr>
        {:else}
          <tr><td colspan="4">{t.docker.keineImages}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>

  <h3>{t.docker.volumesTitel}</h3>
  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.docker.spalteName}</th>
          <th>{t.docker.spalteTreiber}</th>
          <th>{t.docker.spalteOrt}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each daten.volumes as v (v.name)}
          <tr>
            <td data-spalte={t.docker.spalteName}>
              <span class="mono">{v.name}</span>
              {#if v.in_gebrauch}<span class="leise-marke">{t.docker.inGebrauch}</span>{/if}
            </td>
            <td data-spalte={t.docker.spalteTreiber}>{v.treiber}</td>
            <!-- Der Ort steht da, weil er der Weg ist, über SSH an die Daten zu
                 kommen — bevor man das Volume löscht. -->
            <td data-spalte={t.docker.spalteOrt}><span class="mono">{v.ort}</span></td>
            <td data-spalte="">
              {#if darfAendern && !v.in_gebrauch}
                <button type="button" class="knopf gefahr klein" disabled={gesperrt}
                  onclick={() => starten((b, g) => api.volumeEntfernen(v.name, b, g))}>
                  {t.docker.entfernen}
                </button>
              {/if}
            </td>
          </tr>
        {:else}
          <tr><td colspan="4">{t.docker.keineVolumes}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>

  <h3>{t.docker.netzeTitel}</h3>
  <div class="tabelle-rahmen">
    <table class="tabelle">
      <thead>
        <tr>
          <th>{t.docker.spalteName}</th>
          <th>{t.docker.spalteTreiber}</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        {#each daten.netze as n (n.id)}
          <tr>
            <td data-spalte={t.docker.spalteName}>
              {n.name}
              {#if n.eingebaut}<span class="leise-marke">{t.docker.eingebaut}</span>{/if}
            </td>
            <td data-spalte={t.docker.spalteTreiber}>{n.treiber}</td>
            <td data-spalte="">
              <!-- bridge, host und none legt Docker selbst an und lässt sie
                   nicht entfernen. -->
              {#if darfAendern && !n.eingebaut}
                <button type="button" class="knopf leise klein" disabled={gesperrt}
                  onclick={() => starten((b) => api.netzEntfernen(n.id, b))}>
                  {t.docker.entfernen}
                </button>
              {/if}
            </td>
          </tr>
        {:else}
          <tr><td colspan="3">{t.docker.keineNetze}</td></tr>
        {/each}
      </tbody>
    </table>
  </div>
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={arbeitet}
    bestaetigen={(getippt) => offeneFrage?.tun(getippt) ?? Promise.resolve()}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  h3 {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin: 1.25rem 0 0.5rem;
  }

  .aktionen {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4rem;
    margin: 0.75rem 0;
  }

  .leise-marke {
    color: var(--tx-faint);
    font-size: 0.72rem;
    margin-left: 0.4rem;
  }
</style>
