<?php

defined( 'ABSPATH' ) || exit;

/**
 * Конструктор полезной нагрузки событий DashaMail.
 *
 * Карта событий соответствует разделу «Отслеживаемые события» личного
 * кабинета DashaMail — там указаны и идентификаторы для трекинга, и полная
 * структура данных, включая блок customer, которого нет в кратком
 * «Руководстве для разработчика».
 */
final class AMA_DM_Events {

	/** Идентификаторы операций по умолчанию. */
	public static function operations() {
		return array(
			'view_product'        => 'ViewProduct',
			'view_category'       => 'ViewCategory',
			'add_product'         => 'AddProduct',
			'remove_product'      => 'RemoveProduct',
			'set_quantity'        => 'SetProductQuantity',
			'clear_cart'          => 'ClearCart',
			'promo'               => 'PromoActivated',
			'order_created'       => 'OrderCreate',
			'order_paid'          => 'OrderPaid',
			'order_closed'        => 'OrderFinished',
			'one_click_order'     => 'OneClickOrder',
			'authorization'       => 'Authorization',
			'identify'            => 'Identify',
			'registration'        => 'UserRegistration',
			'subscribe'           => 'UserSubscribe',
			'subscribe_pending'   => 'UserSubscriberNoConfirmBTN',
			'product_come_back'   => 'ProductComeBack',
		);
	}

	/**
	 * Идентификатор операции с учётом переопределения в настройках.
	 *
	 * @param string $key Ключ из self::operations().
	 */
	public static function operation( $key ) {
		$defaults  = self::operations();
		$default   = isset( $defaults[ $key ] ) ? $defaults[ $key ] : '';
		$overrides = array( 'order_created', 'order_paid', 'order_closed' );

		if ( in_array( $key, $overrides, true ) ) {
			$saved = trim( (string) AMA_DM_Settings::get( 'op_' . $key, '' ) );
			if ( '' !== $saved ) {
				$default = $saved;
			}
		}

		return (string) apply_filters( 'ama_dm_operation', $default, $key );
	}

	/** Событие async с блоком data. */
	private static function async( $key, $data ) {
		return array(
			'command' => 'async',
			'payload' => array(
				'operation' => self::operation( $key ),
				'data'      => $data,
			),
		);
	}

	/** Добавляет блок customer, если он не пустой. */
	private static function with_customer( $data, $customer ) {
		if ( ! empty( $customer ) ) {
			$data = array_merge( array( 'customer' => $customer ), $data );
		}

		return $data;
	}

	/* -----------------------------------------------------------------
	 * Просмотры
	 * ----------------------------------------------------------------- */

	/**
	 * @param WC_Product $product Товар или вариация.
	 * @param float|null $price   Цена для клиента.
	 */
	public static function view_product( $product, $price = null ) {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$product_data = AMA_DM_Product_Data::payload(
			$product,
			array( 'price' => null === $price ? wc_get_price_to_display( $product ) : $price )
		);

		// empty() отсекает и пустую строку, и «0» — оба означают, что товар
		// не удалось сопоставить с offer id фида, и событие бесполезно.
		if ( empty( $product_data['productId'] ) ) {
			self::log_missing_id( 'view_product', $product );
			return array();
		}

		return array(
			self::async(
				'view_product',
				self::with_customer( array( 'product' => $product_data ), AMA_DM_Customer::payload() )
			),
		);
	}

	/** Событие без идентификатора товара отправлять бессмысленно — фиксируем причину. */
	private static function log_missing_id( $key, $product ) {
		AMA_DM_Event_Queue::log_skipped(
			'пустой productId — товар не сопоставлен с offer id YML-фида',
			array(
				'operation' => self::operation( $key ),
				'productId' => $product instanceof WC_Product ? $product->get_id() : 0,
				'mode'      => (string) AMA_DM_Settings::get( 'product_id_mode', 'yml_offer_id' ),
			)
		);
	}

