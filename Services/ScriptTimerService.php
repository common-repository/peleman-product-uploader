<?php

namespace PelemanProductUploader\Services;

/**
 * Utility class for logging function execution times
 * 
 * Starts & stops a timer and logs the result
 */
class ScriptTimerService
{
    private $startTime;
    private $endTime;
    private $runTime;

    public function __construct()
    {
        $this->startTime = microtime(true);
    }

    /**
     * Stops a timer
     */
    public function stopTimer()
    {
        $this->endTime = microtime(true);
    }

    /**
     * Stops a timer and logs the result
     * 
     * @param string $functionName The name of the executing function (__FUNCTION__)
     * @param string $currentDirectory The directory of the executing function (__DIR__)
     */
    public function stopAndLogDuration($functionName, $currentDirectory = __DIR__)
    {
        $this->stopTimer();
        $this->runTime = round(($this->endTime - $this->startTime), 3);
        $this->logDuration($functionName, $currentDirectory);
    }

    /**
     * Logs the result of the timer
     * 
     * @param string $functionName The name of the executing function (__FUNCTION__)
     * @param string $currentDirectory The directory of the executing function (__DIR__)
     */
    public function logDuration($functionName, $currentDirectory = __DIR__)
    {
        $dateTime = new \DateTime;
        $dateTime->format('Y-m-d H:i:s');
        $logMessage = $dateTime->format('Y-m-d H:i:s') . " - execution time for $functionName: " . $this->runTime . ' seconds';
        $logLocation = $currentDirectory . '/scriptRunTimeLog.txt';

        error_log(print_r($logMessage, true) . PHP_EOL, 3, $logLocation);
    }
}
