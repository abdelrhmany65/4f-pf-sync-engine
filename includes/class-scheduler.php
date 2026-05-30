<?php
/**
 * Class FourF_PF_Scheduler
 * Smart scheduling engine: manages automatic sync every 2 hours.
 * Supports Action Scheduler (preferred) with a secure WP-Cron fallback.
 *
 * @package 4F-PF-Sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_Scheduler {

    private static $instance = null;
    private $hook_name = 'fourf_pf_cron_sync_event';
    private $group_name = '4f-pf-sync-group';

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Bind scheduled event to the main processor
        add_action( $this->hook_name, array( $this, 'execute_sync_job' ) );

        // Support secure manual AJAX trigger (async background processing)
        add_action( 'wp_ajax_fourf_pf_manual_trigger', array( $this, 'handle_manual_trigger' ) );

        // Register "every 2 hours" interval for WP-Cron fallback
        add_filter( 'cron_schedules', array( $this, 'add_custom_cron_intervals' ) );
    }

    /**
     * Add custom cron interval (every 2 hours) per the project brief.
     */
    public function add_custom_cron_intervals( $schedules ) {
        $schedules['every_two_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display'  => esc_html__( 'Every 2 Hours', '4f-pf-sync' ),
        );
        return $schedules;
    }

    /**
     * Register scheduling events (called during plugin activation).
     */
    public static function register_events() {
        $instance = self::get_instance();

        // Preferred: Action Scheduler for precise background execution
        if ( function_exists( 'as_schedule_recurring_action' ) ) {
            // Defensive check: prevent duplicate accumulated schedules
            if ( false === as_next_scheduled_action( $instance->hook_name ) ) {
                as_schedule_recurring_action(
                    time(),
                    2 * HOUR_IN_SECONDS,
                    $instance->hook_name,
                    array(),
                    $instance->group_name
                );
                FourF_PF_Logger::log( "Sync scheduled via Action Scheduler (preferred). Interval: every 2 hours.", 'SUCCESS' );
            }
        } else {
            // Automatic fallback: WP-Cron when Action Scheduler is unavailable
            if ( ! wp_next_scheduled( $instance->hook_name ) ) {
                wp_schedule_event( time(), 'every_two_hours', $instance->hook_name );
                FourF_PF_Logger::log( "Action Scheduler unavailable. Sync scheduled via WP-Cron. Interval: every 2 hours.", 'INFO' );
            }
        }
    }

    /**
     * Clear all scheduled events (called during plugin deactivation).
     */
    public static function clear_events() {
        $instance = self::get_instance();

        // Comprehensive cleanup of Action Scheduler actions to prevent table residue
        if ( function_exists( 'as_unschedule_all_actions' ) ) {
            as_unschedule_all_actions( $instance->hook_name, array(), $instance->group_name );
            FourF_PF_Logger::log( "Action Scheduler events unscheduled and tables cleaned.", 'WARNING' );
        }

        // Clean up WP-Cron fallback events
        $timestamp = wp_next_scheduled( $instance->hook_name );
        if ( $timestamp ) {
            wp_unschedule_event( $timestamp, $instance->hook_name );
            FourF_PF_Logger::log( "Orphaned WP-Cron events unscheduled successfully.", 'WARNING' );
        }
    }

    /**
     * Execute the actual sync job with architectural race condition protection.
     * Lock release is guaranteed via finally block to prevent permanent lock state.
     */
    public function execute_sync_job() {
        // Safety lock mechanism to prevent overlapping heavy processes
        if ( 'running' === get_transient( 'fourf_pf_sync_lock' ) ) {
            FourF_PF_Logger::log( "Sync engine is already running. Skipping current cycle to protect the server.", 'WARNING' );
            return;
        }

        // Acquire transient lock for 1 hour during intensive processing
        set_transient( 'fourf_pf_sync_lock', 'running', HOUR_IN_SECONDS );

        try {
            FourF_PF_Logger::log( "Starting scheduled sync job execution...", 'INFO' );

            // Invoke the core processing engine
            $processor = FourF_PF_Processor::get_instance();
            $processor->start_synchronization();

        } catch ( Exception $e ) {
            FourF_PF_Logger::log( "Critical error during scheduled sync execution: " . $e->getMessage(), 'ERROR' );
        } finally {
            // Guaranteed lock release — prevents permanent lock if a fatal error occurs
            // during image processing, post creation, taxonomy insertion, or sideload operations.
            delete_transient( 'fourf_pf_sync_lock' );
            FourF_PF_Logger::log( "Sync lock released.", 'INFO' );
        }
    }

    /**
     * Handle AJAX manual trigger using async background processing.
     * Instead of executing the sync inline (which blocks the request),
     * this enqueues an async Action Scheduler task and returns immediately.
     */
    public function handle_manual_trigger() {
        // Strict security: only allow full administrators
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'You do not have sufficient permissions to perform this action.', '4f-pf-sync' ) ), 403 );
        }

        // Verify nonce token to prevent CSRF attacks
        check_ajax_referer( 'fourf_pf_sync_nonce', 'nonce' );

        FourF_PF_Logger::log( "Manual sync cycle triggered by admin via AJAX.", 'INFO' );

        // Check if Action Scheduler is available for async processing
        if ( function_exists( 'as_enqueue_async_action' ) ) {
            // Prevent duplicate heavy sync executions: verify no identical running/pending task exists
            $existing_tasks = as_get_scheduled_actions(
                array(
                    'hook'   => $this->hook_name,
                    'status' => array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING ),
                    'group'  => $this->group_name,
                    'per_page' => 1,
                )
            );

            if ( ! empty( $existing_tasks ) ) {
                FourF_PF_Logger::log( "Async sync task already pending/running. Skipping duplicate enqueue.", 'WARNING' );
                wp_send_json_success( array(
                    'message' => __( 'A sync process is already pending or running. Monitor logs for progress.', '4f-pf-sync' )
                ) );
            }

            // Enqueue async background action — the AJAX request returns immediately
            as_enqueue_async_action( $this->hook_name, array(), $this->group_name );

            FourF_PF_Logger::log( "Async sync task enqueued via Action Scheduler.", 'INFO' );

            // Lightweight response — no blocking wait for sync completion
            wp_send_json_success( array(
                'message' => __( 'Sync process started in background. Monitor logs for progress.', '4f-pf-sync' )
            ) );
        } else {
            // Fallback: execute synchronously if Action Scheduler is unavailable
            FourF_PF_Logger::log( "Action Scheduler not available. Falling back to synchronous execution.", 'WARNING' );
            $this->execute_sync_job();

            wp_send_json_success( array(
                'message' => __( 'Manual sync completed successfully (synchronous fallback). Please review the logs for details.', '4f-pf-sync' )
            ) );
        }
    }
}
