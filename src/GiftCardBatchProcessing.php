<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

require_once $module->getModulePath() .  "src/BatchProcessingInstance.php";

use \Exception;
use \REDCap;
use \UserRights;

$pid     = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$action  = isset($_POST['action']) && !empty($_POST['action']) ? $_POST['action'] : null;
$records = isset($_POST['records']) && !empty($_POST['records']) ? $_POST['records'] : null;

$stylesheet = $module->getUrl('config/batch.css');


// Retrieve all the gift card configurations for this project
$configs = $module->getSubSettings("rewards");

if ($action == "process") {

    // Retrieve the reward library that holds the gift cards
    $gcr_pid = $module->getProjectSetting("gcr-pid");
    $gcr_event_id = $module->getProjectSetting("gcr-event-id");
    $alert_email = $module->getProjectSetting("alert-email");
    $cc_email = $module->getProjectSetting("cc-email");

    // Save each record so the gift cards will be processed
    $post_values = $_POST;
    unset($post_values['action']);
    foreach($post_values as $config => $records) {

        $selected_config = array();
        foreach($configs as $one_config) {
            $label_nospace = str_replace(' ', '-', $one_config['reward-title']);
            $label_name = $label_nospace . '^' . 'records';
            if ($config == $label_name) {
                $selected_config = $one_config;
                break;
            }
        }

        // Process this config for the selected records to send out the gift cards
        try {
            $batch_message = '';
            $GCInstance = new RewardInstance($module, $pid, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $selected_config);
            $GCInstance->verifyConfig();

            foreach ($records as $record) {

                // Make sure this record still needs to be processed and if so, process it
                $status = $GCInstance->checkRewardStatus($record);
                if ($status) {
                    list($rewardSent, $message) = $GCInstance->processReward($record);
                    if (empty($message)) {
                        $batch_message .= '<br>Sucessfully sent reward to record ' . $record;
                    } else {
                        $batch_message .= '<br>' . $message;
                    }
                } else {
                    $module->emDebug("Record $record is not due for reward " . $selected_config['reward-title']);
                }
            }

            // Tell the user that the gift cards were successfully processed
            $module->emDebug("Result of batch processing: " . $batch_message);

        } catch (Exception $ex) {
            $module->emError("Exception when sending batch GC for project $pid");
        }

    }

}


$finalHtml = '';
foreach ($configs as $configNum => $config) {

    // Loop over each config and see if the config is using the batch processing feature
    $configHtml = '';
    if (!empty($config['batch-processing'])) {

        // Look for records that are ready for gift cards using the logic in the configuration
        $batchProc = new BatchProcessingInstance($pid, $config);
        $configHtml = $batchProc->returnConfigHtml();
    }
    $finalHtml .= $configHtml;
}


require_once $module->getModulePath() . "config/batch.php";