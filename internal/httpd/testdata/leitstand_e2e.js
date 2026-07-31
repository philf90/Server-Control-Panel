// Fährt die neue Oberfläche in einem echten Browser.
//
// Vier Fragen, die kein Go-Test beantworten kann:
//
//  1. Montiert die Anwendung überhaupt? Ein Go-Test sieht die Hülle mit einem
//     leeren <div id="app">; ob Svelte darin etwas erzeugt, sagt nur der
//     Browser. Ein Laufzeitfehler im Bundle bliebe sonst unsichtbar.
//  2. Verwirft die Content-Security-Policy etwas? Das Bundle ist eine externe
//     Datei und ein Modulskript, das Stylesheet ebenso — aber genau an dieser
//     Stelle ist das Projekt schon zweimal gescheitert (Auslastungsbalken in
//     rc.5, CodeMirror im Dateimanager).
//  3. Ist der Strich der Verläufe gleichmäßig? Die Kachel ist neu gebaut, die
//     Falle dieselbe: 100 viewBox-Einheiten werden waagerecht stärker gestreckt
//     als senkrecht. Ohne vector-effect zieht der Browser die Strichstärke mit.
//  4. Trägt der Live-Kanal? Die große Zahl kommt beim Aufbau aus /api/v1/overview
//     und wird danach aus dem SSE-Strom fortgeschrieben.
//  5. Werden Tabellen unter 600 Pixeln zu Karten? `overflow-x: auto` allein
//     genügt nicht — eine seitlich scrollende Tabelle ist bedienbar, aber man
//     sieht ihr nicht an, dass rechts noch etwas steht. Gemessen wird, ob der
//     Seitenkörper waagerecht scrollt und ob die Spaltennamen als Beschriftung
//     erscheinen.
//  6. Klappen die weiteren Einhängepunkte einer Platte auf?
//
// Aufruf über TestLeitstandBrowser. Erwartete Umgebung:
//   ASYLUM_E2E_URL, ASYLUM_E2E_COOKIE, ASYLUM_CHROMIUM
//   ASYLUM_NODE_PATH (Verzeichnis mit playwright)

const { chromium } = require("playwright");

const gesammelt = [];

// klickeInTabelle wählt die erste Zeile DER Tabelle, die eine bestimmte Spalte
// hat — und nicht der ersten Tabelle auf der Seite.
//
// Der Anlass steht in der Fassung 0.5: Die Docker-Seite trug erst eine Tabelle,
// dann vier, dann fünf. Jeder Selektor der Form „.werkbank .tabelle" prüfte ab
// dem nächsten Schritt etwas anderes als beim Schreiben gemeint — und tat es
// still, weil eine falsche Tabelle immer noch eine Tabelle ist.
async function klickeInTabelle(seite, spalte) {
  const getroffen = await seite.evaluate((spalte) => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === spalte),
    );
    const knopf = tab?.querySelector('tbody tr [data-spalte="Name"] button');
    if (!knopf) return false;
    knopf.click();
    return true;
  }, spalte);
  if (!getroffen) throw new Error(`keine Tabelle mit der Spalte ${spalte}`);
}

