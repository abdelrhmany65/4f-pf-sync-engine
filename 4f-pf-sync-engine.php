<?php
/**
 * Plugin Name: 4F Realty - Property Finder Sync Engine
 * Plugin URI:  https://4frealty.com
 * Description: Robust, OOP-based synchronization engine for Property Finder API and WP Residence Theme.
 * Version:     1.0.0
 * Author:      Abdelrahman Yasser
 * Text Domain: 4f-pf-sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// Define constants and secure the log path programmatically against external access
define( 'FOURF_PF_SYNC_PATH', plugin_dir_path( __FILE__ ) );
define( 'FOURF_PF_SYNC_URL', plugin_dir_url( __FILE__ ) );
define( 'FOURF_PF_SYNC_VERSION', '1.0.0' );

// Dynamically resolve uploads path for cross-server compatibility
$upload_dir = wp_upload_dir();
define( 'FOURF_PF_SYNC_LOG_FILE', $upload_dir['basedir'] . '/4f-sync-logs/secure-log.php' );

define( 'FOURF_PF_HOUSE_ACCOUNT_OPTION', 'fourf_pf_house_account_agent_id' );

final class FourF_PF_Sync_Engine {

    private static $instance = null;

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies() {
        require_once FOURF_PF_SYNC_PATH . 'includes/class-logger.php';
        require_once FOURF_PF_SYNC_PATH . 'includes/class-api-client.php';
        require_once FOURF_PF_SYNC_PATH . 'includes/class-processor.php';
        require_once FOURF_PF_SYNC_PATH . 'includes/class-scheduler.php';

        if ( is_admin() ) {
            require_once FOURF_PF_SYNC_PATH . 'admin/class-admin-settings.php';
            require_once FOURF_PF_SYNC_PATH . 'tests/class-test-suite.php';
        }
    }

    private function init_hooks() {
        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        register_deactivation_hook( __FILE__, array( $this, 'deactivate' ) ); // Server protection on shutdown
        
        // Bootstrap the entire system once all plugins are loaded
        add_action( 'plugins_loaded', array( $this, 'bootstrap_engine' ) );
    }

    /**
     * Initialize and launch core plugin instances.
     */
    public function bootstrap_engine() {
        // Start the smart scheduling system
        FourF_PF_Scheduler::get_instance();
        
        // Initialize admin dashboard if inside WP Admin
        if ( is_admin() ) {
            new FourF_PF_Admin_Settings();
        }
    }

    public function activate() {
        // Create log directory and secure the log file against browser access
        if ( ! file_exists( FOURF_PF_SYNC_LOG_FILE ) ) {
            wp_mkdir_p( dirname( FOURF_PF_SYNC_LOG_FILE ) );
            // Inject PHP header to prevent external browsing of log data
            $secure_header = "<?php if(!defined('ABSPATH')){exit;} ?>\n--- Sync Engine Log Initialized ---\n";
            file_put_contents( FOURF_PF_SYNC_LOG_FILE, $secure_header );
        }
        
        // Auto-create the default fallback agent to bypass permission constraints
        $this->ensure_house_account_exists();

        // Preload Scheduler during activation to ensure filters register immediately
        if ( class_exists( 'FourF_PF_Scheduler' ) ) {
            $scheduler = FourF_PF_Scheduler::get_instance();
            // Manually apply the cron filter in the current activation request
            add_filter( 'cron_schedules', array( $scheduler, 'add_custom_cron_intervals' ) );
            FourF_PF_Scheduler::register_events();
        }

        // Database optimization: add index for remote identifier meta key
        // Improves lookup performance during synchronization cycles.
        $this->add_remote_id_index();
    }

    /**
     * Add a database index for the _4f_pf_remote_id meta key to optimize
     * lookup performance during delta synchronization cycles.
     * Uses meta_value(191) for utf8mb4 compatibility with long remote IDs.
     */
    private function add_remote_id_index() {
        global $wpdb;
        $meta_table = $wpdb->postmeta;
        $index_name = 'fourf_pf_remote_id_idx';

        // Check if the index already exists to avoid duplicate index errors.
        // Uses SHOW INDEX instead of information_schema.statistics because
        // some hosting providers restrict SELECT on information_schema.
        $index_exists = $wpdb->get_results( "SHOW INDEX FROM {$meta_table} WHERE Key_name = '{$index_name}'" );

        if ( empty( $index_exists ) ) {
            // Use meta_key(191), meta_value(191) for utf8mb4 compatibility.
            // The 191 prefix is the maximum indexable length for utf8mb4 columns
            // in InnoDB (767 bytes / 4 bytes per char = 191 chars).
            $wpdb->query( "CREATE INDEX {$index_name} ON {$meta_table} (meta_key(191), meta_value(191))" );
            FourF_PF_Logger::log( "Database index '{$index_name}' created on {$meta_table} for optimized remote_id lookups.", 'INFO' );
        }
    }

    public function deactivate() {
        // Immediately clean up cron events to prevent orphaned processes
        if ( class_exists( 'FourF_PF_Scheduler' ) ) {
            FourF_PF_Scheduler::clear_events();
        }
        // Release the transient lock for a clean start on next activation
        delete_transient( 'fourf_pf_sync_lock' );
    }

    private function ensure_house_account_exists() {
        $existing_agent_id = get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION );
        if ( $existing_agent_id && get_post_type( $existing_agent_id ) === 'estate_agent' ) {
            return;
        }

        // Use slug-based search instead of unsupported 'title' WP_Query parameter.
        // The 'title' parameter is not a valid WP_Query arg; using 'name' (slug) or 's' (search).
        // Slug: 'house-account' derived from sanitize_title( 'House Account' ).
        $agent_query = new WP_Query( array(
            'post_type'              => 'estate_agent',
            'name'                   => 'house-account',
            'post_status'            => 'any',
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ) );
        if ( $agent_query->have_posts() ) {
            $agent_post = $agent_query->posts[0];
            update_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, $agent_post->ID );
            return;
        }

        $agent_id = wp_insert_post( array(
            'post_title'    => 'House Account',
            'post_content'  => 'Default fallback account for synchronized properties without a designated agent.',
            'post_status'   => 'publish',
            'post_type'     => 'estate_agent',
        ) );

        if ( ! is_wp_error( $agent_id ) && $agent_id ) {
            update_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, $agent_id );
            update_post_meta( $agent_id, 'agent_email', get_bloginfo('admin_email') );
        }
    }
}

FourF_PF_Sync_Engine::get_instance();