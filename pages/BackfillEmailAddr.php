<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

/**
 * Reached only through the External Module framework, which supplies $module.
 *
 * The file is also sitting under the webroot at
 * /modules[-local]/<module>/pages/BackfillEmailAddr.php. Hitting that directly used to produce an
 * xdebug stack trace for an undefined $module rather than a refusal -- harmless, but it leaks
 * paths and says more about the install than a stranger needs to know.
 */
if (!isset($module) || !is_object($module)) {
    http_response_code(404);
    exit;
}

require_once $module->getModulePath() . "src/BackfillEmailAddr.php";

/**
 * Control Center page: find and repair Gift Card Library rows that are missing
 * "Email Address where reward was sent" (and the url the participant was sent).
 *
 * Exists because the underlying defect affected every project whose library holds link-style gift
 * cards, not just the one that reported it, and because REDCap admins cannot always run a CLI
 * script against production. Same logic as scripts/backfill-reward-email-addr.php -- both call
 * BackfillEmailAddr -- so the page cannot drift from the script.
 */

// A Control Center link is hidden from non-admins by REDCap. Hiding a link is not access control.
if (!$module->isSuperUser()) {
    http_response_code(403);
    exit('This page is restricted to REDCap administrators.');
}

/**
 * Session key for this page's own CSRF nonces. A short list rather than a single value so that an
 * admin with the overview open in one tab and a scan in another does not invalidate either.
 */
const GCB_TOKEN_KEY = 'giftcard_reward_backfill_csrf';

$backfill = new BackfillEmailAddr($module);

// Every project with the module on, and the library each points at. This list is also the
// authorization boundary: a pid that is not in it is not a project this page will touch.
$projects = $backfill->projectsAndLibraries();

$pid        = isset($_GET['gc_pid']) ? (int) $_GET['gc_pid'] : 0;
$rewardPid  = isset($_GET['reward_pid']) ? (int) $_GET['reward_pid'] : 0;
$applied    = null;
$notice     = null;

if ($pid !== 0 && !array_key_exists($pid, $projects)) {
    $notice = "Project $pid does not have the Gift Card Reward module enabled.";
    $pid = 0;
}

// ---------------------------------------------------------------- apply

if ($pid !== 0 && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'apply') {

    /**
     * CSRF, checked with a token this page owns end to end.
     *
     * Neither surrounding layer can be relied on here, and both were measured rather than assumed
     * on REDCap 17.2.3:
     *   - REDCap core's System::checkCsrfToken() exempts anything under /ExternalModules/ from the
     *     check, and then ends with an UNCONDITIONAL
     *     `unset($_POST['redcap_csrf_token'], $_GET['redcap_csrf_token'])`. So module pages never
     *     see that field; validating it rejects every legitimate POST.
     *   - ExternalModules/index.php does call $framework->checkCSRFToken($page), and a POST with no
     *     token at all is refused — but a POST carrying a *forged*
     *     `redcap_external_module_csrf_token` was accepted, and wrote. Presence, not validity.
     *
     * So this page mints its own single-use-per-render nonce, keeps it in the session, and
     * compares it with hash_equals(). e2e/backfill-page.js forges it and asserts nothing is
     * written. If a future REDCap tightens the framework check, this simply becomes redundant.
     */
    $posted = (string) ($_POST['gcb_token'] ?? '');
    $known  = (array) ($_SESSION[GCB_TOKEN_KEY] ?? []);
    $valid  = false;
    foreach ($known as $t) {
        if (hash_equals((string) $t, $posted) && $posted !== '') {
            $valid = true;
            break;
        }
    }
    if (!$valid) {
        $module->emError("Backfill page: rejected a POST with a bad or missing CSRF token (user "
            . ($module->getUser() ? $module->getUser()->getUsername() : 'unknown') . ")");
        $notice = 'Your session token was not valid, so nothing was written. Re-scan and try again.';
        $pid = 0;
    } else {

    // Recomputed here, deliberately: the page writes what a fresh scan says, never a row list
    // posted back from the browser.
        $plan    = $backfill->scanProject($pid, $rewardPid ?: null);
        $applied = $backfill->apply($plan);
    }
}

$plan    = $pid === 0 ? null : $backfill->scanProject($pid, $rewardPid ?: null);
$library = $plan === null ? 0 : (int) $plan['library'];
$breakdown = [];
if ($library > 0) {
    try {
        $breakdown = $backfill->libraryCountsByRewardPid($library, $pid);
    } catch (\Exception $ex) {
        $breakdown = [];
    }
}

