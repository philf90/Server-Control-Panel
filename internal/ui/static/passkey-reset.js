// passkey-reset.js — Nachweis für ein vergessenes Passwort.
//
// Unterschied zur Anmeldung (passkey-login.js): Es gibt hier weder Benutzername
// noch Passwort. Der Server liefert eine Zeremonie ohne allowCredentials, der
// Browser bietet von sich aus an, welche Passkeys er für diese Domain hat. Ohne
// WebAuthn bleibt der Knopf verborgen und die Seite nennt nur den Weg über SSH.
(function () {
  "use strict";

  var btn = document.getElementById("passkey-reset");
  if (!btn || !window.PublicKeyCredential || !navigator.credentials) {
    return;
  }
  btn.hidden = false;

  var status = document.getElementById("pk-reset-status");

  function b64urlToBuf(s) {
    s = s.replace(/-/g, "+").replace(/_/g, "/");
    while (s.length % 4) {
      s += "=";
    }
    var bin = atob(s);
    var buf = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) {
      buf[i] = bin.charCodeAt(i);
    }
    return buf.buffer;
  }

  function bufToB64url(buf) {
    var bytes = new Uint8Array(buf);
    var s = "";
    for (var i = 0; i < bytes.length; i++) {
      s += String.fromCharCode(bytes[i]);
    }
    return btoa(s).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
  }

  function say(msg, kind) {
    status.hidden = false;
    status.textContent = msg;
    status.className = kind === "error" ? "hint warn-text" : "hint";
  }

  function form_encode(obj) {
    return Object.keys(obj)
      .map(function (k) {
        return encodeURIComponent(k) + "=" + encodeURIComponent(obj[k]);
      })
      .join("&");
  }

  function post(url, fields) {
    return fetch(url, {
      method: "POST",
      headers: { "Content-Type": "application/x-www-form-urlencoded" },
      body: form_encode(fields),
    });
  }

  btn.addEventListener("click", function () {
    say("Bestätige den Passkey auf deinem Gerät …");

    post("/login/forgot/begin", {})
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Der Vorgang ließ sich nicht beginnen.");
          }
          return body;
        });
      })
      .then(function (body) {
        var opt = body.publicKey;
        opt.challenge = b64urlToBuf(opt.challenge);
        // Kein allowCredentials: Die Auswahl trifft der Browser aus dem, was er
        // für diese Domain kennt.
        if (opt.allowCredentials) {
          opt.allowCredentials = opt.allowCredentials.map(function (c) {
            return { id: b64urlToBuf(c.id), type: c.type, transports: c.transports };
          });
        }
        return navigator.credentials.get({ publicKey: opt }).then(function (cred) {
          var payload = {
            id: cred.id,
            rawId: bufToB64url(cred.rawId),
            type: cred.type,
            clientExtensionResults: cred.getClientExtensionResults ? cred.getClientExtensionResults() : {},
            response: {
              authenticatorData: bufToB64url(cred.response.authenticatorData),
              clientDataJSON: bufToB64url(cred.response.clientDataJSON),
              signature: bufToB64url(cred.response.signature),
              userHandle: cred.response.userHandle ? bufToB64url(cred.response.userHandle) : null,
            },
          };
          return post("/login/forgot/finish", {
            token: body.token,
            credential: JSON.stringify(payload),
          });
        });
      })
      .then(function (r) {
        return r.json().then(function (body) {
          if (!r.ok) {
            throw new Error(body.error || "Der Passkey wurde nicht angenommen.");
          }
          window.location = body.redirect || "/login";
        });
      })
      .catch(function (err) {
        if (err && err.name === "NotAllowedError") {
          say("Abgebrochen.", "error");
        } else {
          say((err && err.message) || "Es ist ein Fehler aufgetreten.", "error");
        }
      });
  });
})();
