<?php
namespace Stanford\GiftcardReward;

use Piping;
use \Project;
use \REDCap;
use \Exception;

class RewardInstance
{
    /** @var \Stanford\GiftcardReward\GiftcardReward $module */
    private $module, $project_id, $Proj;

    private $title, $logic, $fk_field, $fk_event_id, $gc_status, $amount,
        $email, $email_subject, $email_header, $email_verification,
        $email_verification_subject, $email_verification_header,
        $email_from, $email_address, $email_event_id, $alert_email,
        $cc_verification_email, $cc_reward_email, $cc_email,
        $brand_field, $brand_name;

    private $optout_low_balance, $low_balance_number, $optout_daily_summary, $allow_multiple_rewards;

    private $gcr_pid, $gcr_event_id, $gcr_pk;

    public function __construct($module, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance)
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
        $this->brand_field                  = $instance['brand-field'];

        // These are reward email parameters
        $this->cc_reward_email              = $instance['cc-reward-email'];
        $this->email                        = $instance['reward-email'];
        $this->email_from                   = $instance['reward-email-from'];
        $this->email_subject                = $instance['reward-email-subject'];
        $this->email_header                 = $instance['reward-email-header'];
        $this->cc_verification_email        = $instance['cc-verification-email'];
        $this->email_verification           = $instance['reward-email-verification'];
        $this->email_verification_subject   = $instance['reward-email-verification-subject'];
        $this->email_verification_header    = $instance['reward-email-verification-header'];
        $this->alert_email                  = $alert_email;
        $this->cc_email                     = $cc_email;

        // There are gift card options
        $this->optout_low_balance           = $instance['optout-low-balance'];
        $this->low_balance_number           = $instance['low-balance-number'];
        $this->allow_multiple_rewards       = $instance['allow-multiple-rewards'];

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

        // Check that the forms that the required fields are on are in this event.
        // We are not including the email address field since we are now allowing that to be in a different event
        $all_forms = array();
        $all_forms[0] = $metaData[$this->fk_field]['form_name'];
        $all_forms[1] = $metaData[$this->gc_status]['form_name'];
        $diff = array_diff($all_forms, $this->Proj->eventsForms[$this->fk_event_id]);
        if (!empty($diff)) {
            if (count($diff) == 1) {
                $message .= "<li>The project fields are not all in the same event " . $this->Proj->eventInfo[$this->fk_event_id]['name'] .
                    ". The form " . json_encode(array_values($diff)) . " is not in this event.</li>";
            } else {
                $message .= "<li>The project fields are not all in the same event " . $this->Proj->eventInfo[$this->fk_event_id]['name'] .
                    ". The forms " . json_encode(array_values($diff)) . " are not in this event.</li>";
            }
        }

        // The email address does not need to be in the same event as the gift card fields so look for it.
        $this->email_event_id = null;
        $email_form = $metaData[$this->email]['form_name'];
        foreach ($this->Proj->eventsForms as $event_id => $form_list) {
            if (in_array($email_form, $form_list)) {
                if (is_null($this->email_event_id)) {
                    $this->email_event_id = $event_id;
                } else {
                    $message .= "<li>The email address is in more than 1 event: $this->email_event_id and $event_id.</li>";
                }
            }
        }
        $this->module->emDebug("email event id is $this->email_event_id");

        // There can only be one email field for each project so it cannot be located on a repeating form or in
        // a repeating event
        if (!empty($this->Proj->RepeatingFormsEvents[$this->email_event_id])) {
            if ($this->Proj->RepeatingFormsEvents[$this->email_event_id] == 'WHOLE' or
                !is_null($this->Proj->RepeatingFormsEvents[$this->email_event_id][$email_form])) {
                $message .= "<li>Email address cannot be on a form that is repeating or in a repeating event.</li>";
                $this->module->emDebug("Email address cannot be on a form that is repeating or in a repeating event.");
            }
        }

        // If the configuration has the checkbox checked to cc either the verification or reward email, make sure we
        // have an email address specified [cc-email]
        if (($this->cc_reward_email === 'true') || ($this->cc_verification_email === 'true')) {
            if (empty($this->cc_email)) {
                $message .= "<li>Emails should be cc'd but the CC email address is blank. Please fill in the CC email address field.</li>";
            }
        }


