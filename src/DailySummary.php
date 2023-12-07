<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

require_once $module->getModulePath() .  "src/VerifyLibraryClass.php";

use Exception;
use REDCap;
use Project;

$pid = $module->getProjectId();

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
$configs = $module->getSubSettings("rewards");

try {

    // Check to see if the event id is set
    $gcr_proj = new Project($gcr_pid);
    $gcr_proj->setRepeatingFormsEvents();
    $gcr_event_id = $module->checkGiftCardLibEventId($gcr_proj, $gcr_event_id);

    // First make sure the Library is valid (this is the same repo for all configurations)
    $gclib = $module->getVerifyLibraryClass($gcr_pid, $gcr_event_id, $module);
    [$valid, $message] = $gclib->verifyLibraryConfig();

    if (!$valid) {
        $module->emError($message);
        \REDCap::logEvent($message);
        return;
    }
} catch (Exception $ex) {
    $module->emError("Exception verifying Gift Card Library (pid=" . $gcr_pid . ") for project $pid - cannot create Daily Summary", $ex->getMessage());
    \REDCap::logEvent("Exception verifying Gift Card Library (pid=" . $gcr_pid . ") for project $pid - cannot create Daily Summary", $ex->getMessage());
    return;
}

$body = '';
foreach ($configs as $configNum => $config) {

    // If the project has opted out of receiving a summary for this configuration, skip
    if (empty($config['optout-daily-summary'])) {

        $stats = $module->retrieveSummaryData($config, $gcr_pid, $gcr_event_id);
        $body .= createConfigSummary($config['reward-title'], $stats);
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

    global $module, $pid;

    $yesterday = date('Y-m-d',strtotime("-1 days"));

    $message = "<br>";
    $message .= "<h4>" . $module->tt("display_title", $pid, $configNum) . "</h4>";
    $message .= "<ul>";
    $message .= "<li>" . $module->tt("sent_yesterday", $yesterday, $stats['sent_yesterday']) . "</li>";
    $message .= "<li>" . $module->tt("claimed_yesterday", $yesterday, $stats['claimed_yesterday']) . "</li>";
    $message .= "<li>" . $module->tt("not_claimed_gt_7days", $stats['sent_gt7days_ago']) . "</li>";
    $message .= "<li>" . $module->tt("not_claimed_le_7days", $stats['sent_lt7days_ago']) . "</li>";
    $message .= "<li>" . $module->tt("not_available", $stats['not_ready']) . "</li>";
    $message .= "<li>" . $module->tt("total_awarded", $stats['num_awarded']) . "</li>";
    $message .= "<li>" . $module->tt("total_claimed", $stats['num_claimed']) . "</li>";
    $message .= "<li>" . $module->tt("total_available", $stats['num_available']) . "</li>";

    // If these are split by brands, show each brand quantity as well as the total
    if (!empty($stats['brand'])) {
        $message .= "<ul>";
        foreach ($stats['brand'] as $brand => $count) {
            $message .= "<li>" . $module->tt("total_available_per_brand", $brand, $count) . "</li>";
        }
        $message .= "</ul>";
    }
    $message .= "</ul><br>";

    return $message;
}
