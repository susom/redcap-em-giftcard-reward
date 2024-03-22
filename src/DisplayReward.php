<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

use Piping;
use REDCap;
use Project;
use Exception;

$gcToken = isset($_GET['reward_token']) && !empty($_GET['reward_token']) ? filter_var($_GET['reward_token'], FILTER_SANITIZE_STRING) : null;
$pid = isset($_GET['pid']) && !empty($_GET['pid']) ? filter_var($_GET['pid'], FILTER_SANITIZE_NUMBER_INT) : null;
$action = isset($_GET['action']) && !empty($_GET['action']) ? filter_var($_GET['action'], FILTER_SANITIZE_STRING) : null;
$emailAddr = isset($_GET['e_addr']) && !empty($_GET['e_addr']) ? filter_var($_GET['e_addr'], FILTER_SANITIZE_EMAIL) : null;
$claimed = "Claimed";

$module->emDebug("Token: " . $gcToken . ", pid: " . $pid . ", action: " . $action . ", email addr: " . $emailAddr);

/*
 * This page is called from a link sent to the gift card recipients in email. The status in the gift card library
 * designated this reward as reserved until the recipient comes here. The gift card library project record status
 * is then changed from Reserved to Claimed and a timestamp is saved.
 *
 * The information needed to redeem a gift card is displayed to the recipient and an email is sent with the same
 * information.
 */

$gcConfig = array();
$gclRecordId = '';
$gclRecord = array();
$gclPid = '';
$gclEventId = '';
$ccEmailAddr = '';
$projRecordId = '';
$rewardEmailAddr = '';
$setupComplete = false;
$dont_send_email = 0;
$logo = '';
$headerFooterColor = '';
$backgroundImage = '';
$tableButton = '';

/**
 * If there is an action tag included in the post with sendEmail, send an email with the reward to the
 * email address that is entered.
 */

if ($action === "sendEmail") {

    $status = sendRewardEmail($pid, $gcToken, $emailAddr);
    if (!$status) {
        $module->emError("Error encountered when trying to send email with reward information for request pid $pid, token $gcToken ");
        \REDCap::logEvent("Error encountered when trying to send email with reward information for request pid $pid, token $gcToken ");
    }

    // Send back the status so we know if an email was sent or not
    print $status;
    return;

} else {

    // Process the token to find the gift card record so we can send the recipient their reward
    $status = displayGCAndUpdateProjects($pid, $gcToken);
    if (!$status) {
        $module->emError("Error encountered when processing reward token for request pid $pid, token $gcToken ");
        \REDCap::logEvent("Error encountered when processing reward token for request pid $pid, token $gcToken ");
    }
}


/**
 * @return string - message with reward values for recipient
 */
