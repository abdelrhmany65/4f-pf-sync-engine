<?php
/**
 * Class FourF_PF_Admin_Settings
 * Manages the plugin admin dashboard, settings storage, secure manual trigger,
 * and the administrative log viewer (after the manner of WC_Logger).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_Admin_Settings {

    private $option_group = 'fourf_pf_settings_group';
    private $settings_page_hook = '';
    private $logs_page_hook = '';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_plugin_pages' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_assets' ) );

        // Handle log file download and clear actions
        add_action( 'admin_post_fourf_pf_download_log', array( $this, 'handle_download_log' ) );
        add_action( 'admin_post_fourf_pf_clear_log', array( $this, 'handle_clear_log' ) );
    }

    /**
     * Register two submenu pages under WP Residence "Properties":
     * 1. PF Sync Settings — API configuration and manual trigger
     * 2. PF Sync Logs — Administrative log viewer (after WC_Logger manner)
     */
    public function add_plugin_pages() {
        $this->settings_page_hook = add_submenu_page(
            'edit.php?post_type=estate_property',
            __( 'PF Sync Settings', '4f-pf-sync' ),
            __( 'PF Sync Settings', '4f-pf-sync' ),
            'manage_options',
            '4f-pf-sync-settings',
            array( $this, 'create_admin_page' )
        );

        $this->logs_page_hook = add_submenu_page(
            'edit.php?post_type=estate_property',
            __( 'PF Sync Logs', '4f-pf-sync' ),
            __( 'PF Sync Logs', '4f-pf-sync' ),
            'manage_options',
            '4f-pf-sync-logs',
            array( $this, 'create_logs_page' )
        );
    }

    /**
     * Register settings using the official WordPress Settings API.
     */
    public function register_settings() {
        register_setting( $this->option_group, 'fourf_pf_api_key', 'sanitize_text_field' );
        register_setting( $this->option_group, 'fourf_pf_api_url', 'esc_url_raw' );

        add_settings_section( 'fourf_pf_main_section', __( 'API Configuration', '4f-pf-sync' ), null, '4f-pf-sync-settings' );

        add_settings_field(
            'fourf_pf_api_key',
            __( 'Property Finder API Key', '4f-pf-sync' ),
            array( $this, 'api_key_callback' ),
            '4f-pf-sync-settings',
            'fourf_pf_main_section'
        );

        add_settings_field(
            'fourf_pf_api_url',
            __( 'API Endpoint URL', '4f-pf-sync' ),
            array( $this, 'api_url_callback' ),
            '4f-pf-sync-settings',
            'fourf_pf_main_section'
        );
    }

    public function api_key_callback() {
        $val = get_option( 'fourf_pf_api_key', '' );
        printf( '<input type="password" name="fourf_pf_api_key" value="%s" class="regular-text" placeholder="%s">', esc_attr( $val ), esc_attr__( 'Enter your API key', '4f-pf-sync' ) );
        echo '<p class="description">' . esc_html__( 'Enter the secure API key provided by the Property Finder platform.', '4f-pf-sync' ) . '</p>';
    }

    public function api_url_callback() {
        $val = get_option( 'fourf_pf_api_url', 'https://api.propertyfinder.com/v1/listings' );
        printf( '<input type="text" name="fourf_pf_api_url" value="%s" class="regular-text">', esc_url( $val ) );
    }

    /**
     * Conditionally enqueue admin assets to protect performance of other admin pages.
     */
    public function enqueue_admin_assets( $hook ) {
        // Defensive guard: only load on our plugin pages
        if ( ! in_array( $hook, array( $this->settings_page_hook, $this->logs_page_hook ), true ) ) return;

        wp_enqueue_script( 'fourf-pf-admin-js', FOURF_PF_SYNC_URL . 'admin/assets/admin.js', array( 'jquery' ), FOURF_PF_SYNC_VERSION, true );
        
        // Pass nonce and AJAX URL aligned with the Scheduler
        wp_localize_script( 'fourf-pf-admin-js', 'fourf_pf_vars', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'fourf_pf_sync_nonce' )
        ) );
    }

    /**
     * Render the admin settings view.
     */
    public function create_admin_page() {
        $house_account_id = get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-update" style="color: #0073aa; vertical-align: middle;"></span> 4F Realty - Property Finder Sync Engine</h1>
            <p class="description"><?php esc_html_e( 'Welcome to the smart sync engine management dashboard, built for WP Residence theme compatibility.', '4f-pf-sync' ); ?></p>
            <hr>

            <div class="notice notice-info" style="border-right-color: #00a0d2; border-left: none; padding: 12px;">
                <p style="margin: 0;"><strong><?php esc_html_e( 'Current System Status:', '4f-pf-sync' ); ?></strong>
                    <?php esc_html_e( 'House Account ID:', '4f-pf-sync' ); ?> <code><?php echo $house_account_id ? esc_html( $house_account_id ) : esc_html__( 'Not set', '4f-pf-sync' ); ?></code> |
                    <?php esc_html_e( 'Secure Log Path:', '4f-pf-sync' ); ?> <code>/wp-content/uploads/4f-sync-logs/secure-log.php</code>
                </p>
            </div>

            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px; border-radius: 4px;">
                <form method="post" action="options.php">
                    <?php
                    settings_fields( $this->option_group );
                    do_settings_sections( '4f-pf-sync-settings' );
                    submit_button( __( 'Save API Configurations', '4f-pf-sync' ) );
                    ?>
                </form>
            </div>

            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; margin-top: 20px; border-radius: 4px; border-top: 4px solid #ffb900;">
                <h2><?php esc_html_e( 'Manual Sync Trigger', '4f-pf-sync' ); ?></h2>
                <p><?php esc_html_e( 'Use this option to launch an immediate sync cycle for testing connectivity, data fetching, and image processing without waiting for the automatic schedule.', '4f-pf-sync' ); ?></p>
                
                <div style="margin: 15px 0;">
                    <button id="fourf-pf-sync-now" class="button button-secondary button-large" style="height: 40px; padding: 0 20px;">
                        <span class="dashicons dashicons-cloud-download" style="vertical-align: middle; margin-top: -4px;"></span> <?php esc_html_e( 'Run Sync Now', '4f-pf-sync' ); ?>
                    </button>
                </div>
                
                <div id="sync-status-msg" style="margin-top: 15px; padding: 10px; border-radius: 3px; display: none;"></div>
            </div>
        </div>
        <?php
    }

    /**
     * =====================================================================
     * ADMIN LOG VIEWER
     * Renders a paginated, filterable log viewer (after the manner of WC_Logger).
     * =====================================================================
     */
    public function create_logs_page() {
        $log_file = FOURF_PF_SYNC_LOG_FILE;
        $log_exists = file_exists( $log_file );
        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-list-view" style="color: #0073aa; vertical-align: middle;"></span> <?php esc_html_e( 'PF Sync Logs', '4f-pf-sync' ); ?></h1>
            <p class="description"><?php esc_html_e( 'View, filter, and manage synchronization logs. Logs are stored securely outside the web root.', '4f-pf-sync' ); ?></p>
            <hr>

            <div style="background: #fff; padding: 20px; border: 1px solid #ccd0d4; border-radius: 4px; margin-top: 20px;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <strong><?php esc_html_e( 'Log File:', '4f-pf-sync' ); ?></strong>
                        <code><?php echo esc_html( $log_file ); ?></code>
                        <span style="margin-left: 10px; color: <?php echo $log_exists ? '#46b450' : '#dc3232'; ?>;">
                            (<?php echo $log_exists ? esc_html__( 'Exists', '4f-pf-sync' ) : esc_html__( 'Not found', '4f-pf-sync' ); ?>
                            <?php
                            if ( $log_exists ) {
                                $size = filesize( $log_file );
                                echo ' | ' . esc_html( size_format( $size ) );
                            }
                            ?>)
                        </span>
                    </div>
                    <div>
                        <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=fourf_pf_download_log' ) ); ?>" class="button button-secondary">
                            <span class="dashicons dashicons-download" style="vertical-align: middle;"></span> <?php esc_html_e( 'Download Log', '4f-pf-sync' ); ?>
                        </a>
                        <a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=fourf_pf_clear_log' ), 'fourf_pf_clear_log_nonce' ) ); ?>" class="button button-secondary" style="border-color: #dc3232; color: #dc3232;"
                           onclick="return confirm('<?php esc_attr_e( 'Are you sure you want to clear all logs? This action cannot be undone.', '4f-pf-sync' ); ?>');">
                            <span class="dashicons dashicons-trash" style="vertical-align: middle;"></span> <?php esc_html_e( 'Clear Log', '4f-pf-sync' ); ?>
                        </a>
                    </div>
                </div>

                <?php if ( ! $log_exists ) : ?>
                    <div class="notice notice-warning inline">
                        <p><?php esc_html_e( 'The log file has not been created yet. Logs will appear here once the first sync cycle runs.', '4f-pf-sync' ); ?></p>
                    </div>
                <?php else : ?>
                    <?php
                    // Read log content — use tail to read only the last 1000 lines
                    // to prevent memory exhaustion on large log files.
                    // Falls back to file_get_contents() if shell_exec is unavailable.
                    $log_content = @shell_exec( "tail -n 1000 " . escapeshellarg( $log_file ) );
                    if ( ! $log_content ) {
                        $log_content = file_get_contents( $log_file );
                    }

                    // Filter by level if requested
                    // Note: Log levels are padded to 8 characters (e.g., [SUCCESS ], [INFO    ]),
                    // so we search for "[LEVEL" without the closing bracket to match both
                    // padded and unpadded formats.
                    $filter_level = isset( $_GET['log_level'] ) ? strtoupper( sanitize_text_field( $_GET['log_level'] ) ) : '';
                    if ( ! empty( $filter_level ) ) {
                        $lines = explode( "\n", $log_content );
                        $filtered = array();
                        foreach ( $lines as $line ) {
                            if ( strpos( $line, "[{$filter_level}" ) !== false ) {
                                $filtered[] = $line;
                            }
                        }
                        $log_content = implode( "\n", $filtered );
                    }

                    // Search filter
                    $search_term = isset( $_GET['log_search'] ) ? sanitize_text_field( $_GET['log_search'] ) : '';
                    if ( ! empty( $search_term ) ) {
                        $lines = explode( "\n", $log_content );
                        $filtered = array();
                        foreach ( $lines as $line ) {
                            if ( stripos( $line, $search_term ) !== false ) {
                                $filtered[] = $line;
                            }
                        }
                        $log_content = implode( "\n", $filtered );
                    }

                    // Pagination
                    $lines = explode( "\n", trim( $log_content ) );
                    $total_lines = count( $lines );
                    $per_page = 100;
                    $current_page = isset( $_GET['log_paged'] ) ? max( 1, intval( $_GET['log_paged'] ) ) : 1;
                    $total_pages = max( 1, ceil( $total_lines / $per_page ) );
                    $offset = ( $current_page - 1 ) * $per_page;
                    $page_lines = array_slice( $lines, $offset, $per_page );
                    ?>

                    <!-- Filter Controls -->
                    <form method="get" action="" style="margin-bottom: 15px; padding: 15px; background: #f0f6fa; border-radius: 4px;">
                        <input type="hidden" name="post_type" value="estate_property">
                        <input type="hidden" name="page" value="4f-pf-sync-logs">
                        
                        <div style="display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;">
                            <div>
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;"><?php esc_html_e( 'Filter by Level:', '4f-pf-sync' ); ?></label>
                                <select name="log_level" style="min-width: 150px;">
                                    <option value=""><?php esc_html_e( 'All Levels', '4f-pf-sync' ); ?></option>
                                    <option value="SUCCESS" <?php selected( $filter_level, 'SUCCESS' ); ?>>SUCCESS</option>
                                    <option value="INFO" <?php selected( $filter_level, 'INFO' ); ?>>INFO</option>
                                    <option value="WARNING" <?php selected( $filter_level, 'WARNING' ); ?>>WARNING</option>
                                    <option value="ERROR" <?php selected( $filter_level, 'ERROR' ); ?>>ERROR</option>
                                </select>
                            </div>
                            <div>
                                <label style="display: block; margin-bottom: 4px; font-weight: 600;"><?php esc_html_e( 'Search:', '4f-pf-sync' ); ?></label>
                                <input type="text" name="log_search" value="<?php echo esc_attr( $search_term ); ?>" placeholder="<?php esc_attr_e( 'Search logs...', '4f-pf-sync' ); ?>" style="min-width: 250px;">
                            </div>
                            <div>
                                <button type="submit" class="button"><?php esc_html_e( 'Filter', '4f-pf-sync' ); ?></button>
                                <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=estate_property&page=4f-pf-sync-logs' ) ); ?>" class="button button-secondary"><?php esc_html_e( 'Reset', '4f-pf-sync' ); ?></a>
                            </div>
                        </div>
                    </form>

                    <!-- Pagination Info -->
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span>
                            <strong><?php esc_html_e( 'Total Entries:', '4f-pf-sync' ); ?></strong> <?php echo intval( $total_lines ); ?>
                            <?php if ( ! empty( $filter_level ) || ! empty( $search_term ) ) : ?>
                                | <a href="<?php echo esc_url( admin_url( 'edit.php?post_type=estate_property&page=4f-pf-sync-logs' ) ); ?>"><?php esc_html_e( 'Clear filters', '4f-pf-sync' ); ?></a>
                            <?php endif; ?>
                        </span>
                        <span>
                            <?php echo esc_html( sprintf( __( 'Page %d of %d', '4f-pf-sync' ), $current_page, $total_pages ) ); ?>
                        </span>
                    </div>

                    <!-- Pagination Links -->
                    <div class="tablenav" style="margin-bottom: 10px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links( array(
                                'base'      => add_query_arg( 'log_paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'add_args'  => array_filter( array(
                                    'log_level' => $filter_level ?: null,
                                    'log_search' => $search_term ?: null,
                                ) ),
                            ) );
                            ?>
                        </div>
                    </div>

                    <!-- Log Content -->
                    <div style="background: #0f172a; color: #38bdf8; padding: 15px; border-radius: 4px; font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.5; max-height: 600px; overflow-y: auto; white-space: pre-wrap; word-break: break-all;">
                        <?php
                        if ( empty( $page_lines ) ) {
                            echo '<span style="color: #94a3b8;">' . esc_html__( 'No log entries match the current filter criteria.', '4f-pf-sync' ) . '</span>';
                        } else {
                            foreach ( $page_lines as $line ) {
                                $line_esc = esc_html( $line );
                                // Color-code log levels
                                $line_esc = preg_replace(
                                    '/\[(SUCCESS)\]/i',
                                    '<span style="color: #4ade80;">[$1]</span>',
                                    $line_esc
                                );
                                $line_esc = preg_replace(
                                    '/\[(INFO)\]/i',
                                    '<span style="color: #38bdf8;">[$1]</span>',
                                    $line_esc
                                );
                                $line_esc = preg_replace(
                                    '/\[(WARNING)\]/i',
                                    '<span style="color: #fbbf24;">[$1]</span>',
                                    $line_esc
                                );
                                $line_esc = preg_replace(
                                    '/\[(ERROR)\]/i',
                                    '<span style="color: #f43f5e;">[$1]</span>',
                                    $line_esc
                                );
                                echo $line_esc . "\n";
                            }
                        }
                        ?>
                    </div>

                    <!-- Bottom Pagination -->
                    <div class="tablenav" style="margin-top: 10px;">
                        <div class="tablenav-pages">
                            <?php
                            echo paginate_links( array(
                                'base'      => add_query_arg( 'log_paged', '%#%' ),
                                'format'    => '',
                                'prev_text' => '&laquo;',
                                'next_text' => '&raquo;',
                                'total'     => $total_pages,
                                'current'   => $current_page,
                                'add_args'  => array_filter( array(
                                    'log_level' => $filter_level ?: null,
                                    'log_search' => $search_term ?: null,
                                ) ),
                            ) );
                            ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Handle log file download.
     */
    public function handle_download_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', '4f-pf-sync' ) );
        }

        $log_file = FOURF_PF_SYNC_LOG_FILE;
        if ( ! file_exists( $log_file ) ) {
            wp_die( esc_html__( 'Log file not found.', '4f-pf-sync' ) );
        }

        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: text/plain' );
        header( 'Content-Disposition: attachment; filename="4f-pf-sync-' . date( 'Y-m-d' ) . '.log"' );
        header( 'Content-Length: ' . filesize( $log_file ) );
        readfile( $log_file );
        exit;
    }

    /**
     * Handle log file clearing.
     */
    public function handle_clear_log() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have sufficient permissions.', '4f-pf-sync' ) );
        }

        check_admin_referer( 'fourf_pf_clear_log_nonce' );

        $log_file = FOURF_PF_SYNC_LOG_FILE;
        if ( file_exists( $log_file ) ) {
            // Preserve the PHP security header
            $secure_header = "<?php if(!defined('ABSPATH')){exit;} ?>\n--- Sync Engine Log Initialized (Cleared) ---\n";
            file_put_contents( $log_file, $secure_header );
            FourF_PF_Logger::log( 'Log file cleared by admin via Log Viewer.', 'WARNING' );
        }

        wp_safe_redirect( add_query_arg( array(
            'post_type' => 'estate_property',
            'page'      => '4f-pf-sync-logs',
            'cleared'   => '1',
        ), admin_url( 'edit.php' ) ) );
        exit;
    }
}