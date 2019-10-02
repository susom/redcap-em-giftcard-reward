<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

use Piping;
use REDCap;

$gcToken = isset($_GET['reward_token']) && !empty($_GET['reward_token']) ? $_GET['reward_token'] : null;
$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? $_GET['pid'] : null;
$claimed = "Claimed";

/*
 * This page is called from a link sent to the gift card recipients in email. The status in the gift card library
 * designated this reward as reserved until the recipient comes here. The gift card library project record status
 * is then changed from Reserved to Claimed and a timestamp is saved.
 *
 * The information needed to redeem a gift card is displayed to the recipient and an email is sent with the same
 * information.
 */

$module->emDebug("Processing request for token $gcToken for project $pid");

$gcConfig = array();
$gclRecord = array();
$projRecord = array();
$projRecordId = '';
$gclPid = '';
$gclRecordId = '';
$gclEventId = '';
$message = '';
$setupComplete = false;

/**
 * Process the token to find the gift card record so we can send the recipient their reward
 */
$status = sendEmailAndUpdateProjects($pid, $gcToken);
if (!$status) {
    $module->emError("Processing error for request pid $pid, token $gcToken " . $message);
}

/**
 * @return string - message with reward values for recipient
 */
function giftCardDisplay() {
    global $message, $setupComplete, $pid, $gcToken;

    // make sure we've run through the setup otherwise our message won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    if (empty($message)) {
        $message = "We are unable to locate your gift card reward.";
    }

    return $message;
}

/**
 * @return string - subject value set in project setup with all piping converted to record values
 */
function getGiftCardSubject() {
    global $subject, $setupComplete, $pid, $gcToken, $gcConfig, $projRecordId, $projEventId;

    // Make sure we run through the setup, otherwise our gift card config won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    if (empty($gcConfig) || empty($gcConfig['reward-email-subject'])) {
        $subject = "<h4>Here is your reward</h4>";
    } else {
        $emailSubject = $gcConfig['reward-email-subject'];
        $subject = Piping::replaceVariablesInLabel($emailSubject, $projRecordId, $projEventId, array(), false, null, false);

    }
    return $subject;
}

/**
 * @return string - header value set in project setup with all piping converted to record values
 */

function getGiftCardHeader() {
    global $rewardHeader, $setupComplete, $pid, $gcToken, $gcConfig, $projRecordId, $projEventId;

    // Make sure we run through the setup, otherwise our config won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    if (empty($gcConfig) || empty($gcConfig['reward-email-header'])) {
        $rewardHeader = "Thank you for participating!";
    } else {
        $header = $gcConfig['reward-email-header'];
        $rewardHeader = Piping::replaceVariablesInLabel($header, $projRecordId, $projEventId, array(), false, null, false);
    }
    return $rewardHeader;
}

/**
 * This function sets up processing of the reward token.  The token is found in the gift card library.  Once the title for the
 * reward is found, the gift card configuration is found. Once the configuration is found, the gift card project record is found.
 * All data needed for the reward to be sent will be initialized.
 *
 * @param $pid
 * @param $gcToken
 */
function findGiftCardData($pid, $gcToken) {

    global $module, $gcConfig, $gclRecord, $projRecord, $setupComplete, $gclPid, $gclEventId, $gclRecordId, $message, $projRecordId;

    $setupComplete = true;

    // If no token was given, we can't find a reward
    if (empty($gcToken)) {
        $module->emError("ERROR: Empty gift card token $gcToken");
        $setupComplete = false;
    } else {

        // First find the gift card library pid from the gift card config
        $gclPid = $module->getProjectSetting('gcr-pid', $pid);
        $gclEventId = $module->getProjectSetting('gcr-event-id', $pid);

        $gclRecord = findGiftCardLibraryRecord($pid, $gclPid, $gcToken);
        if (empty($gclRecord)) {
            $module->emError("Gift card token $gcToken was not found in GC Library project $gclPid");
            $setupComplete = false;
        } else {

            // Retrieve configuration for this reward name so we can get the email parameters
            $rewardName = $gclRecord[$gclRecordId][$gclEventId]['reward_name'];
            $projRecordId = $gclRecord[$gclRecordId][$gclEventId]['reward_record'];
            $module->emDebug("This is the reward name: " .$rewardName . ", and project record id: " .$projRecordId);
            $gcConfig = getGiftCardConfig($rewardName);
            $module->emDebug("This is the config which holds this token: " . json_encode($gcConfig));
            if (empty($gcConfig)) {
                $module->emError("Cannot find Gift Card Configuration titled " . json_encode($gclRecord['reward_name']));
                $setupComplete = false;
            } else {

                // Now that we have the configuration, we can find the GC Project record to get the email address
                $projRecord = getProjectRecord($pid, $projRecordId);
                $module->emDebug("This is the project Record: " . json_encode($projRecord));
                if (empty($projRecord)) {
                    $module->emError("Cannot retrieve gift card project record " . $gclRecord['reward_record']);
                    $setupComplete = false;
                }
            }
        }
    }

    if ($setupComplete) {
        $message = getGiftCardSummary();
    }
}

