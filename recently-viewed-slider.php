<?php
/**
 * Plugin Name: Recently Viewed Products Slider (Complianz + LSCache Ready)
 * Description: Reads the cookie set by Complianz JS and renders a cached slider via ESI.
 * Version: 2.0.0
 * Author: Yoan Chobanov
 */

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * 1. RETRIEVE IDs
 * We rely PURELY on the cookie now, because Complianz handles the logic.
 */
function rvp_get_recent_ids( $limit = 15, $exclude_id = 0 ) {
    $ids = [];
    
    // We check the cookie directly.
    if ( ! empty( $_COOKIE['woocommerce_recently_viewed'] ) ) {
        $raw = wp_unslash( $_COOKIE['woocommerce_recently_viewed'] );
        // Your JS saves as pipe separated: "10|20|30"
        $ids = array_filter( array_map( 'absint', explode( '|', $raw ) ) );
    }

    if ( empty( $ids ) ) return [];

    // Your JS saves [Old, Middle, New].
    // We reverse it to show [New, Middle, Old].
    $ids = array_reverse( array_values( array_unique( $ids ) ) );

    // Exclude current product if requested
    if ( $exclude_id > 0 ) {
        $ids = array_values( array_diff( $ids, [ (int) $exclude_id ] ) );
    }

    return array_slice( $ids, 0, $limit );
}

/**
 * 2. BUILD HTML (Flatsome Style)
 */
function rvp_build_slider_html( $args = [] ) {
    $defaults = [
        'limit'           => 12,
        'columns'         => 5,
        'medium_columns'  => 3,
        'small_columns'   => 2,
        'title'           => '',
        'exclude_id'      => 0,
        'debug'           => false,
    ];
    $args = wp_parse_args( $args, $defaults );

    $ids = rvp_get_recent_ids( max( $args['limit'], 1 ), (int) $args['exclude_id'] );
    
    // If no history, show nothing.
    if ( empty( $ids ) ) return '';

    $wc_query = new WP_Query( [
        'post_type'           => 'product',
        'post__in'            => $ids,
        'orderby'             => 'post__in',
        'posts_per_page'      => count( $ids ),
        'post_status'         => 'publish',
        'ignore_sticky_posts' => true,
        'no_found_rows'       => true,
    ] );

    if ( ! $wc_query->have_posts() ) return '';

    // Flatsome Slider Classes
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
        'autoPlay'         => false,
    ];

    ob_start();
    echo '<div class="related related-products-wrapper product-section rvp-section">';
    if ( ! empty( $args['title'] ) ) {
        echo '<h3 class="product-section-title container-width product-section-title-related pt-half pb-half uppercase">' . esc_html( $args['title'] ) . '</h3>';
    }
    echo '<div class="' . esc_attr( $row_classes ) . '" data-flickity-options=\'' . esc_attr( wp_json_encode( $flickity_options ) ) . '\'>';

    while ( $wc_query->have_posts() ) {
        $wc_query->the_post();
        $GLOBALS['product'] = wc_get_product( get_the_ID() );
        wc_get_template_part( 'content', 'product' );
    }
    wp_reset_postdata();
    echo '</div></div>';

    return ob_get_clean();
}

/**
 * 3. SHORTCODE
 */
add_shortcode( 'recently_viewed_slider', function( $atts ) {
    $atts = shortcode_atts( [
        'limit'           => 12,
        'columns'         => 5,
        'medium_columns'  => 3,
        'small_columns'   => 2,
        'title'           => '',
        'exclude_id'      => 0,
    ], $atts, 'recently_viewed_slider' );

    return rvp_build_slider_html( [
        'limit'           => (int) $atts['limit'],
        'columns'         => (int) $atts['columns'],
        'medium_columns'  => (int) $atts['medium_columns'],
        'small_columns'   => (int) $atts['small_columns'],
        'title'           => $atts['title'],
        'exclude_id'      => (int) $atts['exclude_id'],
    ] );
} );

/**
 * 4. OUTPUT WITH ESI
 */
add_action( 'woocommerce_after_single_product_summary', function() {
    if ( ! function_exists( 'is_product' ) || ! is_product() ) return;

    $current_id = get_the_ID();
    
    $shortcode = sprintf(
        '[recently_viewed_slider limit="12" columns="5" title="Последно разгледани" exclude_id="%d"]',
        $current_id
    );

    // LiteSpeed ESI Check
    if ( defined( 'LSCWP_V' ) || class_exists( 'LiteSpeed_Cache' ) ) {
        // We use cache="private" so it reads the user's specific cookie
        // We use ttl="0" because the cookie changes via JS on every page load
        echo do_shortcode( '[esi cache="private" ttl="0"]' . $shortcode . '[/esi]' );
    } else {
        echo do_shortcode( $shortcode );
    }
}, 25 );