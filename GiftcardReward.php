<?php

namespace Stanford\GiftcardReward;

use Exception;
use ExternalModules\ExternalModules;

require_once "emLoggerTrait.php";
require_once "src/InsertInstrumentHelper.php";
require_once "src/RewardInstance.php";
require_once "util/GiftCardUtils.php";

/**
 * Class GiftcardReward
 * @package Stanford\GiftcardReward
 */
class GiftcardReward extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    /******************************************************************************************************************/
    /* HOOK METHODS                                                                                                   */
    /******************************************************************************************************************/
    /**
     * When the External Module configuration is saved, check to make sure each configuration is valid
     *
     * @param $project_id - this project ID
     */
    public function redcap_module_save_configuration($project_id) {

        // Get GiftCard Repo Info
        $gcr_pid = $this->getProjectSetting('gcr-pid');
        $gcr_event_id = $this->getProjectSetting('gcr-event-id');

        // First check the Gift Card Library to see if it is valid
        try {
            list($validLib, $messageLib) = verifyGiftCardRepo($gcr_pid, $gcr_event_id);
            if (!$validLib) {
                $this->emError($messageLib);
            }
        } catch (Exception $ex) {
            $this->emError("Exception catch verifying Gift Card Library");
        }

        // Retrieve Gift Card configurations
        $instances = $this->getSubSettings('rewards');

        // Next check the Gift Card Project to see if it is valid
        list($validConfig, $mesageConfig) = $this->verifyConfigs($gcr_pid, $gcr_event_id, $instances);
        if (!$validConfig) {
            $this->emError($mesageConfig);
        }
    }

    /**
     * When a record is saved, look at each Gift Card Configuration and make sure:
     *      1) Verify the configuration is valid
     *      2) If valid, check if the record meets the reward criteria
     *      3) If criteria is met
     *          a) Send out email to tell recipient that they have a gift card award
     *          b) Update the gift card library to reserve the gift card
     *          c) Update the current project to save the gift card rewards record and status
     *
     * @param $project_id
     * @param null $record
     * @param $instrument
     * @param $event_id
     * @param null $group_id
     * @param null $survey_hash
     * @param null $response_id
     * @param $repeat_instance
     */
    public function redcap_save_record($project_id, $record = NULL,  $instrument,  $event_id,  $group_id = NULL,  $survey_hash = NULL,  $response_id = NULL, $repeat_instance) {

        // Return the Reward configurations
        $gc_pid = $this->getProjectSetting("gcr-pid");
        $gc_event_id = $this->getProjectSetting("gcr-event-id");
        $alert_email = $this->getProjectSetting("alert-email");
        $configs = $this->getSubSettings("rewards");
        $this->emDebug("In save record for project $project_id and record $record");
        $this->emDebug("Library id $gc_pid and Lib event id $gc_event_id and email $alert_email");
        $this->emDebug("Configs: " . json_encode($configs));

        foreach ($configs as $config => $config_info) {

            $this->emDebug("Looking at config " . $config_info["reward-title"] . " for record $record" . ", " . json_encode($config_info));

            $reward = new RewardInstance($this, $gc_pid, $gc_event_id, $alert_email, $config_info);
            $this->emDebug("Reward: " . $reward);

            $status = $reward->verifyConfig();
            $this->emDebug("Status of verifying config '" . $status . "' for record $record");
            if ($status) {
                $eligible = $reward->checkRewardStatus($record);
                $this->emDebug("Record $record status is '". $eligible . "' for reward " . $config_info["reward-title"]);

                if ($eligible) {
                    $message = "[PID:". $project_id . "] - record $record is eligible for " . $config_info["reward-title"] . " reward.";
                    $this->emDebug($message);
                    list($rewardSent, $message) = $reward->processReward($record);
                    $this->emDebug("Reward sent status is '" . $rewardSent . "' for record $record");

                    if ($rewardSent) {
                        $message = "Finished processing reward for [$project_id] record $record for " . $config_info["reward-title"] . " reward.";
                        $this->emLog($message);
                    } else {
                        $message .= "<br>ERROR: Reward for [PID:$project_id] record $record for " . $config_info["reward-title"] . " reward was not processed.";
                        $this->emError($message);
                    }
                }

            } else {
                $message = "[PID:" . $project_id . "] Reward configuration " . $config_info["reward-title"] . " is invalid so cannot evaluate for records!";
                $this->emError($message);
            }
        }
    }


    /******************************************************************************************************************/
    /* METHODS                                                                                                       */
    /******************************************************************************************************************/
    /**
     * This function takes the settings for each Gift Card configuration and rearranges them into arrays of subsettings
     * instead of arrays of key/value pairs. This is called from javascript so each configuration
     * can be verified in real-time.
     *
     * @param $key - JSON key where the subsettings are stored
     * @param $settings - retrieved list of subsettings from the html modal
     * @return array - the array of subsettings for each configuration
     */
    public function parseSubsettingsFromSettings($key, $settings) {
        $config = $this->getSettingConfig($key);
        if ($config['type'] !== "sub_settings") return false;

        // Get the keys that are part of this subsetting
        $keys = [];
        foreach ($config['sub_settings'] as $subSetting) {
            $keys[] = $subSetting['key'];
        }

        // Loop through the keys to pull values from $settings
        $subSettings = [];
        foreach ($keys as $key) {
            $values = $settings[$key];
            foreach ($values as $i => $value) {
                $subSettings[$i][$key] = $value;
            }
        }
        return $subSettings;
    }

    /**
     * This function will loop over all configs specified in the External Module Setup. Each config
     * will be checked for validity and the user will be warned it is not valid. If there no records
     * in the project yet, the logic when to send a reward cannot be tested.
     *
     * @param $project_id
     * @return array
     */
    public function verifyConfigs($gcr_pid, $gcr_event_id, $instances) {

        $errors = array();
        $overallStatus = true;
        $alert_email = $this->getProjectSetting("alert-email");

        foreach ($instances as $i => $instance) {

            // Create a new reward instance and verify the config
            $this->emDebug("Configuration " . ($i+1) . " is: " . json_encode($instance));
            $ri = new RewardInstance($this, $gcr_pid, $gcr_event_id, $alert_email, $instance);

            list($result,$message) = $ri->verifyConfig();
            if ($result === false) {
                $overallStatus = $result;
                $this->emError("Errors with instance " . ($i+1), $message);
                $errors[] = '<b>Gift Card config ' . ($i+1) . ' has error message:</b> ' . $message;
            }
        }

        return array($overallStatus, $errors);
    }

    /******************************************************************************************************************/
    /* CRON METHODS                                                                                                   */
    /******************************************************************************************************************/
    /**
     * This function will be called by the Cron on a daily basis.  It will call DailySummary.php for each project that has
     * this EM enabled via an API call (so that the project context is setup).
     */
    public function giftCardCron () {

        // Find all the projects that are using the Gift Card Rewards EM
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        while($row = db_fetch_assoc($enabled)) {

            $proj_id = $row['project_id'];

            // Create the API URL to this project
            $dailySummaryURL = $this->getUrl('src/DailySummary.php?pid=' . $proj_id, false, true);
            $this->emDebug("Calling cron Daily Summary for project $proj_id at URL " . $dailySummaryURL);

            // Call the project through the API so it will be in project context
            $response = http_get($dailySummaryURL);

            $this->emDebug("Back from API call for project $proj_id: ", $response);
        }
    }

}