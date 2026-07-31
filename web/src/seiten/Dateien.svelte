<script lang="ts">
  // Dateien: Krumenpfad und Liste links, Inspektor rechts — dieselbe Werkbank
  // wie bei den Diensten. Was dieses Modul davon unterscheidet, sind drei Dinge:
  //
  //  1. Der Ort steht in der Adresse und ist ein Schritt im Verlauf. Beim
  //     Hineinwechseln in einen Ordner drückt niemand „zurück", um die Seite zu
  //     verlassen — er will eine Ebene höher. Deshalb pushState je Ebene
  //     (weg.setzeAlle mit schritt=true), und nicht das Muster der Dienste, wo
  //     nur die erste Auswahl ein Schritt ist.
  //  2. Gefiltert wird NICHT im Browser. Bei den Diensten kommt die Liste einmal
  //     und das Tippen filtert sofort; hier ist die Liste bei zweitausend
  //     Einträgen gekürzt, und ein Browserfilter darüber behauptete „kein
  //     Treffer" für eine Datei, die es gibt. Die Suche geht deshalb an den
  //     Server — sie sucht auch in Unterordnern, was ein Browserfilter nie
  //     könnte.
  //  3. Ein Eintrag kann gesperrt sein. Er ist dann sichtbar, sein Inhalt aber
  //     nie: kein Download, kein Editor, kein Kopieren. Das steht an der Zeile
  //     und im Inspektor, nicht in einer Fehlermeldung nach dem Klick.
  import Editor from "../komponenten/Editor.svelte";
  import Inspektor from "../komponenten/Inspektor.svelte";
  import Rueckfrage from "../komponenten/Rueckfrage.svelte";
  import Vorgangsplatte from "../komponenten/Vorgangsplatte.svelte";
  import Zielwahl from "../komponenten/Zielwahl.svelte";
  import { AbgemeldetFehler, BestaetigungNoetig, api } from "../lib/api";
  import { t } from "../lib/texte";
  import { Vorgang } from "../lib/vorgang.svelte";
  import { verweis, weg } from "../lib/weg.svelte";
  import type {
    Bestaetigung,
    Dateiauftrag,
    Dateidetail,
    Dateihandlung,
    Dateiliste,
    Eintrag,
    Handgriff,
    Sortierung,
  } from "../lib/typen";

  let { darfSchreiben = false }: { darfSchreiben?: boolean } = $props();

  let daten = $state<Dateiliste | null>(null);
  let fehler = $state("");
  let laedt = $state(false);
  /** suchfeld ist der Text im Eingabefeld. Getrennt vom Parameter in der
   *  Adresse, weil die Suche erst beim Absenden läuft: Ein Aufruf je Buchstabe
   *  liefe zehn Sekunden lang über einen Verzeichnisbaum. */
  let suchfeld = $state("");

  let detail = $state<Dateidetail | null>(null);
  let detailFehler = $state("");
  let detailLaeuft = $state(false);

  const vorgang = new Vorgang("files");

  const pfad = $derived(weg.parameter.pfad ?? "");
  const gewaehlt = $derived(weg.parameter.eintrag ?? "");
  const begriff = $derived(weg.parameter.q ?? "");
  const sortierung = $derived((weg.parameter.sort ?? "name") as Sortierung);
  const absteigend = $derived(weg.parameter.desc === "1");
  const versteckt = $derived(weg.parameter.versteckt === "1");
  /** bearbeitet ist der Pfad im Editor. Er steht in der Adresse wie jede andere
   *  Auswahl: Ein Verweis auf eine Datei im Editor ist damit teilbar, und der
   *  Zurück-Knopf schließt ihn statt die Seite zu verlassen. */
  const bearbeitet = $derived(weg.parameter.bearbeiten ?? "");

  // Die Liste folgt der Adresse, nicht dem Klick — damit ein Neuladen auf
  // ?pfad=/etc/nginx dasselbe zeigt und der Zurück-Knopf wirkt.
  $effect(() => {
    void laden(pfad, begriff, sortierung, absteigend, versteckt);
  });

  async function laden(
    p: string,
    q: string,
    sort: Sortierung,
    desc: boolean,
    hidden: boolean,
  ) {
    laedt = true;
    fehler = "";
    try {
      const antwort = await api.dateien(p, { sort, absteigend: desc, versteckt: hidden, q });
      daten = antwort;
      suchfeld = antwort.suche;
      // Der Server kennt den Ort besser als die Adresse: Ohne Parameter beginnt
      // er im ersten sichtbaren Bereich, und ohne diese Zeile stünde im
      // Krumenpfad ein Ort und in der Adresse keiner — der nächste Klick auf
      // „eine Ebene höher" ginge dann ins Leere.
      if (!p && antwort.pfad) weg.setzeAlle({ pfad: antwort.pfad }, false);
      vorgang.setzen(antwort.vorgang);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      fehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laedt = false;
    }
  }

  // Das Detail folgt ebenfalls der Adresse.
  $effect(() => {
    const ziel = gewaehlt;
    if (!ziel) {
      detail = null;
      detailFehler = "";
      return;
    }
    if (detail?.eintrag.path === ziel) return;
    void detailHolen(ziel);
  });

  async function detailHolen(ziel: string) {
    detailLaeuft = true;
    detailFehler = "";
    try {
      detail = await api.eintrag(ziel);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      detail = null;
      detailFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      detailLaeuft = false;
    }
  }

  /** hinein wechselt das Verzeichnis. Ein Schritt im Verlauf, und Auswahl wie
   *  Suchbegriff fallen dabei weg: Beides bezog sich auf den alten Ort. */
  function hinein(ziel: string) {
    meldungenLeeren();
    weg.setzeAlle({ pfad: ziel, eintrag: "", q: "", bearbeiten: "" }, true);
  }

  function waehlen(e: Eintrag) {
    // Ein Ordner wird betreten, eine Datei ausgewählt. Ein Doppelklick als
    // Unterschied wäre auf einem Telefon nicht bedienbar; die Art des Eintrags
    // ist die verlässlichere Auskunft darüber, was gemeint ist. Der Inspektor
    // eines Ordners ist über die Spalte „Art" erreichbar.
    if (e.kind === "ordner") {
      hinein(e.path);
      return;
    }
    meldungenLeeren();
    weg.setzeAlle({ eintrag: e.path }, !gewaehlt);
  }

  function schliessen() {
    meldungenLeeren();
    weg.setzeAlle({ eintrag: "" }, false);
  }

  function sortieren(feld: Sortierung) {
    // Ein Klick auf die schon aktive Spalte dreht die Richtung. Kein Schritt im
    // Verlauf: Zehnmal sortieren und dann zehnmal zurück wäre kein Gewinn.
    const dreht = sortierung === feld && !absteigend;
    weg.setzeAlle({ sort: feld === "name" ? "" : feld, desc: dreht ? "1" : "" }, false);
  }

  function versteckteUmschalten() {
    weg.setzeAlle({ versteckt: versteckt ? "" : "1" }, false);
  }

  function suchen(e: SubmitEvent) {
    e.preventDefault();
    meldungenLeeren();
    weg.setzeAlle({ q: suchfeld.trim(), eintrag: "", bearbeiten: "" }, true);
  }

  function sucheBeenden() {
    suchfeld = "";
    meldungenLeeren();
    weg.setzeAlle({ q: "", eintrag: "", bearbeiten: "" }, true);
  }

  function kann(h: Handgriff): boolean {
    return detail?.aktionen.includes(h) ?? false;
  }

  /** bearbeiten öffnet den Editor. Ein Schritt im Verlauf, damit der
   *  Zurück-Knopf ihn schließt — dieselbe Regel wie beim Inspektor. */
  function bearbeiten(pfadJetzt: string) {
    meldungenLeeren();
    weg.setzeAlle({ bearbeiten: pfadJetzt }, !bearbeitet);
  }

  function editorSchliessen() {
    weg.setzeAlle({ bearbeiten: "" }, false);
  }

  // ------------------------------------------------------------- Verändern ---
  //
  // Jede verändernde Handlung läuft durch handlung(). Der Grund dafür ist die
  // Rückfrage: Sie kommt als 409 vom Server, und der zweite Anlauf ist DERSELBE
  // Aufruf mit bestaetigt=true. Wäre das an sieben Stellen ausgeschrieben, wäre
  // eine davon die, an der die Bestätigung nicht mit durchgereicht wird — und
  // dann führte ein Knopf zuverlässig in einen Dialog, der nichts tut.

  let laufendeHandlung = $state<Dateihandlung | "upload" | "">("");
  let handlungMeldung = $state("");
  let handlungFehler = $state("");
  /** offeneFrage hält die Rückfrage zusammen mit dem Weg, sie auszuführen. Beides
   *  zusammen, weil ein Dialog, der nicht weiß, was er bestätigt, nichts tun
   *  kann. */
  let offeneFrage = $state<{
    frage: Bestaetigung;
    weiter: (getippt: string) => Promise<void>;
  } | null>(null);

  /** anlegen ist die offene Eingabemaske über der Liste, "" heißt geschlossen. */
  let anlegen = $state<"" | "ordner" | "datei">("");
  let neuerName = $state("");
  /** umbenennen ist offen, wenn im Inspektor der Name bearbeitet wird. */
  let umbenennenOffen = $state(false);
  let namensfeld = $state("");
  /** zielwahl ist offen, während der Ordnerbrowser steht. */
  let zielwahl = $state<"copy" | "move" | "">("");
  /** rechteOffen und die drei Felder darunter sind die Maske für chmod/chown. */
  let rechteOffen = $state(false);
  let rechtefeld = $state("");
  let besitzerfeld = $state("");
  let gruppenfeld = $state("");
  let rekursivfeld = $state(false);
  let ueberschreiben = $state(false);
  let dateifeld: HTMLInputElement | undefined = $state();

  /** meldungImInspektor sagt, WO die Rückmeldung steht.
   *
   *  Nicht Kosmetik: „Umbenannt in x" gehört an die Stelle, an der der Knopf war,
   *  und „3 Dateien hochgeladen" über die Liste, in der sie jetzt stehen. Eine
   *  Meldung, die nach einem Klick im Inspektor am anderen Ende der Seite
   *  erscheint, wird nicht gelesen. */
  let meldungImInspektor = $state(false);

  /** Masken schließen, wenn die Auswahl wechselt: Eine offene Rechtemaske mit den
   *  Werten der vorigen Datei wäre die gefährlichste Art von Aufräumfehler — der
   *  Knopf hieße weiter „anwenden" und träfe etwas anderes.
   *
   *  Die Meldungen werden hier NICHT geleert, und das ist überlegt: Eine Handlung
   *  wechselt oft selbst die Auswahl (nach dem Anlegen ist der neue Eintrag
   *  gewählt, nach dem Löschen keiner), und dieser Effekt lief danach — er hätte
   *  genau die Meldung weggewischt, die die Handlung eben erzeugt hat. Geleert
   *  wird beim Klick des Bedieners, unten in waehlen(), hinein() und
   *  schliessen(). */
  $effect(() => {
    const ziel = gewaehlt;
    void ziel;
    umbenennenOffen = false;
    rechteOffen = false;
    zielwahl = "";
  });

  function meldungenLeeren() {
    handlungMeldung = "";
    handlungFehler = "";
  }

  /** handlung führt eine verändernde Handlung aus und behandelt die Rückfrage.
   *
   *  nachher entscheidet, wohin die Oberfläche danach sieht. Es steht als
   *  Rückruf hier und nicht in jedem Aufrufer, weil es je Handlung etwas anderes
   *  ist: Nach dem Verschieben folgt der Blick dem Eintrag an seinen neuen Ort,
   *  nach dem Löschen bleibt er im übergeordneten Ordner. */
  async function handlung(
    h: Dateihandlung,
    felder: Partial<Dateiauftrag>,
    nachher: (ordner: string, neuerPfad: string) => void,
    imInspektor = true,
  ) {
    laufendeHandlung = h;
    meldungImInspektor = imInspektor;
    handlungMeldung = "";
    handlungFehler = "";

    const lauf = async (bestaetigt: boolean, getippt: string) => {
      const antwort = await api.dateiHandlung(h, felder, bestaetigt, getippt);
      offeneFrage = null;
      handlungMeldung = antwort.meldung;
      // Läuft es als Vorgang, hängt sich die Platte an den Strom. Der Zustand des
      // Ziels steht dann erst am Ende fest — deshalb kein Detail aus dieser
      // Antwort.
      if (antwort.vorgang) vorgang.setzen(antwort.vorgang);
      if (antwort.eintrag) detail = antwort.eintrag;
      nachher(antwort.ordner, antwort.eintrag?.eintrag.path ?? "");
      await neuLaden();
    };

    try {
      await lauf(false, "");
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        // Auch der zweite Anlauf kann zurückkommen: bei Stufe 3, wenn das
        // getippte Wort nicht passte. Dann trägt die Frage das Feld `fehler` und
        // der Dialog bleibt stehen.
        offeneFrage = { frage: e.bestaetigung, weiter: (w) => lauf(true, w) };
        return;
      }
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      // Ohne Bedingung: Steht die Rückfrage noch, wäre der Knopf darin sonst für
      // immer gesperrt. Genau dieser Fehler stand schon einmal im Paketmodul.
      laufendeHandlung = "";
    }
  }

  /** offeneFrageBestaetigen ist der Weg vom Dialog zurück in die Handlung. Die
   *  Fehlerbehandlung liegt bewusst hier und nicht im Dialog: Auch der zweite
   *  Anlauf kann scheitern, und dann soll die Meldung im Inspektor stehen. */
  async function offeneFrageBestaetigen(getippt: string) {
    const frage = offeneFrage;
    if (!frage) return;
    laufendeHandlung = laufendeHandlung || "delete";
    try {
      await frage.weiter(getippt);
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      if (e instanceof BestaetigungNoetig) {
        offeneFrage = { frage: e.bestaetigung, weiter: frage.weiter };
        return;
      }
      offeneFrage = null;
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufendeHandlung = "";
    }
  }

  function neuLaden() {
    return laden(pfad, begriff, sortierung, absteigend, versteckt);
  }

  function ordnerAnlegen(e: SubmitEvent) {
    e.preventDefault();
    const name = neuerName.trim();
    if (!name || !daten) return;
    const art: Dateihandlung = anlegen === "ordner" ? "mkdir" : "touch";
    void handlung(
      art,
      { pfad: daten.pfad, name },
      (_, neu) => {
        anlegen = "";
        neuerName = "";
        // Der neue Eintrag wird ausgewählt: Wer einen Ordner anlegt, will meist
        // gleich hinein oder die Rechte setzen.
        if (neu) weg.setzeAlle({ eintrag: neu }, !gewaehlt);
      },
      false,
    );
  }

  function umbenennen(e: SubmitEvent) {
    e.preventDefault();
    const name = namensfeld.trim();
    if (!name || !detail) return;
    void handlung("rename", { pfad: detail.eintrag.path, name }, (_, neu) => {
      umbenennenOffen = false;
      if (neu) weg.setzeAlle({ eintrag: neu }, false);
    });
  }

  function zielGewaehlt(ziel: string) {
    if (!detail) return;
    const verschieben = zielwahl === "move";
    const pfadJetzt = detail.eintrag.path;
    zielwahl = "";
    void handlung(verschieben ? "move" : "copy", { pfad: pfadJetzt, ziel }, (ordner, neu) => {
      // Nach dem Verschieben folgt der Blick dem Eintrag: Er ist hier weg, und
      // eine Liste ohne ihn ohne weitere Auskunft sieht aus, als wäre er
      // verschwunden. Nach dem Kopieren bleibt der Blick, wo er war — das
      // Original steht noch da, und die Meldung nennt das Ziel.
      if (verschieben) weg.setzeAlle({ pfad: ordner, eintrag: neu }, true);
    });
  }

  function loeschen() {
    if (!detail) return;
    void handlung(
      "delete",
      { pfad: detail.eintrag.path },
      (ordner) => {
        detail = null;
        weg.setzeAlle({ pfad: ordner, eintrag: "" }, false);
      },
      // Über die Liste: Der Inspektor geht mit dem gelöschten Eintrag zu, und
      // eine Meldung darin wäre nie zu sehen.
      false,
    );
  }

  function rechteOeffnen() {
    if (!detail) return;
    // Vorbelegt mit dem, was gilt: Ein leeres Feld hieße „nichts ändern", und
    // wer die Rechte ansehen will, soll sie nicht abschreiben müssen.
    rechtefeld = detail.rechte.octal;
    besitzerfeld = detail.eintrag.owner;
    gruppenfeld = detail.eintrag.group;
    rekursivfeld = false;
    rechteOffen = true;
  }

  function rechteAnwenden(e: SubmitEvent) {
    e.preventDefault();
    if (!detail) return;
    const jetzt = detail;
    // Nur schicken, was sich geändert hat. Ein chown auf denselben Eigentümer
    // wäre ein Audit-Eintrag ohne Vorgang — und bei „rekursiv" ein Lauf über den
    // ganzen Baum, der nichts tut außer Zeit zu kosten.
    const felder: Partial<Dateiauftrag> = { pfad: jetzt.eintrag.path, rekursiv: rekursivfeld };
    if (rechtefeld.trim() !== jetzt.rechte.octal) felder.rechte = rechtefeld.trim();
    if (besitzerfeld !== jetzt.eintrag.owner) felder.eigentuemer = besitzerfeld;
    if (gruppenfeld !== jetzt.eintrag.group) felder.gruppe = gruppenfeld;
    // Bei einem rekursiven Lauf ist „unverändert" trotzdem eine Handlung: Die
    // Einträge darunter tragen andere Werte, und genau die sollen gleichgezogen
    // werden.
    if (rekursivfeld) {
      felder.rechte = rechtefeld.trim();
      felder.eigentuemer = besitzerfeld;
      felder.gruppe = gruppenfeld;
    }
    if (!felder.rechte && !felder.eigentuemer && !felder.gruppe) {
      handlungFehler = t.dateien.nichtsGeaendert;
      return;
    }
    void handlung("mode", felder, () => {
      rechteOffen = false;
    });
  }

  async function hochladen() {
    const dateien = [...(dateifeld?.files ?? [])];
    if (dateien.length === 0 || !daten) return;

    laufendeHandlung = "upload";
    meldungImInspektor = false;
    handlungMeldung = "";
    handlungFehler = "";
    try {
      const antwort = await api.hochladen(daten.pfad, dateien, ueberschreiben);
      const anzahl = antwort.entries?.length ?? 0;
      handlungMeldung = t.dateien.hochgeladen(anzahl);
      if (dateifeld) dateifeld.value = "";
      await neuLaden();
    } catch (e) {
      if (e instanceof AbgemeldetFehler) throw e;
      handlungFehler = e instanceof Error ? e.message : t.fehler.laden;
    } finally {
      laufendeHandlung = "";
    }
  }

  /** darfHierAnlegen sagt, ob im STEHENDEN Ordner etwas landen darf. Nicht
   *  dasselbe wie darfSchreiben: Das Konto darf, der Ort nicht — und dann sind
   *  die Knöpfe nicht da, statt in ein 403 zu laufen. */
  const darfHierAnlegen = $derived(
    darfSchreiben && !!daten?.ordner.writable && !daten?.ordner.sensitive && !begriff,
  );

  function artKlasse(e: Eintrag): string {
    if (e.sensitive) return "warn";
    if (e.kind === "verweis" && e.link_broken) return "schlecht";
    return "";
  }

  const sortPfeil = (feld: Sortierung) =>
    sortierung !== feld ? "" : absteigend ? " ↓" : " ↑";