/**
 * This function will email the participant their email reward and also display it on the webpage. Also the
 * gift card library and gift card project will both be updated to indicate the participant has viewed their
 * reward.
 *
 * @param $pid
 * @param $gcToken
 * @return bool - true if successful, otherwise false
 */
function sendEmailAndUpdateProjects($pid, $gcToken) {
    global $module, $setupComplete, $gcConfig, $gclEventId, $gclPid, $gclRecordId, $projRecordId, $claimed;

    $status = true;
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
        if ($setupComplete === false) {
            return false;
        }
    }

    // Retrieve gift card email address since it may or may not be in the same event as the gift card fields in the project
    $data = REDCap::getData($pid, 'array', $projRecordId, array($gcConfig['reward-email']));
    $module->emDebug("To find email address: " . json_encode($data));
    $email_address_eventID = array_keys($data[$projRecordId])[0];
    $module->emDebug("Email event: " . $email_address_eventID);
    $email_address = $data[$projRecordId][$email_address_eventID]['reward_email'];
    $module->emDebug("This is the email address: " . $email_address);

    // Send the email with the above information
    $status = sendRewardEmail($email_address);
    if ($status) {
        // Save the fact that we have shown them their reward in the gift card libary
        $saveData['status'] = 3;  // GC Claimed
        $saveData['claimed_ts'] = date("Y-m-d H:i:s");
        $saveReward[$gclRecordId][$gclEventId] = $saveData;
        $returnStatus = REDCap::saveData($gclPid, 'array', $saveReward);
        if (empty($returnStatus['ids']) || !empty($returnStatus['errors'])) {
            $module->emError("Problem saving Gift Card project status for record $gclRecordId in project $gclPid and status $claimed");
            $status = false;
        } else {
            $module->emDebug("Successfully updated gift card project pid $gclPid record $gclRecordId with status $claimed");
        }

        // Update the project record to tell them they have viewed their reward
        $projEventId = $gcConfig['reward-fk-event-id'];
        $projStatusField = $gcConfig['reward-status'];
        $projData[$projRecordId][$projEventId][$projStatusField] = $claimed;
        $returnStatus = REDCap::saveData($pid, 'array', $projData);
        if (empty($returnStatus['ids']) || !empty($returnStatus['errors'])) {
            $module->emError("Problem saving Gift Card project status for record $projRecordId in project $pid and status $claimed");
            $status = false;
        } else {
            $module->emDebug("Successfully update gift card project pid $pid record $projRecordId with status $claimed");
        }
    }

    return $status;
}

/**
 * This function will put together the message of the reward to be sent in email and displayed on the webpage.
 *
 * @return string - reward message
 */
function getGiftCardSummary() {

    global $module, $gclRecord, $gclRecordId, $gclEventId;

    if (empty($gclRecord)) {
        return "Empty token - cannot process reward";
    }

    // We now have the record information that belongs to the token so we know who to send the reward to
    $record = $gclRecord[$gclRecordId][$gclEventId];

    $rewardName = $record['reward_name'];
    $reward = $record["egift_number"];
    $code = $record['challenge_code'];
    $brand = $record['brand'];
    $amount = $record['amount'];

    // Create the message that will be shown to recipients
    $message = "$" . $amount . " " . $brand . " gift card for your " . $rewardName. " reward.<br><br>";
    $message .= "Your gift card number is <b>" . $reward . "</b><br>";
    if (!empty($code)) {
        $message .= "The challenge code is <b>" . $code . "</b><br>";
    }
    $module->emDebug($message);

    return $message;
}

/**
 * This function will use the token to find the gift card record associated with the reward.
 *
 * @param $pid
 * @param $gcrPid
 * @param $gcToken
 * @return array - gift card library record
 */
