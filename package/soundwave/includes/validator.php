<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Internal: read a value from a single product by meta first, then attributes.
 */
function soundwave__value_from_single_product( WC_Product $product, array $candidates ) {
	// 1) Try post meta on this product
	foreach ( $candidates as $key ) {
		$val = $product->get_meta( $key, true );
		if ( is_string($val) ) { $val = trim($val); }
		if ( $val !== '' && $val !== null ) {
			return $val;
		}
	}

	// 2) Try attributes on this product
	$attr_candidates = array_unique(array_merge(
		$candidates,
		array_map(function($k){ return 'pa_' . ltrim(str_replace(['-',' '], '_', strtolower($k)), '_'); }, $candidates),
		array_map(function($k){ return ltrim(str_replace(['-',' '], '_', strtolower($k)), '_'); }, $candidates)
	));

	foreach ( $attr_candidates as $attr_key ) {
		$val = $product->get_attribute( $attr_key );
		if ( is_string($val) && trim($val) !== '' ) {
			return trim($val);
		}
	}

	return null;
}

/**
 * Return the first non-empty value from an array of candidate keys.
 * Checks the variation first (if present), then **falls back to the parent product**.
 */
function soundwave_first_value_from_candidates( WC_Product $product, array $candidates ) {
	// Try the current product (variation or simple)
	$val = soundwave__value_from_single_product( $product, $candidates );
	if ( $val !== null && $val !== '' ) return $val;

	// Fallback: if it's a variation, also check the parent product
	if ( $product instanceof WC_Product_Variation ) {
		$parent_id = $product->get_parent_id();
		if ( $parent_id ) {
			$parent = wc_get_product( $parent_id );
			if ( $parent ) {
				$val = soundwave__value_from_single_product( $parent, $candidates );
				if ( $val !== null && $val !== '' ) return $val;
			}
		}
	}

	return null;
}

/**
 * Validate required fields for every line item in a Woo order.
 * Returns ['ok'=>bool, 'errors'=>string[]]
 */
function soundwave_validate_order_required_fields( WC_Order $order ) {
	$required = [
		'Company Name' => [
			'company_name','company-name','Company Name','_company_name'
		],
		'Print Location' => [
			'print_location','print-location','Print Location','_print_location','attribute_print_location'
		],
		'Production' => [
			'production','Production','_production'
		],
		'Original Art' => [
			'original_art','original_art_url','Original Art','_original_art','original-art'
		],
		'Site Slug' => [
			'site_slug','site-slug','Site Slug','_site_slug'
		],
		'Vendor Code' => [
			'vendor_code','vendor-code','Vendor Code','_vendor_code','quality','Quality','_quality'
		],
	];

	$errors = [];
	$seq = 0;

	foreach ( $order->get_items( 'line_item' ) as $item ) {
		$seq++;
		$product = $item->get_product();
		if ( ! $product ) {
			$errors[] = sprintf(
				'Line item #%d “%s” has no associated product (deleted or unavailable).',
				$seq,
				$item->get_name()
			);
			continue;
		}

		$missing_labels = [];
		foreach ( $required as $label => $candidates ) {
			$val = soundwave_first_value_from_candidates( $product, $candidates );
			if ( $val === null || (is_string($val) && trim($val) === '') ) {
				$missing_labels[] = $label;
			}
		}

		if ( ! empty( $missing_labels ) ) {
			$errors[] = sprintf(
				'Line item #%d “%s” missing: %s',
				$seq,
				$item->get_name(),
				implode(', ', $missing_labels)
			);
		}
	}

	return [
		'ok'     => empty( $errors ),
		'errors' => $errors,
	];
}
