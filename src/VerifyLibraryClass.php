<?php
namespace Stanford\GiftcardReward;

use \Project;
use \Exception;


class VerifyLibraryClass
{
    private $gcr_pid, $gcr_event_id, $gcr_pk;
    private $gcr_proj, $module;

    public function __construct($gcr_pid, $gcr_event_id, $module)
    {
        $this->module = $module;

        // Get the data dictionary for the Library project
        try {
            $this->gcr_proj = new Project($gcr_pid);
            $this->gcr_proj->setRepeatingFormsEvents();
        } catch (Exception $ex) {
            $this->module->emError("Cannot retrieve the Gift Card Library data dictionary!");
            return false;
        }

        // Find the needed gift card library values
        $this->gcr_pk = $this->gcr_proj->table_pk;
        $this->gcr_event_id = $this->module->checkGiftCardLibEventId($this->gcr_proj, $gcr_event_id);
        $this->gcr_pid = $gcr_pid;
    }

    /**
     * Run through the steps to verify the Gift Card Library project
     *
     *  1. Make sure the event id is valid and set
     *  2. Make sure the required fields are present and not on a repeating form or a repeating event
     *  3. Make sure the timestamps are displayed in the format we expect (may this doesn't matter?)
     * @return array
     */
    public function verifyLibraryConfig() {

        $message = '';
        $title = "<b>Gift Card Library:</b>";

        // Make sure we have a project for the gift card library.  It was entered in the EM Config.
        if (empty($this->gcr_pid)) {
            $message = $title . "<li>Select a Gift Card Library project</li>";
            return [false, array($message)];
        }

        // The event id was not given, go find it
        $valid = $this->validateEventId();
        if (!$valid) {
            $message = $title . "<li>This project $this->gcr_pid event id is not valid, please specify which event to use:</li>";
            return [false, array($message)];
        }

        // Make sure all the fields we are expecting to be available in the gift card library project are in this event_id
        // and are not on a repeating form or in a repeating event
        $fields_not_found = $this->checkForLibReqFields();
        if ($fields_not_found === false) {
            $message = $title . "<li>Required fields cannot be in a repeating event. </li>";
        } else if (!empty($fields_not_found)) {
            $message = $title . "<li>Required fields are not found on a non-repeating form: " . json_encode(array_values($fields_not_found)) . " </li>";
        }

        // Make sure the reserve and viewed timestamp are in 'datetime_seconds_ymd' format so we can successfully save
        $ts_valid = $this->checkTimestampFieldTypes();
        if (!$ts_valid) {
            $message = (empty($message) ? $title : $message) . "<li>The timestamps in the Gift Card Library must be formatted as 'Y-M-D H:M:S'.</li>";
        }

        if (empty($message)) {
            return [true, null];
        } else {
            return [false, array($message)];
        }
    }

    /**
     * Make sure the event Id is set.  If not find it from the data dictionary.
     *
     * @return bool
     */
    private function validateEventId () {

        // Make sure we have a correct event_id in the gift card rewards project
        if (($this->gcr_proj->numEvents > 1) && empty($this->gcr_event_id)) {

            // If this project has more than 1 event, the event id must be specified
            return false;
        } else {

            // If this project has only 1 event id and it wasn't specified, then set it.
            if (empty($this->gcr_event_id)) {
                $this->gcr_event_id = array_keys($this->gcr_proj->eventInfo)[0];
            }
            $valid = (isset($this->gcr_proj->eventInfo[$this->gcr_event_id]) ? true : false);
        }

        return $valid;
    }

    /**
     * Check that the required fields are found in the library project.
     *
     * @return array|bool|string[]
     */
    private function checkForLibReqFields() {

        // Retrieve the repeating forms for this event id in the Library
        $repeat_forms = $this->gcr_proj->RepeatingFormsEvents[(int)$this->gcr_event_id];

        if (!empty($repeat_forms)) {

            // Check to see if this event id is a repeating event. If so, it can't be used
            if ($repeat_forms == 'WHOLE') {
                return false;
            } else {

                // Make an array of all the fields in all the forms in this event.  Do not include repeating forms
                $all_event_fields = array();
                foreach ($this->gcr_proj->eventsForms[(int)$this->gcr_event_id] as $form_num => $form_name) {
                    $all_event_fields = array_merge($all_event_fields, array_keys($this->gcr_proj->forms[$form_name]['fields']));
                }

                // See if there are any required fields that are not found in the field list.
                $gcr_required_fields = $this->module->getGiftCardLibraryFields();
                return array_diff($gcr_required_fields, $all_event_fields);
            }
        }

        // Not on a repeating form or in a repeating event
        return null;
    }

    /**
     * Check that the required timestamps are verified in the correct format.  (maybe doesn't matter)
     *
     * @return bool
     */
    private function checkTimestampFieldTypes()
    {
        // Check the data dictionary to make sure these 2 timestamp fields are validated properly
        if ((!empty($this->gcr_proj->metadata['reserved_ts'])) &&
            ($this->gcr_proj->metadata['reserved_ts']['element_validation_type'] != 'datetime_seconds_ymd')) {
            return false;
        }

        if (!empty($this->gcr_proj->metadata['claimed_ts']['element_validation_type']) &&
            ($this->gcr_proj->metadata['claimed_ts']['element_validation_type'] != 'datetime_seconds_ymd')) {
            return false;
        }

        return true;
    }

}