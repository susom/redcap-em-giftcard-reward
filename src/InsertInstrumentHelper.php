<?php

namespace Stanford\GiftcardReward;

use \MetaData;
use \ZipArchive;


/**
 * Class InsertInstrumentHelper
 * call the insertParticipantInfoForm() method - if it returns false, then you can call getErrors to see what went wrong
 */
class InsertInstrumentHelper
{

    use emLoggerTrait;

    private $module;

    public  $errors = array();
    private $dd_array = array();
    private $status;                // 0 = dev, 1 = prod
    private $current_metadata;      // this will depend on dev or prod mode

    const ZIP_PREFIX = "docs/";

    public function __construct(GiftcardReward $module)
    {
        $this->module = $module;
        global $Proj;
        $this->status = $Proj->project['status'];
        $this->current_metadata = ($this->status == 0 ? $Proj->metadata : $Proj->metadata_temp);
    }

    /**
     * Try to insert the entered form
     * @return bool     Returns false if there was an error, call getErrors to see details
     */
    public function insertForm($form)
    {

        $zipFile = $this->module->getModulePath() . self::ZIP_PREFIX . $form . ".zip";
        $this->emDebug($form, $zipFile);
        if (!$this->loadZipFile($zipFile))  return false;
        if (!$this->verifyFields()) return false;
        if (!$this->verifyForms())  return false;
        if (!$this->saveMetadata()) return false;
        return true;
    }


    private function loadZipFile($zipFile)
    {
        //$zipFile = $this->module->getModulePath() . self::ZIP_PATH;

        $zip = new ZipArchive;
        $res = $zip->open($zipFile);
        if ($res !== TRUE) {
            return $this->addError("Unable to open");
        }

        $instrumentDD = $zip->getFromName('instrument.csv');
        if ($instrumentDD === false) {
            return $this->addError("Unable to get instrument.csv");
        }

        // Create a temp file for the zip contents
        $project_id = $this->module->getProjectId();
        $dd_filename = APP_PATH_TEMP . date('YmdHis') . '_instrumentdd_' . $project_id . '_' . substr(sha1(rand()), 0, 6) . '.csv';
        file_put_contents($dd_filename, $instrumentDD);

        // Parse DD
        $this->dd_array = \Design::excel_to_array($dd_filename);

        // Get rid of temp file
        unlink($dd_filename);

        if ($this->dd_array === false || $this->dd_array == "") {
            return $this->addError('Unable to parse file');
        }

        return true;
    }

    public function designateFormInEvent($form, $event) {

        if ($this->formDesignatedInEvent($form,$event)) {
            $this->addError("Form $form already enabled in event $event");
            return false;
        }

        $sql = sprintf("insert into redcap_events_forms (event_id, form_name) values (%d, '%s')",
            db_escape($event),
            db_escape($form)
        );

        $result = db_query($sql);
        //$this->emDebug($sql, $result);
        return $result;
    }

    public function formExists($formName) {
        global $Proj;

        //don't rely on tne object stored data as they could be stale
        //actually the form list is stale. $Proj-forms, $Proj->metadata, is stale, so resorting to sql query

        $forms = $Proj->forms;

//        $this->emDebug("FORMS",$prod, $forms, $formName,array_keys($forms),array_key_exists($formName, $forms));
        $form_exists =  array_key_exists($formName, $forms);

        //resorting to database query to get accurate state of installed forms
        $sql = sprintf(
            "select count(*) from redcap_metadata where project_id = %d and form_name = '%s';",
            $Proj->project_id,
            $formName);
        $result = db_result(db_query($sql),0);

        //$this->emDebug($sql, $result);

        return $result;
    }

    public function formDesignatedInEvent($formName, $event) {

        $event_names = \REDCap::getEventNames();

        //$this->emDebug($event_names);

        $sql = sprintf(
            "select count(*) from redcap_events_forms where form_name = '%s'  and event_id in (%s);",
            $formName,
            $event);
            //implode(",",array_keys($event_names)));

        $result = db_result(db_query($sql),0);

        //$this->emDebug($sql, $result);

        return $result;


    }



    private function verifyForms() {
        // Find any variables that are duplicated in the DD
        $existingForms = array();
        foreach ($this->current_metadata as $fields => $metadata) {
            $form = $metadata['form_name'];
            if (!isset($existingForms[$form])) $existingForms[] = $form;
        }

        $newForms = array_unique($this->dd_array['B']);

        $dupForms = array();
        foreach ($newForms as $newForm) {
            if (in_array($newForm, $existingForms)) {
                $dupForms[] = $newForm;
            }
        }
        return empty($dupForms) ? true : $this->addError( "Form(s): " . implode(",",$dupForms) . " already exist!");
    }


    private function verifyFields() {
        $existingFields = array_keys($this->current_metadata);

        $newFields = $this->dd_array['A'];

        $dupFields = array();
        foreach ($newFields as $newField) {
            if (in_array($newField, $existingFields)) {
                $dupFields[] = $newField;
            }
        }

        $this->emDebug("String", FALSE);

        return empty($dupFields) ? true : $this->addError( "Fields(s): " . implode(",",$dupFields) . " already exist!");
    }


    private function saveMetadata() {

        db_query("SET AUTOCOMMIT=0");
        db_query("BEGIN");

        // Save data dictionary in metadata table
        $sql_errors = MetaData::save_metadata($this->dd_array, true);

        if (count($sql_errors) > 0) {
            $this->emDebug("ERRORS", $sql_errors);
            // ERRORS OCCURRED, so undo any changes made
            db_query("ROLLBACK");
            // Set back to previous value
            db_query("SET AUTOCOMMIT=1");
            // Display error messages
            if ($this->status == 0) {
                $this->addError("Unable to save: " . json_encode($sql_errors));
            } else {
                $this->addError("Unable to save - if this is a production project please enter draft mode first");
            }
            return false;
        } else {
            $this->emDebug("SUCCESS");
            // COMMIT CHANGES
            db_query("COMMIT");
            // Set back to previous value
            db_query("SET AUTOCOMMIT=1");
        }
        return true;
    }


    // Make an emDebug method inside this child class
    private function emDebug() {
       call_user_func_array(array($this->module, "emDebug"), func_get_args());
    }

    // Build an array of errors
    private function addError($error) {
        $this->errors[] = $error;
        return false;
    }

    // Return the errors
    public function getErrors() {
        return $this->errors;
    }
}