	/**
	 * ViewCategory. Руководство для разработчика использует ключ categoryId,
	 * а структура события в личном кабинете — id, поэтому передаём оба.
	 *
	 * @param WP_Term $term Категория товара.
	 */
	public static function view_category( $term ) {
		$id = AMA_DM_Product_ID::category( $term );
		if ( '' === $id ) {
			return array();
		}

		$category = array( 'categoryId' => $id, 'id' => $id );

		if ( ! AMA_DM_Settings::yes( 'strict_payload' ) ) {
			$name = AMA_DM_Product_Data::term_name( $term );
			if ( '' !== $name ) {
				$category['name'] = $name;
			}
		}

		return array(
			self::async(
				'view_category',
				self::with_customer( array( 'category' => $category ), AMA_DM_Customer::payload() )
			),
		);
	}

	/* -----------------------------------------------------------------
	 * Корзина
	 * ----------------------------------------------------------------- */

	/**
	 * Событие корзины.
	 *
	 * Короткие команды cart.* поддерживают состояние корзины на стороне
	 * DashaMail (нужно для брошенной корзины), но не переносят ни блок
	 * customer, ни название товара — именно поэтому колонка «Данные» в
	 * журнале остаётся пустой. Событие async с той же операцией переносит
	 * полную структуру. Режим отправки задаётся настройкой.
	 *
	 * @param string     $command Короткая команда cart.*.
	 * @param string     $key     Ключ операции.
	 * @param WC_Product $product Товар.
	 * @param array      $extra   quantity и price.
	 */
	private static function cart_event( $command, $key, $product, $extra ) {
		$product_data = AMA_DM_Product_Data::payload( $product, $extra );
		if ( empty( $product_data['productId'] ) ) {
			self::log_missing_id( $key, $product );
			return array();
		}

		$mode   = (string) AMA_DM_Settings::get( 'cart_event_mode', 'both' );
		$events = array();

		if ( 'async' !== $mode ) {
			// Короткая команда сохраняет прежний формат: количество числом.
			$short = array( 'productId' => $product_data['productId'] );
			if ( isset( $product_data['quantity'] ) ) {
				$short['quantity'] = (int) $product_data['quantity'];
			}
			if ( isset( $product_data['price'] ) ) {
				$short['price'] = $product_data['price'];
			}
			$events[] = array( 'command' => $command, 'payload' => $short );
		}

		if ( 'command' !== $mode ) {
			$events[] = self::async(
				$key,
				self::with_customer( array( 'product' => $product_data ), AMA_DM_Customer::payload() )
			);
		}

		return $events;
	}

	public static function cart_add( $product, $quantity, $price ) {
		return self::cart_event(
			'cart.addProduct',
			'add_product',
			$product,
			array( 'quantity' => max( 1, (int) $quantity ), 'price' => $price )
		);
	}

	public static function cart_remove( $product, $quantity ) {
		return self::cart_event(
			'cart.removeProduct',
			'remove_product',
			$product,
			array( 'quantity' => max( 1, (int) $quantity ) )
		);
	}

	public static function cart_set_quantity( $product, $quantity ) {
		return self::cart_event(
			'cart.setProductQuantity',
			'set_quantity',
			$product,
			array( 'quantity' => max( 0, (int) $quantity ) )
		);
	}

	public static function cart_clear() {
		$mode   = (string) AMA_DM_Settings::get( 'cart_event_mode', 'both' );
		$events = array();

		if ( 'async' !== $mode ) {
			$events[] = array( 'command' => 'cart.clear', 'payload' => array() );
		}

		if ( 'command' !== $mode ) {
			$events[] = self::async( 'clear_cart', self::with_customer( array(), AMA_DM_Customer::payload() ) );
		}

		return $events;
	}

	/* -----------------------------------------------------------------
	 * Промокод
	 * ----------------------------------------------------------------- */

	public static function promo( $code, $discount, $amount ) {
		$promocode = array(
			'name'     => (string) $code,
			'discount' => (string) $discount,
			'amount'   => AMA_DM_Product_ID::price( $amount ),
		);

		return array(
			self::async(
				'promo',
				self::with_customer( array( 'promocode' => $promocode ), AMA_DM_Customer::payload() )
			),
		);
	}

	/* -----------------------------------------------------------------
	 * Заказы
	 * ----------------------------------------------------------------- */