function giftCardDisplay() {
    global $setupComplete, $pid, $gcToken;

    $rewardInfo = null;

    // make sure we've run through the setup otherwise our message won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    // If we can't complete the setup, we can't find the reward.
    if ($setupComplete === true) {
        $rewardInfo = getGiftCardSummary();
    } else {
        $rewardInfo = null;
    }

    return $rewardInfo;
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

    if ($setupComplete === true) {
        if (empty($gcConfig) || empty($gcConfig['reward-email-subject'])) {
            $subject = "<h4>Here is your reward</h4>";
        } else {
            $emailSubject = $gcConfig['reward-email-subject'];
            $subject = Piping::replaceVariablesInLabel($emailSubject, $projRecordId, $projEventId, array(), false, null, false);
        }
    } else {
        $subject = "<h4>Problem locating your reward. Please contact your Project Administrators</h4>";
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

    if ($setupComplete === true) {
        if (empty($gcConfig) || empty($gcConfig['reward-email-header'])) {
            $rewardHeader = "Thank you for participating!";
        } else {
            $header = $gcConfig['reward-email-header'];
            $rewardHeader = Piping::replaceVariablesInLabel($header, $projRecordId, $projEventId, array(), false, null, false);
        }
    } else {
        $rewardHeader = null;
    }
    return $rewardHeader;
}

/**
 * @return string|null
 */
function getEmailAddress() {
    global $setupComplete, $pid, $gcToken, $rewardEmailAddr;

    // Make sure we run through the setup, otherwise our config won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    // Retrieve the email address of the person receiving the reward to initialize the email field on the display
    if ($setupComplete === false) {
        return null;
    } else {
        return $rewardEmailAddr;
    }
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
    $module->emDebug("This is the GC amount: " . $amount);

    // Create the message that will be shown to recipients
    $message = $module->tt("gift_card", $amount, $brand, $rewardName) . "<br><br>";
    $message .= $module->tt("redeem_code", $reward) . "<br>";
    if (!empty($code)) {
        $message .= $module->tt("challenge_code", $code) . "<br>";
    }
    $module->emDebug($message);

    return $message;
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


function displayGCAndUpdateProjects($pid, $gcToken) {
    global $module, $setupComplete, $gcConfig, $gclEventId, $gclPid, $gclRecordId,
           $projRecordId, $claimed, $email_eventID, $dont_send_email, $logo, $headerFooterColor, $tableButton, $backgroundImage;

    $DEFAULTHEADERCOLOR = '#FFFFFF';
    $DEFAULTTABLECOLOR = '#DAD7CB';

    $status = true;
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
        if ($setupComplete === false) {
            return false;
        }
    }

    // Save, in the gift card library, the fact that we have shown them their reward
    $saveData['status'] = 3;  // GC Claimed
    $saveData['claimed_ts'] = date("Y-m-d H:i:s");
    $saveReward[$gclRecordId][$gclEventId] = $saveData;
    $returnStatus = REDCap::saveData($gclPid, 'array', $saveReward);
    if (empty($returnStatus['ids']) || !empty($returnStatus['errors'])) {
        $module->emError("Problem saving Gift Card library status for record $gclRecordId in project $gclPid and status $claimed");
        \REDCap::logEvent("Problem saving Gift Card library status for record $gclRecordId in project $gclPid and status $claimed");
        $status = false;
    } else {
        $module->emDebug("Successfully updated gift card library pid $gclPid record $gclRecordId with status $claimed");
    }

    // Retrieve the value of the config parameter on whether or not we allow sending email with the reward codes
    $dont_send_email = $gcConfig['dont-send-email'];

    // Retrieve styling information
    $hf_color = $module->getProjectSetting('gcr-display-header-footer-color');
    $tbl_color = $module->getProjectSetting('gcr-display-table-color');
    $headerFooterColor = empty($hf_color) ? $DEFAULTHEADERCOLOR : $hf_color;
    $tableButton = empty($tbl_color) ? $DEFAULTTABLECOLOR : $tbl_color;
    $logo = $module->getProjectSetting('gcr-display-logo');
    $backgroundImage = $module->getProjectSetting('gcr-display-background-image');

    // Update the project record to tell them they have viewed their reward
    $projEventId = $gcConfig['reward-fk-event-id'];
    $projStatusField = $gcConfig['reward-status'];
    $projData[$projRecordId][$projEventId][$projStatusField] = $claimed;
    $returnStatus = REDCap::saveData($pid, 'array', $projData);
    if (empty($returnStatus['ids']) || !empty($returnStatus['errors'])) {
        $module->emError("Problem saving Gift Card project status for record $projRecordId in project $pid and status $claimed");
        \REDCap::logEvent("Problem saving Gift Card project status for record $projRecordId in project $pid and status $claimed");
        $status = false;
    } else {
        $module->emDebug("Successfully update gift card project pid $pid record $projRecordId with status $claimed");
    }

    return $status;
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

    global $module, $gcConfig, $gclRecord, $setupComplete, $gclPid, $gclEventId, $gclRecordId, $projRecordId, $ccEmailAddr, $rewardEmailAddr;

    $setupComplete = true;

    // If no token was given, we can't find a reward
    if (empty($gcToken)) {
        $module->emError("ERROR: Empty gift card token $gcToken");
        \REDCap::logEvent("ERROR: Empty gift card token $gcToken");
        $setupComplete = false;
    } else {

        // First find the gift card library pid from the gift card config
        $gclPid = $module->getProjectSetting('gcr-pid', $pid);
        $gclEventId = $module->getProjectSetting('gcr-event-id', $pid);
        $ccEmailAddr = $module->getProjectSetting('cc-email', $pid);

        $gclRecord = findGiftCardLibraryRecord($pid, $gclPid, $gcToken);
        if (empty($gclRecord)) {
            $module->emError("Gift card token $gcToken was not found in GC Library project $gclPid");
            \REDCap::logEvent("Gift card token $gcToken was not found in GC Library project $gclPid");
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
                \REDCap::logEvent("Cannot find Gift Card Configuration titled " . json_encode($gclRecord['reward_name']));
                $setupComplete = false;
            } else {
                // Now that we have the configuration, we can find the GC Project record to get the email address
                $rewardEmailAddr = getProjectEmail($pid, $projRecordId);
            }
        }
    }
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
    $filter = "[reward_hash]='" . $gcToken . "' and [reward_pid]='" . $pid . "'";
    $gclData = REDCap::getData($gcrPid, 'array', null, null, $gclEventId, null, null, null, null, $filter);

    if (empty($gclData)) {
        $module->emError("Could not find record with token $gcToken in project $gcrPid");
        \REDCap::logEvent("Could not find record with token $gcToken in project $gcrPid");
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
 * @param $record_id
 * @return array - gift card project record
 */

function getProjectEmail($pid, $record_id) {

    global $module, $gcConfig, $Proj;

    $fieldWithEmail = $gcConfig['reward-email'];

    // This is silly but you can't tell from REDCap data which event has real email address information.
    // We need to look at the data dictionary for the event that holds the email address.
    $metadata = $Proj->metadata;
    $email_form = $metadata[$gcConfig['reward-email']]['form_name'];
    $email_eventID = null;
    foreach($Proj->eventsForms as $eventId => $eventForms) {
        if (in_array($email_form, $eventForms)) {
            $email_eventID = $eventId;
        }
    }
    if (empty($email_eventID)) {
        $module->emError("Cannot find the event ID where the Email address resides: " . $gcConfig['reward-email']);
        \REDCap::logEvent("Cannot find the event ID where the Email address resides: " . $gcConfig['reward-email']);
        return null;
    } else {
        $emailData = REDCap::getData($pid, 'array', array($record_id), array($fieldWithEmail), array($email_eventID));
        $rewardEmailAddr = $emailData[$record_id][$email_eventID][$fieldWithEmail];
        return $rewardEmailAddr;
    }
}


/**
 * @return mixed|null
 */
function setToken() {
    global $gcToken;

    return $gcToken;
}

/**
 * This function will send the email with the reward information.
 *
 * @return bool - true - email was successfully sent, otherwise false
 */

function sendRewardEmail($pid, $gcToken, $emailAddress) {

    global $module, $setupComplete, $gcConfig, $projRecordId, $ccEmailAddr, $gclPid, $gclEventId, $gclRecordId;

    $status = true;

    // Make sure we run through the setup, otherwise our config won't be set
    if ($setupComplete === false) {
        findGiftCardData($pid, $gcToken);
    }

    if ($setupComplete === false) {
        $module->emError("Cannot send email to " . $emailAddress . ", because token " . $gcToken . " cannot be found.");
        \REDCap::logEvent("Cannot send email to " . $emailAddress . ", because token " . $gcToken . " cannot be found.");
        return false;
    } else {

        // Find the fields that holds the email setup
        $fromEmail = $gcConfig["reward-email-from"];
        $ccRewardEmail = $gcConfig["cc-reward-email"];

        // Check if we should CC the cc email address and if so, set the email address
        $ccRewardEmailAddr = null;
        if ($ccRewardEmail == 'true') {
            $ccRewardEmailAddr = $ccEmailAddr;
        }

        // The subject and header fields might have some piped data. Convert those piped values to record values
        $subject = getGiftCardSubject();
        $body = getGiftCardHeader() . "<br>" . giftCardDisplay();

        // Send the email to the address specified on the webpage.
        $status = REDCap::email($emailAddress, $fromEmail, $subject, $body, $ccRewardEmailAddr);
        $module->emDebug("Rewards email: To $emailAddress, From: $fromEmail, Subject: $subject, Body: $body, CC: $ccRewardEmailAddr, status: $status");

        // If the email was successfully sent, see if the library project has an email field so we can save the email address where we sent the reward.
        //  If so, save the email address in the library project, otherwise log the email.
        $gcProjDD = null;
        try {
            $gcProjDD = new Project($gclPid, true);
        } catch (Exception $ex) {
            $module->emError("Cannot access Project variables for Gift Card Library project pid=" . $gclPid . ", Error: " . $ex->getMessage());
            \REDCap::logEvent("Cannot access Project variables for Gift Card Library project pid=" . $gclPid ," Error: " . $ex->getMessage());
            return $status;
        }

        if (empty($gcProjDD) || empty($gcProjDD->metadata)) {
            $module->emError("No project data dictionary for pid=" . $gclPid);
            \REDCap::logEvent("No project data dictionary for pid=" . $gclPid);
        } else {

            // If the field reward_email_addr exists, save the email address where we sent the reward email message
            // This is a new field option so older projects may not have this field defined
            if (!empty($gcProjDD->metadata['reward_email_addr'])) {
                $gclEventId = $module->getProjectSetting('gcr-event-id', $pid);
                $data[$gclRecordId][$gclEventId]['reward_email_addr'] = $emailAddress;
                $save_status = REDCap::saveData($gclPid, 'array', $data);
            } else {
                $module->emDebug("Project $gclPid does not have field 'reward_email_addr' so we could not save the email address $emailAddress");
            }
        }
    }

    return $status;
}


?>

<!DOCTYPE html>
<html lang="en">
    <header>
        <title>Gift Card Reward Display</title>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=yes">
        <link rel="stylesheet" href="<?php echo $module->getUrl("./css/DisplayReward.css",true,true); ?>" />
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
        <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />

        <script src="https://ajax.googleapis.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
        <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
        <style>
            html {background-color: <?php echo $headerFooterColor; ?>}
            .logo {background-color: <?php echo $headerFooterColor; ?>}
            body {background-image: url(<?php echo $backgroundImage; ?>)}
            .p-2 {background-color: <?php echo $tableButton; ?>}
            .header{background-color: blue}
        </style>
        <div class="logo">
            <img id='logo' src="<?php echo $logo; ?>">
        </div>

    </header>
    <body>

        <div class="container">

            <div class="row mt-5">
                <div class="col-3">
                </div>

                <div class="col-6 pt-5">

                    <div class="border border-2">
                    <div class="col-12 text-light bg-dark p-3">
                        <?php echo getGiftCardSubject(); ?>
                    </div>
                    <div class="col-12 p-2 border">
                        <?php echo getGiftCardHeader(); ?>
                    </div>
                    <div class="col-12 p-2">
                        <?php echo giftCardDisplay(); ?>
                    </div>

                    <?php if (!$dont_send_email) : ?>
                    <div class="col-12 p-2 border">
                        <form style="margin-top: 10px; font-size: small;">
                            <input id="token" style="display:none" value="<?php echo setToken(); ?>">

                            <div><?php echo $module->tt("send_codes_in_email", "Send"); ?></div>
                            <label><b><?php echo $module->tt("email_addr"); ?></b></label><input id="emailAddress" style="width: 250px; margin: 10px 10px" value="<?php echo getEmailAddress(); ?>">
                            <input id="button" type="button" value="<?php echo $module->tt("email_send_button"); ?>" onclick="sendReward()"><br>

                            <div id="invalidAddr" style="display:none;color:red">
                                ***   This is not a valid email address  ***
                            </div>
                            <div id="sent" style="display:none;color:red">
                                ***   An email has been sent   ***
                            </div>
                            <div id="notsent" style="display:none;color:red">
                                ***   Email was not sent, please try again.  ***
                            </div>
                        </form>
                    </div>
                    <?php endif; ?>
                    </div>
                </div>   <! -- end column  -->

                <div class="col-3">
                </div>

            </div>   <!-- end row -->
        </div>  <!-- end container -->
        <div class="loader"><!-- Place at bottom of page --></div>
    </body>
</html>


<script>

    function sendReward() {

        // Make sure all the hints are hidden
        document.getElementById("invalidAddr").style.display = "none";
        document.getElementById("sent").style.display = "none";
        document.getElementById("notsent").style.display = "none";

        // Retrieve the email address and token that we've stored for this reward
        var emailAddr = document.getElementById("emailAddress").value;
        var token = document.getElementById("token").value;

        // Check for a valid email address by making sure it has an "@" and "."
        var atsign = emailAddr.indexOf("@");
        var dot = emailAddr.indexOf(".");
        if (atsign === -1 || dot === -1) {
            document.getElementById("invalidAddr").style.display = "inline";
        } else {
            document.getElementById("invalidAddr").style.display = "none";

            // Send an email to the entered address
            GiftcardReward.send_email(emailAddr, token);
        }
    }

    var GiftcardReward = GiftcardReward || {};

    // Make the API call back to the server to send reward email to the entered address
    GiftcardReward.send_email = function(emailAddr, token) {
        $body = $("body");
        $.ajax({
            type: "GET",
            datatype: "html",
            data: {
                "action"        : "sendEmail",
                "e_addr"        : emailAddr,
                "reward_token"  : token
            },
            success:function(status) {
                if (status === '1') {
                    document.getElementById("sent").style.display = "inline";
                } else {
                    document.getElementById("notsent").style.display = "inline";
                }
            },
            beforeSend:function (status) {
                $body.addClass("loading");
                console.log("Before Send: " + status);
            }
        }).done(function (status) {
            $body.removeClass("loading");
            console.log("Return from GiftcardReward: " + status);
        }).fail(function (jqXHR, textStatus, errorThrown) {
            $body.removeClass("loading");
            console.log("Failed in GiftcardReward");
        });
    };


</script>
