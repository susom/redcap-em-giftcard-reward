<?php

namespace Stanford\GiftcardReward;;

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
        $from_email;

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
        $this->email_subject                = $instance['reward-email-subject'];
        $this->email_header                 = $instance['reward-email-header'];
        $this->email_verification           = $instance['reward-email-verification'];
        $this->email_verification_subject   = $instance['reward-email-verification-subject'];
        $this->email_verification_header    = $instance['reward-email-verification-header'];
        $this->from_email                   = 'yasukawa@stanford.edu';

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
        $message = $this->checkForEmptyField($this->email, "Reward Email Address", $message);
        $message = $this->checkForEmptyField($this->email_subject, "Reward Email Subject", $message);
        if ($message !== '') {
            $message = "<li>" . $message . "</li>";
        }

        // Check that the forms that the required fields are on are in this event
        $all_forms = array();
        //$all_forms[0] = $metaData[$this->logic]['form_name'];  // Since we are checking the logic, can we skip parsing this?
        $all_forms[1] = $metaData[$this->fk_field]['form_name'];
        $all_forms[2] = $metaData[$this->gc_status]['form_name'];
        $all_forms[3] = $metaData[$this->email]['form_name'];
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
        $test_record = null;

        // If a record was not given, find a record to test the logic on
        // This path is used to test out the logic to make sure it is configured correctly
        if (empty($record)) {
            $data = REDCap::getData($this->project_id, 'array', null,
                    array($this->Proj->table_pk), $this->fk_event_id);
            $test_record = array_keys($data)[0];
            if (empty($test_record)) {
                $this->module->emError("There are no records to test the gift card logic '" . $this->logic . "' for pid $this->project_id");
            } else {
                $this->module->emDebug("Found record $test_record to test logic " . $this->logic);
            }
        } else {

            // If a record was given, make sure a reward was not already given
            $test_record = $record;
            $data = REDCap::getData($this->project_id, 'array', $test_record,
                array($this->fk_field, $this->gc_status), $this->fk_event_id);
            $thisRecord = $data[$test_record][$this->fk_event_id];

            // If either the fk_field and gc_status fields are not blank, a gc has already been issued
            if (!empty($thisRecord[$this->fk_field]) || !empty($thisRecord[$this->gc_status])) {
                $status = false;
            }
        }

        // Test the logic for this record
        if (($test_record !== null) && ($status !== false)) {
            $status = REDCap::evaluateLogic($this->logic, $this->project_id, $test_record);
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
        } else {

            // There is a valid reward available so reserve it
            list($valid, $message) = $this->reserveReward($record_id, $reward_record);
        }

        return array($valid, $message);
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
        if (empty($data)) {
            $this->module->emDebug("Retrieved rewards from Library: ", $data);
        }

        return $data;
    }

    /**
     * The recipient is eligible for a reward so email them their reward.
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

        // Send the reward to the recipient
        $status = $this->sendReward($record_id);
        if ($status) {

            // If the email was successfully sent, update the Gift Card Library to reserve this reward
            $reward_record['status'] = '2';   //  ('Reserved')
            $reward_record['reward_name'] = $this->title;
            $reward_record['reward_pid'] = $this->project_id;
            $reward_record['reward_record'] = $record_id;
            $reward_record['reserved_ts'] = date('Y-m-d H:i:s');

            // Format the data the way REDCap wants it
            $saveData = array();
            $saveData[$gcr_record_id][$this->gcr_event_id] = $reward_record;
            $saveStatus = REDCap::saveData($this->gcr_pid, 'array', $saveData, 'overwrite', 'YMD');
            if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
                $status = "<li>Problem saving Gift Card Library updates for record $gcr_record_id in project ". $this->gcr_pid . "</li>";
                $message .= $status;
                $this->module->emLog($status);
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
                $this->module->emLog($status);
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
     * Send the recipient their gift card
     *
     * @param $record_id
     * @return bool
     */
    private function sendReward($record_id) {

        $body = "This is your gift card reward for participating in our survey.";
        if (!empty($this->email_header)) {
            $email_body = $this->email_header . "<br>" . $body;
        } else {
            $email_body = $body;
        }

        $email = new Message();
        $email->setTo($this->email);
        $email->setFrom($this->from_email);
        $email->setSubject($this->email_subject);
        $email->setBody($email_body);
        $sendStatus = $email->send();
        if ($sendStatus == false) {
            $this->module->emLog("Error sending email for " . $this->title . " reward for record $record_id - send error " . $email->getSendError(), json_encode($email));
            return false;
        } else {
            $this->module->emError("Email was successfully sent for " . $this->title . " reward for record $record_id", json_encode($email));
            return true;
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

}