<?php

namespace Stanford\GiftcardReward;;



class RewardInstance
{
    /** @var \Stanford\GiftcardReward\GiftcardReward $module */
    private $module;

    public $title, $logic, $fk_field, $fk_event_id, $amount,
        $email, $email_subject, $email_header, $email_verification,
        $email_verification_subject, $email_verification_header;




    public function __construct($module, $instance)
    {
        $this->module = $module;

        $this->title                        = $instance['reward-title'];
        $this->logic                        = $instance['reward-logic'];
        $this->fk_field                     = $instance['reward-fk-field'];
        $this->fk_event_id                  = $instance['reward-fk-event-id'];
        $this->amount                       = $instance['reward-amount'];
        $this->email                        = $instance['reward-email'];
        $this->email_subject                = $instance['reward-email-subject'];
        $this->email_header                 = $instance['reward-email-header'];
        $this->email_verification           = $instance['reward-email-verification'];
        $this->email_verification_subject   = $instance['reward-email-verification-subject'];
        $this->email_verification_header    = $instance['reward-email-verification-header'];

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