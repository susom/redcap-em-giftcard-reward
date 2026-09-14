<?php

/**
 * Set up (or tear down) everything the gift-card reservation E2E needs.
 *
 *   docker exec <web> php /var/www/html/modules-local/giftcard_reward_v9.9.9/scripts/e2e-giftcard-fixture.php 265 setup
 *   docker exec <web> php /var/www/html/modules-local/giftcard_reward_v9.9.9/scripts/e2e-giftcard-fixture.php 265 teardown
 *   docker exec <web> php .../e2e-giftcard-fixture.php 265 report    # print the library row + project row
 *
 * Creates a throwaway REDCap user with a known password and ordinary data-entry rights, and seeds
 * one fresh participant record carrying a never-before-used email address. The Playwright spec then
 * ticks the "Award $25 remote screen gift card" checkbox through the real data-entry form, which is
 * exactly what a study coordinator does and the only thing that fires redcap_save_record.
 *
 * A dedicated user, not a real one: the alternative is resetting somebody's password for the
 * duration of a test, which is invasive and easy to forget to undo.
 *
 * A never-before-used email is not cosmetic. `allow-multiple-rewards` is false on all 13 SPARCLE
 * configs, so reusing an address makes checkForPreviousReward() write 'Duplicate email' and skip
 * the reservation entirely -- the run then "fails" for a reason that has nothing to do with the bug.
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

$PID   = (int) ($argv[1] ?? 265);
$MODE  = (string) ($argv[2] ?? 'setup');
/**
 * 'url'   -- reserve one of the study's real cards, whose egift_number is already a vendor link.
 *            This is the SPARCLE/prod shape and the one the reported bug lives in.
 * 'plain' -- seed a card whose egift_number is a bare redemption code, so the module generates its
 *            own claim link and the participant lands on DisplayReward.php. The other half of the
 *            matrix, and the only way to exercise the claim-page write.
 */
$FLAVOR = (string) ($argv[3] ?? 'url');
/**
 * Which point in the lifecycle 'verify' is being asked about. 'reserved' is straight after the
 * coordinator's save; 'claimed' is after the participant opened the claim page and asked for the
 * codes, which legitimately advances status to 3 and rewrites reward_email_addr to whatever address
 * they typed there.
 */
$STAGE = (string) ($argv[4] ?? 'reserved');

$_GET['pid'] = $PID;
define('NOAUTH', true);
require_once gcr_find_redcap_connect();

/**
 * And refuse to run anywhere but a development server.
 *
 * This fixture creates REDCap SUPER USERS with passwords that are written in plain text a few
 * lines below, which is fine for a throwaway localhost instance and catastrophic anywhere else.
 * The CLI guard above stops the web vector; this one stops a mistyped ssh session.
 */
if (($GLOBALS['is_development_server'] ?? '0') != '1') {
    fwrite(STDERR, "Refusing to run: this is not a development server (redcap_config.is_development_server != 1).\n");
    exit(1);
}


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

const USER   = 'e2e_gcr_tester';
const PASS   = 'E2eGiftCard!2026';
const RECORD = '90001';
const EVENT  = 1058;              // "REDCap Eligibility Screen" -- holds master_list AND the email form
const EMAIL_FIELD = 'ies_email_v2';
const PAY_FIELD   = 'remote_screen_payment';
const GC_ID_FIELD = 'remote_screen_gift_card_id';
const GC_ST_FIELD = 'remote_screen_gift_card_status';

/**
 * Sorts below every real library record id ('20_001', '25_001', '100_001', ...) because
 * findNextAvailableReward() picks min(array_keys(...)) and those keys compare as strings.
 * A card that is not reliably chosen first makes the plain-code run reserve somebody else's card.
 */
const PLAIN_CARD = '00_e2e_plain';
const PLAIN_CODE = 'E2E-PLAIN-CODE-9137';

/** A fresh address every run, so checkForPreviousReward() can never short-circuit the reservation. */
function e2eEmail(): string
{
    return 'gcr-e2e-' . date('YmdHis') . '@example.invalid';
}