</script>

<div class="kopfzeile">
  <div>
    <div class="crumb">{t.bereiche.betrieb} / {t.ziele.dateien}</div>
    <div class="h1">{t.ziele.dateien}</div>
  </div>
  <div class="schub"></div>
  {#if daten}
    {#if daten.frei_text}
      <span class="marke" class:warn={daten.frei_knapp}>
        {daten.frei_text} {daten.frei_knapp ? t.dateien.freiKnapp : t.dateien.frei}
      </span>
    {/if}
  {/if}
</div>

{#if fehler && !daten}
  <div class="hinweis">
    <p>{t.fehler.laden}</p>
    <p class="detail">{fehler}</p>
    <button
      class="knopf"
      onclick={() => laden(pfad, begriff, sortierung, absteigend, versteckt)}
    >
      {t.fehler.erneut}
    </button>
  </div>
{:else if !daten}
  <p class="detail">{t.dateien.laedt}</p>
{:else}
  <!-- Die Bereiche sind die Einstiegspunkte. Sie stehen über allem, weil sie die
       Antwort auf „wo darf ich überhaupt hin" sind — und weil ein Pfad, der
       außerhalb liegt, gar nicht erst getippt werden soll. -->
  <div class="bereiche" role="group" aria-label={t.dateien.wurzeln}>
    <span class="etikett">{t.dateien.wurzeln}</span>
    {#each daten.wurzeln as wurzel (wurzel)}
      <button
        type="button"
        class:an={pfad === wurzel}
        class:schreib={daten.schreibwurzeln.includes(wurzel)}
        onclick={() => hinein(wurzel)}
      >
        {wurzel}
      </button>
    {/each}
  </div>

  {#each daten.warnungen as warnung (warnung.path)}
    <!-- Fast immer eine alte systemd-Härtung nach einem Selbstupdate. Ohne diesen
         Hinweis sucht man den Fehler im Panel statt in der Unit. -->
    <p class="band warn" role="status">
      <b>{warnung.path}</b> — {warnung.reason || t.dateien.nichtBeschreibbar}
    </p>
  {/each}

  <nav class="krumen" aria-label={t.dateien.name}>
    {#each daten.krumen as krume, i (krume.path)}
      <!-- Der Trenner erst ab dem zweiten Glied: Das erste IST der Schrägstrich,
           und „/ / tmp" sieht wie ein Fehler in der Pfadzusammensetzung aus. -->
      {#if i > 1}<span class="trenner" aria-hidden="true">/</span>{/if}
      <button
        type="button"
        class:jetzt={krume.path === daten.pfad}
        onclick={() => hinein(krume.path)}
      >
        {krume.name}
      </button>
    {/each}
  </nav>

  <div class="werkzeuge">
    <!-- Ein Formular und kein Tippfilter: Die Suche läuft über den Baum und
         braucht Sekunden. Enter ist der Auslöser, und das ist ehrlicher als ein
         Feld, das bei jedem Buchstaben eine Anfrage schickt. -->
    <form class="suche" onsubmit={suchen}>
      <label>
        <span class="nur-vorlese">{t.dateien.suchenHier}</span>
        <input
          bind:value={suchfeld}
          type="search"
          placeholder={t.dateien.suchen}
          autocomplete="off"
          spellcheck="false"
        />
      </label>
      <button type="submit" class="knopf leise klein">{t.dateien.suchen}</button>
      {#if begriff}
        <button type="button" class="knopf leise klein" onclick={sucheBeenden}>
          {t.dateien.suchenBeenden}
        </button>
      {/if}
    </form>

    <div class="schub"></div>

    <label class="schalter">
      <input type="checkbox" checked={versteckt} onchange={versteckteUmschalten} />
      {t.dateien.versteckte}
    </label>

    {#if daten.eltern}
      <button type="button" class="knopf leise klein" onclick={() => hinein(daten.eltern)}>
        ↑ {t.dateien.hoch}
      </button>
    {/if}
  </div>

  <!-- Anlegen und Hochladen beziehen sich auf den STEHENDEN Ordner und nicht auf
       die Auswahl — deshalb stehen sie hier und nicht im Inspektor. Sie sind nur
       da, wo sie gehen: Ein Konto mit Schreibrecht in einem nur lesbaren Bereich
       bekommt sie nicht angeboten. Während einer Suche fehlen sie ebenfalls — die
       Trefferliste steht quer über Ordner, und „hier anlegen" hätte dann kein
       eindeutiges Hier. -->
  {#if darfHierAnlegen}
    <div class="werkstatt">
      <button
        type="button"
        class="knopf leise klein"
        class:an={anlegen === "ordner"}
        onclick={() => {
          anlegen = anlegen === "ordner" ? "" : "ordner";
          neuerName = "";
        }}
      >
        + {t.dateien.neuerOrdner}
      </button>
      <button
        type="button"
        class="knopf leise klein"
        class:an={anlegen === "datei"}
        onclick={() => {
          anlegen = anlegen === "datei" ? "" : "datei";
          neuerName = "";
        }}
      >
        + {t.dateien.neueDatei}
      </button>

      <span class="trennstrich" aria-hidden="true"></span>

      <label class="hochladen">
        <span class="nur-vorlese">{t.dateien.hochladenTitel}</span>
        <!-- Ohne Formular: Der Aufruf schickt FormData über fetch, weil der
             Handler den Körper Teil für Teil streamt. Ein normales Formular zöge
             eine Datei von zwei Gigabyte durch den Formularparser. -->
        <input bind:this={dateifeld} type="file" multiple onchange={hochladen} />
      </label>
      <label class="schalter">
        <input type="checkbox" bind:checked={ueberschreiben} />
        {t.dateien.ueberschreiben}
      </label>
      {#if laufendeHandlung === "upload"}
        <span class="detail">{t.dateien.hochladenLaeuft}</span>
      {/if}
    </div>

    {#if anlegen}
      <form class="maske" onsubmit={ordnerAnlegen}>
        <label>
          <span class="nur-vorlese">{t.dateien.namePlatzhalter}</span>
          <!-- autofocus ist hier richtig: Die Maske erscheint auf einen Klick,
               und der nächste Schritt ist immer das Tippen des Namens. -->
          <!-- svelte-ignore a11y_autofocus -->
          <input
            bind:value={neuerName}
            type="text"
            autocomplete="off"
            spellcheck="false"
            autofocus
            placeholder={anlegen === "ordner" ? t.dateien.neuerOrdner : t.dateien.neueDatei}
          />
        </label>
        <button type="submit" class="knopf klein" disabled={!neuerName.trim() || laufendeHandlung !== ""}>
          {t.dateien.anlegen}
        </button>
        <button type="button" class="knopf leise klein" onclick={() => (anlegen = "")}>
          {t.dateien.abbrechen}
        </button>
      </form>
    {/if}
  {:else if darfSchreiben && daten.ordner.writable === false && !begriff}
    <p class="detail">{t.dateien.nichtBeschreibbar}</p>
  {/if}

  {#if handlungMeldung && !meldungImInspektor}
    <p class="band gut" role="status">{handlungMeldung}</p>
  {/if}
  {#if handlungFehler && !meldungImInspektor}
    <p class="band schlecht" role="alert">{handlungFehler}</p>
  {/if}

  {#if begriff}
    <p class="band info" role="status">
      {t.dateien.suchergebnis(begriff, daten.gesamt)}
      {#if daten.gekuerzt}
        — {daten.gekuerzt_grund}
      {/if}
    </p>
  {:else if daten.gekuerzt}
    <p class="band warn" role="status">{daten.gekuerzt_grund}</p>
  {/if}

  {#if vorgang.job}
    <Vorgangsplatte {vorgang} />
  {/if}

  <!-- Der Editor steht über der Werkbank und ersetzt sie nicht: Der Krumenpfad
       bleibt oben, also auch der Weg zurück, und die Liste darunter zeigt weiter,
       wo man ist. Ein eigener Bildschirm hätte den Ort verloren. -->
  {#if bearbeitet}
    <div class="editorplatz">
      <!-- key auf den Pfad: Wechselt die Datei, wird die Komponente neu gebaut
           statt in ihrem Effekt umgeschaltet. Ein Editor, der seinen Inhalt
           tauscht, behält den Verlauf der vorigen Datei — und ein Strg+Z holte
           dann fremden Text zurück. -->
      {#key bearbeitet}
        <Editor
          pfad={bearbeitet}
          {darfSchreiben}
          schliessen={editorSchliessen}
          gespeichert={() => {
            void neuLaden();
            // Das Detail neu holen, wenn es dieselbe Datei zeigt: Größe und
            // Zeitpunkt darin sind nach dem Speichern andere.
            if (gewaehlt === bearbeitet) void detailHolen(gewaehlt);
          }}
        />
      {/key}
    </div>
  {/if}

  {#if fehler}
    <p class="band schlecht" role="alert">{fehler}</p>
  {/if}

  <div class="werkbank" class:allein={!gewaehlt}>
    <div class="tabelle-rahmen" class:blass={laedt}>
      <table class="tabelle">
        <thead>
          <tr>
            <th>
              <button type="button" class="spalte" onclick={() => sortieren("name")}>
                {t.dateien.name}{sortPfeil("name")}
              </button>
            </th>
            <th class="rechts">
              <button type="button" class="spalte" onclick={() => sortieren("size")}>
                {t.dateien.groesse}{sortPfeil("size")}
              </button>
            </th>
            <th>
              <button type="button" class="spalte" onclick={() => sortieren("time")}>
                {t.dateien.geaendert}{sortPfeil("time")}
              </button>
            </th>
            <th>{t.dateien.rechte}</th>
            <th>{t.dateien.eigentuemer}</th>
          </tr>
        </thead>
        <tbody>
          {#if daten.eintraege.length === 0}
            <tr>
              <td colspan="5" class="gedaempft">
                {begriff ? t.dateien.nichtsGefunden : t.dateien.leer}
              </td>
            </tr>
          {:else}
            {#each daten.eintraege as eintrag (eintrag.path)}
              <tr class:gewaehlt={eintrag.path === gewaehlt}>
                <td data-spalte={t.dateien.name}>
                  <!-- Ein eigener Behälter um alles, was zum Namen gehört. Unter
                       600 px ist die Zelle selbst ein Flexkasten mit der
                       Spaltenbeschriftung davor; ohne diesen Behälter wären Name,
                       Marke und Ort drei Geschwister in derselben Reihe, und der
                       Name wurde auf drei Zeilen gequetscht
                       („schlues|sel.geh|eim"). Gesehen hat das ein
                       Bildschirmfoto, nicht der DOM-Test — dort stand alles da. -->
                  <div class="namenszelle">
                    <span class="namenskopf">
                      <button
                        type="button"
                        class="zeile"
                        class:ordner={eintrag.kind === "ordner"}
                        onclick={() => waehlen(eintrag)}
                      >
                        <!-- Der Schrägstrich hinten und nicht vorn: „schreibbar/"
                             ist die Schreibweise, in der jeder einen Ordner
                             erkennt, und ein führender Strich sähe wie ein
                             absoluter Pfad aus. -->
                        {eintrag.name}{#if eintrag.kind === "ordner"}<span aria-hidden="true">/</span>{/if}
                      </button>
                      {#if eintrag.sensitive}
                        <span class="zustand warn" title={eintrag.sensitive_reason}>
                          <i aria-hidden="true"></i>{t.dateien.gesperrt}
                        </span>
                      {/if}
                    </span>
                    {#if eintrag.kind === "verweis"}
                      <span class="verweisziel {artKlasse(eintrag)}">
                        → {eintrag.link_target}
                      </span>
                    {/if}
                    <!-- Ein Suchergebnis steht quer über Unterordner. Ohne den Ort
                         wäre die Liste eine Sammlung gleichnamiger Dateien, von
                         denen keine auffindbar ist. -->
                    {#if begriff}
                      <span class="ort">{eintrag.path}</span>
                    {/if}
                  </div>
                </td>
                <td data-spalte={t.dateien.groesse} class="rechts zahl gedaempft">
                  {eintrag.groesse_text || "—"}
                </td>
                <td data-spalte={t.dateien.geaendert} class="gedaempft">
                  {eintrag.geaendert_text || "—"}
                </td>
                <td data-spalte={t.dateien.rechte} class="zahl gedaempft">
                  {eintrag.mode_octal}
                </td>
                <td data-spalte={t.dateien.eigentuemer} class="gedaempft">
                  {eintrag.owner}:{eintrag.group}
                </td>
              </tr>
            {/each}
          {/if}
        </tbody>
      </table>
    </div>

    {#if gewaehlt}
      <Inspektor
        titel={detail?.eintrag.name ?? gewaehlt}
        zustand={detail ? artKlasse(detail.eintrag) : ""}
        zustandText={detail?.eintrag.sensitive ? t.dateien.gesperrt : ""}
        marke={detail?.eintrag.art ?? ""}
        {schliessen}
      >
        {#snippet kinder()}
          {#if detailLaeuft && !detail}
            <p class="detail">{t.dateien.laedt}</p>
          {:else if detailFehler && !detail}
            <p class="warnung">{detailFehler}</p>
            <button class="knopf leise" onclick={() => detailHolen(gewaehlt)}>
              {t.fehler.erneut}
            </button>
          {:else if detail}
            <dl class="kv">
              <dt>{t.dateien.name}</dt>
              <dd class="pfad">{detail.eintrag.path}</dd>
              <dt>{t.dateien.art}</dt>
              <dd>{detail.eintrag.art}</dd>
              {#if detail.eintrag.kind !== "ordner"}
                <dt>{t.dateien.groesse}</dt>
                <dd class="zahl">{detail.eintrag.groesse_text}</dd>
              {/if}
              {#if detail.mass_text}
                <dt>{t.dateien.inhaltZaehlung}</dt>
                <dd>{detail.mass_text}</dd>
              {/if}
              <dt>{t.dateien.geaendert}</dt>
              <dd>{detail.eintrag.geaendert_text || "—"}</dd>
              <dt>{t.dateien.eigentuemer}</dt>
              <dd>{detail.eintrag.owner}:{detail.eintrag.group}</dd>
              <dt>{t.dateien.rechte}</dt>
              <dd class="zahl">{detail.rechte.octal} · {detail.rechte.symbolic}</dd>
              {#if detail.eintrag.link_target}
                <dt>{t.dateien.verweisAuf}</dt>
                <dd class="pfad">{detail.eintrag.link_target}</dd>
              {/if}
            </dl>

            {#if detail.eintrag.link_broken}
              <p class="warnung">{t.dateien.verweisGebrochen}</p>
            {/if}
            {#if detail.eintrag.sensitive}
              <p class="warnung">
                {detail.eintrag.sensitive_reason || t.dateien.gesperrtErklaerung}
              </p>
            {/if}

            <!-- Die Rechte in Worten. „0755“ sagt nur denen etwas, die es ohnehin
                 wissen; „Eigentümer — darf lesen, ändern und ausführen“ ist die
                 Auskunft, die eine Entscheidung trägt.
                 Zwei Spalten und kein Satz: Der Text aus privops ist als Wert
                 einer Zeile formuliert („darf lesen“), nicht als Prädikat zu
                 einem Subjekt. Aneinandergereiht ergäbe er „alle anderen darf
                 lesen“ — grammatisch falsch, und die Ursache läge dann in der
                 Darstellung und nicht im Text. -->
            <div class="rechteblock">
              <b>{t.dateien.rechte}</b>
              <dl>
                {#each detail.rechte.roles as rolle (rolle.key)}
                  <dt>{rolle.label}</dt>
                  <dd>{rolle.text}</dd>
                {/each}
              </dl>
              {#each detail.rechte.specials.filter((s) => s.set) as sonder (sonder.key)}
                <p class="sonder"><b>{sonder.label}</b> — {sonder.text}</p>
              {/each}
            </div>

            <div class="aktionen">
              {#if kann("oeffnen")}
                <button
                  type="button"
                  class="knopf"
                  onclick={() => hinein(detail!.eintrag.path)}
                >
                  {t.dateien.handgriff.oeffnen}
                </button>
              {/if}
              <!-- Ein echter Verweis und kein fetch: Der Browser soll den
                   Download-Manager bekommen und nicht der Speicher des Tabs eine
                   Datei von zwei Gigabyte. Deshalb auch kein onclick-Abfang. -->
              {#if kann("herunterladen")}
                <a class="knopf leise" href={api.herunterladen(detail.eintrag.path)}>
                  {t.dateien.handgriff.herunterladen}
                </a>
              {/if}
              {#if kann("archiv")}
                <a class="knopf leise" href={api.archiv(detail.eintrag.path)}>
                  {t.dateien.handgriff.archiv}
                </a>
              {/if}
              <!-- „bearbeiten" steht bei den lesenden Handgriffen und nicht bei den
                   verändernden: Den Editor zu ÖFFNEN verändert nichts. Angeboten
                   wird er nur, wo er auch speichern könnte und wo die Datei unter
                   die Größengrenze fällt — das entscheidet der Server. -->
              {#if kann("bearbeiten")}
                <button
                  type="button"
                  class="knopf"
                  class:leise={bearbeitet !== detail.eintrag.path}
                  onclick={() => bearbeiten(detail!.eintrag.path)}
                >
                  {t.dateien.handgriff.bearbeiten}
                </button>
              {/if}
            </div>

            {#if !darfSchreiben}
              <p class="detail">{t.dateien.nurLesen}</p>
            {:else}
              <!-- Die verändernden Handgriffe. Angezeigt wird nur, was der Server
                   in `aktionen` genannt hat — die Bedienhilfe aus dateiAktionen().
                   Verbindlich bleibt die Pfadwache; hier steht nur, was gehen
                   kann. -->
              <div class="aktionen">
                {#if kann("kopieren")}
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={laufendeHandlung !== ""}
                    onclick={() => (zielwahl = "copy")}
                  >
                    {t.dateien.handgriff.kopieren}
                  </button>
                {/if}
                {#if kann("verschieben")}
                  <button
                    type="button"
                    class="knopf leise"
                    disabled={laufendeHandlung !== ""}
                    onclick={() => (zielwahl = "move")}
                  >
                    {t.dateien.handgriff.verschieben}
                  </button>
                {/if}
                {#if kann("umbenennen")}
                  <button
                    type="button"
                    class="knopf leise"
                    class:an={umbenennenOffen}
                    onclick={() => {
                      umbenennenOffen = !umbenennenOffen;
                      namensfeld = detail!.eintrag.name;
                    }}
                  >
                    {t.dateien.handgriff.umbenennen}
                  </button>
                {/if}
                {#if kann("rechte")}
                  <button
                    type="button"
                    class="knopf leise"
                    class:an={rechteOffen}
                    onclick={() => (rechteOffen ? (rechteOffen = false) : rechteOeffnen())}
                  >
                    {t.dateien.handgriff.rechte}
                  </button>
                {/if}
                {#if kann("loeschen")}
                  <button
                    type="button"
                    class="knopf gefahr"
                    disabled={laufendeHandlung !== ""}
                    onclick={loeschen}
                  >
                    {t.dateien.handgriff.loeschen}
                  </button>
                {/if}
              </div>

              {#if umbenennenOffen}
                <form class="maske" onsubmit={umbenennen}>
                  <label>
                    <span class="nur-vorlese">{t.dateien.umbenennenTitel}</span>
                    <!-- svelte-ignore a11y_autofocus -->
                    <input
                      bind:value={namensfeld}
                      type="text"
                      autocomplete="off"
                      spellcheck="false"
                      autofocus
                    />
                  </label>
                  <button
                    type="submit"
                    class="knopf klein"
                    disabled={!namensfeld.trim() ||
                      namensfeld.trim() === detail.eintrag.name ||
                      laufendeHandlung !== ""}
                  >
                    {t.dateien.handgriff.umbenennen}
                  </button>
                  <button
                    type="button"
                    class="knopf leise klein"
                    onclick={() => (umbenennenOffen = false)}
                  >
                    {t.dateien.abbrechen}
                  </button>
                </form>
              {/if}

              {#if rechteOffen}
                <form class="rechtemaske" onsubmit={rechteAnwenden}>
                  <label>
                    <span>{t.dateien.rechteOktal}</span>
                    <input
                      bind:value={rechtefeld}
                      type="text"
                      inputmode="numeric"
                      autocomplete="off"
                      spellcheck="false"
                      size="5"
                    />
                  </label>
                  <!-- Auswahlfelder und kein Freitext: Ein Tippfehler kam sonst
                       als „Benutzer gibt es nicht" zurück. Die Namen liefert der
                       Server; ist die Liste leer, fehlt das Feld statt einer
                       Auswahl ohne Auswahl. -->
                  {#if detail.benutzer.length > 0}
                    <label>
                      <span>{t.dateien.eigentuemer}</span>
                      <select bind:value={besitzerfeld}>
                        {#each detail.benutzer as b (b)}
                          <option value={b}>{b}</option>
                        {/each}
                      </select>
                    </label>
                  {/if}
                  {#if detail.gruppen.length > 0}
                    <label>
                      <span>{t.dateien.gruppe}</span>
                      <select bind:value={gruppenfeld}>
                        {#each detail.gruppen as g (g)}
                          <option value={g}>{g}</option>
                        {/each}
                      </select>
                    </label>
                  {/if}
                  {#if detail.eintrag.kind === "ordner"}
                    <label class="schalter">
                      <input type="checkbox" bind:checked={rekursivfeld} />
                      {t.dateien.rekursiv}
                    </label>
                  {/if}
                  <div class="aktionen">
                    <button type="submit" class="knopf klein" disabled={laufendeHandlung !== ""}>
                      {t.dateien.rechteAnwenden}
                    </button>
                    <button
                      type="button"
                      class="knopf leise klein"
                      onclick={() => (rechteOffen = false)}
                    >
                      {t.dateien.abbrechen}
                    </button>
                  </div>
                </form>
              {/if}

              {#if handlungMeldung && meldungImInspektor}
                <p class="meldung" role="status">{handlungMeldung}</p>
              {/if}
              {#if handlungFehler && meldungImInspektor}
                <p class="warnung" role="alert">{handlungFehler}</p>
              {/if}

              {#if !detail.eintrag.writable}
                <p class="detail">{t.dateien.nichtBeschreibbar}</p>
              {/if}
            {/if}
          {/if}
        {/snippet}
      </Inspektor>
    {/if}
  </div>

  <p class="fuss">
    {t.dateien.inhalt(daten.zaehler.ordner, daten.zaehler.dateien, daten.zaehler.bytes_text)}
    {#if daten.zaehler.gesperrt > 0}
      · {daten.zaehler.gesperrt} {t.dateien.gesperrt}
    {/if}
    <!-- Der Weg in die alte Oberfläche bleibt sichtbar, solange der Editor und
         die Schreibvorgänge dort noch mehr können. Ihn zu verschweigen hieße,
         jemanden mit einer halben Fläche allein zu lassen. -->
    ·
    <!-- Der Weg in die eingefrorene alte Ansicht. Sie liegt seit dem Umschalten
         unter /alt/ und ist ein FREMDES Ziel: Der eigene Router hat für sie keine
         Ansicht, deshalb steht /alt/ in der Liste in weg.svelte.ts und der
         Browser navigiert dorthin. Der Verweis fällt mit dem Abbau der alten
         Oberfläche. -->
    <a href={`/alt/files?path=${encodeURIComponent(daten.pfad)}`}>{t.dateien.alteAnsicht}</a>
  </p>
{/if}

{#if zielwahl && detail}
  <Zielwahl
    start={detail.ordner}
    titel={zielwahl === "move" ? t.dateien.zielwahlVerschieben : t.dateien.zielwahlKopieren}
    knopf={zielwahl === "move"
      ? t.dateien.handgriff.verschieben
      : t.dateien.handgriff.kopieren}
    waehlen={zielGewaehlt}
    abbrechen={() => (zielwahl = "")}
  />
{/if}

{#if offeneFrage}
  <Rueckfrage
    frage={offeneFrage.frage}
    laeuft={laufendeHandlung !== ""}
    bestaetigen={offeneFrageBestaetigen}
    abbrechen={() => (offeneFrage = null)}
  />
{/if}

<style>
  .bereiche {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
    margin-bottom: 0.7rem;
  }

  .etikett {
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-right: 0.2rem;
  }

  .bereiche button {
    background: var(--surface);
    border: 1px solid var(--line);
    border-radius: 7px;
    padding: 0.28rem 0.55rem;
    color: var(--tx-mut);
    font: 0.76rem var(--mono);
    cursor: pointer;
  }

  /* Ein beschreibbarer Bereich ist etwas anderes als ein nur sichtbarer, und der
   * Unterschied entscheidet, ob ein Handgriff angeboten wird. Er gehört deshalb
   * an den Einstiegspunkt und nicht erst in die Fehlermeldung. */
  .bereiche button.schreib {
    color: var(--tx);
  }

  .bereiche button.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .krumen {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.1rem;
    margin-bottom: 0.7rem;
    font: 0.82rem var(--mono);
  }

  .krumen button {
    background: none;
    border: none;
    padding: 0.1rem 0.2rem;
    color: var(--tx-mut);
    font: inherit;
    cursor: pointer;
    border-radius: 4px;
  }

  .krumen button:hover {
    color: var(--accent);
  }

  .krumen button.jetzt {
    color: var(--tx);
    font-weight: 650;
  }

  .krumen .trenner {
    color: var(--tx-faint);
  }

  .werkzeuge {
    display: flex;
    align-items: center;
    gap: 0.7rem;
    flex-wrap: wrap;
    margin-bottom: 0.8rem;
  }

  .suche {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
  }

  .suche input {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.35rem 0.7rem;
    color: var(--tx);
    font: 0.84rem var(--sans);
    width: 14rem;
    max-width: 100%;
  }

  .schalter {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    color: var(--tx-mut);
    font-size: 0.78rem;
    cursor: pointer;
  }

  /* Die Werkstatt: was sich auf den STEHENDEN Ordner bezieht. Getrennt von der
   * Werkzeugzeile darüber (Suche, Sortierung), weil das eine liest und das andere
   * schreibt — und weil die Zeile ganz fehlt, wo nicht geschrieben werden darf. */
  .werkstatt {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
    margin-bottom: 0.7rem;
    padding-bottom: 0.7rem;
    border-bottom: 1px solid var(--line);
  }

  .trennstrich {
    width: 1px;
    height: 1.1rem;
    background: var(--line2);
  }

  .hochladen input[type="file"] {
    color: var(--tx-mut);
    font: 0.76rem var(--sans);
    max-width: 100%;
  }

  .maske {
    display: flex;
    align-items: center;
    gap: 0.4rem;
    flex-wrap: wrap;
    margin-bottom: 0.7rem;
  }

  .maske input[type="text"] {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 8px;
    padding: 0.32rem 0.6rem;
    color: var(--tx);
    font: 0.82rem var(--mono);
    width: 14rem;
    max-width: 100%;
  }

  .rechtemaske {
    display: grid;
    gap: 0.5rem;
    background: var(--bg);
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.7rem 0.8rem;
  }

  .rechtemaske > label {
    display: grid;
    grid-template-columns: 7rem 1fr;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  .rechtemaske > label.schalter {
    grid-template-columns: auto 1fr;
  }

  .rechtemaske input[type="text"],
  .rechtemaske select {
    background: var(--surface);
    border: 1px solid var(--line2);
    border-radius: 7px;
    padding: 0.28rem 0.5rem;
    color: var(--tx);
    font: 0.8rem var(--mono);
    min-width: 0;
  }

  .knopf.an {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .band {
    border: 1px solid var(--line);
    border-radius: 8px;
    padding: 0.5rem 0.7rem;
    margin-bottom: 0.7rem;
    font-size: 0.8rem;
    color: var(--tx-mut);
  }

  .band.warn {
    border-color: var(--accent-dim);
    color: var(--accent);
  }

  .band.gut {
    border-color: var(--ok);
    color: var(--ok);
  }

  .band.schlecht {
    border-color: var(--err);
    color: var(--err);
  }

  .band b {
    font-family: var(--mono);
  }

  /* Während eine neue Liste geholt wird, bleibt die alte stehen und wird blass.
   * Sie durch „lädt …" zu ersetzen ließe die Seite bei jedem Ordnerwechsel
   * springen — und der Krumenpfad, an dem man sich orientiert, verschwände mit. */
  .blass {
    opacity: 0.5;
  }

  .spalte {
    background: none;
    border: none;
    padding: 0;
    color: inherit;
    font: inherit;
    cursor: pointer;
  }

  .spalte:hover {
    color: var(--accent);
  }

  .zeile {
    background: none;
    border: none;
    padding: 0;
    color: var(--tx);
    font: 0.82rem var(--mono);
    cursor: pointer;
    text-align: left;
    overflow-wrap: anywhere;
  }

  .zeile:hover {
    color: var(--accent);
  }

  .zeile.ordner {
    color: var(--accent);
    font-weight: 650;
  }

  :global(table.tabelle tr.gewaehlt) {
    background: var(--surface2);
  }

  .editorplatz {
    margin-bottom: 0.8rem;
    min-width: 0;
  }

  .namenszelle {
    display: grid;
    gap: 0.15rem;
    min-width: 0;
  }

  /* flex-wrap statt eines Umbruchs je Bildschirmbreite: Die Marke rutscht auf
   * ihre eigene Zeile, wenn der Name den Platz braucht — und bleibt daneben,
   * wenn er kurz ist. Eine Regel für beide Breiten. */
  .namenskopf {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.45rem;
    min-width: 0;
  }

  .verweisziel,
  .ort {
    display: block;
    color: var(--tx-faint);
    font: 0.72rem var(--mono);
    overflow-wrap: anywhere;
  }

  .verweisziel.schlecht {
    color: var(--err);
  }

  .rechts {
    text-align: right;
  }

  .rechteblock b {
    display: block;
    font-size: 0.68rem;
    font-weight: 650;
    letter-spacing: 0.1em;
    text-transform: uppercase;
    color: var(--tx-faint);
    margin-bottom: 0.3rem;
  }

  .rechteblock dl {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 0.2rem 0.7rem;
    font-size: 0.8rem;
  }

  .rechteblock dt {
    color: var(--tx);
    white-space: nowrap;
  }

  .rechteblock dd {
    color: var(--tx-mut);
  }

  .rechteblock .sonder {
    margin-top: 0.4rem;
    font-size: 0.76rem;
    color: var(--accent);
  }

  .aktionen {
    display: flex;
    gap: 0.45rem;
    flex-wrap: wrap;
    /* Ohne min-width: 0 kann der Kasten nicht schrumpfen, und im Gitter der
     * Werkbank wächst dann die ganze Spalte über das Fenster hinaus. */
    min-width: 0;
  }

  .fuss {
    margin-top: 0.8rem;
    color: var(--tx-faint);
    font: 0.76rem var(--mono);
  }

  .hinweis {
    display: grid;
    place-items: center;
    gap: 0.7rem;
    padding: 2.5rem 1rem;
    text-align: center;
  }

  .detail {
    color: var(--tx-mut);
    font: 0.82rem var(--mono);
  }

  /* overflow-wrap: anywhere ist hier keine Kosmetik. Die Meldungen dieses Moduls
   * enthalten PFADE („Nach /var/lib/sehr/tiefer/ordner kopiert."), und ein Pfad
   * ohne Trennstelle hat eine große Mindestbreite. Ohne diese Zeile wuchs die
   * Spalte der Werkbank über das Fenster hinaus: Der Inspektor wurde rechts
   * abgeschnitten, und die Schaltfläche „löschen" lag außerhalb des Bildes.
   * Gesehen hat das ein Bildschirmfoto — gemessen wird es jetzt im Browsertest. */
  .meldung,
  .warnung {
    font-size: 0.82rem;
    overflow-wrap: anywhere;
    min-width: 0;
  }

  .meldung {
    color: var(--ok);
  }

  .warnung {
    color: var(--err);
  }
</style>
