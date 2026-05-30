<?php
/**
 * Class FourF_PF_Logger
 * Secure logging system with padded levels and protected output.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_Logger {
    
    /**
     * Write a timestamped, level-padded message to the secure log file.
     */
    public static function log( $message, $level = 'INFO' ) {
        $time = current_time( 'mysql' );
        
        // Pad log levels to 8 chars for aligned, human-readable output
        $level_padded = str_pad( strtoupper( $level ), 8 );
        
        $formatted_message = sprintf( "[%s] [%s] - %s%s", $time, $level_padded, $message, PHP_EOL );
        
        // FILE_APPEND preserves the PHP security header written during activation
        error_log( $formatted_message, 3, FOURF_PF_SYNC_LOG_FILE );
    }
}