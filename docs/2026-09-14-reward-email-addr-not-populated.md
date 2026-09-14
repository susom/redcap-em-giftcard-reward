# "Email Address where reward was sent" is never filled in

**Reported:** 2 Sep 2026, Lucas Wozniak — SPARCLE (prod pid 33646) / SPARCLE Gift Card Library.
**Investigated on:** localhost copy, pid 265 (project) + pid 266 (library), REDCap 17.2.3.
**Status:** fixed and verified end-to-end.

> "gift card emails have not been being sent, despite having a linked email field that is populated
> for subjects who have had gift cards successfully reserved for them in our library, yet the
> recently reserved ones don't have their 'Email Address where reward was sent' field populated."
>
> …and the follow-up: *"it sounds like the gift card emails have been sending; I guess the module
> just doesn't auto-fill the 'Email Address where reward was sent' field?"*

The follow-up is right. The emails were always going out. Two library columns were not being
written, and one of them is the one the study team reads to confirm delivery.

## What the data said before anything was changed

Library pid 266, 480 cards:

| column | rows with a value |
|---|---|
| `reward_id`, `brand`, `egift_number`, `amount`, `status` | 480 |
| `reserved_ts` | 97 |
| `reward_record` | 96 |
| **`reward_email_addr`** | **5** (all legacy) |
| **`url`** | **0** |
| **`reward_hash`** | **0** |
| **`claimed_ts`** | **0** |

`status`: 383 at `1` (Ready), 97 at `2` (Reserved), **none at `3` (Claimed)**.

And all 480 cards have an `egift_number` that starts with `https://` — they are
`https://egift.activationspot.com/?tid=…` vendor links, not redemption codes.

That combination has exactly one explanation, below.

## Root cause

`RewardInstance::reserveReward()` has always had two branches:

```php
$gcr_record_code = $reward_record['egift_number'];
if (substr($gcr_record_code, 0, 4) == "http") {
    // the card IS a link — mail it straight to the participant
    $reward_url = $gcr_record_code;
} else {
    $hash = $this->createRewardHash();
    $url  = …DisplayReward.php… . "&reward_token=" . $hash;   // a claim page
}
…
$reward_record[…]['reward_hash'] = $hash;   // undefined in the http branch
$reward_record[…]['url']         = $url;    // undefined in the http branch
```

SPARCLE's cards are links, so the `http` branch runs every single time. Two consequences:

1. **`$hash` and `$url` are only ever assigned in the `else` branch.** In the `http` branch they
   are undefined, so they were saved as empty strings — which is why `url` and `reward_hash` are
   blank on all 97 issued cards.

2. **`reward_email_addr` was only ever written by `DisplayReward.php`** (the module's claim page),
   inside `sendRewardEmail()`, i.e. only once a participant opens the module's own claim link and
   presses "Send". In the `http` branch there *is* no claim link — the card travels in the
   verification email itself. Nobody ever visits the page, so nothing ever writes the field, and
   `status` never advances past `2` either. The field is not "sometimes missed"; on a link-style
   library it could never have been populated.

The 5 rows that do have an address, and the `reward_name` 97 / `reward_pid` 92 gap, are residue
from an earlier configuration. They are not evidence the current path ever worked.

### A second, latent defect in the same area

`DisplayReward.php` re-read the event id immediately before saving the address:

```php
$gclEventId = $module->getProjectSetting('gcr-event-id', $pid);   // blank for a classic library
$data[$gclRecordId][$gclEventId]['reward_email_addr'] = $emailAddress;
```

`gcr-event-id` is documented as "leave blank for classical", and SPARCLE's library is classic, so
this threw away the event id `findGiftCardLibraryRecord()` had already resolved and wrote into
`$data[$record]['']`. `Records::saveData()` only looks at the event key `if ($longitudinal)`, so on
a classic library this is harmless — and on a **longitudinal** library it is a silent no-save. It
also never checked the save result. Both fixed.

## The fix

`src/RewardInstance.php` — `reserveReward()`:

* `$hash` and `$reward_url` are initialised before the branch, so neither depends on which one runs.
  `$url` is gone; `$reward_url` ("what the participant was sent") is the single variable, and it is
  what gets written to the library's `url` column in both flows.
* `$emailed_to` records the address the verification email actually went to, and is only set when
  `sendEmailWithLinkToReward()` returned true.
* The library row now carries `reward_email_addr` at **reservation** time, guarded on the field
  existing in the library's data dictionary (it is a later addition to the library template and
  older libraries legitimately lack it — that guard is why nothing breaks for them).

`src/DisplayReward.php` — `sendRewardEmail()`:

* Stops clobbering the resolved `$gclEventId` with the blank project setting; falls back to the
  library's first event only if it is genuinely unset.
* Checks the `saveData()` result and logs a failure instead of swallowing it.

### Which value wins

Reservation writes the address on the project record. If the participant later opens the claim page
and asks for the codes at a *different* address, `DisplayReward.php` overwrites the field with the
address they typed. That is the more specific answer and should win — the field means "where the
reward was sent", and the claim page is where it was most recently sent.

## Verification

Both flows, end to end, through the browser as an ordinary coordinator — not via a unit test and
not as an admin. See [e2e-testing.md](e2e-testing.md) for how to run them.

**Before the fix**, against the state left by a real reservation:

```
FAIL  V6  library url holds the link the participant was sent — ""
FAIL  V7  library reward_email_addr holds the address it went to
          — expected "gcr-e2e-…@example.invalid", got ""
```

**After the fix:**

| flow | result |
|---|---|
| link-style card (SPARCLE's shape) — `e2e/reserve-reward.js` | 9/9 pass, `verify` 7/7 |
| plain redemption code → module claim page — `e2e/claim-page.js` | 13/13 pass, `verify … claimed` 8/8 |

The link-style run now produces a library row with `status=2`, `reserved_ts`, `url` = the vendor
link the participant received, and `reward_email_addr` = the address it was sent to.

## Two things for the study team, not code

1. **The down-taper configuration points at a non-field.** Reward config 3, "$25 down taper gift
   card", has its *Reward Email Address Field* set to `ies_purpose_v2` — a **descriptive** (display
   only) field with zero stored values, not `ies_email_v2`. With that setting `$this->email_address`
   is always empty, so `reserveReward()` writes `No email address entered` to the record and
   reserves nothing. Two down-taper rewards were issued historically (25_014, 25_044), so the
   setting was presumably correct once and changed later. Worth checking in prod before the next
   down-taper payment. `verifyConfig()` does not catch this — it only checks the setting is
   non-empty, not that it names a real text field.
2. **Every configuration has *Reward Email Subject* set to `N/A`.** That string is what the claim
   page prints in its header bar and what the reward email uses as its subject. Harmless for
   SPARCLE, which never reaches the claim page, but it will look odd if they ever switch to
   code-style cards.

Both observed on the localhost copy; confirm against prod before acting.

> Rewards already issued keep their blank columns; the fix only changes what happens from now on.
> Repairing them is the next commit.

## Files changed

| file | change |
|---|---|
| `src/RewardInstance.php` | the fix: `url`, `reward_hash`, and `reward_email_addr` written at reservation |
| `src/DisplayReward.php` | event-id clobber, unchecked save, and an unescaped echo of the participant's address |
| `scripts/e2e-giftcard-fixture.php` | new — E2E fixture and DB assertions |
| `e2e/reserve-reward.js` | new — link-style reservation, through the UI |
| `e2e/claim-page.js` | new — claim page, desktop and iPhone 13 |
