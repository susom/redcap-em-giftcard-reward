<?php
namespace Stanford\GiftcardReward;
/** @var \Stanford\GiftcardReward\GiftcardReward $module */

use \Exception;
use \REDCap;

// HANDLE BUTTON ACTION
if (!empty($_POST['action'])) {

    $action = filter_var($_POST['action'], FILTER_SANITIZE_STRING);
    //$zip_loader = InsertInstrumentHelper::getInstance($module);
    //$module->emLog($_POST);
    $message = $delay = $callback = null;

    switch ($action) {
        case "insert_form":
            $module->emLog('In insert_form');
            break;
        case "designate_event":
            $module->emLog('In designate_event');
            break;
        case "getStatus":

            $post_clean = filter_var_array($_POST, FILTER_SANITIZE_STRING);
            $raw            = $post_clean['raw'];
            $gcrPid         = filter_var($raw['gcr-pid'], FILTER_SANITIZE_NUMBER_INT);
            $gcrEventID     = filter_var($raw['gcr-event-id'], FILTER_SANITIZE_NUMBER_INT);
            unset($raw['gcr-pid'], $raw['gcr-event-id']);
            $data = \ExternalModules\ExternalModules::formatRawSettings($module->PREFIX, $module->getProjectId(), $raw);

            // At this point we have the settings in individual arrays for each value.  The equivalent to ->getProjectSettings();

            // For this module, we want the subsettings of 'instance' - the repeating block of config
            $instances = $module->parseSubsettingsFromSettings('rewards', $data);
            try {
                $gclib = new VerifyLibraryClass($gcrPid, $gcrEventID, $module);
                [$resultLib, $messageLib] = $gclib->verifyLibraryConfig();

            } catch (Exception $ex) {
                $module->emError('Exception when verifying Gift Card library in verifyGiftCardRepo');
            }
            [$resultProj,$messageProj] = $module->verifyEMConfigs( $module->getProjectId(), $gcrPid, $gcrEventID, $instances );

            $result = true;
            $message = array();
            if (($resultLib === false) || ($resultProj === false)) {
                $result = false;

                if ($messageLib === null) {
                    $message = $messageProj;
                } else if ($messageProj === null) {
                    $message = $messageLib;
                } else {
                    $message = array_merge($messageLib, $messageProj);
                }
           }

            break;

        case "checkForms":
            $module->emLog('In checkForms');

            break;
        case "test":
            $module->emLog('In test');

    }

    header('Content-Type: application/json');
    echo json_encode(
        filter_var_array ( array(
            'result' => $result,
            'message' => $message,
            'callback' => $callback,
            'delay'=> $delay
        ), FILTER_SANITIZE_STRING)
    );
    exit();

}
