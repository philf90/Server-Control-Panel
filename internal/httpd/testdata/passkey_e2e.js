// passkey_e2e.js — treibt einen echten Browser mit virtuellem Authenticator
// durch den Passkey-Durchlauf. Aufgerufen aus den E2E-Tests.
//
// argv: mode baseURL username password sessionCookieValue chromiumPath
//   mode = "flow"   — registrieren, abmelden, mit Passkey anmelden (positiv)
//   mode = "tamper" — wie flow, aber die Assertion beim Anmelden verfälschen;
//                     die Anmeldung MUSS scheitern (negativ)
//   mode = "forgot" — registrieren, abmelden, Passwort über "Passwort
//                     vergessen" per Passkey neu setzen (positiv)
//   mode = "v2"     — dieselbe Registrierung über die NEUE Oberfläche
//                     (/konto), danach Anmeldung mit diesem Passkey, umbenennen
//                     und entfernen (positiv)
//   mode = "forgot-nouv" — derselbe Weg mit einem Authenticator, der nichts am
//                     Gerät prüft; die Zurücksetzung MUSS scheitern (negativ)
const { chromium } = require("playwright");

const [, , mode, baseURL, username, password, sessionCookie, chromiumPath] = process.argv;

// Muss mit e2eNewPassword im Go-Test übereinstimmen.
const NEW_PASSWORD = "ein frisches langes Passwort";

