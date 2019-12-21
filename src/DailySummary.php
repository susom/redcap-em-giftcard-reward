<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

require_once $module->getModulePath() .  "util/GiftCardUtils.php";

use Exception;
use REDCap;

global $Proj;

$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$module->emDebug("In DailySummary for project_id $pid");

/*
 * We are called through an API call from the function giftCardCron in GiftcardReward.php.  giftCardCron runs daily
 * on a cron and will process all projects who have this EM enabled.  We are called for each project with the pid
 * in the URL so the project context is setup for us.
 *
 * Once here, we will loop over each configuration and gather the summary data so one email can be sent per project.
 * If projects do not want summary information, they can select the checkbox which opts out of this data.  Each
 * configuration has a separate checkbox so projects can receive summary data for selected configurations.
 *
 * Each gift card summary will consist of the following data for each configuration in the gift card library project:
 *  1) how many gift card rewards were sent in email yesterday
 *  2) How many gift card rewards were viewed(claimed) yesterday
 *  3) How many gift cards were sent > 7 days ago and have not been viewed
 *  4) How many gift cards were sent < 7 days ago and have not been viewed
 *  5) How many gift cards are still Ready to be awarded
 *  6) How many gift cards in total have been awarded and viewed
*/

// Retrieve all the gift card configurations for this project
$gcr_pid = $module->getProjectSetting("gcr-pid");
$gcr_event_id = $module->getProjectSetting("gcr-event-id");
$alert_email = $module->getProjectSetting("alert-email");
$cc_email = $module->getProjectSetting("cc-email");
$configs = $module->getSubSettings("rewards");

try {
    // First make sure the Library is valid (this is the same repo for all configurations)
    list($valid, $message) = verifyGiftCardRepo($gcr_pid, $gcr_event_id);
    if (!$valid) {
        $module->emError($message);
        return;
    }
} catch (Exception $ex) {
    $module->emError("Exception verifying Gift Card Library (pid=" . $gcr_pid . ") for project $pid - cannot create Daily Summary", $ex->getMessage());
    return;
}

$body = '';
foreach ($configs as $configNum => $config) {

    // If the project has opted out of receiving a summary for this configuration, skip
    if (empty($config['optout-daily-summary'])) {

        // Make sure this config is valid
        try {
            $ri = new RewardInstance($module, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $config);
        } catch (Exception $ex) {
            $module->emError("Could not create RewardInstance (pid=" . $gcr_pid . ") for project $pid - cannot create Daily Summary", $ex->getMessage());
            return;
        }
        list($valid, $message) = $ri->verifyConfig();
        if ($valid) {
            // If the config is valid, go retrieve the summary statistics
            $stats = $ri->retrieveSummaryData();
            $body .= createConfigSummary($config['reward-title'], $stats);
        }
    } else {
        $module->emLog("Skipping Daily Summary for project $pid, configuration " . ($configNum+1));
    }
}

// Only send one email for each project
$status = REDCap::email($alert_email, $alert_email, "Gift Card Daily Summary for project $pid", $body);
return;

/**
 * This function will take the gift card library summary data and format it to a readable form.
 *
 * @param $configNum
 * @param $stats
 * @return string
 */
function createConfigSummary($configNum, $stats) {

    global $pid;

    $yesterday = date('Y-m-d',strtotime("-1 days"));

    $message = "<br>";
    $message .= "<h4>Gift Card Summary for project $pid - configuration $configNum</h4>";
    $message .= "<ul>";
    $message .= "<li>Number of gift cards sent out yesterday (" . $yesterday . "): " . $stats['sent_yesterday'] . "</li>";
    $message .= "<li>Number of gift cards claimed yesterday (" . $yesterday . "): " . $stats['claimed_yesterday'] . "</li>";
    $message .= "<li>Number of gift cards that were sent > 7 days ago and not claimed: " . $stats['sent_gt7days_ago'] . "</li>";
    $message .= "<li>Number of gift cards that were sent <= 7 days ago and not claimed: " . $stats['sent_lt7days_ago'] . "</li>";
    $message .= "<li>Number of gift cards with status 'Not Available': " . $stats['not_ready'] . "</li>";
    $message .= "<li>Number of gift cards that are available to be awarded: " . $stats['num_available'] . "</li>";
    $message .= "<li>Number of gift cards that were awarded: " . $stats['num_awarded'] . "</li>";
    $message .= "<li>Number of gift cards that were claimed: " . $stats['num_claimed'] . "</li>";
    $message .= "</ul><br>";

    return $message;
}
