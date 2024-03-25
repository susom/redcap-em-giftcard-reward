<?php
namespace Stanford\GiftcardReward;

use Piping;
use Project;
use REDCap;
use Exception;

require_once "src/VerifyLibraryClass.php";

class RewardInstance
{
    /** @var \Stanford\GiftcardReward\GiftcardReward $module */

    private $title, $logic, $fk_field, $fk_event_id, $gc_status, $amount,
        $email, $email_subject, $email_header, $email_verification,
        $email_verification_subject, $email_verification_header,
        $email_from, $email_address, $email_event_id, $alert_email,
        $cc_verification_email, $cc_reward_email, $cc_email,
        $brand_field, $brand_name, $project_id, $brand_options,
        $dont_send_email, $reward_url_field;
    private $optout_low_balance, $low_balance_number, $allow_multiple_rewards;
    private $gcr_pid, $gcr_event_id, $gcr_pk, $pid;
    private $module, $lock_name;
    private $gcr_proj;
    private $gcr_required_fields;

    public function __construct($module, $pid, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $instance)
    {
        global $Proj;

        $Proj->setRepeatingFormsEvents();
        $this->module                       = $module;
        $this->project_id                   = $pid;

        // These are required gift card parameters in the gift card project
        $this->title                        = $instance['reward-title'];
        $this->logic                        = $instance['reward-logic'];
        $this->fk_field                     = $instance['reward-fk-field'];
        $this->fk_event_id                  = $instance['reward-fk-event-id'];
        $this->gc_status                    = $instance['reward-status'];
        $this->amount                       = $instance['reward-amount'];
        $this->brand_field                  = $instance['brand-field'];
        $this->reward_url_field             = $instance['reward-url'];
        $this->dont_send_email              = $instance['dont-send-email'];

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

        // These are gift card options
        $this->optout_low_balance           = $instance['optout-low-balance'];
        $this->low_balance_number           = $instance['low-balance-number'];
        $this->allow_multiple_rewards       = $instance['allow-multiple-rewards'];

        try {
            // Retrieve data dictionary of library project
            $this->gcr_pid = $gcr_pid;
            $this->gcr_proj = new Project($this->gcr_pid);
            $this->gcr_proj->setRepeatingFormsEvents();
            $this->gcr_pk = $this->gcr_proj->table_pk;

            // Check to see if the event id is set
            $this->gcr_event_id = $module->checkGiftCardLibEventId($this->gcr_proj, $gcr_event_id);

            // Retrieve the gift card library required fields
            $this->gcr_required_fields = $module->getGiftCardLibraryFields();

            // Create a lock name around the GC Library
            $this->lock_name = 'GCLib' . $this->gcr_pid;

            // Retrieve the brand options if the brand option is selected.
            if ($this->brand_field != '') {
                $this->retrieveFieldOptions();
            }

        } catch (Exception $ex) {
            $this->module->emError("Exception caught initializing Reward Instance for project $this->project_id, with error: " . json_encode($ex));
            \REDCap::logEvent("Exception caught initializing Reward Instance for project $this->project_id", "with error: " . json_encode($ex));
        }
    }


    /**
     * Verify the gift card project configuration and return an array of ($result, $message)
     * where $result = true/false
     * and $message is an error string that can be displayed on the configuration setup page
     */
    public function verifyConfig() {

        global $Proj;

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
            return [false, $message];
        }

        // Check that the forms that the required fields are on are in this event.
        // We are not including the email address field since we are now allowing that to be in a different event
        $all_forms = array();
        $all_forms[0] = $metaData[$this->fk_field]['form_name'];
        $all_forms[1] = $metaData[$this->gc_status]['form_name'];
        $diff = array_diff($all_forms, $Proj->eventsForms[$this->fk_event_id]);
        if (!empty($diff)) {
            if (count($diff) == 1) {
                $message .= "<li>The project fields are not all in the same event " . $Proj->eventInfo[$this->fk_event_id]['name'] .
                    ". The form " . json_encode(array_values($diff)) . " is not in this event.</li>";
            } else {
                $message .= "<li>The project fields are not all in the same event " . $Proj->eventInfo[$this->fk_event_id]['name'] .
                    ". The forms " . json_encode(array_values($diff)) . " are not in this event.</li>";
            }
        }