(async () => {
  const browser = await chromium.launch({ executablePath: chromiumPath });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  // Ohne Prüfung am Gerät darf die Zurücksetzung nicht gelingen — dafür ein
  // Authenticator, der keine Benutzerprüfung vorweist.
  const withUV = mode !== "forgot-nouv";

  const cdp = await ctx.newCDPSession(page);
  await cdp.send("WebAuthn.enable");
  await cdp.send("WebAuthn.addVirtualAuthenticator", {
    options: {
      protocol: "ctap2",
      transport: "internal",
      hasResidentKey: true,
      hasUserVerification: withUV,
      isUserVerified: withUV,
      automaticPresenceSimulation: true,
    },
  });

  // Angemeldet starten und einen Passkey registrieren.
  await ctx.addCookies([
    { name: "asylum_session", value: sessionCookie, domain: "localhost", path: "/", secure: true, httpOnly: true, sameSite: "Strict" },
  ]);

  if (mode === "v2") {
    // Dieselbe Zeremonie über die NEUE Oberfläche. Der Nachweis, auf den es
    // ankommt, ist nicht „ein Eintrag erscheint in der Liste", sondern: Ein über
    // /konto registrierter Passkey trägt eine echte Anmeldung. Alles zwischen
    // Browser und go-webauthn — die Umrechnung base64url ↔ ArrayBuffer in
    // lib/api.ts, die Durchreichung der Optionen, das Ticket — ist genau dann
    // richtig, und nur dann.
    const beobachtet = {};
    // Ein Laufzeitfehler im Bundle wäre hier sonst unsichtbar: Die Seite bliebe
    // leer, und der Test meldete nur „Selektor nicht gefunden".
    page.on("pageerror", (e) => console.error("BROWSER-FEHLER " + e.message));
    await page.goto(baseURL + "/konto");
    await page.waitForSelector("#pk-name", { timeout: 10000 });

    beobachtet.warum = await page.evaluate(() => {
      const abschnitte = [...document.querySelectorAll("section.platte")];
      const pk = abschnitte.find((s) => s.querySelector("#pk-name"));
      return pk?.querySelector(".detail")?.textContent.trim() ?? "";
    });
    beobachtet.vorher = await page.evaluate(
      () => document.querySelectorAll(".passkeys li").length,
    );

    await page.fill("#pk-name", "Neue Oberfläche");
    await page.fill("#pk-pass", password);
    await page.click('form:has(#pk-name) button[type=submit]');
    await page.waitForSelector(".passkeys li", { timeout: 15000 });

    beobachtet.nachher = await page.evaluate(() => {
      const li = document.querySelector(".passkeys li");
      return {
        name: li.querySelector(".name")?.textContent.trim() ?? "",
        marke: li.querySelector(".marke")?.textContent.trim() ?? "",
        detail: li.querySelector(".detail")?.textContent.trim() ?? "",
        meldung: document.querySelector(".band.gut")?.textContent.trim() ?? "",
      };
    });
    // Das Passwortfeld ist danach leer — es soll nicht gefüllt stehen bleiben.
    beobachtet.feldLeer = await page.evaluate(
      () => document.querySelector("#pk-pass").value === "",
    );

    // Umbenennen.
    await page.click('.passkeys li button:text("umbenennen")');
    await page.fill(".passkeys .umbenennen input", "Umbenanntes Gerät");
    await page.click('.passkeys .umbenennen button[type=submit]');
    await page.waitForTimeout(600);
    beobachtet.nachUmbenennen = await page.evaluate(
      () => document.querySelector(".passkeys .name")?.textContent.trim() ?? "",
    );

    // Und jetzt der Punkt: abmelden und mit diesem Passkey anmelden.
    await ctx.clearCookies();
    await page.goto(baseURL + "/login");
    await page.fill("#username", username);
    await page.fill("#password", password);
    await page.click("#passkey-login");
    await page.waitForURL(baseURL + "/", { timeout: 10000 });
    beobachtet.anmeldungGeglueckt = true;

    // Zurück auf die neue Kontoseite: Der Passkey ist jetzt benutzt, und das
    // Entfernen fragt mit seinem NAMEN zurück.
    await page.goto(baseURL + "/konto");
    await page.waitForSelector(".passkeys li", { timeout: 10000 });
    beobachtet.zuletzt = await page.evaluate(
      () => document.querySelector(".passkeys .detail")?.textContent.trim() ?? "",
    );

    await page.click('.passkeys li button:text("entfernen")');
    await page.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
    beobachtet.frage = await page.evaluate(() => {
      const d = document.querySelector("dialog.rueckfrage");
      return {
        text: d.querySelector(".frage")?.textContent.trim() ?? "",
        punkte: [...d.querySelectorAll(".punkte li")].map((li) => li.textContent.trim()),
        tippfeld: d.querySelector(".tippen") !== null,
      };
    });
    // Erst abbrechen: Der Passkey muss danach noch da sein.
    await page.keyboard.press("Escape");
    await page.waitForTimeout(400);
    beobachtet.nachAbbruch = await page.evaluate(
      () => document.querySelectorAll(".passkeys li").length,
    );

    await page.click('.passkeys li button:text("entfernen")');
    await page.waitForSelector("dialog.rueckfrage[open]", { timeout: 5000 });
    await page.click('dialog.rueckfrage button:text("entfernen")');
    await page.waitForTimeout(800);
    beobachtet.nachEntfernen = await page.evaluate(
      () => document.querySelectorAll(".passkeys li").length,
    );

    console.log("V2-BEOBACHTET " + JSON.stringify(beobachtet));
    console.log("V2-OK");
    await browser.close();
    return;
  }

  await page.goto(baseURL + "/alt/account");
  await page.fill("#pk-label", "E2E-Key");
  await page.fill("#pk-pass", password);
  await page.click("#passkey-add button");
  await page.waitForSelector("text=E2E-Key", { timeout: 10000 });

  // Abmelden.
  await ctx.clearCookies();

  if (mode === "tamper") {
    // Die Antwort des Authenticators unterwegs verfälschen: die Signatur
    // umdrehen. Der Server muss das ablehnen.
    await page.route("**/login/passkey/finish", async (route) => {
      const params = new URLSearchParams(route.request().postData());
      const cred = JSON.parse(params.get("credential"));
      cred.response.signature = cred.response.signature.split("").reverse().join("");
      params.set("credential", JSON.stringify(cred));
      await route.continue({ postData: params.toString() });
    });

    await page.goto(baseURL + "/login");
    await page.fill("#username", username);
    await page.fill("#password", password);
    await page.click("#passkey-login");
    // Warten und prüfen, dass KEINE Anmeldung zustande kam: weiterhin /login.
    await page.waitForTimeout(3000);
    const url = page.url();
    if (url.replace(/\/$/, "").endsWith("/login")) {
      console.log("TAMPER-REJECTED");
    } else {
      console.error("TAMPER-ACCEPTED " + url);
      process.exit(1);
    }
    await browser.close();
    return;
  }

  if (mode === "forgot" || mode === "forgot-nouv") {
    // Vergessenes Passwort: kein Anmeldename, kein Passwort — nur der Passkey.
    await page.goto(baseURL + "/login/forgot");
    await page.click("#passkey-reset");

    if (mode === "forgot-nouv") {
      // Entweder lehnt der Browser die Zeremonie ab (userVerification
      // "required" bei einem Authenticator, der nichts prüft) oder der Server
      // verwirft die Assertion. Beides ist richtig; falsch wäre einzig, auf
      // dem Formular für das neue Passwort zu landen.
      await page.waitForTimeout(4000);
      if (page.url().includes("/login/forgot/new")) {
        console.error("NOUV-ACCEPTED " + page.url());
        process.exit(1);
      }
      console.log("NOUV-REJECTED");
      await browser.close();
      return;
    }

    await page.waitForURL(baseURL + "/login/forgot/new", { timeout: 10000 });
    await page.fill("#new_password", NEW_PASSWORD);
    await page.fill("#new_password_confirm", NEW_PASSWORD);
    await page.click("button[type=submit]");
    // Der Weg endet auf der Anmeldeseite mit der Bestätigung.
    await page.waitForSelector("text=Das Passwort wurde geändert", { timeout: 10000 });
    console.log("FORGOT-OK");
    await browser.close();
    return;
  }

  // Positiver Fall: mit Passkey anmelden.
  await page.goto(baseURL + "/login");
  await page.fill("#username", username);
  await page.fill("#password", password);
  await page.click("#passkey-login");
  await page.waitForURL(baseURL + "/", { timeout: 10000 });
  console.log("E2E-OK");
  await browser.close();
})().catch((e) => {
  console.error("E2E-ERR " + (e && e.message ? e.message : e));
  process.exit(1);
});
