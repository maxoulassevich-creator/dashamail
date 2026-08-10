<?php

defined( 'ABSPATH' ) || exit;

/**
 * Полный блок product для событий DashaMail.
 *
 * В структурах DashaMail обязательны productId / price / quantity,
 * а название товара, ссылка и картинка передаются дополнительно —
 * они видны в журнале событий и доступны в сценариях.
 */
final class AMA_DM_Product_Data {

	/**
	 * @param WC_Product|int $product Товар или вариация.
	 * @param array          $extra   Дополнительные поля (quantity, price).
	 */
	public static function payload( $product, $extra = array() ) {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return array();
		}

		$extra   = is_array( $extra ) ? $extra : array();
		$payload = array( 'productId' => AMA_DM_Product_ID::from_product( $product ) );

		if ( array_key_exists( 'price', $extra ) ) {
			$payload['price'] = AMA_DM_Product_ID::price( $extra['price'] );
		}
		if ( array_key_exists( 'quantity', $extra ) ) {
			$payload['quantity'] = (string) max( 0, (int) $extra['quantity'] );
		}

		if ( AMA_DM_Settings::yes( 'send_product_name' ) && ! AMA_DM_Settings::yes( 'strict_payload' ) ) {
			$payload['name'] = self::name( $product );

			$url = self::url( $product );
			if ( '' !== $url ) {
				$payload['url'] = $url;
			}

			$picture = self::picture( $product );
			if ( '' !== $picture ) {
				$payload['picture'] = $picture;
			}

			$sku = trim( (string) $product->get_sku( 'edit' ) );
			if ( '' !== $sku ) {
				$payload['sku'] = $sku;
			}

			$category = self::category_name( $product );
			if ( '' !== $category ) {
				$payload['category'] = $category;
			}

			$brand = self::brand( $product );
			if ( '' !== $brand ) {
				$payload['brand'] = $brand;
			}
		}

		return (array) apply_filters( 'ama_dm_product_payload', $payload, $product, $extra );
	}

	/**
	 * Название товара. Для вариации WooCommerce уже возвращает имя
	 * вида «Топ — Синий, L», что и нужно передавать в DashaMail.
	 *
	 * @param WC_Product $product Товар.
	 */
	public static function name( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$name = trim( wp_strip_all_tags( (string) $product->get_name() ) );

		if ( '' === $name && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$name   = $parent instanceof WC_Product ? trim( wp_strip_all_tags( (string) $parent->get_name() ) ) : '';
		}

		if ( '' === $name ) {
			$name = trim( wp_strip_all_tags( (string) get_the_title( $product->get_id() ) ) );
		}

		return html_entity_decode( $name, ENT_QUOTES, 'UTF-8' );
	}

	/** Постоянная ссылка на карточку товара (для вариации — с выбранными атрибутами). */
	public static function url( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}
		$url = $product->is_type( 'variation' ) ? $product->get_permalink() : get_permalink( $product->get_id() );
		return $url ? esc_url_raw( $url ) : '';
	}

	/** Ссылка на изображение товара, с откатом к изображению родителя. */
	public static function picture( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$image_id = (int) $product->get_image_id();
		if ( ! $image_id && $product->is_type( 'variation' ) ) {
			$parent   = wc_get_product( $product->get_parent_id() );
			$image_id = $parent instanceof WC_Product ? (int) $parent->get_image_id() : 0;
		}
		if ( ! $image_id ) {
			return '';
		}

		$src = wp_get_attachment_image_url( $image_id, 'woocommerce_single' );

		return $src ? esc_url_raw( $src ) : '';
	}

	/** Название первой категории товара. */
	public static function category_name( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$parent_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$terms     = get_the_terms( $parent_id, 'product_cat' );
		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		$first = reset( $terms );

		return $first instanceof WP_Term ? trim( wp_strip_all_tags( $first->name ) ) : '';
	}

	/** Бренд товара из популярных таксономий каталога. */
	public static function brand( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$parent_id  = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$taxonomies = (array) apply_filters( 'ama_dm_brand_taxonomies', array( 'product_brand', 'pwb-brand', 'pa_brand' ) );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}
			$terms = get_the_terms( $parent_id, $taxonomy );
			if ( is_wp_error( $terms ) || empty( $terms ) ) {
				continue;
			}
			$first = reset( $terms );
			if ( $first instanceof WP_Term ) {
				return trim( wp_strip_all_tags( $first->name ) );
			}
		}

		return '';
	}

	/** Название категории для события ViewCategory. */
	public static function term_name( $term ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( absint( $term ), 'product_cat' );
		}

		return $term instanceof WP_Term ? trim( wp_strip_all_tags( $term->name ) ) : '';
	}
}
