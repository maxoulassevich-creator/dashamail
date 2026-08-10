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

			// Обогащение данных.
			'enrich_customer'                      => '1',
			'send_product_name'                    => '1',
			'strict_payload'                       => '0',
			'cart_event_mode'                      => 'both',
			'track_identify_event'                 => '1',
			'subscribe_pending'                    => '1',
			'payment_type_map'                     => '',

			// Идентификаторы операций заказов.
			'op_order_created'                     => 'OrderCreate',
			'op_order_paid'                        => 'OrderPaid',
			'op_order_closed'                      => 'OrderFinished',

			// Раскрытие анонимного посетителя.
			'identify_url_params'                  => 'dm_email,ama_email,subscriber_email',
			'identify_any_email_field'             => '1',
			'remember_identity'                    => '1',
			'email_field_selector'                 => '',
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
			'enrich_customer',
			'send_product_name',
			'strict_payload',
			'track_identify_event',
			'subscribe_pending',
			'identify_any_email_field',
			'remember_identity',
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

		$cart_modes = array( 'both', 'command', 'async' );
		$cart_mode  = isset( $input['cart_event_mode'] ) ? sanitize_key( $input['cart_event_mode'] ) : $defaults['cart_event_mode'];
		$output['cart_event_mode'] = in_array( $cart_mode, $cart_modes, true ) ? $cart_mode : $defaults['cart_event_mode'];

		$output['checkout_marketing_checkbox_selector'] = isset( $input['checkout_marketing_checkbox_selector'] )
			? sanitize_text_field( wp_unslash( $input['checkout_marketing_checkbox_selector'] ) )
			: $defaults['checkout_marketing_checkbox_selector'];

		$output['email_field_selector'] = isset( $input['email_field_selector'] )
			? sanitize_text_field( wp_unslash( $input['email_field_selector'] ) )
			: '';

		$output['relod_newsletter_checkbox_id'] = isset( $input['relod_newsletter_checkbox_id'] )
			? sanitize_key( wp_unslash( $input['relod_newsletter_checkbox_id'] ) )
			: $defaults['relod_newsletter_checkbox_id'];

		$output['subscribe_point_of_contact'] = isset( $input['subscribe_point_of_contact'] )
			? sanitize_text_field( wp_unslash( $input['subscribe_point_of_contact'] ) )
			: '';

		foreach ( array( 'op_order_created', 'op_order_paid', 'op_order_closed' ) as $key ) {
			$value          = isset( $input[ $key ] ) ? sanitize_text_field( wp_unslash( $input[ $key ] ) ) : '';
			$value          = preg_replace( '/[^A-Za-z0-9_]/', '', $value );
			$output[ $key ] = '' !== $value ? $value : $defaults[ $key ];
		}

		$params = isset( $input['identify_url_params'] ) ? sanitize_text_field( wp_unslash( $input['identify_url_params'] ) ) : '';
		$params = array_filter( array_map( 'sanitize_key', array_map( 'trim', explode( ',', $params ) ) ) );
		$output['identify_url_params'] = implode( ',', $params );

		$output['payment_type_map'] = isset( $input['payment_type_map'] )
			? sanitize_textarea_field( wp_unslash( $input['payment_type_map'] ) )
			: '';

		return $output;
	}
}
