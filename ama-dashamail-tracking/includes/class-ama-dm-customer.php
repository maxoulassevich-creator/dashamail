<?php

defined( 'ABSPATH' ) || exit;

/**
 * Сбор данных покупателя для блока customer в событиях DashaMail.
 *
 * DashaMail показывает в структурах событий блок customer с полями
 * email / mobilePhone, а для заказов дополнительно fname / lname / name.
 * Этот класс собирает максимум доступной информации о текущем посетителе
 * из всех источников, доступных WordPress и WooCommerce, включая
 * неавторизованных посетителей, которые оставили email в любой форме.
 */
final class AMA_DM_Customer {
	const SESSION_KEY  = 'ama_dm_identity';
	const COOKIE       = 'ama_dm_identity';
	const USER_META    = '_ama_dm_last_identity';
	const DM_COOKIE    = 'dashamail_device_uuid';

	/** @var array|null Кэш личности в пределах запроса. */
	private static $resolved = null;

	/**
	 * Блок customer для события текущего посетителя.
	 *
	 * @param array $overrides Приоритетные значения (например, из заказа).
	 * @param bool  $force     Собрать данные даже без маркетингового согласия.
	 */
	public static function payload( $overrides = array(), $force = false ) {
		if ( ! AMA_DM_Settings::yes( 'enrich_customer' ) ) {
			return array();
		}

		$identity = self::resolve();
		$data     = self::merge( $identity, is_array( $overrides ) ? $overrides : array() );

		if ( ! $force && '' !== $data['email'] && ! self::email_allowed( $data['email'] ) ) {
			// Посетитель не дал маркетингового согласия — передаём событие без личных данных.
			return array();
		}

		return self::format( $data );
	}

	/**
	 * Блок customer для конкретного заказа WooCommerce.
	 *
	 * @param WC_Order $order Заказ.
	 */
	public static function from_order( $order ) {
		if ( ! AMA_DM_Settings::yes( 'enrich_customer' ) || ! $order instanceof WC_Order ) {
			return array();
		}

		$fname = trim( (string) $order->get_billing_first_name() );
		$lname = trim( (string) $order->get_billing_last_name() );
		if ( '' === $fname && '' === $lname ) {
			$fname = trim( (string) $order->get_shipping_first_name() );
			$lname = trim( (string) $order->get_shipping_last_name() );
		}

		$data = array(
			'email'       => sanitize_email( (string) $order->get_billing_email() ),
			'mobilePhone' => self::phone( (string) $order->get_billing_phone() ),
			'fname'       => $fname,
			'lname'       => $lname,
			'name'        => trim( $fname . ' ' . $lname ),
		);

		if ( $order->get_customer_id() ) {
			$data = self::merge( self::from_user( $order->get_customer_id() ), $data );
		}

		return self::format( $data );
	}

	/**
	 * Данные зарегистрированного пользователя WordPress.
	 *
	 * @param int $user_id ID пользователя.
	 */
	public static function from_user( $user_id ) {
		$user_id = absint( $user_id );
		$user    = $user_id ? get_userdata( $user_id ) : null;
		if ( ! $user instanceof WP_User ) {
			return self::blank();
		}

		$fname = trim( (string) get_user_meta( $user_id, 'first_name', true ) );
		$lname = trim( (string) get_user_meta( $user_id, 'last_name', true ) );
		if ( '' === $fname ) {
			$fname = trim( (string) get_user_meta( $user_id, 'billing_first_name', true ) );
		}
		if ( '' === $lname ) {
			$lname = trim( (string) get_user_meta( $user_id, 'billing_last_name', true ) );
		}

		$name = trim( $fname . ' ' . $lname );
		if ( '' === $name ) {
			$name = trim( (string) $user->display_name );
		}

		$phone = (string) get_user_meta( $user_id, 'billing_phone', true );
		if ( '' === $phone ) {
			$phone = (string) get_user_meta( $user_id, 'phone', true );
		}

		$email = sanitize_email( (string) $user->user_email );
		if ( '' === $email ) {
			$email = sanitize_email( (string) get_user_meta( $user_id, 'billing_email', true ) );
		}

		return array(
			'email'       => $email,
			'mobilePhone' => self::phone( $phone ),
			'fname'       => $fname,
			'lname'       => $lname,
			'name'        => $name,
		);
	}