        // The email address does not need to be in the same event as the gift card fields so look for it.
        // If this is a multi-arm project, make sure it is in only one event for this arm although it might be
        // in every arm.
        $this->email_event_id = null;
        $email_form = $metaData[$this->email]['form_name'];

        $events_to_check = array();
        if ($Proj->numArms > 1) {

            // There is more than one arm so find the arm that the other fields are in
            // and make sure that arm only has one email field
            foreach($Proj->events as $arm => $info) {
                $events_in_arm = array_keys($info['events']);
                if (in_array($this->fk_event_id, $events_in_arm)) {
                    $events_to_check = $events_in_arm;
                }
            }
        } else {

            $events_to_check = array_keys($Proj->eventsForms);
        }

        // Check through the events in this arm to make sure there is only one email field
        foreach($events_to_check as $event_id) {
            $form_list = $Proj->eventsForms[$event_id];
            if (in_array($email_form, $form_list)) {
                if (is_null($this->email_event_id)) {
                    $this->email_event_id = $event_id;
                } else {
                    $message .= "<li>The email address is in more than 1 event: $this->email_event_id and $event_id.</li>";
                }
            }
        }

        // There can only be one email field for each project so it cannot be located on a repeating form or in
        // a repeating event
        if (!empty($Proj->RepeatingFormsEvents[$this->email_event_id])) {
            if ($Proj->RepeatingFormsEvents[$this->email_event_id] == 'WHOLE' or
                !is_null($Proj->RepeatingFormsEvents[$this->email_event_id][$email_form])) {
                $message .= "<li>Email address cannot be on a form that is repeating or in a repeating event.</li>";
                $this->module->emError("Email address cannot be on a form that is repeating or in a repeating event.");
                \REDCap::logEvent("Email address cannot be on a form that is repeating or in a repeating event.");
            }
        }

        // If the configuration has the checkbox checked to cc either the verification or reward email, make sure we
        // have an email address specified [cc-email]
        if (($this->cc_reward_email === 'true') || ($this->cc_verification_email === 'true')) {
            if (empty($this->cc_email)) {
                $message .= "<li>Emails should be cc'd but the CC email address is blank. Please fill in the CC email address field.</li>";
            }
        }

        // Check to make sure there are brand options if the brand option is selected.
        if ($this->brand_field != '' and empty($this->brand_options)) {
            $message .= "<li>Cannot filter on brands because there are no options specified for field " . $this->brand_field . " </li>";
        }

