<?php
/**
 * Write a result message to a dedicated log file.
 *
 * @param string $message The message to log.
 */
function writeResultLog($message) {
    $logFile = __DIR__ . '/auto_unblock_result.log';
    $formattedMessage = date('Y-m-d H:i:s') . " - " . $message . PHP_EOL;
    // Append the message to the log file.
    file_put_contents($logFile, $formattedMessage, FILE_APPEND);
}
?>