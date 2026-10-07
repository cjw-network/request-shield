# 0042 — A harder task for forms: ALTCHA's v2 work (PBKDF2, Argon2id)

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-07 |
| Affects | the check inside the form (the endpoint's task, `widget.js`, `requirePass()`), `ProofOfWork` (a second kind of task beside it), the settings (`widget-work`) |
| Relates to | [RSF03-02 the browser check](../features/RSF03-02-browser-challenge.md) · [RSF03-03 the check inside the form](../features/RSF03-03-browser-check-in-the-form.md) · [ADR 0004 stateless, signed challenges](../adr/0004-stateless-signed-challenge-and-pass.md) · [0041 a risk score](0041-risk-score.md) |

## What was checked first

**Does ALTCHA's own widget still work with the shield?** ALTCHA's widget
v3 (a rewrite, 2026) solves a new kind of task (below). The shield speaks
the old one (v1: find `n` with `sha256(salt . n) = challenge`). Tested
with widget **3.3.0**: it still takes a v1 task -- it turns it into its own
form (`createChallengeFromV1`), solves it with its SHA worker and answers
in v1 (`createPayloadV1`), and the shield accepts that answer, bound to its
client, budget and expiry as before (`tests/AltchaWidgetTest.php`, run with
`ALTCHA_WIDGET=<unpacked npm package>`; it fails against a copy of the
widget that answers wrongly or has no v1 path). **Nothing has to change for
compatibility.** One difference: v3 ignores `maxnumber` and tries until its
timeout, where the shield's own script stops at it -- harmless for a task
the shield made.

**Can the shield use ALTCHA's PHP library** (`altcha-org/altcha`, MIT)?
The licence allows it. The library does not fit:

- it needs **PHP 8.1** (`readonly`, enums, `array_is_list()`); the shield
  runs on 8.0 (RHEL 9) and would not even load;
- it is a **Composer package**; the shield runs before the application's
  autoloader (`auto_prepend_file`, the single file) and has no runtime
  dependencies -- and an application with its own copy in another version
  would clash;
- it would not make the work cheaper: the cost below is the algorithm's,
  not the library's.

What the library does have, the shield can take as code under its licence
(the copyright line kept): the format, and its tests as the reference.

## Why a v2 task at all

v1 costs the browser `maxnumber/2` SHA-256 hashes, the server one. That
asymmetry is its strength -- and its weakness: SHA-256 is what graphics
cards and mining chips are built for. A bot with a GPU solves a task of
500 000 hashes in well under a millisecond; for it, the check is free.

v2 replaces the hash with a **key derivation**: PBKDF2 (many rounds) or
**Argon2id** (many rounds *and* a lot of memory). Memory is what a GPU
lacks per core and a bot farm pays for per process: an Argon2id task with
32 MB costs a phone roughly what it costs a server, and a farm of a
thousand parallel solvers needs 32 GB.

```text
  v1 (today)                       v2 (this proposal, forms only)
  browser: 250 000 × SHA-256       browser: ~16 × Argon2id (32 MB each)
  server:  1 × SHA-256  ≈ 3 µs     server:  1 × Argon2id  ≈ 32 ms, 32 MB
  GPU bot: ≈ free                  GPU bot: as slow as a browser
```

## The price: the server does real work

Measured on the development machine (PHP 8.3, one core):

| Work | One check on the server |
|---|---|
| v1, SHA-256 + HMAC (today) | **0.003 ms** |
| PBKDF2-SHA-256, 1 000 rounds | 2.1 ms |
| PBKDF2-SHA-256, 5 000 rounds (ALTCHA's example) | 8.7 ms |
| Argon2id, 8 MB | 6.8 ms |
| Argon2id, 32 MB (ALTCHA's default) | 32 ms, and 32 MB of memory |

The asymmetry shrinks from ~250 000 : 1 to ~16 : 1. That is the point of
v2 for the solver -- and a risk for the server: **every answer someone
sends makes the server derive a key.** Hence the rules:

1. **Forms only, never the check page.** The check page answers every bot
   request in front of the site; a few milliseconds each would be the
   cheapest way to load the server. A form is sent by people, a few times
   a visit, and is behind its own budget.
2. **The signature first, then "already used?", then the derivation.** A
   task the server did not sign costs one HMAC, as today. A signed task is
   marked used *before* its key is derived, right or wrong: one task, one
   derivation at most. Tasks come from the endpoint, which counts against
   the visitor's budgets.
3. **The derivation when the answer arrives, not when the task is made.**
   ALTCHA's library can derive the key in advance (a "key signature", then
   checking is one HMAC); that moves the cost to *fetching a task*, which a
   bot does for free. Not used.
4. **Off by default.** `set widget-work sha` (v1, today) stays the default;
   `pbkdf2` and `argon2id` are chosen per site -- or per form, with
   [0036](0036-forms-per-page.md).
5. **Without `ext-sodium`** (Argon2id comes from it): the setting is told so
   when the settings are compiled, and the form falls back to PBKDF2.

## How it would work

```text
  form page ──GET /request-shield/challenge──▶ shield: a v2 task
     { parameters: { algorithm: "ARGON2ID", nonce, salt, cost: 1,
                     memoryCost: 32768, keyLength: 32, keyPrefix: "0",
                     expiresAt, data: { c: <client tag>, b: <budget> } },
       signature: HMAC(canonical JSON of parameters) }
  browser: tries counter 0, 1, 2 … until the derived key starts with keyPrefix
  form sent ──rss=<answer>──▶ shield: signature ✓ · not used ✓ (mark) ·
                                       client/budget/expiry ✓ · derive once ✓
```

- **Format:** ALTCHA's v2 exactly, so its widget can solve it; the client
  and budget go in `data` (signed with the rest), as they go in the salt
  today.
- **Code:** a second class beside `ProofOfWork` (`KeyWork`), PHP 8.0, about
  150 lines: the canonical JSON (without `array_is_list()`), PBKDF2 through
  `hash_pbkdf2()`, Argon2id through `sodium_crypto_pwhash()`. Scrypt is
  left out (it needs a PECL extension). The file carries ALTCHA's copyright
  line for what is taken from the library; `LICENSE`/`NOTICE` names it.
- **The shield's own `widget.js`:** PBKDF2 is in every browser's WebCrypto
  (a few lines). Argon2id is not -- it needs a WebAssembly worker (ALTCHA's
  is about 47 kB). *Open question 2.*
- **The check page** keeps v1, unchanged.

## Plan

1. Keep `tests/AltchaWidgetTest.php` (done with this draft) and run it in
   CI against a pinned widget version. *Open question 3.*
2. `KeyWork`: create and verify, PBKDF2 and Argon2id, with ALTCHA's test
   vectors; unit tests for each refusal (signature, used, expired, other
   client, wrong key, no sodium); the benchmark shows the passing path
   unchanged.
3. The endpoint and `requirePass()` with `set widget-work`; an end-to-end
   test with a form.
4. `widget.js` for PBKDF2; Argon2id per open question 2.
5. Docs: RSF03-03, an ADR (why forms only, why no key signature).

## Open questions for the owner

1. **Worth it at all?** v1 already turns away the mass of generic bots; v2
   matters against someone who brings a GPU for *your* forms.
   *Recommendation: yes, but after 0038's honeypot and time, which cost
   nothing -- v2 is for the forms where those are not enough.*
2. **Argon2id in our own `widget.js`,** with a WebAssembly worker of our
   own (~47 kB, a second file for the CSP) -- or Argon2id only through
   ALTCHA's widget, ours does PBKDF2? *Recommendation: PBKDF2 in ours,
   Argon2id with ALTCHA's widget, documented.*
3. **The widget test in CI:** fetch a pinned `altcha` package from npm in
   the CI job (network at test time, but only there) -- or keep the test
   manual? *Recommendation: CI, pinned, so a new widget version that drops
   v1 is seen before a site updates.*
