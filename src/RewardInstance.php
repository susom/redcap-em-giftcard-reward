<?php
namespace Stanford\GiftcardReward;

use Piping;
use \Project;
use \REDCap;
use \Message;
use \Exception;

class RewardInstance
{
    /** @var \Stanford\GiftcardReward\GiftcardReward $module */
    private $module, $project_id, $Proj;

    private $title, $logic, $fk_field, $fk_event_id, $gc_status, $amount,
        $email, $email_subject, $email_header, $email_verification,
        $email_verification_subject, $email_verification_header,
        $email_from, $email_address;

    private $gcr_pid, $gcr_event_id, $gcr_pk;

    public function __construct($module, $gcr_pid, $gcr_event_id, $instance)
    {
        // These are Gift Card project parameters
        global $Proj;
        $this->Proj = $Proj;
        $this->Proj->setRepeatingFormsEvents();
        $this->module                       = $module;
        $this->project_id                   = $module->getProjectId();

        // These are required gift card parameters in the gift card project
        $this->title                        = $instance['reward-title'];
        $this->logic                        = $instance['reward-logic'];
        $this->fk_field                     = $instance['reward-fk-field'];
        $this->fk_event_id                  = $instance['reward-fk-event-id'];
        $this->gc_status                    = $instance['reward-status'];
        $this->amount                       = $instance['reward-amount'];

        // These are reward email parameters
        $this->email                        = $instance['reward-email'];
        $this->email_from                   = $instance['reward-email-from'];
        $this->email_subject                = $instance['reward-email-subject'];
        $this->email_header                 = $instance['reward-email-header'];
        $this->email_verification           = $instance['reward-email-verification'];
        $this->email_verification_subject   = $instance['reward-email-verification-subject'];
        $this->email_verification_header    = $instance['reward-email-verification-header'];
        $this->alert_email                  = $instance['alert-email'];

        // There are gift card library parameters
        $this->gcr_pid = $gcr_pid;
        $this->gcr_event_id = $gcr_event_id;
        try {
            $this->getGiftCardLibraryParams();
        } catch (Exception $ex) {
            $this->module->emError("Exception caught initializing RewardInstance for project $this->project_id");
        }
    }


    /**
     * Retrieve any info we need about the Gift Card Library here.  Right now, if an event id is not
     * specified in the config file, retrieve it.  Also, store the primary key for later use.
     *
     * @throws \Exception
     */
    private function getGiftCardLibraryParams() {

        $gcr_proj = new Project($this->gcr_pid);
        $gcr_proj->setRepeatingFormsEvents();

        // Make sure we have an event_id in the gift card rewards project
        if (!isset($this->gcr_event_id) && ($gcr_proj->numEvents === 1)) {
            // If this project has only 1 event id and it wasn't specified, then set it.
            $this->gcr_event_id = array_keys($gcr_proj->eventInfo)[0];
        } else if (!isset($this->gcr_event_id)) {
            $this->module->emError("Gift Card Libary has more than 1 event - select one through the External Module Configuration");
        }

        $this->gcr_pk = $gcr_proj->table_pk;
    }

