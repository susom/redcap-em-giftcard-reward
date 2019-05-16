<?php

namespace Stanford\GiftcardReward;

use ExternalModules\ExternalModules;
use \Project;
use \Exception;

require_once "emLoggerTrait.php";
require_once "src/InsertInstrumentHelper.php";
require_once "src/RewardInstance.php";

/**
 * Class GiftcardReward
 * @package Stanford\GiftcardReward
 */
class GiftcardReward extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    // These are required fields for the gift card library.  If any of these fields are not
    // present, we cannot continue.  We will give the option to update a form with these fields.
    protected $gcr_required_fields = array('reward_id', 'egift_number', 'url', 'amount', 'status',
                                    'reward_hash', 'reward_name', 'reward_pid', 'reward_record',
                                    'reserved_ts', 'claimed_ts');

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
            list($validLib, $messageLib) = $this->verifyGiftCardRepo($gcr_pid, $gcr_event_id);
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
        $configs = $this->getSubSettings("rewards");

        foreach ($configs as $config => $config_info) {
            $reward = new RewardInstance($this, $gc_pid, $gc_event_id, $config_info);
            $status = $reward->verifyConfig();
            if ($status) {
                $eligible = $reward->checkRewardStatus($record);

                if ($eligible) {
                    $message = "[PID:". $project_id . "] - record $record is eligible for " . $config_info["reward-title"] . " reward.";
                    $this->emDebug($message);
                    list($rewardSent, $message) = $reward->processReward($record, $this->gcr_required_fields);

                    if ($rewardSent) {
                        $message = "Reward for [$project_id] record $record was sent for " . $config_info["reward-title"] . " reward.";
                        $this->emLog($message);
                    } else {
                        $message .= "<br>ERROR: Reward for [PID:$project_id] record $record was NOT sent for " . $config_info["reward-title"] . " reward.";
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

        foreach ($instances as $i => $instance) {

            // Create a new reward instance and verify the config
            $this->emDebug("Configuration " . ($i+1) . " is: " . json_encode($instance));
            $ri = new RewardInstance($this, $gcr_pid, $gcr_event_id, $instance);

            list($result,$message) = $ri->verifyConfig();
            if ($result === false) {
                $overallStatus = $result;
                $this->emError("Errors with instance " . ($i+1), $message);
                $errors[] = '<b>Gift Card config ' . ($i+1) . ' has error message:</b> ' . $message;
            }
        }

        return array($overallStatus, $errors);
    }

    /**
     * This function performs checks on the gift card library to make sure it is valid. The checks are:
     *  1) Make sure all available fields exist (list is listed at top of this class).
     *  2) Make sure all the fields are in the same event
     *  3) Make sure they do not reside on a repeating form or event
     *  4) Make sure the timestamps are in the correct format (Y-M-D H:M:S)
     *
     * @return array
     * @throws \Exception
     */
    public function verifyGiftCardRepo($gcr_pid, $gcr_event_id) {

        $this->emDebug("This is the Library pid $gcr_pid and event $gcr_event_id");
        $message = array();
        $title = "<b>Gift Card Library:</b><br>";

        // Setup the data dictionary so we can check for the requird fields
        $gcr_proj = new Project($gcr_pid);
        $gcr_proj->setRepeatingFormsEvents();

        // The event id was not given, go find it
        // Make sure we have a correct event_id in the gift card rewards project
        if (($gcr_proj->numEvents > 1) && empty($gcr_event_id)) {
            // If this project has more than 1 event, the event id must be specified
            $message[] = $title . "<li>This project $gcr_pid contains more than 1 event, you must specify which event to use:</li>";
        } else {
            // If this project has only 1 event id and it wasn't specified, then set it.
            if (empty($gcr_event_id)) {
                $gcr_event_id = array_keys($gcr_proj->eventInfo)[0];
            }
        }

        // Now check to make sure the event_id is part of this project
        if (!empty($gcr_event_id)) {
            $found = (isset($gcr_proj->eventInfo[$gcr_event_id]) ? true : false);
            if (!$found) {
                $message[] = $title . "<li>This event ID $gcr_event_id does not belong to project $gcr_pid</li>";
            }
        }

        // If we don't have a valid event_id for this project, we cannot continue.
        if (!empty($message)) {
            return array(false, $message);
        }

        // Make sure all the fields we are expecting to be available in the gift card library project are in the event_id
        $gcr_forms_in_event = $gcr_proj->eventsForms[$gcr_event_id];
        $all_event_fields = array();
        $all_event_forms = array();

        // Make an array of all the fields in all the forms in this event
        foreach($gcr_forms_in_event as $form_num => $form_name) {
            $all_event_forms[] = $gcr_proj->forms[$form_name];
            $all_event_fields = array_merge($all_event_fields, array_keys($gcr_proj->forms[$form_name]['fields']));
        }

        // See if there are any required fields that are not found in the field list.
        $fields_not_found = array_diff($this->gcr_required_fields, $all_event_fields);

        if (!empty($fields_not_found)) {
            $message[] = $title . "<li>Required fields not found in the Gift Card Rewards project $gcr_pid event id $gcr_event_id are: " . implode(',', $fields_not_found) . "</li>";
        }

        // Make sure this form is not a repeating form and not in a repeating event
        $repeat_forms = $gcr_proj->RepeatingFormsEvents[$gcr_event_id];
        if (!empty($repeat_forms)) {
            if ($repeat_forms == 'WHOLE') {
                $message[] = $title . "<li>The Gift Card Rewards instruments cannot be a repeating event for project $gcr_pid event id $gcr_event_id </li>";
            } else {
                $gcr_repeat_forms = array_keys($repeat_forms);
                $intersection = array_intersect($all_event_forms, $gcr_repeat_forms);
                if (!empty($intersection)) {
                    $message[] = $title . "<li>The Gift Card Rewards instrument(s) " . implode(',', $intersection) . " cannot be a repeating form for project $gcr_pid event id $gcr_event_id</li>";
                }
            }
        }

        // Make sure the reserve and viewed timestamp are in 'datetime_seconds_ymd' format so we can successfully save
        $missing_timestamp_fields = array_intersect($fields_not_found, array('reserved_ts', 'claimed_ts'));
        if (empty($missing_timestamp_fields)) {
            if (($gcr_proj->metadata['reserved_ts']['element_validation_type'] != 'datetime_seconds_ymd') ||
                ($gcr_proj->metadata['claimed_ts']['element_validation_type'] != 'datetime_seconds_ymd')) {
                $message[] = $title . "<li>The timestamps must be in format 'Y-M-D H:M:S - please change the Gift Card Rewards instrument.</li>";
            }
        }

        if (empty($message)) {
            return array(true, null);
        } else {
            return array(false, $message);
        }
    }

    /**
     * This function will determine how many gift cards are available for the configuration that
     * is specified.  It is currently not being used but can put on a cron to update projects
     * when their card count is low.
     *
     * @param $configNum
     * @return int|null
     */
    function numAvailableRewards($configNum) {

        // Get GiftCard Repo Info
        $gcr_pid = $this->getProjectSetting('gcr-pid');
        $gcr_event_id = $this->getProjectSetting('gcr-event-id');

        // Verify Reward Instance Configurations
        $instances = $this->getSubSettings('rewards');
        $instance = $instances[$configNum];
        if (!empty($instance)) {
            $ri = new RewardInstance($this, $gcr_pid, $gcr_event_id, $instance);
            $numRewards =  $ri->numberAvailableRewards($this->gcr_required_fields);
            $this->emLog("This is the number of available rewards for config $configNum: " . $numRewards);
            return $numRewards;
        } else {
            $this->emLog("Invalid configuration number $configNum");
            return null;
        }
    }

}