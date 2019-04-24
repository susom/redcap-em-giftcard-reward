<?php

namespace Stanford\GiftcardReward;;



class RewardInstance
{
    /** @var \Stanford\Summarize\Summarize $module */
    private $module;



    public function __construct($module, $instance)
    {
        $this->module = $module;

        // $this->include_forms     = $this->parseConfigList( $instance['include_forms']  );
        // $this->include_fields    = $this->parseConfigList( $instance['include_fields'] );
        // $this->exclude_fields    = $this->parseConfigList( $instance['exclude_fields'] );
        // $this->event_id          = $instance['event_id'];
        // $this->destination_field = $instance['destination_field'];
        // $this->title             = $instance['title'];
        //
        // global $Proj;
        // $this->Proj = $Proj;
        // $this->Proj->setRepeatingFormsEvents();
        //
        // $this->getAllFields();
        // $module->emDebug("Forms", $this->include_forms);

    }


    /**
     * Verify the configuration and return an array of ($result, $data)
     * where $result = true/false
     * and $data is an array of errors
     */
    public function verifyConfig() {
        //TODO:
        return array(true, null);
    }

}