<?php
/**
 * Plugin Name: LeanCart Abandoned Cart Lite
 * Plugin URI:  https://boatliftlabs.com/
 * Description: Saves the cart contents of logged-in customers, marks carts older than a set time as “abandoned,” and lists them in the admin panel. The record is deleted automatically when an order is completed or the cart is emptied.
 * Version:     1.0.0
 * Author:      LeanCart
 * Author URI:  https://leancart.dev
 * License:     GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: abandoned-cart-lite
 * Requires Plugins: woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'LEANCART_ABANDONED_CART_LITE_DEMO_SITE' ) ) {
	define( 'LEANCART_ABANDONED_CART_LITE_DEMO_SITE', 'https://boatliftlabs.com' );
}

require_once __DIR__ . '/includes/class-leancart-framework.php';

final class LeanCart_Abandoned_Cart_Lite {

	const SLUG = 'abandoned-cart-lite';

	public static function init() {
		LeanCart_Framework::boot( array(
			'slug'    => self::SLUG,
			'name'    => 'LeanCart Abandoned Cart Lite',
			'version' => '1.0.0',
			'file'    => __FILE__,
			'site'    => LEANCART_ABANDONED_CART_LITE_DEMO_SITE,
		) );

		// WooCommerce may load after these plugins; defer the check to runtime.
		add_action( 'admin_notices', array( __CLASS__, 'wc_notice' ) );

		load_plugin_textdomain( 'abandoned-cart-lite', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

		add_action( 'leancart_' . self::SLUG . '_settings_fields', array( __CLASS__, 'fields' ) );

		// WooCommerce may load after this plugin; hooks are registered once WC is ready.
		if ( LeanCart_Framework::is_wc() ) {
			self::hooks();
		} else {
			add_action( 'woocommerce_loaded', array( __CLASS__, 'hooks' ) );
		}
	}

	public static function wc_notice() {
		if ( LeanCart_Framework::is_wc() ) { return; }
		echo '<div class="notice notice-warning"><p><strong>' . esc_html( 'LeanCart Abandoned Cart Lite' ) . '</strong>: ';
		esc_html_e( 'This plugin requires WooCommerce to be active.', 'abandoned-cart-lite' );
		echo '</p></div>';
	}

	public static function fields() {
		LeanCart_Framework::field_text( 'hours', __('Abandonment time (hours)', 'abandoned-cart-lite'), __('Default: 2', 'abandoned-cart-lite'), 'number' );
	}


	public static function hooks() {
		add_action( 'woocommerce_cart_updated', array( __CLASS__, 'capture' ) );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'clear_on_order' ) );
		add_action( 'woocommerce_thankyou', array( __CLASS__, 'clear_on_order' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_page' ) );
	}

	public static function capture() {
		$user = wp_get_current_user();
		if ( ! $user->ID ) { return; }
		$key = 'lc_cart_' . $user->ID;
		if ( ! function_exists( 'WC' ) || ! WC() || ! WC()->cart ) { return; }
		if ( WC()->cart->is_empty() ) {
			delete_transient( $key );
			delete_user_meta( $user->ID, '_lc_abandoned_cart' );
			return;
		}
		$items = array();
		foreach ( WC()->cart->get_cart() as $item ) {
			$items[] = array( 'id' => $item['product_id'], 'qty' => $item['quantity'] );
		}
		$data = array( 'email' => $user->user_email, 'items' => $items, 'total' => WC()->cart->get_subtotal(), 'time' => time() );
		update_user_meta( $user->ID, '_lc_abandoned_cart', $data );
	}

	public static function clear_on_order( $order ) {
		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) { $order = wc_get_order( $order ); }
		if ( ! is_object( $order ) ) { return; }
		$uid = (int) $order->get_customer_id();
		if ( ! $uid ) { return; }
		delete_user_meta( $uid, '_lc_abandoned_cart' );
		delete_transient( 'lc_cart_' . $uid );
	}

	public static function admin_page() {
		add_submenu_page( 'woocommerce', __( 'Abandoned carts', 'abandoned-cart-lite' ), __( 'Abandoned carts', 'abandoned-cart-lite' ), 'manage_woocommerce', 'lc-abandoned', array( __CLASS__, 'render' ) );
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) { wp_die(); }
		$users = get_users( array( 'meta_key' => '_lc_abandoned_cart', 'number' => 100 ) );
		$hours = max( 1, (int) LeanCart_Framework::setting( 'hours', 2 ) );
		echo '<div class="wrap"><h1>' . esc_html__( 'Abandoned carts', 'abandoned-cart-lite' ) . '</h1>';
		echo '<table class="widefat"><thead><tr><th>' . esc_html__( 'Email', 'abandoned-cart-lite' ) . '</th><th>' . esc_html__( 'Product', 'abandoned-cart-lite' ) . '</th><th>' . esc_html__( 'Amount', 'abandoned-cart-lite' ) . '</th><th>' . esc_html__( 'Status', 'abandoned-cart-lite' ) . '</th></tr></thead><tbody>';
		foreach ( $users as $user ) {
			$data = get_user_meta( $user->ID, '_lc_abandoned_cart', true );
			if ( ! is_array( $data ) || empty( $data['items'] ) ) { continue; }
			$age = ( time() - (int) $data['time'] ) / 3600;
			$status = $age >= $hours ? __( 'Abandoned', 'abandoned-cart-lite' ) : __( 'Active', 'abandoned-cart-lite' );
			$titles = array();
			foreach ( $data['items'] as $item ) { $titles[] = get_the_title( $item['id'] ); }
			echo '<tr><td>' . wp_kses_post( $data['email'] ) . '</td><td>' . wp_kses_post( implode( ', ', $titles ) ) . '</td><td>' . wp_kses_post( LeanCart_Framework::money( $data['total'] ) ) . '</td><td>' . wp_kses_post( $status ) . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}


}

LeanCart_Abandoned_Cart_Lite::init();