	/**
	 * Личность текущего посетителя из всех доступных источников.
	 *
	 * Порядок: авторизованный пользователь → объект клиента WooCommerce
	 * (заполняется на оформлении) → сохранённая личность анонимного
	 * посетителя (сессия WooCommerce или cookie).
	 */
	public static function resolve() {
		if ( null !== self::$resolved ) {
			return self::$resolved;
		}

		$data = self::blank();

		if ( is_user_logged_in() ) {
			$data = self::merge( $data, self::from_user( get_current_user_id() ) );
		}

		$data = self::merge( $data, self::from_wc_customer() );
		$data = self::merge( $data, self::stored() );

		self::$resolved = $data;

		return self::$resolved;
	}

	/** Данные из объекта WC_Customer — их заполняет WooCommerce при оформлении. */
	private static function from_wc_customer() {
		if ( ! function_exists( 'WC' ) || ! WC() || ! isset( WC()->customer ) || ! WC()->customer ) {
			return self::blank();
		}

		$customer = WC()->customer;
		$fname    = trim( (string) $customer->get_billing_first_name() );
		$lname    = trim( (string) $customer->get_billing_last_name() );

		return array(
			'email'       => sanitize_email( (string) $customer->get_billing_email() ),
			'mobilePhone' => self::phone( (string) $customer->get_billing_phone() ),
			'fname'       => $fname,
			'lname'       => $lname,
			'name'        => trim( $fname . ' ' . $lname ),
		);
	}