function findGiftCardLibraryRecord($pid, $gcrPid, $gcToken) {

    global $module, $gclRecordId, $gclEventId;

    // Find the gift card library record with the token (hash)
    $filter = "[reward_hash]='" . $gcToken . "' and [reward_pid]='" . $pid . "'";$module->emDebug("Filter for Library: " . $filter);
    $module->emDebug("Filter for gc library: " . $filter);
    $gclData = REDCap::getData($gcrPid, 'array', null, null, $gclEventId, null, null, null, null, $filter);
    $module->emDebug("Reward library data: " . json_encode($gclData));
    if (empty($gclData)) {
        $module->emError("Could not find record with token $gcToken in project $gcrPid");
    } else {
        $gclRecordId = array_keys($gclData)[0];
        if (empty($gclEventId)) {
            $gclEventId = array_keys($gclData[$gclRecordId])[0];
        }
    }

    return $gclData;
}

/**
 * This function will use the gift card reward name to find the configuration associated with this reward.
 *
 * @param $rewardName
 * @return array - gift card configuration
 */
function getGiftCardConfig($rewardName) {

    global $module;

    // Retrieve the gift card configuration so we get the email parameters
    $instances = $module->getSubSettings('rewards');
    $rewardConfig = null;
    foreach ($instances as $instanceNum => $instanceInfo) {
        $title = $instanceInfo["reward-title"];
        if ($rewardName == $title) {
            $gcInstance = $instanceInfo;
            break;
        }
    }

    return $gcInstance;
}

/**
 * This function will retrieve the gift card project record based on the record number
 * saved in the gift card library project.
 *
 * @param $pid
 * @return array - gift card project record
 */
function getProjectRecord($pid, $record_id) {

    global $module, $gcConfig;

    $eventId = $gcConfig['reward-fk-event-id'];

    // Retrieve the record so we know who to send this info to
    $record = REDCap::getData($pid, 'array', array($record_id), null, array($eventId));
    $module->emDebug("Project record: " . json_encode($record));

    return $record;
}

/**
 * This function will send the email with the reward information.
 *
 * @return bool - true - email was successfully sent, otherwise false
 */
function sendRewardEmail($toEmail) {

    global $module, $projRecord, $message, $gcConfig, $projRecordId;

    $rewardRecord = array();
    foreach ($projRecord as $record_id => $info) {
        foreach ($info as $event_id => $thisRecord) {
            $rewardRecord = $thisRecord;
            $projEventId = $event_id;
        }
    }

    // Find the fields that holds the email setup
    $fromEmail = $gcConfig["reward-email-from"];
    $subjectBefore = $gcConfig["reward-email-subject"];
    $headerBefore = $gcConfig["reward-email-header"];

    // The subject and header fields might have some piped data. Convert those piped values to record values
    $subject = Piping::replaceVariablesInLabel($subjectBefore, $projRecordId, $projEventId, array(), false, null, false);
    if (empty($headerBefore)) {
        $body = $message;
    } else {
        $header = Piping::replaceVariablesInLabel($headerBefore, $projRecordId, $projEventId, array(), false, null, false);
        $body = $header . "<br>" . $message;
    }


    $status = REDCap::email($toEmail, $fromEmail, $subject, $body);
    $module->emDebug("Rewards email: To $toEmail, From: $fromEmail, Subject: $subject, Body: $body");

    return $status;
}


?>

<!DOCTYPE html>
<html lang="en">
    <header>
        <title>Gift Card Reward Display</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">

        <link rel="stylesheet" href="https://stackpath.bootstrapcdn.com/bootstrap/4.3.1/css/bootstrap.min.css" integrity="sha384-ggOyR0iXCbMQv3Xipma34MD+dH/1fQ784/j6cY/iJTQUOhcWr7x9JvoRxT2MZw1T" crossorigin="anonymous">
        <style type="text/css">
            table {max-height: 250px; margin-top:100px; text-align: center;}
        </style>

    </header>

    <body>
        <div class="container">
            <div class="row">

                <div class="col-3">
                </div>

                <div class="col-6">
                    <table id="giftcard" class="table table-bordered">
                        <thead class='thead-dark'>
                            <tr scope='row'>
                                <th>
                                    <?php echo getGiftCardSubject(); ?>
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr scope='row'>
                                <td>
                                    <?php echo getGiftCardHeader(); ?>
                                </td>
                            </tr>
                            <tr scope='row'>
                                <td>
                                    <?php echo giftCardDisplay(); ?>
                                </td>
                            </tr>
                            <tr scope='row'>
                                <td>
                                    <i style="font-size:small">*An email with these details have been sent you.</i>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>  <!-- end row -->
        </div>  <!-- end container -->
    </body>

</html>



