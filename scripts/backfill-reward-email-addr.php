<?php

/**
 * CLI front-end to the Gift Card Library backfill.
 *
 *   php scripts/backfill-reward-email-addr.php <gift card project pid>            # dry run (default)
 *   php scripts/backfill-reward-email-addr.php <gift card project pid> --apply
 *   php scripts/backfill-reward-email-addr.php --overview                         # every project
 *
 * There is also a Control Center page that does the same thing for admins who cannot run a script
 * against production: Control Center -> "Gift Cards: Backfill Reward Email Address".
 *
 * Both front-ends call src/BackfillEmailAddr.php, so the script and the page cannot disagree about
 * what "affected" means or about what gets written. All of the conservatism -- fill blanks only,
 * never overwrite; match on reward_pid; only fill `url` where egift_number is itself a link --
 * lives in that class, not here. See its docblock.
 *
 * The address written is the one on the record TODAY. If a participant's email was corrected after
 * their reward went out, this records the corrected address rather than the historical one. The
 * alternative -- reading it out of the REDCap logs -- is not reliably available, and a blank field
 * is not more truthful than a current one. The dry run lists every value before anything is written.
 */

/**
 * CLI only, and that guard is load-bearing.
 *
 * A module directory sits under the webroot, so every .php file in it is reachable at
 * /modules[-local]/<module>/scripts/<file>.php unless something stops it -- the External Module
 * framework's no-auth-pages list governs the framework's own dispatcher, not Apache. This file was
 * verified to execute from a plain unauthenticated GET before this check existed.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$ARGS  = array_slice($argv, 1);
$APPLY = in_array('--apply', $ARGS, true);
$OVERVIEW = in_array('--overview', $ARGS, true);
$PID   = 0;
foreach ($ARGS as $a) {
    if (ctype_digit((string) $a)) {
        $PID = (int) $a;
        break;
    }
}

/**
 * Which value of the library's `reward_pid` column identifies this study's rows. Defaults to $PID,
 * which is right on the server where the rewards were issued.
 *
 * It is NOT right on a copy. Copying a project renumbers it, but `reward_pid` is ordinary field
 * data and keeps pointing at the original -- so on a restored copy every row looks like it belongs
 * to somebody else and the backfill silently finds nothing to do. Pass the original project id
 * here in that case. The filter itself has to stay: a gift card library is commonly shared between
 * studies, and writing another study's addresses into their rows would be worse than doing nothing.
 */
$REWARD_PID = null;
foreach ($ARGS as $a) {
    if (str_starts_with((string) $a, '--reward-pid=')) {
        $REWARD_PID = (int) substr((string) $a, strlen('--reward-pid='));
    }
}

if ($PID <= 0 && !$OVERVIEW) {
    fwrite(STDERR, "Usage: php backfill-reward-email-addr.php <gift card project pid> [--apply] [--reward-pid=N]\n");
    fwrite(STDERR, "       php backfill-reward-email-addr.php --overview\n");
    exit(1);
}

if ($PID > 0) {
    $_GET['pid'] = $PID;
}
define('NOAUTH', true);
require_once gcr_find_redcap_connect();
require_once dirname(__DIR__) . '/src/BackfillEmailAddr.php';

use Stanford\GiftcardReward\BackfillEmailAddr;

function gcr_find_redcap_connect(): string
{
    if (($env = getenv('REDCAP_ROOT')) && is_file("$env/redcap_connect.php")) {
        return "$env/redcap_connect.php";
    }
    if (is_file('/var/www/html/redcap_connect.php')) {
        return '/var/www/html/redcap_connect.php';
    }
    for ($d = __DIR__, $i = 0; $i < 8; $i++, $d = dirname($d)) {
        if (is_file("$d/redcap_connect.php")) {
            return "$d/redcap_connect.php";
        }
        if ($d === '/') {
            break;
        }
    }
    fwrite(STDERR, "Could not locate redcap_connect.php.\n");
    exit(1);
}

$module = \ExternalModules\ExternalModules::getModuleInstance('giftcard_reward');
if (!$module) {
    fwrite(STDERR, "Could not instantiate giftcard_reward.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$backfill = new BackfillEmailAddr($module);

// ------------------------------------------------------------------ overview

if ($OVERVIEW) {
    $ov = $backfill->overview();
    printf("\n%-10s %-10s %10s %14s %12s\n", 'project', 'library', 'issued', 'missing addr', 'missing url');
    printf("%s\n", str_repeat('-', 60));
    foreach ($ov['rows'] as $pid => $r) {
        if (isset($r['error'])) {
            printf("%-10s %-10s  %s\n", $pid, $r['library'] ?: '-', $r['error']);
            continue;
        }
        if (!$r['has_field']) {
            printf("%-10s %-10s  library has no reward_email_addr field\n", $pid, $r['library']);
            continue;
        }
        printf("%-10s %-10s %10d %14d %12d\n", $pid, $r['library'], $r['issued'], $r['missing_email'], $r['missing_url']);
    }
    echo "\n";
    exit(0);
}

// ------------------------------------------------------------------ one project

$plan = $backfill->scanProject($PID, $REWARD_PID);

echo "\nGift Card Library backfill — project $PID, library " . $plan['library'] . ", event " . $plan['event'] . "\n";
echo "matching library rows whose reward_pid = " . $plan['reward_pid'] . "\n";
echo $APPLY ? "MODE: APPLY (writing)\n\n" : "MODE: dry run (nothing will be written; pass --apply to write)\n\n";

if ($plan['error'] !== null) {
    fwrite(STDERR, $plan['error'] . "\n");
    exit(1);
}

echo "reward_email_addr to fill: " . count($plan['emails']) . "\n";
foreach ($plan['emails'] as $rec => $email) {
    echo "  $rec  <-  $email\n";
}
echo "\nurl to fill (egift_number is itself the link): " . count($plan['urls']) . "\n";
foreach ($plan['urls'] as $rec => $u) {
    echo "  $rec  <-  " . substr($u, 0, 72) . "\n";
}
if ($plan['skipped'] !== []) {
    echo "\nskipped: " . count($plan['skipped']) . "\n";
    foreach ($plan['skipped'] as $rec => $why) {
        echo "  $rec  --  $why\n";
    }
}

if (!$APPLY) {
    echo "\nDry run complete. Re-run with --apply to write these values.\n";
    exit(0);
}

$res = $backfill->apply($plan);
if ($res['errors'] !== []) {
    fwrite(STDERR, "\nsaveData reported errors: " . json_encode($res['errors']) . "\n");
    exit(1);
}
echo "\nWrote " . $res['written'] . " library record(s).\n";