$module = \ExternalModules\ExternalModules::getModuleInstance('giftcard_reward');
if (!$module) {
    fwrite(STDERR, "Could not instantiate giftcard_reward.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$gclPid   = (int) $module->getProjectSetting('gcr-pid', $PID);
$dataTbl  = \Records::getDataTable($PID);
$gclTbl   = \Records::getDataTable($gclPid);

/** The library row this project record points at, whatever state it is in. */
function libraryRowFor($module, int $gclPid, string $gclTbl, string $rewardRecord): array
{
    $q = $module->query(
        "SELECT record, field_name, value FROM $gclTbl WHERE project_id = ? AND record = ? ORDER BY field_name",
        [$gclPid, $rewardRecord]
    );
    $row = [];
    while ($r = $q->fetch_assoc()) {
        $row[$r['field_name']] = $r['value'];
    }

    return $row;
}

function projectValue($module, int $pid, string $tbl, string $record, string $field): ?string
{
    $q = $module->query(
        "SELECT value FROM $tbl WHERE project_id = ? AND record = ? AND field_name = ?",
        [$pid, $record, $field]
    );
    $r = $q->fetch_assoc();

    return $r === null ? null : $r['value'];
}

// ------------------------------------------------------------------ report

if ($MODE === 'report') {
    $gcId = projectValue($module, $PID, $dataTbl, RECORD, GC_ID_FIELD);
    $gcSt = projectValue($module, $PID, $dataTbl, RECORD, GC_ST_FIELD);
    $mail = projectValue($module, $PID, $dataTbl, RECORD, EMAIL_FIELD);

    echo "project pid=$PID record=" . RECORD . "\n";
    echo "  " . EMAIL_FIELD . " = " . var_export($mail, true) . "\n";
    echo "  " . GC_ID_FIELD . " = " . var_export($gcId, true) . "\n";
    echo "  " . GC_ST_FIELD . " = " . var_export($gcSt, true) . "\n";

    if ($gcId === null || $gcId === '') {
        echo "\nno library record reserved yet\n";
        exit(0);
    }

    echo "\nlibrary pid=$gclPid record=$gcId\n";
    foreach (libraryRowFor($module, $gclPid, $gclTbl, $gcId) as $f => $v) {
        echo "  $f = " . var_export($v, true) . "\n";
    }
    echo "\nJSON " . json_encode([
        'reward_record'     => $gcId,
        'project_status'    => $gcSt,
        'library'           => libraryRowFor($module, $gclPid, $gclTbl, $gcId),
    ]) . "\n";
    exit(0);
}

// ------------------------------------------------------------------ verify

/**
 * The assertions the bug is about. Everything here is what the study team actually looks at in the
 * Gift Card Library, and every one of them failed before the fix.
 */
if ($MODE === 'verify') {
    $gcId  = (string) projectValue($module, $PID, $dataTbl, RECORD, GC_ID_FIELD);
    $gcSt  = (string) projectValue($module, $PID, $dataTbl, RECORD, GC_ST_FIELD);
    $email = (string) projectValue($module, $PID, $dataTbl, RECORD, EMAIL_FIELD);
    $lib   = $gcId === '' ? [] : libraryRowFor($module, $gclPid, $gclTbl, $gcId);

    $pass = 0;
    $fail = 0;
    $check = function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail) {
        $ok ? $pass++ : $fail++;
        echo '  ' . ($ok ? 'PASS' : 'FAIL') . "  $name" . ($detail === '' ? '' : " — $detail") . "\n";
    };

    echo "\nGift Card Library assertions (pid $gclPid, record " . ($gcId === '' ? '?' : $gcId) . ")\n\n";

    $check('V1  a card was reserved into the project record', $gcId !== '', "id=\"$gcId\"");
    $wantProjSt = $STAGE === 'claimed' ? 'Claimed' : 'Reserved';
    $wantLibSt  = $STAGE === 'claimed' ? '3' : '2';
    $check("V2  the project status field reads $wantProjSt", $gcSt === $wantProjSt, "got \"$gcSt\"");
    $check("V3  library status is $wantLibSt", ($lib['status'] ?? '') === $wantLibSt, 'got "' . ($lib['status'] ?? '') . '"');
    $check('V4  library reserved_ts is set', ($lib['reserved_ts'] ?? '') !== '');
    if ($STAGE === 'claimed') {
        $check('V4b library claimed_ts is set', ($lib['claimed_ts'] ?? '') !== '');
    }
    $check('V5  library reward_record points back at the participant', ($lib['reward_record'] ?? '') === RECORD);

    // The two the bug was about.
    if ($FLAVOR === 'plain') {
        $check(
            'V6  library url is the module claim link, with a hash to match',
            str_contains((string) ($lib['url'] ?? ''), 'reward_token=') && ($lib['reward_hash'] ?? '') !== ''
            && str_ends_with((string) ($lib['url'] ?? ''), (string) ($lib['reward_hash'] ?? 'x')),
            '"' . substr((string) ($lib['url'] ?? ''), -40) . '" hash="' . ($lib['reward_hash'] ?? '') . '"'
        );
    } else {
        $check(
            'V6  library url holds the vendor link the participant was sent',
            ($lib['url'] ?? '') !== '' && $lib['url'] === ($lib['egift_number'] ?? null),
            '"' . substr((string) ($lib['url'] ?? ''), 0, 60) . '"'
        );
    }
    if ($STAGE === 'claimed') {
        // DisplayReward.php rewrites the field with whatever address the participant typed on the
        // claim page, which may not be the one on the project record. Only that it was recorded at
        // all is in scope here -- the exact value is the spec's assertion, not this one's.
        $check(
            'V7  library reward_email_addr was recorded by the claim page',
            ($lib['reward_email_addr'] ?? '') !== '',
            'got "' . ($lib['reward_email_addr'] ?? '') . '"'
        );
    } else {
        $check(
            'V7  library reward_email_addr holds the address it went to',
            ($lib['reward_email_addr'] ?? '') === $email && $email !== '',
            'expected "' . $email . '", got "' . ($lib['reward_email_addr'] ?? '') . '"'
        );
    }

    echo "\n  $pass passed, $fail failed\n\n";
    exit($fail > 0 ? 1 : 0);
}

// ------------------------------------------------------------------ teardown

if ($MODE === 'teardown') {
    echo "Tearing down the gift-card E2E fixture (pid $PID)\n";

    // Put the consumed card back in circulation BEFORE the project record goes away -- the project
    // record is the only pointer to which library row the test took.
    $gcId = projectValue($module, $PID, $dataTbl, RECORD, GC_ID_FIELD);
    if ($gcId !== null && $gcId !== '') {
        $module->query(
            "DELETE FROM $gclTbl WHERE project_id = ? AND record = ? AND field_name IN "
            . "('reward_pid','reward_name','reward_record','reward_hash','url','reserved_ts','claimed_ts','reward_email_addr')",
            [$gclPid, $gcId]
        );
        $module->query(
            "UPDATE $gclTbl SET value = '1' WHERE project_id = ? AND record = ? AND field_name = 'status'",
            [$gclPid, $gcId]
        );
        echo "  released library card $gcId back to status 1 (Ready)\n";
    }

    $module->query("DELETE FROM $dataTbl WHERE project_id = ? AND record = ?", [$PID, RECORD]);
    $module->query('DELETE FROM redcap_record_list WHERE project_id = ? AND record = ?', [$PID, RECORD]);
    echo "  removed project record " . RECORD . "\n";

    $module->query("DELETE FROM $gclTbl WHERE project_id = ? AND record = ?", [$gclPid, PLAIN_CARD]);
    $module->query('DELETE FROM redcap_record_list WHERE project_id = ? AND record = ?', [$gclPid, PLAIN_CARD]);
    echo "  removed seeded plain-code card " . PLAIN_CARD . "\n";

    $module->query('DELETE FROM redcap_user_rights WHERE project_id = ? AND username = ?', [$PID, USER]);
    $module->query('DELETE FROM redcap_auth WHERE username = ?', [USER]);
    $module->query('DELETE FROM redcap_user_information WHERE username = ?', [USER]);
    echo "  removed user " . USER . "\n";

    echo "Done.\n";
    exit(0);
}

// ------------------------------------------------------------------ setup

echo "Setting up the gift-card E2E fixture (pid $PID, library pid $gclPid)\n\n";

// ---- the throwaway user
$module->query('DELETE FROM redcap_user_rights WHERE project_id = ? AND username = ?', [$PID, USER]);
$module->query('DELETE FROM redcap_auth WHERE username = ?', [USER]);
$module->query('DELETE FROM redcap_user_information WHERE username = ?', [USER]);

// Table auth, hashed by REDCap's own helper: hash($password_algo, $password . $salt) with a PER-USER
// salt, not password_hash(). Going through Authentication also survives a REDCap upgrade that
// changes the algorithm.
$salt = \Authentication::generatePasswordSalt();
$hash = \Authentication::hashPassword(PASS, $salt);
$module->query(
    'INSERT INTO redcap_auth (username, password, password_salt, legacy_hash, password_question, '
    . 'password_answer, temp_pwd) VALUES (?, ?, ?, 0, NULL, NULL, 0)',
    [USER, $hash, $salt]
);
if (!\Authentication::verifyTableUsernamePassword(USER, PASS)) {
    fwrite(STDERR, "The seeded password does not verify against REDCap's own check. Aborting.\n");
    exit(1);
}
echo "  password verified through Authentication::verifyTableUsernamePassword()\n";

$module->query(
    'INSERT INTO redcap_user_information (username, user_email, user_firstname, user_lastname, '
    . 'user_creation, super_user, account_manager, allow_create_db) '
    . 'VALUES (?, ?, ?, ?, NOW(), 0, 0, 0)',
    [USER, USER . '@example.invalid', 'E2E', 'GiftCard Tester']
);

// Data-entry rights cloned from an existing member of the project, minus design and user-rights.
// A coordinator ticking a payment checkbox is an ordinary user, and running the repro as an admin
// is exactly how an access-dependent bug stays hidden.
$src = $module->query(
    'SELECT data_entry, data_export_tool FROM redcap_user_rights WHERE project_id = ? LIMIT 1',
    [$PID]
)->fetch_assoc();
if ($src === null) {
    fwrite(STDERR, "No existing user rights row on pid $PID to clone form-level rights from.\n");
    exit(1);
}
$module->query(
    'INSERT INTO redcap_user_rights (project_id, username, expiration, group_id, design, user_rights, '
    . 'data_export_tool, data_entry, record_create, record_delete, lock_record, record_rename) '
    . 'VALUES (?, ?, NULL, NULL, 0, 0, ?, ?, 1, 0, 0, 0)',
    [$PID, USER, $src['data_export_tool'], $src['data_entry']]
);
echo "  created user " . USER . " with data-entry rights (no design, no user-rights)\n";

// ---- a clean participant record with a fresh email address
$module->query("DELETE FROM $dataTbl WHERE project_id = ? AND record = ?", [$PID, RECORD]);
$module->query('DELETE FROM redcap_record_list WHERE project_id = ? AND record = ?', [$PID, RECORD]);

$email = e2eEmail();
$seed  = [RECORD => [EVENT => [
    'participant_id' => RECORD,
    EMAIL_FIELD      => $email,
]]];
$res = \REDCap::saveData($PID, 'array', $seed, 'overwrite');
if (empty($res['ids']) || !empty($res['errors'])) {
    fwrite(STDERR, "Seeding record " . RECORD . " failed: " . json_encode($res) . "\n");
    exit(1);
}
echo "  seeded record " . RECORD . " with " . EMAIL_FIELD . " = $email\n";

// ---- optionally, a plain-code card that will be chosen ahead of every real one
if ($FLAVOR === 'plain') {
    $module->query("DELETE FROM $gclTbl WHERE project_id = ? AND record = ?", [$gclPid, PLAIN_CARD]);
    $card = [PLAIN_CARD => [$module->checkGiftCardLibEventId(new \Project($gclPid), null) => [
        'reward_id'    => PLAIN_CARD,
        'brand'        => 'Amazon',
        'egift_number' => PLAIN_CODE,          // deliberately NOT a URL
        'challenge_code' => 'CHAL-42',
        'amount'       => '25.00',
        'status'       => '1',
    ]]];
    $res = \REDCap::saveData($gclPid, 'array', $card, 'overwrite');
    if (empty($res['ids']) || !empty($res['errors'])) {
        fwrite(STDERR, "Seeding plain-code card failed: " . json_encode($res) . "\n");
        exit(1);
    }
    echo "  seeded plain-code library card " . PLAIN_CARD . " (egift_number = " . PLAIN_CODE . ")\n";
}

// ---- what the library looks like going in
$avail = $module->query(
    "SELECT COUNT(*) c FROM $gclTbl d WHERE d.project_id = ? AND d.field_name = 'status' AND d.value = '1'",
    [$gclPid]
)->fetch_assoc()['c'];
echo "  library has $avail cards at status 1 (Ready)\n\n";

echo "USER=" . USER . "\n";
echo "PASS=" . PASS . "\n";
echo "PID=$PID\n";
echo "RECORD=" . RECORD . "\n";
echo "EVENT=" . EVENT . "\n";
echo "EMAIL=$email\n";
echo "FLAVOR=$FLAVOR\n";