$overview = $pid === 0 ? $backfill->overview() : null;

// A fresh nonce for whatever form this render puts on the page, keeping the last few so parallel
// tabs keep working.
$gcbToken = bin2hex(random_bytes(32));
$tokens   = (array) ($_SESSION[GCB_TOKEN_KEY] ?? []);
$tokens[] = $gcbToken;
$_SESSION[GCB_TOKEN_KEY] = array_slice($tokens, -10);

$e = function ($v) use ($module) { return $module->escape($v); };
$selfUrl = $module->getUrl('pages/BackfillEmailAddr.php');
?>

<style>
    .gcb-wrap { max-width: 1100px; }
    .gcb-wrap h3 { margin-bottom: .25rem; }
    .gcb-lede { color: #6c757d; max-width: 70ch; }
    .gcb-num { text-align: right; font-variant-numeric: tabular-nums; }
    .gcb-ok { color: #157347; }
    .gcb-warn { color: #a15c00; font-weight: 600; }
    .gcb-card { border: 1px solid #dee2e6; border-radius: .4rem; padding: 1rem 1.25rem; margin-bottom: 1.25rem; background: #fff; }
    .gcb-scroll { max-height: 22rem; overflow-y: auto; }
    .gcb-wrap table { width: 100%; }
    /* Only the vendor urls need to break mid-token. A global `break-all` on <code> split inline
       identifiers too, rendering "reward_pid" as "reward_pi / d" across a line end. */
    .gcb-break code { word-break: break-all; }
    @media (max-width: 640px) {
        .gcb-wrap .table-responsive { overflow-x: auto; }
    }
</style>

<div class="gcb-wrap">

    <h3>Gift Card Library — backfill “Email Address where reward was sent”</h3>
    <p class="gcb-lede">
        Until v3.2.1 the module only recorded the recipient’s address when a participant opened the
        module’s own claim page. Libraries whose <code>egift_number</code> is already a vendor link
        never produce a claim page — the card travels in the email itself — so those rows were left
        with an empty <code>reward_email_addr</code> and an empty <code>url</code>. The fix records
        both at reservation time; this page repairs rewards issued before it.
    </p>
    <p class="gcb-lede">
        Blanks are filled, never overwritten. <code>url</code> is only filled where
        <code>egift_number</code> is itself a link, because that is the one case where what the
        participant received is known for certain.
    </p>

    <?php if ($notice !== null) : ?>
        <div class="alert alert-warning"><?php echo $e($notice); ?></div>
    <?php endif; ?>

    <?php if ($applied !== null) : ?>
        <?php if ($applied['errors'] !== []) : ?>
            <div class="alert alert-danger">
                <strong>Nothing was written.</strong> REDCap reported:
                <?php echo $e(implode('; ', $applied['errors'])); ?>
            </div>
        <?php else : ?>
            <div class="alert alert-success">
                Wrote <strong><?php echo (int) $applied['written']; ?></strong> library record(s).
                The scan below is the state <em>after</em> that write.
            </div>
        <?php endif; ?>
    <?php endif; ?>

<?php if ($overview !== null) : ?>

    <!-- ----------------------------------------------------------- overview -->
    <div class="gcb-card">
        <h5>Projects with this module enabled</h5>
        <?php if ($overview['rows'] === []) : ?>
            <p class="text-muted mb-0">No projects have the Gift Card Reward module enabled.</p>
        <?php else : ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle">
                <thead>
                    <tr>
                        <th>Project</th>
                        <th>Library</th>
                        <th class="gcb-num">Rewards issued</th>
                        <th class="gcb-num">Missing address</th>
                        <th class="gcb-num">Missing url</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($overview['rows'] as $rowPid => $row) : ?>
                    <tr>
                        <td><?php echo (int) $rowPid; ?></td>
                        <td><?php echo $row['library'] > 0 ? (int) $row['library'] : '<span class="text-muted">—</span>'; ?></td>
                        <?php if (isset($row['error'])) : ?>
                            <td colspan="3" class="text-muted"><?php echo $e($row['error']); ?></td>
                            <td></td>
                        <?php elseif (!$row['has_field']) : ?>
                            <td class="gcb-num"><?php echo (int) $row['issued']; ?></td>
                            <td colspan="2" class="text-muted">library has no <code>reward_email_addr</code> field</td>
                            <td></td>
                        <?php else : ?>
                            <td class="gcb-num"><?php echo (int) $row['issued']; ?></td>
                            <td class="gcb-num <?php echo $row['missing_email'] > 0 ? 'gcb-warn' : 'gcb-ok'; ?>">
                                <?php echo (int) $row['missing_email']; ?>
                            </td>
                            <td class="gcb-num <?php echo $row['missing_url'] > 0 ? 'gcb-warn' : 'gcb-ok'; ?>">
                                <?php echo (int) $row['missing_url']; ?>
                            </td>
                            <td>
                                <a class="btn btn-sm btn-outline-primary"
                                   href="<?php echo $e($selfUrl . '&gc_pid=' . (int) $rowPid); ?>">Scan</a>
                            </td>
                        <?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <?php
    // Reward_pid values in a library that belong to no project on this server. On a restored copy
    // every issued row still points at the ORIGINAL project id, so the per-project counts above all
    // read zero and the repair looks unnecessary. Say so plainly rather than let an admin conclude
    // there is nothing to do.
    $orphans = [];
    foreach ($overview['libraries'] as $libPid => $counts) {
        foreach ($counts as $rp => $c) {
            if (!array_key_exists((int) $rp, $projects) && $c['missing_email'] > 0) {
                $orphans[] = ['library' => $libPid, 'reward_pid' => $rp, 'counts' => $c];
            }
        }
    }
    ?>
    <?php if ($orphans !== []) : ?>
    <div class="gcb-card">
        <h5 class="gcb-warn">Issued rows pointing at a project that is not on this server</h5>
        <p class="gcb-lede">
            These library rows record a <code>reward_pid</code> that no enabled project matches.
            The usual cause is a <strong>restored or copied project</strong>: copying renumbers the
            project, but <code>reward_pid</code> is ordinary field data and keeps pointing at the
            original. Scan the project that now owns the library and pick the original id there.
        </p>
        <div class="table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Library</th><th>reward_pid on the rows</th><th class="gcb-num">Issued</th><th class="gcb-num">Missing address</th></tr></thead>
                <tbody>
                <?php foreach ($orphans as $o) : ?>
                    <tr>
                        <td><?php echo (int) $o['library']; ?></td>
                        <td><?php echo $e($o['reward_pid']); ?></td>
                        <td class="gcb-num"><?php echo (int) $o['counts']['issued']; ?></td>
                        <td class="gcb-num gcb-warn"><?php echo (int) $o['counts']['missing_email']; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php else : ?>

    <!-- ----------------------------------------------------------- one project -->
    <p><a href="<?php echo $e($selfUrl); ?>">&larr; All projects</a></p>

    <div class="gcb-card">
        <h5>Project <?php echo (int) $pid; ?> &rarr; library <?php echo (int) $library; ?></h5>

        <?php if ($plan['error'] !== null) : ?>
            <div class="alert alert-warning mb-0"><?php echo $e($plan['error']); ?></div>
        <?php else : ?>

            <?php
            // Show the breakdown whenever the choice is not self-evident: more than one reward_pid in
            // the library, or a selection that is not this project's own id. Hiding it once a
            // non-default value is selected loses the only on-screen explanation of why rows are
            // being written under someone else's pid.
            // The default view is exactly where this matters most: if the library's issued rows all
            // say 33646 and you are looking at project 265, the counts read zero and the page looks
            // like it has nothing to do. So also show it when the SELECTED reward_pid has no rows.
            $showBreakdown = count($breakdown) > 1
                || (int) $plan['reward_pid'] !== $pid
                || !isset($breakdown[(string) $plan['reward_pid']]);
            ?>
            <?php if ($showBreakdown && $breakdown !== []) : ?>
                <p class="gcb-lede">
                    Rows are matched on the <code>reward_pid</code> stored on each library row. Only
                    rows carrying the selected value are touched.
                </p>
                <div class="table-responsive">
                    <table class="table table-sm w-auto">
                        <thead><tr><th>reward_pid</th><th class="gcb-num">Issued</th><th class="gcb-num">Missing address</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($breakdown as $rp => $c) : ?>
                            <tr<?php echo ((string) $rp === (string) $plan['reward_pid']) ? ' class="table-active"' : ''; ?>>
                                <td>
                                    <?php echo $e($rp); ?>
                                    <?php if ((int) $rp === $pid) : ?><span class="badge bg-secondary">this project</span><?php endif; ?>
                                    <?php if (!array_key_exists((int) $rp, $projects)) : ?><span class="badge bg-warning text-dark">not a project here</span><?php endif; ?>
                                </td>
                                <td class="gcb-num"><?php echo (int) $c['issued']; ?></td>
                                <td class="gcb-num"><?php echo (int) $c['missing_email']; ?></td>
                                <td>
                                    <?php if ((string) $rp === (string) $plan['reward_pid']) : ?>
                                        <span class="text-muted">selected</span>
                                    <?php else : ?>
                                        <a href="<?php echo $e($selfUrl . '&gc_pid=' . $pid . '&reward_pid=' . (int) $rp); ?>">Select</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <p>
                Matching rows whose <code>reward_pid</code> is
                <strong><?php echo (int) $plan['reward_pid']; ?></strong>:
                <strong><?php echo count($plan['emails']); ?></strong> address(es) and
                <strong><?php echo count($plan['urls']); ?></strong> url(s) would be filled,
                <strong><?php echo count($plan['skipped']); ?></strong> skipped.
            </p>

            <?php if ($plan['emails'] !== [] || $plan['urls'] !== []) : ?>
                <form method="post" action="<?php echo $e($selfUrl . '&gc_pid=' . $pid . '&reward_pid=' . (int) $plan['reward_pid']); ?>"
                      onsubmit="return confirm('Write <?php echo count($plan['emails']); ?> address(es) and <?php echo count($plan['urls']); ?> url(s) into library <?php echo (int) $library; ?>?');">
                    <input type="hidden" name="action" value="apply">
                    <!-- Two tokens. `redcap_external_module_csrf_token` is what the EM framework
                         looks for (its presence is required, though its validity was not enforced
                         on 17.2.3), and `gcb_token` is this page's own nonce, which IS checked.
                         REDCap's `redcap_csrf_token` is unusable here — core unsets it from $_POST
                         before module code runs. See the note at the top of this file. -->
                    <input type="hidden" name="redcap_external_module_csrf_token" value="<?php echo $e($module->getCSRFToken()); ?>">
                    <input type="hidden" name="gcb_token" value="<?php echo $e($gcbToken); ?>">

                    <button type="submit" class="btn btn-primary">Apply to library <?php echo (int) $library; ?></button>
                    <span class="text-muted ms-2">Re-scanned server-side before anything is written.</span>
                </form>
            <?php else : ?>
                <p class="gcb-ok mb-0">Nothing to fill for this <code>reward_pid</code>.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <?php if ($plan['error'] === null && $plan['emails'] !== []) : ?>
    <div class="gcb-card">
        <h5>Addresses to be written (<?php echo count($plan['emails']); ?>)</h5>
        <div class="gcb-scroll table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Library record</th><th>Address</th></tr></thead>
                <tbody>
                <?php foreach ($plan['emails'] as $rec => $addr) : ?>
                    <tr><td><?php echo $e($rec); ?></td><td><?php echo $e($addr); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($plan['error'] === null && $plan['urls'] !== []) : ?>
    <div class="gcb-card">
        <h5>Urls to be written (<?php echo count($plan['urls']); ?>)</h5>
        <div class="gcb-scroll table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Library record</th><th>Url (from <code>egift_number</code>)</th></tr></thead>
                <tbody>
                <?php foreach ($plan['urls'] as $rec => $u) : ?>
                    <tr><td><?php echo $e($rec); ?></td><td class="gcb-break"><code><?php echo $e($u); ?></code></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($plan['error'] === null && $plan['skipped'] !== []) : ?>
    <div class="gcb-card">
        <h5>Skipped (<?php echo count($plan['skipped']); ?>)</h5>
        <p class="gcb-lede">Each of these has a reason the address cannot be recovered. Nothing is guessed.</p>
        <div class="gcb-scroll table-responsive">
            <table class="table table-sm">
                <thead><tr><th>Library record</th><th>Reason</th></tr></thead>
                <tbody>
                <?php foreach ($plan['skipped'] as $rec => $why) : ?>
                    <tr><td><?php echo $e($rec); ?></td><td><?php echo $e($why); ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

<?php endif; ?>

</div>
