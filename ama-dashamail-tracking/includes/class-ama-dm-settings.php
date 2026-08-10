<?php

defined( 'ABSPATH' ) || exit;

final class AMA_DM_Settings {
	const OPTION = 'ama_dm_settings';

	public static function defaults() {
		return array(
			'enabled'                              => '1',
			'tracker_url'                          => 'https://directcrm.dashamail.com/scripts/v2/tracker.js',
			'product_id_mode'                      => 'yml_offer_id',
			'custom_product_meta_key'              => '',
			'category_id_mode'                     => 'term_id',
			'track_product_views'                  => '1',
			'track_category_views'                 => '1',
			'track_cart'                           => '1',
			'track_orders'                         => '1',
			'track_auth'                           => '1',
			'identify_checkout_email'              => '1',
			'require_marketing_consent'            => '1',
			'checkout_marketing_checkbox_selector' => '#foneona_consent_marketing',
			'relod_newsletter_checkbox_id'         => 'cb_newsletter',
			'subscribe_point_of_contact'           => '',
			'debug_log'                            => '0',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key, $default = null ) {
		$settings = self::all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return null === $default ? '' : $default;
	}

	public static function yes( $key ) {
		return '1' === (string) self::get( $key, '0' );
	}

	public static function sanitize( $input ) {
		$defaults = self::defaults();
		$input    = is_array( $input ) ? $input : array();
		$output   = $defaults;

		$checkboxes = array(
			'enabled',
			'track_product_views',
			'track_category_views',
			'track_cart',
			'track_orders',
			'track_auth',
			'identify_checkout_email',
			'require_marketing_consent',
			'debug_log',
		);
		foreach ( $checkboxes as $key ) {
			$output[ $key ] = ! empty( $input[ $key ] ) ? '1' : '0';
		}

		$tracker_url = isset( $input['tracker_url'] ) ? esc_url_raw( trim( wp_unslash( $input['tracker_url'] ) ) ) : $defaults['tracker_url'];
		$output['tracker_url'] = $tracker_url ? $tracker_url : $defaults['tracker_url'];

		$product_modes = array( 'yml_offer_id', 'variation_or_product_id', 'parent_product_id', 'sku', 'custom_meta' );
		$product_mode  = isset( $input['product_id_mode'] ) ? sanitize_key( $input['product_id_mode'] ) : $defaults['product_id_mode'];
		$output['product_id_mode'] = in_array( $product_mode, $product_modes, true ) ? $product_mode : $defaults['product_id_mode'];

		$output['custom_product_meta_key'] = isset( $input['custom_product_meta_key'] )
			? sanitize_key( wp_unslash( $input['custom_product_meta_key'] ) )
			: '';

		$category_modes = array( 'term_id', 'slug' );
		$category_mode  = isset( $input['category_id_mode'] ) ? sanitize_key( $input['category_id_mode'] ) : $defaults['category_id_mode'];
		$output['category_id_mode'] = in_array( $category_mode, $category_modes, true ) ? $category_mode : $defaults['category_id_mode'];

		$output['checkout_marketing_checkbox_selector'] = isset( $input['checkout_marketing_checkbox_selector'] )
			? sanitize_text_field( wp_unslash( $input['checkout_marketing_checkbox_selector'] ) )
			: $defaults['checkout_marketing_checkbox_selector'];

		$output['relod_newsletter_checkbox_id'] = isset( $input['relod_newsletter_checkbox_id'] )
			? sanitize_key( wp_unslash( $input['relod_newsletter_checkbox_id'] ) )
			: $defaults['relod_newsletter_checkbox_id'];

		$output['subscribe_point_of_contact'] = isset( $input['subscribe_point_of_contact'] )
			? sanitize_text_field( wp_unslash( $input['subscribe_point_of_contact'] ) )
			: '';

		return $output;
	}
}