	/**
	 * Сохранённая личность анонимного посетителя.
	 *
	 * Пишется, когда посетитель оставил email в любой форме на сайте
	 * или перешёл по ссылке из письма DashaMail с email в параметре.
	 */
	public static function stored() {
		$stored = array();

		if ( self::session_available() ) {
			$session = WC()->session->get( self::SESSION_KEY, array() );
			if ( is_array( $session ) ) {
				$stored = $session;
			}
		}

		if ( empty( $stored['email'] ) && ! empty( $_COOKIE[ self::COOKIE ] ) ) {
			$raw    = json_decode( wp_unslash( $_COOKIE[ self::COOKIE ] ), true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			$stored = is_array( $raw ) ? $raw : array();
		}

		return self::merge( self::blank(), $stored );
	}

	/**
	 * Запомнить личность посетителя.
	 *
	 * @param array $data Поля личности; обязательно валидный email.
	 */
	public static function remember( $data ) {
		$data = self::merge( self::blank(), is_array( $data ) ? $data : array() );
		if ( ! is_email( $data['email'] ) ) {
			return false;
		}
		$data['email'] = sanitize_email( $data['email'] );

		if ( self::session_available() ) {
			WC()->session->set( self::SESSION_KEY, $data );
		}

		if ( is_user_logged_in() ) {
			update_user_meta( get_current_user_id(), self::USER_META, $data );
		}

		if ( ! headers_sent() ) {
			$lifetime = (int) apply_filters( 'ama_dm_identity_cookie_lifetime', YEAR_IN_SECONDS );
			setcookie(
				self::COOKIE,
				wp_json_encode( $data ),
				array(
					'expires'  => time() + $lifetime,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => false,
					'samesite' => 'Lax',
				)
			);
			$_COOKIE[ self::COOKIE ] = wp_json_encode( $data );
		}

		self::$resolved = self::merge( self::resolve(), $data );

		return true;
	}

	/** Идентификатор устройства, который трекер DashaMail хранит в cookie. */
	public static function device_uuid() {
		foreach ( array( self::DM_COOKIE, 'dashamail_uuid', 'directCrmDeviceUUID', 'mindboxDeviceUUID' ) as $name ) {
			if ( ! empty( $_COOKIE[ $name ] ) ) {
				return sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) );
			}
		}
		return '';
	}

	/**
	 * Можно ли передавать личные данные этого email в DashaMail.
	 *
	 * @param string $email Адрес.
	 */
	public static function email_allowed( $email ) {
		if ( ! AMA_DM_Settings::yes( 'require_marketing_consent' ) ) {
			return true;
		}

		$email = sanitize_email( (string) $email );
		if ( '' === $email ) {
			return false;
		}

		$user = get_user_by( 'email', $email );
		if ( $user instanceof WP_User ) {
			return AMA_DM_Plugin::user_has_consent( $user->ID );
		}

		// Гость: согласие фиксируется в сессии при отметке чекбокса на оформлении.
		if ( self::session_available() ) {
			return 'yes' === (string) WC()->session->get( 'ama_dm_guest_consent', '' );
		}

		return false;
	}

	/** Нормализация телефона к виду +7XXXXXXXXXX. */
	public static function phone( $phone ) {
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}

		$plus   = 0 === strpos( $phone, '+' );
		$digits = preg_replace( '/\D+/', '', $phone );
		if ( '' === $digits ) {
			return '';
		}

		if ( 11 === strlen( $digits ) && '8' === $digits[0] ) {
			$digits = '7' . substr( $digits, 1 );
		}
		if ( 10 === strlen( $digits ) ) {
			$digits = '7' . $digits;
		}

		if ( 11 === strlen( $digits ) && '7' === $digits[0] ) {
			return '+' . $digits;
		}

		return $plus ? '+' . $digits : $digits;
	}

	/** Пустой набор полей. */
	private static function blank() {
		return array(
			'email'       => '',
			'mobilePhone' => '',
			'fname'       => '',
			'lname'       => '',
			'name'        => '',
		);
	}

	/** Слияние: непустые значения из $extra перекрывают $base. */
	private static function merge( $base, $extra ) {
		$base  = wp_parse_args( is_array( $base ) ? $base : array(), self::blank() );
		$extra = is_array( $extra ) ? $extra : array();

		foreach ( self::blank() as $key => $unused ) {
			if ( isset( $extra[ $key ] ) && '' !== trim( (string) $extra[ $key ] ) ) {
				$base[ $key ] = trim( (string) $extra[ $key ] );
			}
		}

		if ( '' === $base['name'] ) {
			$base['name'] = trim( $base['fname'] . ' ' . $base['lname'] );
		}
		if ( '' === $base['fname'] && '' !== $base['name'] ) {
			$parts          = preg_split( '/\s+/', $base['name'] );
			$base['fname']  = isset( $parts[0] ) ? $parts[0] : '';
			$base['lname']  = isset( $parts[1] ) ? implode( ' ', array_slice( $parts, 1 ) ) : '';
		}

		return $base;
	}

	/**
	 * Приведение к формату DashaMail: пустые поля не передаём,
	 * в строгом режиме отдаём только email и mobilePhone.
	 */
	private static function format( $data ) {
		$data['email']       = sanitize_email( (string) $data['email'] );
		$data['mobilePhone'] = self::phone( $data['mobilePhone'] );

		$out = array();
		foreach ( array( 'email', 'mobilePhone', 'name', 'fname', 'lname' ) as $key ) {
			$value = isset( $data[ $key ] ) ? trim( (string) $data[ $key ] ) : '';
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}

		if ( AMA_DM_Settings::yes( 'strict_payload' ) ) {
			$out = array_intersect_key( $out, array( 'email' => 1, 'mobilePhone' => 1 ) );
		}

		return (array) apply_filters( 'ama_dm_customer_payload', $out, $data );
	}

	private static function session_available() {
		return function_exists( 'WC' ) && WC() && isset( WC()->session ) && WC()->session;
	}
}
