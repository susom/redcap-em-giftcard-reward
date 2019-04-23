<?php
namespace Stanford\GiftcardReward;

/**
 * This trait can be use with External Modules to enable the Stanford emLogger module
 *
 * To use this on your project, simply add this trait to your EM project.  In your main EM class add:
 * include "emLoggerTrait.php" // before the class is defined.
 * use emLoggerTrait;          // inside the class

 * INSERT THE FOLLOWING INTO THE CONFIG.JSON

  "system-settings": [
    {
      "key": "enable-system-debug-logging",
      "name": "<b>Enable Debug Logging (system-wide)</b><i>(Requires emLogger)</i>",
      "required": false,
      "type": "checkbox"
    }
  ],

  "project-settings": [
    {
      "key": "enable-project-debug-logging",
      "name": "<b>Enable Debug Logging</b><i>(Requires emLogger)</i>",
      "required": false,
      "type": "checkbox"
    }
   ],


 */
trait emLoggerTrait
{

    /**
     * Obtain an instance of emLogger or false if not installed / active
     * @return bool|mixed
     */
    function emLoggerInstance() {
        try {
            $emLogger = \ExternalModules\ExternalModules::getModuleInstance('em_logger');
            return $emLogger;
        } catch (\Exception $e) {
            // EmLogger Not Installed
            return false;
        }
    }

    /**
     * Determine if we are in debug mode either on system or project level
     * @return bool
     */
    function emLoggerDebugMode() {
        $systemDebug =  $this->getSystemSetting('enable-system-debug-logging');
        $projectDebug = !empty($_GET['pid']) && $this->getProjectSetting('enable-project-debug-logging');
        return $systemDebug || $projectDebug;
    }

    /**
     * Do the logging
     * The reason we broke it into three functions was to reduce complexity with backtrace and the calling function
     */
    function emLog() {
        if ($emLogger = $this->emLoggerInstance()) $emLogger->emLog($this->PREFIX, func_get_args(), "INFO");
    }

    function emDebug() {
        if ( $this->emLoggerDebugMode() && ($emLogger = $this->emLoggerInstance()) ) $emLogger->emLog($this->PREFIX, func_get_args(), "DEBUG");
    }

    function emError() {
        if ($emLogger = $this->emLoggerInstance()) $emLogger->emLog($this->PREFIX, func_get_args(), "ERROR");
    }
}