	/**
	 * Полная структура заказа: customer + order с доставкой и оплатой.
	 *
	 * @param string   $key   Ключ операции заказа.
	 * @param WC_Order $order Заказ.
	 */
	public static function order( $key, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return array();
		}

		$lines = array();
		foreach ( $order->get_items( 'line_item' ) as $item ) {
			$product = $item->get_product();
			if ( ! $product instanceof WC_Product ) {
				continue;
			}
			$line = AMA_DM_Product_Data::payload(
				$product,
				array(
					'quantity' => max( 1, (int) $item->get_quantity() ),
					'price'    => $order->get_item_total( $item, true, false ),
				)
			);
			if ( empty( $line['productId'] ) ) {
				continue;
			}
			if ( isset( $line['name'] ) && '' === (string) $line['name'] ) {
				$line['name'] = trim( wp_strip_all_tags( (string) $item->get_name() ) );
			}
			$lines[] = $line;
		}

		$order_data = array(
			'orderId'    => (string) $order->get_order_number(),
			'totalPrice' => AMA_DM_Product_ID::price( $order->get_total() ),
			'status'     => self::order_status( $order ),
		);

		$delivery_type = self::delivery_type( $order );
		if ( '' !== $delivery_type ) {
			$order_data['deliveryType'] = $delivery_type;
		}

		$address = self::delivery_address( $order );
		if ( '' !== $address ) {
			$order_data['deliveryAddress'] = $address;
		}

		$delivery_time = self::delivery_time( $order );
		if ( '' !== $delivery_time ) {
			$order_data['deliveryTime'] = $delivery_time;
		}

		$payment_type = self::payment_type( $order );
		if ( '' !== $payment_type ) {
			$order_data['paymentType'] = $payment_type;
		}

		$order_data['lines'] = $lines;

		if ( ! AMA_DM_Settings::yes( 'strict_payload' ) ) {
			// Совместимость с более ранней интеграцией, использовавшей ключ items.
			$order_data['items'] = $lines;
		}

