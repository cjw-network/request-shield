# 0032 — Check levels: one readable word for how strict the shield is

| | |
|---|---|
| Status | **Draft** — largely superseded, see *Relation to 0004* |
| Proposed | 2026-09-28 (as a local draft numbered 0002; renumbered 2026-10-03, 0002 is the single-file build) |
| Affects | the configuration, the settings compiler, the shield |
| Relates to | [0004 modes: monitor and strict](0004-modes-monitor-and-strict.md) (implemented) · [0031](0031-robust-core-plugins.md) |

## Relation to 0004 (2026-10-03)

Most of this draft has since been built by [0004](0004-modes-monitor-and-strict.md):
`set mode off|monitor|enforce|strict` is the one readable word, `monitor`
decides without blocking and logs what *would* have happened, `strict`
tightens budgets and the check, and the mode is resolved at compile time. The
`monitor` prefix on single rules covers "prove a rule before making it real".
What this draft still adds, and 0004 does not have:

- the **`basic`** stance (methods, size limits, path sanity only) as a first
  step for sites with many special cases;
- a **level per path** (`levels`, first match wins, one combined expression),
  which 0008 match blocks now express differently (`match **/api/** { … }`).

Whether either is worth a word of its own is open; the draft stays as the
record. The sketch below predates rule files and speaks of PHP-array keys.

## Summary

A single, readable setting decides how much the shield checks, instead of
turning every check on and off by hand:

```php
'level' => 'standard',   // off | monitor | basic | standard | strict
```

The level is only a **preset**: it says which groups of checks are on by
default. An explicitly set key always wins, so a level plus a few own keys
reads as "this stance, and my exceptions". The level is resolved to fixed
booleans when the settings are checked and compiled (ADR 0005); the request
path never compares a string against it.

**Nothing changes without the new key.** A configuration with no `level`
behaves exactly as today.

## Motivation

- Turning the shield down in an incident, or bringing it up on a new site, is
  today several edits — methods, hosts, blocked paths, budgets — each of which
  can be got wrong under pressure. One word is one decision.
- A new site, or a new rule, wants a **monitor** stance first: run every check,
  block nothing, and see what *would* have happened before making it real.
- "How strict is this site?" should be answerable by reading one line.

## Design

### The levels

| Level | What runs | For |
|---|---|---|
| `off` | nothing; the shield is a no-op | an emergency switch-off without a deploy |
| `monitor` | **every** check runs, but nothing is blocked (except path sanity); each would-be block is recorded | rollout, testing new rules, deriving rules from real traffic |
| `basic` | methods, size limits, path sanity (NUL, `..`, broken escapes) | sites with many special cases; a first step |
| `standard` | `basic` + hosts + `blockedPaths` + the cacheable definition + the requests budget | normal operation |
| `strict` | `standard` + the browser challenge (`challengeAt`) + the `misses` budget enforced | under attack; login- and API-heavy sites |

### A preset, not a replacement for keys

The level sets, for each group of checks, whether it is on. An **explicitly
present** key turns its group on regardless of the level:

```php
'level' => 'basic',
'blockedPaths' => ['#^/admin-alt/#'],   // on, although basic would leave it off
```

This needs to know which keys the configuration actually set, which is lost
once merged over the defaults. So the resolution reads the **raw** array (the
keys present before `Config::merge()`), not the merged one: a key that appears,
even set to its default value, counts as "the operator asked for this group".

### Resolved at compile time, not on the request path

`Settings::from()` turns the level (and the present-keys set) into the same
typed, per-group booleans the shield already builds its rule list from. The
compiled settings file (ADR 0005) stores the resolved result, so a served
request imports booleans and builds exactly the rule list it would have built
by hand. There is **no** `level` string in the request path and no extra
branch: `standard` registers the same rules as today's equivalent config.

Sketch, in the shield's constructor, unchanged in shape:

```php
if ($s->checkMethods)  $this->rules[] = new MethodRule($s->methods);
if ($s->checkLimits)   $this->rules[] = new LimitsRule(...);
$this->rules[] = new PathSanityRule();          // always, see Security
if ($s->checkHosts && $s->hosts !== []) $this->rules[] = new HostRule($s->hosts);
if ($s->checkBlockedPaths) $this->rules[] = new BlockedPathRule($s->blockedPaths);
if ($s->checkCacheable)    $this->rules[] = new CacheableRule(...);
// budgets: requests at standard+, misses enforced at strict
```

### Optional: a level per path

First match wins, at most 16 entries; the list is compiled to **one** combined
regular expression, so it stays a single `preg_match` on the request path:

```php
'levels' => [
    '#^/api/#'    => 'strict',
    '#^/login#'   => 'strict',
    '#^/assets/#' => 'basic',
],
```

The combined expression is built once at compile time (named groups, one per
entry) and stored in the compiled settings; at request time one match yields
the group index, which selects the pre-resolved boolean set for that path. A
request that matches nothing uses the top-level `level`.

### monitor sets the decision, but does not block

Under `monitor` every rule runs. A rule that wants to reject does not end the
request; its status and reason are kept on the `Decision`, the application
runs, and the operator can read it:

```php
$d = CjwNetwork\RequestShield\Shield::current();
if ($d && $d->wouldReject()) {
    // $d->reason(): 'blocked path', 'host', 'method', ...
}
```

A `monitor` event is recorded (see proposal 0033) with what *would* have been
sent. `monitor` is how a rule is proven safe before it is made real (proposal
0033, §5.4).

## Security

- **Path sanity always blocks.** `monitor` does **not** switch off the NUL /
  traversal / broken-escape check: it protects the application from a malformed
  path, it does not merely save work. This is a deliberate exception to
  "monitor blocks nothing", stated here so it is not read as an oversight.
- **An unknown level is a configuration error** that names the key, like every
  other wrong setting (`Settings::wrong()`); it never falls back silently to a
  stricter or looser stance.
- `off` is a real no-op — the shield registers no rules and returns `allow` —
  so it cannot be mistaken for "on but permissive".
- The level changes *which* checks run, never the neutral, rule-hiding
  responses of the ones that do.

## Cost

Zero on the request path: the level is gone by the time a request is served,
replaced by the booleans the rule list is already built from. A passing
`standard` request builds the same rules as the equivalent explicit config
today. Per-path `levels` add one `preg_match` against a combined expression
only when the key is set.

## Definition of done

- Tests per level: which checks fire and which do not; an explicit key
  overriding the level; `monitor` blocking nothing but path sanity, and setting
  `wouldReject()`/reason; per-path `levels` first-match; an unknown level named
  as an error.
- `bench/overhead.php`: a passing request at `standard` at most **+0.5 µs**
  against today; `basic` measurably faster. Numbers in the pull request.
- PHPStan (level max) and Psalm taint clean; PHP 8.1 only; no dependency.
- Docs in the same change: `docs/features/levels.md`, the `levels` keys added
  (commented) to `config/request-shield.dist.php`, `CHANGELOG.md` (*Unreleased*).

## Open questions

1. Should `off` still strip untrusted `X-Forwarded-*` (a safety measure), or be
   a total no-op? Leaning total no-op, to match "emergency switch-off".
2. Does `strict` force `challengeAt` to a default when a budget leaves it
   `null`, or only enforce it where set? A forced default changes behaviour from
   a preset, which the "explicit keys win" rule argues against.
3. Is 16 the right cap for `levels`, and combined-regex compilation the right
   trade against readability of the error when one entry's expression is bad?