// bildschirmfoto nimmt eine Aufnahme — mit Frist, mit angehaltenen Animationen
// und ohne den Lauf scheitern zu lassen.
//
// Alle drei Eigenschaften stammen aus einem Befund: Eine Aufnahme der
// Docker-Seite mit offenem CodeMirror-Editor kehrte NIE zurück. Playwright
// versteckt vor jeder Aufnahme den Textcursor und wartet dafür auf ein ruhiges
// Bild; ein blinkender Cursor ist eine endlose CSS-Animation, und die beiden
// zusammen ergaben einen Lauf, der ohne Fehler und ohne Frist stand, bis die
// Testuhr ablief. „animations: disabled" hält die Animation an, die Frist macht
// aus einem Hänger einen Fehler, und der Fang sorgt dafür, dass ein
// Diagnosebild nie eine Prüfung kippt: Bildschirmfotos sind eine Hilfe beim
// Nachsehen, keine Zusicherung.
async function bildschirmfoto(seite, name, opt = {}) {
  if (!process.env.ASYLUM_E2E_SHOTS) return;
  try {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/${name}.png`,
      animations: "disabled",
      caret: "initial",
      timeout: 15000,
      ...opt,
    });
  } catch (e) {
    gesammelt.push(`Bildschirmfoto ${name} nicht möglich: ${e.message}`);
  }
}

async function main() {
  const basis = process.env.ASYLUM_E2E_URL;
  const cookie = process.env.ASYLUM_E2E_COOKIE;

  const browser = await chromium.launch({
    executablePath: process.env.ASYLUM_CHROMIUM,
    args: ["--no-sandbox"],
  });
  const kontext = await browser.newContext({ ignoreHTTPSErrors: true });

  const [name, wert] = cookie.split("=");
  const url = new URL(basis);
  await kontext.addCookies([
    {
      name,
      value: wert,
      domain: url.hostname,
      path: "/",
      httpOnly: true,
      secure: false,
      sameSite: "Strict",
    },
  ]);

  const seite = await kontext.newPage();
  const verstoesse = [];
  const fehler = [];

  seite.on("console", (m) => {
    const text = m.text();
    gesammelt.push(`${m.type()}: ${text}`);
    if (/Content Security Policy|Refused to/i.test(text)) {
      verstoesse.push(text);
    }
  });
  // Ein Laufzeitfehler im Bundle erscheint nicht als Konsolenmeldung, sondern
  // als pageerror. Ohne diesen Mitleser wäre eine leere Seite ein grüner Test.
  seite.on("pageerror", (e) => {
    fehler.push(String(e));
    gesammelt.push(`pageerror: ${e}`);
  });
  // Fehlende Antworten mit Adresse festhalten: "404" allein sagt nicht, ob ein
  // Asset fehlt (schwer) oder der Browser nach einem Favicon fragt (harmlos).
  // Alle angefragten Adressen mitschreiben. Gebraucht wird das für eine Frage,
  // die man dem fertigen Bild nicht ansehen kann: Hat die Seite den
  // Ereignisstrom eines Vorgangs überhaupt geöffnet? Die Zeilen stünden auch da,
  // wenn sie nur die Ressource abgefragt hätte — bei einem Vorgang, der eine
  // Viertelstunde läuft, wäre das der Unterschied zwischen einer Quittung und
  // einem Standbild.
  const angefragt = [];
  seite.on("request", (r) => angefragt.push(r.url()));

  const fehlend = [];
  // absichtlich sammelt Pfade, an denen dieser Lauf SELBST eine Ablehnung
  // provoziert — etwa einen falschen Bestätigungscode. Eine solche Ablehnung ist
  // das geprüfte Verhalten und keine fehlende Antwort. Ausgenommen wird der Pfad
  // und nicht der Statuscode: „400 überall in Ordnung" machte den Sammler blind
  // für die Fehler, um die es ihm geht.
  const absichtlich = [];
  seite.on("response", (r) => {
    // Zwei Statuscodes sind KEINE fehlende Antwort, sondern eine Auskunft, um die
    // ausdrücklich gebeten wurde:
    //
    //   409 — unbestätigt; der Handler führt nichts aus und schickt stattdessen
    //         den Text der Rückfrage (api_v1_bestaetigung.go).
    //   412 — die Datei wurde von außen geändert; der Editor bekommt den fremden
    //         Stand statt ihn zu überschreiben (api_v1_dateien_editor.go).
    //
    // Sie hier mitzuzählen wäre Rauschen, das eine echte 404 verdeckt.
    if (absichtlich.some((p) => r.url().includes(p))) return;
    if (r.status() >= 400 && r.status() !== 409 && r.status() !== 412) {
      fehlend.push(`${r.status()} ${r.url()}`);
      gesammelt.push(`response: ${r.status()} ${r.url()}`);
    }
  });

  // Bewusst nicht "networkidle": Die Anwendung hält den Live-Kanal dauerhaft
  // offen, das Netz wird also nie ruhig. Gewartet wird stattdessen unten auf
  // die Kachel — auf das Ergebnis, nicht auf einen Zustand des Netzes.
  await seite.goto(`${basis}/`, { waitUntil: "domcontentloaded" });

  // 1. Montiert die Anwendung? Gewartet wird auf die Kachel und nicht auf einen
  //    Zeitraum: Ein fester Schlaf wäre auf einer langsamen Maschine zu kurz
  //    und auf einer schnellen Zeitverschwendung.
  await seite.waitForSelector(".karte", { timeout: 10000 });

  const montiert = await seite.evaluate(() => {
    const app = document.getElementById("app");
    return {
      kinder: app ? app.children.length : 0,
      kacheln: document.querySelectorAll(".karte").length,
      schale: document.querySelectorAll(".schale").length,
      statusband: document.querySelectorAll(".statusband").length,
      seitenleiste: document.querySelectorAll(".seitenleiste").length,
      protokoll: document.querySelectorAll(".protokollzeile").length,
      // Der Stil muss angekommen sein: Kommt die Datei nicht durch die
      // Richtlinie, ist die Kachel unbeholfen weiß statt dunkel.
      kartenFarbe: getComputedStyle(document.querySelector(".karte")).backgroundColor,
    };
  });

  // Urteil und Handlungsbedarf: Beide kommen aus einem eigenen Aufruf, der
  // systemctl anfasst — sie erscheinen also nach den Kacheln.
  await seite.waitForSelector(".urteil b", { timeout: 10000 });
  const uebersicht = await seite.evaluate(() => {
    const urteil = document.querySelector(".urteil");
    const punkte = [...document.querySelectorAll(".handlungsbedarf li")];
    return {
      urteilText: urteil ? urteil.textContent.trim().replace(/\s+/g, " ") : "",
      urteilUnbekannt: urteil ? urteil.classList.contains("unbekannt") : null,
      punkte: punkte.length,
      // Jeder Punkt trägt einen Weg dorthin, wo man ihn behebt.
      punkteMitGriff: punkte.filter((li) => li.querySelector("a.griff")).length,
      tabellen: document.querySelectorAll("table.tabelle").length,
      dateisystemZeilen: document.querySelectorAll("table.tabelle tbody tr").length,
    };
  });

  // Sitzt jeder Tabellentitel ÜBER seiner Tabelle und nicht daneben?
  //
  // Diese Messung gibt es, weil der Fehler passiert ist: Jede Tabellen-
  // komponente gab zwei Wurzelelemente aus (Titel und Rahmen), und im Gitter
  // der Übersicht ist jedes Wurzelelement eine eigene Zelle — der Titel landete
  // in der linken Spalte, die Tabelle in der rechten. Der DOM-Test war grün,
  // weil beide Elemente vorhanden waren. Gesehen hat es erst ein Bildschirmfoto,
  // und danach war klar, was zu messen ist: dieselbe linke Kante, Titel oben.
  const titelSitz = await seite.evaluate(() => {
    return [...document.querySelectorAll(".tabelle-titel")].map((titel) => {
      const rahmen = titel.parentElement.querySelector(".tabelle-rahmen");
      if (!rahmen) return { name: titel.textContent.trim(), gefunden: false };
      const t = titel.getBoundingClientRect();
      const r = rahmen.getBoundingClientRect();
      return {
        name: titel.textContent.trim(),
        gefunden: true,
        gleicheKante: Math.abs(t.left - r.left) <= 1,
        titelDarueber: t.bottom <= r.top + 1,
      };
    });
  });

  // Wird eine Tabelle beschnitten? Der Rahmen hatte overflow: hidden, und die
  // letzte Spalte war halb abgeschnitten — ohne Balken, also ohne Hinweis. Gemessen
  // wird deshalb, ob der Inhalt in den Rahmen passt; passt er nicht, muss der
  // Rahmen wenigstens scrollen können.
  const rahmenSitz = await seite.evaluate(() => {
    return [...document.querySelectorAll(".tabelle-rahmen")].map((r) => ({
      inhaltBreite: r.scrollWidth,
      rahmenBreite: r.clientWidth,
      scrollbar: getComputedStyle(r).overflowX,
    }));
  });

  // 6. Die weiteren Einhängepunkte aufklappen.
  let zweige = { vorher: 0, nachher: 0 };
  const mehr = await seite.$("button.mehr");
  if (mehr) {
    zweige.vorher = await seite.evaluate(() => document.querySelectorAll("tr.zweig").length);
    await mehr.click();
    await seite.waitForSelector("tr.zweig", { timeout: 3000 }).catch(() => {});
    zweige.nachher = await seite.evaluate(() => document.querySelectorAll("tr.zweig").length);
  }

  // 3. Strich und Endpunkt vermessen — dieselbe Messung wie bei der alten
  //    Übersicht, weil die Kachel neu gebaut ist.
  const strich = await seite.evaluate(() => {
    const svg = document.querySelector("svg.verlauf");
    if (!svg) return null;
    const kasten = svg.getBoundingClientRect();
    const linie = svg.querySelector("path.linie");
    const punkt = svg.querySelector("path.punkt");
    return {
      svgBreite: kasten.width,
      effekt: getComputedStyle(linie).getPropertyValue("vector-effect").trim(),
      punktEffekt: getComputedStyle(punkt).getPropertyValue("vector-effect").trim(),
      strichbreite: getComputedStyle(linie).getPropertyValue("stroke-width").trim(),
    };
  });

  // 4. Der Live-Kanal: Der Punkt im Statusband wird grün, sobald EventSource
  //    offen ist.
  let live = false;
  try {
    await seite.waitForFunction(
      () => document.querySelector(".statusband .live.an") !== null,
      { timeout: 10000 },
    );
    live = true;
  } catch {
    live = false;
  }

  // Die Ablesung am Verlauf: Zeiger über die Kachel, Kasten muss erscheinen.
  let ablesung = "";
  if (strich && strich.svgBreite > 0) {
    const svg = await seite.$("svg.verlauf");
    const kasten = await svg.boundingBox();
    await seite.mouse.move(kasten.x + kasten.width * 0.6, kasten.y + kasten.height / 2);
    try {
      await seite.waitForSelector(".ablesung", { timeout: 3000 });
      ablesung = (await seite.textContent(".ablesung")).trim();
    } catch {
      ablesung = "";
    }
  }

  // Die Befehlspalette. Geprüft wird der Weg, den jemand mit der Tastatur nimmt:
  // öffnen, tippen, mit dem Pfeil wählen, Enter. Ein DOM-Test sieht davon
  // nichts — ob ⌘K überhaupt ankommt und ob der Fokus im Feld landet, sagt nur
  // der Browser.
  const palette = { schritte: [] };
  await seite.keyboard.press("Control+k");
  await seite.waitForSelector('[role="dialog"]', { timeout: 3000 });
  palette.schritte.push("mit Strg+K geöffnet");

  palette.fokusImFeld = await seite.evaluate(
    () => document.activeElement?.classList.contains("feld") ?? false,
  );
  palette.zieleGesamt = await seite.evaluate(
    () => document.querySelectorAll('[role="option"]').length,
  );
  // Und dieselbe Zahl aus der Seitenleiste. Verglichen wird gegeneinander und
  // nicht gegen eine feste Zahl: Ein neues Modul erschien sonst in der Leiste,
  // aber nicht in der Suche — genau der Fehler, den lib/ziele.ts verhindern
  // soll —, und eine Zahl im Test nachzuziehen ist kein Nachweis.
  palette.zieleInLeiste = await seite.evaluate(
    () => document.querySelectorAll(".seitenleiste nav a").length,
  );

  // Ein Suchwort, das NICHT im Namen steht: "nginx" muss den Webserver finden.
  // Genau daran entscheidet sich, ob die Palette eine Suche ist oder eine Liste.
  await seite.fill("input.feld", "nginx");
  await seite.waitForTimeout(120);
  palette.trefferNginx = await seite.evaluate(() =>
    [...document.querySelectorAll('[role="option"]')].map((o) =>
      o.querySelector(".label")?.textContent.trim(),
    ),
  );

  // Umlaut weggelassen: "ubersicht" muss die Übersicht finden.
  await seite.fill("input.feld", "ubersicht");
  await seite.waitForTimeout(120);
  palette.trefferOhneUmlaut = await seite.evaluate(() =>
    [...document.querySelectorAll('[role="option"]')].map((o) =>
      o.querySelector(".label")?.textContent.trim(),
    ),
  );

  // Nichts gefunden ist ein Zustand, kein Fehler.
  await seite.fill("input.feld", "xyzquatsch");
  await seite.waitForTimeout(120);
  palette.leerZustand = await seite.evaluate(
    () => document.querySelector(".leer")?.textContent.trim() ?? "",
  );

  // Escape schließt.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(150);
  palette.nachEscape = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') === null,
  );

  // Der Knopf im Statusband öffnet sie ebenfalls, und der Pfeil wandert.
  await seite.click(".statusband .kbd");
  await seite.waitForSelector('[role="dialog"]', { timeout: 3000 });
  palette.schritte.push("über den Knopf im Statusband geöffnet");
  await seite.keyboard.press("ArrowDown");
  await seite.waitForTimeout(80);
  palette.zweiteGewaehlt = await seite.evaluate(() => {
    const gewaehlt = document.querySelectorAll('[role="option"]');
    return gewaehlt[1]?.getAttribute("aria-selected") === "true";
  });

  await bildschirmfoto(seite, "leitstand-palette");

  // Ein Klick in die Palette darf sie nicht schließen, ein Klick daneben schon.
  // Das hängt am Ziel des Klicks: Der Schleier horcht, die Palette liegt darin.
  // Ohne diese zwei Messungen wäre der Unterschied unbemerkt — die Tastatur
  // nimmt beide Wege nicht.
  await seite.click(".feldzeile .lupe");
  await seite.waitForTimeout(120);
  palette.klickInnenHaelt = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') !== null,
  );
  await seite.mouse.click(20, 20);
  await seite.waitForTimeout(150);
  palette.klickDanebenSchliesst = await seite.evaluate(
    () => document.querySelector('[role="dialog"]') === null,
  );

  // 5. Schmalmodus: Der Seitenkörper darf nicht waagerecht scrollen, und die
  //    Zellen müssen ihre Spaltenbeschriftung zeigen.
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(200);
  const schmal = await seite.evaluate(() => {
    const zelle = document.querySelector("table.tabelle td[data-spalte]");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      // ::before mit content: attr(data-spalte) — sichtbar nur im Schmalmodus.
      beschriftung: zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "",
    };
  });
  await bildschirmfoto(seite, "leitstand-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });
  await seite.waitForTimeout(200);

  if (process.env.ASYLUM_E2E_SHOTS) {
    // Zeiger aus dem Bild nehmen: Ein Hover-Zustand auf einer Kachel wäre auf
    // dem Bild ein Unterschied, den niemand erklären kann.
    await seite.mouse.move(0, 0);
  }
  await bildschirmfoto(seite, "leitstand-uebersicht", { fullPage: true });

  // 8. Das Modul Dienste. Hier hängt mehr am Browser als bei der Übersicht: der
  //    Wechsel ohne Neuladen, die Auswahl in der Adresse, der Zurück-Knopf und
  //    die Rückfrage vor dem Stoppen. Nichts davon sieht ein Go-Test.
  const dienste = {};

  // Eine Marke am Fenster: Sie überlebt einen Wechsel ohne Neuladen und stirbt
  // mit jedem echten Seitenaufruf. Das ist der Beweis, dass der Router greift —
  // und dass das Statusband mitsamt Live-Kanal stehen bleibt.
  await seite.evaluate(() => {
    window.__marke = "haelt";
  });
  await seite.click('.seitenleiste a[href="/dienste"]');
  await seite.waitForSelector("table.tabelle .zeile", { timeout: 5000 });
  dienste.ohneNeuladen = await seite.evaluate(() => window.__marke === "haelt");
  dienste.pfad = await seite.evaluate(() => location.pathname);
  dienste.navAktiv = await seite.evaluate(
    () => document.querySelector('.seitenleiste a[aria-current="page"] span')?.textContent ?? "",
  );

  // Gescheitertes zuerst — der Grund, warum jemand diese Seite öffnet.
  dienste.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".zeile")?.textContent.trim() ?? "",
      zustand: tr.querySelector(".zustand")?.textContent.trim() ?? "",
    })),
  );

  // Auswahl: Klick auf die Zeile öffnet den Inspektor, und die Adresse trägt sie.
  await seite.click("table.tabelle tbody tr:first-child .zeile");
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dienste.nachKlick = await seite.evaluate(() => ({
    suche: location.search,
    titel: document.querySelector(".inspektor h2")?.textContent.trim() ?? "",
    // Die Wertepaare im Inspektor — kommen aus dem zweiten Aufruf, nicht aus
    // der Liste. Steht hier nichts, hat das Detail nicht geladen.
    paare: document.querySelectorAll(".inspektor .kv dt").length,
  }));

  await bildschirmfoto(seite, "leitstand-dienste", { fullPage: true });

  // Der Zurück-Knopf schließt den Inspektor, statt die Seite zu verlassen. Das
  // hängt daran, dass die ERSTE Auswahl ein Schritt im Verlauf ist.
  await seite.goBack();
  await seite.waitForTimeout(200);
  dienste.nachZurueck = await seite.evaluate(() => ({
    inspektor: document.querySelector(".inspektor") !== null,
    pfad: location.pathname,
    suche: location.search,
  }));

  // Ein Neuladen auf der tiefen Adresse muss denselben Zustand zeigen — sonst
  // ist der Verweis nicht teilbar, und genau das war der Zweck der Übung.
  await seite.goto(`${basis}/dienste?unit=nginx.service`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dienste.nachNeuladen = await seite.evaluate(
    () => document.querySelector(".inspektor h2")?.textContent.trim() ?? "",
  );

  // Die Suche filtert im Browser. Ein Begriff, der nur in der Beschreibung
  // steht: Wer „Webserver" tippt, sucht nginx, und der Unitname sagt das nicht.
  await seite.fill(".suche input", "Webserver");
  await seite.waitForTimeout(150);
  dienste.gefiltert = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody .zeile")].map((b) =>
      b.textContent.trim(),
    ),
  );
  await seite.fill(".suche input", "");
  await seite.waitForTimeout(150);

  // Die Zähler sind Filter — Grundsatz II: jede Zahl ist ein Griff.
  await seite.click(".stufen button:nth-child(2)");
  await seite.waitForTimeout(150);
  dienste.nurGescheitert = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map(
      (tr) => tr.querySelector(".zustand")?.textContent.trim() ?? "",
    ),
  );
  await seite.click(".stufen button:nth-child(2)");
  await seite.waitForTimeout(150);

  // Die Rückfrage. Der Kern: Vor der Bestätigung darf NICHTS passieren, und der
  // gefährliche Knopf darf den Fokus nicht haben — sonst zerstört Enter sofort.
  const stoppKnopf = ".aktionen .knopf.gefahr";
  await seite.waitForSelector(stoppKnopf, { timeout: 5000 });
  await seite.click(stoppKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  dienste.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d?.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d?.querySelectorAll(".punkte li").length ?? 0,
      // Der gefährliche Knopf ist der letzte; den Fokus hat er nicht.
      fokusAufGefahr: document.activeElement?.classList.contains("gefahr") ?? false,
      // Stufe 2 hat kein Eingabefeld. Stünde hier eines, wäre die Stufe falsch.
      tippfeld: d?.querySelector(".tippen") !== null,
    };
  });

  // Escape ist ein Abbruch — und der Dialog muss danach wieder zu öffnen sein.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  dienste.nachAbbruch = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage") === null,
  );
  await seite.click(stoppKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  await seite.click("dialog.rueckfrage .knopf.gefahr");
  await seite.waitForTimeout(400);
  dienste.nachBestaetigung = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    meldung: document.querySelector(".inspektor .meldung")?.textContent.trim() ?? "",
  }));

  // Escape schließt den Inspektor.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  dienste.escapeSchliesst = await seite.evaluate(
    () => document.querySelector(".inspektor") === null,
  );

  // Schmal: Auch diese Seite darf nicht waagerecht scrollen, und der Inspektor
  // steht dann über der Liste statt daneben.
  await seite.goto(`${basis}/dienste?unit=nginx.service`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  dienste.schmal = await seite.evaluate(() => {
    const insp = document.querySelector(".inspektor");
    const tab = document.querySelector(".tabelle-rahmen");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      // Der Inspektor steht oben: kleinerer y-Wert als die Tabelle.
      inspektorOben: insp.getBoundingClientRect().top < tab.getBoundingClientRect().top,
    };
  });
  await bildschirmfoto(seite, "leitstand-dienste-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 9. Das Modul Pakete und die Vorgangsplatte. Hier hängt am Browser, was kein
  //    Go-Test sehen kann: Kommen die Zeilen über den Ereignisstrom an, und
  //    findet jemand, der die Seite neu lädt, den Vorgang vor?
  const pakete = {};
  await seite.goto(`${basis}/pakete`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle .pfad", { timeout: 5000 });

  pakete.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".pfad")?.textContent.trim() ?? "",
      // Sicherheitszeilen sind zusätzlich zur Farbe an einem Wort erkennbar.
      art: tr.querySelector(".zustand")?.textContent.trim() ?? "",
    })),
  );
  // Der Neustart-Hinweis erscheint nur, wenn einer aussteht — ein Knopf, der
  // immer da ist, wird irgendwann versehentlich gedrückt. Die Attrappe sagt in
  // diesem Lauf ja, und der Hinweis nennt das Paket, das ihn verlangt.
  pakete.neustart = await seite.evaluate(() => {
    const n = document.querySelector(".neustart");
    return {
      da: n !== null,
      text: n?.textContent.trim() ?? "",
      knopf: n?.querySelector(".knopf") !== null,
    };
  });

  // Listen holen: Der Knopf löst einen Vorgang aus, die Platte erscheint, und
  // die Zeilen von apt stehen darin. Ohne die wäre die Quittung eine Behauptung.
  await seite.click(".aktionen .knopf.leise");
  await seite.waitForSelector(".vorgang", { timeout: 5000 });
  await seite.waitForFunction(
    () => (document.querySelectorAll(".vorgang .auszug .zeile").length ?? 0) > 0,
    null,
    { timeout: 5000 },
  );
  pakete.vorgang = await seite.evaluate(() => {
    const v = document.querySelector(".vorgang");
    return {
      titel: v?.querySelector("b")?.textContent.trim() ?? "",
      zustand: v?.querySelector(".zustand")?.textContent.trim() ?? "",
      zeilen: [...v.querySelectorAll(".auszug .zeile")].map((d) => d.textContent),
      // Die Fußzeile nennt, wer den Vorgang angestoßen hat, und die Laufzeit.
      kopf: v?.querySelector(".mut")?.textContent.trim() ?? "",
    };
  });

  pakete.stromGeoeffnet = angefragt.some((u) => u.includes("/api/v1/jobs/packages/events"));

  await bildschirmfoto(seite, "leitstand-pakete", { fullPage: true });

  // Neu laden: Der Vorgang ist auf dem Server, nicht in der Seite. Wer
  // zurückkommt, findet ihn vor — mit denselben Zeilen.
  await seite.goto(`${basis}/pakete`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".vorgang", { timeout: 5000 });
  pakete.nachNeuladen = await seite.evaluate(() => ({
    zeilen: document.querySelectorAll(".vorgang .auszug .zeile").length,
    zustand: document.querySelector(".vorgang .zustand")?.textContent.trim() ?? "",
  }));

  // Alle einspielen fragt zurück, und die Frage nennt die Zahl.
  const alleKnopf = ".aktionen .knopf:not(.leise)";
  await seite.click(alleKnopf);
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  pakete.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d?.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d?.querySelectorAll(".punkte li").length ?? 0,
      tippfeld: d?.querySelector(".tippen") !== null,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  pakete.nachAbbruch = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage") === null,
  );

  // Stufe 3 am Neustart: Der bestätigende Knopf bleibt gesperrt, bis der
  // Hostname stimmt. Das ist die einzige Stelle in der neuen Oberfläche, an der
  // die dritte Stufe zu prüfen ist — und die Sperre ist der ganze Unterschied
  // zwischen Stufe 2 und Stufe 3.
  await seite.click(".neustart .knopf");
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  pakete.stufeDrei = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    const hinweis = d?.querySelector(".tippen span")?.textContent ?? "";
    return {
      tippfeld: d?.querySelector(".tippen input") !== null,
      hinweis,
      // Der Hostname steht im Hinweis — abzulesen, nicht zu erraten.
      wort: hinweis.split(": ").pop() ?? "",
      gesperrt: d?.querySelector(".knopf.gefahr")?.disabled ?? false,
      // Der gefährliche Knopf darf den Fokus nicht haben; bei Stufe 3 liegt er
      // im Eingabefeld.
      fokusImFeld: document.activeElement?.closest(".tippen") !== null,
    };
  });

  // Ein falsches Wort lässt den Knopf gesperrt, das richtige gibt ihn frei.
  await seite.fill("dialog.rueckfrage .tippen input", "falsch");
  await seite.waitForTimeout(120);
  pakete.stufeDrei.nachFalschem = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage .knopf.gefahr")?.disabled ?? false,
  );
  await seite.fill("dialog.rueckfrage .tippen input", pakete.stufeDrei.wort.toUpperCase());
  await seite.waitForTimeout(120);
  // Großschreibung darf genügen: Auf einem Telefon macht die Tastatur aus "vm"
  // gern "Vm". Der Server prüft ebenso.
  pakete.stufeDrei.nachRichtigem = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage .knopf.gefahr")?.disabled ?? true,
  );
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  pakete.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
    beschriftung: (() => {
      const zelle = document.querySelector("table.tabelle td[data-spalte]");
      return zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "";
    })(),
  }));
  await bildschirmfoto(seite, "leitstand-pakete-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 10. Das Modul Logs. Der zweite Strom des Panels — und ein anderer als beim
  //     Vorgang: Er hat kein Ende, das der Server bestimmt. Geprüft wird genau
  //     das: Bleibt er offen, kommen Zeilen nach, gelten die Filter aus der
  //     Adresse auch für ihn, und wird er beim Verlassen der Seite angehalten?
  const logs = {};
  await seite.goto(`${basis}/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  logs.zeilenAnfangs = await seite.evaluate(
    () => document.querySelectorAll("table.tabelle tbody tr").length,
  );
  logs.spalten = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle thead th")].map((th) => th.textContent.trim()),
  );
  // Vor dem Umschalten ist kein Strom offen: Wer die Seite öffnet, will meist
  // lesen, was war. Ein Journal, das ungefragt einen Prozess auf dem Server
  // aufmacht, ist eine Zumutung an den Betrieb.
  logs.stromVorherOffen = angefragt.some((u) => u.includes("/api/v1/logs/follow"));

  // Filter setzen: Sie stehen in der Adresse, damit ein Verweis auf „nur Fehler"
  // teilbar ist.
  await seite.selectOption(".filter select >> nth=1", "3");
  await seite.waitForTimeout(250);
  logs.nachStufenfilter = await seite.evaluate(() => location.search);

  await seite.fill(".suchform input", "publickey");
  await seite.press(".suchform input", "Enter");
  await seite.waitForTimeout(250);
  logs.nachSuche = await seite.evaluate(() => location.search);

  // Zurück-Knopf: Er nimmt den Filter zurück, und das Suchfeld folgt.
  await seite.goBack();
  await seite.waitForTimeout(250);
  logs.nachZurueck = await seite.evaluate(() => ({
    suche: location.search,
    feld: document.querySelector(".suchform input")?.value ?? "",
  }));

  // Verfolgen einschalten. Die Attrappe schiebt eine Zeile nach — die muss
  // ankommen, ohne dass jemand neu lädt.
  await seite.goto(`${basis}/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  // Auf die ANFRAGE warten und nicht auf den Puls. Der Puls erscheint, sobald der
  // Zustand umschlägt — die Verbindung ist dann erst angestoßen. Bis hierher wurde
  // gleich danach in der Liste der Anfragen nachgesehen, und das war ein Rennen:
  // Es ging gut, solange der Aufbau schneller war als die Prüfung. Beim
  // Umschalten fiel es auf, weil die Zeitverhältnisse sich verschoben haben —
  // der Strom lief weiter, nur die Anfrage war noch nicht verbucht.
  const stromAnfrage = seite
    .waitForRequest((r) => r.url().includes("/api/v1/logs/follow"), { timeout: 5000 })
    .then(() => true)
    .catch(() => false);
  await seite.click(".filter .verfolgen");
  await seite.waitForSelector(".filter .verfolgen .puls", { timeout: 5000 });
  logs.stromGeoeffnet = await stromAnfrage;

  // Auf eine bestimmte Zeile warten und nicht auf eine größere Zahl: Der Strom
  // bringt seinen eigenen Rückblick mit, und eine gewachsene Zeilenzahl könnte
  // auch der wieder eingespielte Rückblick sein. Diese eine Zeile gibt es nur im
  // Nachschub der Attrappe — sie kommt herein, während zugesehen wird.
  try {
    await seite.waitForFunction(
      () => document.body.textContent.includes("waehrend-des-verfolgens"),
      null,
      { timeout: 5000 },
    );
    logs.zeileNachgekommen = true;
  } catch {
    logs.zeileNachgekommen = false;
  }
  // Und keine Zeile steht doppelt da: Der Rückblick des Stroms ersetzt die
  // Liste, statt sie zu verdoppeln.
  logs.doppelt = await seite.evaluate(() => {
    const zeilen = [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) =>
      tr.textContent.replace(/\s+/g, " ").trim(),
    );
    return zeilen.length - new Set(zeilen).size;
  });
  logs.knopfText = await seite.evaluate(
    () => document.querySelector(".filter .verfolgen")?.textContent.trim() ?? "",
  );

  await bildschirmfoto(seite, "leitstand-logs", { fullPage: true });

  // Anhalten schließt den Strom.
  await seite.click(".filter .verfolgen");
  await seite.waitForTimeout(300);
  logs.nachAnhalten = await seite.evaluate(
    () => document.querySelector(".filter .verfolgen .puls") === null,
  );

  // Und der Wechsel auf eine andere Seite hält ihn ebenfalls an — sonst läuft auf
  // dem Server ein journalctl weiter, dem niemand mehr zusieht. Geprüft am
  // Zähler des Servers, den die Abfrage mitbringt.
  await seite.click(".filter .verfolgen");
  await seite.waitForSelector(".filter .verfolgen .puls", { timeout: 5000 });
  await seite.click('.seitenleiste a[href="/dienste"]');
  await seite.waitForSelector("table.tabelle .zeile", { timeout: 5000 });
  await seite.waitForTimeout(400);
  logs.folgerNachWechsel = await seite.evaluate(async () => {
    const r = await fetch("/api/v1/logs", {
      headers: { Accept: "application/json" },
      credentials: "same-origin",
    });
    const d = await r.json();
    return d.folger_frei;
  });

  await seite.goto(`${basis}/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  logs.schmal = await seite.evaluate(() => {
    const zelle = document.querySelector("table.tabelle td[data-spalte]");
    return {
      koerperBreite: document.body.scrollWidth,
      fensterBreite: window.innerWidth,
      beschriftung: zelle
        ? getComputedStyle(zelle, "::before").content.replace(/"/g, "")
        : "",
    };
  });
  await bildschirmfoto(seite, "leitstand-logs-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 11. Das Modul Firewall — Grundsatz VI, „Was schiefgehen kann, hat einen
  //     Rückweg." Geprüft wird die Probe: Steht sie ganz oben? Zählt der
  //     Countdown herunter? Und beendet der Knopf sie wirklich?
  const firewall = {};
  await seite.goto(`${basis}/firewall`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  firewall.zeilen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      text: tr.textContent.replace(/\s+/g, " ").trim(),
      vorschlag: tr.classList.contains("vorschlag"),
    })),
  );
  // Vor einer Änderung läuft keine Probe.
  firewall.probeVorher = await seite.evaluate(
    () => document.querySelector(".probe") !== null,
  );
  // „Regeln übernehmen" ist aus, solange nichts bearbeitet wurde: Ein Knopf, der
  // den unveränderten Stand noch einmal schreibt, stellt ohne Grund auf Probe.
  firewall.uebernehmenGesperrt = await seite.evaluate(() => {
    const knoepfe = [...document.querySelectorAll(".aktionen .knopf")];
    const u = knoepfe.find((b) => b.textContent.includes("übernehmen"));
    return u ? u.disabled : null;
  });

  // Eine Regel hinzufügen und übernehmen. Die Rückfrage kommt vom Server.
  await seite.click(".aktionen .knopf.leise");
  await seite.waitForTimeout(150);
  firewall.entwurfHinweis = await seite.evaluate(
    () => document.querySelector(".hinweis")?.textContent.trim() ?? "",
  );
  // Port in die neue (letzte) Zeile tippen.
  const felder = await seite.$$("table.tabelle input[type=number]");
  await felder[felder.length - 1].fill("8080");
  await seite.waitForTimeout(120);

  await seite.evaluate(() => {
    const u = [...document.querySelectorAll(".aktionen .knopf")].find((b) =>
      b.textContent.includes("übernehmen"),
    );
    u.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  firewall.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d?.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d?.querySelectorAll(".punkte li").length ?? 0,
      tippfeld: d?.querySelector(".tippen") !== null,
    };
  });
  await seite.click("dialog.rueckfrage .knopf.gefahr");

  // Und jetzt der Kern: Die Probe steht oben, und die Uhr läuft.
  await seite.waitForSelector(".probe .uhr", { timeout: 5000 });
  const erste = Number(await seite.textContent(".probe .uhr"));
  firewall.probe = {
    ersteZahl: erste,
    // Steht die Probe VOR der Tabelle? Wer hereinkommt, während eine Frist
    // läuft, muss zuerst den Knopf sehen, der sie beendet.
    vorDerTabelle: await seite.evaluate(() => {
      const p = document.querySelector(".probe").getBoundingClientRect();
      const t = document.querySelector(".tabelle-rahmen").getBoundingClientRect();
      return p.top < t.top;
    }),
    text: await seite.evaluate(
      () => document.querySelector(".probe b")?.textContent.trim() ?? "",
    ),
  };

  await bildschirmfoto(seite, "leitstand-firewall", { fullPage: true });

  // Die Uhr muss kleiner werden. Auf eine kleinere Zahl warten und nicht auf
  // eine Dauer: Ein fester Schlaf wäre auf einer langsamen Maschine zu kurz.
  try {
    await seite.waitForFunction(
      (start) => Number(document.querySelector(".probe .uhr")?.textContent) < start,
      erste,
      { timeout: 5000 },
    );
    firewall.probe.laeuftRunter = true;
  } catch {
    firewall.probe.laeuftRunter = false;
  }

  // Ein Neuladen findet die Probe vor: Sie ist Zustand des Servers, nicht der
  // Seite. Das ist der Fall, in dem es darauf ankommt — wer neu lädt, weil die
  // Seite nach einer Regeländerung hängt, muss den Knopf trotzdem finden.
  await seite.goto(`${basis}/firewall`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".probe .uhr", { timeout: 5000 });
  firewall.probeNachNeuladen = Number(await seite.textContent(".probe .uhr"));

  // Bestätigen beendet sie.
  await seite.click(".probe .knopf");
  await seite.waitForTimeout(400);
  firewall.nachBestaetigen = await seite.evaluate(() => ({
    probe: document.querySelector(".probe") !== null,
    meldung: document.querySelector(".meldung")?.textContent.trim() ?? "",
  }));

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  firewall.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await bildschirmfoto(seite, "leitstand-firewall-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12. Das Modul Dateien. Es ist das erste, dessen Ort in der Adresse steht und
  //     dessen Bewegung ein Schritt im Verlauf ist — und genau das prüft ein
  //     Go-Test nicht: Ob der Zurück-Knopf eine Ebene höher führt, ist eine
  //     Aussage über history.pushState und nicht über die Antwort des Servers.
  const wurzel = process.env.ASYLUM_E2E_DATEIWURZEL ?? "";
  const dateien = {};
  await seite.goto(`${basis}/dateien?pfad=${encodeURIComponent(wurzel)}`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  dateien.wurzeln = await seite.evaluate(() =>
    [...document.querySelectorAll(".bereiche button")].map((b) => b.textContent.trim()),
  );
  dateien.krumen = await seite.evaluate(() =>
    [...document.querySelectorAll(".krumen button")].map((b) => b.textContent.trim()),
  );
  dateien.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".zeile")?.textContent.trim() ?? "",
      groesse: tr.children[1]?.textContent.trim() ?? "",
      rechte: tr.children[3]?.textContent.trim() ?? "",
      gesperrt: tr.querySelector(".zustand.warn") !== null,
    })),
  );
  // Im Fuß stand bis 0.4.0 ein Verweis in die alte Ansicht (/alt/files). Sie ist
  // abgebaut; hier wird weiter nachgesehen, damit ein zurückkehrender Verweis
  // auffällt und nicht als 404 beim Nutzer landet.
  dateien.fussVerweis = await seite.evaluate(
    () => document.querySelector(".fuss a")?.getAttribute("href") ?? "",
  );

  await bildschirmfoto(seite, "leitstand-dateien", { fullPage: true });

  // Ein Klick auf einen Ordner geht hinein — nicht in den Inspektor. Ein
  // Doppelklick als Unterschied wäre auf einem Telefon nicht bedienbar.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find((b) =>
      b.classList.contains("ordner") && b.textContent.includes("schreibbar"),
    );
    z.click();
  });
  await seite.waitForFunction(
    () => new URL(location.href).searchParams.get("pfad")?.endsWith("/schreibbar"),
    null,
    { timeout: 5000 },
  );
  await seite.waitForTimeout(300);
  dateien.nachOrdnerklick = await seite.evaluate(() => ({
    pfad: new URL(location.href).searchParams.get("pfad") ?? "",
    krumen: [...document.querySelectorAll(".krumen button")].map((b) => b.textContent.trim()),
    reihen: [...document.querySelectorAll("table.tabelle .zeile")].map((b) =>
      b.textContent.trim(),
    ),
  }));

  // Und der Zurück-Knopf führt eine Ebene höher. Das ist der Punkt: Bei den
  // Diensten ersetzt der Wechsel der Auswahl den Verlaufseintrag, hier muss
  // jeder Schritt hinein einer sein.
  await seite.goBack();
  await seite.waitForTimeout(400);
  dateien.nachZurueck = await seite.evaluate(() => ({
    pfad: new URL(location.href).searchParams.get("pfad") ?? "",
    reihen: [...document.querySelectorAll("table.tabelle .zeile")].map((b) =>
      b.textContent.trim(),
    ),
  }));

  // Der Inspektor einer Datei: Rechte in Worten, und ein Download, der ein
  // echter Verweis ist. Wäre es ein Knopf mit fetch, zöge er die Datei in den
  // Speicher des Tabs.
  await seite.goto(
    `${basis}/dateien?pfad=${encodeURIComponent(wurzel + "/schreibbar")}` +
      `&eintrag=${encodeURIComponent(wurzel + "/schreibbar/notizen.txt")}`,
    { waitUntil: "domcontentloaded" },
  );
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  await seite.waitForSelector(".inspektor .rechteblock dd", { timeout: 5000 });
  dateien.inspektor = await seite.evaluate(() => {
    const i = document.querySelector(".inspektor");
    return {
      titel: i.querySelector("h2, .titel")?.textContent.trim() ?? i.getAttribute("aria-label") ?? "",
      paare: i.querySelectorAll("dl.kv dt").length,
      // Beschriftung UND Wert: „darf lesen" allein wäre kein Nachweis, dass
      // dabeisteht, für WEN es gilt.
      rechtetext: [...i.querySelectorAll(".rechteblock dt")].map((dt, n) => {
        const dd = i.querySelectorAll(".rechteblock dd")[n];
        return `${dt.textContent.trim()}: ${dd ? dd.textContent.replace(/\s+/g, " ").trim() : ""}`;
      }),
      aktionen: [...i.querySelectorAll(".aktionen .knopf")].map((a) =>
        a.textContent.trim(),
      ),
      downloadZu:
        [...i.querySelectorAll(".aktionen a")].find((a) =>
          a.textContent.includes("herunterladen"),
        )?.tagName ?? "",
    };
  });

  await bildschirmfoto(seite, "leitstand-dateien-inspektor", { fullPage: true });

  // Der gesperrte Eintrag: sichtbar, benannt — und ohne einen Handgriff, der
  // seinen Inhalt anfassen würde. Der Knopf wäre bereits der Fehler, auch wenn
  // der Endpunkt danach 403 antwortet.
  await seite.goto(
    `${basis}/dateien?pfad=${encodeURIComponent(wurzel)}` +
      `&eintrag=${encodeURIComponent(wurzel + "/schluessel.geheim")}`,
    { waitUntil: "domcontentloaded" },
  );
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dateien.gesperrtInspektor = await seite.evaluate(() => {
    const i = document.querySelector(".inspektor");
    return {
      warnung: i.querySelector(".warnung")?.textContent.trim() ?? "",
      aktionen: [...i.querySelectorAll(".aktionen .knopf")].map((a) =>
        a.textContent.trim(),
      ),
    };
  });

  // Die Suche geht an den Server und findet unterhalb. Ein Browserfilter könnte
  // das nicht — und behauptete bei einer gekürzten Liste „kein Treffer" für eine
  // Datei, die es gibt.
  await seite.goto(`${basis}/dateien?pfad=${encodeURIComponent(wurzel)}`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  await seite.fill(".suche input", "gesucht");
  await seite.press(".suche input", "Enter");
  await seite.waitForSelector(".band.info", { timeout: 5000 });
  dateien.suche = await seite.evaluate(() => ({
    band: document.querySelector(".band.info")?.textContent.replace(/\s+/g, " ").trim() ?? "",
    reihen: [...document.querySelectorAll("table.tabelle .zeile")].map((b) =>
      b.textContent.trim(),
    ),
    orte: [...document.querySelectorAll("table.tabelle .ort")].map((o) =>
      o.textContent.trim(),
    ),
  }));

  // Suche beenden bringt die Liste zurück.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".suche .knopf")].find((x) =>
      x.textContent.includes("beenden"),
    );
    b.click();
  });
  // Auf die LISTE warten und nicht bloß auf das Verschwinden des Bandes: Das
  // Band geht sofort, die Liste kommt über einen zweiten Aufruf. Wer nur auf das
  // Band wartet, misst mit etwas Pech noch die eine Trefferzeile — der Test war
  // genau deshalb sporadisch rot.
  await seite.waitForFunction(
    () =>
      document.querySelector(".band.info") === null &&
      document.querySelectorAll("table.tabelle tbody tr").length > 1,
    null,
    { timeout: 5000 },
  );
  dateien.nachSuchende = await seite.evaluate(
    () => document.querySelectorAll("table.tabelle tbody tr").length,
  );

  // Sortieren steht in der Adresse — teilbar, und ein Neuladen zeigt dasselbe.
  await seite.evaluate(() => {
    const s = [...document.querySelectorAll("table.tabelle th .spalte")].find((x) =>
      x.textContent.includes("Größe"),
    );
    s.click();
  });
  await seite.waitForTimeout(300);
  dateien.sortiertNach = await seite.evaluate(
    () => new URL(location.href).searchParams.get("sort") ?? "",
  );
  await seite.reload({ waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle th .spalte", { timeout: 5000 });
  dateien.nachNeuladen = await seite.evaluate(
    () =>
      [...document.querySelectorAll("table.tabelle th .spalte")]
        .find((x) => x.textContent.includes("Größe"))
        ?.textContent.trim() ?? "",
  );

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  dateien.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await bildschirmfoto(seite, "leitstand-dateien-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12b. Die Schreibvorgänge. Der Kern ist die Rückfrage: Ohne Bestätigung darf
  //      NICHTS geschehen, und bei einem Ordner mit Inhalt muss der Dialog nach
  //      einem getippten Wort fragen. Bis 0.3.0-rc.5 waren dreizehn Rückfragen im
  //      Projekt so gebaut, dass keine einzige gefragt hat — deshalb wird hier
  //      nicht nur der Dialog gezählt, sondern nach dem Abbruch geprüft, dass der
  //      Eintrag noch in der Liste steht.
  const schreiben = {};
  const schreibbar = `${wurzel}/schreibbar`;
  await seite.goto(`${basis}/dateien?pfad=${encodeURIComponent(schreibbar)}`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector(".werkstatt", { timeout: 5000 });

  // Die Werkstatt steht nur, wo geschrieben werden darf. In der Leseworzel
  // darüber darf sie nicht sein — ein Knopf, der zuverlässig in ein 403 läuft,
  // nennt den Fehler erst nach dem Klick.
  schreiben.werkstattHier = true;

  // Einen Ordner anlegen.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".werkstatt .knopf")].find((x) =>
      x.textContent.includes("Neuer Ordner"),
    );
    b.click();
  });
  await seite.waitForSelector(".maske input", { timeout: 5000 });
  await seite.fill(".maske input", "vom-browser");
  await seite.press(".maske input", "Enter");
  await seite.waitForSelector(".band.gut", { timeout: 5000 });
  schreiben.nachAnlegen = await seite.evaluate(() => ({
    meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
    // Der neue Eintrag ist ausgewählt: Wer einen Ordner anlegt, will meist gleich
    // hinein oder die Rechte setzen.
    auswahl: new URL(location.href).searchParams.get("eintrag") ?? "",
    inListe: [...document.querySelectorAll("table.tabelle .zeile")].some((z) =>
      z.textContent.includes("vom-browser"),
    ),
  }));

  // Umbenennen im Inspektor.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find((x) =>
      x.textContent.trim() === "umbenennen",
    );
    b.click();
  });
  await seite.waitForSelector(".inspektor .maske input", { timeout: 5000 });
  await seite.fill(".inspektor .maske input", "umbenannt");
  await seite.press(".inspektor .maske input", "Enter");
  await seite.waitForSelector(".inspektor .meldung", { timeout: 5000 });
  schreiben.nachUmbenennen = await seite.evaluate(() => ({
    // Die Meldung steht IM INSPEKTOR und nicht über der Liste: Sie gehört an die
    // Stelle, an der der Knopf war.
    meldung: document.querySelector(".inspektor .meldung")?.textContent.trim() ?? "",
    bandOben: document.querySelector(".band.gut") !== null,
    titel: document.querySelector(".inspektor h2")?.textContent.trim() ?? "",
    inListe: [...document.querySelectorAll("table.tabelle .zeile")].some((z) =>
      z.textContent.includes("umbenannt"),
    ),
  }));

  // Rechte setzen — Stufe 1 für einen einzelnen Eintrag, also ohne Dialog.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find(
      (x) => x.textContent.trim() === "Rechte",
    );
    b.click();
  });
  await seite.waitForSelector(".rechtemaske", { timeout: 5000 });
  schreiben.rechtemaske = await seite.evaluate(() => {
    const m = document.querySelector(".rechtemaske");
    return {
      // Vorbelegt mit dem, was gilt: Ein leeres Feld hieße „nichts ändern", und
      // wer die Rechte ansehen will, soll sie nicht abschreiben müssen.
      oktal: m.querySelector('input[type="text"]')?.value ?? "",
      auswahlfelder: m.querySelectorAll("select").length,
      // Der rekursive Schalter gibt es nur bei einem Ordner.
      rekursiv: m.querySelector('input[type="checkbox"]') !== null,
    };
  });

  // Und jetzt der rekursive Lauf: Er MUSS zurückfragen. Das ist die Verschärfung
  // gegenüber der alten Oberfläche.
  await seite.evaluate(() => {
    const m = document.querySelector(".rechtemaske");
    m.querySelector('input[type="text"]').value = "0700";
    m.querySelector('input[type="text"]').dispatchEvent(new Event("input", { bubbles: true }));
    m.querySelector('input[type="checkbox"]').click();
  });
  await seite.waitForTimeout(120);
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".rechtemaske .knopf")].find((x) =>
      x.textContent.includes("anwenden"),
    );
    b.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  schreiben.rekursivFrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d.querySelectorAll(".punkte li").length,
      tippfeld: d.querySelector(".tippen") !== null,
    };
  });
  // Abbrechen — und danach darf nichts geschehen sein.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(200);
  schreiben.nachAbbruchRekursiv = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage") === null,
  );

  // Löschen eines Ordners MIT Inhalt: Stufe 3.
  await seite.goto(
    `${basis}/dateien?pfad=${encodeURIComponent(schreibbar)}` +
      `&eintrag=${encodeURIComponent(schreibbar + "/tief")}`,
    { waitUntil: "domcontentloaded" },
  );
  await seite.waitForSelector(".inspektor .aktionen .knopf.gefahr", { timeout: 5000 });
  await seite.click(".inspektor .aktionen .knopf.gefahr");
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  schreiben.loeschFrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: d.querySelectorAll(".punkte li").length,
      tippfeld: d.querySelector(".tippen") !== null,
      hinweis: d.querySelector(".tippen-hinweis, label")?.textContent.trim() ?? "",
      // Der Knopf bleibt gesperrt, bis das Wort stimmt.
      gesperrt: [...d.querySelectorAll(".knopf")].find((b) =>
        b.textContent.includes("löschen"),
      )?.disabled,
    };
  });

  // Wo sitzt der Dialog? Ein modales <dialog> zentriert der Browser über
  // `margin: auto` — und der Rücksetzer in app.css (`* { margin: 0 }`) nimmt ihm
  // das. Gemessen und nicht angenommen: Ein Dialog in der linken oberen Ecke
  // funktioniert, sieht aber aus wie ein Fehler.
  schreiben.dialogSitz = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage").getBoundingClientRect();
    return {
      links: Math.round(d.left),
      breite: Math.round(d.width),
      fenster: window.innerWidth,
      // Waagerecht mittig? Zwei Pixel Toleranz für Rundung.
      mittig: Math.abs(d.left - (window.innerWidth - d.width) / 2) <= 2,
    };
  });
  await bildschirmfoto(seite, "leitstand-dateien-loeschfrage");

  // Abbrechen, und der Ordner steht noch da. DAS ist die Prüfung, die zählt.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);
  schreiben.nachLoeschAbbruch = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    nochDa: [...document.querySelectorAll("table.tabelle .zeile")].some((z) =>
      z.textContent.includes("tief"),
    ),
  }));

  // Jetzt wirklich: Wort tippen und löschen.
  await seite.click(".inspektor .aktionen .knopf.gefahr");
  await seite.waitForSelector("dialog.rueckfrage .tippen", { timeout: 5000 });
  await seite.fill("dialog.rueckfrage .tippen", "tief");
  await seite.waitForTimeout(120);
  await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    [...d.querySelectorAll(".knopf")].find((b) => b.textContent.includes("löschen")).click();
  });
  await seite.waitForSelector(".band.gut", { timeout: 5000 });
  schreiben.nachLoeschen = await seite.evaluate(() => ({
    meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
    // Der Inspektor ist zu: Es gibt den Eintrag nicht mehr.
    inspektor: document.querySelector(".inspektor") !== null,
    nochDa: [...document.querySelectorAll("table.tabelle .zeile")].some((z) =>
      z.textContent.includes("tief"),
    ),
  }));

  // Die Zielauswahl beim Kopieren: ein Ordnerbrowser und kein Textfeld.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find((x) =>
      x.textContent.includes("notizen.txt"),
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find(
      (x) => x.textContent.trim() === "kopieren",
    );
    b.click();
  });
  await seite.waitForSelector("dialog.zielwahl[open]", { timeout: 5000 });
  await seite.waitForSelector("dialog.zielwahl .ordner button", { timeout: 5000 });
  schreiben.zielwahl = await seite.evaluate(() => {
    const d = document.querySelector("dialog.zielwahl");
    return {
      // Kein Textfeld: Ein Tippfehler wurde sonst erst beim Absenden zu einer
      // Meldung, und "/srv/date" statt "/srv/daten" legt nichts an.
      textfelder: d.querySelectorAll('input[type="text"]').length,
      ordner: [...d.querySelectorAll(".ordner button")].map((b) => b.textContent.trim()),
      ziel: d.querySelector(".gewaehlt .pfad")?.textContent.trim() ?? "",
      knopfOffen: ![...d.querySelectorAll(".knoepfe .knopf")].find((b) =>
        b.textContent.includes("kopieren"),
      )?.disabled,
    };
  });

  await bildschirmfoto(seite, "leitstand-dateien-zielwahl", { fullPage: true });

  // In einen Unterordner wechseln und dorthin kopieren. Der Ordner heißt
  // „umbenannt" — er wurde weiter oben angelegt und umbenannt. Ins EIGENE
  // Verzeichnis zu kopieren wäre ein 400 („gibt es bereits"), und der Test
  // prüfte dann versehentlich die Fehlerbehandlung statt des Kopierens.
  await seite.waitForFunction(
    () =>
      [...document.querySelectorAll("dialog.zielwahl .ordner button")].some((x) =>
        x.textContent.includes("umbenannt"),
      ),
    null,
    { timeout: 5000 },
  );
  await seite.evaluate(() => {
    [...document.querySelectorAll("dialog.zielwahl .ordner button")]
      .find((x) => x.textContent.includes("umbenannt"))
      .click();
  });
  await seite.waitForFunction(
    () =>
      document
        .querySelector("dialog.zielwahl .gewaehlt .pfad")
        ?.textContent.endsWith("/umbenannt"),
    null,
    { timeout: 5000 },
  );
  await seite.evaluate(() => {
    const d = document.querySelector("dialog.zielwahl");
    [...d.querySelectorAll(".knoepfe .knopf")]
      .find((b) => b.textContent.includes("kopieren"))
      .click();
  });
  await seite.waitForSelector(".inspektor .meldung", { timeout: 5000 });
  schreiben.nachKopieren = await seite.evaluate(() => ({
    meldung: document.querySelector(".inspektor .meldung")?.textContent.trim() ?? "",
    dialogZu: document.querySelector("dialog.zielwahl") === null,
    // Das Original steht noch da — sonst wäre es ein Verschieben.
    originalDa: [...document.querySelectorAll("table.tabelle .zeile")].some((z) =>
      z.textContent.includes("notizen.txt"),
    ),
    // Und die Messung, die den Fehler gefunden hat: Diese Meldung enthält einen
    // PFAD, und ein Pfad ohne Trennstelle hat eine große Mindestbreite. Ohne
    // overflow-wrap wuchs die Spalte der Werkbank über das Fenster hinaus, der
    // Inspektor wurde rechts abgeschnitten und „löschen" lag außerhalb des
    // Bildes. Gemessen wird deshalb beides: der Seitenkörper und die rechte
    // Kante des Inspektors.
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
    inspektorRechts: Math.round(
      document.querySelector(".inspektor").getBoundingClientRect().right,
    ),
    // Die letzte Schaltfläche muss innerhalb des Inspektors liegen.
    letzterKnopfDrin: (() => {
      const i = document.querySelector(".inspektor").getBoundingClientRect();
      const knoepfe = [...document.querySelectorAll(".inspektor .aktionen .knopf")];
      const letzter = knoepfe[knoepfe.length - 1]?.getBoundingClientRect();
      return letzter ? letzter.right <= i.right + 1 : false;
    })(),
  }));

  await bildschirmfoto(seite, "leitstand-dateien-schreiben", { fullPage: true });

  // Und die Gegenprobe: In der Leseworzel gibt es keine Werkstatt.
  await seite.goto(`${basis}/dateien?pfad=${encodeURIComponent(wurzel)}`, {
    waitUntil: "domcontentloaded",
  });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  schreiben.werkstattDraussen = await seite.evaluate(
    () => document.querySelector(".werkstatt") !== null,
  );
  // Und am Eintrag dort keine verändernden Handgriffe.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find((x) =>
      x.textContent.includes("schluessel.geheim"),
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  schreiben.handgriffeDraussen = await seite.evaluate(() =>
    [...document.querySelectorAll(".inspektor .aktionen .knopf")].map((b) =>
      b.textContent.trim(),
    ),
  );

  // 12c. Der Editor. Er ist der Prüfstein dieses Moduls, und zwar an einer
  //      Stelle, an der dieses Projekt schon zweimal gescheitert ist: Die
  //      Content-Security-Policy des Panels erlaubt kein Inline-Skript und kein
  //      Inline-Stylesheet, und CodeMirror trägt seine Stilregeln zur Laufzeit
  //      ein. Ob das durchgeht, sagt kein Go-Test und kein Build — nur der
  //      Browser gegen die UNVERÄNDERTE Richtlinie.
  //
  //      Dazu kommt: Der Editor ist ein NACHGELADENER Brocken (dynamisches
  //      import()). Dass er über die Richtlinie hinweg geholt wird, ist die
  //      zweite Frage, die nur hier zu beantworten ist.
  const editor = {};
  const editorPfad = `${wurzel}/schreibbar/server.conf`;
  // Die Zahl der Anfragen vor dem Öffnen: Der Brocken darf VORHER nicht geholt
  // worden sein. Das ist der ganze Zweck der Aufteilung.
  editor.kernVorher = angefragt.filter((u) => u.includes("editorkern")).length;

  await seite.goto(
    `${basis}/dateien?pfad=${encodeURIComponent(schreibbar)}` +
      `&eintrag=${encodeURIComponent(editorPfad)}`,
    { waitUntil: "domcontentloaded" },
  );
  await seite.waitForSelector(".inspektor .aktionen .knopf", { timeout: 5000 });
  editor.kernVorOeffnen = angefragt.filter((u) => u.includes("editorkern")).length;

  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find(
      (x) => x.textContent.trim() === "bearbeiten",
    );
    b.click();
  });
  // Auf die Zeilennummern warten: Sie entstehen erst, wenn CodeMirror läuft. Auf
  // den Kasten zu warten hieße, auf das leere Zuhause zu warten.
  await seite.waitForSelector(".editor .cm-gutters", { timeout: 10000 });
  editor.kernNachher = angefragt.filter((u) => u.includes("editorkern")).length;

  editor.aufbau = await seite.evaluate(() => {
    const e = document.querySelector(".editor");
    const zeilen = e.querySelectorAll(".cm-gutterElement").length;
    return {
      // Die Adresse trägt die bearbeitete Datei: teilbar, und der Zurück-Knopf
      // schließt den Editor.
      adresse: new URL(location.href).searchParams.get("bearbeiten") ?? "",
      zeilennummern: zeilen,
      inhalt: e.querySelector(".cm-content")?.textContent ?? "",
      sprache: [...e.querySelectorAll(".marke")].map((m) => m.textContent.trim()),
      // Und der Nachweis, um den es geht: Ist der Stil angekommen? Kommt die
      // Regel nicht durch die Richtlinie, ist der Rahmen nicht da und die
      // Schriftart nicht Mono.
      rahmen: getComputedStyle(e.querySelector(".cm-editor")).borderTopWidth,
      schrift: getComputedStyle(e.querySelector(".cm-scroller")).fontFamily,
      // Die Liste steht weiter darunter: Der Editor ersetzt sie nicht, damit der
      // Ort nicht verloren geht.
      listeDa: document.querySelector("table.tabelle") !== null,
      krumenDa: document.querySelector(".krumen") !== null,
    };
  });

  await bildschirmfoto(seite, "leitstand-dateien-editor", { fullPage: true });

  // Tippen und speichern.
  await seite.click(".editor .cm-content");
  await seite.keyboard.press("End");
  await seite.keyboard.type("\n# vom-browser");
  await seite.waitForTimeout(150);
  editor.nachTippen = await seite.evaluate(
    () =>
      [...document.querySelectorAll(".editor .marke")].some((m) =>
        m.textContent.includes("ungespeichert"),
      ),
  );

  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".editor .fuss .knopf")].find((x) =>
      x.textContent.includes("speichern"),
    );
    b.click();
  });
  await seite.waitForSelector(".editor .band.gut", { timeout: 5000 });
  editor.nachSpeichern = await seite.evaluate(() => ({
    meldung: document.querySelector(".editor .band.gut")?.textContent.trim() ?? "",
    // Das Kennzeichen ist weg: Es gibt nichts Ungespeichertes mehr.
    ungespeichert: [...document.querySelectorAll(".editor .marke")].some((m) =>
      m.textContent.includes("ungespeichert"),
    ),
  }));
  // Und die Größe in der Liste ist eine andere — die Liste wurde neu geholt.
  editor.groesseDanach = await seite.evaluate(() => {
    const zeile = [...document.querySelectorAll("table.tabelle tbody tr")].find((tr) =>
      tr.textContent.includes("server.conf"),
    );
    return zeile?.children[1]?.textContent.trim() ?? "";
  });

  // Der Konflikt — der Fall, um den es beim Editor eines Panels wirklich geht:
  // Zwei Menschen bearbeiten dieselbe Datei. Nachgestellt wird er ehrlich: Die
  // Datei wird VON AUSSERHALB des Editors geschrieben (eigener Aufruf mit
  // ueberschreiben), während der Editor seinen alten Hash noch hält. Danach läuft
  // das Speichern über die Schaltfläche — und muss in den Konflikt laufen statt
  // die fremde Änderung zu überschreiben.
  editor.fremdSchreiben = await seite.evaluate(async (pfad) => {
    const sitzung = await (
      await fetch("/api/v1/session", {
        headers: { Accept: "application/json" },
        credentials: "same-origin",
      })
    ).json();
    const antwort = await fetch("/api/v1/files/text", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
        "X-CSRF-Token": sitzung.csrf,
      },
      credentials: "same-origin",
      body: JSON.stringify({
        pfad,
        inhalt: "von auswaerts\n",
        hash: "",
        crlf: false,
        ohne_schlussumbruch: false,
        ueberschreiben: true,
      }),
    });
    return antwort.status;
  }, editorPfad);

  // Jetzt im Editor tippen und speichern: Das muss der Konflikt sein.
  await seite.click(".editor .cm-content");
  await seite.keyboard.press("End");
  await seite.keyboard.type("\n# und noch etwas");
  await seite.evaluate(() => {
    [...document.querySelectorAll(".editor .fuss .knopf")]
      .find((x) => x.textContent.includes("speichern"))
      .click();
  });
  await seite.waitForSelector(".editor .band.schlecht", { timeout: 5000 });
  editor.konflikt = await seite.evaluate(() => {
    const band = document.querySelector(".editor .band.schlecht");
    return {
      meldung: band?.textContent.replace(/\s+/g, " ").trim() ?? "",
      // Der zweite Ausweg muss angeboten werden: die fremde Fassung übernehmen.
      // Ein Konflikt mit nur einem Knopf ist keine Wahl.
      fremdKnopf: [...band.querySelectorAll(".knopf")].some((b) =>
        b.textContent.includes("übernehmen"),
      ),
      // Und der eigene Text steht noch im Editor. DAS ist der Kern: Die eigene
      // Arbeit geht nicht verloren, weil jemand anders gespeichert hat.
      eigenerTextDa: (document.querySelector(".editor .cm-content")?.textContent ?? "").includes(
        "und noch etwas",
      ),
      // Der Speicherknopf heißt jetzt anders: Überschreiben ist eine andere
      // Handlung als Speichern, und der Knopf sagt es.
      knopf:
        [...document.querySelectorAll(".editor .fuss .knopf")]
          .map((b) => b.textContent.trim())
          .join(" ") ?? "",
    };
  });

  // Und der ehrliche zweite Weg: den fremden Stand übernehmen.
  await seite.evaluate(() => {
    [...document.querySelectorAll(".editor .band.schlecht .knopf")]
      .find((b) => b.textContent.includes("übernehmen"))
      .click();
  });
  await seite.waitForSelector(".editor .band.gut", { timeout: 5000 });
  editor.nachUebernahme = await seite.evaluate(() => ({
    meldung: document.querySelector(".editor .band.gut")?.textContent.trim() ?? "",
    inhalt: document.querySelector(".editor .cm-content")?.textContent ?? "",
    konfliktWeg: document.querySelector(".editor .band.schlecht") === null,
  }));

  // Der Zurück-Knopf schließt den Editor und lässt die Seite stehen.
  await seite.goBack();
  await seite.waitForTimeout(400);
  editor.nachZurueck = await seite.evaluate(() => ({
    editorDa: document.querySelector(".editor") !== null,
    pfadDa: new URL(location.href).searchParams.get("pfad") !== null,
    bearbeiten: new URL(location.href).searchParams.get("bearbeiten") ?? "",
  }));

  // 12d. Das Modul Audit. Zwei Dinge sind hier nur im Browser zu sehen: dass der
  //      Filter in der ADRESSE steht (ein Verweis auf „alles, was philipp am
  //      Dateimanager abgelehnt bekam" ist damit teilbar), und dass „weitere 100
  //      laden" ANHÄNGT statt zu ersetzen — ein Seitenwechsel, der die Liste
  //      austauscht, verliert die Zeile, wegen der man weitergeblättert hat.
  const audit = {};
  await seite.goto(`${basis}/audit`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  audit.zeilenAnfangs = await seite.evaluate(
    () => document.querySelectorAll("table.tabelle tbody tr").length,
  );
  audit.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  // Es gibt keinen Knopf, der etwas ändert. Das ist die Aussage des Moduls, und
  // sie ist auch eine Aussage über die Fläche: kein „löschen", kein „bearbeiten".
  audit.knoepfe = await seite.evaluate(() =>
    [...document.querySelectorAll(".knopf")].map((b) => b.textContent.trim()),
  );

  // Nach Ergebnis filtern. Der Filter wandert in die Adresse.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".stufen button")].find((x) =>
      x.textContent.includes("abgelehnt"),
    );
    b.click();
  });
  await seite.waitForFunction(
    () => new URL(location.href).searchParams.get("ergebnis") === "denied",
    null,
    { timeout: 5000 },
  );
  await seite.waitForTimeout(300);
  audit.nachFilter = await seite.evaluate(() => ({
    adresse: new URL(location.href).searchParams.get("ergebnis") ?? "",
    ergebnisse: [...document.querySelectorAll("table.tabelle tbody tr .zustand")].map((z) =>
      z.textContent.trim(),
    ),
  }));

  // Ein Neuladen zeigt dasselbe — der Filter ist Zustand der Adresse, nicht der
  // Seite.
  await seite.reload({ waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".stufen button.an", { timeout: 5000 });
  audit.nachNeuladen = await seite.evaluate(
    () =>
      [...document.querySelectorAll(".stufen button.an")]
        .map((b) => b.textContent.trim())
        .join(","),
  );

  // Filter zurücksetzen.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".knopf")].find((x) =>
      x.textContent.includes("zurücksetzen"),
    );
    b.click();
  });
  await seite.waitForFunction(
    () => new URL(location.href).searchParams.get("ergebnis") === null,
    null,
    { timeout: 5000 },
  );
  await seite.waitForTimeout(300);
  audit.nachZuruecksetzen = await seite.evaluate(
    () => document.querySelectorAll("table.tabelle tbody tr").length,
  );

  // Die Einzelheiten klappen auf. Sie stehen nicht in der Zeile, weil ein Detail
  // bis 1024 Zeichen lang sein darf und die Liste dann keine mehr wäre.
  await seite.click("table.tabelle tbody .zeile");
  await seite.waitForSelector("tr.einzelheiten", { timeout: 5000 });
  audit.einzelheiten = await seite.evaluate(() => ({
    paare: document.querySelectorAll("tr.einzelheiten dl.kv dt").length,
    aufgeklappt: document.querySelector('table.tabelle .zeile[aria-expanded="true"]') !== null,
  }));

  await bildschirmfoto(seite, "leitstand-audit", { fullPage: true });

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  audit.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12e. Benutzer & SSH. Der Kern ist die Frage beim LETZTEN Schlüssel: Sie ist
  //      eine andere als „einen von dreien entfernen", weil danach das Konto
  //      keinen Zugang mehr hat. Und die Gegenprobe an root: Ein geschütztes Konto
  //      darf keinen Knopf zeigen, der dann verweigert.
  const konten = {};
  await seite.goto(`${basis}/benutzer`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  konten.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  konten.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".zeile")?.textContent.trim() ?? "",
      warn: tr.querySelector("td:nth-child(3) .zustand.warn") !== null,
    })),
  );
  // Die Zähler sind Filter.
  konten.filter = await seite.evaluate(() =>
    [...document.querySelectorAll(".stufen button")].map((b) =>
      b.textContent.replace(/\s+/g, " ").trim(),
    ),
  );

  // root: geschützt, also keine verändernden Knöpfe.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find(
      (x) => x.textContent.trim() === "root",
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  konten.rootHandgriffe = await seite.evaluate(() =>
    [...document.querySelectorAll(".inspektor .aktionen .knopf")].map((b) =>
      b.textContent.trim(),
    ),
  );
  konten.rootHinweis = await seite.evaluate(() =>
    [...document.querySelectorAll(".inspektor .detail")].some((p) =>
      p.textContent.includes("nicht sperren"),
    ),
  );

  // philipp: alles da, und die Schlüssel stehen im Inspektor.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find(
      (x) => x.textContent.trim() === "philipp",
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor .schluesselblock", { timeout: 5000 });
  konten.philipp = await seite.evaluate(() => ({
    handgriffe: [...document.querySelectorAll(".inspektor .aktionen .knopf")].map((b) =>
      b.textContent.trim(),
    ),
    schluessel: document.querySelectorAll(".inspektor .schluesselliste li").length,
    // Der Ort der Datei steht dabei: Wer den Zugang verliert, muss wissen, wo er
    // von Hand nachsehen kann.
    datei: [...document.querySelectorAll(".inspektor .schluesselblock .detail")].some((p) =>
      p.textContent.includes("authorized_keys"),
    ),
    // Bei genau einem Schlüssel steht die Anmerkung da, BEVOR jemand klickt.
    letzterHinweis:
      document.querySelector(".inspektor .schluesselblock .anmerkung")?.textContent.trim() ?? "",
  }));

  await bildschirmfoto(seite, "leitstand-benutzer", { fullPage: true });

  // Und jetzt der Punkt: Der letzte Schlüssel verlangt den Kontonamen.
  await seite.click(".inspektor .schluesselliste .knopf");
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  konten.letzterSchluessel = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d.querySelector(".frage")?.textContent.trim() ?? "",
      tippfeld: d.querySelector(".tippen") !== null,
      gesperrt: [...d.querySelectorAll(".knopf")].find((b) =>
        b.textContent.includes("entfernen"),
      )?.disabled,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);
  konten.nachAbbruch = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    // Der Schlüssel steht noch da. DAS ist die Prüfung, die zählt.
    schluessel: document.querySelectorAll(".inspektor .schluesselliste li").length,
  }));

  // Die Maske zum Anlegen: Auswahlfelder und kein Freitext für Schale und Gruppen.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".werkzeuge .knopf")].find((x) =>
      x.textContent.includes("Konto anlegen"),
    );
    b.click();
  });
  await seite.waitForSelector("form.anlegen", { timeout: 5000 });
  konten.anlegen = await seite.evaluate(() => {
    const f = document.querySelector("form.anlegen");
    return {
      auswahlfelder: f.querySelectorAll("select").length,
      hinweis: f.querySelector(".detail")?.textContent.trim() ?? "",
      schluesselfeld: f.querySelector("textarea") !== null,
    };
  });

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  konten.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12f. Panel-Zugänge. Vier Dinge sind hier nur im Browser zu sehen:
  //
  //      1. Die eigene Zeile trägt eine Marke und KEINE Handgriffe. Ein Modul,
  //         das sie einfach weglässt, sieht halb gebaut aus.
  //      2. Die Schranke vor den Zurücksetzungen steht offen da, und ihre Knöpfe
  //         sind gesperrt, solange das Feld leer ist. Das ist der Unterschied
  //         zwischen einer sichtbaren Bedingung und einem 403 nach dem Klick.
  //      3. Das Einmalpasswort steht in einem Dialog, den Escape NICHT schließt.
  //         Es kommt kein zweites Mal; ein Band, das beim nächsten Klick
  //         verschwindet, wäre die falsche Form.
  //      4. Der Dialog sitzt in der Mitte. Dieselbe Messung wie bei der
  //         Rückfrage, und aus demselben Grund: `* { margin: 0 }` hat
  //         margin:auto schon einmal geschlagen, und gesehen hat das erst ein
  //         Bildschirmfoto.
  const zugaenge = {};
  await seite.goto(`${basis}/zugaenge`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  zugaenge.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  zugaenge.reihen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      name: tr.querySelector(".zeile")?.textContent.trim() ?? "",
      ich: tr.querySelector(".namenszelle .marke") !== null,
      zustand: tr.querySelector("td:nth-child(5) .zustand")?.textContent.trim() ?? "",
    })),
  );
  // Der Menüpunkt ist da — für die Owner-Rolle. Die Gegenprobe steht in 12g.
  zugaenge.imMenue = await seite.evaluate(
    () => document.querySelector('.seitenleiste a[href="/zugaenge"]') !== null,
  );

  // Das eigene Konto: markiert, ohne Handgriffe, mit dem Satz, der das erklärt.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find(
      (x) => x.textContent.trim() === "philipp",
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  zugaenge.eigenes = await seite.evaluate(() => ({
    handgriffe: document.querySelectorAll(".inspektor .aktionen .knopf").length,
    schranke: document.querySelector(".inspektor .schranke") !== null,
    hinweis:
      document.querySelector(".inspektor .anmerkung")?.textContent.trim() ?? "",
  }));

  // Und das fremde Konto: alles da, und die Schranke davor.
  await seite.evaluate(() => {
    const z = [...document.querySelectorAll("table.tabelle .zeile")].find(
      (x) => x.textContent.trim() === "vertretung",
    );
    z.click();
  });
  await seite.waitForSelector(".inspektor .schranke", { timeout: 5000 });
  zugaenge.fremdes = await seite.evaluate(() => {
    const schranke = document.querySelector(".inspektor .schranke");
    return {
      handgriffe: [...document.querySelectorAll(".inspektor .handgriffe .knopf")].map((b) =>
        b.textContent.trim(),
      ),
      // Der Satz sagt, WESSEN Passwort gemeint ist — die häufigste Verwechslung
      // an dieser Stelle.
      warum: schranke.querySelector(".detail")?.textContent.trim() ?? "",
      feldTyp: schranke.querySelector("input")?.getAttribute("type") ?? "",
      // Gesperrt, solange das Feld leer ist.
      gesperrt: [...schranke.querySelectorAll(".knopf")].map((b) => b.disabled),
      knoepfe: [...schranke.querySelectorAll(".knopf")].map((b) => b.textContent.trim()),
    };
  });

  // Sperren ist Stufe 2: eine Frage, kein Tippfeld.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .handgriffe .knopf")].find(
      (x) => x.textContent.trim() === "sperren",
    );
    b.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  zugaenge.sperren = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    const r = d.getBoundingClientRect();
    return {
      frage: d.querySelector(".frage")?.textContent.trim() ?? "",
      tippfeld: d.querySelector(".tippen") !== null,
      // Die Mitte, gemessen: links und rechts derselbe Abstand.
      links: r.left,
      rechts: window.innerWidth - r.right,
      oben: r.top,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);

  // Löschen ist Stufe 3: Der Anmeldename muss getippt werden, und der Knopf
  // bleibt gesperrt, bis er stimmt.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .handgriffe .knopf")].find(
      (x) => x.textContent.trim() === "löschen",
    );
    b.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open] .tippen", { timeout: 5000 });
  await seite.fill("dialog.rueckfrage .tippen input", "vertretun");
  await seite.waitForTimeout(120);
  const gesperrtFalsch = await seite.evaluate(
    () =>
      [...document.querySelectorAll("dialog.rueckfrage .knopf")].find((b) =>
        b.textContent.includes("löschen"),
      )?.disabled ?? null,
  );
  await seite.fill("dialog.rueckfrage .tippen input", "vertretung");
  await seite.waitForTimeout(120);
  const gesperrtRichtig = await seite.evaluate(
    () =>
      [...document.querySelectorAll("dialog.rueckfrage .knopf")].find((b) =>
        b.textContent.includes("löschen"),
      )?.disabled ?? null,
  );
  zugaenge.loeschen = { gesperrtFalsch, gesperrtRichtig };
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);
  // Das Konto steht noch. DAS ist die Prüfung, die zählt.
  zugaenge.nachAbbruch = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    reihen: document.querySelectorAll("table.tabelle tbody tr").length,
  }));

  await bildschirmfoto(seite, "leitstand-zugaenge", { fullPage: true });

  // Und jetzt die Zurücksetzung mit dem eigenen Passwort. Das Einmalpasswort
  // landet in einem Dialog, den Escape nicht schließt.
  await seite.fill(".inspektor .schranke input", "ein sehr langes Testpasswort");
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".inspektor .schranke .knopf")].find((x) =>
      x.textContent.includes("Passwort zurücksetzen"),
    );
    b.click();
  });
  await seite.waitForSelector("dialog.einmal[open]", { timeout: 5000 });
  zugaenge.einmal = await seite.evaluate(() => {
    const d = document.querySelector("dialog.einmal");
    const r = d.getBoundingClientRect();
    return {
      wort: d.querySelector(".wort")?.textContent.trim() ?? "",
      // „steht nur hier" — der Satz muss dabeistehen, sonst schließt man den
      // Dialog und hat das Passwort verloren.
      warnung: d.querySelector(".warnung")?.textContent.trim() ?? "",
      knoepfe: [...d.querySelectorAll(".knopf")].map((b) => b.textContent.trim()),
      links: r.left,
      rechts: window.innerWidth - r.right,
    };
  });
  // Das Einmalpasswort bekommt ein eigenes Bild: Es ist die heikelste Fläche des
  // Moduls, und ob der Satz „steht nur hier" daneben auch gelesen wird, sieht man
  // nur an der Anordnung.
  await bildschirmfoto(seite, "leitstand-einmalpasswort");

  // Escape darf ihn NICHT schließen.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);
  zugaenge.nachEscape = await seite.evaluate(
    () => document.querySelector("dialog.einmal[open]") !== null,
  );
  // Und das Feld ist danach leer: Ein gefülltes Passwortfeld verleitet zum
  // nächsten Klick auf ein anderes Ziel.
  zugaenge.feldLeer = await seite.evaluate(
    () => (document.querySelector(".inspektor .schranke input")?.value ?? "x") === "",
  );
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll("dialog.einmal .knopf")].find((x) =>
      x.textContent.includes("notiert"),
    );
    b.click();
  });
  await seite.waitForTimeout(250);
  zugaenge.zu = await seite.evaluate(
    () => document.querySelector("dialog.einmal") === null,
  );

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  zugaenge.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12g. Die Gegenprobe mit einer anderen Rolle. Ein eigener Browserkontext mit
  //      dem Cookie eines Admin-Kontos: Der Menüpunkt fehlt, die Palette findet
  //      ihn nicht, und der Pfad von Hand aufgerufen sagt, WARUM er nichts zeigt.
  //      Das ist Bedienhilfe und keine Sicherheitsmaßnahme — die Route antwortet
  //      ohnehin 403 —, aber ein Menüpunkt, der zuverlässig „vorbehalten" sagt,
  //      ist eine Einladung, es trotzdem zu versuchen.
  const fremdeRolle = {};
  if (process.env.ASYLUM_E2E_COOKIE2) {
    const [n2, w2] = process.env.ASYLUM_E2E_COOKIE2.split("=");
    const kontext2 = await browser.newContext({ ignoreHTTPSErrors: true });
    await kontext2.addCookies([
      {
        name: n2,
        value: w2,
        domain: url.hostname,
        path: "/",
        httpOnly: true,
        secure: false,
        sameSite: "Strict",
      },
    ]);
    const seite2 = await kontext2.newPage();
    await seite2.goto(`${basis}/`, { waitUntil: "domcontentloaded" });
    await seite2.waitForSelector(".seitenleiste a", { timeout: 5000 });
    fremdeRolle.imMenue = await seite2.evaluate(
      () => document.querySelector('.seitenleiste a[href="/zugaenge"]') !== null,
    );
    // Die Palette: Auch dort nicht.
    await seite2.keyboard.press("Control+k");
    await seite2.waitForSelector('[role="dialog"]', { timeout: 5000 });
    await seite2.fill("input.feld", "zugänge");
    await seite2.waitForTimeout(200);
    fremdeRolle.inPalette = await seite2.evaluate(
      () => document.querySelectorAll('[role="option"]').length,
    );
    await seite2.keyboard.press("Escape");

    // Der Pfad von Hand: Die Seite sagt, warum sie leer ist.
    await seite2.goto(`${basis}/zugaenge`, { waitUntil: "domcontentloaded" });
    await seite2.waitForSelector(".hinweis", { timeout: 5000 });
    fremdeRolle.satz = await seite2.evaluate(
      () => document.querySelector(".hinweis .detail")?.textContent.trim() ?? "",
    );
    // Und KEIN Knopf „Erneut versuchen": Er brächte nie ein anderes Ergebnis.
    fremdeRolle.erneutKnopf = await seite2.evaluate(
      () => document.querySelector(".hinweis .knopf") !== null,
    );
    if (process.env.ASYLUM_E2E_SHOTS) {
      await seite2.screenshot({
        path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-zugaenge-fremde-rolle.png`,
        fullPage: true,
      });
    }
    await kontext2.close();
  }

  // 12j. Updates des Panels. Drei Dinge sind hier nur im Browser zu sehen:
  //
  //      1. „Noch nicht geprüft" ist ein eigener Zustand und nicht „kein
  //         Update". Der Knopf zum Einspielen ist dann gesperrt.
  //      2. Nach der Prüfung steht die gefundene Fassung da, samt Einstufung als
  //         Sicherheitsupdate und Verweis auf die Notizen.
  //      3. Die Rückfrage nennt BEIDE Folgen: Neustart und Rückweg. Der Satz
  //         über den Verbindungsabbruch steht schon vorher auf der Seite — ohne
  //         ihn sieht der Abbruch wie ein Fehlschlag aus.
  //
  //      Der Vorgang selbst wird hier NICHT ausgelöst: Er tauscht das Binary des
  //      laufenden Testservers. Was danach geschieht, prüfen die Go-Tests an der
  //      Attrappe.
  const upd = {};
  await seite.goto(`${basis}/updates`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("section.platte", { timeout: 5000 });

  upd.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  upd.angaben = await seite.evaluate(() =>
    [...document.querySelectorAll("dl.kv dt")].map((dt) => dt.textContent.trim()),
  );
  upd.vorPruefung = await seite.evaluate(() => {
    const knoepfe = [...document.querySelectorAll(".knopf")];
    return {
      // Der Knopf trägt vor der Prüfung nicht „auf X aktualisieren", weil kein X
      // bekannt ist — er lädt zur Prüfung ein.
      einspielenText:
        knoepfe.find((b) => b.textContent.includes("aktualisieren"))?.textContent.trim() ??
        "",
      einspielenGesperrt: knoepfe.find((b) =>
        b.textContent.includes("aktualisieren"),
      )?.disabled,
      // „noch nicht geprüft" steht als Satz da.
      satz: [...document.querySelectorAll(".detail")].some((p) =>
        p.textContent.includes("noch nicht geprüft"),
      ),
      // Und der Verbindungsabbruch ist vorher angekündigt.
      abbruchAngekuendigt: [...document.querySelectorAll(".detail")].some((p) =>
        p.textContent.includes("verliert für einige Sekunden die Verbindung"),
      ),
      // Ohne Sicherung kein Rückweg.
      rueckweg: [...document.querySelectorAll(".knopf")].some((b) =>
        b.textContent.includes("zurück auf"),
      ),
      keineSicherung: [...document.querySelectorAll(".detail")].some((p) =>
        p.textContent.includes("keine Sicherung"),
      ),
    };
  });

  // Prüfen. Die Attrappe liefert Fassung 9.9.9 als Sicherheitsupdate.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".knopf")].find(
      (x) => x.textContent.trim() === "nach Updates suchen",
    );
    b.click();
  });
  await seite.waitForSelector(".band.gut", { timeout: 8000 });
  upd.nachPruefung = await seite.evaluate(() => ({
    meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
    marke: [...document.querySelectorAll(".marke")].map((m) => m.textContent.trim()),
    notizen: document.querySelector(".detail a")?.getAttribute("href") ?? "",
    knopf:
      [...document.querySelectorAll(".knopf")]
        .find((b) => b.textContent.includes("aktualisieren"))
        ?.textContent.trim() ?? "",
    gesperrt: [...document.querySelectorAll(".knopf")].find((b) =>
      b.textContent.includes("aktualisieren"),
    )?.disabled,
  }));

  await bildschirmfoto(seite, "leitstand-updates", { fullPage: true });

  // Die Rückfrage — und danach ABBRECHEN. Ausgeführt wird hier nichts: Der
  // Vorgang tauscht das Binary des laufenden Testservers.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".knopf")].find((x) =>
      x.textContent.includes("aktualisieren"),
    );
    b.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  upd.frage = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      text: d.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: [...d.querySelectorAll(".punkte li")].map((li) => li.textContent.trim()),
      tippfeld: d.querySelector(".tippen") !== null,
      knopf:
        [...d.querySelectorAll(".knopf")]
          .find((b) => b.textContent.includes("aktualisieren"))
          ?.textContent.trim() ?? "",
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(400);
  upd.nachAbbruch = await seite.evaluate(() => ({
    dialogZu: document.querySelector("dialog.rueckfrage") === null,
    // Es läuft nichts: Kein Band über einen laufenden Vorgang.
    keinLauf: ![...document.querySelectorAll(".band")].some((b) =>
      b.textContent.includes("Vorgang läuft"),
    ),
  }));

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  upd.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12i. Zertifikat und ACME. Drei Dinge sind hier nur im Browser zu sehen:
  //
  //      1. Das Formular zeigt NUR, was zur Wahl passt. Ein Anbieterfeld bei
  //         HTTP-01 oder ein Tokenfeld beim Hook wäre die Aufforderung, etwas
  //         einzutragen, das nichts bewirkt.
  //      2. Der Zwischenzustand „eingestellt, aber noch nichts bezogen" ist
  //         benannt. Ohne das sucht jemand den Fehler an der falschen Stelle.
  //      3. Der Rückschritt auf selbstsigniert fragt zurück, und nach dem
  //         ABBRUCH steht die Einstellung noch.
  const zert = {};
  await seite.goto(`${basis}/zertifikate`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".wahl label", { timeout: 5000 });

  zert.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  zert.kopfzustand = await seite.evaluate(
    () => document.querySelector(".kopfzeile .zustand")?.textContent.trim() ?? "",
  );
  zert.angaben = await seite.evaluate(() =>
    [...document.querySelectorAll("dl.kv dt")].map((dt) => dt.textContent.trim()),
  );
  // Selbstsigniert ist benannt, nicht bloß eingefärbt.
  zert.selbstsigniertSatz = await seite.evaluate(
    () => document.querySelector(".anmerkung")?.textContent.trim() ?? "",
  );
  // Und die verwaltete Datei steht dabei: Das Panel versteckt nichts.
  zert.verwalteteDatei = await seite.evaluate(() =>
    [...document.querySelectorAll(".detail")].some((p) => p.textContent.includes("Gespeichert wird in")),
  );

  // Bei „selbstsigniert" stehen keine ACME-Felder da.
  zert.selbstsigniertFelder = await seite.evaluate(() => ({
    email: document.querySelector("#zert-email") !== null,
    methode: document.querySelector("#zert-methode") !== null,
  }));

  // Auf ACME umstellen: Jetzt kommen die Felder, und zwar gestaffelt.
  await seite.click('.wahl label:has-text("Let\'s Encrypt") input');
  await seite.waitForSelector("#zert-email", { timeout: 5000 });
  zert.acmeFelder = await seite.evaluate(() => ({
    email: document.querySelector("#zert-email") !== null,
    namen: document.querySelector("#zert-namen") !== null,
    methode: document.querySelector("#zert-methode") !== null,
    // Bei „automatisch" ist der Anbieter zulässig, aber nicht nötig.
    anbieter: document.querySelector("#zert-anbieter") !== null,
    hook: document.querySelector("#zert-hook-setzen") !== null,
    token: document.querySelector("#zert-token") !== null,
    // Die aufgelösten Namen stehen da, damit niemand raten muss, was „leer" heißt.
    geltend: [...document.querySelectorAll(".detail")].some((p) =>
      p.textContent.includes("Verwendet würde"),
    ),
  }));

  // HTTP-01: kein Anbieterfeld.
  await seite.selectOption("#zert-methode", "http-01");
  await seite.waitForTimeout(200);
  zert.http01 = await seite.evaluate(
    () => document.querySelector("#zert-anbieter") === null,
  );

  // DNS-01 mit Hook: zwei Pfadfelder, kein Token.
  await seite.selectOption("#zert-methode", "dns-01");
  await seite.waitForSelector("#zert-anbieter", { timeout: 5000 });
  await seite.selectOption("#zert-anbieter", "hook");
  await seite.waitForSelector("#zert-hook-setzen", { timeout: 5000 });
  zert.hook = await seite.evaluate(() => ({
    setzen: document.querySelector("#zert-hook-setzen") !== null,
    aufraeumen: document.querySelector("#zert-hook-aufraeumen") !== null,
    token: document.querySelector("#zert-token") !== null,
  }));

  // Cloudflare: Tokenfeld, und es ist ein Passwortfeld.
  await seite.selectOption("#zert-anbieter", "cloudflare");
  await seite.waitForSelector("#zert-token", { timeout: 5000 });
  zert.cloudflare = await seite.evaluate(() => ({
    token: document.querySelector("#zert-token")?.getAttribute("type") ?? "",
    hook: document.querySelector("#zert-hook-setzen") !== null,
    warum: [...document.querySelectorAll(".detail")].some((p) =>
      p.textContent.includes("0600"),
    ),
  }));

  await bildschirmfoto(seite, "leitstand-zertifikat", { fullPage: true });

  // Jetzt gültig ausfüllen und speichern — HTTP-01, damit kein Anbieter nötig
  // ist. Der Weg dorthin führt ABSICHTLICH über die Cloudflare-Wahl von oben:
  // Geschickt werden muss, was zu sehen ist, und nicht der letzte Zustand jedes
  // Feldes. Ohne das ginge der unsichtbare Anbieter mit, und der Server lehnte
  // mit einer Begründung für ein Feld ab, das gar nicht dasteht.
  await seite.selectOption("#zert-methode", "http-01");
  await seite.fill("#zert-email", "admin@example.test");
  await seite.fill("#zert-namen", "panel.example.test");
  await seite.click('form button[type=submit]');
  await seite.waitForSelector(".band.gut", { timeout: 5000 });
  zert.nachSpeichern = await seite.evaluate(() => ({
    meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
    hinweis: document.querySelector(".band.warn")?.textContent.trim() ?? "",
    // Der Zwischenzustand: eingestellt, aber noch nichts bezogen.
    zwischen: [...document.querySelectorAll(".anmerkung")].some((p) =>
      p.textContent.includes("noch kein Zertifikat bezogen"),
    ),
    // Und jetzt ist „jetzt beziehen" offen.
    beziehenOffen: ![...document.querySelectorAll(".knopf")].find((b) =>
      b.textContent.includes("jetzt beziehen"),
    )?.disabled,
  }));

  // Der Rückschritt fragt zurück.
  await seite.click('.wahl label:has-text("selbstsigniert") input');
  await seite.click('form button[type=submit]');
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  zert.rueckschritt = await seite.evaluate(() => {
    const d = document.querySelector("dialog.rueckfrage");
    return {
      frage: d.querySelector(".frage")?.textContent.trim() ?? "",
      punkte: [...d.querySelectorAll(".punkte li")].map((li) => li.textContent.trim()),
      tippfeld: d.querySelector(".tippen") !== null,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(400);
  // Nach dem ABBRUCH steht die Einstellung noch — das prüft, ob die Rückfrage
  // gefragt hat oder nur gefragt aussah.
  await seite.reload({ waitUntil: "domcontentloaded" });
  await seite.waitForSelector(".wahl label", { timeout: 5000 });
  zert.nachAbbruch = await seite.evaluate(
    () => document.querySelector('.wahl label.an .name')?.textContent.trim() ?? "",
  );

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  zert.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12h. Das eigene Konto. Die Passkeys stehen hier NICHT — die brauchen einen
  //      virtuellen Authenticator und haben ihren eigenen Durchlauf
  //      (passkey_e2e.js, Modus „v2"). Was hier geprüft wird, ist der Rest, und
  //      drei Dinge daran sind nur im Browser zu sehen:
  //
  //      1. Der begonnene Wechsel des zweiten Faktors übersteht ein NEULADEN.
  //         Der Zustand liegt auf dem Server; ein halber Wechsel, der beim
  //         Seitenwechsel verschwindet, wäre eine Falle.
  //      2. Der QR-Code kommt tatsächlich an. Er ist ein Bild von /api/v1/… und
  //         die Richtlinie sagt `img-src 'self'` — genau die Stelle, an der das
  //         Projekt zweimal gescheitert ist.
  //      3. Die eigene Sitzung ist in der Liste markiert, und ihr Knopf heißt
  //         „abmelden" und nicht „beenden".
  const konto = {};
  await seite.goto(`${basis}/konto`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("#pw-aktuell", { timeout: 5000 });

  konto.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  konto.bloecke = await seite.evaluate(() =>
    [...document.querySelectorAll("section.platte > b")].map((b) => b.textContent.trim()),
  );
  // Jeder BENANNTE Block sagt, warum es ihn gibt — Grundsatz V. Der erste Block
  // ist ausgenommen und hat deshalb auch keinen Titel: Er zeigt nur Tatsachen
  // (Rolle, angelegt, offene Codes), und die brauchen keine Begründung.
  konto.warum = await seite.evaluate(() =>
    [...document.querySelectorAll("section.platte")]
      .filter((s) => s.querySelector(":scope > b") !== null)
      .map((s) => ({
        titel: s.querySelector(":scope > b").textContent.trim(),
        satz: s.querySelector(".detail")?.textContent.trim() ?? "",
      })),
  );
  konto.sitzungen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle tbody tr")].map((tr) => ({
      diese: tr.querySelector(".marke") !== null,
      knopf: tr.querySelector(".knopf")?.textContent.trim() ?? "",
    })),
  );
  konto.passkeysAus = await seite.evaluate(() =>
    [...document.querySelectorAll("section.platte .detail")].some((p) =>
      p.textContent.includes("abgeschaltet"),
    ),
  );

  // Der Wechsel des zweiten Faktors: beginnen, neu laden, abbrechen.
  await seite.fill("#f2-pass", "ein sehr langes Testpasswort");
  await seite.click('form:has(#f2-pass) button[type=submit]');
  await seite.waitForSelector("#f2-code", { timeout: 5000 });
  konto.wechsel = await seite.evaluate(() => {
    const platte = [...document.querySelectorAll("section.platte")].find((s) =>
      s.querySelector("#f2-code"),
    );
    const bild = platte.querySelector("img");
    return {
      hervorgehoben: platte.classList.contains("offen"),
      frist: platte.querySelector(".anmerkung")?.textContent.trim() ?? "",
      geheimnis: platte.querySelector(".geheimnis code")?.textContent.trim() ?? "",
      qrPfad: bild?.getAttribute("src") ?? "",
      // naturalWidth > 0 heißt: Das Bild ist geladen. Ein von der Richtlinie
      // verworfenes Bild hätte ein <img> mit 0 — genau der Fall, den ein
      // DOM-Test nicht sieht.
      qrGeladen: (bild?.naturalWidth ?? 0) > 0,
    };
  });

  // Neu laden: Der halbe Wechsel steht wieder da.
  await seite.reload({ waitUntil: "domcontentloaded" });
  await seite.waitForSelector("#f2-code", { timeout: 5000 });
  konto.nachNeuladen = true;

  await bildschirmfoto(seite, "leitstand-konto", { fullPage: true });

  // Ein falscher Code stellt nichts um und sagt das. Die Ablehnung ist hier das
  // geprüfte Verhalten — deshalb steht der Pfad in `absichtlich`.
  absichtlich.push("/api/v1/account/2fa/confirm");
  await seite.fill("#f2-code", "000000");
  await seite.click('form:has(#f2-code) button[type=submit]');
  await seite.waitForSelector(".band.schlecht", { timeout: 5000 });
  konto.falscherCode = await seite.evaluate(() => ({
    meldung: document.querySelector(".band.schlecht")?.textContent.trim() ?? "",
    nochOffen: document.querySelector("#f2-code") !== null,
  }));

  // Abbrechen: Der Wechsel ist weg, der bisherige Faktor gilt weiter.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll("section.platte .knopf")].find((x) =>
      x.textContent.includes("Wechsel abbrechen"),
    );
    b.click();
  });
  await seite.waitForTimeout(600);
  konto.nachAbbruch = await seite.evaluate(() => ({
    wechselWeg: document.querySelector("#f2-code") === null,
    meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
  }));

  // Neue Wiederherstellungscodes: Stufe 2, dann eine Liste in einem Dialog, den
  // Escape nicht schließt.
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll("section.platte .knopf")].find(
      (x) => x.textContent.trim() === "Neue Codes erzeugen",
    );
    b.click();
  });
  await seite.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
  konto.codesFrage = await seite.evaluate(
    () => document.querySelector("dialog.rueckfrage .frage")?.textContent.trim() ?? "",
  );
  await seite.click('dialog.rueckfrage button:text("neue Codes erzeugen")');
  await seite.waitForSelector("dialog.codes[open]", { timeout: 5000 });
  konto.codes = await seite.evaluate(() => {
    const d = document.querySelector("dialog.codes");
    const r = d.getBoundingClientRect();
    return {
      anzahl: d.querySelectorAll(".liste li").length,
      warnung: d.querySelector(".warnung")?.textContent.trim() ?? "",
      links: r.left,
      rechts: window.innerWidth - r.right,
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);
  konto.codesNachEscape = await seite.evaluate(
    () => document.querySelector("dialog.codes[open]") !== null,
  );
  await seite.click('dialog.codes button:text("notiert")');
  await seite.waitForTimeout(250);

  // Und die Zahl unten steht danach richtig da.
  konto.codesOffen = await seite.evaluate(() => {
    const dd = [...document.querySelectorAll("dl.kv dd")];
    return dd.map((d) => d.textContent.trim()).find((x) => x.includes("unbenutzt")) ?? "";
  });

  await seite.setViewportSize({ width: 375, height: 800 });
  await seite.waitForTimeout(250);
  konto.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12l. API-Tokens. Der Kern ist die EINMAL-Anzeige, und die ist nur im Browser
  //      zu prüfen: Ein Go-Test sieht den Klartext in der Antwort, aber nicht,
  //      dass er in einem Dialog landet, den Escape nicht schließt, und dass er
  //      nach dem Schließen fort ist.
  //
  //      1. Der Dialog widersteht Escape. Der Token kommt kein zweites Mal, und
  //         ein Dialog, der sich versehentlich schließt, nimmt ihn mit.
  //      2. Er zeigt den fertigen curl-Aufruf. An dem Punkt, an dem man das
  //         Geheimnis in der Hand hält, soll niemand eine Dokumentation suchen.
  //      3. Nach dem Schließen steht der Klartext NIRGENDS mehr auf der Seite.
  //      4. Die gesperrten Flächen stehen als eigener Block da und fehlen in der
  //         Auswahl — genannt, nicht verschwiegen.
  const tk = {};
  await seite.goto(`${basis}/tokens`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle", { timeout: 5000 });

  tk.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  // Der Block „für Tokens gesperrt" mit seinen Marken.
  tk.gesperrt = await seite.evaluate(() => {
    const p = document.querySelector(".platte.gesperrt");
    if (!p) return null;
    return {
      marken: [...p.querySelectorAll(".marke")].map((m) => m.textContent.trim()),
      warum: p.querySelector(".detail")?.textContent.trim() ?? "",
    };
  });

  // Das Formular. Die gesperrten Flächen dürfen darin NICHT auftauchen.
  await seite.click(".werkzeuge .knopf.leise.klein");
  await seite.waitForSelector("form.anlegen", { timeout: 5000 });
  tk.formular = await seite.evaluate(() => ({
    flaechen: [...document.querySelectorAll("form.anlegen .flaechen span")].map((s) =>
      s.textContent.trim(),
    ),
    // Jede Fläche trägt ihre Erklärung im Titelattribut: „schedules" sagt einem
    // Menschen nichts.
    erklaert: [...document.querySelectorAll("form.anlegen .kaestchen")].every(
      (l) => (l.getAttribute("title") ?? "") !== "",
    ),
    // Nur-Lesen ist vorbelegt: Wer die Auswahl übersieht, bekommt den engeren
    // Token.
    nurLesenVorbelegt:
      document.querySelector('form.anlegen input[type=radio]')?.checked ?? null,
    fristen: [...document.querySelectorAll("form.anlegen select option")].map((o) =>
      o.textContent.trim(),
    ),
    saetze: document.querySelectorAll("form.anlegen small").length,
  }));

  // Anlegen: Stufe 2, und die Rückfrage sagt den Umfang.
  await seite.fill("form.anlegen input[type=text]", "e2e-sicherung");
  await seite.evaluate(() => {
    const l = [...document.querySelectorAll("form.anlegen .kaestchen")].find((x) =>
      x.textContent.includes("files"),
    );
    l.querySelector("input").click();
  });
  await seite.click('form.anlegen button[type=submit]');
  await seite.waitForSelector("dialog[open]", { timeout: 5000 });
  tk.frage = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    return {
      text: d.textContent.replace(/\s+/g, " ").trim(),
      tippfeld: d.querySelector("input[type=text]") !== null,
    };
  });
  await seite.evaluate(() => document.querySelector("dialog[open] .knopf.gefahr").click());

  // Und jetzt die Einmal-Anzeige.
  await seite.waitForSelector("dialog.einmal[open]", { timeout: 5000 });
  tk.einmal = await seite.evaluate(() => {
    const d = document.querySelector("dialog.einmal[open]");
    return {
      token: d.querySelector(".geheimnis code")?.textContent.trim() ?? "",
      warnung: d.querySelector(".warnung")?.textContent.trim() ?? "",
      beispiel: d.querySelector(".beispiel")?.textContent.trim() ?? "",
      knoepfe: [...d.querySelectorAll(".knopf")].map((b) => b.textContent.trim()),
    };
  });

  await bildschirmfoto(seite, "leitstand-tokens-einmal", { fullPage: true });

  // Escape schließt ihn NICHT: Der Token kommt kein zweites Mal.
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(300);
  tk.nachEscape = await seite.evaluate(
    () => document.querySelector("dialog.einmal[open]") !== null,
  );

  // Erst der Knopf schließt ihn — und danach steht der Klartext nirgends mehr.
  await seite.evaluate(() => {
    const d = document.querySelector("dialog.einmal[open]");
    [...d.querySelectorAll(".knopf")].find((b) => b.textContent.includes("notiert")).click();
  });
  await seite.waitForTimeout(300);
  tk.nachSchliessen = await seite.evaluate((token) => ({
    dialogZu: document.querySelector("dialog.einmal[open]") === null,
    // Der Klartext darf nirgends im Dokument mehr stehen.
    imDokument: document.body.textContent.includes(token),
    zeilen: document.querySelectorAll("table.tabelle tbody tr").length,
  }), tk.einmal.token);

  // Die Zeile in der Liste: Name, sichtbarer Anfang, Umfang, Zustand — und NICHT
  // der Token.
  tk.zeile = await seite.evaluate(() => {
    const tr = document.querySelector("table.tabelle tbody tr");
    const td = [...tr.querySelectorAll("td")];
    return {
      name: td[0]?.querySelector("b")?.textContent.trim() ?? "",
      anfang: td[0]?.querySelector(".anfang")?.textContent.trim() ?? "",
      umfang: td[2]?.textContent.replace(/\s+/g, " ").trim() ?? "",
      zuletzt: td[4]?.textContent.trim() ?? "",
      zustand: tr.querySelector(".zustand")?.className ?? "",
      handgriff: td[6]?.textContent.trim() ?? "",
    };
  });

  await seite.setViewportSize({ width: 375, height: 900 });
  await seite.waitForTimeout(250);
  tk.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
  }));
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12k. Zeitpläne. Vier Dinge sind hier nur im Browser zu sehen:
  //
  //      1. Der Zeitplan steht ZWEIMAL da: als Satz und als rohes Feld. Ein Test
  //         auf die JSON-Antwort sieht beide Werte, aber nicht, dass beide auf
  //         dem Schirm landen — und der Satz allein wäre eine Auslegung ohne
  //         Beleg.
  //      2. Die Rückfrage für einen root-Eintrag verlangt den HOSTNAMEN, und der
  //         Knopf bleibt gesperrt, bis er stimmt. Das ist Verhalten des Dialogs
  //         und nicht des Servers.
  //      3. Ein fremder Eintrag trägt keine Handgriffe, sondern seine Quelle. Auf
  //         dem Schirm ist das der Unterschied zwischen „geht nicht" und „geht
  //         hier nicht".
  //      4. Die Timer-Tabelle zeigt für einen nie gelaufenen Timer „nie" und
  //         nicht den 1. Januar 1970 — die Stelle, an der ein fehlender
  //         Zeitstempel zu einem echt aussehenden Datum wird.
  const plaene = {};
  await seite.click('.seitenleiste a[href="/cron"]');
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });

  plaene.wesen = await seite.evaluate(
    () => document.querySelector(".wesen")?.textContent.trim() ?? "",
  );
  // Der Satz UND das rohe Feld, Zeile für Zeile.
  // Nur die ERSTE Tabelle: Die Timer-Tabelle weiter unten trägt dieselben
  // Klassen, und ohne die Einschränkung stünden ihre Zeilen als Cron-Einträge in
  // der Auswertung — eine Zahl, die stimmt, und eine Bedeutung, die nicht stimmt.
  plaene.zeilen = await seite.evaluate(() =>
    [...document.querySelectorAll("table.tabelle")[0].querySelectorAll("tbody tr")]
      .filter((tr) => tr.querySelector(".zeile"))
      .map((tr) => ({
        satz: tr.querySelector(".satz")?.textContent.trim() ?? "",
        roh: tr.querySelector(".roh")?.textContent.trim() ?? "",
        befehl: tr.querySelector(".befehl")?.textContent.trim() ?? "",
        aus: tr.classList.contains("aus"),
      })),
  );

  // Ein eigener Eintrag: Handgriffe da. Der erste mit der Marke „vom Panel".
  await seite.evaluate(() => {
    const tab = document.querySelectorAll("table.tabelle")[0];
    const tr = [...tab.querySelectorAll("tbody tr")].find((r) =>
      [...r.querySelectorAll(".marke")].some((m) => m.textContent.includes("vom Panel")),
    );
    tr.querySelector(".zeile").click();
  });
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  plaene.eigener = await seite.evaluate(() => ({
    knoepfe: [...document.querySelectorAll(".inspektor .handgriffe .knopf")].map((b) =>
      b.textContent.trim(),
    ),
    // Auch im Inspektor: Satz und rohes Feld.
    satz: document.querySelector(".inspektor dl.kv dd")?.textContent.trim() ?? "",
    roh: document.querySelector(".inspektor .roh")?.textContent.trim() ?? "",
  }));

  await bildschirmfoto(seite, "leitstand-zeitplaene", { fullPage: true });

  // Ein fremder Eintrag: keine Handgriffe, dafür die Quelle.
  await seite.evaluate(() => {
    const tab = document.querySelectorAll("table.tabelle")[0];
    const tr = [...tab.querySelectorAll("tbody tr")].find((r) => r.querySelector(".pfad"));
    tr.querySelector(".zeile").click();
  });
  await seite.waitForTimeout(300);
  plaene.fremder = await seite.evaluate(() => ({
    knoepfe: document.querySelectorAll(".inspektor .handgriffe .knopf").length,
    anmerkung: document.querySelector(".inspektor .anmerkung")?.textContent.trim() ?? "",
  }));
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(250);

  // Das Formular. Der Zeitplan kommt aus einer Vorlage — der Knopf trägt den
  // Satz im Titelattribut, damit „jede Nacht" nicht geraten werden muss.
  await seite.click(".werkzeuge .knopf.leise.klein");
  await seite.waitForSelector("form.anlegen", { timeout: 5000 });
  plaene.formular = await seite.evaluate(() => ({
    vorlagen: [...document.querySelectorAll(".vorlagen .knopf")].map((b) => ({
      name: b.textContent.trim(),
      satz: b.getAttribute("title") ?? "",
    })),
    // Jedes Feld hat seinen Satz — Grundsatz V: die Oberfläche erklärt sich dort,
    // wo etwas geschieht.
    saetze: [...document.querySelectorAll("form.anlegen label small")].length,
    benutzer: [...document.querySelectorAll("form.anlegen select option")].map((o) =>
      o.textContent.trim(),
    ),
  }));

  // Anlegen als root: Stufe 3. Der Knopf im Dialog bleibt gesperrt, bis der
  // Hostname stimmt — das ist das eigentlich Prüfenswerte.
  await seite.fill('form.anlegen label:nth-of-type(1) input', "e2e-nachtlauf");
  await seite.evaluate(() => {
    const b = [...document.querySelectorAll(".vorlagen .knopf")].find((x) =>
      x.textContent.includes("jede Nacht"),
    );
    b.click();
  });
  await seite.fill('form.anlegen label:nth-of-type(4) input', "/usr/local/bin/e2e.sh --nacht");
  await seite.fill('form.anlegen label:nth-of-type(5) input', "Vom Browsertest angelegt");
  await seite.click('form.anlegen button[type=submit]');
  await seite.waitForSelector("dialog[open]", { timeout: 5000 });

  plaene.frage = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    // Der bestätigende Knopf trägt .gefahr. Nach type=submit zu suchen war
    // falsch: Beide Knöpfe des Dialogs sind type=button, weil ein Enter darin
    // nichts zerstören soll — die Suche fand nichts und lieferte null, was wie
    // „nicht gesperrt" gelesen worden wäre.
    const knopf = d.querySelector(".knopf.gefahr");
    return {
      titel: d.querySelector("h2, b, strong")?.textContent.trim() ?? "",
      text: d.textContent.replace(/\s+/g, " ").trim(),
      // Ein Feld zum Tippen heißt Stufe 3.
      tippfeld: d.querySelector("input[type=text]") !== null,
      knopfGesperrt: knopf?.disabled ?? null,
    };
  });

  // Ein falsches Wort lässt den Knopf gesperrt.
  await seite.fill("dialog[open] input[type=text]", "irgendwas");
  await seite.waitForTimeout(150);
  plaene.frageFalsch = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    return d.querySelector(".knopf.gefahr")?.disabled ?? null;
  });

  // Der richtige Hostname öffnet ihn. Er steht im Statusband und ist abzulesen —
  // der Test liest ihn dort, wie ein Mensch es täte.
  const hostname = await seite.evaluate(
    () => document.querySelector(".statusband .wirt b")?.textContent.trim() ?? "",
  );
  await seite.fill("dialog[open] input[type=text]", hostname);
  await seite.waitForTimeout(150);
  plaene.frageRichtig = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    return { host: true, knopfGesperrt: d.querySelector(".knopf.gefahr")?.disabled ?? null };
  });
  await seite.evaluate(() => {
    document.querySelector("dialog[open] .knopf.leise")?.click();
  });
  await seite.waitForTimeout(300);
  plaene.abgebrochen = await seite.evaluate(
    () => document.querySelector("dialog[open]") === null,
  );

  // Die Timer-Tabelle. „nie" statt eines Datums für den Timer, der noch nie lief.
  plaene.timer = await seite.evaluate(() => {
    const kopf = document.querySelector("#timer");
    if (!kopf) return null;
    // Die zweite Tabelle der Seite ist die der Timer.
    const tabellen = [...document.querySelectorAll("table.tabelle")];
    const tab = tabellen[tabellen.length - 1];
    return [...tab.querySelectorAll("tbody tr")].map((tr) => {
      const td = [...tr.querySelectorAll("td")];
      return {
        unit: td[0]?.querySelector(".satz")?.textContent.trim() ?? "",
        naechster: td[2]?.textContent.trim() ?? "",
        letzter: td[3]?.textContent.trim() ?? "",
      };
    });
  });

  // Und schmal: Tabellen werden unter 600 Pixeln zu Karten, der Körper darf nicht
  // waagerecht scrollen. Diese Seite hat ZWEI Tabellen — die Lektion aus rc.4
  // gilt für jede von ihnen, und gemessen wird sie, nicht vermutet.
  await seite.setViewportSize({ width: 375, height: 900 });
  await seite.waitForTimeout(250);
  plaene.schmal = await seite.evaluate(() => ({
    koerperBreite: document.body.scrollWidth,
    fensterBreite: window.innerWidth,
    // Die Beschriftung der Karten kommt aus data-spalte. Fehlt sie, steht auf dem
    // Telefon eine Spalte ohne Namen.
    beschriftung:
      document.querySelector("table.tabelle tbody td")?.getAttribute("data-spalte") ?? "",
  }));
  await bildschirmfoto(seite, "leitstand-zeitplaene-schmal", { fullPage: true });
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 13. Ein angekündigtes Modul. Bis 0.4.0-rc.2 landete „Docker" stillschweigend
  //     auf der Übersicht — ein Klick, der woanders herauskommt, sieht wie ein
  //     Fehler aus. Geprüft wird, dass die Seite sagt, worum es geht.
  //
  //     Geprüft wird das am Webserver: Docker ist mit dem ersten Schritt der 0.5
  //     ein gebautes Modul und hat eine eigene Seite (Abschnitt 14).
  const bald = {};
  await seite.click('.seitenleiste a[href="/webserver"]');
  await seite.waitForSelector(".platte .satz", { timeout: 5000 });
  bald.pfad = new URL(seite.url()).pathname;
  bald.titel = await seite.evaluate(
    () => document.querySelector(".h1")?.textContent.trim() ?? "",
  );
  bald.marke = await seite.evaluate(
    () => document.querySelector(".kopfzeile .marke")?.textContent.trim() ?? "",
  );
  bald.satz = await seite.evaluate(
    () => document.querySelector(".platte .satz")?.textContent.trim() ?? "",
  );
  bald.ersatz = await seite.evaluate(
    () => document.querySelector(".ersatz a")?.getAttribute("href") ?? "",
  );
  // Der Menüpunkt ist hervorgehoben: Wer hier steht, soll sehen, wo er steht.
  bald.navAktiv = await seite.evaluate(
    () =>
      document.querySelector('.seitenleiste a[aria-current="page"]')?.getAttribute("href") ?? "",
  );
  await bildschirmfoto(seite, "leitstand-bald", { fullPage: true });

  // 14. Das Modul Docker, Schritt 1 der Fassung 0.5. Zwei Dinge sind hier nur im
  //     Browser zu sehen: dass der Menüpunkt nicht mehr auf die Seite „bald"
  //     führt, und dass aus dem Zustand der richtige Handgriff wird. Die
  //     Attrappe meldet ein fehlendes Docker — dann muss genau ein Knopf da
  //     sein, und zwar der, der es einspielt.
  const dock = {};
  await seite.click('.seitenleiste a[href="/docker"]');
  await seite.waitForSelector(".karten .karte", { timeout: 5000 });
  dock.pfad = new URL(seite.url()).pathname;
  dock.titel = await seite.evaluate(
    () => document.querySelector(".h1")?.textContent.trim() ?? "",
  );
  // Drei Karten: Laufzeit, Daemon, Compose. Sie sind der Grund, warum die Seite
  // schon in dieser Fassung existiert — sie halten drei Zustände auseinander,
  // zu denen drei verschiedene Handgriffe gehören.
  dock.karten = await seite.evaluate(() => document.querySelectorAll(".karten .karte").length);
  // Die Anmerkung der SEITE, nicht irgendein Hinweis: Seit Schritt 7 bringt
  // auch die Update-Prüfung einen mit („wieder möglich ab …"), und ein Selektor
  // über alle .hinweis nahm den. Gemeint ist der über der ersten Überschrift —
  // dort steht, was die drei Zustandskarten zu sagen haben.
  dock.anmerkung = await seite.evaluate(() => {
    const ersteUeberschrift = document.querySelector("h2");
    for (const h of document.querySelectorAll(".hinweis")) {
      if (
        ersteUeberschrift &&
        h.compareDocumentPosition(ersteUeberschrift) & Node.DOCUMENT_POSITION_FOLLOWING
      ) {
        return h.textContent.trim();
      }
      if (!ersteUeberschrift) return h.textContent.trim();
    }
    return "";
  });
  // Genau die Knopfreihe der SEITE, nicht die der Werkbank oder des Bestands:
  // Seit Schritt 3 gibt es drei .aktionen-Reihen auf dieser Seite, und ein
  // Selektor über alle drei prüft etwas anderes als gemeint.
  dock.knoepfe = await seite.evaluate(() => {
    const reihe = document.querySelector(".aktionen");
    return reihe ? [...reihe.querySelectorAll(".knopf")].map((k) => k.textContent.trim()) : [];
  });
  // Die Seite „bald" darf hier nicht mehr auftauchen: Sie hat einen eigenen
  // Aufbau (.platte .satz), und wenn der stünde, führte der Menüpunkt weiter auf
  // die Vertröstung statt auf das Modul.
  dock.istBald = await seite.evaluate(() => !!document.querySelector(".platte .satz"));

  // Die Stackwerkbank steht seit Schritt 4 ÜBER der Containerwerkbank — Stacks
  // sind das führende Objekt des Moduls. Genau deshalb wird sie hier zuerst
  // geprüft: Wäre die Reihenfolge anders herum, führe jeder Selektor, der „die
  // erste Werkbank" meint, auf die falsche Tabelle.
  await seite.waitForSelector(".tabelle tbody tr", { timeout: 5000 });
  await klickeInTabelle(seite, "Dienste");
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dock.stacks = await seite.evaluate(() => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === "Dienste"),
    );
    const insp = document.querySelector(".inspektor");
    return {
      reihen: tab
        ? [...tab.querySelectorAll("tbody tr")].map((tr) => ({
            name: tr.querySelector('[data-spalte="Name"]')?.textContent.trim() ?? "",
            dienste: tr.querySelector('[data-spalte="Dienste"]')?.textContent.trim() ?? "",
            zustand: tr.querySelector('[data-spalte="Zustand"] .zustand')?.className ?? "",
            herkunft: tr.querySelector('[data-spalte="Herkunft"]')?.textContent.trim() ?? "",
          }))
        : [],
      titel: insp?.querySelector(".pfad")?.textContent.trim() ?? "",
      // Die Compose-Datei steht im Inspektor. Sie ist der Beleg dafür, dass der
      // Weg über den NAMEN bis zum Text durchgeht — ohne dass je ein Pfad aus
      // dem Browser kam.
      datei: insp?.querySelector("pre")?.textContent.trim() ?? "",
      suche: new URL(location.href).search,
    };
  });
  // Schritt 5: die Handgriffe. Zwei Dinge sind hier nur im Browser zu sehen —
  // dass ein FREMDES Projekt keinen Bearbeiten- und keinen Löschen-Knopf
  // bekommt, und dass „herunterfahren" eine Rückfrage bringt, „starten" bei
  // einem sauberen Stack dagegen nicht.
  dock.stackAktionen = await seite.evaluate(() =>
    [...(document.querySelector(".inspektor")?.querySelectorAll(".aktionen .knopf") ?? [])].map(
      (k) => k.textContent.trim(),
    ),
  );

  // „herunterfahren" ist Stufe 2: Dialog ohne Tippfeld.
  await seite.evaluate(() => {
    const knopf = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find(
      (k) => k.textContent.trim() === "herunterfahren",
    );
    knopf?.click();
  });
  await seite.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
  dock.stackFrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    if (!d) return { offen: false };
    return {
      offen: true,
      text: d.textContent.trim().slice(0, 400),
      tippfeld: !!d.querySelector('input[type="text"]'),
    };
  });
  await seite.keyboard.press("Escape");
  await seite.waitForTimeout(100);

  // Der Editor. Er lädt CodeMirror dynamisch nach — genau der Weg, an dem das
  // Projekt schon zweimal an der Content-Security-Policy gescheitert ist.
  await seite.evaluate(() => {
    const knopf = [...document.querySelectorAll(".inspektor .aktionen .knopf")].find(
      (k) => k.textContent.trim() === "bearbeiten",
    );
    knopf?.click();
  });
  await seite.waitForSelector(".cm-editor", { timeout: 8000 }).catch(() => {});
  dock.stackEditor = await seite.evaluate(() => ({
    da: !!document.querySelector(".cm-editor"),
    // Der Inhalt der Compose-Datei muss im Editor stehen und nicht bloß im
    // Inspektor daneben.
    inhalt: document.querySelector(".cm-editor")?.textContent?.slice(0, 200) ?? "",
    knoepfe: [...document.querySelectorAll(".editor .kopf .knopf")].map((k) =>
      k.textContent.trim(),
    ),
  }));
  // Der sichtbare Ausschnitt genügt: Die Docker-Seite ist mit Editor, Werkbank,
  // Containertabelle und Bestand mehrere tausend Pixel hoch, und zu sehen ist
  // hier nur, ob der Editor aufgegangen ist.
  await bildschirmfoto(seite, "leitstand-docker-editor");
  // Editor zu.
  await seite.evaluate(() => {
    const knopf = [...document.querySelectorAll(".editor .kopf .knopf")].find(
      (k) => k.textContent.trim() === "abbrechen",
    );
    knopf?.click();
  });
  await seite.waitForTimeout(150);

  // Das FREMDE Projekt: lesbar, aber ohne Bearbeiten und ohne Löschen.
  await seite.evaluate(() => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === "Dienste"),
    );
    const zeilen = [...(tab?.querySelectorAll("tbody tr") ?? [])];
    const fremd = zeilen.find(
      (tr) => tr.querySelector('[data-spalte="Herkunft"]')?.textContent.trim() === "fremd",
    );
    fremd?.querySelector('[data-spalte="Name"] button')?.click();
  });
  await seite.waitForTimeout(300);
  dock.stackFremd = await seite.evaluate(() => {
    const insp = document.querySelector(".inspektor");
    return {
      titel: insp?.querySelector(".pfad")?.textContent.trim() ?? "",
      knoepfe: [...(insp?.querySelectorAll(".aktionen .knopf") ?? [])].map((k) =>
        k.textContent.trim(),
      ),
    };
  });

  // Zumachen, bevor die Containerwerkbank drankommt: Sonst stünden zwei
  // Inspektoren nebeneinander, und jeder Selektor darauf nähme den oberen.
  await seite.click(".inspektor .zu");
  await seite.waitForTimeout(200);

  // Die Containerwerkbank. Zwei Dinge sind hier nur im Browser zu sehen: dass
  // die auffälligen Zeilen oben stehen (die Sortierung kommt vom Server, aber ob
  // sie ankommt, sagt nur die gerenderte Tabelle), und dass der Inspektor mit
  // der Auswahl in der Adresse auf- und mit dem Zurück-Knopf wieder zugeht.
  // Die Containertabelle heraussuchen und nicht die erste beste nehmen: Der
  // Bestand bringt drei weitere mit. Erkennbar ist sie an der Spalte „Ports".
  dock.reihen = await seite.evaluate(() => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === "Ports"),
    );
    if (!tab) return [];
    return [...tab.querySelectorAll("tbody tr")].map((tr) => ({
      name: tr.querySelector('[data-spalte="Name"]')?.textContent.trim() ?? "",
      zustand: tr.querySelector('[data-spalte="Zustand"] .zustand')?.className ?? "",
    }));
  });

  await klickeInTabelle(seite, "Ports");
  await seite.waitForSelector(".inspektor", { timeout: 5000 });
  dock.nachKlick = {
    suche: new URL(seite.url()).search,
    titel: await seite.evaluate(
      () => document.querySelector(".inspektor .pfad")?.textContent.trim() ?? "",
    ),
    // Das Protokoll kommt MIT dem Detail und nicht als zweiter Aufruf.
    auszug: await seite.evaluate(
      () => document.querySelector(".inspektor pre")?.textContent.trim() ?? "",
    ),
    handgriffe: await seite.evaluate(() =>
      [...document.querySelectorAll(".inspektor .aktionen .knopf")].map((k) => k.textContent.trim()),
    ),
  };

  // Die Rückfrage beim Stoppen: Stufe 2, also Dialog ohne Tippfeld.
  const stopKnopf = await seite.$(".inspektor .aktionen .knopf");
  if (stopKnopf) await stopKnopf.click();
  await seite.waitForSelector("dialog[open]", { timeout: 5000 }).catch(() => {});
  dock.rueckfrage = await seite.evaluate(() => {
    const d = document.querySelector("dialog[open]");
    if (!d) return { offen: false };
    return {
      offen: true,
      frage: d.querySelector("h2, .frage")?.textContent.trim() ?? d.textContent.trim().slice(0, 80),
      tippfeld: !!d.querySelector('input[type="text"]'),
    };
  });
  await seite.keyboard.press("Escape");

  await seite.goBack();
  await seite.waitForTimeout(200);
  dock.nachZurueck = {
    inspektor: await seite.evaluate(() => !!document.querySelector(".inspektor")),
    suche: new URL(seite.url()).search,
  };

  // Der Bestand. Zwei Dinge sind hier nur im Browser zu sehen: dass an einem
  // BENUTZTEN Abbild kein Entfernen-Knopf steht (Docker weigerte sich, und der
  // Knopf wäre dann selbst der Fehler), und dass die Zeile „freigebbar" ankommt
  // — sie ist die Frage, mit der jemand diese Seite öffnet.
  dock.bestand = await seite.evaluate(() => {
    const ueberschriften = [...document.querySelectorAll("h2")].map((h) => h.textContent.trim());
    const tabellen = [...document.querySelectorAll(".tabelle")];
    const platte = tabellen.find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.includes("freigebbar")),
    );
    // Die Abbildtabelle des BESTANDS, erkennbar an „Abbild" UND „Größe": Seit
    // Schritt 7 hat auch die Update-Prüfung eine Spalte „Abbild", und ein
    // Selektor über die erste passende Tabelle nahm die falsche — er bestand
    // weiter und prüfte etwas anderes.
    const abbilder = tabellen.find((t) => {
      const kopf = [...t.querySelectorAll("th")].map((th) => th.textContent.trim());
      return kopf.includes("Abbild") && kopf.some((x) => x.startsWith("Gr"));
    });
    const zeilen = abbilder
      ? [...abbilder.querySelectorAll("tbody tr")].map((tr) => ({
          text: tr.querySelector('[data-spalte="Abbild"]')?.textContent.trim() ?? "",
          knopf: !!tr.querySelector(".knopf"),
        }))
      : [];
    return {
      ueberschriften,
      platteDa: !!platte,
      freigebbar: platte
        ? (platte.querySelector('tbody [data-spalte="freigebbar"]')?.textContent.trim() ?? "")
        : "",
      abbilder: zeilen,
      // Die Aufräumreihe: die .aktionen, deren Knöpfe „wegräumen" oder „leeren"
      // heißen. Ein Selektor über alle .aktionen nähme die Seitenknöpfe mit.
      aufraeumKnoepfe: [...document.querySelectorAll(".aktionen")]
        .map((reihe) => [...reihe.querySelectorAll(".knopf")].map((k) => k.textContent.trim()))
        .find((texte) => texte.some((x) => x.includes("wegräumen") || x.includes("leeren"))) ?? [],
    };
  });
  // Schritt 6: die Portübersicht. Der Kern ist EIN Urteil — ein Container, der
  // auf 0.0.0.0 veröffentlicht, ist aus dem Netz erreichbar, auch wenn ufw läuft
  // und den Port nicht kennt. Ob dieser Satz ankommt, sagt nur die gerenderte
  // Seite: Der Server kann ihn schicken, und die Fläche kann ihn trotzdem
  // verschlucken.
  dock.ports = await seite.evaluate(() => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === "gebunden an"),
    );
    return {
      zeilen: tab
        ? [...tab.querySelectorAll("tbody tr")].map((tr) => ({
            port: tr.querySelector('[data-spalte="Port"]')?.textContent.trim() ?? "",
            adresse: tr.querySelector('[data-spalte="gebunden an"]')?.textContent.trim() ?? "",
            urteil: tr.querySelector('[data-spalte="erreichbar"]')?.textContent.trim() ?? "",
            stufe: tr.querySelector('[data-spalte="erreichbar"] .zustand')?.className ?? "",
          }))
        : [],
      // Der erklärende Satz steht ÜBER der Tabelle. Er ist die eigentliche
      // Auskunft der Seite; ohne ihn ist ein rotes Feld nur ein rotes Feld.
      warnung: [...document.querySelectorAll(".warnung")]
        .map((w) => w.textContent.trim())
        .find((x) => x.includes("Docker trägt seine Weiterleitungen")) ?? "",
    };
  });

  // Schritt 7: die Update-Prüfung. Zwei Dinge sind hier nur im Browser zu
  // sehen — dass „nicht geprüft" als EIGENE Aussage dasteht und nicht als
  // Abwesenheit, und dass der Knopf zum Aktualisieren am Stack hängt und nicht
  // am Abbild.
  dock.updates = await seite.evaluate(() => {
    const tab = [...document.querySelectorAll(".tabelle")].find((t) =>
      [...t.querySelectorAll("th")].some((th) => th.textContent.trim() === "Stand"),
    );
    return {
      zeilen: tab
        ? [...tab.querySelectorAll("tbody tr")].map((tr) => ({
            ref: tr.querySelector('[data-spalte="Abbild"]')?.textContent.trim() ?? "",
            stand: tr.querySelector('[data-spalte="Stand"]')?.textContent.trim() ?? "",
            stufe: tr.querySelector('[data-spalte="Stand"] .zustand')?.className ?? "",
            gebrauch: tr.querySelector('[data-spalte="benutzt von"]')?.textContent.trim() ?? "",
            knopf: tr.querySelector(".knopf")?.textContent.trim() ?? "",
          }))
        : [],
      // Der Satz zu den ungeprüften Abbildern: Er sagt, dass sie keine
      // Beruhigung sind. Ohne ihn ist „nicht geprüft" eine leere Zelle.
      ungeprueftSatz: [...document.querySelectorAll(".hinweis")]
        .map((h) => h.textContent.trim())
        .find((x) => x.includes("Das heißt nicht, dass sie aktuell sind")) ?? "",
    };
  });

  // Der Ereignisstrom. Er beginnt ZUGEKLAPPT — er hält einen docker-Prozess auf
  // dem Server, und dafür soll niemand zahlen, der die Seite nur geöffnet hat.
  dock.ereignisse = { vorherOffen: await seite.evaluate(() => !!document.querySelector(".ereignisse .tabelle")) };
  await seite.evaluate(() => {
    const knopf = [...document.querySelectorAll(".ereignisse .kopf")].at(0);
    knopf?.click();
  });
  await seite
    .waitForFunction(() => !!document.querySelector(".ereignisse table tbody tr"), null, {
      timeout: 5000,
    })
    .catch(() => {});
  dock.ereignisse.zeilen = await seite.evaluate(() =>
    [...document.querySelectorAll(".ereignisse table tbody tr")].map((tr) => ({
      aktion: tr.querySelector('[data-spalte="Aktion"]')?.textContent.trim() ?? "",
      stufe: tr.querySelector('[data-spalte="Aktion"] .zustand')?.className ?? "",
      objekt: tr.querySelector('[data-spalte="Objekt"]')?.textContent.trim() ?? "",
    })),
  );

  dock.navAktiv = await seite.evaluate(
    () =>
      document.querySelector('.seitenleiste a[aria-current="page"]')?.getAttribute("href") ?? "",
  );
  await bildschirmfoto(seite, "leitstand-docker", { fullPage: true });

  await browser.close();

  console.log(
    JSON.stringify({
      verstoesse,
      fehler,
      fehlend,
      montiert,
      uebersicht,
      titelSitz,
      rahmenSitz,
      palette,
      dienste,
      pakete,
      logs,
      firewall,
      dateien,
      schreiben,
      editor,
      audit,
      konten,
      zugaenge,
      zert,
      upd,
      konto,
      plaene,
      tk,
      fremdeRolle,
      bald,
      dock,
      zweige,
      schmal,
      strich,
      live,
      ablesung,
    }),
  );
}

main().catch((e) => {
  console.error(e);
  console.error(gesammelt.join("\n"));
  process.exit(1);
});
