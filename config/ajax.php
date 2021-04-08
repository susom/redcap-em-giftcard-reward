<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

require_once $module->getModulePath() .  "util/GiftCardUtils.php";

use \Exception;

// HANDLE BUTTON ACTION
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    //$zip_loader = InsertInstrumentHelper::getInstance($module);
    //$module->emLog($_POST);
    $message = $delay = $callback = null;

    switch ($action) {
        case "insert_form":
            $module->emLog("In insert_form");
            /*
            $form = $_POST['form'];
            list($result, $message) = $module->insertForm($form);
\
            $module->emDebug("INSERT FORM", $result);
            $message = $result ? "$form Created!" : $message;
            */
            break;
        case "designate_event":
            $module->emLog("In designate_event");
            /*
            $module->emDebug("DESIGNATING EVENT");
            $form = $_POST['form'];
            $event = $_POST['event'];
            list($result, $message) = $module->designateEvent($form, $event);

            $module->emDebug("result",  $result);
            */

            break;
        case "getStatus":

            $raw = $_POST['raw'];
            $gclPid = $raw['gcr-pid'];
            $gclEventID = $raw['gcr-event-id'];
            unset($raw['gcr-pid'], $raw['gcr-event-id']);
            $data = \ExternalModules\ExternalModules::formatRawSettings($module->PREFIX, $module->getProjectId(), $raw);

            // At this point we have the settings in individual arrays for each value.  The equivalent to ->getProjectSettings();

            // For this module, we want the subsettings of 'instance' - the repeating block of config
            $instances = $module->parseSubsettingsFromSettings('rewards', $data);
            //$module->emDebug("formatted instances: ", $instances);

            // foreach ($instances as $k => $v) {
            //     foreach ($v as $key => $val) {
            //         $module->emDebug($key, $val);
            //     }
            // }
            // $module->emDebug("formatted settings: ", $data);

            try {
                list($resultLib, $messageLib) = verifyGiftCardRepo($gclPid, $gclEventID);
            } catch (Exception $ex) {
                $module->emError("Exception when verifying Gift Card library in verifyGiftCardRepo");
            }
            list($resultProj,$messageProj) = $module->verifyConfigs( $module->getProjectId(), $gclPid, $gclEventID, $instances );

            $result = true;
            $message = array();
            if (($resultLib === false) || ($resultProj === false)) {
                $result = false;
                //$message = array_merge($messageLib, $messageProj);
                if ($messageLib === null) {
                    $message = $messageProj;
                } else if ($messageProj === null) {
                    $message = $messageLib;
                } else {
                    $message = array_merge($messageLib, $messageProj);
                }
           }

            //$module->emLog("DONE WITH GET_STATUS", $action, $result, $message);
            break;

        case "checkForms":
            $module->emLog("In checkForms");
//            if (!$zip_loader->formExists('participant_info')) {
//                 $f_p_status = $zip_loader->insertParticipantInfoForm();
//            };
//            if (!$zip_loader->formExists('rsp_survey_metadata')) {
//                $f_m_status= $zip_loader->insertSurveyMetadataForm();
//            };
//
//            $status = $f_p_status && $f_m_status;
//
//            if ($f_p_status) {
//                $msg = "The participant_info form was succesfully uploaded";
//            } else {
//                $msg = "The attempt to upload participant_info failed.";
//            }
//
//            if ($status) {
//                $result = array(
//                    'result' => 'success',
//                    'message' =>
//                );
//            }

            break;
        case "test":
            $module->emLog("In test");


            // SAVE A CONFIGURATION
            $participant_config_id = $_POST['config_field'];


            // $module->debug($raw_config,"DEBUG","Raw Config");


            //if this were working, check that the fields don't already exist in file

        /**
            $p_status = $zip_loader->insertParticipantInfoForm();

            if (!$p_status) {
                //TODO
                $zip_loader->getErrors();
            }

            $m_status = $zip_loader->insertSurveyMetadataForm(); //todo: designate to event with config id
            if (!$m_status) {
                //TODO
                $zip_loader->getErrors();
            }

            //how to deal with designating for event

            $sub_settings = $module->getSubSettings('survey-portals');
            //$module->emDebug($sub_settings);

            foreach ($sub_settings as $sub) {
                //TODO: designate for each event

            }

            $test_error = "foo bar";

            $status = true;
            if ($status) {
                // SAVE
                $result = array(
                    'result' => 'success',
                    'message' => 'Please enable this new form in the event.'
                );
            } else {
                $test_error = 'not foobar';
            }
            $result = array(
                'result' => 'success',
                'message' => $test_error
            );
         */
    }

    header('Content-Type: application/json');
    echo json_encode(
        array(
            'result' => $result,
            'message' => $message,
            'callback' => $callback,
            'delay'=> $delay
        )
    );
    exit();

}
