<?php
/**
 * Created by PhpStorm.
 * User: jael
 * Date: 2019-03-01
 * Time: 12:27
 */

namespace Stanford\RepeatingSurveyPortal;

require_once 'InsertInstrumentHelper.php';

/** @var \Stanford\RepeatingSurveyPortal\RepeatingSurveyPortal $module */

//check for presence p info

//if NO
//present modal


//IF YES
// import form

//populate defaults


// HANDLE BUTTON ACTION
if (!empty($_POST['action'])) {
    $action = $_POST['action'];
    //$zip_loader = InsertInstrumentHelper::getInstance($module);
    $module->emDebug($_POST);
    $message = $delay = $callback = null;

    switch ($action) {
        case "insert_form":
            $form = $_POST['form'];
            list($result, $message) = $module->insertForm($form);


            $module->emDebug("INSERT FORM", $result);
            $message = $result ? "$form Created!" : $message;

            break;
        case "designate_event":
            $module->emDebug("DESIGNATING EVENT");
            $form = $_POST['form'];
            $event = $_POST['event'];
            list($result, $message) = $module->designateEvent($form, $event);

            $module->emDebug("result",  $result);


            break;
        case "getStatus":


            //does particiapnt form exist

            //does the rsp_metadata form exit

            //is
            list($result,$message) = $module->getConfigStatus();
            $module->emDebug("GET STATUS", $result, $message);


            //$result = array(1,2,3);
            break;
        case "checkForms":
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
