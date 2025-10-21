<?php
/**
 * Plugin Name: Recently Viewed Products Slider (Flatsome + LSCache-ready)
 * Description: Flatsome-compatible slider of recently viewed products. Works on localhost; supports LSCache ESI (private) on LiteSpeed servers.
 * Version: 1.3.2
 * Author: Yoan Chobanov
 */

if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'WooCommerce' ) ) return;

/* Write the WC "recently viewed" cookie only on single product pages */
add_action( 'template_redirect', function () {
    if ( function_exists('is_product') 
         && is_product() 
         && function_exists('wc_track_product_view') ) {
        wc_track_product_view();
    }
}, 20 );


/** Get recent product IDs from WC cookie */
function rvp_get_recent_ids( $limit = 15, $exclude_current = true ) {
	$ids = [];
	if ( function_exists( 'wc_get_product_ids_recently_viewed' ) ) {
		$ids = array_reverse( (array) wc_get_product_ids_recently_viewed() ); // newest first
	} elseif ( ! empty( $_COOKIE['woocommerce_recently_viewed'] ) ) {
		$raw = wp_unslash( $_COOKIE['woocommerce_recently_viewed'] );
		$ids = array_reverse( array_filter( array_map( 'absint', explode( '|', $raw ) ) ) );
	}
	if ( empty( $ids ) ) return [];

	$ids = array_values( array_unique( $ids ) );

	if ( $exclude_current && function_exists( 'is_product' ) && is_product() ) {
		global $product;
		if ( $product instanceof WC_Product ) {
			$ids = array_values( array_diff( $ids, [ (int) $product->get_id() ] ) );
		}
	}

	if ( $limit > 0 ) {
		$ids = array_slice( $ids, 0, $limit );
	}
	return $ids;
}

/** Build Flatsome-like slider HTML */
function rvp_build_slider_html( $args = [] ) {
	$defaults = [
		'limit'           => 12,
		'columns'         => 5,
		'medium_columns'  => 3,
		'small_columns'   => 2,
		'title'           => '',
		'exclude_current' => true,
		'debug'           => false,
	];
	$args = wp_parse_args( $args, $defaults );

	$ids = rvp_get_recent_ids( max( $args['limit'], 1 ), (bool) $args['exclude_current'] );
	if ( empty( $ids ) ) return '';

	// Build a Woo-style product query that respects catalog visibility
	$wc_query = new WP_Query( [
		'post_type'           => 'product',
		'post__in'            => $ids,
		'orderby'             => 'post__in',
		'posts_per_page'      => count( $ids ),
		'post_status'         => 'publish',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'meta_query'          => WC()->query->get_meta_query(),
		'tax_query'           => WC()->query->get_tax_query(),
	] );

	if ( ! $wc_query->have_posts() ) return '';

	// Flickity & row classes mirroring Flatsome's related block
	$row_classes = sprintf(
		'row has-equal-box-heights equalize-box large-columns-%1$d medium-columns-%2$d small-columns-%3$d row-small slider row-slider slider-nav-reveal slider-nav-push',
		max(1,(int)$args['columns']),
		max(1,(int)$args['medium_columns']),
		max(1,(int)$args['small_columns'])
	);
	$flickity_options = [
		'imagesLoaded'     => true,
		'groupCells'       => '100%',
		'dragThreshold'    => 5,
		'cellAlign'        => 'left',
		'wrapAround'       => true,
		'prevNextButtons'  => true,
		'percentPosition'  => true,
		'pageDots'         => false,
		'rightToLeft'      => is_rtl(),
		'autoPlay'         => false,
	];
	$flickity_attr = esc_attr( wp_json_encode( $flickity_options ) );

	ob_start();
	echo '<div class="related related-products-wrapper product-section rvp-section">';
	if ( ! empty( $args['title'] ) ) {
		echo '<h3 class="product-section-title container-width product-section-title-related pt-half pb-half uppercase">'
		   . esc_html( $args['title'] ) . '</h3>';
	}
	echo '<div class="' . esc_attr( $row_classes ) . '" data-flickity-options=\'' . $flickity_attr . '\'>';

	while ( $wc_query->have_posts() ) {
		$wc_query->the_post();

		// Make sure Flatsome’s template has the correct globals
		$GLOBALS['product'] = wc_get_product( get_the_ID() );
		wc_get_template_part( 'content', 'product' );
	}
	wp_reset_postdata();

	echo '</div></div>';

	if ( $args['debug'] ) {
		printf( "\n<!-- RVP debug: ids=%s -->\n", esc_html( implode( ',', $ids ) ) );
	}

	return ob_get_clean();
}

add_shortcode( 'recently_viewed_slider', function( $atts ) {
	$atts = shortcode_atts( [
		'limit'           => 12,
		'columns'         => 5,
		'medium_columns'  => 3,
		'small_columns'   => 2,
		'title'           => '',
		'exclude_current' => 'yes',
		'debug'           => 'no',
	], $atts, 'recently_viewed_slider' );

	return rvp_build_slider_html( [
		'limit'           => (int) $atts['limit'],
		'columns'         => (int) $atts['columns'],
		'medium_columns'  => (int) $atts['medium_columns'],
		'small_columns'   => (int) $atts['small_columns'],
		'title'           => $atts['title'],
		'exclude_current' => filter_var( $atts['exclude_current'], FILTER_VALIDATE_BOOLEAN ),
		'debug'           => filter_var( $atts['debug'], FILTER_VALIDATE_BOOLEAN ),
	] );
} );

/** Auto output under the single product summary */
add_action( 'woocommerce_after_single_product_summary', function() {
	if ( ! function_exists( 'is_product' ) || ! is_product() ) return;

	$shortcode = '[recently_viewed_slider limit="12" columns="5" title="Последно разгледани" exclude_current="yes"]';

	// Wrap in ESI block when LiteSpeed Cache is present
	if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' ) ) {
		$shortcode = '[esi cache="private" ttl="86400"]' . $shortcode . '[/esi]';
	}

	echo do_shortcode( $shortcode );
}, 25 );

