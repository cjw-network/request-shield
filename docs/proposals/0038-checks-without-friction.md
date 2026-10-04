# 0038 — Without JavaScript, and without friction: a fallback and invisible signals

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | the check page (a fallback without JavaScript), the check inside the form (`widget.js`: a honeypot, the time it took), `requirePass()`, texts, the log, the statistics' forms |
| Relates to | [the browser check](../features/RSF03-02-browser-challenge.md) · [the check inside the form](../features/RSF03-03-browser-check-in-the-form.md) · [the site asks for the check](../features/RSF03-04-app-challenges.md) · [0035 forms that send JSON](0035-checked-json-forms.md) · [0036 forms per page](0036-forms-per-page.md) |

## Why not a captcha

A captcha in the shield -- a sum to work out, distorted text, a grid of
pictures -- was weighed and is not proposed:

- **It no longer tells a person from a program.** Image and language models
  read distorted text and pick traffic lights as well as people do, and
  solving services sell a thousand answers for a few cents.
- **Google's captcha is not its puzzle.** Its value is the risk score from
  what Google sees across many websites; a local copy would have the puzzle
  without the score.
- **It costs every person,** every time: seconds of work, failed tries,
  people who give up a form, and people it shuts out -- text in a picture
  excludes blind visitors (WCAG asks for another way).

The browser check already turns the cheap mass away without anyone noticing.
What is missing are the visitors it leaves alone, and signals that cost
nobody anything.

## 1. A fallback for visitors without JavaScript

Today a visitor without JavaScript gets *"Please enable JavaScript to
continue."* -- and no way on. A small share of people (privacy add-ons,
text browsers, some screen reader setups, a strict corporate policy) are
locked out of every checked page and form.

The proposal: the check page's `<noscript>` part offers a way that needs
neither JavaScript nor a picture:

```text
  One moment, please.
  Your browser does not run JavaScript, so it cannot be checked automatically.
  [ Continue in 10 seconds ]          <- a form button, enabled by the server's clock, not a script
```

- **A signed form, a wait:** the page carries a signed token (the client's
  bucket, a time, the address asked for). Sent back no sooner than N seconds
  later (`set fallback-wait 10s`), it earns a short pass -- a few minutes, not
  an hour. Sent too early, the page comes again.
- **Why that is enough:** it makes a request cost the bot the wait, as the
  task costs it computing time. A script can wait too -- so the fallback is a
  door, not a lock: past a few uses per address an hour it closes (the check
  page says to enable JavaScript, as today), and bans count as before.
- **Accessible:** a button and a sentence, read out by a screen reader; no
  picture, no puzzle. The texts in the visitor's language (`fallback-…`).
- **A form sent again:** as today, the form's fields go back with the button;
  files cannot (the page says so).
- **Off by default?** *Recommendation: on, with a 10 s wait and 5 uses per
  address an hour -- the people it lets in are real people; the bots it lets
  in pay ten seconds each.*

## 2. Invisible signals in the form

Two signals a form gives for free, gathered by the box that does the check
inside the form (`widget.js`) and checked where the form arrives
(`requirePass()`, the check of a form the shield answers):

- **A honeypot:** a field no person sees (off screen, `aria-hidden`,
  `tabindex="-1"`, `autocomplete="off"`, a plausible name such as `website`).
  Bots that fill every field fill it; a filled one means a bot. Screen readers
  skip it.
- **The time it took:** from the first input to the send, signed by the box.
  A contact form filled in under two seconds (`set form-min 2s`) was filled
  in by a program. A form sent with no first input at all (pasted by a
  script) the same.

What happens with a hit -- **never a plain refusal of a person**:

| Signal | The form gets |
|---|---|
| honeypot filled | the browser check, harder (`difficulty-max`); counted as a `bots` signal for bans |
| sent too fast | the browser check; counted |
| both | refused (403) -- a person cannot fill a field they cannot see *and* type that fast |

- **Nothing for the visitor to do:** no field to fill, no picture, nothing
  that changes how the form looks.
- **Counted:** the statistics' forms ([0036](0036-forms-per-page.md)) show
  "stopped: honeypot / too fast" per form, the log names the signal.
- **For forms that send JSON** ([0035](0035-checked-json-forms.md)): the same
  signals go as a header (`Request-Shield-Form: …`) that `RS.fetch()` adds.
- **Limits:** a bot written for this one form leaves the field empty and
  waits. The signals stop the mass of generic form spam, not a targeted
  attack -- that is what limits and bans are for.

## Cost

Nothing on a request that is no form and no check. A form request with the
box: one more field and one signed time to check (a few µs). The fallback:
one more signed token on the check page.

## Open questions for the owner

1. The fallback on by default, or only with a setting? *Recommendation: on,
   10 s, 5 uses per address an hour.*
2. The honeypot's name chosen by the site (`set form-honeypot website`) or by
   the shield? *Recommendation: the shield picks a plausible one per site,
   the site may set its own.*
3. A hit on both signals: refuse, or only the harder check? *Recommendation:
   refuse -- both together are no person; one alone gets the check.*
