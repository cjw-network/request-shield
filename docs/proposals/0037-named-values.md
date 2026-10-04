# 0037 — Named values: an address range, a set of paths, written once

| | |
|---|---|
| Status | **Draft** |
| Proposed | 2026-10-04 |
| Affects | rule files (new words `addresses`, `paths`, `agents`, `hosts`), the rule compiler, the rules page, `trace`, `expect` |
| Relates to | [rule files](../features/RSF05-01-rule-files.md) · [access rules](../features/RSF02-03-access-rules.md) · [IP lists](../features/RSF01-02-ip-lists.md) · [examples next to the rules](../features/RSF05-04-rule-examples.md) |

## The problem

The office's range is written in `exempt`, in `restrict` for the admin area,
in `restrict` for the dashboard, in `expect … from`, perhaps in a site block
too. When the office moves to another provider, every line has to be found
and changed; one forgotten line locks the office out or lets someone else in.
The same holds for a set of paths (the admin areas) and for User-Agent
patterns (the uptime monitors).

## The idea

A value gets a name once, by its kind, and every rule uses the name:

```text
addresses office    192.0.2.0/24 2001:db8:1::/48     # the office
addresses internal  10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 fd00::/8
addresses balancer  10.0.0.5 10.0.0.6
addresses partners  from partners.txt                # one per line, like a list file
paths     admin     /admin/** /setup/** /wp-admin/**
paths     forms     /contact /login /api/**
agents    monitors  /UptimeRobot|Pingdom|Better Uptime/

trust     balancer
exempt    office
restrict  admin to office internal
restrict  **/rs/** to office
allow     POST forms
expect    GET /admin/ from office answered            # an example from the name's first address
```

- **Typed:** `addresses` takes addresses and ranges, `paths` path patterns,
  `agents` User-Agent patterns, `hosts` website names -- checked where they
  are defined, so a mistake names its line there.
- **Used bare, where a value of the kind is expected:** `office` in place of
  an address, `admin` in place of a path. A path starts with `/` or `*`, an
  address is digits or hex, so a name cannot be taken for one; a word that is
  neither a value nor a name is a mistake, as today. Names and values mix:
  `restrict admin /backup/** to office 203.0.113.7`.
- **Defined before use,** above the site blocks, valid in all of them; a
  name defined twice is a mistake (no silent override).
- **Shipped names:** `private` (10/8, 172.16/12, 192.168/16, 127/8, ::1,
  fc00::/7, fe80::/10) and `loopback` -- the ranges every site writes.
- **Seen everywhere:** the rules page and `trace` show the name with its
  values ("restricted to office: 192.0.2.0/24, 2001:db8:1::/48"); `show`
  prints the rules with the names resolved; `vocabulary` lists the words.
- **Cost:** none on a request. The names are resolved when the rules are
  compiled; the compiled settings hold the values, as today.

## Other values worth a name

- **Hosts:** `hosts shop shop.a.de a.de www.a.de`, then `site shop { … }`
  and `post-origin` with a name -- one list of a website's names.
- **Durations** (`ban-max`, `for 15m`): rarely repeated; not proposed.
- **Countries** need a GeoIP database: not part of the shield (feeds of
  cloud and provider ranges cover most of what a country rule is used for).

## Open questions for the owner

1. The words: `addresses` / `paths` / `agents` / `hosts`, or one `define
   <name> <values>` that guesses the kind? *Recommendation: typed words --
   a mistake is found where the name is defined, not where it is used.*
2. Names inside a site block, valid only there? *Recommendation: not at
   first; above the site blocks, for every website.*
3. Bare names, or with a sign (`@office`)? *Recommendation: bare -- `@` already
   means a shipped rule set (`include @attacks`), and a bare name reads like
   the sentence it is.*
