<?php
namespace Stanford\GiftcardReward;

use \Project;
use REDCap;

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
function verifyGiftCardRepo($gcr_pid, $gcr_event_id) {

    global $module;

    $message = '';
    $title = "<b>Gift Card Library:</b>";

    // Make sure entries were made for gift card library project
    if (empty($gcr_pid)) {
        $message = $title . "<li>Select a project</li>";
        return array(false, array($message));
    }

    // Setup the data dictionary so we can check for the requird fields
    $gcr_proj = new Project($gcr_pid);
    $gcr_proj->setRepeatingFormsEvents();

    // The event id was not given, go find it
    // Make sure we have a correct event_id in the gift card rewards project
    if (($gcr_proj->numEvents > 1) && empty($gcr_event_id)) {
        // If this project has more than 1 event, the event id must be specified
        $message = $title . "<li>This project $gcr_pid contains more than 1 event, you must specify which event to use:</li>";
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
            $message = $title . "<li>This event ID $gcr_event_id does not belong to project $gcr_pid</li>";
        }
    }

    // If we don't have a valid event_id for this project, we cannot continue.
    if (!empty($message)) {
        return array(false, array($message));
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
    $gcr_required_fields = getGiftCardLibraryFields();
    $fields_not_found = array_diff($gcr_required_fields, $all_event_fields);

    if (!empty($fields_not_found)) {
        $message = $title . "<li>Required fields not found in the Gift Card Rewards project $gcr_pid event id $gcr_event_id are: " . implode(',', $fields_not_found) . "</li>";
    }

    // Make sure this form is not a repeating form and not in a repeating event
    $repeat_forms = $gcr_proj->RepeatingFormsEvents[$gcr_event_id];
    if (!empty($repeat_forms)) {
        if ($repeat_forms == 'WHOLE') {
            $message = (empty($message) ? $title : $message) . "<li>The Gift Card Rewards instruments cannot be a repeating event for project $gcr_pid event id $gcr_event_id </li>";
        } else {
            $gcr_repeat_forms = array_keys($repeat_forms);
            $intersection = array_intersect($all_event_forms, $gcr_repeat_forms);
            if (!empty($intersection)) {
                $message = (empty($message) ? $title : $message) . "<li>The Gift Card Rewards instrument(s) " . implode(',', $intersection) . " cannot be a repeating form for project $gcr_pid event id $gcr_event_id</li>";
            }
        }
    }

    // Make sure the reserve and viewed timestamp are in 'datetime_seconds_ymd' format so we can successfully save
    $missing_timestamp_fields = array_intersect($fields_not_found, array('reserved_ts', 'claimed_ts'));
    if (empty($missing_timestamp_fields)) {
        if (($gcr_proj->metadata['reserved_ts']['element_validation_type'] != 'datetime_seconds_ymd') ||
            ($gcr_proj->metadata['claimed_ts']['element_validation_type'] != 'datetime_seconds_ymd')) {
            $message = (empty($message) ? $title : $message) . "<li>The timestamps in the Gift Card Library must be formatted as 'Y-M-D H:M:S'.</li>";
        }
    }

    if (empty($message)) {
        return array(true, null);
    } else {
        return array(false, array($message));
    }
}

/**
 * This is just a central repository for the gift card library required fields
 *
 * @return array - required fields in the gift card library project
 */
function getGiftCardLibraryFields() {

    // These are required fields for the gift card library project.  If any of these fields are not
    // present, we cannot continue.  We will give the option to upload a form with these fields.

    $gcr_required_fields = array('reward_id', 'egift_number', 'url', 'amount', 'status',
            'reward_hash', 'reward_name', 'reward_pid', 'reward_record',
            'reserved_ts', 'claimed_ts');

    return $gcr_required_fields;
}

