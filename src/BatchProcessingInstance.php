<?php
namespace Stanford\GiftcardReward;

use \REDCap;
use \Exception;

class BatchProcessingInstance
{
    /** @var \Stanford\GiftcardReward\GiftcardReward $module */

    private $pid, $config, $config_event_id, $event_name, $config_name;
    private $field_display_list, $pk_field, $label, $message;
    private $reward_logic;

    public function __construct($pid, $config)
    {
        global $Proj, $module;

        // These are required gift card parameters in the gift card project
        $this->pid = $pid;
        $this->config   = $config;

        // Retrieve the config name and event name
        $this->config_name = $this->config['reward-title'];
        $this->reward_logic = $this->config['reward-logic'];
        $event_id = $this->config['reward-fk-event-id'];
        $this->config_event_id = $event_id;
        $this->event_name = REDCap::getEventNames(true, true, $this->config_event_id);

        // Make the config name something we can use as an id
        $this->label = str_replace(' ', '-', $this->config_name);

        // This is a comma separated list so put them into an array
        $batch_display_fields = $this->config['display-field-list'];
        $display_fields = explode(',', $batch_display_fields);

        // Make sure these fields reside in this event
        $forms_this_event  = $Proj->eventsForms[$event_id];

        $display_field_list = array();
        $this->pk_field = REDCap::getRecordIdField();
        $display_field_list[] = $this->pk_field;
        foreach ($display_fields as $field_name) {

            // Find the form where this field resides
            $form = $Proj->metadata[trim($field_name)]['form_name'];

            // If this field is in this event
            if (in_array($form, $forms_this_event)) {
                $display_field_list[] = trim($field_name);
            } else {
                $this->message = "Field trim($field_name) cannot be displayed because it is not in this event (event_id = $this->config_event_id)";
                $module->emError($this->message);
            }
        }

        $this->field_display_list = array_unique($display_field_list);
    }

    public function returnConfigHtml() {

        $records = $this->getRecordsReadyForGCs();
        return $this->formatRecordsToHtml($records);
    }

    private function formatRecordsToHtml($records) {
        global $module;

        // If there are no records to display for this config, skip it.
        if (empty($records)) {
            return null;
        } else {

            $html = '<div class="pl-lg-5 pt-lg-5">';
            $html .= $this->getTitle();
            $html .= '<table id="' . $this->label . '" colspan="' . count($this->field_display_list) . '">';
            $html .= '<tr>';
            foreach($this->field_display_list as $field_name) {
                $html .= '<th>' . $field_name . '</th>';
            }
            $html .= '</tr>';

            foreach ($records as $record) {
                $html .= $this->getRecordHtml($record);
            }

            $html .= '</table>';
            $html .= '</div>';
        }

        return $html;
    }

    private function getTitle() {

        global $module;

        $html = '<h4>' . $module->tt("batch_title") . ': <span class="config"> ' . $this->config_name . '</span></h4>
                 <span id="select_links_forms">
                    <a href="javascript:;" onclick="selectAllInConfig(\'' . $this->label . '\',true)" style="margin-right:10px;text-decoration:underline;">' . $module->tt("select_all") . '</a>|
                    <a href="javascript:;" onclick="selectAllInConfig(\'' . $this->label . '\',false)" style="margin-left:5px;text-decoration:underline;">' . $module->tt("deselect_all") . '</a>
                 </span>';

        return $html;
    }

    private function getRecordHtml($record) {

        $html = '<tr>';
        foreach ($this->field_display_list as $field_info) {
            if ($this->pk_field == $field_info) {
                $html .= '<td class="reclist">
                        <div>
                             <input type="checkbox" name="' . $this->label . '^records[]" value="' . $record[$this->pk_field] . '"/>
                                <span class="pl-1">' . $record[$this->pk_field] . '</span>
                        </div>
                     </td>';
            } else {
                $html .= '<td><div><span class="pl-1"> ' . $record[$field_info] . '</span></div></td>';
            }
        }

        $html .= '</tr>';

        return $html;
    }


    private function getRecordsReadyForGCs() {

        global $module;

        // Get the record_id and the fields that the user wants displayed
        $fields = array_merge(array($this->pk_field), $this->field_display_list);

        // Retrieve the list of records that fit our configuration criteria
        $params = array(
            'project_id'        => $this->pid,
            'return_format'     => 'json',
            'fields'            => $fields,
            'events'            => array($this->config_event_id),
            'filterLogic'       => $this->reward_logic,
            'exportAsLabels'    => true
        );
        $possible_records = REDCap::getData($params);
        $possible_record_array = json_decode($possible_records, true);

        // Retrieve the reward library project information
        $gcr_pid        = $module->getProjectSetting('gcr-pid');
        $gcr_event_id   = $module->getProjectSetting('gcr-event-id');
        $alert_email    = $module->getProjectSetting("alert-email");
        $cc_email       = $module->getProjectSetting("cc-email");

        // These are the records that meet the reward logic but run it through checkRewardStatus
        // to make sure it meets all other requirements that it is okay to send
        try {
           $gcInstance = new RewardInstance($module, $this->pid, $gcr_pid, $gcr_event_id, $alert_email, $cc_email, $this->config);
        } catch (Exception $ex) {
            $this->message .= "<br>Cannot create instance of class RewardInstance. Exception message: " . $ex->getMessage();
            $module->emError($this->message);
            return null;
        }

        // Once the reward instance is created, check to see if this record should receive an award.  If so, send it.
        $status = $gcInstance->verifyConfig();
        if ($status) {
            $needs_reward = array();
            foreach ($possible_record_array as $check_record) {

                    $status = $gcInstance->checkRewardStatus($check_record[$this->pk_field]);
                    if ($status) {
                        $needs_reward[] = $check_record;
                    }
            }

            $module->emDebug("Records that need batch processing rewards: " . json_encode($needs_reward));
            return $needs_reward;

        } else {
            $message = "[PID:" . $this->pid . "] Reward configuration " . $this->config_name . " is invalid so cannot evaluate for records!";
            $module->emError($message);
            return null;
        }

    }

}