    /**
     * Verify the gift card project configuration and return an array of ($result, $message)
     * where $result = true/false
     * and $message is an error string that can be displayed on the configuration setup page
     */
    public function verifyConfig() {

        $message = '';
        $metaData = $this->module->getMetadata($this->project_id);

        // First check that required fields are filled in
        $message = $this->checkForEmptyField($this->title, "Project Title", $message);
        $message = $this->checkForEmptyField($this->fk_event_id, "Reward ID Field Event", $message);
        $message = $this->checkForEmptyField($this->logic, "Reward Logic", $message);
        $message = $this->checkForEmptyField($this->fk_field, "Reward ID Field", $message);
        $message = $this->checkForEmptyField($this->gc_status, "Reward ID Status Field", $message);
        $message = $this->checkForEmptyField($this->email, "Reward Email Address Field", $message);
        $message = $this->checkForEmptyField($this->email_from, "Email From Address", $message);
        $message = $this->checkForEmptyField($this->email_subject, "Reward Email Subject", $message);
        $message = $this->checkForEmptyField($this->email_verification_subject, "Verification Email Subject", $message);

        if ($message !== '') {
            $message = "<li>" . $message . "</li>";
        }

        // Check that the forms that the required fields are on are in this event
        $all_forms = array();
        $all_forms[0] = $metaData[$this->fk_field]['form_name'];
        $all_forms[1] = $metaData[$this->gc_status]['form_name'];
        $all_forms[2] = $metaData[$this->email]['form_name'];
        $diff = array_diff($all_forms, $this->Proj->eventsForms[$this->fk_event_id]);
        if (!empty($diff)) {
            $message .= "<li>The project fields are not all in event " . $this->fk_event_id . "</li>";
        }

        // Now check the logic which will determine when to send a gift card -- make sure it is valid
        if (!empty($this->logic)) {
            $valid = $this->checkRewardStatus();
            if ($valid === null) {
                $message .= "<li>Reward logic (" . $this->logic . ") cannot be confirmed to be valid because there are no records to check against.</li>";
            } else if ($valid === false) {
                $message .= "<li>Reward logic is invalid (" . $this->logic . ")</li>";
            }
        } else {
            $message .= "<li>Logic to determine gift card eligibility is empty.</li>";
        }

        if (empty($message)) {
            return array(true, null);
        } else {
            return array(false, $message);
        }
    }

    /**
     * This function will check to see if the field $field is empty and if so, it will add
     * this label to the message to tell the user this is a required field.
     *
     * @param $field - required field for Gift Card Config
     * @param $label - Label of field which is required but empty
     * @param $message - Error message to add this field label to
     * @return string - Concatenated error message
     */
    private function checkForEmptyField($field, $label, $message) {

        $title = "Required fields that are missing: ";
        if (empty($field)) {
            if (empty($message)) {
                $message = $title . $label;
            } else {
                $message .= ", " . $label;
            }
        }

        return $message;
    }

    /**
     * This function checks the logic which determines when it is time to send a reward.  When the
     * configuration is being setup, we don't have a record number to test the logic against so find
     * a record in the project.  If the project does not have any records yet, we can verify the logic
     * is correct.
     *
     * When a record is saved, we check the status of the logic to see if it is time to send the reward.
     * If a reward was already sent (by checking the reward status and gift card id fields) don't
     * send another one - just set the status as false which means it is not time to send a reward
     *
     * @param null $record
     * @return bool null means logic is invalid
     *              false means the logic is valid and evaluates to false
     *              true means the logic is valid and evaluates to true
     */
    public function checkRewardStatus($record=null) {

        $status = null;

        // If a record was not given, find a record to test the logic on
        // This path is used to test out the logic to make sure it is configured correctly
        if (empty($record)) {
            $data = REDCap::getData($this->project_id, 'array', null, array($this->Proj->table_pk), $this->fk_event_id);
            $record = array_keys($data)[0];
            if (empty($record)) {
                $this->module->emError("There are no records to test the gift card logic '" . $this->logic . "' for pid $this->project_id");
            } else {
                $this->module->emDebug("Found record $record to test logic " . $this->logic);
            }
        } else {

            // If a record was given, make sure a reward was not already given
            $data = REDCap::getData($this->project_id, 'array', $record, null, $this->fk_event_id);
            $thisRecord = $data[$record][$this->fk_event_id];
            $this->email_address = $thisRecord[$this->email];

            // If the fk_field field is not blank, a gc has already been issued
            if (!empty($thisRecord[$this->fk_field])) {
                $status = false;
            }
        }

        // Test the logic for this record
        if (($record !== null) && ($status !== false)) {
            $status = REDCap::evaluateLogic($this->logic, $this->project_id, $record);
        }

        return $status;
    }