		return array(
			self::async(
				$key,
				self::with_customer( array( 'order' => $order_data ), AMA_DM_Customer::from_order( $order ) )
			),
		);
	}

	/** Статус в терминах DashaMail: created, processing, finished, canceled. */
	private static function order_status( $order ) {
		$map = array(
			'pending'    => 'created',
			'on-hold'    => 'created',
			'failed'     => 'created',
			'processing' => 'processing',
			'completed'  => 'finished',
			'cancelled'  => 'canceled',
			'refunded'   => 'canceled',
		);

		$status = $order->get_status();

		return (string) apply_filters(
			'ama_dm_order_status',
			isset( $map[ $status ] ) ? $map[ $status ] : $status,
			$order
		);
	}

	/** Способ доставки: express или pickup. */
	private static function delivery_type( $order ) {
		$type = '';

		foreach ( $order->get_shipping_methods() as $method ) {
			$method_id = (string) $method->get_method_id();
			$type      = false !== strpos( $method_id, 'local_pickup' ) ? 'pickup' : 'express';
			break;
		}

		if ( '' === $type && ! $order->has_shipping_address() ) {
			$type = 'pickup';
		}

		return (string) apply_filters( 'ama_dm_delivery_type', $type, $order );
	}

	/** Адрес доставки одной строкой. */
	private static function delivery_address( $order ) {
		$address = trim( wp_strip_all_tags( (string) $order->get_formatted_shipping_address() ) );
		if ( '' === $address ) {
			$address = trim( wp_strip_all_tags( (string) $order->get_formatted_billing_address() ) );
		}

		return preg_replace( '/\s*\R\s*/u', ', ', $address );
	}

	/** Дата и время доставки в формате YYYY-MM-DD HH:ii:ss, если магазин их хранит. */
	private static function delivery_time( $order ) {
		$keys = (array) apply_filters(
			'ama_dm_delivery_time_meta_keys',
			array( '_delivery_date', 'delivery_date', '_orddd_timestamp', '_billing_delivery_date' )
		);

		foreach ( $keys as $key ) {
			$value = $order->get_meta( $key, true );
			if ( '' === $value || null === $value ) {
				continue;
			}
			$timestamp = is_numeric( $value ) ? (int) $value : strtotime( (string) $value );
			if ( $timestamp ) {
				return gmdate( 'Y-m-d H:i:s', $timestamp );
			}
		}

		return '';
	}

	/** Способ оплаты в терминах DashaMail: cash, robokassa, card, invoice. */
	private static function payment_type( $order ) {
		$gateway = (string) $order->get_payment_method();
		if ( '' === $gateway ) {
			return '';
		}

		$map = array(
			'cod'       => 'cash',
			'cheque'    => 'cash',
			'robokassa' => 'robokassa',
			'bacs'      => 'invoice',
			'invoice'   => 'invoice',
		);

		foreach ( self::payment_overrides() as $key => $value ) {
			$map[ $key ] = $value;
		}

		$type = isset( $map[ $gateway ] ) ? $map[ $gateway ] : 'card';

		return (string) apply_filters( 'ama_dm_payment_type', $type, $gateway, $order );
	}

	/** Пользовательское сопоставление шлюзов оплаты из настроек. */
	private static function payment_overrides() {
		$raw = (string) AMA_DM_Settings::get( 'payment_type_map', '' );
		$map = array();

		foreach ( preg_split( '/\R/', $raw ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $gateway, $type ) = array_map( 'trim', explode( '=', $line, 2 ) );
			if ( '' !== $gateway && '' !== $type ) {
				$map[ $gateway ] = $type;
			}
		}

		return $map;
	}

	/* -----------------------------------------------------------------
	 * Идентификация и подписки
	 * ----------------------------------------------------------------- */

	/**
	 * Идентификация посетителя.
	 *
	 * Команда identify связывает анонимную сессию трекера с профилем и
	 * порождает событие Authorization. Дополнительное событие Identify
	 * фиксирует момент раскрытия личности со всеми известными полями.
	 *
	 * @param string $email    Email посетителя.
	 * @param array  $customer Дополнительные поля (телефон, ФИО).
	 */
	public static function identify( $email, $customer = array() ) {
		$email = sanitize_email( (string) $email );
		if ( ! is_email( $email ) ) {
			return array();
		}

		$customer = AMA_DM_Customer::payload( array_merge( (array) $customer, array( 'email' => $email ) ), true );
		$events   = array(
			array(
				'command' => 'identify',
				'payload' => array(
					'operation'     => self::operation( 'authorization' ),
					'identificator' => array(
						'provider' => 'email',
						'identity' => $email,
					),
				),
			),
		);

		if ( AMA_DM_Settings::yes( 'track_identify_event' ) ) {
			$events[] = self::async( 'identify', array( 'customer' => $customer ) );
		}

		return $events;
	}

	/**
	 * @param WP_User $user    Пользователь.
	 * @param array   $overrides Дополнительные поля.
	 */
	public static function registration( $user, $overrides = array() ) {
		if ( ! $user instanceof WP_User ) {
			return array();
		}

		$customer = AMA_DM_Customer::payload(
			array_merge( AMA_DM_Customer::from_user( $user->ID ), (array) $overrides ),
			true
		);

		if ( empty( $customer['email'] ) ) {
			return array();
		}

		return array( self::async( 'registration', array( 'customer' => $customer ) ) );
	}

	/**
	 * @param string $email     Email.
	 * @param string $point     Точка контакта DashaMail.
	 * @param bool   $confirmed Подтверждена ли подписка.
	 * @param array  $overrides Дополнительные поля покупателя.
	 */
	public static function subscribe( $email, $point, $confirmed = true, $overrides = array() ) {
		$email = sanitize_email( (string) $email );
		$point = trim( (string) $point );

		if ( ! is_email( $email ) || '' === $point ) {
			return array();
		}

		$customer = AMA_DM_Customer::payload( array_merge( (array) $overrides, array( 'email' => $email ) ), true );

		return array(
			self::async(
				$confirmed ? 'subscribe' : 'subscribe_pending',
				array(
					'customer'       => $customer,
					'pointOfContact' => $point,
				)
			),
		);
	}
}
