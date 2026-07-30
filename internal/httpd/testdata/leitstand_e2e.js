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
    if (r.status() >= 400 && r.status() !== 409 && r.status() !== 412) {
      fehlend.push(`${r.status()} ${r.url()}`);
      gesammelt.push(`response: ${r.status()} ${r.url()}`);
    }
  });

  // Bewusst nicht "networkidle": Die Anwendung hält den Live-Kanal dauerhaft
  // offen, das Netz wird also nie ruhig. Gewartet wird stattdessen unten auf
  // die Kachel — auf das Ergebnis, nicht auf einen Zustand des Netzes.
  await seite.goto(`${basis}/v2/`, { waitUntil: "domcontentloaded" });

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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-palette.png`,
    });
  }

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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });
  await seite.waitForTimeout(200);

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.mouse.move(0, 0);
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-uebersicht.png`,
      fullPage: true,
    });
  }

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
  await seite.click('.seitenleiste a[href="/v2/dienste"]');
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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dienste.png`,
      fullPage: true,
    });
  }

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
  await seite.goto(`${basis}/v2/dienste?unit=nginx.service`, {
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
  await seite.goto(`${basis}/v2/dienste?unit=nginx.service`, {
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dienste-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 9. Das Modul Pakete und die Vorgangsplatte. Hier hängt am Browser, was kein
  //    Go-Test sehen kann: Kommen die Zeilen über den Ereignisstrom an, und
  //    findet jemand, der die Seite neu lädt, den Vorgang vor?
  const pakete = {};
  await seite.goto(`${basis}/v2/pakete`, { waitUntil: "domcontentloaded" });
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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-pakete.png`,
      fullPage: true,
    });
  }

  // Neu laden: Der Vorgang ist auf dem Server, nicht in der Seite. Wer
  // zurückkommt, findet ihn vor — mit denselben Zeilen.
  await seite.goto(`${basis}/v2/pakete`, { waitUntil: "domcontentloaded" });
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-pakete-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 10. Das Modul Logs. Der zweite Strom des Panels — und ein anderer als beim
  //     Vorgang: Er hat kein Ende, das der Server bestimmt. Geprüft wird genau
  //     das: Bleibt er offen, kommen Zeilen nach, gelten die Filter aus der
  //     Adresse auch für ihn, und wird er beim Verlassen der Seite angehalten?
  const logs = {};
  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
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
  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
  await seite.waitForSelector("table.tabelle tbody tr", { timeout: 5000 });
  await seite.click(".filter .verfolgen");
  await seite.waitForSelector(".filter .verfolgen .puls", { timeout: 5000 });
  logs.stromGeoeffnet = angefragt.some((u) => u.includes("/api/v1/logs/follow"));

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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-logs.png`,
      fullPage: true,
    });
  }

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
  await seite.click('.seitenleiste a[href="/v2/dienste"]');
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

  await seite.goto(`${basis}/v2/logs`, { waitUntil: "domcontentloaded" });
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-logs-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 11. Das Modul Firewall — Grundsatz VI, „Was schiefgehen kann, hat einen
  //     Rückweg." Geprüft wird die Probe: Steht sie ganz oben? Zählt der
  //     Countdown herunter? Und beendet der Knopf sie wirklich?
  const firewall = {};
  await seite.goto(`${basis}/v2/firewall`, { waitUntil: "domcontentloaded" });
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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-firewall.png`,
      fullPage: true,
    });
  }

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
  await seite.goto(`${basis}/v2/firewall`, { waitUntil: "domcontentloaded" });
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-firewall-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12. Das Modul Dateien. Es ist das erste, dessen Ort in der Adresse steht und
  //     dessen Bewegung ein Schritt im Verlauf ist — und genau das prüft ein
  //     Go-Test nicht: Ob der Zurück-Knopf eine Ebene höher führt, ist eine
  //     Aussage über history.pushState und nicht über die Antwort des Servers.
  const wurzel = process.env.ASYLUM_E2E_DATEIWURZEL ?? "";
  const dateien = {};
  await seite.goto(`${basis}/v2/dateien?pfad=${encodeURIComponent(wurzel)}`, {
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
  dateien.alteAnsicht = await seite.evaluate(
    () => document.querySelector(".fuss a")?.getAttribute("href") ?? "",
  );

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien.png`,
      fullPage: true,
    });
  }

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
    `${basis}/v2/dateien?pfad=${encodeURIComponent(wurzel + "/schreibbar")}` +
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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-inspektor.png`,
      fullPage: true,
    });
  }

  // Der gesperrte Eintrag: sichtbar, benannt — und ohne einen Handgriff, der
  // seinen Inhalt anfassen würde. Der Knopf wäre bereits der Fehler, auch wenn
  // der Endpunkt danach 403 antwortet.
  await seite.goto(
    `${basis}/v2/dateien?pfad=${encodeURIComponent(wurzel)}` +
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
  await seite.goto(`${basis}/v2/dateien?pfad=${encodeURIComponent(wurzel)}`, {
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
  await seite.waitForFunction(
    () => document.querySelector(".band.info") === null,
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-schmal.png`,
      fullPage: true,
    });
  }
  await seite.setViewportSize({ width: 1280, height: 720 });

  // 12b. Die Schreibvorgänge. Der Kern ist die Rückfrage: Ohne Bestätigung darf
  //      NICHTS geschehen, und bei einem Ordner mit Inhalt muss der Dialog nach
  //      einem getippten Wort fragen. Bis 0.3.0-rc.5 waren dreizehn Rückfragen im
  //      Projekt so gebaut, dass keine einzige gefragt hat — deshalb wird hier
  //      nicht nur der Dialog gezählt, sondern nach dem Abbruch geprüft, dass der
  //      Eintrag noch in der Liste steht.
  const schreiben = {};
  const schreibbar = `${wurzel}/schreibbar`;
  await seite.goto(`${basis}/v2/dateien?pfad=${encodeURIComponent(schreibbar)}`, {
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
    `${basis}/v2/dateien?pfad=${encodeURIComponent(schreibbar)}` +
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-loeschfrage.png`,
    });
  }

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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-zielwahl.png`,
      fullPage: true,
    });
  }

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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-schreiben.png`,
      fullPage: true,
    });
  }

  // Und die Gegenprobe: In der Leseworzel gibt es keine Werkstatt.
  await seite.goto(`${basis}/v2/dateien?pfad=${encodeURIComponent(wurzel)}`, {
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
    `${basis}/v2/dateien?pfad=${encodeURIComponent(schreibbar)}` +
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

  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-dateien-editor.png`,
      fullPage: true,
    });
  }

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

  // 13. Ein angekündigtes Modul. Bis 0.4.0-rc.2 landete „Docker" stillschweigend
  //     auf der Übersicht — ein Klick, der woanders herauskommt, sieht wie ein
  //     Fehler aus. Geprüft wird, dass die Seite sagt, worum es geht.
  const bald = {};
  await seite.click('.seitenleiste a[href="/v2/docker"]');
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
  if (process.env.ASYLUM_E2E_SHOTS) {
    await seite.screenshot({
      path: `${process.env.ASYLUM_E2E_SHOTS}/leitstand-bald.png`,
      fullPage: true,
    });
  }

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
      bald,
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
