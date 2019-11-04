<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

use REDCap;
use Exception;

require_once ($module->getModulePath() . "util/GiftCardUtils.php");

/**
 *  When Gift Card configurations use Cron processing, the Cron job must call each project through an API because the processing needs to be done
 *  in project context.
 *
 *  Now that we are in Project context, retrieve the list of configurations and check for the Enable Cron checkbox.  When Cron is enabled, first
 *  check to make sure the configuration is valid. This only needs to be done once since it is the same configuration for each record. Then, retrieve
 *  the list of records who have not rewarded a gift card yet (gift card library record id is blank).
 *
 *  Loop over each record and evaluation the logic to determine if the record is eligible for a gift card. If so, send out the reward.
 */


$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;

// Retrieve the gift card configurations for this project id
$gc_pid = $module->getProjectSetting("gcr-pid");
$gc_event_id = $module->getProjectSetting("gcr-event-id");
$alert_email = $module->getProjectSetting("alert-email");
$cc_email = $module->getProjectSetting("cc-email");
$configs = $module->getSubSettings("rewards");

// For this project, loop over each config to see which has the Cron enabled.
foreach($configs as $config_num => $config_info) {

    // Check this project's config to see if they checked the box which uses a cron job to check reward logic
    $use_cron = $config_info["enable-cron"];
    if ($use_cron) {

        // Instantiate a reward instance and make sure the config is valid. We only need to do this once.
        try {
            $reward = new RewardInstance($module, $gc_pid, $gc_event_id, $alert_email, $cc_email, $config_info);
            $status = $reward->verifyConfig();
            if (!$status) {
                $message = "[Processing cron PID:" . $pid . "] Reward configuration " . $config_info["reward-title"] . " is invalid so cannot evaluate record logic!";
                $module->emError($message);
                return;
            }
        } catch (Exception $ex) {
            $module->emError("Cannot create instance of class RewardInstance. Exception message: " . $ex->getMessage());
            return;
        }

        // The config is valid so now we need to check all records that might be eligible for a reward.
        // Retrieve all records that don't have the reward field already filled in
        $config_event_id = $config_info['reward-fk-event-id'];
        $filter = "[" . $config_info['reward-fk-field'] . "] = ''";
        $proj_data = REDCap::getData($pid, 'array', null, null, $config_event_id, null, null, null, null, $filter);

        // Loop over each record that has not had a gift card dispersed and check the logic to see if they are now eligible
        foreach($proj_data as $record_id => $record_data) {

            $eligible = $reward->checkRewardStatus($record_id);
            if ($eligible) {

                // This record is eligible for a reward. Process the reward.
                $message = "[PID:". $pid . "] - record $record_id is eligible for " . $config_info["reward-title"] . " reward.";
                $module->emDebug($message);
                list($rewardSent, $message) = $reward->processReward($record_id);

                // If the reward was successfully sent, log it, otherwise, log an error because we weren't able to send a reward.
                if ($rewardSent) {
                    $message = "Finished processing reward for [$pid] record $record_id for " . $config_info["reward-title"] . " reward.";
                    $module->emDebug($message);
                } else {
                    $message .= "<br>ERROR: Reward for [PID:$pid] record $record_id for " . $config_info["reward-title"] . " reward was not processed.";
                    $module->emError($message);
                }
            }
        }

    } else {
        $module->emDebug("Not using cron for config $config_num");
    }
}