        // Now check the logic which will determine when to send a gift card -- make sure it is valid
        if (!empty($this->logic)) {

            // To check the logic, we need a record.  See if there is a record in the project
            $data = REDCap::getData($this->project_id, 'array', null, array($this->Proj->table_pk), $this->fk_event_id);
            $record = array_keys($data)[0];
            if (empty($record)) {
                $this->module->emError("There are no records to test the gift card logic '" . $this->logic . "' for pid $this->project_id");
                $message .= "<li>Reward logic (" . $this->logic . ") cannot be confirmed to be valid because there are no records to check against.</li>";
            } else {
                $valid = $this->checkRewardStatus($record);
                if ($valid === null) {
                    $message .= "<li>Reward logic (" . $this->logic . ") is invalid.</li>";
                }
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
     * This function checks the logic which determines when it is time to send a reward.  If a record is not
     * given, we can't check the logic so return invalid.
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
            $this->module->emError("Entered record id is null so gift card logic '" . $this->logic . "' for pid $this->project_id cannot be performed.");
            return $status;
        } else {

            // If a record was given, make sure a reward was not already given for the same email address.
            // We need the whole record because the email address might be in a different event.
            $data = REDCap::getData($this->project_id, 'array', $record, null, array($this->email_event_id, $this->fk_event_id));
            $thisRecord = $data[$record][$this->fk_event_id];
            if (is_null($this->email_event_id)) {
                $this->module->emError("This email event ID is null so cannot find the email address.");
                $status = false;
            } else {
                $this->email_address = $data[$record][$this->email_event_id][$this->email];
            }

            // If the fk_field field is not blank, a gc has already been issued
            if (!empty($thisRecord[$this->fk_field])) {
                $status = false;
            }
        }

        // Test the logic for this record
        $this->module->emDebug("This is for record " . $record . ", with status = $status");
        if (($record !== null) && ($status !== false)) {
            // If this config is filtering on brand, find out what Brand they want
            // The brand is a radio button so we don't get misspelling.  We need to get the
            // data dictionary to find out the actual brand.
            if ($this->brand_field != '') {
                $options = $this->retrieveFieldOptions();
                $brand_option = $thisRecord[$this->brand_field];
                $this->brand_name = $options[$brand_option];
            }

            $status = REDCap::evaluateLogic($this->logic, $this->project_id, $record);
            $this->module->emDebug("Logic evaluated to '$status' for record $record for project $this->project_id - Logic: " . $this->logic);
        }

        return $status;
    }

    /**
     * This function finds the options for a radio button field. Specifically the brand names should be
     * listed in this field so as to avoid data entry errors.
     *
     * @return array
     * @throws Exception
     */
    function retrieveFieldOptions() {

        // Split the selection list so we can determine which option is selected for our record
        $field_dd = REDCap::getDataDictionary($this->project_id, 'array', false, $this->brand_field);

        $options = array();
        if ($field_dd[$this->brand_field]['field_type'] == 'radio') {

            // Split the list of options from a string into arrays
            $selections = explode('|', $field_dd[$this->brand_field]['select_choices_or_calculations']);

            foreach ($selections as $selection) {
                $split = explode(',', $selection);
                $key = trim($split[0]);
                $value = trim($split[1]);
                $options[$key] = $value;
            }
        } else {
            $this->module->emError("The field " . $this->brand_field . " needs to be a radio field with list of brand names.");
        }
        return $options;
    }



    /**
     * This record qualifies for a reward so process it.
     *
     * @param $record_id
     * @return array
     */
    public function processReward($record_id) {

        $message = '';
        $valid = false;

        // Check to see if this participant has already been rewarded a gift card. If so, don't
        // send another one unless the configuation checkbox was selected that it is okay to send.
        list($valid, $message) = $this->checkForPreviousReward($record_id);
        if (!$valid) {

            // Even though it is not valid to send a reward, we are successfully done processing
            return array(true, $message);
        }

        $gcr_required_fields = getGiftCardLibraryFields();
        // We found that this user is eligible for an gift card, so find the next award that fits our criteria
        list($found, $reward_record) = $this->findNextAvailableReward($gcr_required_fields);
        if (!$found) {

            // We were not able to find a reward that meets our criteria.
            $message = "No reward was found in project " . $this->gcr_pid . " which meets our criteria for $this->title reward";
            if (!empty($this->brand_name)) {
                $message .= " for brand " . $this->brand_name;
            }

            // Send email to the alert email address to notify them there are no longer any gift cards and update record
            $this->sendAlertEmailNoGiftCards($record_id);

        } else {

            // There is a valid reward available so reserve it
            list($valid, $message) = $this->reserveReward($record_id, $reward_record);
        }

        return array($valid, $message);
    }

