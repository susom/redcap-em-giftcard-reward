<?php

namespace Stanford\GiftcardReward;

use ExternalModules\ExternalModules;
use \REDCap;

require_once "emLoggerTrait.php";
require_once "src/InsertInstrumentHelper.php";

/**
 * Class GiftcardReward
 * @package Stanford\GiftcardReward
 */
class GiftcardReward extends \ExternalModules\AbstractExternalModule
{

    use emLoggerTrait;


    /*******************************************************************************************************************/
    /* HOOK METHODS                                                                                                    */
    /***************************************************************************************************************** */


    public function redcap_module_system_enable() {
        $this->emDebug(__METHOD__);
    }


    // SAVE CONFIG HOOK
    public function redcap_module_save_configuration($project_id) {
        $this->emDebug(__METHOD__);
    }



    public function redcap_module_link_check_display($project_id, $link) {
        // TODO: Loop through each portal config that is enabled and see if they are all valid.
        //TODO: ask andy123; i'm not sure what KEY_VALID_CONFIGURATION is for...
        //if ($this->getSystemSetting(self::KEY_VALID_CONFIGURATION) == 1) {
        list($result, $message)  = $this->getConfigStatus();
        if ($result === true) {
                    // Do nothing - no need to show the link
        } else {
            $link['icon'] = "exclamation";
        }
        return $link;
    }



     /**
     * @param $project_id
     * @param null $record
     * @param $instrument
     */
    public function redcap_save_record($project_id, $record = NULL,  $instrument,  $event_id,  $group_id = NULL,  $survey_hash = NULL,  $response_id = NULL, $repeat_instance) {
        // TODO: This is where the magic happens!
    }



    /******************************************************************************************************************/
    /*  METHODS                                                                                                       */
    /******************************************************************************************************************/

    public function getConfigStatus() {

        $iih = new InsertInstrumentHelper($this);

        $alerts = array();
        $result = false;

        $main_events = $this->getProjectSetting('main-config-event-name');

        $survey_events = $this->getProjectSetting('survey-event-name');
        //$this->emDebug("SURVEY",$survey_events);

        if (!$iih->formExists(self::PARTICIPANT_INFO_FORM)) {
            $p = "<b>Participant Info form has not yet been created. </b> 
              <div class='btn btn-xs btn-primary float-right' data-action='insert_form' data-form='" . self::PARTICIPANT_INFO_FORM ."'>Create Form</div>";
            $alerts[] = $p;
        } else {
            // Form exists - check if enabled on event
            foreach ($main_events as $sub => $event) {
                if (isset($event)) {
                    if (!$iih->formDesignatedInEvent(self::PARTICIPANT_INFO_FORM, $event)) {
                        $event_name = REDCap::getEventNames(false, true, $event);
                        $pe = "<b>Participant Info form has not been designated to the event selected for the main event: <br>".$event_name.
                            " </b><div class='btn btn-xs btn-primary float-right' data-action='designate_event' data-event='".$event.
                            "' data-form='".self::PARTICIPANT_INFO_FORM."'>Designate Form</div>";
                        $alerts[] = $pe;
                    }
                }
            }
        }

        if (!$iih->formExists(self::SURVEY_METADATA_FORM)) {
            $s=  "<b>Survey Info form has not yet been created. </b> 
              <div class='btn btn-xs btn-primary float-right' data-action='insert_form' data-form='" . self::SURVEY_METADATA_FORM . "'>Create Form</div>";
            $alerts[] = $s;
        } else {
            foreach ($survey_events as $sub => $event) {
                if (isset($event)) {
                    if (!$iih->formDesignatedInEvent(self::SURVEY_METADATA_FORM, $event)) {
                        $event_name = REDCap::getEventNames(false, true, $event);
                        $se = "<b>Survey Metadata form has not been designated to the event selected for the survey event: <br>".$event_name.
                            " </b><div class='btn btn-xs btn-primary float-right' data-action='designate_event' data-event='".$event.
                            "' data-form='".self::SURVEY_METADATA_FORM."'>Designate Form</div>";
                        $alerts[] = $se;
                    }
                }
            }
        }

        //$this->emDebug($alerts);

        if (empty($alerts)) {
            $result = true;
            $alerts[] = "Your configuration appears valid!";
        }

        return array( $result, $alerts );
    }

    public function insertForm($form) {
        $iih = new InsertInstrumentHelper($this);

        $result = $iih->insertForm($form);
        $message = $iih->getErrors();

        $this->emDebug("RETURN STATUS", $result, $message);

        return array($result, $message);

    }


    public function designateEvent($form, $event) {
        $iih = new InsertInstrumentHelper($this);

        $this->emDebug("DESIGNATING EVENT: ". $form . $event);
        $result = $iih->designateFormInEvent($form, $event);
        $message = $iih->getErrors();

        $this->emDebug("RETURN STATUS", $result, $message);

        return array($result, $message);

    }

    /******************************************************************************************************************/
    /* HELPER METHODS                                                                                                 */
    /******************************************************************************************************************/

    /**
     * @param $input    A string like 1,2,3-55,44,67
     * @return mixed    An array with each number enumerated out [1,2,3,4,5,...]
     */
    static function parseRangeString($input) {
        $input = preg_replace('/\s+/', '', $input);
        $string = preg_replace_callback('/(\d+)-(\d+)/', function ($m) {
            return implode(',', range($m[1], $m[2]));
        }, $input);
        $array = explode(",",$string);
        return empty($array) ? false : $array;
    }

}