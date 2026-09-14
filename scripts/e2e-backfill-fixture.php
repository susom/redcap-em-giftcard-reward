<?php

/**
 * Fixture for the Control Center backfill page E2E.
 *
 *   docker exec <web> php .../scripts/e2e-backfill-fixture.php setup [library pid]
 *   docker exec <web> php .../scripts/e2e-backfill-fixture.php teardown
 *
 * Creates two throwaway accounts -- one super user (the page is admin-only) and one ordinary user
 * (to prove a non-admin is refused, rather than merely not shown the link) -- and snapshots the
 * library columns the test is about to write.
 *
 * The snapshot is the point. Unlike the reservation E2E, which creates its own record and can
 * simply delete it, this test presses Apply on the REAL copied library and rewrites 69 addresses
 * and 92 urls across existing rows. Teardown has to put every one of them back exactly as it was,
 * so setup records the full (record, event, value) set for both fields first and teardown restores
 * it verbatim. It is kept in a module system setting rather than a temp file so a crashed run does
 * not lose it.
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

$MODE   = (string) ($argv[1] ?? 'setup');
$LIBPID = (int) ($argv[2] ?? 266);

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

const ADMIN      = 'e2e_gcr_admin';
const ADMIN_PASS = 'E2eGcAdmin!2026';
/**
 * A SECOND admin, used only by the forged-CSRF case.
 *
 * REDCap does two things that make one account insufficient: signing the same user in again
 * invalidates the earlier session, and a failed forceCsrfTokenCheck() poisons the session it
 * happened in, so the next legitimate POST is refused too. Either way the real Apply fails for a
 * reason that has nothing to do with the code under test. A separate account keeps the forgery
 * genuinely isolated.
 */
const ADMIN2      = 'e2e_gcr_admin2';
const ADMIN2_PASS = 'E2eGcAdmin2!2026';
const USER       = 'e2e_gcr_tester';
const USER_PASS  = 'E2eGiftCard!2026';
const SNAPSHOT   = 'e2e-backfill-snapshot';
/** The two columns the page writes; the only ones that need restoring. */
const TOUCHED    = ['reward_email_addr', 'url'];

$module = \ExternalModules\ExternalModules::getModuleInstance('giftcard_reward');
if (!$module) {
    fwrite(STDERR, "Could not instantiate giftcard_reward.\n");
    exit(1);
}
$module->disableUserBasedSettingPermissions();

$tbl = \Records::getDataTable($LIBPID);
$in  = "'" . implode("','", TOUCHED) . "'";

function makeUser($module, string $user, string $pass, bool $superUser): void
{
    $module->query('DELETE FROM redcap_auth WHERE username = ?', [$user]);
    $module->query('DELETE FROM redcap_user_information WHERE username = ?', [$user]);

    // REDCap's own hashing: hash($password_algo, $password . $salt) with a per-user salt.
    $salt = \Authentication::generatePasswordSalt();
    $hash = \Authentication::hashPassword($pass, $salt);
    $module->query(
        'INSERT INTO redcap_auth (username, password, password_salt, legacy_hash, password_question, '
        . 'password_answer, temp_pwd) VALUES (?, ?, ?, 0, NULL, NULL, 0)',
        [$user, $hash, $salt]
    );
    if (!\Authentication::verifyTableUsernamePassword($user, $pass)) {
        fwrite(STDERR, "Seeded password for $user does not verify. Aborting.\n");
        exit(1);
    }
    $module->query(
        'INSERT INTO redcap_user_information (username, user_email, user_firstname, user_lastname, '
        . 'user_creation, super_user, account_manager, allow_create_db) VALUES (?, ?, ?, ?, NOW(), ?, 0, 0)',
        [$user, $user . '@example.invalid', 'E2E', $superUser ? 'Admin' : 'Tester', $superUser ? 1 : 0]
    );
    echo "  created " . ($superUser ? 'SUPER USER ' : 'ordinary user ') . "$user\n";
}

// ------------------------------------------------------------------ teardown

if ($MODE === 'teardown') {
    echo "Tearing down the backfill-page fixture (library $LIBPID)\n";

    $snapshot = $module->getSystemSetting(SNAPSHOT);
    if (!empty($snapshot)) {
        $rows = json_decode($snapshot, true);
        $module->query("DELETE FROM $tbl WHERE project_id = ? AND field_name IN ($in)", [$LIBPID]);
        foreach ($rows as $r) {
            $module->query(
                "INSERT INTO $tbl (project_id, event_id, record, field_name, value, instance) VALUES (?, ?, ?, ?, ?, ?)",
                [$LIBPID, $r['event_id'], $r['record'], $r['field_name'], $r['value'], $r['instance']]
            );
        }
        echo "  restored " . count($rows) . " row(s) of " . implode('/', TOUCHED) . " in library $LIBPID\n";
        $module->removeSystemSetting(SNAPSHOT);
    } else {
        echo "  no snapshot stored — library left untouched\n";
    }

    foreach ([ADMIN, ADMIN2, USER] as $u) {
        $module->query('DELETE FROM redcap_auth WHERE username = ?', [$u]);
        $module->query('DELETE FROM redcap_user_information WHERE username = ?', [$u]);
    }
    echo "  removed " . ADMIN . ", " . ADMIN2 . " and " . USER . "\n";
    echo "Done.\n";
    exit(0);
}

// ------------------------------------------------------------------ setup

echo "Setting up the backfill-page fixture (library $LIBPID)\n\n";

makeUser($module, ADMIN, ADMIN_PASS, true);
makeUser($module, ADMIN2, ADMIN2_PASS, true);
makeUser($module, USER, USER_PASS, false);

$q    = $module->query("SELECT event_id, record, field_name, value, instance FROM $tbl WHERE project_id = ? AND field_name IN ($in)", [$LIBPID]);
$rows = [];
while ($r = $q->fetch_assoc()) {
    $rows[] = $r;
}
$module->setSystemSetting(SNAPSHOT, json_encode($rows));
echo "  snapshotted " . count($rows) . " existing row(s) of " . implode('/', TOUCHED) . "\n\n";

echo "ADMIN=" . ADMIN . "\n";
echo "ADMIN_PASS=" . ADMIN_PASS . "\n";
echo "ADMIN2=" . ADMIN2 . "\n";
echo "ADMIN2_PASS=" . ADMIN2_PASS . "\n";
echo "USER=" . USER . "\n";
echo "USER_PASS=" . USER_PASS . "\n";