        // Now check the logic which will determine when to send a gift card -- make sure it is valid
        if (!empty($this->logic)) {

            // To check the logic, we need a record.  See if there is a record in the project
            $data = REDCap::getData($this->project_id, 'array', null, array($Proj->table_pk), $this->fk_event_id);
            $record = array_keys($data)[0];
            if (empty($record)) {
                $this->module->emError("There are no records to test the gift card logic '" . $this->logic . "' for pid $this->project_id");
                \REDCap::logEvent("There are no records to test the gift card logic '" . $this->logic . "' for pid $this->project_id");
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
            return [true, null];
        } else {
            return [false, $message];
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
            \REDCap::logEvent("Entered record id is null so gift card logic '" . $this->logic . "' for pid $this->project_id cannot be performed.");
            return $status;
        } else {

            // If a record was given, make sure a reward was not already given for the same email address.
            // We need the whole record because the email address might be in a different event.
            $data = REDCap::getData($this->project_id, 'array', $record, null, array($this->email_event_id, $this->fk_event_id));
            $thisRecord = $data[$record][$this->fk_event_id];
            if (is_null($this->email_event_id)) {
                $this->module->emError("This email event ID is null so cannot find the email address.");
                \REDCap::logEvent("This email event ID is null so cannot find the email address.");
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
        if (($record !== null) && ($status !== false)) {
            // If this config is filtering on brand, find out what Brand they want
            // The brand is a radio button or dropdown so we don't get misspelling.  We need to get the
            // data dictionary to find out the actual brand.
            if ($this->brand_field != '') {
                $brand_option = $thisRecord[$this->brand_field];
                $this->brand_name = $this->brand_options[$brand_option];
            }

            $status = REDCap::evaluateLogic($this->logic, $this->project_id, $record);
        }

        return $status;
    }

    /**
     * This function finds the options for a radio button field. Specifically the brand names should be
     * listed in this field so as to avoid data entry errors.
     *
     * @throws Exception
     */
    private function retrieveFieldOptions() {

        // Split the selection list so we can determine which option is selected for our record
        $field_dd = REDCap::getDataDictionary($this->project_id, 'array', false, $this->brand_field);

        $this->brand_options = array();
        if (($field_dd[$this->brand_field]['field_type'] == 'radio') or ($field_dd[$this->brand_field]['field_type'] == 'dropdown')) {

            // Split the list of options from a string into arrays
            $selections = explode('|', $field_dd[$this->brand_field]['select_choices_or_calculations']);

            foreach ($selections as $selection) {
                $split = explode(',', $selection);
                $key = trim($split[0]);
                $value = trim($split[1]);
                $this->brand_options[$key] = $value;
            }
        } else {
            $this->module->emError("The field " . $this->brand_field . " needs to be a dropdown or radio field with list of brand names.");
            \REDCap::logEvent("The field " . $this->brand_field . " needs to be a dropdown or radio field with list of brand names.");
        }
    }


    /**
     * This record qualifies for a reward so process it.
     *
     * @param $record_id
     * @param $sendNoGiftCardAlert - making an optional argument in case we are batch processing
     *                               and don't want to send a flurry of emails
     * @return array
     */
    public function processReward($record_id) {

        $message = '';
        $valid = false;

        // Check to see if this participant has already been rewarded a gift card. If so, don't
        // send another one unless the configuation checkbox was selected that it is okay to send.
        [$valid, $message] = $this->checkForPreviousReward($record_id);
        if (!$valid) {

            $this->module->emDebug("Already sent to record $record_id");
            // Even though it is not valid to send a reward, we are successfully done processing
            return [true, $message];
        }

        try {

            // We found that this user is eligible for a gift card, so find the next award that fits our criteria
            $status = $this->getLock($this->lock_name, $record_id);
            $this->module->emDebug("Status of lock: " . $status);
            [$found, $reward_record] = $this->findNextAvailableReward();
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
                [$valid, $message] = $this->reserveReward($record_id, $reward_record);
            }
        } catch (Exception $ex) {
            $this->module->emError("Exception encountered when processing reward: " . $ex->getMessage());
            \REDCap::logEvent("Exception encountered when processing reward: " ,  $ex->getMessage());
            $message .= "<br>Exception encountered! Please contact a REDCap administrator.";
        } finally {
            $this->module->emDebug("In finally: Before releasing lock");
            $this->releaseLock($this->lock_name);
            $this->module->emDebug("In finally: Released lock");
        }

        return [$valid, $message];
    }


    /**
     * This function retrieves a DB lock
     *
     * @param $lock_name
     * @return int
     */
    private function getLock($lock_name, $record_id) {

        // Obtain lock for reward library
        $result = $this->module->query("SELECT GET_LOCK(?, 5)", [$lock_name]);
        $row = $result->fetch_row();
        if ($row[0] !== 1) {
            $record[$record_id][$this->fk_event_id][$this->gc_status] = 'Database lock problem - contact REDCap team';
            $err_msg = "Database lock is preventing processing - alert REDCap team";
            $message = $this->saveGCData($this->project_id, 'array', $record, $err_msg);

            throw new Exception("Unable to obtain lock on " . $lock_name);
        } else {
            $this->module->emDebug("Obtained Lock: $lock_name");
            $status = 1;
        }

        return $status;
    }


    /**
     * This function releases the DB lock
     *
     * @param $lock_name
     * @return void
     */
    private function releaseLock($lock_name) {

        // Obtain lock for reward library
        if ($lock_name != null) {
            $result = $this->module->query("select RELEASE_LOCK(?)", [$lock_name]);
            $row = $result->fetch_row();
            $this->module->emDebug("Released Lock: " . $lock_name . ", with status " . $row[0]);
        }
    }


    /**
     * This function will send an ALERT email when there are no more gift cards available and
     * someone qualifies for one.
     *
     * @param $record_id
     */
    private function sendAlertEmailNoGiftCards($record_id) {

        global $redcap_version;

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
        $err_msg = "Problem saving status to project $this->project_id record $record_id";
        $message = $this->saveGCData($this->project_id, 'array', $saveRecord, $err_msg);

    }

    /**
     * This function finds the first available reward record from the library
     *
     * @return array -
     *          1) true/false - was reward record found
     *          2) if true, reward record otherwise null
     */
    private function findNextAvailableReward() {

        // Retrieve all available gift cards for this reward
        $data = $this->retrieveGiftCardRewardsList();

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
            return [false, "No Reward Found"];
        } else {
            // We already know that this is not a repeating form/event
            $next_record_id = min(array_keys($data));
            return [true, $data[$next_record_id][$this->gcr_event_id]];
        }
    }

