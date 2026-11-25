<?php
/**
 * Class FourF_PF_API_Client
 * Handles secure communication with the Property Finder API with full error handling and retry logic.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_API_Client {

    private $api_key;
    private $base_url;
    private $total_pages = 0;

    public function __construct() {
        $this->init_credentials();
    }

    private function init_credentials() {
        // Strict hierarchy override: define() > database option (enterprise-grade protection)
        $this->api_key  = defined( 'FOURF_PF_API_KEY' )  ? FOURF_PF_API_KEY  : get_option( 'fourf_pf_api_key', '' );
        
        $default_url    = 'https://api.propertyfinder.com/v1/listings';
        $this->base_url = defined( 'FOURF_PF_API_URL' )  ? FOURF_PF_API_URL  : get_option( 'fourf_pf_api_url', $default_url );
    }

    /**
     * Get the total number of pages from the last API response.
     * Used by the processor to determine exact pagination boundaries.
     *
     * @return int Total pages count.
     */
    public function get_total_pages() {
        return $this->total_pages;
    }

    public function fetch_properties( $page = 1, $retry_count = 0 ) {
        // Reset pagination metadata at the beginning of each sync cycle.
        if ( 1 === (int) $page ) {
            $this->total_pages = 0;
        }

        // =============================================
        // MOCK MODE: Return fake data for testing without real API Key.
        // Activate by defining FOURF_PF_MOCK_MODE in wp-config.php:
        //   define( 'FOURF_PF_MOCK_MODE', true );
        // =============================================
        if ( defined( 'FOURF_PF_MOCK_MODE' ) && FOURF_PF_MOCK_MODE ) {
            FourF_PF_Logger::log( "Mock Mode ACTIVE - Using simulated property data for testing (page {$page}).", 'WARNING' );
            return $this->get_mock_properties( $page );
        }

        if ( empty( $this->api_key ) ) {
            FourF_PF_Logger::log( "API connection failed: API Key is missing. Check settings or wp-config.php.", 'ERROR' );
            return false;
        }

        // Build URL securely with XSS/URL injection prevention
        $request_url = add_query_arg( array(
            'page'     => intval( $page ),
            'per_page' => 50,
        ), esc_url_raw( $this->base_url ) );

        FourF_PF_Logger::log( "Fetching API data - Page ($page)...", 'INFO' );

        $response = wp_remote_get( $request_url, array(
            'timeout'   => 30, // Prevent server freeze on slow responses
            'headers'   => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Accept'        => 'application/json',
                'X-Source'      => '4F-Realty-Sync-Engine'
            ),
        ) );

        // 1. Network-level error handling
        if ( is_wp_error( $response ) ) {
            $error_message = $response->get_error_message();
            FourF_PF_Logger::log( "Network connection error: " . $error_message, 'ERROR' );
            return false;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );

        // 2. HTTP protocol-level error handling
        if ( $response_code !== 200 ) {
            FourF_PF_Logger::log( "Remote server returned HTTP error code: {$response_code}", 'ERROR' );
            
            if ( $response_code === 401 || $response_code === 403 ) {
                FourF_PF_Logger::log( "Authentication error: Verify the configured API Key.", 'ERROR' );
            } elseif ( $response_code === 429 ) {
                // Smart retry with exponential backoff for rate limiting
                $max_retries = 3;
                if ( $retry_count < $max_retries ) {
                    $wait_seconds = intval( pow( 2, $retry_count + 1 ) ); // 2, 4, 8 seconds
                    $retry_headers = wp_remote_retrieve_headers( $response );
                    if ( isset( $retry_headers['retry-after'] ) ) {
                        $wait_seconds = max( $wait_seconds, intval( $retry_headers['retry-after'] ) );
                    }
                    FourF_PF_Logger::log( "Rate limited. Retrying in ($wait_seconds) seconds (attempt " . ($retry_count + 1) . " of $max_retries).", 'WARNING' );
                    sleep( $wait_seconds );
                    return $this->fetch_properties( $page, $retry_count + 1 );
                }
                FourF_PF_Logger::log( "All retry attempts exhausted due to rate limiting.", 'ERROR' );
            } elseif ( $response_code >= 500 && $response_code < 600 ) {
                // Retry on server errors (5xx)
                $max_retries = 2;
                if ( $retry_count < $max_retries ) {
                    $wait_seconds = intval( pow( 3, $retry_count + 1 ) ); // 3, 9 seconds
                    FourF_PF_Logger::log( "Server error ($response_code). Retrying in ($wait_seconds) seconds (attempt " . ($retry_count + 1) . " of $max_retries).", 'WARNING' );
                    sleep( $wait_seconds );
                    return $this->fetch_properties( $page, $retry_count + 1 );
                }
                FourF_PF_Logger::log( "All retry attempts exhausted due to server error ($response_code).", 'ERROR' );
            }
            return false;
        }

        // 3. Data integrity check
        if ( empty( $response_body ) ) {
            FourF_PF_Logger::log( "Response 200 OK but payload is completely empty.", 'WARNING' );
            return array(); // Return empty array to trigger smart exit strategy
        }

        $data = json_decode( $response_body, true );

        if ( json_last_error() !== JSON_ERROR_NONE ) {
            FourF_PF_Logger::log( "JSON parse failed (Malformed JSON). Error: " . json_last_error_msg(), 'ERROR' );
            return false;
        }

        // =====================================================================
        // PAGINATION INTELLIGENCE
        // Read total_pages / total from API response to determine exact boundaries.
        // Falls back to per_page-based calculation if total is provided.
        // =====================================================================
        if ( isset( $data['total_pages'] ) ) {
            $this->total_pages = intval( $data['total_pages'] );
            FourF_PF_Logger::log( "API reports total_pages: {$this->total_pages}.", 'INFO' );
        } elseif ( isset( $data['total'] ) && isset( $data['per_page'] ) ) {
            $this->total_pages = (int) ceil( intval( $data['total'] ) / intval( $data['per_page'] ) );
            FourF_PF_Logger::log( "Calculated total_pages from total({$data['total']}) / per_page({$data['per_page']}): {$this->total_pages}.", 'INFO' );
        } elseif ( isset( $data['total'] ) ) {
            $this->total_pages = (int) ceil( intval( $data['total'] ) / 50 );
            FourF_PF_Logger::log( "Calculated total_pages from total({$data['total']}) / default 50: {$this->total_pages}.", 'INFO' );
        }

        // Flexible array structure detection
        $listings = isset( $data['properties'] ) ? $data['properties'] : (isset($data['listings']) ? $data['listings'] : null);

        if ( ! is_array( $listings ) ) {
            FourF_PF_Logger::log( "API data structure mismatch; no properties/listings array found.", 'ERROR' );
            return false;
        }

        $count = count( $listings );
        if ( $count === 0 ) {
            FourF_PF_Logger::log( "Reached end of live API data feed.", 'INFO' );
            return array();
        }

        FourF_PF_Logger::log( "Successfully fetched ($count) properties from page ($page).", 'SUCCESS' );
        return $listings;
    }

    /**
     * Generate mock property data for testing without a real API Key.
     * Activated by defining FOURF_PF_MOCK_MODE in wp-config.php.
     *
     * @param int $page Page number (supports pagination simulation).
     * @return array Mock properties array.
     */
    private function get_mock_properties( $page = 1 ) {
        $all_mock = array(
            array(
                'reference_number' => 'MOCK-PROP-001',
                'title'            => 'Luxury Villa - Dubai Marina',
                'description'      => 'Beautiful luxury villa with panoramic sea view and private pool.',
                'price'            => 2500000,
                'bedrooms'         => 5,
                'bathrooms'        => 4,
                'size'             => 3500,
                'address'          => 'Dubai Marina, Dubai',
                'latitude'         => '25.0748',
                'longitude'        => '55.1424',
                'city'             => 'Dubai',
                'area'             => 'Dubai Marina',
                'category'         => 'Villa',
                'action'           => 'For Sale',
                'features'         => array( 'Balcony', 'Pool', 'Covered Parking', 'Maid Room' ),
                'images'           => array( 'https://picsum.photos/id/106/800/600' ),
                'agent_email'      => get_bloginfo( 'admin_email' ),
                'updated_at'       => current_time( 'mysql' ),
            ),
            array(
                'reference_number' => 'MOCK-PROP-002',
                'title'            => 'عقار فاخر - وسط دبي',
                'description'      => 'عقار فاخر مع إطلالة رائعة على المدينة. يتميز بتصميم عصري ومساحات واسعة.',
                'price'            => 3200000,
                'bedrooms'         => 4,
                'bathrooms'        => 3,
                'size'             => 2800,
                'address'          => 'وسط دبي، دبي',
                'latitude'         => '25.2048',
                'longitude'        => '55.2708',
                'city'             => 'دبي',
                'area'             => 'وسط دبي',
                'category'         => 'Apartment',
                'action'           => 'For Sale',
                'features'         => array( 'Balcony', 'Central AC', 'Concierge' ),
                'images'           => array( 'https://picsum.photos/id/15/800/600' ),
                'agent_email'      => get_bloginfo( 'admin_email' ),
                'updated_at'       => current_time( 'mysql' ),
            ),
            array(
                'reference_number' => 'MOCK-PROP-003',
                'title'            => 'Modern Penthouse - Abu Dhabi',
                'description'      => 'Spacious penthouse with stunning city view and premium finishes.',
                'price'            => 1800000,
                'bedrooms'         => 3,
                'bathrooms'        => 2,
                'size'             => 2200,
                'address'          => 'Al Reem Island, Abu Dhabi',
                'latitude'         => '24.4950',
                'longitude'        => '54.3772',
                'city'             => 'Abu Dhabi',
                'area'             => 'Al Reem Island',
                'category'         => 'Penthouse',
                'action'           => 'For Rent',
                'features'         => array( 'Balcony', 'Gym', 'Pool', 'Security' ),
                'images'           => array( 'https://picsum.photos/id/26/800/600' ),
                'agent_email'      => '',
                'updated_at'       => current_time( 'mysql' ),
            ),
        );

        // Simulate pagination: page 1 returns all, subsequent pages return empty
        if ( $page === 1 ) {
            $this->total_pages = 1;
            FourF_PF_Logger::log( "Mock Mode: Returning " . count( $all_mock ) . " simulated properties.", 'SUCCESS' );
            return $all_mock;
        }

        $this->total_pages = 1;
        FourF_PF_Logger::log( "Mock Mode: No more pages (page {$page} > total_pages 1).", 'INFO' );
        return array();
    }
}