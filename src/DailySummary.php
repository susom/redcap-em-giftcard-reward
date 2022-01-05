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
    $gclib = new VerifyLibraryClass($gcr_pid, $gcr_event_id);
    [$valid, $message] = $gclib->verifyLibraryConfig();

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

        $stats = retrieveSummaryData($config, $gcr_pid, $gcr_event_id);
        $body .= createConfigSummary($config['reward-title'], $stats);
    } else {
        $module->emLog("Skipping Daily Summary for project $pid, configuration " . ($configNum+1));
    }
}

// Only send one email for each project
$status = REDCap::email($alert_email, $alert_email, "Gift Card Daily Summary for project $pid", $body);
return;

/**
 * This function is called from the nightly cron for each gift card configuration that has not opted-out of receiving it.
 * The gift card library will be checked for the following:
 *          1) how many gift card rewards were sent in email yesterday
 *          2) How many gift card rewards were viewed(claimed) yesterday
 *          3) How many gift cards were sent > 7 days ago and have not been viewed
 *          4) How many gift cards were sent < 7 days ago and have not been viewed
 *          5) How many gift cards are still Ready to be awarded
 *          6) How many gift cards in total have been awarded
 *
 * @return array
 */
function retrieveSummaryData($config, $gcr_pid, $gcr_event_id) {

    global $module;

    $rewards_sent_yesterday = 0;
    $rewards_claimed_yesterday = 0;
    $num_gc_sent_more_than_7days_ago = 0;
    $num_gc_send_less_than_7days_ago = 0;
    $num_gc_notready = 0;
    $num_gc_available = 0;
    $num_gc_awarded = 0;
    $num_gc_claimed = 0;
    $today = strtotime(date('Y-m-d'));

    // Make sure we only retrieve the records that pertain to this configuration (based on gift card amount) and title
    $filter = "[amount] = '" . $config['reward-amount'] . "'";

    $data = REDCap::getData($gcr_pid, 'array', null, null, $gcr_event_id, null, null, null, null, $filter);
    if (!empty($data)) {
        $brand_type = array();
        foreach ($data as $record_id => $event_info) {
            foreach ($event_info as $event_id => $record) {

                $status = $record['status'];
                if ($record['reward_name'] == $config['reward-title']) {
                    // Convert timestamps so we can do date math
                    $datetime_sent = strtotime(date($record['reserved_ts']));
                    $date_sent = strtotime(date("Y-m-d", $datetime_sent));

                    if ($record['claimed_ts'] !== '') {
                        $datetime_claimed = strtotime(date($record['claimed_ts']));
                        $date_claimed = strtotime(date("Y-m-d", $datetime_claimed));
                    } else {
                        $date_claimed = '';
                    }

                    $num_days_sent = intval(($today - $date_sent) / 86400);
                    if ($date_claimed != '') {
                        $num_days_claimed = intval(($today - $date_claimed) / 86400);
                    } else {
                        $num_days_claimed = 0;
                    }

                    // Num of gift cards sent yesterday
                    if (($num_days_sent == 1) && (!empty($record['reserved_ts']))) {
                        $rewards_sent_yesterday++;
                    }

                    // Num of gift cards claimed yesterday
                    if (($num_days_claimed == 1) && ($status == 3)) {
                        $rewards_claimed_yesterday++;
                    }

                    // Num of gift cards sent > 7 days ago and have not been viewed
                    if (($num_days_sent > 7) && ($status == 2)) {
                        $num_gc_sent_more_than_7days_ago++;
                    }

                    // Num of gift cards sent < 7 days ago and have not been viewed
                    if (($num_days_sent <= 7) && ($status == 2)) {
                        $num_gc_send_less_than_7days_ago++;
                    }

                    // Num of gift cards in total have been awarded
                    if (($status == 2) || ($status == 3)) {
                        $num_gc_awarded++;
                    }

                    // Num of gift cards in total have been claimed
                    if ($status == 3) {
                        $num_gc_claimed++;
                    }
                }

                // Num of gift cards with NOT Ready status (value of 0)
                if ($status == 0) {
                    $num_gc_notready++;
                }

                // Num of gift cards with Ready status (value of 1 means Ready)
                if ($status == 1) {
                    $brand = $record['brand'];
                    $num_gc_available++;
                    if (empty($brand_type[$brand])) {
                        $brand_type[$brand] = 1;
                    } else {
                        $brand_type[$brand]++;
                    }
                }
            }
        }

    } else {
        $module->emError("No data was found for DailySummary from gc library [pid:$gcr_pid/event id:$gcr_event_id]");
    }

    $results = array(
        "sent_yesterday"        => $rewards_sent_yesterday,
        "claimed_yesterday"     => $rewards_claimed_yesterday,
        "sent_gt7days_ago"      => $num_gc_sent_more_than_7days_ago,
        "sent_lt7days_ago"      => $num_gc_send_less_than_7days_ago,
        "not_ready"             => $num_gc_notready,
        "num_available"         => $num_gc_available,
        "num_awarded"           => $num_gc_awarded,
        "num_claimed"           => $num_gc_claimed,
        "brand"                 => $brand_type
    );

    return $results;
}



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
    $message .= "<li>Number of gift cards that were awarded: " . $stats['num_awarded'] . "</li>";
    $message .= "<li>Number of gift cards that were claimed: " . $stats['num_claimed'] . "</li>";
    $message .= "<li>Number of gift cards that are available to be awarded: " . $stats['num_available'] . "</li>";

    // If these are split by brands, show each brand quantity as well as the total
    if (!empty($stats['brand'])) {
        $message .= "<ul>";
        foreach ($stats['brand'] as $brand => $count) {
            $message .= "<li>Number of gift cards that are available to be awarded for " . $brand . ": " . $count . "</li>";
        }
        $message .= "</ul>";
    }
    $message .= "</ul><br>";

    return $message;
}