    /**
     * This function will retrieve all rewards record which haven't been previously claimed and
     * match the dollar amount that was specified in the configuration if specified.
     *
     * @return null if no records were found
     *              array of records which fits our criteria
     */
    private function retrieveGiftCardRewardsList() {

        // Look for the next available gift card record which meets our requirements
        $filter = "[status] = '1' and [reserved_ts] = '' and [claimed_ts] = ''";
        if ($this->amount != '') {
            $filter .= " and [amount] = " . $this->amount;
        }

        if ($this->brand_field != '') {
            $filter .= " and [brand] = '" . $this->brand_name . "'";
        }

        $data = REDCap::getData($this->gcr_pid, 'array', null, $this->gcr_required_fields, $this->gcr_event_id,
            null, null, null, null, $filter);
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

            $projFilter = "[" . $event_names[$this->email_event_id] . "][" . $this->email . "] = '" . $this->email_address . "'" .
                " and (([" . $event_names[$this->fk_event_id] . "][". $this->gc_status . "] = 'Reserved')" .
                " or ([" . $event_names[$this->fk_event_id] . "][" . $this->gc_status . "] = 'Claimed'))";
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
                // log that this email was rewarded multiple giftcards.
                \REDCap::logEvent($message);
                // Update the status for this record to say they were not sent a reward because it is a duplicate
                $record[$record_id][$this->fk_event_id][$this->gc_status] = 'Duplicate email';
                $err_msg = "Duplicate email for $record_id in project $this->project_id";
                $message = $this->saveGCData($this->project_id, 'array', $record, $err_msg);

                return [false, $message];
            }
        }

        return [true, null];
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
            $bodyDescription = $this->module->tt("your_link") . '<br>'  .
                '<a href="' . $gcr_record_code. '">' . $gcr_record_code . '</a>';
            $reward_url = $gcr_record_code;

        } else {

            // Create a unique hash for this reward (record/reward)
            $hash = $this->createRewardHash();

            // Create the URL for this reward. Add on the project and hash
            $url = $this->module->getUrl("src/DisplayReward.php", true, true);
            $url .= "&reward_token=" . $hash;
            $reward_url = $url;

            // Set up the verification email to send to the recipient
            $bodyDescription = $this->module->tt("your_link") . '<br>' .
                '<a href="' . $url . '">' . $url . '</a>';
        }

        // Send the verification email to the recipient unless the don't send email box was checked
        $status =  true;
        if ($this->dont_send_email == '0') {
            // There should be an email address entered otherwise we can't send the reward
            if (empty($this->email_address)) {
                // Save the fact that there is no email address entered so it can be corrected
                $record[$record_id][$this->fk_event_id][$this->gc_status] = 'No email address entered';
                $err_msg = "Problem saving Gift Card project updates for record $record_id in project $this->project_id";
                $message .= $this->saveGCData($this->project_id, 'array', $record, $err_msg);
                $status = false;
            } else {
                $status = $this->sendEmailWithLinkToReward($record_id, $bodyDescription);
            }
        }
        if ($status) {

            // If the email was successfully sent, update the Gift Card Library to reserve this reward
            $reward_record[$gcr_record_id][$this->gcr_event_id]['status'] = '2';   //  ('Reserved')
            $reward_record[$gcr_record_id][$this->gcr_event_id]['reward_name'] = $this->title;
            $reward_record[$gcr_record_id][$this->gcr_event_id]['reward_pid'] = $this->project_id;
            $reward_record[$gcr_record_id][$this->gcr_event_id]['reward_record'] = $record_id;
            $reward_record[$gcr_record_id][$this->gcr_event_id]['reserved_ts'] = date('Y-m-d H:i:s');
            $reward_record[$gcr_record_id][$this->gcr_event_id]['reward_hash'] = $hash;
            $reward_record[$gcr_record_id][$this->gcr_event_id]['url'] = $url;

            // Format the data the way REDCap wants it
            $err_msg = "<li>Problem saving Gift Card Library updates for record $gcr_record_id in project ". $this->gcr_pid . "</li>";
            $message .= $this->saveGCData($this->gcr_pid, 'array', $reward_record, $err_msg);

            // Update the record in this project to save which record we are reserving from the Gift Card Library
            $record[$record_id][$this->fk_event_id][$this->fk_field] = $gcr_record_id;
            $record[$record_id][$this->fk_event_id][$this->gc_status] = 'Reserved';
            if (!empty($this->reward_url_field)) {
                $record[$record_id][$this->fk_event_id][$this->reward_url_field] = $reward_url;
            }
            $err_msg = "Problem saving Gift Card project updates for record $gcr_record_id in project $this->project_id";
            $message .= $this->saveGCData($this->project_id, 'array', $record, $err_msg);

        } else {
            // The email was not able to be sent - this is an error
            $message = "<li>Reward email was NOT sent for record $record_id even though Gift Card Reward was found in record $gcr_record_id</li>";
        }

        if ($message === '') {
            return [true, null];
        } else {
            return [false, $message];
        }
    }


    /**
     * This function saves data to the database and checks for errors with the save.
     *
     * @param $pid
     * @param $format
     * @param $data
     * @param $error
     * @return string
     */
    private function saveGCData($pid, $format, $data, $error) {

        // Save the data passed and check the status for errors
        $message = '';
        $saveStatus = REDCap::saveData($pid, $format, $data, 'overwrite');
        if (empty($saveStatus['ids']) || !empty($saveStatus['errors'])) {
            $status = $error;
            $message = "<li>" . $status . "</li>";
            $this->module->emError($status);
            \REDCap::logEvent($status);
        }

        return $message;
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
        $emailSubject = Piping::replaceVariablesInLabel($subject, $record_id, $this->fk_event_id, array(), false, null, false, false);

        if (!empty($this->email_verification_header)) {
            $body = $this->email_verification_header . "<br>" . $bodyDescription;
            $emailBody = Piping::replaceVariablesInLabel($body, $record_id, $this->fk_event_id, array(), false, null, false, false);
        } else {
            $emailBody = $bodyDescription;
        }

        // Check to see if the config checkbox is checked to cc the alert-email address of this reward.
        $cc_email = null;
        if ($this->cc_verification_email == 'true') {
            $cc_email = $this->cc_email;
        }
        $status = $this->sendEmail($emailTo, $emailFrom, $emailSubject, $emailBody, $cc_email);

        return $status;
    }

    /**
     * This function will generate an unique hash for each reward. Each hash has 15 characters plus the UNIX time in seconds appended.
     *
     * @return string - newly created unique 15 character hash
     */
    private function createRewardHash() {

        $hash = generateRandomHash(15, false, false, false);
        return $hash . time();
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
            \REDCap::logEvent("Attempted to send email to $emailTo but received error.", json_encode($email));
        }

        return $status;
    }

}