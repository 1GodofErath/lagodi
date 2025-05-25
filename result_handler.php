<?php
// Include the logger that writes result messages to a separate log file.
require_once __DIR__ . '/result_logger.php';

/**
 * Process and log the result of the unblocking operation.
 *
 * @param int $affectedRows The number of rows affected by the update query.
 */
function processResult($affectedRows) {
    if ($affectedRows > 0) {
        $message = "Розблоковано " . $affectedRows . " користувач(ів).";
        error_log($message);
        writeResultLog($message);
        echo $message;
    } else {
        $message = "Немає користувачів для розблокування.";
        error_log($message);
        writeResultLog($message);
        echo $message;
    }
}
?>