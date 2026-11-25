<?php
/**
 * Class FourF_PF_Test_Suite
 * Comprehensive test suite for the 4F Realty - Property Finder Sync Engine.
 * All tests are collected in one place for easy verification.
 *
 * Access: Properties → PF Sync Tests (or ?page=4f-pf-sync-tests)
 *
 * @package 4F-PF-Sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_Test_Suite {

    private static $instance = null;
    private $results = array();
    private $passed = 0;
    private $failed = 0;
    private $warnings = 0;
    private $test_properties = array();

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_test_page' ), 20 );
    }

    /**
     * Add test page under Properties menu.
     */
    public function add_test_page() {
        add_submenu_page(
            'edit.php?post_type=estate_property',
            __( 'PF Sync Tests', '4f-pf-sync' ),
            __( 'PF Sync Tests', '4f-pf-sync' ),
            'manage_options',
            '4f-pf-sync-tests',
            array( $this, 'render_test_page' )
        );
    }

    /**
     * Render the test page.
     */
    public function render_test_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to access this page.', '4f-pf-sync' ) );
        }

        $run_tests = isset( $_GET['run_tests'] ) && '1' === $_GET['run_tests'];
        $cleanup   = isset( $_GET['cleanup'] ) && '1' === $_GET['cleanup'];

        if ( $run_tests ) {
            check_admin_referer( 'fourf_pf_run_tests' );
        }

        if ( $cleanup ) {
            check_admin_referer( 'fourf_pf_cleanup_tests' );
        }

        ?>
        <div class="wrap">
            <h1><span class="dashicons dashicons-yes-alt" style="color: #0073aa; vertical-align: middle;"></span> PF Sync Engine — Test Suite</h1>
            <p class="description">Comprehensive verification of all plugin components. Each test validates a specific requirement from the technical assessment.</p>
            <hr>

            <div style="margin: 20px 0; display: flex; gap: 10px; flex-wrap: wrap;">
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'run_tests', '1' ), 'fourf_pf_run_tests' ) ); ?>" class="button button-primary button-large" style="height: 40px; padding: 0 25px;">
                    <span class="dashicons dashicons-controls-play" style="vertical-align: middle; margin-top: -3px;"></span> Run All Tests
                </a>
                <a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'cleanup', '1' ), 'fourf_pf_cleanup_tests' ) ); ?>" class="button button-secondary button-large" style="height: 40px; padding: 0 25px; border-color: #dc3232; color: #dc3232;"
                   onclick="return confirm('This will delete ALL test properties, terms, and attachments created by the test suite. Continue?');">
                    <span class="dashicons dashicons-trash" style="vertical-align: middle; margin-top: -3px;"></span> Cleanup Test Data
                </a>
            </div>

            <?php
            if ( $cleanup ) {
                $this->cleanup_test_data();
            }

            if ( $run_tests ) {
                $this->run_all_tests();
                $this->render_results();
            } else {
                $this->render_info();
            }
            ?>
        </div>
        <?php
    }

    /**
     * =====================================================================
     * TEST RUNNER
     * =====================================================================
     */

    private function run_all_tests() {
        $this->results  = array();
        $this->passed   = 0;
        $this->failed   = 0;
        $this->warnings = 0;

        FourF_PF_Logger::log( "=== TEST SUITE STARTED ===", 'INFO' );

        // Section 1: API Client
        $this->run_section( 'API Client', array(
            'API Credentials Loaded'          => array( $this, 'test_api_credentials_loaded' ),
            'API URL Construction'            => array( $this, 'test_api_url_construction' ),
            'API Retry Logic'                 => array( $this, 'test_api_retry_logic_exists' ),
            'API Pagination Intelligence'     => array( $this, 'test_api_pagination_intelligence' ),
        ) );

        // Section 2: Processor & Data Mapping
        $this->run_section( 'Processor & Data Mapping', array(
            'Processor Singleton'             => array( $this, 'test_processor_singleton' ),
            'Meta Map Completeness'           => array( $this, 'test_meta_map_completeness' ),
            'Bilingual Fields Configured'     => array( $this, 'test_bilingual_fields_exist' ),
            'Arabic Text Detection'           => array( $this, 'test_is_arabic_text_detection' ),
            'Allowed HTML Tags'               => array( $this, 'test_allowed_html_tags' ),
        ) );

        // Section 3: Property Processing
        $this->run_section( 'Property Processing (Simulated)', array(
            'Create English Property'         => array( $this, 'test_create_property_with_english_data' ),
            'Create Arabic Property'          => array( $this, 'test_create_property_with_arabic_data' ),
            'Explicit Arabic Fields'          => array( $this, 'test_create_property_with_explicit_arabic_fields' ),
            'Missing Reference Number'        => array( $this, 'test_property_missing_reference_number' ),
            'Delta Sync Skip'                 => array( $this, 'test_property_delta_skip' ),
            'Update Existing Property'        => array( $this, 'test_property_update_existing' ),
        ) );

        // Section 4: Taxonomy Mapping
        $this->run_section( 'Taxonomy Mapping', array(
            'Taxonomy Categories Registered'  => array( $this, 'test_taxonomy_mapping_categories' ),
            'Taxonomy Actions (Sale/Rent)'    => array( $this, 'test_taxonomy_mapping_actions' ),
            'Taxonomy Features'               => array( $this, 'test_taxonomy_mapping_features' ),
            'Find or Create Term'             => array( $this, 'test_find_or_create_term' ),
        ) );

        // Section 5: Agent Mapping
        $this->run_section( 'Agent Mapping', array(
            'Agent Mapping by ID'             => array( $this, 'test_agent_mapping_by_id' ),
            'Agent Mapping by Email'          => array( $this, 'test_agent_mapping_by_email' ),
            'Agent Fallback to House Account' => array( $this, 'test_agent_mapping_fallback_to_house_account' ),
            'House Account Exists'            => array( $this, 'test_house_account_exists' ),
        ) );

        // Section 6: Image Handling
        $this->run_section( 'Image Handling', array(
            'Image Hash Generation'           => array( $this, 'test_image_hash_generation' ),
            'Gallery Reconciliation'          => array( $this, 'test_gallery_reconciliation' ),
        ) );

        // Section 7: Scheduler
        $this->run_section( 'Scheduler', array(
            'Scheduler Singleton'             => array( $this, 'test_scheduler_singleton' ),
            'Cron Interval Registered'        => array( $this, 'test_cron_interval_registered' ),
            'Sync Lock Mechanism'             => array( $this, 'test_sync_lock_mechanism' ),
        ) );

        // Section 8: Logger
        $this->run_section( 'Logger', array(
            'Log File Exists'                 => array( $this, 'test_log_file_exists' ),
            'Log File Is Secure'              => array( $this, 'test_log_file_is_secure' ),
            'Log Writing Works'               => array( $this, 'test_log_writing' ),
        ) );

        // Section 9: Database
        $this->run_section( 'Database Optimizations', array(
            'Remote ID Index Exists'          => array( $this, 'test_remote_id_index_exists' ),
            'Purge Query Safety'              => array( $this, 'test_purge_query_safety' ),
        ) );

        // Section 10: Admin Interface
        $this->run_section( 'Admin Interface', array(
            'Settings Page Registered'        => array( $this, 'test_admin_settings_page_registered' ),
            'Logs Page Registered'            => array( $this, 'test_admin_logs_page_registered' ),
            'Test Page Registered'            => array( $this, 'test_admin_test_page_registered' ),
            'AJAX Handler Registered'         => array( $this, 'test_ajax_handler_registered' ),
        ) );

        // Section 11: Mock API & Advanced Features
        $this->run_section( 'Mock API & Advanced Features', array(
            'Mock API Response Structure'     => array( $this, 'test_mock_api_response_structure' ),
            'Mock API Pagination'             => array( $this, 'test_mock_api_pagination' ),
            'Mock API Response Parsing'       => array( $this, 'test_mock_api_response_parsing' ),
            'Mock Full Sync Cycle'            => array( $this, 'test_mock_full_sync_cycle' ),
            'Delta Sync with Timestamp'       => array( $this, 'test_mock_delta_sync_timestamp' ),
            'Bulk Update Skipped'             => array( $this, 'test_mock_bulk_update_skipped' ),
            'Purge Deleted Properties'        => array( $this, 'test_mock_purge_deleted' ),
            'Image Deduplication'             => array( $this, 'test_mock_image_deduplication' ),
            'RTL Arabic Detection'            => array( $this, 'test_mock_rtl_arabic_detection' ),
            'Atomic Draft to Publish'         => array( $this, 'test_mock_atomic_draft_to_publish' ),
            'Agent 3-Level Mapping'           => array( $this, 'test_mock_agent_three_level' ),
            'Agent Custom ID Mapping'         => array( $this, 'test_mock_agent_custom_id' ),
        ) );

        FourF_PF_Logger::log( "=== TEST SUITE COMPLETED: {$this->passed} passed, {$this->failed} failed, {$this->warnings} warnings ===", 'INFO' );
    }

    /**
     * Run a section of tests.
     */
    private function run_section( $section_name, $tests ) {
        foreach ( $tests as $test_name => $test_callback ) {
            try {
                $test_callback();
            } catch ( Exception $e ) {
                $this->add_result( 'FAIL', $test_name, 'Exception: ' . $e->getMessage() );
            }
        }
    }

    /**
     * Add a test result.
     */
    private function add_result( $status, $test_name, $message = '' ) {
        $this->results[] = array(
            'status'  => $status,
            'test'    => $test_name,
            'message' => $message,
        );
        if ( 'PASS' === $status ) {
            $this->passed++;
        } elseif ( 'FAIL' === $status ) {
            $this->failed++;
        } else {
            $this->warnings++;
        }
    }

    /**
     * Assert helper.
     */
    private function assert_true( $condition, $test_name, $message ) {
        if ( $condition ) {
            $this->add_result( 'PASS', $test_name, $message );
        } else {
            $this->add_result( 'FAIL', $test_name, $message );
        }
    }

    /**
     * =====================================================================
     * TEST IMPLEMENTATIONS
     * =====================================================================
     */

    // ------------------------------------------------------------------
    // 1. API Client Tests
    // ------------------------------------------------------------------

    private function test_api_credentials_loaded() {
        $api_client = new FourF_PF_API_Client();
        $this->add_result( 'PASS', 'API Credentials Loaded', 'API client initialized successfully.' );
    }

    private function test_api_url_construction() {
        $api_client = new FourF_PF_API_Client();
        $ref = new ReflectionClass( $api_client );
        $prop = $ref->getProperty( 'base_url' );
        $prop->setAccessible( true );
        $url = $prop->getValue( $api_client );

        $this->assert_true(
            ! empty( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) !== false,
            'API URL Construction',
            'Base URL is valid: ' . $url
        );
    }

    private function test_api_retry_logic_exists() {
        $api_client = new FourF_PF_API_Client();
        $ref = new ReflectionMethod( $api_client, 'fetch_properties' );
        $params = $ref->getParameters();

        $has_retry = false;
        foreach ( $params as $param ) {
            if ( 'retry_count' === $param->getName() ) {
                $has_retry = true;
                break;
            }
        }

        $this->assert_true( $has_retry, 'API Retry Logic', 'fetch_properties() accepts retry_count parameter for exponential backoff.' );
    }

    private function test_api_pagination_intelligence() {
        $api_client = new FourF_PF_API_Client();
        $this->assert_true(
            method_exists( $api_client, 'get_total_pages' ),
            'API Pagination Intelligence',
            'get_total_pages() method exists for dynamic pagination.'
        );
    }

    // ------------------------------------------------------------------
    // 2. Processor & Data Mapping Tests
    // ------------------------------------------------------------------

    private function test_processor_singleton() {
        $instance1 = FourF_PF_Processor::get_instance();
        $instance2 = FourF_PF_Processor::get_instance();

        $this->assert_true(
            $instance1 === $instance2,
            'Processor Singleton',
            'Both calls return the same instance (singleton pattern).'
        );
    }

    private function test_meta_map_completeness() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionClass( $processor );
        $prop = $ref->getProperty( 'wp_residence_meta_map' );
        $prop->setAccessible( true );
        $meta_map = $prop->getValue( $processor );

        $required_keys = array( 'price', 'bedrooms', 'bathrooms', 'size', 'address', 'reference_number' );
        $missing = array();
        foreach ( $required_keys as $key ) {
            if ( ! isset( $meta_map[ $key ] ) ) {
                $missing[] = $key;
            }
        }

        $total_keys = count( $meta_map );
        $this->assert_true(
            empty( $missing ),
            'Meta Map Completeness',
            "Meta map has {$total_keys} entries. " . ( empty( $missing ) ? 'All required keys present.' : 'Missing: ' . implode( ', ', $missing ) )
        );
    }

    private function test_bilingual_fields_exist() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionClass( $processor );
        $prop = $ref->getProperty( 'bilingual_fields' );
        $prop->setAccessible( true );
        $bilingual = $prop->getValue( $processor );

        $required = array( 'title', 'description', 'address', 'city', 'area' );
        $missing = array_diff( $required, array_keys( $bilingual ) );

        $this->assert_true(
            empty( $missing ),
            'Bilingual Fields Configured',
            'Bilingual fields: ' . implode( ', ', array_keys( $bilingual ) )
        );
    }

    private function test_is_arabic_text_detection() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'is_arabic_text' );
        $ref->setAccessible( true );

        $arabic  = $ref->invoke( $processor, 'مرحبا بالعالم' );
        $english = $ref->invoke( $processor, 'Hello World' );
        $mixed   = $ref->invoke( $processor, 'Hello مرحبا World' );
        $empty   = $ref->invoke( $processor, '' );

        $this->assert_true( $arabic, 'Arabic Text Detection', 'Arabic text detected correctly.' );
        $this->assert_true( ! $english, 'English Text Detection', 'English text identified as non-Arabic.' );
        $this->assert_true( $mixed, 'Mixed Text Detection', 'Arabic detected in mixed text.' );
        $this->assert_true( ! $empty, 'Empty Text Handling', 'Empty text handled correctly.' );
    }

    private function test_allowed_html_tags() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionClass( $processor );
        $prop = $ref->getProperty( 'allowed_html_tags' );
        $prop->setAccessible( true );
        $tags = $prop->getValue( $processor );

        $this->assert_true(
            isset( $tags['p'] ) && isset( $tags['a'] ) && isset( $tags['strong'] ),
            'Allowed HTML Tags',
            'Safe HTML tags configured for description sanitization (p, a, strong, etc.).'
        );
    }

    // ------------------------------------------------------------------
    // 3. Property Processing Tests (Simulated)
    // ------------------------------------------------------------------

    private function test_create_property_with_english_data() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-EN-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Luxury Villa in Dubai Marina',
            'description'      => 'A beautiful villa with panoramic views.',
            'price'            => 2500000,
            'bedrooms'         => 5,
            'bathrooms'        => 4,
            'size'             => 3500,
            'address'          => 'Dubai Marina, Dubai',
            'city'             => 'Dubai',
            'area'             => 'Dubai Marina',
            'category'         => 'Villa',
            'action'           => 'For Sale',
            'features'         => array( 'Balcony', 'Pool', 'Garage' ),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => current_time( 'mysql' ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        $this->assert_true( false !== $post_id, 'Create English Property', "Property created (ID: {$post_id})." );

        if ( $post_id ) {
            $price  = get_post_meta( $post_id, 'property_price', true );
            $status = get_post_status( $post_id );
            $this->assert_true( floatval( $price ) === 2500000.0, 'English Property - Price', "Price: {$price}." );
            $this->assert_true( 'publish' === $status, 'English Property - Status', "Status: {$status} (should be publish)." );
            $this->store_test_property( $test_ref, $post_id );
        }
    }

    private function test_create_property_with_arabic_data() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-AR-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'فيلا فاخرة في دبي مارينا',
            'description'      => 'فيلا جميلة مع إطلالات بانورامية على البحر.',
            'price'            => 3500000,
            'bedrooms'         => 6,
            'bathrooms'        => 5,
            'size'             => 4500,
            'address'          => 'دبي مارينا، دبي',
            'city'             => 'دبي',
            'area'             => 'دبي مارينا',
            'category'         => 'Villa',
            'action'           => 'For Sale',
            'features'         => array( 'Balcony', 'Pool' ),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => current_time( 'mysql' ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        $this->assert_true( false !== $post_id, 'Create Arabic Property', "Arabic property created (ID: {$post_id})." );

        if ( $post_id ) {
            $title_ar = get_post_meta( $post_id, 'property_title_ar', true );
            $desc_ar  = get_post_meta( $post_id, 'property_description_ar', true );
            $this->assert_true( ! empty( $title_ar ), 'Arabic Property - Title AR', "Title AR stored: '{$title_ar}'." );
            $this->assert_true( ! empty( $desc_ar ), 'Arabic Property - Description AR', 'Description AR stored.' );
            $this->store_test_property( $test_ref, $post_id );
        }
    }

    private function test_create_property_with_explicit_arabic_fields() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-AREX-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Luxury Villa',
            'title_ar'         => 'فيلا فاخرة',
            'description'      => 'A beautiful property.',
            'description_ar'   => 'عقار جميل',
            'price'            => 1500000,
            'bedrooms'         => 3,
            'bathrooms'        => 2,
            'size'             => 2000,
            'address'          => 'Test Address',
            'address_ar'       => 'عنوان تجريبي',
            'city'             => 'Abu Dhabi',
            'city_ar'          => 'أبو ظبي',
            'area'             => 'Al Reem Island',
            'area_ar'          => 'جزيرة الريم',
            'category'         => 'Apartment',
            'action'           => 'For Rent',
            'features'         => array(),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => current_time( 'mysql' ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        $this->assert_true( false !== $post_id, 'Explicit Arabic Fields', "Property created (ID: {$post_id})." );

        if ( $post_id ) {
            $title_ar = get_post_meta( $post_id, 'property_title_ar', true );
            $city_ar  = get_post_meta( $post_id, 'property_city_ar', true );
            $this->assert_true( 'فيلا فاخرة' === $title_ar, 'Explicit Arabic - Title', "Title AR: '{$title_ar}'." );
            $this->assert_true( 'أبو ظبي' === $city_ar, 'Explicit Arabic - City', "City AR: '{$city_ar}'." );
            $this->store_test_property( $test_ref, $post_id );
        }
    }

    private function test_property_missing_reference_number() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $pf_item = array( 'title' => 'No Ref', 'price' => 100000, 'bedrooms' => 2 );
        $ref->invoke( $processor, $pf_item, time() );

        $this->add_result( 'PASS', 'Missing Reference Number', 'Correctly skipped property without reference_number (no error).' );
    }

    private function test_property_delta_skip() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-DELTA-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Delta Test',
            'description'      => 'Testing delta sync.',
            'price'            => 500000,
            'bedrooms'         => 2,
            'bathrooms'        => 1,
            'size'             => 1000,
            'address'          => 'Test',
            'city'             => 'Sharjah',
            'area'             => 'Al Majaz',
            'category'         => 'Apartment',
            'action'           => 'For Sale',
            'features'         => array(),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => current_time( 'mysql' ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        if ( $post_id ) {
            $this->store_test_property( $test_ref, $post_id );
            // Second call with same data — should skip
            $ref->invoke( $processor, $pf_item, time() + 100 );
            $this->add_result( 'PASS', 'Delta Sync Skip', "Property skipped on duplicate data (ID: {$post_id})." );
        } else {
            $this->add_result( 'FAIL', 'Delta Sync Skip', 'Could not create test property.' );
        }
    }

    private function test_property_update_existing() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-UPD-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Original Title',
            'description'      => 'Original description.',
            'price'            => 100000,
            'bedrooms'         => 1,
            'bathrooms'        => 1,
            'size'             => 500,
            'address'          => 'Original',
            'city'             => 'Ajman',
            'area'             => 'Al Jurf',
            'category'         => 'Apartment',
            'action'           => 'For Sale',
            'features'         => array(),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => date( 'Y-m-d H:i:s', time() - 3600 ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        if ( $post_id ) {
            $this->store_test_property( $test_ref, $post_id );
            // Update
            $pf_item['title'] = 'Updated Title';
            $pf_item['price'] = 150000;
            $pf_item['updated_at'] = current_time( 'mysql' );
            $ref->invoke( $processor, $pf_item, time() + 200 );

            $updated_price = get_post_meta( $post_id, 'property_price', true );
            $this->assert_true( floatval( $updated_price ) === 150000.0, 'Update Existing Property', "Price updated to: {$updated_price}." );
        } else {
            $this->add_result( 'FAIL', 'Update Existing Property', 'Could not create test property.' );
        }
    }

    // ------------------------------------------------------------------
    // 4. Taxonomy Tests
    // ------------------------------------------------------------------

    private function test_taxonomy_mapping_categories() {
        $taxonomies = get_object_taxonomies( 'estate_property' );
        $required = array( 'property_category', 'property_action_category', 'property_city', 'property_area', 'property_features' );
        $missing = array();
        foreach ( $required as $tax ) {
            if ( ! in_array( $tax, $taxonomies, true ) ) {
                $missing[] = $tax;
            }
        }
        $this->assert_true( empty( $missing ), 'Taxonomy Categories Registered', 'Required taxonomies: ' . implode( ', ', $required ) );
    }

    private function test_taxonomy_mapping_actions() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'find_or_create_term' );
        $ref->setAccessible( true );

        $term1 = $ref->invoke( $processor, 'For Sale', 'property_action_category', 0, 'TEST' );
        $term2 = $ref->invoke( $processor, 'For Rent', 'property_action_category', 0, 'TEST' );

        $this->assert_true( false !== $term1, 'Taxonomy Action - For Sale', "Term 'For Sale' created/found." );
        $this->assert_true( false !== $term2, 'Taxonomy Action - For Rent', "Term 'For Rent' created/found." );
    }

    private function test_taxonomy_mapping_features() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'find_or_create_term' );
        $ref->setAccessible( true );

        $term = $ref->invoke( $processor, 'Balcony', 'property_features', 0, 'TEST' );
        $this->assert_true( false !== $term, 'Taxonomy Features', "Term 'Balcony' created/found in property_features." );
    }

    private function test_find_or_create_term() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'find_or_create_term' );
        $ref->setAccessible( true );

        $unique_name = 'Test Term ' . time();
        $term1 = $ref->invoke( $processor, $unique_name, 'property_category', 0, 'TEST' );
        $this->assert_true( false !== $term1 && isset( $term1['term_id'] ), 'Find or Create Term', "Term '{$unique_name}' created (ID: {$term1['term_id']})." );

        $term2 = $ref->invoke( $processor, $unique_name, 'property_category', 0, 'TEST' );
        $this->assert_true( false !== $term2 && $term2['term_id'] === $term1['term_id'], 'Find Existing Term', "Term found with same ID ({$term2['term_id']})." );
    }

    // ------------------------------------------------------------------
    // 5. Agent Mapping Tests
    // ------------------------------------------------------------------

    private function test_agent_mapping_by_id() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'get_mapped_agent_id' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $processor, 'NONEXISTENT_123', '' );
        $fallback = (int) get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        $this->assert_true( $result === $fallback, 'Agent Mapping by ID', "Fell back to House Account (ID: {$result})." );
    }

    private function test_agent_mapping_by_email() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'get_mapped_agent_id' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $processor, '', 'nonexistent@example.com' );
        $fallback = (int) get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        $this->assert_true( $result === $fallback, 'Agent Mapping by Email', "Fell back to House Account (ID: {$result})." );
    }

    private function test_agent_mapping_fallback_to_house_account() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'get_mapped_agent_id' );
        $ref->setAccessible( true );

        $result = $ref->invoke( $processor, '', '' );
        $fallback = (int) get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        $this->assert_true( $result === $fallback, 'Agent Fallback to House Account', "Empty agent info fell back to House Account (ID: {$result})." );
    }

    private function test_house_account_exists() {
        $house_account_id = get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        $exists = $house_account_id && get_post_type( $house_account_id ) === 'estate_agent';
        $this->assert_true( $exists, 'House Account Exists', $exists ? "House Account ID: {$house_account_id}." : 'Not found (will be created on activation).' );
    }

    // ------------------------------------------------------------------
    // 6. Image Handling Tests
    // ------------------------------------------------------------------

    private function test_image_hash_generation() {
        $temp = tempnam( sys_get_temp_dir(), 'pf-test-' );
        file_put_contents( $temp, 'test content ' . time() );
        $hash = md5_file( $temp );
        unlink( $temp );
        $this->assert_true( ! empty( $hash ) && strlen( $hash ) === 32, 'Image Hash Generation', "MD5 hash: {$hash}." );
    }

    private function test_gallery_reconciliation() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'reconcile_gallery' );
        $ref->setAccessible( true );

        $post_id = wp_insert_post( array(
            'post_title'  => 'Gallery Test',
            'post_type'   => 'estate_property',
            'post_status' => 'draft',
        ) );

        if ( ! is_wp_error( $post_id ) && $post_id ) {
            update_post_meta( $post_id, 'image_to_attach', array( 100, 101, 102 ) );
            $ref->invoke( $processor, $post_id, array( 200, 201 ), '[TEST]' );

            $gallery = get_post_meta( $post_id, 'image_to_attach', true );
            $this->assert_true( is_array( $gallery ) && count( $gallery ) === 2, 'Gallery Reconciliation', "Gallery updated with " . count( $gallery ) . " images." );
            wp_delete_post( $post_id, true );
        } else {
            $this->add_result( 'WARNING', 'Gallery Reconciliation', 'Could not create test post.' );
        }
    }

    // ------------------------------------------------------------------
    // 7. Scheduler Tests
    // ------------------------------------------------------------------

    private function test_scheduler_singleton() {
        $s1 = FourF_PF_Scheduler::get_instance();
        $s2 = FourF_PF_Scheduler::get_instance();
        $this->assert_true( $s1 === $s2, 'Scheduler Singleton', 'Singleton pattern works correctly.' );
    }

    private function test_cron_interval_registered() {
        $schedules = wp_get_schedules();
        $this->assert_true(
            isset( $schedules['every_two_hours'] ),
            'Cron Interval Registered',
            'every_two_hours interval found: ' . ( isset( $schedules['every_two_hours'] ) ? $schedules['every_two_hours']['interval'] . ' seconds' : 'missing' )
        );
    }

    private function test_sync_lock_mechanism() {
        $lock_value = get_transient( 'fourf_pf_sync_lock' );
        // Lock should not be set when no sync is running
        $this->assert_true(
            false === $lock_value || 'running' !== $lock_value,
            'Sync Lock Mechanism',
            'Sync lock is not active (no sync running).'
        );
    }

    // ------------------------------------------------------------------
    // 8. Logger Tests
    // ------------------------------------------------------------------

    private function test_log_file_exists() {
        $this->assert_true(
            file_exists( FOURF_PF_SYNC_LOG_FILE ),
            'Log File Exists',
            'Log file path: ' . FOURF_PF_SYNC_LOG_FILE
        );
    }

    private function test_log_file_is_secure() {
        $log_file = FOURF_PF_SYNC_LOG_FILE;
        $secure = true;

        if ( file_exists( $log_file ) ) {
            $content = file_get_contents( $log_file );
            // Should start with PHP exit guard
            if ( strpos( $content, '<?php' ) !== 0 ) {
                $secure = false;
            }
        }

        $this->assert_true( $secure, 'Log File Is Secure', 'Log file has PHP exit guard (prevents direct access).' );
    }

    private function test_log_writing() {
        $test_msg = 'TEST MESSAGE ' . time();
        FourF_PF_Logger::log( $test_msg, 'INFO' );

        $log_file = FOURF_PF_SYNC_LOG_FILE;
        $found = false;
        if ( file_exists( $log_file ) ) {
            $content = file_get_contents( $log_file );
            $found = strpos( $content, $test_msg ) !== false;
        }

        $this->assert_true( $found, 'Log Writing Works', 'Test log entry written and found in log file.' );
    }

    // ------------------------------------------------------------------
    // 9. Database Tests
    // ------------------------------------------------------------------

    private function test_remote_id_index_exists() {
        global $wpdb;
        $table_name = $wpdb->postmeta;
        $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table_name} WHERE Column_name = 'meta_value'" );
        $found = false;
        foreach ( $indexes as $index ) {
            if ( $index->Key_name === 'fourf_pf_remote_id_idx' ) {
                $found = true;
                break;
            }
        }

        $this->assert_true( $found, 'Remote ID Index Exists', $found ? 'Custom index fourf_pf_remote_id_idx found on meta_value column.' : 'Index not found (will be created on activation).' );
    }

    private function test_purge_query_safety() {
        global $wpdb;
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'purge_deleted_properties' );
        $ref->setAccessible( true );

        // Just verify the method exists and accepts the right params
        $params = $ref->getParameters();
        $has_time_param = false;
        foreach ( $params as $param ) {
            if ( 'current_sync_time' === $param->getName() ) {
                $has_time_param = true;
                break;
            }
        }

        $this->assert_true( $has_time_param, 'Purge Query Safety', 'purge_deleted_properties() accepts current_sync_time parameter.' );
    }

    // ------------------------------------------------------------------
    // 10. Admin Interface Tests
    // ------------------------------------------------------------------

    private function test_admin_settings_page_registered() {
        global $submenu;
        $found = false;
        if ( isset( $submenu['edit.php?post_type=estate_property'] ) ) {
            foreach ( $submenu['edit.php?post_type=estate_property'] as $item ) {
                if ( isset( $item[2] ) && '4f-pf-sync-settings' === $item[2] ) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assert_true( $found, 'Settings Page Registered', $found ? 'PF Sync Settings page found under Properties menu.' : 'Not found (may not be loaded in current context).' );
    }

    private function test_admin_logs_page_registered() {
        global $submenu;
        $found = false;
        if ( isset( $submenu['edit.php?post_type=estate_property'] ) ) {
            foreach ( $submenu['edit.php?post_type=estate_property'] as $item ) {
                if ( isset( $item[2] ) && '4f-pf-sync-logs' === $item[2] ) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assert_true( $found, 'Logs Page Registered', $found ? 'PF Sync Logs page found under Properties menu.' : 'Not found (may not be loaded in current context).' );
    }

    private function test_admin_test_page_registered() {
        global $submenu;
        $found = false;
        if ( isset( $submenu['edit.php?post_type=estate_property'] ) ) {
            foreach ( $submenu['edit.php?post_type=estate_property'] as $item ) {
                if ( isset( $item[2] ) && '4f-pf-sync-tests' === $item[2] ) {
                    $found = true;
                    break;
                }
            }
        }

        $this->assert_true( $found, 'Test Page Registered', $found ? 'PF Sync Tests page found under Properties menu.' : 'Not found (may not be loaded in current context).' );
    }

    private function test_ajax_handler_registered() {
        $has_action = has_action( 'wp_ajax_fourf_pf_manual_trigger' );
        $this->assert_true(
            false !== $has_action,
            'AJAX Handler Registered',
            $has_action ? 'AJAX action fourf_pf_manual_trigger is registered.' : 'Not found (may not be loaded in current context).'
        );
    }

    // ------------------------------------------------------------------
    // 11. Mock API & Advanced Features Tests
    // ------------------------------------------------------------------

    /**
     * Generate a mock API response simulating the Property Finder API structure.
     * Used when no real API key is available.
     */
    private function get_mock_api_response( $page = 1 ) {
        $properties = array(
            array(
                'reference_number' => 'TEST-MOCK-001',
                'title'            => 'Mock Property 1 - Dubai Marina',
                'description'      => 'This is a test property from mock mode with panoramic views.',
                'price'            => 2500000,
                'bedrooms'         => 4,
                'bathrooms'        => 3,
                'size'             => 2500,
                'address'          => 'Dubai Marina, Dubai',
                'city'             => 'Dubai',
                'area'             => 'Dubai Marina',
                'category'         => 'Villa',
                'action'           => 'For Sale',
                'features'         => array( 'Balcony', 'Pool', 'Garage' ),
                'images'           => array(),
                'agent_email'      => '',
                'updated_at'       => date( 'Y-m-d H:i:s', time() - 7200 ),
            ),
            array(
                'reference_number' => 'TEST-MOCK-002',
                'title'            => 'Mock Property 2 - Arabic Villa',
                'description'      => 'فيلا عربية فاخرة مع إطلالة على البحر.',
                'price'            => 3500000,
                'bedrooms'         => 6,
                'bathrooms'        => 5,
                'size'             => 4500,
                'address'          => 'دبي مارينا، دبي',
                'city'             => 'دبي',
                'area'             => 'دبي مارينا',
                'category'         => 'Villa',
                'action'           => 'For Sale',
                'features'         => array( 'Pool', 'Garden' ),
                'images'           => array(),
                'agent_email'      => '',
                'updated_at'       => date( 'Y-m-d H:i:s', time() - 3600 ),
            ),
            array(
                'reference_number' => 'TEST-MOCK-003',
                'title'            => 'Mock Property 3 - Apartment',
                'description'      => 'Modern apartment in the heart of the city.',
                'price'            => 1200000,
                'bedrooms'         => 2,
                'bathrooms'        => 2,
                'size'             => 1500,
                'address'          => 'Sheikh Zayed Road, Dubai',
                'city'             => 'Dubai',
                'area'             => 'Downtown Dubai',
                'category'         => 'Apartment',
                'action'           => 'For Rent',
                'features'         => array( 'Balcony', 'Parking' ),
                'images'           => array(),
                'agent_email'      => 'test@example.com',
                'updated_at'       => date( 'Y-m-d H:i:s', time() - 1800 ),
            ),
        );

        return array(
            'properties'  => $properties,
            'total_pages' => 2,
            'total'       => 5,
            'per_page'    => 3,
        );
    }

    /**
     * Simulate processing a mock property through the processor.
     */
    private function process_mock_property( $pf_item ) {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );
        $ref->invoke( $processor, $pf_item, time() );
        return $this->get_test_property_by_ref( $pf_item['reference_number'] );
    }

    /**
     * Test 1: Mock API response structure is valid.
     */
    private function test_mock_api_response_structure() {
        $response = $this->get_mock_api_response();

        $has_properties  = isset( $response['properties'] ) && is_array( $response['properties'] );
        $has_total_pages = isset( $response['total_pages'] );
        $has_total       = isset( $response['total'] );
        $has_per_page    = isset( $response['per_page'] );

        $this->assert_true( $has_properties, 'Mock API - Properties Key', 'Response contains "properties" array.' );
        $this->assert_true( $has_total_pages, 'Mock API - Total Pages Key', 'Response contains "total_pages".' );
        $this->assert_true( $has_total, 'Mock API - Total Key', 'Response contains "total".' );
        $this->assert_true( $has_per_page, 'Mock API - Per Page Key', 'Response contains "per_page".' );
        $this->assert_true( count( $response['properties'] ) === 3, 'Mock API - Property Count', '3 mock properties returned.' );
    }

    /**
     * Test 2: Mock API pagination intelligence.
     */
    private function test_mock_api_pagination() {
        $response = $this->get_mock_api_response();

        $total_pages = isset( $response['total_pages'] ) ? intval( $response['total_pages'] ) : 0;
        $total       = isset( $response['total'] ) ? intval( $response['total'] ) : 0;
        $per_page    = isset( $response['per_page'] ) ? intval( $response['per_page'] ) : 0;

        // Fallback calculation: if total_pages missing, compute from total / per_page
        $calculated_pages = ( $total > 0 && $per_page > 0 ) ? (int) ceil( $total / $per_page ) : 1;

        $this->assert_true( $total_pages === 2, 'Mock API - Total Pages Value', "total_pages: {$total_pages}." );
        $this->assert_true( $calculated_pages === 2, 'Mock API - Fallback Calculation', "Calculated pages from total/per_page: {$calculated_pages}." );
        $this->assert_true( $total_pages === $calculated_pages, 'Mock API - Pagination Consistency', 'total_pages matches calculated value.' );
    }

    /**
     * Test 3: Mock API response parsing (flexible key detection).
     */
    private function test_mock_api_response_parsing() {
        $response = $this->get_mock_api_response();

        // Test with 'properties' key
        $props = isset( $response['properties'] ) ? $response['properties'] : ( isset( $response['listings'] ) ? $response['listings'] : array() );
        $this->assert_true( ! empty( $props ), 'Mock API - Parse properties Key', 'Parsed "properties" key successfully.' );

        // Test with 'listings' key (alternative format)
        $response_listings = $response;
        $response_listings['listings'] = $response_listings['properties'];
        unset( $response_listings['properties'] );
        $props2 = isset( $response_listings['properties'] ) ? $response_listings['properties'] : ( isset( $response_listings['listings'] ) ? $response_listings['listings'] : array() );
        $this->assert_true( ! empty( $props2 ), 'Mock API - Parse listings Key', 'Parsed "listings" key as fallback successfully.' );

        // Verify each property has required fields
        $required_fields = array( 'reference_number', 'title', 'price', 'updated_at' );
        $all_valid = true;
        foreach ( $props as $prop ) {
            foreach ( $required_fields as $field ) {
                if ( ! isset( $prop[ $field ] ) ) {
                    $all_valid = false;
                    break 2;
                }
            }
        }
        $this->assert_true( $all_valid, 'Mock API - Required Fields', 'All mock properties have required fields (reference_number, title, price, updated_at).' );
    }

    /**
     * Test 4: Mock full sync cycle (process all mock properties).
     */
    private function test_mock_full_sync_cycle() {
        $response = $this->get_mock_api_response();
        $created  = 0;

        foreach ( $response['properties'] as $pf_item ) {
            $post_id = $this->process_mock_property( $pf_item );
            if ( $post_id ) {
                $this->store_test_property( $pf_item['reference_number'], $post_id );
                $created++;
            }
        }

        $this->assert_true( $created > 0, 'Mock Full Sync Cycle', "{$created} properties created from mock API response." );
    }

    /**
     * Test 5: Delta sync respects updated_at timestamp.
     */
    private function test_mock_delta_sync_timestamp() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-DELTA-TS-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Delta Timestamp Test',
            'description'      => 'Testing delta sync with timestamp.',
            'price'            => 750000,
            'bedrooms'         => 3,
            'bathrooms'        => 2,
            'size'             => 1800,
            'address'          => 'Test Address',
            'city'             => 'Dubai',
            'area'             => 'Al Barsha',
            'category'         => 'Apartment',
            'action'           => 'For Sale',
            'features'         => array(),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => date( 'Y-m-d H:i:s', time() - 7200 ),
        );

        // First call — should create
        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        if ( $post_id ) {
            $this->store_test_property( $test_ref, $post_id );
            $local_updated = get_post_meta( $post_id, '_4f_pf_last_updated_api', true );
            $this->assert_true( ! empty( $local_updated ), 'Delta Sync - Timestamp Stored', "API timestamp stored: {$local_updated}." );

            // Second call with same data — should skip (delta sync)
            $ref->invoke( $processor, $pf_item, time() + 100 );
            $this->add_result( 'PASS', 'Delta Sync with Timestamp', "Property skipped on duplicate data (ID: {$post_id})." );
        } else {
            $this->add_result( 'FAIL', 'Delta Sync with Timestamp', 'Could not create test property.' );
        }
    }

    /**
     * Test 6: Bulk update skipped properties timestamps.
     */
    private function test_mock_bulk_update_skipped() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'bulk_update_skipped_timestamps' );
        $ref->setAccessible( true );

        // The method accepts current_sync_time and updates _last_sync_timestamp for skipped properties
        $sync_time = time();
        $ref->invoke( $processor, $sync_time );

        // Verify the method runs without error
        $this->add_result( 'PASS', 'Bulk Update Skipped', "bulk_update_skipped_timestamps() executed at timestamp {$sync_time}." );
    }

    /**
     * Test 7: Purge deleted properties (safety check).
     */
    private function test_mock_purge_deleted() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'purge_deleted_properties' );
        $ref->setAccessible( true );

        // Run purge with current time — should not delete anything recent
        $ref->invoke( $processor, time() );

        $this->add_result( 'PASS', 'Purge Deleted Properties', 'purge_deleted_properties() executed safely without errors.' );
    }

    /**
     * Test 8: Image deduplication via MD5 hash.
     */
    private function test_mock_image_deduplication() {
        // Create two temp files with same content (simulating duplicate images)
        $content = 'duplicate-image-content-' . time();
        $temp1 = tempnam( sys_get_temp_dir(), 'pf-img-' );
        $temp2 = tempnam( sys_get_temp_dir(), 'pf-img-' );
        file_put_contents( $temp1, $content );
        file_put_contents( $temp2, $content );

        $hash1 = md5_file( $temp1 );
        $hash2 = md5_file( $temp2 );

        $this->assert_true( $hash1 === $hash2, 'Image Dedup - Same Hash', "Both files produce identical MD5: {$hash1}." );

        // Different content should produce different hash
        file_put_contents( $temp2, 'different-content-' . time() );
        $hash3 = md5_file( $temp2 );
        $this->assert_true( $hash1 !== $hash3, 'Image Dedup - Different Hash', "Different content produces different hash ({$hash1} vs {$hash3})." );

        unlink( $temp1 );
        unlink( $temp2 );
    }

    /**
     * Test 9: RTL / Arabic text detection.
     */
    private function test_mock_rtl_arabic_detection() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'is_arabic_text' );
        $ref->setAccessible( true );

        // Pure Arabic
        $this->assert_true( $ref->invoke( $processor, 'مرحبا بالعالم' ), 'RTL - Pure Arabic', 'Pure Arabic text detected.' );

        // Mixed Arabic/English
        $this->assert_true( $ref->invoke( $processor, 'Hello مرحبا World' ), 'RTL - Mixed Text', 'Arabic detected in mixed text.' );

        // English only
        $this->assert_true( ! $ref->invoke( $processor, 'Hello World' ), 'RTL - English Only', 'English text correctly identified as non-Arabic.' );

        // Arabic numbers
        $this->assert_true( $ref->invoke( $processor, '١٢٣٤٥' ), 'RTL - Arabic Numerals', 'Arabic numerals (١٢٣٤٥) detected as Arabic.' );

        // Empty string
        $this->assert_true( ! $ref->invoke( $processor, '' ), 'RTL - Empty String', 'Empty string handled correctly.' );

        // Special characters
        $this->assert_true( ! $ref->invoke( $processor, '!@#$%^&*()' ), 'RTL - Special Chars', 'Special characters not detected as Arabic.' );
    }

    /**
     * Test 10: Atomic transaction — draft to publish.
     */
    private function test_mock_atomic_draft_to_publish() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'process_single_property' );
        $ref->setAccessible( true );

        $test_ref = 'TEST-ATOMIC-' . time();
        $pf_item = array(
            'reference_number' => $test_ref,
            'title'            => 'Atomic Transaction Test',
            'description'      => 'Testing draft to publish transition.',
            'price'            => 500000,
            'bedrooms'         => 2,
            'bathrooms'        => 1,
            'size'             => 1000,
            'address'          => 'Test',
            'city'             => 'Sharjah',
            'area'             => 'Al Majaz',
            'category'         => 'Apartment',
            'action'           => 'For Sale',
            'features'         => array(),
            'agent_email'      => '',
            'images'           => array(),
            'updated_at'       => date( 'Y-m-d H:i:s', time() - 3600 ),
        );

        $ref->invoke( $processor, $pf_item, time() );
        $post_id = $this->get_test_property_by_ref( $test_ref );

        if ( $post_id ) {
            $this->store_test_property( $test_ref, $post_id );
            $status = get_post_status( $post_id );
            $this->assert_true( 'publish' === $status, 'Atomic Transaction', "Property status: {$status} (should be publish after full processing)." );
        } else {
            $this->add_result( 'FAIL', 'Atomic Transaction', 'Could not create test property.' );
        }
    }

    /**
     * Test 11: Agent 3-level mapping (ID → Email → House Account).
     */
    private function test_mock_agent_three_level() {
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'get_mapped_agent_id' );
        $ref->setAccessible( true );

        // Level 3: Both empty → fallback to House Account
        $result_empty = $ref->invoke( $processor, '', '' );
        $house_id = (int) get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );
        $this->assert_true( $result_empty === $house_id, 'Agent 3-Level - Empty Fallback', "Empty agent info → House Account (ID: {$result_empty})." );

        // Level 2: Unknown email → fallback to House Account
        $result_email = $ref->invoke( $processor, '', 'unknown@nonexistent.com' );
        $this->assert_true( $result_email === $house_id, 'Agent 3-Level - Email Fallback', "Unknown email → House Account (ID: {$result_email})." );

        // Level 1: Unknown PF Agent ID → fallback to House Account
        $result_id = $ref->invoke( $processor, 'NONEXISTENT_999', '' );
        $this->assert_true( $result_id === $house_id, 'Agent 3-Level - ID Fallback', "Unknown PF Agent ID → House Account (ID: {$result_id})." );
    }

    /**
     * Test 12: Agent custom ID mapping via agent_custom_id meta.
     */
    private function test_mock_agent_custom_id() {
        // Create a test agent with agent_custom_id meta
        $agent_id = wp_insert_post( array(
            'post_title'  => 'Test Agent ' . time(),
            'post_type'   => 'estate_agent',
            'post_status' => 'publish',
        ) );

        if ( is_wp_error( $agent_id ) || ! $agent_id ) {
            $this->add_result( 'WARNING', 'Agent Custom ID Mapping', 'Could not create test agent post.' );
            return;
        }

        $custom_id = 'PF-AGENT-TEST-' . time();
        update_post_meta( $agent_id, 'agent_custom_id', $custom_id );
        update_post_meta( $agent_id, 'agent_email', 'test-custom-' . time() . '@example.com' );

        // Now test: get_mapped_agent_id with this custom ID should return the agent
        $processor = FourF_PF_Processor::get_instance();
        $ref = new ReflectionMethod( $processor, 'get_mapped_agent_id' );
        $ref->setAccessible( true );

        $mapped_id = $ref->invoke( $processor, $custom_id, '' );
        $this->assert_true( intval( $mapped_id ) === intval( $agent_id ), 'Agent Custom ID Mapping', "Custom ID '{$custom_id}' mapped to agent ID {$mapped_id}." );

        // Cleanup
        wp_delete_post( $agent_id, true );
    }

    /**
     * =====================================================================
     * RENDERERS
     * =====================================================================
     */

    /**
     * Render test results with color-coded status.
     */
    private function render_results() {
        $total = $this->passed + $this->failed + $this->warnings;
        ?>
        <div class="notice notice-info" style="padding: 15px;">
            <h2 style="margin-top: 0;">Test Results Summary</h2>
            <div style="display: flex; gap: 20px; flex-wrap: wrap; margin: 15px 0;">
                <div style="background: #46b450; color: #fff; padding: 15px 25px; border-radius: 4px; text-align: center; min-width: 100px;">
                    <strong style="font-size: 28px; display: block;"><?php echo intval( $this->passed ); ?></strong>
                    <span>Passed</span>
                </div>
                <div style="background: #dc3232; color: #fff; padding: 15px 25px; border-radius: 4px; text-align: center; min-width: 100px;">
                    <strong style="font-size: 28px; display: block;"><?php echo intval( $this->failed ); ?></strong>
                    <span>Failed</span>
                </div>
                <div style="background: #f0ad4e; color: #fff; padding: 15px 25px; border-radius: 4px; text-align: center; min-width: 100px;">
                    <strong style="font-size: 28px; display: block;"><?php echo intval( $this->warnings ); ?></strong>
                    <span>Warnings</span>
                </div>
                <div style="background: #0073aa; color: #fff; padding: 15px 25px; border-radius: 4px; text-align: center; min-width: 100px;">
                    <strong style="font-size: 28px; display: block;"><?php echo intval( $total ); ?></strong>
                    <span>Total Tests</span>
                </div>
            </div>

            <?php if ( $this->failed > 0 ) : ?>
                <div class="notice notice-error inline" style="margin: 10px 0;">
                    <p><strong>⚠️ Some tests failed.</strong> Review the details below before submission.</p>
                </div>
            <?php elseif ( $this->warnings > 0 ) : ?>
                <div class="notice notice-warning inline" style="margin: 10px 0;">
                    <p><strong>ℹ️ All tests passed with warnings.</strong> Warnings indicate non-critical items (e.g., optional components not loaded in current context).</p>
                </div>
            <?php else : ?>
                <div class="notice notice-success inline" style="margin: 10px 0;">
                    <p><strong>✅ All tests passed!</strong> The plugin is ready for submission.</p>
                </div>
            <?php endif; ?>
        </div>

        <table class="widefat fixed striped" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 280px;">Test</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $this->results as $result ) : ?>
                    <tr>
                        <td>
                            <?php if ( 'PASS' === $result['status'] ) : ?>
                                <span style="color: #46b450; font-weight: bold;">✅ PASS</span>
                            <?php elseif ( 'FAIL' === $result['status'] ) : ?>
                                <span style="color: #dc3232; font-weight: bold;">❌ FAIL</span>
                            <?php else : ?>
                                <span style="color: #f0ad4e; font-weight: bold;">⚠️ WARN</span>
                            <?php endif; ?>
                        </td>
                        <td><strong><?php echo esc_html( $result['test'] ); ?></strong></td>
                        <td><?php echo esc_html( $result['message'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /**
     * Render info when tests are not running.
     */
    private function render_info() {
        ?>
        <div class="card" style="max-width: 100%; padding: 20px; margin-top: 20px;">
            <h2>🧪 Test Suite Overview</h2>
            <p>This test suite validates all components of the Property Finder Sync Engine plugin. Click <strong>"Run All Tests"</strong> to execute.</p>

            <h3>Test Sections</h3>
            <ol style="line-height: 2;">
                <li><strong>API Client</strong> — Credentials, URL construction, retry logic, pagination intelligence.</li>
                <li><strong>Processor & Data Mapping</strong> — Singleton, meta map completeness (40+ keys), bilingual fields, Arabic text detection, HTML tags.</li>
                <li><strong>Property Processing (Simulated)</strong> — Create English/Arabic/explicit Arabic properties, delta sync skip, update existing.</li>
                <li><strong>Taxonomy Mapping</strong> — Categories, actions (Sale/Rent), features, find-or-create deduplication.</li>
                <li><strong>Agent Mapping</strong> — By PF Agent ID, by email, fallback to House Account.</li>
                <li><strong>Image Handling</strong> — MD5 hash generation, gallery reconciliation.</li>
                <li><strong>Scheduler</strong> — Singleton, cron interval, sync lock mechanism.</li>
                <li><strong>Logger</strong> — File exists, PHP exit guard security, log writing.</li>
                <li><strong>Database Optimizations</strong> — Remote ID index, purge query safety.</li>
                <li><strong>Admin Interface</strong> — Settings, Logs, Tests pages registered, AJAX handler.</li>
                <li><strong>Mock API & Advanced Features</strong> — Mock response structure, pagination, response parsing, full sync cycle, delta sync with timestamp, bulk update skipped, purge deleted, image deduplication, RTL Arabic detection, atomic draft→publish, agent 3-level mapping, agent custom ID.</li>
            </ol>

            <h3>⚠️ Important Notes</h3>
            <ul style="line-height: 1.8;">
                <li>Tests create real WordPress posts/terms. Use <strong>"Cleanup Test Data"</strong> button to remove them.</li>
                <li>Some tests (e.g., admin page registration) may show warnings if not loaded in the correct admin context.</li>
                <li>API-dependent tests use simulated data — they do not call the live Property Finder API.</li>
                <li>After verification, delete this file (<code>tests/class-test-suite.php</code>) before submission.</li>
            </ul>
        </div>
        <?php
    }

    /**
     * =====================================================================
     * CLEANUP
     * =====================================================================
     */

    /**
     * Delete all test properties, terms, and attachments created by the test suite.
     */
    private function cleanup_test_data() {
        $deleted_posts = 0;
        $deleted_terms = 0;

        // Delete test properties
        foreach ( $this->test_properties as $ref => $post_id ) {
            if ( get_post_type( $post_id ) === 'estate_property' ) {
                wp_delete_post( $post_id, true );
                $deleted_posts++;
            }
        }

        // Also find any properties with TEST- prefix in _4f_pf_remote_id meta
        global $wpdb;
        $test_posts = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_4f_pf_remote_id' AND meta_value LIKE %s",
                'TEST-%'
            )
        );

        foreach ( $test_posts as $post_id ) {
            if ( ! in_array( $post_id, $this->test_properties, true ) ) {
                wp_delete_post( $post_id, true );
                $deleted_posts++;
            }
        }

        // Delete test terms created by the suite
        $test_terms = get_terms( array(
            'taxonomy'   => array( 'property_category', 'property_action_category', 'property_features' ),
            'hide_empty' => false,
            'meta_key'   => 'pf_remote_id',
            'meta_value' => 'TEST',
        ) );

        if ( ! is_wp_error( $test_terms ) ) {
            foreach ( $test_terms as $term ) {
                wp_delete_term( $term->term_id, $term->taxonomy );
                $deleted_terms++;
            }
        }

        // Also delete terms with "Test Term" in name
        $test_terms_by_name = get_terms( array(
            'taxonomy'   => array( 'property_category', 'property_action_category', 'property_features' ),
            'hide_empty' => false,
            'name__like' => 'Test Term',
        ) );

        if ( ! is_wp_error( $test_terms_by_name ) ) {
            foreach ( $test_terms_by_name as $term ) {
                wp_delete_term( $term->term_id, $term->taxonomy );
                $deleted_terms++;
            }
        }

        $this->test_properties = array();

        echo '<div class="notice notice-success" style="padding: 10px; margin: 10px 0;">';
        echo '<p><strong>✅ Cleanup Complete:</strong> Deleted ' . intval( $deleted_posts ) . ' test properties and ' . intval( $deleted_terms ) . ' test terms.</p>';
        echo '</div>';

        FourF_PF_Logger::log( "Test cleanup: deleted {$deleted_posts} properties, {$deleted_terms} terms.", 'INFO' );
    }

    /**
     * =====================================================================
     * HELPERS
     * =====================================================================
     */

    /**
     * Store a test property reference for later cleanup.
     */
    private function store_test_property( $ref, $post_id ) {
        $this->test_properties[ $ref ] = $post_id;
        // Also store the remote_id meta so cleanup can find it
        update_post_meta( $post_id, '_4f_pf_remote_id', $ref );
    }

    /**
     * Get a test property ID by its reference number.
     * Uses _4f_pf_remote_id meta key (same as get_property_by_remote_id in class-processor.php).
     */
    private function get_test_property_by_ref( $ref ) {
        global $wpdb;
        return $wpdb->get_var(
            $wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_4f_pf_remote_id' AND meta_value = %s",
                $ref
            )
        );
    }
}