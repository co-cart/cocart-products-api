<?php
/**
 * This file is designed to be used to load as package NOT a WP plugin!
 *
 * @version 4.0.0
 * @package CoCart Products API Package
 */

use CoCart\ProductsAPI\Package;

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'COCART_PRODUCTSAPI_PACKAGE_FILE' ) ) {
	define( 'COCART_PRODUCTSAPI_PACKAGE_FILE', __FILE__ );
}

// Include the main CoCart Products API Package class.
if ( ! class_exists( Package::class, false ) ) {
	include_once untrailingslashit( plugin_dir_path( COCART_PRODUCTSAPI_PACKAGE_FILE ) ) . '/includes/class-cocart-products-api.php';
}

/**
 * Returns the main instance of cocart_products_api_package and only runs if it does not already exists.
 *
 * @return cocart_products_api_package
 */
if ( ! function_exists( 'cocart_products_api_package' ) ) {
	function cocart_products_api_package() {
		return Package::init();
	}

	cocart_products_api_package();
}
