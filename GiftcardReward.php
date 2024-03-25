<?php

namespace Stanford\GiftcardReward;

use Exception;
use ExternalModules\ExternalModules;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

require_once "emLoggerTrait.php";
require_once "src/InsertInstrumentHelper.php";
require_once "src/RewardInstance.php";
require_once "src/VerifyLibraryClass.php";

/**
 * Class GiftcardReward
 * @package Stanford\GiftcardReward
 *
 * This Gift Card Reward EM will automate the dispersement of gift cards based on logic specified in the configuration file.
 *
 * This module requires 2 projects to work in tandem.  The first project will be the gift card project specific to the study.
 * This project will store specific information needed for the study but it must also contain two fields required by this
 * module.  The additional required fields are: 1) a text field which stores the gift card library record id,
 * and 2) a field to store the status of the gift card (possible options are Reserved, Claimed, etc.).
 *
 * Th
 *
 */
class GiftcardReward extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;

    /**
     * @var \Stanford\GiftcardReward\VerifyLibraryClass;
     */
    private $verifyLibraryClass;

    /**
     * @var \Stanford\GiftcardReward\RewardInstance[];
     */
    private $rewardInstance;

    /**
     * @var \GuzzleHttp\Client
     */
    private $guzzleClient;
//    public function __construct() {
//        parent::__construct();
//    }

    /******************************************************************************************************************/
    /* HOOK METHODS                                                                                                   */
    /******************************************************************************************************************/
    /**
     * When the External Module configuration is saved, check to make sure each configuration is valid
     *
     * @param $project_id - this project ID
     */
    public function redcap_module_save_configuration($project_id)
    {

        // Get GiftCard Repo Info
        $gcr_pid = $this->getProjectSetting('gcr-pid');
        $gcr_event_id = $this->getProjectSetting('gcr-event-id');

        // First check the Gift Card Library to see if it is valid
        try {
            $gclib = $this->getVerifyLibraryClass($gcr_pid, $gcr_event_id, $this);
            [$validLib, $messageLib] = $gclib->verifyLibraryConfig();
            if (!$validLib) {
                $this->emError($messageLib);
                \REDCap::logEvent($messageLib);
            }
        } catch (Exception $ex) {
            $this->emError("Exception catch verifying Gift Card Library " . $ex->getMessage());
            \REDCap::logEvent("Exception catch verifying Gift Card Library", $ex->getMessage());
        }

        // Retrieve Gift Card configurations
        $instances = $this->getSubSettings('rewards');

        // Next check the Gift Card Project to see if it is valid
        [$validConfig, $mesageConfig] = $this->verifyEMConfigs($project_id, $gcr_pid, $gcr_event_id, $instances);
        if (!$validConfig) {
            $this->emError($mesageConfig);
            \REDCap::logEvent($mesageConfig);
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
    public function redcap_save_record($project_id, $record = NULL, $instrument, $event_id, $group_id = NULL, $survey_hash = NULL, $response_id = NULL, $repeat_instance)
    {

        // Return the Reward configurations
        $gc_pid = $this->getProjectSetting("gcr-pid");
        $gc_event_id = $this->getProjectSetting("gcr-event-id");
        $alert_email = $this->getProjectSetting("alert-email");
        $cc_email = $this->getProjectSetting("cc-email");
        $configs = $this->getSubSettings("rewards");

        foreach ($configs as $index => $config_info) {

            // Check to see if this config should only be processed during batch processing.
            // If so, skip processing now.
            $batch_processing = $config_info['batch-processing'];
            if (empty($batch_processing)) {

                // Create a reward instance so we can process this record
                try {
                    $reward = $this->getRewardInstance($index, $this, $project_id, $gc_pid, $gc_event_id, $alert_email, $cc_email, $config_info);
                } catch (Exception $ex) {
                    $this->emDebug("Cannot create instance of class RewardInstance. Exception message: " . $ex->getMessage());
                    return;
                }

                // Once the reward instance is created, check to see if this record should receive an award.  If so, send it.
                [$status, $message] = $reward->verifyConfig();
                if ($status) {
                    //$this->emDebug("Looking at config " . $config_info["reward-title"] . " for record $record");

                    $eligible = $reward->checkRewardStatus($record);
                    if ($eligible) {
                        $message = "[PID:" . $project_id . "] - record $record is eligible for " . $config_info["reward-title"] . " reward.";
                        $this->emDebug($message);
                        [$rewardSent, $message] = $reward->processReward($record);

                        if ($rewardSent) {
                            $message = "Finished processing reward for [$project_id] record $record for " . $config_info["reward-title"] . " reward.";
                            $this->emLog($message);
                        } else {
                            $message .= "<br>ERROR: Reward for [PID:$project_id] record $record for " . $config_info["reward-title"] . " reward was not processed.";
                            $this->emError($message);
                            \REDCap::logEvent($message);
                        }
                    }
                } else {
                    $message = "[PID:" . $project_id . "] Reward configuration " . $config_info["reward-title"] . " is invalid so cannot evaluate for records!";
                    $this->emError($message);
                    \REDCap::logEvent($message);
                }
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
    public function parseSubsettingsFromSettings($key, $settings)
    {
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
     * @param $gcr_pid - gift card library project id as specified in the config
     * @param $gcr_event_id = gift card reward library project event id where the rewards will be kept
     * @param $instances = array of configurations setup for gift card dispersement
     * @param null $alert_email = Email which will be alerted when the number of gift cards is low
     * @param null $cc_email = Email address to cc if setup in the configuration
     * @return array with overall status and an array of errors
     */
    public function verifyEMConfigs($project_id, $gcr_pid, $gcr_event_id, $instances, $alert_email = null, $cc_email = null)
    {

        $errors = array();
        $overallStatus = true;
        if (is_null($alert_email)) $alert_email = $this->getProjectSetting("alert-email");
        if (is_null($cc_email)) $cc_email = $this->getProjectSetting("cc-email");

        foreach ($instances as $index => $instance) {

            // Create a new reward instance and verify the config
            try {
                $reward = $this->getRewardInstance($index, $this, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance);

                [$result, $message] = $reward->verifyConfig();
                if ($result === false) {
                    $overallStatus = $result;
                    $this->emError("Errors with instance " . ($index + 1), $message);
                    \REDCap::logEvent("Errors with instance " . ($index + 1), $message);
                    $errors[] = '<b>Gift Card config ' . ($index + 1) . ' has error message:</b> ' . $message;
                }
            } catch (Exception $ex) {
                $this->emError("Cannot instantiate the Reward Instance with error: " . json_encode($ex));
                \REDCap::logEvent("Cannot instantiate the Reward Instance with error: ", json_encode($ex));
            }
        }

        return array($overallStatus, $errors);
    }

    /**
     * Retrieve any info we need about the Gift Card Library here.  Right now, if an event id is not
     * specified in the config file, retrieve it.
     *
     * @return BOOL (true if event id is valid otherwise false)
     */
    public function checkGiftCardLibEventId($proj, $event_id)
    {

        // Make sure we have an event_id in the gift card rewards project
        if (!isset($event_id) && ($proj->numEvents === 1)) {

            // If this project has only 1 event id and it wasn't specified, then set it.
            $lib_event_id = array_keys($proj->eventInfo)[0];

        } else if (!isset($event_id)) {

            // library event id is not set and cannot be determined
            $this->emError("Gift Card Library has more than 1 event - select one through the External Module Configuration");
            \REDCap::logEvent("Gift Card Library has more than 1 event - select one through the External Module Configuration");
            $lib_event_id = null;

        } else {
            $lib_event_id = $event_id;
        }

        return $lib_event_id;
    }

    /**
     * This is just a central repository for the gift card library required fields
     *
     * @return array - required fields in the gift card library project
     */
    public function getGiftCardLibraryFields()
    {

        // These are required fields for the gift card library project.  If any of these fields are not
        // present, we cannot continue.  We will give the option to upload a form with these fields.
        $gcr_required_fields = array('reward_id', 'egift_number', 'url', 'amount', 'status',
            'reward_hash', 'reward_name', 'reward_pid', 'reward_record',
            'reserved_ts', 'claimed_ts');

        return $gcr_required_fields;
    }


    /******************************************************************************************************************/
    /* CRON METHODS                                                                                                   */
    /******************************************************************************************************************/
    /**
     * This function will be called by the Cron on a daily basis.  It will call DailySummary.php for each project that has
     * this EM enabled via an API call (so that the project context is setup).
     * @throws GuzzleException
     */
    public function giftCardDisplaySummaryCron()
    {

        // Find all the projects that are using the Gift Card Rewards EM
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        while ($row = $enabled->fetch_assoc()) {

            $proj_id = $row['project_id'];

            // Create the API URL to this project
            $dailySummaryURL = $this->getUrl('src/DailySummary.php?pid=' . $proj_id, true, true);
            $this->emDebug("Calling cron Daily Summary for project $proj_id at URL " . $dailySummaryURL);

            // Call the project through the API so it will be in project context
            $response = $this->getGuzzleClient()->get($dailySummaryURL);
            $this->emDebug("Completed Daily Summary for project $proj_id");
        }
    }

    /**
     * This function will be called by the Cron on a daily basis at 9am.  It will retrieve the list of projects
     * that have selected the 'Enable Logic Check through Cron' checkbox in the gift card project configuration. For each
     * project, each record, that has not already sent out a gift card, will be checked to see if they are eligible.  If
     * the record is eligible, a gift card will be dispersed to them.
     * @throws GuzzleException
     */
    public function giftCardLogicCheck()
    {

        $this->emDebug("Starting Gift Card Logic Cron");

        // Find all the projects that are using the Gift Card Rewards EM
        $enabled = ExternalModules::getEnabledProjects($this->PREFIX);

        //while($row = db_fetch_assoc($enabled)) {
        while ($row = $enabled->fetch_assoc()) {

            // Loop over each project where gift card is enabled
            $proj_id = $row['project_id'];

            // Create the API URL to this project.
            $processCronURL = $this->getUrl('src/ProcessCron.php?pid=' . $proj_id, true, true);
            $this->emDebug("Calling cron ProcessCron for project $proj_id at URL " . $processCronURL);

            // Call the project through the API so it will be in project context.
            $response = $this->getGuzzleClient()->get($processCronURL);
            $this->emDebug("Completed ProcessCron for project $proj_id");
        }


    }

    public function getVerifyLibraryClass($gcr_pid, $gcr_event_id, $module): VerifyLibraryClass
    {
        if (!$this->verifyLibraryClass) {
            $this->setVerifyLibraryClass($gcr_pid, $gcr_event_id, $module);
        }
        return $this->verifyLibraryClass;
    }

    public function setVerifyLibraryClass($gcr_pid, $gcr_event_id, $module): void
    {
        $this->verifyLibraryClass = new VerifyLibraryClass($gcr_pid, $gcr_event_id, $module);
    }

    public function getRewardInstance($index, $module, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance): RewardInstance
    {
        if (is_null($index) or !$this->rewardInstance[$index]) {
            $this->setRewardInstance($index, $module, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance);
        }
        // if no index provided then RewardInstance was added to the end of the method. otherwise return index object.
        if (is_null($index)) {
            return end($this->rewardInstance);
        } else {
            return $this->rewardInstance[$index];
        }
    }

    public function setRewardInstance($index, $module, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance): void
    {
        if (is_null($index)) {
            $this->rewardInstance[] = new RewardInstance($module, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance);
        } else {
            $this->rewardInstance[$index] = new RewardInstance($module, $project_id, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance);
        }
    }

    public function getGuzzleClient(): \GuzzleHttp\Client
    {
        if (!$this->guzzleClient) {
            $this->setGuzzleClient(new Client());
        }
        return $this->guzzleClient;
    }

    public function setGuzzleClient(\GuzzleHttp\Client $guzzleClient): void
    {
        $this->guzzleClient = $guzzleClient;
    }


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
    public function retrieveSummaryData($config, $gcr_pid, $gcr_event_id)
    {

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

        $data = \REDCap::getData($gcr_pid, 'array', null, null, $gcr_event_id, null, null, null, null, $filter);
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
            $this->emError("No data was found for DailySummary from gc library [pid:$gcr_pid/event id:$gcr_event_id]");
            \REDCap::logEvent("No data was found for DailySummary from gc library [pid:$gcr_pid/event id:$gcr_event_id]");
        }

        $results = array(
            "sent_yesterday" => $rewards_sent_yesterday,
            "claimed_yesterday" => $rewards_claimed_yesterday,
            "sent_gt7days_ago" => $num_gc_sent_more_than_7days_ago,
            "sent_lt7days_ago" => $num_gc_send_less_than_7days_ago,
            "not_ready" => $num_gc_notready,
            "num_available" => $num_gc_available,
            "num_awarded" => $num_gc_awarded,
            "num_claimed" => $num_gc_claimed,
            "brand" => $brand_type
        );

        return $results;
    }

}