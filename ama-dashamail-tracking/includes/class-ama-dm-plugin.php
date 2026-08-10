<?php

defined( 'ABSPATH' ) || exit;

final class AMA_DM_Plugin {
	private static $instance = null;
	private $checkout_created_events = array();
	private $processing_coupon       = false;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate() {
		if ( false === get_option( AMA_DM_Settings::OPTION, false ) ) {
			add_option( AMA_DM_Settings::OPTION, AMA_DM_Settings::defaults(), '', false );
		}
	}

	private function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_post_ama_dm_clear_log', array( $this, 'clear_log' ) );

		if ( ! AMA_DM_Settings::yes( 'enabled' ) ) {
			return;
		}

		add_action( 'wp_head', array( $this, 'print_tracker_bootstrap' ), 1 );
		add_filter( 'script_loader_tag', array( $this, 'async_tracker_script' ), 10, 3 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ), 50 );
		add_action( 'wp_ajax_ama_dm_pull_events', array( $this, 'ajax_pull_events' ) );
		add_action( 'wp_ajax_nopriv_ama_dm_pull_events', array( $this, 'ajax_pull_events' ) );

		if ( AMA_DM_Settings::yes( 'track_product_views' ) ) {
			add_filter( 'woocommerce_available_variation', array( $this, 'variation_tracking_data' ), 20, 3 );
		}

		if ( AMA_DM_Settings::yes( 'track_cart' ) ) {
			add_action( 'woocommerce_add_to_cart', array( $this, 'on_add_to_cart' ), 20, 6 );
			add_action( 'woocommerce_cart_item_removed', array( $this, 'on_cart_item_removed' ), 20, 2 );
			add_action( 'woocommerce_after_cart_item_quantity_update', array( $this, 'on_quantity_update' ), 20, 4 );
			add_action( 'woocommerce_cart_emptied', array( $this, 'on_cart_emptied' ), 20 );
			add_action( 'woocommerce_applied_coupon', array( $this, 'on_coupon_applied' ), 20 );
			add_action( 'woocommerce_after_calculate_totals', array( $this, 'after_calculate_totals' ), 100 );
		}

		if ( AMA_DM_Settings::yes( 'track_orders' ) ) {
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_created' ), 90, 3 );
			add_filter( 'woocommerce_payment_successful_result', array( $this, 'add_events_to_checkout_result' ), 90, 2 );
			add_action( 'woocommerce_payment_complete', array( $this, 'on_order_paid' ), 90 );
			add_action( 'woocommerce_order_status_changed', array( $this, 'on_order_status_changed' ), 90, 4 );
		}

		if ( AMA_DM_Settings::yes( 'track_auth' ) ) {
			add_action( 'wp_login', array( $this, 'on_login' ), 90, 2 );
			add_action( 'relod_auth_after_register', array( $this, 'on_relod_register' ), 90, 2 );
			add_action( 'user_register', array( $this, 'on_generic_register' ), 90, 2 );
		}
	}

	/* -----------------------------------------------------------------
	 * Front-end tracker
	 * ----------------------------------------------------------------- */

	public function print_tracker_bootstrap() {
		if ( is_admin() ) {
			return;
		}
		?>
		<script id="ama-dashamail-bootstrap">
		window.dashamail = window.dashamail || function(){ (window.dashamail.queue = window.dashamail.queue || []).push(arguments); };
		window.dashamail.queue = window.dashamail.queue || [];
		window.dashamail('create');
		</script>
		<?php
	}

	public function async_tracker_script( $tag, $handle, $src ) {
		if ( 'ama-dashamail-tracker' !== $handle ) {
			return $tag;
		}
		return '<script src="' . esc_url( $src ) . '" async data-ama-dashamail="tracker"></script>' . "\n";
	}

	public function enqueue_frontend() {
		if ( is_admin() ) {
			return;
		}

		$tracker_url = (string) AMA_DM_Settings::get( 'tracker_url' );
		wp_enqueue_script( 'ama-dashamail-tracker', $tracker_url, array(), null, false );
		wp_enqueue_script( 'ama-dashamail-frontend', AMA_DM_URL . 'assets/js/frontend.js', array( 'jquery' ), AMA_DM_VERSION, true );

		$config = array(
			'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
			'nonce'                    => wp_create_nonce( 'ama_dm_frontend' ),
			'directEvents'             => $this->get_direct_events(),
			'cartSnapshot'             => $this->get_cart_snapshot(),
			'currentUserEmail'         => $this->current_user_identity_email(),
			'identifyCheckoutEmail'    => AMA_DM_Settings::yes( 'identify_checkout_email' ) && function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page(),
			'requireMarketingConsent'  => AMA_DM_Settings::yes( 'require_marketing_consent' ),
			'marketingCheckboxSelector'=> (string) AMA_DM_Settings::get( 'checkout_marketing_checkbox_selector', '#foneona_consent_marketing' ),
			'pointOfContact'           => (string) AMA_DM_Settings::get( 'subscribe_point_of_contact', '' ),
			'orderEvents'              => $this->get_order_page_events(),
			'debug'                    => AMA_DM_Settings::yes( 'debug_log' ),
		);

		wp_localize_script( 'ama-dashamail-frontend', 'amaDashaMail', $config );
	}

	private function get_direct_events() {
		$events = array();

		if ( AMA_DM_Settings::yes( 'track_product_views' ) && function_exists( 'is_product' ) && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
			if ( $product instanceof WC_Product ) {
				$events[] = AMA_DM_Event_Queue::make(
					'async',
					array(
						'operation' => 'ViewProduct',
						'data'      => array(
							'product' => array(
								'productId' => AMA_DM_Product_ID::from_product( $product ),
								'price'     => AMA_DM_Product_ID::price( wc_get_price_to_display( $product ) ),
							),
						),
					),
					'product_view'
				);
			}
		}

		if ( AMA_DM_Settings::yes( 'track_category_views' ) && function_exists( 'is_product_category' ) && is_product_category() ) {
			$term = get_queried_object();
			if ( $term instanceof WP_Term ) {
				$events[] = AMA_DM_Event_Queue::make(
					'async',
					array(
						'operation' => 'ViewCategory',
						'data'      => array(
							'category' => array(
								'categoryId' => AMA_DM_Product_ID::category( $term ),
							),
						),
					),
					'category_view'
				);
			}
		}

		return $events;
	}

	public function variation_tracking_data( $data, $product, $variation ) {
		if ( $variation instanceof WC_Product_Variation ) {
			$data['ama_dm_product_id'] = AMA_DM_Product_ID::from_product( $variation );
			$data['ama_dm_price']      = AMA_DM_Product_ID::price( wc_get_price_to_display( $variation ) );
		}
		return $data;
	}

	public function ajax_pull_events() {
		check_ajax_referer( 'ama_dm_frontend', 'nonce' );
		wp_send_json_success( array( 'events' => AMA_DM_Event_Queue::pull_all() ) );
	}

	/* -----------------------------------------------------------------
	 * Cart events
	 * ----------------------------------------------------------------- */

	public function on_add_to_cart( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		$product = wc_get_product( $variation_id ? $variation_id : $product_id );
		if ( ! $product instanceof WC_Product ) {
			return;
		}
		$cart_item = function_exists( 'WC' ) && WC()->cart ? WC()->cart->get_cart_item( $cart_item_key ) : array();
		if ( ! empty( $cart_item['data'] ) && $cart_item['data'] instanceof WC_Product ) {
			$product = $cart_item['data'];
		}
		AMA_DM_Event_Queue::push(
			'cart.addProduct',
			array(
				'productId' => AMA_DM_Product_ID::from_product( $product ),
				'quantity'  => max( 1, (int) $quantity ),
				'price'     => AMA_DM_Product_ID::price( wc_get_price_to_display( $product ) ),
			),
			'woocommerce_add_to_cart'
		);
	}

	public function on_cart_item_removed( $cart_item_key, $cart ) {
		$item = isset( $cart->removed_cart_contents[ $cart_item_key ] ) ? $cart->removed_cart_contents[ $cart_item_key ] : array();
		if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return;
		}
		AMA_DM_Event_Queue::push(
			'cart.removeProduct',
			array(
				'productId' => AMA_DM_Product_ID::from_product( $item['data'] ),
				'quantity'  => isset( $item['quantity'] ) ? max( 1, (int) $item['quantity'] ) : 1,
			),
			'woocommerce_cart_item_removed'
		);
	}

	public function on_quantity_update( $cart_item_key, $quantity, $old_quantity, $cart ) {
		if ( (int) $quantity === (int) $old_quantity ) {
			return;
		}
		$item = $cart->get_cart_item( $cart_item_key );
		if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
			return;
		}
		AMA_DM_Event_Queue::push(
			'cart.setProductQuantity',
			array(
				'productId' => AMA_DM_Product_ID::from_product( $item['data'] ),
				'quantity'  => max( 0, (int) $quantity ),
			),
			'woocommerce_quantity_update'
		);
	}

	public function on_cart_emptied() {
		AMA_DM_Event_Queue::push( 'cart.clear', array(), 'woocommerce_cart_emptied' );
	}

	public function on_coupon_applied( $coupon_code ) {
		if ( ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$pending   = WC()->session->get( 'ama_dm_pending_coupons', array() );
		$pending   = is_array( $pending ) ? $pending : array();
		$pending[] = wc_format_coupon_code( $coupon_code );
		WC()->session->set( 'ama_dm_pending_coupons', array_values( array_unique( $pending ) ) );
	}

	public function after_calculate_totals( $cart ) {
		if ( $this->processing_coupon || ! function_exists( 'WC' ) || ! WC()->session ) {
			return;
		}
		$pending = WC()->session->get( 'ama_dm_pending_coupons', array() );
		if ( empty( $pending ) || ! is_array( $pending ) ) {
			return;
		}
		$this->processing_coupon = true;
		WC()->session->set( 'ama_dm_pending_coupons', array() );

		foreach ( $pending as $code ) {
			$coupon = new WC_Coupon( $code );
			if ( ! $coupon->get_id() ) {
				continue;
			}
			$actual_amount = method_exists( $cart, 'get_coupon_discount_amount' ) ? $cart->get_coupon_discount_amount( $code, false ) : 0;
			$discount      = 'percent' === $coupon->get_discount_type()
				? AMA_DM_Product_ID::price( $coupon->get_amount() ) . '%'
				: AMA_DM_Product_ID::price( $coupon->get_amount() );

			AMA_DM_Event_Queue::push(
				'async',
				array(
					'operation' => 'PromoActivated',
					'data'      => array(
						'promocode' => array(
							'name'     => (string) $code,
							'discount' => $discount,
							'amount'   => AMA_DM_Product_ID::price( $actual_amount ),
						),
					),
				),
				'woocommerce_coupon_applied'
			);
		}
		$this->processing_coupon = false;
	}

	private function get_cart_snapshot() {
		if ( ! AMA_DM_Settings::yes( 'track_cart' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
			return array();
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product || empty( $item['quantity'] ) ) {
				continue;
			}
			$items[] = array(
				'productId' => AMA_DM_Product_ID::from_product( $item['data'] ),
				'quantity'  => (int) $item['quantity'],
				'price'     => AMA_DM_Product_ID::price( wc_get_price_to_display( $item['data'] ) ),
			);
		}
		return $items;
	}

	/* -----------------------------------------------------------------
	 * Orders
	 * ----------------------------------------------------------------- */

	public function on_order_created( $order_id, $posted_data, $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$event = AMA_DM_Event_Queue::make( 'async', $this->order_payload( 'OrderCreated', $order ), 'woocommerce_checkout_order_processed' );
		$this->checkout_created_events[ $order->get_id() ] = $event;
		AMA_DM_Event_Queue::push( $event['command'], $event['payload'], $event['source'] );
		$order->update_meta_data( '_ama_dm_order_created_payload', $event );
		$order->save();

		$this->remember_order_marketing_consent( $order, $posted_data );
	}

	public function add_events_to_checkout_result( $result, $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return $result;
		}
		$events = array();
		if ( isset( $this->checkout_created_events[ $order_id ] ) ) {
			$events[] = $this->checkout_created_events[ $order_id ];
		} else {
			$stored = $order->get_meta( '_ama_dm_order_created_payload', true );
			if ( is_array( $stored ) ) {
				$events[] = $stored;
			}
		}
		if ( $order->is_paid() ) {
			$events[] = AMA_DM_Event_Queue::make( 'async', $this->order_payload( 'OrderPaid', $order ), 'checkout_result_paid' );
		}
		$result['ama_dm_events'] = $events;
		return $result;
	}

	public function on_order_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		$this->queue_order_event_once( 'OrderPaid', $order, '_ama_dm_order_paid_queued' );
		$this->copy_order_consent_to_user( $order );
		$this->maybe_queue_checkout_created_user_registration( $order );
	}

	public function on_order_status_changed( $order_id, $from_status, $to_status, $order ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		if ( in_array( $to_status, array( 'processing', 'completed' ), true ) && $order->is_paid() ) {
			$this->queue_order_event_once( 'OrderPaid', $order, '_ama_dm_order_paid_queued' );
			$this->copy_order_consent_to_user( $order );
			$this->maybe_queue_checkout_created_user_registration( $order );
		}
		if ( 'completed' === $to_status ) {
			$this->queue_order_event_once( 'OrderClosed', $order, '_ama_dm_order_closed_queued' );
		}
	}

	private function queue_order_event_once( $operation, $order, $meta_key ) {
		if ( 'yes' === $order->get_meta( $meta_key, true ) ) {
			return;
		}
		$event = AMA_DM_Event_Queue::make( 'async', $this->order_payload( $operation, $order ), 'woocommerce_order_status' );
		if ( $order->get_customer_id() ) {
			AMA_DM_Event_Queue::push_for_user( $order->get_customer_id(), $event );
		} else {
			$order->update_meta_data( '_ama_dm_guest_pending_' . strtolower( $operation ), $event );
			AMA_DM_Event_Queue::log( $event );
		}
		$order->update_meta_data( $meta_key, 'yes' );
		$order->save();
	}

	private function order_payload( $operation, $order ) {
		$items = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$items[] = array(
				'productId' => AMA_DM_Product_ID::from_product( $product ),
				'quantity'  => max( 1, (int) $item->get_quantity() ),
				'price'     => AMA_DM_Product_ID::price( $order->get_item_total( $item, true, false ) ),
			);
		}

		$status = $order->get_status();
		if ( 'cancelled' === $status ) {
			$status = 'canceled';
		}

		return array(
			'operation' => $operation,
			'data'      => array(
				'order' => array(
					'orderId'    => (string) $order->get_order_number(),
					'totalPrice' => AMA_DM_Product_ID::price( $order->get_total() ),
					'status'     => (string) $status,
					'items'      => $items,
				),
			),
		);
	}


	private function maybe_queue_checkout_created_user_registration( $order ) {
		$user_id = $order->get_customer_id();
		if ( ! $user_id || 'yes' === get_user_meta( $user_id, '_ama_dm_registration_queued', true ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User || ! $this->user_can_be_identified( $user_id ) ) {
			return;
		}
		$this->queue_registration_events( $user, true, 'foneona_checkout_account_created' );
		update_user_meta( $user_id, '_ama_dm_registration_queued', 'yes' );
	}

	private function get_order_page_events() {
		if ( ! AMA_DM_Settings::yes( 'track_orders' ) || ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) {
			return array();
		}
		$order_id = absint( get_query_var( 'order-received' ) );
		$order    = wc_get_order( $order_id );
		if ( ! $order instanceof WC_Order ) {
			return array();
		}
		$key = isset( $_GET['key'] ) ? wc_clean( wp_unslash( $_GET['key'] ) ) : '';
		if ( ! hash_equals( (string) $order->get_order_key(), (string) $key ) && ! current_user_can( 'manage_woocommerce' ) ) {
			return array();
		}

		$events = array();
		$stored = $order->get_meta( '_ama_dm_order_created_payload', true );
		$events[] = is_array( $stored ) ? $stored : AMA_DM_Event_Queue::make( 'async', $this->order_payload( 'OrderCreated', $order ), 'order_received' );
		if ( $order->is_paid() ) {
			$events[] = AMA_DM_Event_Queue::make( 'async', $this->order_payload( 'OrderPaid', $order ), 'order_received' );
		}
		foreach ( array( 'orderpaid' => 'OrderPaid', 'orderclosed' => 'OrderClosed' ) as $suffix => $operation ) {
			$pending = $order->get_meta( '_ama_dm_guest_pending_' . $suffix, true );
			if ( is_array( $pending ) ) {
				$events[] = $pending;
				$order->delete_meta_data( '_ama_dm_guest_pending_' . $suffix );
			}
		}
		$order->save();
		return $events;
	}

	/* -----------------------------------------------------------------
	 * Authentication and consent
	 * ----------------------------------------------------------------- */

	public function on_login( $user_login, $user ) {
		if ( ! $user instanceof WP_User || ! $this->user_can_be_identified( $user->ID ) ) {
			return;
		}
		AMA_DM_Event_Queue::push_for_user(
			$user->ID,
			'identity',
			array(
				'operation'  => 'UserIdentifier',
				'identifier' => array(
					'profile'  => 'email',
					'identity' => sanitize_email( $user->user_email ),
				),
			),
			'wp_login'
		);
	}

	public function on_relod_register( $user_id, $data ) {
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		$newsletter_id  = (string) AMA_DM_Settings::get( 'relod_newsletter_checkbox_id', 'cb_newsletter' );
		$post_field     = 'cb_' . $newsletter_id;
		$consent        = ! empty( $data[ $post_field ] );
		update_user_meta( $user_id, '_ama_dm_marketing_consent', $consent ? 'yes' : 'no' );
		if ( ! $consent && AMA_DM_Settings::yes( 'require_marketing_consent' ) ) {
			return;
		}
		$this->queue_registration_events( $user, $consent, 'relod_auth_after_register' );
		update_user_meta( $user_id, '_ama_dm_registration_queued', 'yes' );
	}

	public function on_generic_register( $user_id, $userdata = array() ) {
		if ( 'yes' === get_user_meta( $user_id, '_ama_dm_registration_queued', true ) ) {
			return;
		}
		$user = get_userdata( $user_id );
		if ( ! $user instanceof WP_User ) {
			return;
		}
		if ( AMA_DM_Settings::yes( 'require_marketing_consent' ) && ! $this->user_can_be_identified( $user_id ) ) {
			return;
		}
		$this->queue_registration_events( $user, $this->user_can_be_identified( $user_id ), 'user_register' );
		update_user_meta( $user_id, '_ama_dm_registration_queued', 'yes' );
	}

	private function queue_registration_events( $user, $consent, $source ) {
		$phone = (string) get_user_meta( $user->ID, 'billing_phone', true );
		$name  = trim( (string) get_user_meta( $user->ID, 'first_name', true ) . ' ' . (string) get_user_meta( $user->ID, 'last_name', true ) );
		if ( '' === $name ) {
			$name = $user->display_name;
		}
		AMA_DM_Event_Queue::push_for_user(
			$user->ID,
			'async',
			array(
				'operation' => 'UserRegistration',
				'data'      => array(
					'customer' => array(
						'name'        => $name,
						'email'       => sanitize_email( $user->user_email ),
						'mobilePhone' => $phone,
					),
				),
			),
			$source
		);
		if ( $consent ) {
			$this->queue_subscribe_event( $user->ID, $user->user_email, $source );
		}
	}

	private function queue_subscribe_event( $user_id, $email, $source ) {
		$point = (string) AMA_DM_Settings::get( 'subscribe_point_of_contact', '' );
		if ( '' === $point ) {
			return;
		}
		AMA_DM_Event_Queue::push_for_user(
			$user_id,
			'async',
			array(
				'operation' => 'UserSubscribe',
				'data'      => array(
					'customer'      => array( 'email' => sanitize_email( $email ) ),
					'pointOfContact' => $point,
				),
			),
			$source
		);
	}

	private function current_user_identity_email() {
		if ( ! is_user_logged_in() || ! $this->user_can_be_identified( get_current_user_id() ) ) {
			return '';
		}
		$user = wp_get_current_user();
		return sanitize_email( $user->user_email );
	}

	private function user_can_be_identified( $user_id ) {
		if ( ! AMA_DM_Settings::yes( 'require_marketing_consent' ) ) {
			return true;
		}
		$consent = get_user_meta( $user_id, '_ama_dm_marketing_consent', true );
		if ( 'yes' === $consent ) {
			return true;
		}
		if ( 'no' === $consent ) {
			return false;
		}
		return '1' === (string) get_user_meta( $user_id, 'relod_cb_newsletter', true );
	}

	private function remember_order_marketing_consent( $order, $posted_data ) {
		$consent = ( is_array( $posted_data ) && ! empty( $posted_data['foneona_consent_marketing'] ) ) || ! empty( $_POST['foneona_consent_marketing'] );
		$order->update_meta_data( '_ama_dm_marketing_consent', $consent ? 'yes' : 'no' );
		$order->save();
	}

	private function copy_order_consent_to_user( $order ) {
		$user_id = $order->get_customer_id();
		if ( ! $user_id ) {
			return;
		}
		$consent = $order->get_meta( '_ama_dm_marketing_consent', true );
		if ( in_array( $consent, array( 'yes', 'no' ), true ) ) {
			update_user_meta( $user_id, '_ama_dm_marketing_consent', $consent );
		}
	}

	/* -----------------------------------------------------------------
	 * Admin
	 * ----------------------------------------------------------------- */

	public function admin_init() {
		register_setting( 'ama_dm_group', AMA_DM_Settings::OPTION, array( 'sanitize_callback' => array( 'AMA_DM_Settings', 'sanitize' ) ) );
	}

	public function admin_menu() {
		$parent = class_exists( 'WooCommerce' ) ? 'woocommerce' : 'options-general.php';
		add_submenu_page( $parent, 'DashaMail Tracking', 'DashaMail Tracking', 'manage_woocommerce', 'ama-dashamail', array( $this, 'render_admin' ) );
	}

	public function clear_log() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'ama-dashamail' ) );
		}
		check_admin_referer( 'ama_dm_clear_log' );
		AMA_DM_Event_Queue::clear_log();
		wp_safe_redirect( admin_url( 'admin.php?page=ama-dashamail&log_cleared=1' ) );
		exit;
	}

	public function render_admin() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s = AMA_DM_Settings::all();
		?>
		<div class="wrap">
			<h1>DashaMail Tracking для Amarèssence</h1>
			<p>Плагин подключает общий трекер DashaMail и передаёт события WooCommerce, кастомной корзины Foneona и формы RELOD Auth.</p>

			<?php if ( isset( $_GET['settings-updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Настройки сохранены.</p></div>
			<?php endif; ?>

			<div class="notice notice-info inline"><p><strong>Критически важно:</strong> значение <em>ID товара</em> должно в точности совпадать с атрибутом <code>offer id</code> вашего YML-фида DashaMail. Иначе товарные карточки не попадут в письмо о брошенной корзине.</p></div>

			<h2>Диагностика</h2>
			<table class="widefat striped" style="max-width:900px">
				<tbody>
				<tr><td>WooCommerce</td><td><?php echo class_exists( 'WooCommerce' ) ? '<strong style="color:#16833b">обнаружен</strong>' : '<strong style="color:#b32d2e">не обнаружен</strong>'; ?></td></tr>
				<tr><td>Foneona Woo Layout</td><td><?php echo class_exists( 'Foneona_Woo_Layout' ) ? '<strong style="color:#16833b">обнаружен</strong>' : 'не обнаружен'; ?></td></tr>
				<tr><td>RELOD Auth Forms</td><td><?php echo has_action( 'relod_auth_after_register' ) ? '<strong style="color:#16833b">обнаружен</strong>' : 'хук будет подключён при загрузке формы'; ?></td></tr>
				</tbody>
			</table>

			<form method="post" action="options.php" style="max-width:1000px">
				<?php settings_fields( 'ama_dm_group' ); ?>
				<h2>Основные настройки</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Интеграция</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[enabled]" value="1" <?php checked( $s['enabled'], '1' ); ?>> включена</label></td></tr>
					<tr><th scope="row"><label for="ama-dm-tracker-url">URL трекера</label></th><td><input id="ama-dm-tracker-url" class="regular-text code" type="url" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[tracker_url]" value="<?php echo esc_attr( $s['tracker_url'] ); ?>"></td></tr>
					<tr><th scope="row"><label for="ama-dm-product-id">ID товара в YML</label></th><td>
						<select id="ama-dm-product-id" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[product_id_mode]">
							<option value="yml_offer_id" <?php selected( $s['product_id_mode'], 'yml_offer_id' ); ?>>Как в текущем YML Amarèssence (рекомендуется)</option>
							<option value="variation_or_product_id" <?php selected( $s['product_id_mode'], 'variation_or_product_id' ); ?>>ID товара/вариации WooCommerce</option>
							<option value="parent_product_id" <?php selected( $s['product_id_mode'], 'parent_product_id' ); ?>>Всегда ID родительского товара</option>
							<option value="sku" <?php selected( $s['product_id_mode'], 'sku' ); ?>>Артикул SKU</option>
							<option value="custom_meta" <?php selected( $s['product_id_mode'], 'custom_meta' ); ?>>Значение произвольного meta-поля</option>
						</select>
						<p class="description">Для загруженного фида: собственный SKU вариации; если он пуст — SKU родителя + дефис + ID вариации. Примеры: <code>LEG-00247489-L-BLU</code> и <code>ЦБ-00247209-BRG-4695</code>.</p>
						<p><input class="regular-text code" type="text" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[custom_product_meta_key]" value="<?php echo esc_attr( $s['custom_product_meta_key'] ); ?>" placeholder="Например: _yml_offer_id"></p>
					</td></tr>
					<tr><th scope="row">ID категории в YML</th><td><select name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[category_id_mode]"><option value="term_id" <?php selected( $s['category_id_mode'], 'term_id' ); ?>>ID категории WooCommerce</option><option value="slug" <?php selected( $s['category_id_mode'], 'slug' ); ?>>Slug категории</option></select></td></tr>
				</table>

				<h2>Передаваемые события</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Просмотры</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[track_product_views]" value="1" <?php checked( $s['track_product_views'], '1' ); ?>> ViewProduct</label><br><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[track_category_views]" value="1" <?php checked( $s['track_category_views'], '1' ); ?>> ViewCategory</label></td></tr>
					<tr><th scope="row">Корзина и промокод</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[track_cart]" value="1" <?php checked( $s['track_cart'], '1' ); ?>> add/remove/setQuantity/clear и PromoActivated</label></td></tr>
					<tr><th scope="row">Заказы</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[track_orders]" value="1" <?php checked( $s['track_orders'], '1' ); ?>> OrderCreated, OrderPaid, OrderClosed</label></td></tr>
					<tr><th scope="row">Авторизация</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[track_auth]" value="1" <?php checked( $s['track_auth'], '1' ); ?>> UserRegistration, UserIdentifier, UserSubscribe</label></td></tr>
				</table>

				<h2>Идентификация и согласие</h2>
				<table class="form-table" role="presentation">
					<tr><th scope="row">Email на оформлении</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[identify_checkout_email]" value="1" <?php checked( $s['identify_checkout_email'], '1' ); ?>> идентифицировать посетителя после ввода корректного email</label></td></tr>
					<tr><th scope="row">Маркетинговое согласие</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[require_marketing_consent]" value="1" <?php checked( $s['require_marketing_consent'], '1' ); ?>> идентифицировать и подписывать только при явном согласии</label></td></tr>
					<tr><th scope="row">Чекбокс checkout</th><td><input class="regular-text code" type="text" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[checkout_marketing_checkbox_selector]" value="<?php echo esc_attr( $s['checkout_marketing_checkbox_selector'] ); ?>"><p class="description">Для переданного плагина Foneona: <code>#foneona_consent_marketing</code>.</p></td></tr>
					<tr><th scope="row">ID чекбокса RELOD</th><td><input class="regular-text code" type="text" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[relod_newsletter_checkbox_id]" value="<?php echo esc_attr( $s['relod_newsletter_checkbox_id'] ); ?>"><p class="description">Для присланной формы: <code>cb_newsletter</code>.</p></td></tr>
					<tr><th scope="row">Точка подписки DashaMail</th><td><input class="regular-text code" type="text" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[subscribe_point_of_contact]" value="<?php echo esc_attr( $s['subscribe_point_of_contact'] ); ?>"><p class="description">Идентификатор из раздела DashaMail «Сайты → Подписки». Если оставить пустым, событие UserSubscribe не отправляется.</p></td></tr>
				</table>

				<h2>Отладка</h2>
				<table class="form-table"><tr><th scope="row">Лог событий</th><td><label><input type="checkbox" name="<?php echo esc_attr( AMA_DM_Settings::OPTION ); ?>[debug_log]" value="1" <?php checked( $s['debug_log'], '1' ); ?>> хранить последние 100 подготовленных событий</label></td></tr></table>
				<?php submit_button(); ?>
			</form>

			<?php if ( AMA_DM_Settings::yes( 'debug_log' ) ) : ?>
				<h2>Последние события</h2>
				<p><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=ama_dm_clear_log' ), 'ama_dm_clear_log' ) ); ?>">Очистить лог</a></p>
				<table class="widefat striped"><thead><tr><th>Время</th><th>Источник</th><th>Команда</th><th>Данные</th></tr></thead><tbody>
				<?php foreach ( AMA_DM_Event_Queue::get_log() as $row ) : ?>
					<tr><td><?php echo esc_html( wp_date( 'd.m.Y H:i:s', isset( $row['time'] ) ? (int) $row['time'] : time() ) ); ?></td><td><?php echo esc_html( isset( $row['source'] ) ? $row['source'] : '' ); ?></td><td><code><?php echo esc_html( isset( $row['command'] ) ? $row['command'] : '' ); ?></code></td><td><pre style="white-space:pre-wrap;max-width:650px"><?php echo esc_html( wp_json_encode( isset( $row['payload'] ) ? $row['payload'] : array(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT ) ); ?></pre></td></tr>
				<?php endforeach; ?>
				</tbody></table>
			<?php endif; ?>
		</div>
		<?php
	}
}
