// passkey_e2e.js — treibt einen echten Browser mit virtuellem Authenticator
// durch den Passkey-Durchlauf. Aufgerufen aus den E2E-Tests.
//
// argv: mode baseURL username password sessionCookieValue chromiumPath
//   mode = "flow"   — registrieren, abmelden, mit Passkey anmelden (positiv)
//   mode = "tamper" — wie flow, aber die Assertion beim Anmelden verfälschen;
//                     die Anmeldung MUSS scheitern (negativ)
const { chromium } = require("playwright");

const [, , mode, baseURL, username, password, sessionCookie, chromiumPath] = process.argv;

(async () => {
  const browser = await chromium.launch({ executablePath: chromiumPath });
  const ctx = await browser.newContext({ ignoreHTTPSErrors: true });
  const page = await ctx.newPage();

  const cdp = await ctx.newCDPSession(page);
  await cdp.send("WebAuthn.enable");
  await cdp.send("WebAuthn.addVirtualAuthenticator", {
    options: {
      protocol: "ctap2",
      transport: "internal",
      hasResidentKey: true,
      hasUserVerification: true,
      isUserVerified: true,
      automaticPresenceSimulation: true,
    },
  });

  // Angemeldet starten und einen Passkey registrieren.
  await ctx.addCookies([
    { name: "asylum_session", value: sessionCookie, domain: "localhost", path: "/", secure: true, httpOnly: true, sameSite: "Strict" },
  ]);
  await page.goto(baseURL + "/account");
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
