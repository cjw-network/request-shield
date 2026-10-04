# 0036 — Forms per page: viewed, sent, saved

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | the statistics plugin (form counters, the card "Forms", the visitors page, `stats --json`), the docs |
| Relates to | [0028 forms](0028-forms.md) (phase 2: the form counters) · [statistics](../features/RSF06-03-statistics.md#forms-sent-from-where-how-they-ended) · [0035 the check for forms that send JSON](0035-checked-json-forms.md) · [use case: rate limits on the forms](../use-cases/form-rate-limits.md) |

## The problem

A careers site has many job offers and one application form, built by a
script and sent as JSON to one endpoint. The question is per offer: how often
was it read, how often was an application sent from it, how often did that
succeed?

The statistics answer most of it today:

- **Read:** the page views by people, per page.
- **Sent:** a `POST` to the endpoint counts as a form (it is not on an
  `api-path`), with the page it was sent from (the `Referer`, without its
  query). A page with `Referrer-Policy: strict-origin-when-cross-origin`, the
  browsers' default, sends the full path to its own website.
- **How it ended:** saved, an error, or stopped by the shield.

Four things are missing for the question per offer:

1. **The outcome is counted per form, not per page it came from.** With one
   endpoint for all offers, "31 saved" is the sum over all of them.
2. **"Saved" means only an HTTP status below 400.** A JSON endpoint that
   answers a failed application with `200` and `{"status": "error"}` is
   counted as saved.
3. **Five pages per form and hour** (`Stats::REFERRERS`), and the card shows
   the first six. For a form on one page that is plenty; for one endpoint
   behind fifty offers, a busy hour puts the rest under "other".
4. **The check's first try counts as a send.** With the check when the form
   is sent ([0035](0035-checked-json-forms.md)), a sender without a pass sends
   twice: the first try gets the task and counts as sent and stopped, the
   second as sent and saved.

## The idea

1. **The outcome per page:** one counter more per form request,
   `fpo:<form>|<from>|<saved|error|stopped>`, next to `ff:` (sent from) and
   `fo:` (the outcome per form). Kept within the same limits as `ff:`.
2. **The site may say how it ended,** without the shield reading the body:
   a response header, read when the request has ended (the plugin's `ended()`
   gets the headers already):

   ```php
   header('Request-Shield-Form: saved');      // or: error
   ```

   Without the header, the status decides, as today. The header is a word,
   never a value of the form; the shield takes it out of the answer where it
   can (`header_remove()` before the output is sent), and nothing breaks
   where it cannot.
3. **More pages for a form that collects them:** the limit per form and hour
   follows the setting `stats-form-pages` (default 5, at most 100), or rises
   by itself for a form whose sends come from many pages. The card shows
   them all, sorted by sends, in a list that folds.
4. **The task is not a send:** a form request answered with the check's task
   (a JSON request without a pass on a `challenge` path) counts as "checked"
   only, not as sent. The try with the answer counts as the send.

## What the editors see

On the visitors page, the card "Pages" gets, for a page that forms were sent
from, two columns more: sent and saved. For the careers site:

```text
Page                              views   sent   saved
/jobs/architect-…                   412     14      12
/jobs/developer-…                   388      9       9
/jobs/apprenticeship-…            1,204     31      27
```

`stats --path=/jobs/` shows the same for a section, `stats --json` carries
`sent` and `saved` per page. Opening the form inside the page is no request
and is not counted; a site that wants it has its own analytics for clicks.

## Privacy

Counts per page and hour, as today: no field, no value, no file name, no
address in the counters. The header from the site is one of two words.

## Cost

One counter more per form request (about 6 µs with APCu, a form request
renders a page of the site anyway); nothing for GET and HEAD. Reading the
header: one look into the headers the plugin gets already.

## Open questions for the owner

- A setting `stats-form-pages`, or a limit that rises by itself?
- The header's name: `Request-Shield-Form`, or something shorter?
- Show sent and saved in the card "Pages", in the card "Forms", or both?
