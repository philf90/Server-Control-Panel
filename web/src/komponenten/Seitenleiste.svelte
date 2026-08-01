<script lang="ts">
  // Vier Gruppen statt einer Liste gleichrangiger Punkte: System, Apps,
  // Sicherheit, Betrieb. Der Zuschnitt kommt aus docs/16-neukonzeption.md 8.2
  // und trägt die Module, die in 0.5 bis 0.8 dazukommen.
  //
  // Die Ziele selbst stehen in lib/ziele.ts, weil die Befehlspalette dieselbe
  // Liste braucht. Zwei Listen desselben Menüs laufen auseinander: Ein neues
  // Modul erschiene dann in der Leiste, aber nicht in der Suche.
  //
  // Was die Rolle nicht erreicht, steht nicht darin: Ein Menüpunkt, der
  // zuverlässig „der Owner-Rolle vorbehalten" antwortet, ist kein Menüpunkt.
  // Gefiltert wird in lib/ziele.ts, damit die Palette dieselbe Regel benutzt.
  import { sichtbareGruppen } from "../lib/ziele";
  import { verweis, weg } from "../lib/weg.svelte";

  let { istOwner = false }: { istOwner?: boolean } = $props();

  const gruppen = $derived(sichtbareGruppen(istOwner));

  // Der hervorgehobene Punkt kommt aus dem Weg und nicht aus einer Eigenschaft:
  // Die Kennungen der Ziele in lib/ziele.ts sind dieselben, die der Router
  // liefert. Eine Eigenschaft müsste jede Seite selbst setzen — und eine davon
  // wird es vergessen.
  //
  // Die Kennung aus der Adresse hat Vorrang vor der Seite, und das ist mehr als
  // eine Feinheit: Ein angekündigtes Modul (/docker) rendert die Seite „bald",
  // heißt aber weiter „docker". Ohne den Vorrang wäre bei ihm kein Punkt
  // hervorgehoben, und die Seite sähe aus wie eine, auf die man versehentlich
  // geraten ist. Bei einem Pfad, den es gar nicht gibt, bleibt bewusst nichts
  // markiert — die Übersicht hervorzuheben wäre eine Behauptung über den Ort.
  const aktiv = $derived(weg.modul || weg.seite);

  // Die Kennung der stehenden FLÄCHE — „docker/ports", oder „docker/" für die
  // Vorgabe des Moduls. Ein leeres zweites Segment ist keine fehlende Auskunft,
  // sondern die erste Fläche; deshalb der Schrägstrich auch dann.
  const aktivesKind = $derived(aktiv + "/" + weg.unterseite);
</script>

<aside class="seitenleiste">
  {#each gruppen as gruppe (gruppe.titel)}
    <div class="gruppe">
      <b>{gruppe.titel}</b>
      <nav>
        {#each gruppe.ziele as ziel (ziel.id)}
          <!-- Ein echtes <a href>, und der Klick wird nur abgefangen, wenn das
               Ziel zur neuen Oberfläche gehört: Damit bleiben Mittelklick, „in
               neuem Tab öffnen" und der Verweis in der Statuszeile erhalten,
               und die Ziele, die noch auf / zeigen, laden ganz normal. -->
          <a
            href={ziel.href}
            onclick={(e) => verweis(e, ziel.href)}
            class:an={ziel.id === aktiv && !ziel.kinder}
            class:offen={ziel.id === aktiv && !!ziel.kinder}
            aria-current={ziel.id === aktiv && !ziel.kinder ? "page" : undefined}
          >
            <svg aria-hidden="true"><use href="#sym-{ziel.symbol}" /></svg>
            <span>{ziel.label}</span>
          </a>

          <!-- Die Flächen des Moduls, sichtbar solange man darin steht.
               Aufgeklappt heißt hier „ich bin drin" und nicht „ich habe darauf
               geklickt": Es gibt keinen Umschalter und damit keinen Zustand, der
               dem Ort widersprechen könnte.

               Der Elternteil trägt in diesem Fall NICHT die Hervorhebung — sie
               steht am Kind. Sonst wären zwei Punkte gleichzeitig markiert und
               keiner davon sagte, wo man ist. -->
          {#if ziel.kinder && ziel.id === aktiv}
            <div class="kinder">
              {#each ziel.kinder as kind (kind.id)}
                <a
                  href={kind.href}
                  onclick={(e) => verweis(e, kind.href)}
                  class:an={kind.id === aktivesKind}
                  aria-current={kind.id === aktivesKind ? "page" : undefined}
                >
                  <span>{kind.label}</span>
                </a>
              {/each}
            </div>
          {/if}
        {/each}
      </nav>
    </div>
  {/each}
</aside>

<style>
  .seitenleiste {
    background: var(--surface);
    border-right: 1px solid var(--line);
    padding: 0.9rem 0 0.6rem;
    overflow-y: auto;
  }

  .gruppe {
    margin-bottom: 1rem;
  }

  .gruppe > b {
    display: block;
    font-size: 0.64rem;
    font-weight: 650;
    letter-spacing: 0.14em;
    text-transform: uppercase;
    color: var(--tx-faint);
    padding: 0 1rem 0.35rem;
  }

  nav {
    display: flex;
    flex-direction: column;
  }

  a {
    display: flex;
    align-items: center;
    gap: 0.65rem;
    color: var(--tx-mut);
    text-decoration: none;
    font-size: 0.85rem;
    padding: 0.4rem 1rem;
    border-left: 2px solid transparent;
  }

  a svg {
    width: 16px;
    height: 16px;
    flex: none;
    opacity: 0.85;
  }

  a:hover {
    color: var(--tx);
    background: var(--surface2);
  }

  a.an {
    color: var(--accent);
    border-left-color: var(--accent);
    background: linear-gradient(90deg, rgba(232, 163, 61, 0.1), transparent);
  }

  /* Das Modul mit offenen Kindern trägt selbst keine Hervorhebung, bleibt aber
     erkennbar: Es ist der Kopf über der eingerückten Liste. */
  a.offen {
    color: var(--tx);
  }

  .kinder {
    display: flex;
    flex-direction: column;
  }

  /* Eingerückt bis unter das Symbol des Moduls: Die Kinder hängen sichtbar an
     ihm, statt als weitere gleichrangige Punkte zu erscheinen. */
  .kinder a {
    padding-left: 2.65rem;
    font-size: 0.8rem;
  }

  /* Schmal wird aus der Leiste eine Symbolschiene. Die Beschriftung verschwindet
   * für das Auge, bleibt aber für Vorleseprogramme stehen. */
  @media (max-width: 900px) {
    .gruppe > b {
      display: none;
    }

    /* Die Kinder verschwinden aus der Schiene, und das ist der Punkt, an dem
       dieser Entwurf sonst gescheitert wäre: Ohne Beschriftung gibt es keine
       Einrückung, die man sähe — fünf Unterpunkte wären fünf weitere Symbole in
       derselben Spalte, nicht unterscheidbar von einem Modul, und für die es
       keine fünf sinnvollen Symbole gibt.

       Die Fläche wechselt man hier über den Umschaltstreifen der Seite selbst
       (seiten/Docker.svelte). Zwei Navigationen also — aber nie beide
       gleichzeitig sichtbar. */
    .kinder {
      display: none;
    }

    a span {
      position: absolute;
      width: 1px;
      height: 1px;
      overflow: hidden;
      clip-path: inset(50%);
      white-space: nowrap;
    }

    a {
      justify-content: center;
      padding: 0.5rem 0;
    }
  }
</style>
