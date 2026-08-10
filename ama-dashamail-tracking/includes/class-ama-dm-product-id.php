<?php

defined( 'ABSPATH' ) || exit;

final class AMA_DM_Product_ID {
	public static function from_product( $product ) {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( absint( $product ) );
		}
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$mode = (string) AMA_DM_Settings::get( 'product_id_mode', 'yml_offer_id' );

		switch ( $mode ) {
			case 'yml_offer_id':
				/*
				 * Точное правило текущего YML-фида Amarèssence:
				 * 1) собственный SKU вариации;
				 * 2) если он пуст — SKU родителя + "-" + ID вариации;
				 * 3) если нет и SKU родителя — ID вариации/товара.
				 *
				 * Для вариации обязательно используется контекст edit, потому что
				 * get_sku() в контексте view наследует SKU родительского товара.
				 */
				if ( $product->is_type( 'variation' ) ) {
					$variation_sku = trim( (string) $product->get_sku( 'edit' ) );
					if ( '' !== $variation_sku ) {
						$id = $variation_sku;
					} else {
						$parent     = wc_get_product( $product->get_parent_id() );
						$parent_sku = $parent instanceof WC_Product ? trim( (string) $parent->get_sku( 'edit' ) ) : '';
						$id         = '' !== $parent_sku
							? $parent_sku . '-' . $product->get_id()
							: (string) $product->get_id();
					}
				} else {
					$sku = trim( (string) $product->get_sku( 'edit' ) );
					$id  = '' !== $sku ? $sku : (string) $product->get_id();
				}
				break;

			case 'parent_product_id':
				$id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
				break;

			case 'sku':
				$id = trim( (string) $product->get_sku( 'edit' ) );
				if ( '' === $id && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$id = $parent instanceof WC_Product ? trim( (string) $parent->get_sku( 'edit' ) ) : '';
				}
				if ( '' === $id ) {
					$id = (string) $product->get_id();
				}
				break;

			case 'custom_meta':
				$key = (string) AMA_DM_Settings::get( 'custom_product_meta_key', '' );
				$id  = '' !== $key ? trim( (string) $product->get_meta( $key, true ) ) : '';
				if ( '' === $id && $product->is_type( 'variation' ) ) {
					$parent = wc_get_product( $product->get_parent_id() );
					$id = ( $parent instanceof WC_Product && '' !== $key ) ? trim( (string) $parent->get_meta( $key, true ) ) : '';
				}
				if ( '' === $id ) {
					$id = (string) $product->get_id();
				}
				break;

			case 'variation_or_product_id':
			default:
				$id = (string) $product->get_id();
				break;
		}

		return (string) apply_filters( 'ama_dm_product_identifier', $id, $product, $mode );
	}

	public static function category( $term ) {
		if ( is_numeric( $term ) ) {
			$term = get_term( absint( $term ), 'product_cat' );
		}
		if ( ! $term instanceof WP_Term ) {
			return '';
		}
		$mode = (string) AMA_DM_Settings::get( 'category_id_mode', 'term_id' );
		$id   = 'slug' === $mode ? $term->slug : (string) $term->term_id;
		return (string) apply_filters( 'ama_dm_category_identifier', $id, $term, $mode );
	}

	public static function price( $price ) {
		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 2;
		return function_exists( 'wc_format_decimal' ) ? (string) wc_format_decimal( $price, $decimals ) : number_format( (float) $price, $decimals, '.', '' );
	}
}
