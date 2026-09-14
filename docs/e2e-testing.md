# End-to-end tests

Two Playwright specs that drive the real REDCap UI, plus a PHP fixture that seeds the data and
asserts what landed in the database. They cover both shapes a gift card library can take, which is
the distinction the September 2026 bug turned on:

| spec | library card | what the participant gets | covers |
|---|---|---|---|
| `e2e/reserve-reward.js` | `egift_number` **is a URL** | the vendor link, in the verification email | reservation, the SPARCLE/prod shape |
| `e2e/claim-page.js` | `egift_number` is a plain code | a module claim link → `DisplayReward.php` | reservation **and** the claim page, desktop + iPhone 13 |
| `e2e/backfill-page.js` | — | — | the Control Center backfill page: overview, scan, a forged-nonce refusal, a real Apply, mobile, and the non-admin refusal |

Neither mocks the save hook. A coordinator ticking a payment checkbox and pressing Save is the only
thing that fires `redcap_save_record`, so that is what the specs do.

## Prerequisites

**A mail sink the web container can reach.** `reserveReward()` only marks a card Reserved when
`REDCap::email()` returns true, so with no MTA the whole flow no-ops and the run looks like a
different bug. The compose file ships MailHog commented out; run it alongside:

```bash
docker run -d --name gc_e2e_mailhog \
  --network redcap_2023_1_redcap_network --network-alias mailhog \
  -p 127.0.0.1:8025:8025 rdc-mailhog:latest
```

```bash
docker rm -f gc_e2e_mailhog        # when you are done
```

**Playwright**, resolvable by node. There is no `package.json` in this module; point `NODE_PATH` at
an installation that already has it, or `npm i playwright && npx playwright install chromium` here.

## Running

```bash
WEB=redcap_2023_1_web
FIX=/var/www/html/modules-local/giftcard_reward_v9.9.9/scripts/e2e-giftcard-fixture.php
export NODE_PATH=…/proj_mica_v9.9.9/node_modules      # wherever playwright lives

# --- link-style card (the prod shape)
SETUP=$(docker exec $WEB php $FIX 265 setup)
GCR_EMAIL=$(echo "$SETUP" | grep '^EMAIL=' | cut -d= -f2-) node e2e/reserve-reward.js
docker exec $WEB php $FIX 265 verify
docker exec $WEB php $FIX 265 teardown

# --- plain code → claim page
SETUP=$(docker exec $WEB php $FIX 265 setup plain)
GCR_EMAIL=$(echo "$SETUP" | grep '^EMAIL=' | cut -d= -f2-) node e2e/claim-page.js
docker exec $WEB php $FIX 265 verify plain claimed
docker exec $WEB php $FIX 265 teardown
```

Screenshots land in `e2e/shots/`.

## Running the Control Center page spec

```bash
docker exec $WEB php /var/www/html/modules-local/giftcard_reward_v9.9.9/scripts/e2e-backfill-fixture.php setup
node e2e/backfill-page.js
docker exec $WEB php /var/www/html/modules-local/giftcard_reward_v9.9.9/scripts/e2e-backfill-fixture.php teardown
```

**Always tear down.** Unlike the reservation specs, which create their own record and delete it,
this one presses Apply on the *real* library and rewrites dozens of existing rows. Setup snapshots
`reward_email_addr` and `url` for the whole library into a module system setting first, and
teardown restores every row verbatim. Skipping teardown leaves backfilled data behind.

It creates three accounts, not one:

- a throwaway **super user** — the page is admin-only;
- an **ordinary user**, so the spec can prove a non-admin is *refused* rather than merely not shown
  the link. In practice REDCap's own Control Center gate refuses first, with "You do not have
  permission to access this page." at HTTP 200; the page's `isSuperUser()` check is the backstop;
- a **second super user**, used only by the forged-nonce case (B11c). One account is not enough:
  signing the same user in again invalidates the earlier session, and a failed
  `forceCsrfTokenCheck()` poisons the session it happened in — either way the real Apply then fails
  for a reason unrelated to the code under test.

## The fixture

> Every script in `scripts/` refuses to run unless it is invoked from the command line, and the
> fixtures additionally refuse on anything but a development server. A module directory sits under
> the webroot, so without that a plain unauthenticated `GET` of
> `/modules-local/<module>/scripts/e2e-giftcard-fixture.php` executes it — the framework's
> `no-auth-pages` list governs the framework's dispatcher, not Apache.


`scripts/e2e-giftcard-fixture.php <pid> <mode> [flavor] [stage]`

| mode | does |
|---|---|
| `setup [url\|plain]` | throwaway user + a fresh participant record; `plain` also seeds a plain-code library card. Prints `USER=`, `PASS=`, `EMAIL=`, … |
| `report` | dumps the project record and the library row it points at |
| `verify [url\|plain] [reserved\|claimed]` | the assertions, exit 1 on failure |
| `teardown` | removes the record, the user, the seeded card, and releases the consumed card back to status 1 |

Three details that will otherwise cost you an afternoon:

* **A fresh email address every run.** `allow-multiple-rewards` is false on all 13 SPARCLE configs,
  so a reused address makes `checkForPreviousReward()` write `Duplicate email` and skip the
  reservation entirely. The fixture generates a timestamped address for exactly this reason.
* **The seeded plain-code card is `00_e2e_plain`.** `findNextAvailableReward()` picks
  `min(array_keys(...))` and those keys compare as strings, so a card that does not sort below
  `100_001` / `20_001` / `25_001` will not be the one reserved — and the run quietly consumes one of
  the study's real cards instead.
* **REDCap's checkbox inputs are not named after the field.** The visible box is
  `#id-__chk__<field>_RC_<code>`; a `[name="remote_screen_payment___1"]` selector (the import/export
  spelling) matches nothing on the page.

A run leaves the copy exactly as it found it — verified against row counts before and after.

## Test users and data

The fixture creates `e2e_gcr_tester` with ordinary data-entry rights and **no** design or
user-rights privileges, deliberately: a coordinator ticking a payment checkbox is an ordinary user,
and running the repro as an admin is how an access-dependent bug stays hidden. Teardown deletes it.