    /**
     * This function will send an ALERT email when there are no more gift cards available and
     * someone qualifies for one.
     *
     * @param $record_id
     */
    private function sendAlertEmailNoGiftCards($record_id) {

        global $redcap_version, $module;

        // Put together the URL to this redcap record so we can include it in the email.
        $recordUrl = APP_PATH_WEBROOT_FULL . "redcap_v{$redcap_version}/DataEntry/record_home.php?pid=$this->project_id&id=$record_id";

        // Send alert email that we could not send reward to eligible participant
        $emailTo = $this->alert_email;
        $emailFrom = $this->alert_email;
        $emailSubject = "ALERT: No more Gift Cards available for Reward $this->title";
        $emailBody = "Record $record_id is eligible for a gift card but there are none available.<br>".
                     " Once there are more available gift cards available, make sure the eligibility logic for record ". $record_id .
                     " is still valid and re-save the record.<br>".
                     " Link to record: <br>".
                     " <a href='$recordUrl'>Record $record_id</a>";
        $status = $this->sendEmail($emailTo, $emailFrom, $emailSubject, $emailBody);

        // Save the status in the record to show that we were not able to send them a reward
        $saveRecord[$record_id][$this->fk_event_id][$this->gc_status] = "Unavailable - no gift cards available";
        $saveStatus = REDCap::saveData($this->project_id, 'array', $saveRecord);
        if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
            $this->module->emError("Problem saving status to project $this->project_id record $record_id");
        }
    }

    /**
     * This function finds the first available reward record from the library
     *
     * @param $gcr_required_fields
     * @return array -
     *          1) true/false - was reward record found
     *          2) if true, reward record otherwise null
     */
    private function findNextAvailableReward($gcr_required_fields) {

        // Retrieve all available gift cards for this reward
        $data = $this->retrieveGiftCardRewardsList($gcr_required_fields);

        // Check to see if there was threshold limit entered and if so, are we below it. If there are no rewards available,
        // we will be sending them email about this participant so don't send another email here.
        if (!$this->optout_low_balance and !empty($this->low_balance_number) and
                (count($data) <= $this->low_balance_number) and (count($data) > 0)) {
            $emailBody = "There are " . (count($data)-1) . " rewards available for gift card configuation " . $this->title;
            if (!empty($this->brand_field)) {
                $emailBody .= " for brand " . $this->brand_name;
            }
            $status = $this->sendEmail($this->alert_email, $this->alert_email, "Gift Card Low Balance Notification", $emailBody);
        }

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

        if ($this->brand_field != '') {
            $filter .= " and [brand] = '" . $this->brand_name . "'";
        }

        $this->module->emDebug("this is the brand in retrieveGiftCardRewardsList: " . $this->brand_name);
        $this->module->emDebug("Filter: " . $filter);
        $data = REDCap::getData($this->gcr_pid, 'array', null, $gcr_required_fields, $this->gcr_event_id,
            null, null, null, null, $filter);
        $this->module->emDebug("Records: " . json_encode($data));
        return $data;
    }

    /**
     * This function will check to see if the email address listed in this record has already received a reward.
     * If a reward has already been issued, do not issue another one.  To allow multiple rewards, there is a
     * checkbox in the configuration file that will allow this check to be bypassed.
     *
     * @param $record_id
     * @return array
     *          -- true/false - should reward be sent
     *          -- if false, message why the reward will not be sent, otherwise null
     */
    private function checkForPreviousReward($record_id) {

        $message = '';

        // Check to see if this email address already received a gift card or if the checkbox
        // is checked that allows an email to receive multiple rewards.
        if (REDCap::isLongitudinal()) {
            $event_names = REDCap::getEventNames(true, false);
            $this->module->emDebug("(true, false)Event names for record $record_id: " . json_encode($event_names));

            $projFilter = "[" . $event_names[$this->email_event_id] . "][" . $this->email . "] = '" . $this->email_address . "'" .
                " and (([" . $event_names[$this->fk_event_id] . "][". $this->gc_status . "] = 'Reserved')" .
                " or ([" . $event_names[$this->fk_event_id] . "][" . $this->gc_status . "] = 'Claimed'))";
            $this->module->emDebug("Project Filter: " . $projFilter);
        } else {
            $projFilter = "[" . $this->email . "] = '" . $this->email_address . "'" .
                " and (([". $this->gc_status . "] = 'Reserved')" .
                " or ([" . $this->gc_status . "] = 'Claimed'))";
        }

        if (!$this->allow_multiple_rewards && !empty($this->email_address)) {
            $projData = REDCap::getData($this->project_id, 'array', null, array($this->email_address), null,
                null, null, null, null, $projFilter);
            if (!empty($projData)) {
                $message = "Duplicate email addresss $this->email_address for reward $this->title and record $record_id";

                // Update the status for this record to say they were not sent a reward because it is a duplicate
                $record[$this->gc_status] = 'Duplicate email';
                $this->module->emDebug("This email, $this->email_address in record $record_id, has already been sent a reward - not sending another.");

                $saveData = array();
                $saveData[$record_id][$this->fk_event_id] = $record;
                $saveStatus = REDCap::saveData($this->project_id, 'array', $saveData, 'overwrite');
                if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
                    $status = "Duplicate email for $record in project $this->project_id";
                    $this->module->emError($status);
                }

                return array(false, $message);
            }
        }

        return (array(true, null));
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

        // If the reward code is actually a link address, we will put the link into the email body
        $gcr_record_code = $reward_record['egift_number'];
        if (substr($gcr_record_code, 0, 4) == "http") {

            // When the gift card code is a url, put it in the body of the email.
            $bodyDescription = 'Please follow this link to access your gift card:<br>' .
                '<a href="' . $gcr_record_code. '">' . $gcr_record_code . '</a>';

        } else {

            // Create a unique hash for this reward (record/reward)
            $hash = $this->createRewardHash($record_id);

            // Create the URL for this reward. Add on the project and hash
            $url = $this->module->getUrl("src/DisplayReward.php", true, true);
            $url .= "&reward_token=" . $hash;

            // Set up the verification email to send to the recipient
            $bodyDescription = 'To access your gift card reward, please select the link below:<br>' .
                '<a href="' . $url . '">' . $url . '</a>';
        }

        // Send the verification email to the recipient
        $status = $this->sendEmailWithLinkToReward($record_id, $bodyDescription);
        $this->module->emDebug("This is the status '$status' from sendingEmail for record $record_id");
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
     * Send the recipient the email with a link so they can retrieve their gift card
     *
     * @param $url
     * @param $record_id
     * @return bool - Status of the sending of the email
     */
    private function sendEmailWithLinkToReward($record_id, $bodyDescription) {

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

        // Check to see if the config checkbox is checked to cc the alert-email address of this reward.
        $cc_email = null;
        if ($this->cc_verification_email == 'true') {
            $cc_email = $this->cc_email;
        }
        $status = $this->sendEmail($emailTo, $emailFrom, $emailSubject, $emailBody, $cc_email);
        $this->module->emDebug("Notification Email - To: $emailTo, From: $emailFrom, Subject: $emailSubject, Body: $emailBody, CC: $cc_email");


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
    public function retrieveSummaryData() {

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
        $filter = "[amount] = '" . $this->amount . "'";

        $data = REDCap::getData($this->gcr_pid, 'array', null, null, $this->gcr_event_id, null, null, null, null, $filter);
        if (!empty($data)) {
            $brand_type = array();
            foreach ($data as $record_id => $event_info) {
                foreach ($event_info as $event_id => $record) {

                    $status = $record['status'];
                    if ($record['reward_name'] == $this->title) {
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
            $this->module->emError("No data was found for DailySummary from gc library [pid:$this->gcr_pid/event id:$this->gcr_event_id]");
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
     * This is just a wrapper for the REDCap emailer to make it easier to log messages.
     *
     * @param $emailTo - Email address in To field
     * @param $emailFrom - Email address in From field
     * @param $emailSubject - Email subject
     * @param $emailBody - Email Body
     * @return bool - true/false if email was successfully sent
     */
    private function sendEmail($emailTo, $emailFrom, $emailSubject, $emailBody, $ccEmail=null) {

        $this->module->emDebug("In sendEmail: To " . $emailTo . ", and From " . $emailFrom . ", email Subject " . "$emailSubject" .
            " email Body: " . $emailBody . ", cc_email: " . $ccEmail);

        $status = REDCap::email($emailTo, $emailFrom, $emailSubject, $emailBody, $ccEmail);
        if (!$status) {
            $email = array(
                "To:"       => $emailTo,
                "From:"     => $emailFrom,
                "Subject:"  => $emailSubject,
                "Body:"     => $emailBody,
                "CC:"       => $ccEmail
            );
            $this->module->emError("Attempted to send email to $emailTo but received error.", json_encode($email));
        }

        return $status;
    }


}