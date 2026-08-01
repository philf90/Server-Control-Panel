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
  import { t } from "../lib/texte";
  import { verweis, weg } from "../lib/weg.svelte";
  import type { Ziel } from "../lib/ziele";
  import type { Signale } from "../lib/typen";

  let { istOwner = false, signale = null }: { istOwner?: boolean; signale?: Signale | null } =
    $props();

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

  // ─────────────────────────────────────────────── Die Warnpunkte ─────────
  //
  // Sie beantworten EINE Frage, und zwar von jeder Seite aus: Muss ich woanders
  // hinsehen? Ohne sie ist die Antwort nur auf der Übersicht zu haben, und wer
  // gerade in den Logs sucht, sieht die Übersicht nicht. Das ist Mangel Nummer
  // vier aus docs/15-neuordnung.md, wörtlich: „Das Menü verrät nicht, ob
  // irgendwo etwas offen ist. Man muss jede Seite besuchen, um zu wissen, dass
  // nichts zu tun ist."
  //
  // Sie sind KEIN Ersatz für den Handlungsbedarf auf der Übersicht. Der sagt,
  // was los ist, in einem Satz mit einem Griff daneben; der Punkt sagt nur, dass
  // und wo.
  //
  // Die alte, server-gerenderte Fläche hatte sie bis 0.4.0 (DienstePip,
  // PaketePip in pages.go) und ordnete sie über sig.Tag zu — eine Zuordnung von
  // Hand, die für jedes neue Signal ergänzt werden musste. Hier entscheidet
  // stattdessen der Verweis, den das Signal ohnehin trägt: Wohin es führt, dort
  // sitzt sein Punkt. Damit gibt es keine zweite Liste, die auseinanderlaufen
  // kann — dieselbe Regel, aus der lib/ziele.ts entstanden ist.
  //
  // Zwei Stufen und kein „alles gut": Ein grüner Punkt an achtzehn Einträgen ist
  // Rauschen und keine Auskunft.
  const stufen = $derived.by(() => {
    const aus: Record<string, "crit" | "warn"> = {};
    for (const sig of signale?.signale ?? []) {
      const ziel = sig.aktion_href;
      if (!ziel) continue;
      if (aus[ziel] !== "crit") aus[ziel] = sig.level;
    }
    return aus;
  });

  /** stufeVon nennt die Stufe eines Ziels — bei einem Modul einschließlich
   *  seiner Flächen.
   *
   *  Die Zusammenfassung am Elternteil ist der Kern der Sache und keine
   *  Bequemlichkeit: Die Punkte der Flächen sieht man nur, während man im Modul
   *  steht. Ohne sie am Modul wäre der Punkt genau dann unsichtbar, wenn er
   *  gebraucht wird — von woanders aus. */
  function stufeVon(ziel: Ziel): "crit" | "warn" | "" {
    let hoechste: "crit" | "warn" | "" = stufen[ziel.href] ?? "";
    for (const kind of ziel.kinder ?? []) {
      const k = stufen[kind.href] ?? "";
      if (k === "crit") return "crit";
      if (k === "warn" && hoechste === "") hoechste = "warn";
    }
    return hoechste;
  }
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
            <!-- Der Punkt trägt einen Text, den nur Vorleseprogramme hören:
                 Eine Farbe allein ist keine Auskunft, und schmal ist der Punkt
                 das Einzige, was von diesem Eintrag noch etwas sagt. -->
            {#if stufeVon(ziel)}
              <i class="punkt {stufeVon(ziel)}" aria-hidden="true"></i>
              <span class="nurVorlesen">{t.leiste.offen(stufeVon(ziel) === "crit")}</span>
            {/if}
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
                  {#if stufen[kind.href]}
                    <i class="punkt {stufen[kind.href]}" aria-hidden="true"></i>
                    <span class="nurVorlesen">
                      {t.leiste.offen(stufen[kind.href] === "crit")}
                    </span>
                  {/if}
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

  /* Der Punkt sitzt am rechten Rand des Eintrags. Rechts und nicht am Symbol:
     Am Symbol säße er auf dem, was den Eintrag benennt; rechts steht er in einer
     Spalte, die man mit einem Blick von oben nach unten liest — und genau so
     liest man die Frage „ist irgendwo etwas offen". */
  .punkt {
    margin-left: auto;
    width: 7px;
    height: 7px;
    border-radius: 50%;
    flex: none;
    /* Dieselben Marken wie .zustand: „warn" ist überall im Panel der Akzent,
       „crit" überall die Fehlerfarbe. Ein zweites Farbenpaar für dieselbe
       Aussage wäre eine zweite Sprache. */
    background: var(--accent);
  }

  .punkt.crit {
    background: var(--err);
  }

  /* Sichtbar nur für Vorleseprogramme — dieselbe Machart wie die Beschriftungen
     in der Symbolschiene. */
  .nurVorlesen {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip-path: inset(50%);
    white-space: nowrap;
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

    /* Schmal ist der Punkt das Einzige, was ein Eintrag noch sagen kann — er
       bleibt also, rückt aber neben das Symbol statt an den rechten Rand einer
       Zeile, die es nicht mehr gibt. */
    .punkt {
      margin-left: 0.2rem;
    }
  }
</style>
