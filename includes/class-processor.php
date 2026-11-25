<?php
/**
 * Class FourF_PF_Processor
 * Core engine: data processing, mapping, image hashing, and secure purge logic.
 *
 * @package 4F-PF-Sync
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class FourF_PF_Processor {

    private static $instance = null;
    private $api_client;
    private $skipped_property_ids = array();

    /**
     * Allowed HTML tags for property description sanitization.
     * Strips potential malicious iframes or hidden scripts from the API feed.
     */
    private $allowed_html_tags = array(
        'p'      => array(),
        'br'     => array(),
        'strong' => array(),
        'b'      => array(),
        'em'     => array(),
        'i'      => array(),
        'ul'     => array(),
        'ol'     => array(),
        'li'     => array(),
        'h1'     => array(),
        'h2'     => array(),
        'h3'     => array(),
        'h4'     => array(),
        'h5'     => array(),
        'h6'     => array(),
        'span'   => array(),
        'a'      => array(
            'href'   => true,
            'title'  => true,
            'target' => true,
            'rel'    => true,
        ),
    );

    /**
     * WP Residence meta key mapping — verified against live schema dump.
     * Keys prefixed with 'pf_' are Property Finder source fields.
     *
     * @var array
     */
    private $wp_residence_meta_map = array(
        // Core property fields
        'price'             => 'property_price',
        'bedrooms'          => 'property_bedrooms',
        'bathrooms'         => 'property_bathrooms',
        'size'              => 'property_size',
        'lot_size'          => 'property_lot_size',
        'rooms'             => 'property_rooms',
        'address'           => 'property_address',
        'zip'               => 'property_zip',
        'country'           => 'property_country',
        'latitude'          => 'property_latitude',
        'longitude'         => 'property_longitude',

        // Financial fields
        'hoa'               => 'property_hoa',
        'yearly_tax'        => 'property_year_tax',
        'cam_fee'           => 'cam-fee',

        // Listing details
        'reference_number'  => 'uid',
        'label'             => 'property_label',
        'label_before'      => 'property_label_before',
        'garage'            => 'property-garage',
        'garage_size'       => 'property-garage-size',
        'basement'          => 'property-basement',
        'year_built'        => 'property-year',
        'completion_date'   => 'property-date',

        // Project / building fields
        'building_name'     => 'building',
        'project_name'      => 'project-name',
        'developer'         => 'project-developer',
        'total_floors'      => 'project-floors',
        'total_buildings'   => 'project-buildings',
        'total_units'       => 'project-units',

        // Unit details
        'unit_id'           => 'unit-id',
        'unit_type'         => 'unit-type',
        'apartment_ownership' => 'apartment-ownership',
        'nearest_landmark'  => 'nearest-landmark',

        // Energy
        'energy_class'      => 'energy_class',
        'energy_index'      => 'energy_index',

        // Agent / ownership
        'agent_email'       => 'property_agent',        // resolved to agent ID
        'agent_id'          => 'property_agent',         // resolved to agent ID
        'owner_notes'       => 'owner_notes',

        // Featured / status
        'featured'          => 'prop_featured',
    );

    /**
     * Bilingual field map: WP Residence meta keys for Arabic content.
     * The API does NOT send separate 'title_ar' / 'description_ar' fields.
     * Instead, the API sends 'title' and 'description' which may contain
     * Arabic text depending on the user's language preference.
     *
     * Strategy: For each field, detect if the source text is Arabic using
     * Unicode range detection (\x{0600}-\x{06FF}). If Arabic, store in both
     * the default meta key AND the Arabic-specific meta key.
     * If English (or other), store only in the default meta key.
     *
     * If the API DOES send separate Arabic fields (e.g., title_ar), those
     * take priority and are detected in process_single_property().
     */
    private $bilingual_fields = array(
        'title'       => 'property_title_ar',
        'description' => 'property_description_ar',
        'address'     => 'property_address_ar',
        'city'        => 'property_city_ar',
        'area'        => 'property_area_ar',
    );

    public static function get_instance() {
        if ( is_null( self::$instance ) ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->api_client = new FourF_PF_API_Client();
    }

    /**
     * Sync cycle commander: manages pagination, delta logic, bulk updates, purge, and cache flush.
     */
    public function start_synchronization() {
        if ( ! ini_get('safe_mode') ) {
            @set_time_limit(0);
        }

        $current_sync_time = time();
        $start_time = time();
        $max_sync_seconds = apply_filters( 'fourf_pf_max_sync_seconds', 300 );
        $page = 1;
        $total_processed = 0;
        $this->skipped_property_ids = array();
        $properties_seen = 0;
        $api_error_occurred = false;
        $sync_completed = false;
        $api_total_pages = 0;

        // Dynamic pagination: read total_pages from API response on first call,
        // fall back to filter-based max if API doesn't provide pagination info.
        $max_pages = apply_filters( 'fourf_pf_max_sync_pages', 200 );

        FourF_PF_Logger::log( "--- Starting new sync cycle [Sync ID: $current_sync_time] ---", 'INFO' );

        while ( $page <= $max_pages && ( time() - $start_time ) < $max_sync_seconds ) {
            $properties_feed = $this->api_client->fetch_properties( $page );

            // On first successful API response, read total_pages from the client
            if ( 1 === $page && false !== $properties_feed && ! empty( $properties_feed ) ) {
                $api_total_pages = (int) $this->api_client->get_total_pages();
                if ( $api_total_pages > 0 ) {
                    $max_pages = min( $api_total_pages, $max_pages );
                    FourF_PF_Logger::log( "Pagination adjusted: API reports {$api_total_pages} total pages. Processing up to {$max_pages} pages within the configured safety limit.", 'INFO' );
                }
            }

            if ( false === $properties_feed ) {
                FourF_PF_Logger::log( "Connection failed at page ($page). Aborting sync to preserve data integrity.", 'ERROR' );
                $api_error_occurred = true;
                break;
            }

            if ( empty( $properties_feed ) ) {
                FourF_PF_Logger::log( "Reached end of feed at page ($page).", 'INFO' );
                $sync_completed = true;
                break;
            }

            foreach ( $properties_feed as $pf_item ) {
                $this->process_single_property( $pf_item, $current_sync_time );
                $total_processed++;
                $properties_seen++;
            }

            // Memory management for constrained servers
            if ( $page % 5 === 0 && function_exists( 'wp_cache_flush_runtime' ) ) {
                wp_cache_flush_runtime();
            }

            // A trusted API-reported final page completes the feed without an extra empty request.
            if ( $api_total_pages > 0 && $page >= $api_total_pages ) {
                $sync_completed = true;
                FourF_PF_Logger::log( "Reached API-reported final page ({$api_total_pages}).", 'INFO' );
                break;
            }

            $page++;
        }

        if ( $page > $max_pages && ! $sync_completed ) {
            FourF_PF_Logger::log( "Warning: Reached max page limit ($max_pages). Additional data may exist on the API.", 'WARNING' );
        }

        if ( ( time() - $start_time ) >= $max_sync_seconds ) {
            FourF_PF_Logger::log( "Sync stopped due to timeout after {$max_sync_seconds} seconds.", 'WARNING' );
        }

        // Purge is permitted only after the complete upstream feed has been read.
        // Timeouts and local page limits are partial syncs, not evidence of deleted properties.
        if ( ! $sync_completed || 0 === $properties_seen || $api_error_occurred ) {
            $reason = ! $sync_completed
                ? 'Feed was not fully processed (timeout or page limit reached).'
                : ( 0 === $properties_seen ? 'API returned zero properties.' : 'API error detected.' );
            FourF_PF_Logger::log(
                "CRITICAL SAFETY: {$reason} Bulk timestamp update and purge operations ABORTED to prevent data loss.",
                'ERROR'
            );
        } else {
            // Performance optimization: bulk-update timestamps for unchanged properties in a single query
            $this->bulk_update_skipped_timestamps( $current_sync_time );

            // Purge database of properties deleted from the API source
            $this->purge_deleted_properties( $current_sync_time );
        }

        // Final cache cleanup (LiteSpeed & Object Cache)
        $this->clear_system_cache();

        // Release temporary variables to reduce memory footprint
        unset( $properties_feed, $pf_item );

        FourF_PF_Logger::log( "Sync cycle completed successfully. Total processed: $total_processed properties.", 'SUCCESS' );
    }

    /**
     * Process a single property from the API feed.
     * Implements atomic transaction safety: post is created as 'draft' and only
     * transitioned to 'publish' after all meta, taxonomies, and images are processed.
     *
     * @param array $pf_item          Single property data from API.
     * @param int   $current_sync_time Unix timestamp of current sync cycle.
     */
    private function process_single_property( $pf_item, $current_sync_time ) {
        // Guard against PHP notices for missing API fields
        if ( ! isset( $pf_item['reference_number'] ) || empty( $pf_item['reference_number'] ) ) {
            FourF_PF_Logger::log( "Skipped property: missing reference_number in API data.", 'WARNING' );
            return;
        }
        $remote_id = sanitize_text_field( $pf_item['reference_number'] );

        // [Property REF: PF-xxxxx] identifier for traceability in logs
        $log_ref = '[Property REF: ' . $remote_id . ']';

        $api_last_updated = isset( $pf_item['updated_at'] ) ? sanitize_text_field( $pf_item['updated_at'] ) : '1.0';
        $existing_post_id = $this->get_property_by_remote_id( $remote_id );

        // Compute data hash for integrity-checked delta sync
        $data_hash = md5( wp_json_encode( $pf_item ) );

        if ( $existing_post_id ) {
            $local_last_updated = get_post_meta( $existing_post_id, '_4f_pf_last_updated_api', true );
            $local_data_hash    = get_post_meta( $existing_post_id, '_4f_pf_data_hash', true );

            // Delta Sync Logic (Double-Check): skip only if BOTH updated_at AND data_hash match
            if ( $local_last_updated === $api_last_updated && $local_data_hash === $data_hash ) {
                $this->skipped_property_ids[] = $existing_post_id;
                return;
            }

            $post_id = $existing_post_id;
            global $wpdb;

            // Sanitize description with granular allowed tags
            $sanitized_content = isset( $pf_item['description'] )
                ? wp_kses( $pf_item['description'], $this->allowed_html_tags )
                : '';

            $wpdb->update(
                $wpdb->posts,
                array(
                    'post_title'        => isset( $pf_item['title'] ) ? sanitize_text_field( $pf_item['title'] ) : '',
                    'post_content'      => $sanitized_content,
                    'post_modified'     => current_time( 'mysql' ),
                    'post_modified_gmt' => current_time( 'mysql', 1 ),
                ),
                array( 'ID' => $post_id )
            );
            clean_post_cache( $post_id );
        } else {
            // Duplicate prevention: defensively verify remote_id does not already exist before insert
            $existing_check = $this->get_property_by_remote_id( $remote_id );
            if ( $existing_check ) {
                FourF_PF_Logger::log( "$log_ref Duplicate prevention triggered: remote_id already exists (ID: $existing_check). Skipping creation.", 'WARNING' );
                return;
            }

            // Sanitize description with granular allowed tags
            $sanitized_content = isset( $pf_item['description'] )
                ? wp_kses( $pf_item['description'], $this->allowed_html_tags )
                : '';

            // Atomic transaction safety: create as 'draft' to prevent partial imports
            $post_id = wp_insert_post( array(
                'post_title'     => isset( $pf_item['title'] ) ? sanitize_text_field( $pf_item['title'] ) : '',
                'post_content'   => $sanitized_content,
                'post_status'    => 'draft',
                'post_type'      => 'estate_property',
                'comment_status' => 'closed',
                'ping_status'    => 'closed',
            ) );

            if ( is_wp_error( $post_id ) ) {
                FourF_PF_Logger::log( "$log_ref wp_insert_post() failed: " . $post_id->get_error_message(), 'ERROR' );
                return;
            }

            if ( $post_id ) {
                update_post_meta( $post_id, '_4f_pf_remote_id', $remote_id );
                FourF_PF_Logger::log( "$log_ref Created new property (ID: $post_id) as draft.", 'INFO' );
            }
        }

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            FourF_PF_Logger::log( "$log_ref Invalid post_id after insert/update. Aborting property processing.", 'ERROR' );
            return;
        }

        // Update sync flags
        update_post_meta( $post_id, '_4f_pf_last_updated_api', $api_last_updated );
        update_post_meta( $post_id, '_4f_pf_data_hash', $data_hash );
        update_post_meta( $post_id, '_last_sync_timestamp', $current_sync_time );

        // =====================================================================
        // DYNAMIC META FIELD MAPPING
        // Uses the $wp_residence_meta_map array verified against live schema dump.
        // Each PF API field is mapped to its corresponding WP Residence meta key.
        // =====================================================================
        foreach ( $this->wp_residence_meta_map as $pf_field => $wp_meta_key ) {
            // Skip agent_email and agent_id — handled separately by get_mapped_agent_id()
            if ( in_array( $pf_field, array( 'agent_email', 'agent_id' ), true ) ) {
                continue;
            }

            if ( ! isset( $pf_item[ $pf_field ] ) || '' === $pf_item[ $pf_field ] ) {
                continue;
            }

            $value = $pf_item[ $pf_field ];

            // Type-based sanitization
            if ( is_float( $value ) || is_numeric( $value ) ) {
                $sanitized = floatval( $value );
            } elseif ( is_string( $value ) ) {
                $sanitized = sanitize_text_field( $value );
            } else {
                $sanitized = sanitize_text_field( strval( $value ) );
            }

            update_post_meta( $post_id, $wp_meta_key, $sanitized );
        }

        // =====================================================================
        // BILINGUAL SUPPORT (Arabic / English)
        // The API does NOT send separate 'title_ar' / 'description_ar' fields.
        // Instead, the 'title' and 'description' fields may contain Arabic text.
        //
        // Strategy:
        // 1. If the API sends explicit Arabic fields (e.g., title_ar), use them.
        // 2. Otherwise, detect Arabic text in the source field using Unicode range.
        // 3. If Arabic detected, store in both default AND Arabic-specific meta keys.
        // 4. If English (or other), store only in the default meta key.
        // =====================================================================
        foreach ( $this->bilingual_fields as $pf_field => $wp_meta_key_ar ) {
            if ( ! isset( $pf_item[ $pf_field ] ) || empty( $pf_item[ $pf_field ] ) ) {
                continue;
            }

            $source_text = $pf_item[ $pf_field ];
            $is_arabic   = $this->is_arabic_text( $source_text );

            // Check if API sent an explicit Arabic version (e.g., title_ar)
            $explicit_ar_field = $pf_field . '_ar';
            $has_explicit_ar   = isset( $pf_item[ $explicit_ar_field ] ) && ! empty( $pf_item[ $explicit_ar_field ] );

            if ( $has_explicit_ar ) {
                // API provided explicit Arabic field — store it
                update_post_meta( $post_id, $wp_meta_key_ar, sanitize_text_field( $pf_item[ $explicit_ar_field ] ) );
                FourF_PF_Logger::log( "[Bilingual] Stored explicit Arabic '{$explicit_ar_field}' in '{$wp_meta_key_ar}'.", 'INFO' );
            } elseif ( $is_arabic ) {
                // Source text is Arabic — store in both default AND Arabic meta key
                update_post_meta( $post_id, $wp_meta_key_ar, sanitize_text_field( $source_text ) );
                FourF_PF_Logger::log( "[Bilingual] Detected Arabic text in '{$pf_field}'. Stored in '{$wp_meta_key_ar}'.", 'INFO' );
            }
            // If English text without explicit Arabic field, only the default meta key
            // (already stored by the dynamic meta mapping loop above) is used.
        }

        // =====================================================================
        // AGENT MAPPING (Dual Strategy: Agent ID first, then Email)
        // Per the assessment: match incoming PF Agent ID or email with existing
        // WordPress users or WP Residence Agent post types.
        // =====================================================================
        $pf_agent_id    = isset( $pf_item['agent_id'] ) ? sanitize_text_field( $pf_item['agent_id'] ) : '';
        $pf_agent_email = isset( $pf_item['agent_email'] ) ? sanitize_email( $pf_item['agent_email'] ) : '';
        $agent_id       = $this->get_mapped_agent_id( $pf_agent_id, $pf_agent_email );
        update_post_meta( $post_id, 'property_agent', $agent_id );
        FourF_PF_Logger::log( "$log_ref Mapped agent ID: $agent_id for property (ID: $post_id).", 'INFO' );

        // Taxonomies
        $this->map_taxonomies( $post_id, $pf_item, $remote_id );

        // Media Handling
        if ( ! empty( $pf_item['images'] ) ) {
            $this->handle_property_images( $post_id, $pf_item['images'], $remote_id );
        }

        // Atomic transaction safety: transition from 'draft' to 'publish' only after
        // all meta, taxonomies, and images are successfully processed.
        $current_status = get_post_status( $post_id );
        if ( 'draft' === $current_status ) {
            wp_update_post( array(
                'ID'          => $post_id,
                'post_status' => 'publish',
            ) );
            FourF_PF_Logger::log( "$log_ref Property (ID: $post_id) transitioned from draft to publish.", 'INFO' );
        }
    }

    /**
     * Map taxonomies with hierarchical support for city/area relationships.
     * Uses strict parent-aware term lookup to prevent collisions (e.g., "Marina" in Dubai vs. Abu Dhabi).
     *
     * @param int    $post_id   The property post ID.
     * @param array  $pf_item   Single property data from API.
     * @param string $remote_id Remote identifier for logging.
     */
    private function map_taxonomies( $post_id, $pf_item, $remote_id = '' ) {
        $log_ref = $remote_id ? '[Property REF: ' . $remote_id . ']' : '';

        // Confirmed against live WP Residence schema inspection.
        $tax_map = array(
            'category' => 'property_category',
            'action'   => 'property_action_category',
            'city'     => 'property_city',
            'area'     => 'property_area',
            'features' => 'property_features',
        );

        // First pass: store city and area values for hierarchical processing
        $city_name = isset( $pf_item['city'] ) ? sanitize_text_field( $pf_item['city'] ) : '';
        $area_name = isset( $pf_item['area'] ) ? sanitize_text_field( $pf_item['area'] ) : '';

        // Process hierarchical taxonomies (city -> area)
        if ( ! empty( $city_name ) ) {
            $city_term = $this->find_or_create_term( $city_name, 'property_city', 0, $remote_id );
            if ( $city_term && ! is_wp_error( $city_term ) ) {
                $city_result = wp_set_object_terms( $post_id, (int) $city_term['term_id'], 'property_city', false );
                if ( is_wp_error( $city_result ) ) {
                    FourF_PF_Logger::log( "$log_ref wp_set_object_terms() failed for property_city: " . $city_result->get_error_message(), 'ERROR' );
                }
            }
        }

        if ( ! empty( $area_name ) ) {
            $parent_term_id = 0;
            if ( ! empty( $city_name ) ) {
                // Use parent-aware term_exists to prevent collisions
                $city_term = term_exists( $city_name, 'property_city' );
                if ( $city_term && ! is_wp_error( $city_term ) ) {
                    $parent_term_id = is_array( $city_term ) ? (int) $city_term['term_id'] : (int) $city_term;
                }
            }
            $area_term = $this->find_or_create_term( $area_name, 'property_area', $parent_term_id, $remote_id );
            if ( $area_term && ! is_wp_error( $area_term ) ) {
                $area_result = wp_set_object_terms( $post_id, (int) $area_term['term_id'], 'property_area', false );
                if ( is_wp_error( $area_result ) ) {
                    FourF_PF_Logger::log( "$log_ref wp_set_object_terms() failed for property_area: " . $area_result->get_error_message(), 'ERROR' );
                }
            }
        }

        // Process remaining flat taxonomies (category, action, features)
        foreach ( $tax_map as $key => $taxonomy ) {
            // Skip city and area as they were handled hierarchically above
            if ( 'city' === $key || 'area' === $key ) {
                continue;
            }

            if ( isset( $pf_item[ $key ] ) && ! empty( $pf_item[ $key ] ) ) {
                $term_value = $pf_item[ $key ];

                // Handle features which may be an array
                if ( 'features' === $key && is_array( $term_value ) ) {
                    $term_ids = array();
                    foreach ( $term_value as $feature ) {
                        $feature = sanitize_text_field( $feature );
                        if ( ! empty( $feature ) ) {
                            $term = $this->find_or_create_term( $feature, $taxonomy, 0, $remote_id );
                            if ( $term && ! is_wp_error( $term ) ) {
                                $term_ids[] = (int) $term['term_id'];
                            }
                        }
                    }
                    if ( ! empty( $term_ids ) ) {
                        $feature_result = wp_set_object_terms( $post_id, $term_ids, $taxonomy, false );
                        if ( is_wp_error( $feature_result ) ) {
                            FourF_PF_Logger::log( "$log_ref wp_set_object_terms() failed for $taxonomy: " . $feature_result->get_error_message(), 'ERROR' );
                        }
                    }
                } else {
                    $term = $this->find_or_create_term( sanitize_text_field( $term_value ), $taxonomy, 0, $remote_id );
                    if ( $term && ! is_wp_error( $term ) ) {
                        $term_result = wp_set_object_terms( $post_id, (int) $term['term_id'], $taxonomy, false );
                        if ( is_wp_error( $term_result ) ) {
                            FourF_PF_Logger::log( "$log_ref wp_set_object_terms() failed for $taxonomy: " . $term_result->get_error_message(), 'ERROR' );
                        }
                    }
                }
            }
        }
    }

    /**
     * Find an existing term or create it dynamically with sanitized name/slug.
     * Uses parent-aware lookup to prevent collisions between identical names in different branches.
     *
     * @param string $term_name  The term name to find or create.
     * @param string $taxonomy   The taxonomy to search within.
     * @param int    $parent     Optional parent term ID for hierarchical taxonomies.
     * @param string $remote_id  Remote identifier for logging.
     * @return array|false Term array on success, false on failure.
     */
    private function find_or_create_term( $term_name, $taxonomy, $parent = 0, $remote_id = '' ) {
        $log_ref = $remote_id ? '[Property REF: ' . $remote_id . ']' : '';

        // Sanitize term name and slug before any operation
        $term_name = sanitize_text_field( $term_name );
        if ( empty( $term_name ) ) {
            return false;
        }

        $term_slug = sanitize_title( $term_name );

        // Parent-aware term lookup: use term_exists with parent parameter to prevent
        // collisions between identical names in different branches (e.g., "Marina" in Dubai vs. Abu Dhabi).
        $existing_term = term_exists( $term_name, $taxonomy, $parent );
        if ( $existing_term && ! is_wp_error( $existing_term ) ) {
            return is_array( $existing_term ) ? $existing_term : get_term( (int) $existing_term, $taxonomy, ARRAY_A );
        }

        // Term does not exist — create it dynamically
        $args = array(
            'slug' => $term_slug,
        );
        if ( $parent > 0 ) {
            $args['parent'] = $parent;
        }

        $new_term = wp_insert_term( $term_name, $taxonomy, $args );

        if ( is_wp_error( $new_term ) ) {
            FourF_PF_Logger::log( "$log_ref wp_insert_term() failed for '{$term_name}' in '{$taxonomy}': " . $new_term->get_error_message(), 'ERROR' );
            return false;
        }

        FourF_PF_Logger::log( "$log_ref Created new term '{$term_name}' in '{$taxonomy}'" . ( $parent > 0 ? " with parent ID: {$parent}" : '' ) . ".", 'INFO' );
        return $new_term;
    }

    /**
     * Handle property image downloads, deduplication, and gallery attachment.
     * Uses content-based hashing (md5_file) for robust deduplication.
     * Implements gallery reconciliation to remove images no longer present in the API.
     *
     * @param int    $post_id   The property post ID.
     * @param array  $images    Array of image URLs.
     * @param string $remote_id Remote identifier for logging.
     */
    private function handle_property_images( $post_id, $images, $remote_id = '' ) {
        require_once( ABSPATH . 'wp-admin/includes/image.php' );
        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        require_once( ABSPATH . 'wp-admin/includes/media.php' );

        $log_ref = $remote_id ? '[Property REF: ' . $remote_id . ']' : '';
        $gallery_ids = array();
        $featured_set = has_post_thumbnail( $post_id );

        foreach ( $images as $image_url ) {
            $image_url = esc_url_raw( $image_url );
            if ( empty( $image_url ) ) {
                continue;
            }

            $tmp = null;
            try {
                $tmp = download_url( $image_url );

                // Strict WP_Error validation for download_url()
                if ( is_wp_error( $tmp ) ) {
                    FourF_PF_Logger::log( "$log_ref download_url() failed for image: {$image_url}. Error: " . $tmp->get_error_message(), 'ERROR' );
                    continue;
                }

                // MIME type validation: download_url() may return an HTML error page
                // instead of an image. Validate before processing to prevent
                // media_handle_sideload() from failing with an unclear error.
                $mime = mime_content_type( $tmp );
                if ( strpos( $mime, 'image/' ) !== 0 ) {
                    FourF_PF_Logger::log( "$log_ref Skipped non-image file from {$image_url}: detected MIME type '{$mime}'.", 'WARNING' );
                    continue;
                }

                // Content-based hashing: use md5_file() on the actual downloaded file content.
                // This is more robust than URL-based hashing because it survives URL changes.
                $image_hash = md5_file( $tmp );

                global $wpdb;
                $attachment_id = $wpdb->get_var( $wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_4f_image_hash' AND meta_value = %s LIMIT 1",
                    $image_hash
                ) );

                if ( ! $attachment_id ) {
                    $file_array = array(
                        'name'     => basename( $image_url ) . '.jpg',
                        'tmp_name' => $tmp,
                    );

                    $attachment_id = media_handle_sideload( $file_array, $post_id );

                    if ( is_wp_error( $attachment_id ) ) {
                        FourF_PF_Logger::log( "$log_ref media_handle_sideload() failed for image: {$image_url}. Error: " . $attachment_id->get_error_message(), 'ERROR' );
                        continue;
                    }

                    update_post_meta( $attachment_id, '_4f_image_hash', $image_hash );
                    FourF_PF_Logger::log( "$log_ref Downloaded and attached new image (Attachment ID: $attachment_id, Hash: $image_hash).", 'INFO' );
                } else {
                    FourF_PF_Logger::log( "$log_ref Image already exists (Attachment ID: $attachment_id, Hash: $image_hash). Skipping download.", 'INFO' );
                }

                if ( $attachment_id && ! is_wp_error( $attachment_id ) ) {
                    $gallery_ids[] = $attachment_id;
                    if ( ! $featured_set ) {
                        set_post_thumbnail( $post_id, $attachment_id );
                        $featured_set = true;
                    }
                }
            } finally {
                // Ensure temporary file is always removed, even on error.
                // Prevents temporary file accumulation on production servers.
                if ( isset( $tmp ) && file_exists( $tmp ) ) {
                    @unlink( $tmp );
                }
            }
        }

        // Gallery Reconciliation: remove images no longer present in the API feed.
        // If the API returns 5 images and the site has 10, remove the outdated ones.
        $this->reconcile_gallery( $post_id, $gallery_ids, $log_ref );

        // Release temporary variables to reduce memory footprint
        unset( $gallery_ids, $images, $image_url, $image_hash, $attachment_id, $tmp, $file_array );
    }

    /**
     * Reconcile the gallery: remove images from meta that are no longer in the API response.
     *
     * @param int    $post_id     The property post ID.
     * @param array  $gallery_ids Array of current attachment IDs from the API.
     * @param string $log_ref     Log reference string.
     */
    private function reconcile_gallery( $post_id, $gallery_ids, $log_ref ) {
        if ( empty( $gallery_ids ) ) {
            // API returned no images — clear existing gallery meta
            $existing_gallery = get_post_meta( $post_id, 'image_to_attach', true );
            if ( ! empty( $existing_gallery ) ) {
                delete_post_meta( $post_id, 'image_to_attach' );
                delete_post_meta( $post_id, 'image_to_attach_string' );
                FourF_PF_Logger::log( "$log_ref Gallery reconciled: API returned no images. Cleared existing gallery.", 'INFO' );
            }
            return;
        }

        // Update gallery meta with the current set of images from the API
        update_post_meta( $post_id, 'image_to_attach', $gallery_ids );
        update_post_meta( $post_id, 'image_to_attach_string', implode( ',', $gallery_ids ) );
        FourF_PF_Logger::log( "$log_ref Gallery updated with " . count( $gallery_ids ) . " images.", 'INFO' );
    }

    /**
     * Map agent by PF Agent ID or email to a WP Residence agent post ID.
     *
     * Dual-strategy matching per the assessment requirements:
     * 1. First, attempt to match by PF Agent ID (stored in agent_custom_id meta)
     * 2. Fall back to email matching
     * 3. If neither matches, return the House Account fallback
     *
     * @param string $pf_agent_id   Property Finder Agent ID.
     * @param string $agent_email   Agent email address.
     * @return int Agent post ID or fallback house account ID.
     */
    private function get_mapped_agent_id( $pf_agent_id = '', $agent_email = '' ) {
        $fallback_id = (int) get_option( FOURF_PF_HOUSE_ACCOUNT_OPTION, 0 );

        // Strategy 1: Match by PF Agent ID (stored in agent_custom_id meta)
        if ( ! empty( $pf_agent_id ) ) {
            global $wpdb;
            $agent_by_id = $wpdb->get_var( $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'agent_custom_id' AND pm.meta_value = %s
                 AND p.post_type = 'estate_agent' AND p.post_status = 'publish'
                 LIMIT 1",
                sanitize_text_field( $pf_agent_id )
            ) );

            if ( $agent_by_id ) {
                FourF_PF_Logger::log( "Agent matched by PF Agent ID: {$pf_agent_id} -> Post ID: {$agent_by_id}.", 'INFO' );
                return (int) $agent_by_id;
            }

            FourF_PF_Logger::log( "Agent ID '{$pf_agent_id}' not found. Falling back to email matching.", 'INFO' );
        }

        // Strategy 2: Match by email
        if ( ! empty( $agent_email ) ) {
            global $wpdb;
            $agent_by_email = $wpdb->get_var( $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = 'agent_email' AND pm.meta_value = %s
                 AND p.post_type = 'estate_agent' AND p.post_status = 'publish'
                 LIMIT 1",
                sanitize_email( $agent_email )
            ) );

            if ( $agent_by_email ) {
                FourF_PF_Logger::log( "Agent matched by email: {$agent_email} -> Post ID: {$agent_by_email}.", 'INFO' );
                return (int) $agent_by_email;
            }
        }

        // Strategy 3: Fallback to House Account
        FourF_PF_Logger::log(
            "No matching agent found (ID: '{$pf_agent_id}', Email: '{$agent_email}'). "
            . "Mapping to House Account (ID: {$fallback_id}).",
            'WARNING'
        );
        return $fallback_id;
    }

    /**
     * Bulk update sync timestamps for skipped (unchanged) properties.
     *
     * @param int $current_sync_time Unix timestamp of current sync cycle.
     */
    private function bulk_update_skipped_timestamps( $current_sync_time ) {
        if ( empty( $this->skipped_property_ids ) ) return;
        global $wpdb;
        $ids = implode( ',', array_map( 'intval', $this->skipped_property_ids ) );
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$wpdb->postmeta} SET meta_value = %d WHERE meta_key = '_last_sync_timestamp' AND post_id IN ($ids)",
            $current_sync_time
        ) );
    }

    /**
     * Purge properties that were deleted from the API source.
     * Only targets properties originally synchronized by this engine (have _4f_pf_remote_id meta).
     * Strictly protects manually created properties.
     *
     * @param int $current_sync_time Unix timestamp of current sync cycle.
     */
    private function purge_deleted_properties( $current_sync_time ) {
        global $wpdb;

        // Safety: only delete properties that have the _4f_pf_remote_id meta key,
        // ensuring manually created properties are never touched.
        // Only purge properties that have a _last_sync_timestamp AND it differs
        // from the current cycle. Properties without a timestamp (first sync or
        // failed cycle) are NEVER purged — prevents data loss.
        $query = $wpdb->prepare(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_last_sync_timestamp'
             WHERE p.post_type = 'estate_property'
             AND p.post_status IN ('publish', 'private', 'pending')
             AND EXISTS (
                 SELECT 1 FROM {$wpdb->postmeta}
                 WHERE post_id = p.ID
                 AND meta_key = '_4f_pf_remote_id'
             )
             AND pm.meta_value IS NOT NULL
             AND pm.meta_value != %s",
            strval( $current_sync_time )
        );

        $deleted = $wpdb->get_col( $query );
        if ( ! empty( $deleted ) ) {
            foreach ( $deleted as $id ) {
                wp_trash_post( $id );
                FourF_PF_Logger::log( "Purged property (ID: $id) deleted from the API source.", 'WARNING' );
            }
            FourF_PF_Logger::log( "Purged (" . count($deleted) . ") properties deleted from the API source.", 'WARNING' );
        }
    }

    /**
     * Detect if a given text contains Arabic characters.
     * Uses Unicode range \x{0600}-\x{06FF} which covers the Arabic script block.
     *
     * @param string $text The text to check.
     * @return bool True if Arabic characters are detected.
     */
    private function is_arabic_text( $text ) {
        if ( empty( $text ) ) {
            return false;
        }
        return (bool) preg_match( '/[\x{0600}-\x{06FF}]/u', $text );
    }

    /**
     * Clear system caches (LiteSpeed + Object Cache).
     */
    private function clear_system_cache() {
        if ( class_exists( 'LiteSpeed_Cache_API' ) ) {
            LiteSpeed_Cache_API::purge_all();
        }
        if ( function_exists( 'wp_cache_flush' ) ) {
            wp_cache_flush();
        }
        if ( function_exists( 'wp_cache_flush_runtime' ) ) {
            wp_cache_flush_runtime();
        }
    }

    /**
     * Retrieve a property post ID by its remote identifier.
     *
     * @param string $remote_id The remote property reference number.
     * @return string|null Post ID or null if not found.
     */
    private function get_property_by_remote_id( $remote_id ) {
        global $wpdb;
        return $wpdb->get_var( $wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_4f_pf_remote_id' AND meta_value = %s LIMIT 1",
            $remote_id
        ) );
    }
}
