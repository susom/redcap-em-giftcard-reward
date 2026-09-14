# Backfilling “Email Address where reward was sent”

Rewards issued before v3.2.1 left two Gift Card Library columns empty:
`reward_email_addr` and `url`. This repairs them. It is not specific to one study — every project
whose library holds link-style gift cards has the same blank columns.

Why it is possible at all: the library row still records which project record the card was reserved
for (`reward_record`), and the module configuration still records which field on that record holds
the address (`reward-email`). Those are the same two things `reserveReward()` uses, so the value
can be recovered rather than guessed.

There are two front-ends and **one** implementation (`src/BackfillEmailAddr.php`), so the page and
the script cannot drift apart.

## Control Center page

**Control Center → External Modules → “Gift Cards: Backfill Reward Email Address”.**
Administrators only — the page checks `isSuperUser()` itself rather than relying on the link being
hidden.

1. **Overview.** Every project with the module enabled, the library it points at, and how many
   issued rewards are missing an address or a url. One query per *distinct library*, grouped by
   `reward_pid` — several studies commonly share one library, and this way the shared case is
   ordinary rather than special.
2. **Scan.** Per project: what would be written, row by row, with a reason for every row that is
   skipped. Nothing is written yet.
3. **Apply.** Writes it. The plan is **recomputed server-side** on submit — the page never writes a
   row list posted back from the browser.

### Reading the numbers

| column | means |
|---|---|
| Rewards issued | library rows for this project at status Reserved or Claimed |
| Missing address | of those, how many have an empty `reward_email_addr` |
| Missing url | of those, how many have an empty `url` **and** an `egift_number` that is a link |

“Missing address” is not the same as “fixable”. A row is only fixable if the project record it
points at still has a value in the configured email field. The scan shows the split.

### “Issued rows pointing at a project that is not on this server”

If this panel appears, read it before anything else. Library rows match on the `reward_pid` stored
on each row, and **copying a project renumbers it while leaving `reward_pid` pointing at the
original**. On a restored copy every per-project count reads zero and the page looks like it has
nothing to do. The panel names the `reward_pid` actually on the rows; the scan view offers it as a
selectable value, badged *not a project here*.

## Command line

```bash
php scripts/backfill-reward-email-addr.php --overview          # same table as the page
php scripts/backfill-reward-email-addr.php <pid>               # dry run, lists every value
php scripts/backfill-reward-email-addr.php <pid> --apply
php scripts/backfill-reward-email-addr.php <pid> --reward-pid=33646 --apply   # a restored copy
```

Dry run is the default; `--apply` is the only thing that writes.

The script refuses to run unless it is invoked from the command line. A module directory sits under
the webroot, so without that check `/modules-local/<module>/scripts/backfill-reward-email-addr.php`
is simply executable by anyone who can reach the server — the framework's `no-auth-pages` list
governs the framework's own dispatcher, not Apache.

## What it will and will not do

- **Fills blanks only, never overwrites.** A claimed reward may already carry the address the
  participant typed on the claim page, which is more specific than the one on the record.
- **Only rows whose `reward_pid` matches the selection.** A library shared between studies must not
  have one study's addresses written into another's rows. This filter is why the `reward_pid`
  selector exists rather than being removed.
- **Fills `url` only where `egift_number` is itself a link.** That is the one case where what the
  participant received is known for certain. In the claim-link flow the URL carried a one-time hash
  that was never stored; inventing one would be a lie, so those rows are left alone.
- **Writes the address as it is on the record today.** If a participant's email was corrected after
  their reward went out, the corrected address is what gets recorded. A blank field is not more
  truthful than a current one, and every value is shown before anything is written.
- **Logs counts, never addresses.** The write is logged to the library project as "N email
  address(es) and M url(s) filled for project P".

## Skip reasons

| reason | what it means |
|---|---|
| `no reward_record to look the address up on` | the library row was never linked back to a project record |
| `no current configuration titled "X"` | the reward configuration that issued it has been renamed or deleted |
| `cannot locate the event holding <field>` | the configured email field is not on any event in the project |
| `record N has no value in <field>` | the participant record has no address stored — nothing to recover |

The last one is the common case, and it is also worth reading as a **configuration check**: if a
whole configuration's rows skip with "no value in `<field>`", that configuration's *Reward Email
Address Field* may be pointing at the wrong field. On the SPARCLE copy two rows skip on
`ies_purpose_v2`, which is a descriptive field with no data at all.

## A note on CSRF, for whoever maintains this next

The Apply button writes participant data, so it needs real CSRF protection, and on REDCap 17.2.3
**neither surrounding layer provides it**. Both of these were measured, not read:

- REDCap core's `System::checkCsrfToken()` exempts anything under `/ExternalModules/` from the
  check (`$isExtModPage`), and then ends with an **unconditional**
  `unset($_POST['redcap_csrf_token'], $_GET['redcap_csrf_token'])`. Module pages therefore never
  see that field at all — a page-level check of it rejects every legitimate POST. (This page tried
  exactly that and refused its own Apply until the cause was found.)
- `ExternalModules/index.php` does call `$framework->checkCSRFToken($page)`, and a POST with **no**
  token is refused with `em_errors_184`. But a POST carrying a **forged**
  `redcap_external_module_csrf_token` was accepted and wrote 92 rows. Presence, not validity.

So the page mints its own nonce (`gcb_token`), keeps the last few in `$_SESSION`, and compares with
`hash_equals()`. `e2e/backfill-page.js` case **B11c** forges it and asserts nothing is written — if
that test ever starts failing, the write is unprotected. The framework token is still emitted, so
if a future REDCap tightens its check the page simply gets a second layer.

The same gap applies to the module's existing **Batch Processing** page (`config/batch.php`), which
posts `redcap_csrf_token` — a field nothing can check. It was verified to still work (the framework
accepts the request), so it is not broken; it is unprotected. Worth the same treatment, but it is
a separate change from this one.

## Testing

`e2e/backfill-page.js` drives the page as a throwaway super user: overview, the orphaned-`reward_pid`
panel, a scan, a real Apply, the re-scan afterwards, mobile layout, and that an ordinary user is
refused outright. `scripts/e2e-backfill-fixture.php` snapshots the library columns first and
restores them verbatim on teardown — the test presses Apply on real rows. See
[e2e-testing.md](e2e-testing.md).
