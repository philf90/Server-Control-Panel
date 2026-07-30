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
  const fehlend = [];
  seite.on("response", (r) => {
    if (r.status() >= 400) {
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