    /**
     * This record qualifies for a reward so process it.
     *
     * @param $record_id
     * @param $gcr_required_fields
     * @return array
     */
    public function processReward($record_id, $gcr_required_fields) {

        $message = '';
        $valid = false;

        // We found that this user is eligible for an gift card, so find the next award that fits our criteria
        list($found, $reward_record) = $this->findNextAvailableReward($gcr_required_fields);
        if (!$found) {

            // We were not able to find a reward that meets our criteria.
            $message = "No reward was found in project " . $this->gcr_pid . " which meets our criteria for $this->title reward";

            // Send email to the alert email address to notify them there are no longer any gift cards and update record
            $this->sendAlertEmailNoGiftCards($record_id);

        } else {

            // There is a valid reward available so reserve it
            list($valid, $message) = $this->reserveReward($record_id, $reward_record);
        }

        return array($valid, $message);
    }

    /**
     * This function will sent an ALERT email when there are no more gift cards available and
     * someone qualifies for one.
     *
     * @param $record_id
     */
    private function sendAlertEmailNoGiftCards($record_id) {

        // Send alert email that we could not send reward to eligible participant
        $emailTo = $this->alert_email;
        $emailFrom = $this->email_from;
        $emailSubject = "ALERT: No more Gift Cards available for Reward";
        $emailBody = "Record $record_id is eligible for a gift card but there are none available.<br>".
                     " Once there are more available gift cards available, make sure the eligibility logic for ".
                     " record $record_id is still valid and re-save the record.";
        $status = REDCap::email($emailTo, $emailFrom, $emailSubject, $emailBody);
        $this->module->emDebug("No Rewards Available Email - To: $emailTo, From: $emailFrom, Subject: $emailSubject, Body: $emailBody");
        if (!$status) {
            $this->module->emError("Attempted to send email to $emailTo but received error. Gift cards are exhausted - need more");
        }

        // Save the status in the record to show that we were not able to send them a reward
        $saveRecord[$record_id][$this->fk_event_id] = "Unavailable - no gift cards available";
        $saveStatus = REDCap::saveData($this->project_id, 'array', $saveRecord);
        if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
            $this->module->emError("Problem saving status to project $this->project_id record $record_id");
        }
    }

    /**
     * This is currently unused but it can be used if someone wants to know how many rewards in the
     * library meet the criteria for this reward configuration.
     *
     * @param $gcr_required_fields
     * @return int
     */
    public function numberAvailableRewards($gcr_required_fields) {

        // Find the number of available rewards
        $data = $this->retrieveGiftCardRewardsList($gcr_required_fields);
        return count($data);
    }

    /**
     * This function finds the first available reward record from the library
     *
     * @param $gcr_required_fields
     * @return array -
     *          1) true/false - was reward record found
     *          2) if true, reward record
     */
    private function findNextAvailableReward($gcr_required_fields) {

        $data = $this->retrieveGiftCardRewardsList($gcr_required_fields);
        if (empty($data)) {
            return array(false, "No Reward Found");
        } else {
            // We already know that this is not a repeating form/event
            $next_record_id = min(array_keys($data));
            return array(true, $data[$next_record_id][$this->gcr_event_id]);
        }
    }

    /**
     * This function will retrieve all rewards record which haven't been previously claimed and
     * match the dollar amount that was specified in the configuration if specified.
     *
     * @param $gcr_required_fields
     * @return null if no records were found
     *              array of records which fits our criteria
     */
    private function retrieveGiftCardRewardsList($gcr_required_fields) {

        // Look for the next available gift card record which meets our requirements
        $filter = "[status] = '1' and [reserved_ts] = '' and [claimed_ts] = ''";
        if ($this->amount != '') {
            $filter .= " and [amount] = " . $this->amount;
        }

        $data = REDCap::getData($this->gcr_pid, 'array', null, $gcr_required_fields, $this->gcr_event_id,
            null, null, null, null, $filter);

        return $data;
    }

    /**
     * The recipient is eligible for a reward so email them their verification email.
     * The gift card library record is updated so the reward is reserved. Also, the
     * gift card project record is updated to show that a reward was sent to the recipient.
     *
     * @param $record_id
     * @param $reward_record
     * @return array
     */
    private function reserveReward($record_id, $reward_record) {

        $message = '';
        $gcr_record_id = $reward_record[$this->gcr_pk];

        // Create a unique hash for this reward (record/reward)
        $hash = $this->createRewardHash($record_id);

        // Create the URL for this reward. Add on the project and hash
        $url = $this->module->getUrl("src/DisplayReward", TRUE, TRUE);
        $url .= "&pid=" . strval($this->project_id) . "&token=" . $hash;

        // Send the verification email to the recipient
        $status = $this->sendEmailWithLinkToReward($url, $record_id);
        if ($status) {

            // If the email was successfully sent, update the Gift Card Library to reserve this reward
            $reward_record['status'] = '2';   //  ('Reserved')
            $reward_record['reward_name'] = $this->title;
            $reward_record['reward_pid'] = $this->project_id;
            $reward_record['reward_record'] = $record_id;
            $reward_record['reserved_ts'] = date('Y-m-d H:i:s');
            $reward_record['reward_hash'] = $hash;
            $reward_record['url'] = $url;

            // Format the data the way REDCap wants it
            $saveData = array();
            $saveData[$gcr_record_id][$this->gcr_event_id] = $reward_record;
            $saveStatus = REDCap::saveData($this->gcr_pid, 'array', $saveData, 'overwrite', 'YMD');
            if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
                $status = "<li>Problem saving Gift Card Library updates for record $gcr_record_id in project ". $this->gcr_pid . "</li>";
                $message .= $status;
                $this->module->emError($status);
            }

            // Update the record in this project to save which record we are reserving from the Gift Card Library
            $record[$this->fk_field] = $gcr_record_id;
            $record[$this->gc_status] = 'Reserved';

            $saveData = array();
            $saveData[$record_id][$this->fk_event_id] = $record;
            $saveStatus = REDCap::saveData($this->project_id, 'array', $saveData, 'overwrite');
            if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
                $status = "Problem saving Gift Card project updates for record $record in project $this->project_id";
                $message .= "<li>" . $status . "</li>";
                $this->module->emError($status);
            }

        } else {
            // The email was not able to be sent - this is an error
            $message = "<li>Reward email was NOT sent for record $record_id even though Gift Card Reward was found in record $gcr_record_id</li>";
        }

        if ($message === '') {
            return array(true, null);
        } else {
            return array(false, $message);
        }
    }

    /**
     * Send the recipient and email with a link so they can retrieve their gift card
     *
     * @param $record_id
     * @return bool
     */
    private function sendEmailWithLinkToReward($url, $record_id) {

        // Set up the verification email to send to the recipient
        $bodyDescription = 'To access your gift card reward, please select the link below:<br>'.
                           '<a href="' . $url . '">' . $url. '</a>';
        $emailTo = $this->email_address;
        $emailFrom = $this->email_from;
        $subject = $this->email_verification_subject;
        $emailSubject = Piping::replaceVariablesInLabel($subject, $record_id, $this->fk_event_id, array(), false, null, false);

        if (!empty($this->email_verification_header)) {
            $body = $this->email_verification_header . "<br>" . $bodyDescription;
            $emailBody = Piping::replaceVariablesInLabel($body, $record_id, $this->fk_event_id, array(), false, null, false);
        } else {
            $emailBody = $bodyDescription;
        }

        $status = REDCap::email($emailTo, $emailFrom, $emailSubject, $emailBody);
        $this->module->emDebug("Notification Email - To: $emailTo, From: $emailFrom, Subject: $emailSubject, Body: $emailBody");

        return $status;
    }

    /**
     * This function will generate an unique hash for each reward. Each hash has 15 characters.
     *
     * @param $record_id
     * @return string - newly created unique 15 character hash
     */
    private function createRewardHash($record_id) {

        $hashList = array();

        // Retrieve the current hashes so we can make sure this newly generated one is unique
        $recordHashes = REDCap::getData($this->gcr_pid, 'array', null, array('reward_hash'), $this->gcr_event_id);
        foreach ($recordHashes as $record => $event) {
            foreach($event[$this->gcr_event_id] as $fieldname => $recordHash) {
                $hashList[] = $recordHash;
            }
        }

        $hash = null;
        while(is_null($hash) || in_array($hash, $hashList)) {
            $hash = generateRandomHash(15, false, false, false);
            $this->module->emDebug("For record $record_id, new random hash: " . $hash);
        }

        return $hash;
    }


}