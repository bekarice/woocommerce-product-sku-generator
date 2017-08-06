<?php
/**
 * Plugin Name: WooCommerce Product SKU Generator
 * Plugin URI: http://www.skyverge.com/product/woocommerce-product-sku-generator/
 * Description: Automatically generate SKUs for products using the product / variation slug and/or ID
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com/
 * Version: 2.3.2-dev
 * Text Domain: woocommerce-product-sku-generator
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2014-2017 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Product-SKU-Generator
 * @author    SkyVerge
 * @category  Admin
 * @copyright Copyright (c) 2014-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 */

defined( 'ABSPATH' ) or exit;

/**
 * ## Plugin Description
 *
 * Generate a SKU for products that is equal to the product slug or product ID.
 * Simple / parent products can have a SKU equal to the slug or ID.
 * Variable products can have a SKU that combines the parent SKU + variation ID or attributes.
 *
 * ## Database
 *
 * ### Options:
 * `wc_sku_generator_version` - string version number for the plugin
 * `wc_sku_generator_simple` - SKU format for simple / parent products
 * `wc_sku_generator_variation` - SKU format for product variations
 * `wc_sku_generator_attribute_spaces` - determines how spaces in attribute names are treated / replaced
 */

// Check if WooCommerce is active
if ( ! WC_SKU_Generator::is_woocommerce_active() ) {
	return;
}

// WC version check
if ( version_compare( get_option( 'woocommerce_db_version' ), '2.5.0', '<' ) ) {
	add_action( 'admin_notices', array( 'WC_SKU_Generator', 'render_outdated_wc_version_notice' ) );
	return;
}

// Make sure we're loaded after WC and fire it up!
add_action( 'plugins_loaded', 'wc_sku_generator' );


/**
 * Class WC_SKU_Generator
 * Sets up the plugin and all of its admin methods.
 *
 * @since 2.0.0
 */

class WC_SKU_Generator {


	const VERSION = '2.3.2-dev';

	/** @var WC_SKU_Generator single instance of this plugin */
	protected static $instance;


	/**
	 * WC_SKU_Generator constructor.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {

		// load translations
		add_action( 'init', array( $this, 'load_translation' ) );

		if ( is_admin() && ! is_ajax() ) {

			// add settings
			add_filter( 'woocommerce_products_general_settings', array( $this, 'add_settings' ) );
			add_action( 'admin_print_scripts',                   array( $this, 'admin_js' ) );

			// add plugin links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_links' ) );

			// save the generated SKUs during product edit / bulk edit
			add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'maybe_save_sku' ) );
			add_action( 'woocommerce_process_product_meta',   array( $this, 'maybe_save_sku' ), 100, 2 );

			// disable SKU fields when being generated by the plugin
			add_action( 'init', array( $this, 'maybe_disable_skus' ) );

			// run every time
			$this->install();
		}
	}


	/** Helper methods ***************************************/


	/**
	 * Main Sku Generator Instance, ensures only one instance is/can be loaded.
	 *
	 * @since 2.0.0
	 * @see wc_sku_generator()
	 * @return WC_SKU_Generator
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Cloning instances is forbidden due to singleton pattern.
	 *
	 * @since 2.2.0
	 */
	public function __clone() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot clone instances of %s.', 'woocommerce-product-sku-generator' ), 'WooCommerce Product SKU Generator' ), '2.2.0' );
	}


	/**
	 * Unserializing instances is forbidden due to singleton pattern.
	 *
	 * @since 2.2.0
	 */
	public function __wakeup() {
		/* translators: Placeholders: %s - plugin name */
		_doing_it_wrong( __FUNCTION__, sprintf( esc_html__( 'You cannot unserialize instances of %s.', 'woocommerce-product-sku-generator' ), 'WooCommerce Product SKU Generator' ), '2.2.0' );
	}


	/**
	 * Adds plugin page links to the Plugins menu.
	 *
	 * @since 2.0.0
	 * @param array $links all plugin links
	 * @return array $links all plugin links + our custom links (i.e., "Settings")
	 */
	public function add_plugin_links( $links ) {

		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=products' ) . '">' . __( 'Configure', 'woocommerce-product-sku-generator' ) . '</a>',
			'<a href="https://wordpress.org/plugins/woocommerce-product-sku-generator/faq/">' . __( 'FAQ', 'woocommerce-product-sku-generator' ) . '</a>',
			'<a href="https://wordpress.org/support/plugin/woocommerce-product-sku-generator">' . __( 'Support', 'woocommerce-product-sku-generator' ) . '</a>',
		);

		return array_merge( $plugin_links, $links );
	}


	/**
	 * Load Translations.
	 *
	 * @since 1.2.1
	 */
	public function load_translation() {
		// localization
		load_plugin_textdomain( 'woocommerce-product-sku-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages' );
	}


	/**
	 * Checks if WooCommerce is active.
	 *
	 * @since 2.2.0
	 * @return bool true if WooCommerce is active, false otherwise
	 */
	public static function is_woocommerce_active() {

		$active_plugins = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active_plugins = array_merge( $active_plugins, get_site_option( 'active_sitewide_plugins', array() ) );
		}

		return in_array( 'woocommerce/woocommerce.php', $active_plugins ) || array_key_exists( 'woocommerce/woocommerce.php', $active_plugins );
	}


	/**
	 * Renders a notice when WooCommerce version is outdated.
	 *
	 * @since 2.2.0
	 */
	public static function render_outdated_wc_version_notice() {

		$message = sprintf(
			/* translators: Placeholders: %1$s <strong>, %2$s - </strong>, %3$s and %5$s - <a> tags, %4$s - </a> */
			esc_html__( '%1$sWooCommerce Product SKU Generator is inactive.%2$s This plugin requires WooCommerce 2.5 or newer. Please %3$supdate WooCommerce%4$s or %5$srun the WooCommerce database upgrade%4$s.', 'woocommerce-product-sku-generator' ),
			'<strong>',
			'</strong>',
			'<a href="' . admin_url( 'plugins.php' ) . '">',
			'</a>',
			'<a href="' . admin_url( 'plugins.php?do_update_woocommerce=true' ) . '">'
		);

		printf( '<div class="error"><p>%s</p></div>', $message );
	}


	/** Plugin methods ***************************************/


	/**
	 * Generate and save a simple / parent product SKU from the product slug or ID.
	 *
	 * @since 2.0.0
	 * @param \WC_Product $product WC_Product object
	 * @return string $parent_sku the generated string to use for the SKU
	 */
	protected function generate_product_sku( $product ) {

		switch( get_option( 'wc_sku_generator_simple' ) ) {

			case 'slugs':
				$product_sku = urldecode( get_post( $product->get_id() )->post_name );
			break;

			case 'ids':
				$product_sku = $product->get_id();
			break;

			// use the original product SKU if we're not generating it
			default:
				$product_sku = $product->get_sku();
		}

		/**
		 * Filters the generated SKU for simple or parent products.
		 *
		 * @since 2.0.0
		 * @param string $product_sku the generated SKU
		 * @param \WC_Product $product the product object
		 */
		return apply_filters( 'wc_sku_generator_sku', $product_sku, $product );
	}


	/**
	 * Generate and save a product variation SKU using the product slug or ID.
	 *
	 * @since 2.0.0
	 * @param array $variation product variation data
	 * @return string $variation_sku the generated string to append for the variation's SKU
	 */
	protected function generate_variation_sku( $variation ) {

		if ( 'slugs' === get_option( 'wc_sku_generator_variation' ) ) {

			// replace spaces in attributes depending on settings
			switch ( get_option( 'wc_sku_generator_attribute_spaces' ) ) {

				case 'underscore':
					$variation['attributes'] = str_replace( ' ', '_', $variation['attributes'] );
				break;

				case 'dash':
					$variation['attributes'] = str_replace( ' ', '-', $variation['attributes'] );
				break;

				case 'none':
					$variation['attributes'] = str_replace( ' ', '', $variation['attributes'] );
				break;

			}

			/**
			 * Attributes in SKUs _could_ be sorted inconsistently in rare cases.
			 * Return true here to ensure they're always sorted consistently.
			 *
			 * @since 2.0.0
			 * @param bool $sort_atts true to force attribute sorting
			 * @see https://github.com/skyverge/woocommerce-product-sku-generator/pull/2
			 */
			if ( apply_filters( 'wc_sku_generator_force_attribute_sorting', false ) ) {
				ksort( $variation['attributes'] );
			}

			/**
			 * Filters the separator used between variation attributes.
			 *
			 * @since 2.0.0
			 * @param string $separator the separator character
			 */
			$separator = apply_filters( 'wc_sku_generator_attribute_separator', $this->get_sku_separator() );

			$variation_sku = implode( $variation['attributes'], $separator );
			$variation_sku = str_replace( 'attribute_', '', $variation_sku );
		}

		if ( 'ids' === get_option( 'wc_sku_generator_variation') ) {

			$variation_sku = $variation['variation_id'];
		}

		/**
		 * Filters the generated variation portion of the SKU.
		 *
		 * @since 2.0.0
		 * @param string $variation_sku the generated variation portion of the SKU
		 * @param array $variation product variation data
		 */
		return apply_filters( 'wc_sku_generator_variation_sku', $variation_sku, $variation );
	}


	/**
	 * Update the product with the generated SKU.
	 *
	 * @since 2.0.0
	 * @param \WC_Product|int $product WC_Product object or product ID
	 */
	public function maybe_save_sku( $product ) {

		// Checks to ensure we have a product and gets one if we have an ID
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( absint( $product ) );
		}

		// Generate the SKU for simple / external / variable parent products
		$product_sku = $this->generate_product_sku( $product );

		// Only generate / save variation SKUs when we should
		if ( $product->is_type( 'variable' ) && 'never' !== get_option( 'wc_sku_generator_variation' ) ) {

			$variations = $this->get_all_variations( $product->get_id() );

			foreach ( $variations as $variation_id ) {

				$product_variation = wc_get_product( $variation_id );
				$variation         = $product->get_available_variation( $product_variation );

				$variation_sku = $this->generate_variation_sku( $variation );
				$sku           = $product_sku . $this->get_sku_separator() . $variation_sku;

				/**
				 * Filters the entire generated SKU for a variable product.
				 *
				 * @since 2.0.0
				 * @param string $sku the complete generated SKU
				 * @param string $product_sku the portion of the SKU for the parent product
				 * @param string $variation_sku the portion of the SKU for the variation
				 */
				$sku = apply_filters( 'wc_sku_generator_variation_sku_format', $sku, $product_sku, $variation_sku );

				update_post_meta( $variation_id, '_sku', $sku );
			}
		}

		// Save the SKU for simple / external / parent products if we should
		if ( 'never' !== get_option( 'wc_sku_generator_simple' ) )  {
			update_post_meta( $product->get_id(), '_sku', $product_sku );
		}
	}


	/**
	 * Disable SKUs if they're being generated by the plugin.
	 *
	 * TODO: update the get_post_meta call to use a product CRUD method when WC 3.0 is required {BR 2017-03-21}
	 *
	 * @since 1.0.2
	 */
	public function maybe_disable_skus() {

		if ( 'never' !== get_option( 'wc_sku_generator_simple' ) ) {

			// temporarily disable SKUs
			function wc_sku_generator_disable_parent_sku_input() {
				add_filter( 'wc_product_sku_enabled', '__return_false' );
			}
			add_action( 'woocommerce_product_write_panel_tabs', 'wc_sku_generator_disable_parent_sku_input' );

			//	Create a custom SKU label for Product Data
			function wc_sku_generator_create_sku_label() {
				global $thepostid;

				?><p class="form-field"><label><?php esc_html_e( 'SKU', 'woocommerce-product-sku-generator' ); ?></label><span><?php echo esc_html( get_post_meta( $thepostid, '_sku', true ) ); ?></span></p><?php

				add_filter( 'wc_product_sku_enabled', '__return_true' );
			}
			add_action( 'woocommerce_product_options_sku', 'wc_sku_generator_create_sku_label' );

		}

		// TODO {BR 2015-08-10}: maybe disable variations SKU fields if being generated?
		// likely needs purely js solution
	}


	/**
	 * Add SKU generator to Settings > Products page at the end of the 'Product Data' section.
	 *
	 * @since 1.0.0
	 * @param array $settings the WooCommerce product settings
	 * @return array $updated_settings updated array with our settings added
	 */
	public function add_settings( $settings ) {

		$updated_settings = array();

		foreach ( $settings as $setting ) {

			$updated_settings[] = $setting;

			if ( isset( $setting['id'] ) && 'product_measurement_options' === $setting['id'] && 'sectionend' === $setting['type'] ) {
				$updated_settings = array_merge( $updated_settings, $this->get_settings() );
			}
		}

		return $updated_settings;
	}


	/**
	 * Create SKU Generator settings.
	 *
	 * @since 1.2.0
	 * @return array $wc_sku_generator_settings plugin settings
	 */
	protected function get_settings() {

		$wc_sku_generator_settings = array(

			array(
				'title' => __( 'Product SKUs', 'woocommerce-product-sku-generator' ),
				'type'  => 'title',
				'id'    => 'product_sku_options'
			),

			array(
				'title'    => __( 'Generate Simple / Parent SKUs:', 'woocommerce-product-sku-generator' ),
				'desc'     => '<br />' . __( 'Generating simple / parent SKUs disables the SKU field while editing products.', 'woocommerce-product-sku-generator' ),
				'id'       => 'wc_sku_generator_simple',
				'type'     => 'select',
				'options'  => array(
					'never' => __( 'Never (let me set them)', 'woocommerce-product-sku-generator' ),
					'slugs' => __( 'Using the product slug (name)', 'woocommerce-product-sku-generator' ),
					'ids'   => __( 'Using the product ID', 'woocommerce-product-sku-generator' ),
				),
				'default'  => 'slugs',
				'class'    => 'wc-enhanced-select chosen_select',
				'css'      => 'min-width:300px;',
				'desc_tip' => __( 'Determine how SKUs for simple, external, or parent products will be generated.', 'woocommerce-product-sku-generator' ),
			),

			array(
				'title'    => __( 'Generate Variation SKUs:', 'woocommerce-product-sku-generator' ),
				'desc'     => __( 'Determine how SKUs for product variations will be generated.', 'woocommerce-product-sku-generator' ),
				'id'       => 'wc_sku_generator_variation',
				'type'     => 'select',
				'options'  => array(
					'never' => __( 'Never (let me set them)', 'woocommerce-product-sku-generator' ),
					'slugs' => __( 'Using the attribute slugs (names)', 'woocommerce-product-sku-generator' ),
					'ids'   => __( 'Using the variation ID', 'woocommerce-product-sku-generator' ),
				),
				'default'  => 'slugs',
				'class'    => 'wc-enhanced-select chosen_select',
				'css'      => 'min-width:300px;',
				'desc_tip' => true,
			),

			array(
				'title'    => __( 'Replace spaces in attributes?', 'woocommerce-product-sku-generator' ),
				/* translators: placeholders are <strong> tags */
				'desc'     => '<br />' . sprintf( __( '%1$sWill update existing variation SKUs when product is saved if they contain spaces%2$s.', 'woocommerce-product-sku-generator' ), '<strong>', '</strong>' ),
				'id'       => 'wc_sku_generator_attribute_spaces',
				'type'     => 'select',
				'options'  => array(
					'no'         => __( 'Do not replace spaces in attribute names.', 'woocommerce-product-sku-generator' ),
					'underscore' => __( 'Replace spaces with underscores', 'woocommerce-product-sku-generator' ),
					'dash'       => __( 'Replace spaces with dashes / hyphens', 'woocommerce-product-sku-generator' ),
					'none'       => __( 'Remove spaces from attribute names', 'woocommerce-product-sku-generator' ),
				),
				'default'  => 'no',
				'class'    => 'wc-enhanced-select chosen_select',
				'css'      => 'min-width:300px;',
				'desc_tip' => __( 'Replace spaces in attribute names when used in a SKU.', 'woocommerce-product-sku-generator' ),
			),

			array(
				'type' => 'sectionend',
				'id'   => 'product_sku_options'
			),

		);

		return $wc_sku_generator_settings;
	}


	/**
	 * Manually get all variations for a product because WC core functions only
	 *  give us "published" variations, and out of stock ones are "private". ಠ_ಠ
	 *
	 * @since 2.3.0
	 * @param int $product_id the ID for the parent product
	 * @return array IDs of all variations for the product
	 */
	protected function get_all_variations( $product_id ) {

		/**
		 * Filters variation query args to get variations for a variable product.
		 *
		 * @since 2.3.0
		 * @param array $args get_posts args
		 */
		$args = apply_filters( 'wc_sku_generator_variation_query_args', array(
			'post_parent' => $product_id,
			'post_type'   => 'product_variation',
			'orderby'     => 'menu_order',
			'order'       => 'ASC',
			'fields'      => 'ids',
			'post_status' => array( 'publish', 'private' ),
			'numberposts' => -1,
		) );

		return get_posts( $args );
	}


	/**
	 * Get the separator to use between parent / variation SKUs, along with variation attributes.
	 *
	 * @since 2.3.0
	 * @return string $separator the separator character
	 */
	private function get_sku_separator() {

		/**
		 * Filters the separator used between parent / variation SKUs or between variation attributes
		 *
		 * @since 2.3.0
		 * @param string $separator the separator character
		 */
		return apply_filters( 'wc_sku_generator_sku_separator', '-' );
	}


	/**
	 * Hides the "replace spaces in attributes" setting if slugs are not used for variation SKUs.
	 *
	 * @since 2.1.0
	 */
	public function admin_js() {
		global $current_section;

		$current_page = isset( $_GET['page'] ) ? $_GET['page'] : '';
		$current_tab  = isset( $_GET['tab'] )  ? $_GET['tab']  : '';

		if ( 'wc-settings' === $current_page && 'products' === $current_tab && empty( $current_section ) ) {
			wc_enqueue_js(
				"jQuery(document).ready(function() {

					jQuery( 'select#wc_sku_generator_variation' ).change( function() {
						if ( jQuery( this ).val() === 'slugs' ) {
							jQuery( this ).closest('tr').next('tr').show();
						} else {
							jQuery( this ).closest('tr').next('tr').hide();
						}
					}).change();

				});"
			);
		}
	}


	/** Lifecycle methods ***************************************/


	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin.
	 *
	 * @since 2.0.0
	 */
	private function install() {

		// get current version to check for upgrade
		$installed_version = get_option( 'wc_sku_generator_version' );

		// force upgrade to 2.0.0, prior versions did not have version option set
		if ( ! $installed_version ) {
			$this->upgrade( '1.2.2' );
		}

		// upgrade if installed version lower than plugin version
		if ( -1 === version_compare( $installed_version, self::VERSION ) ) {
			$this->upgrade( $installed_version );
		}

		// install defaults for settings
		foreach( $this->get_settings() as $setting ) {

			if ( isset( $setting['default'] ) && ! get_option( $setting['id'] ) ) {
				update_option( $setting['id'], $setting['default'] );
			}
		}
	}


	/**
	 * Perform any version-related changes.
	 *
	 * @since 2.0.0
	 * @param int $installed_version the currently installed version of the plugin
	 */
	private function upgrade( $installed_version ) {

		// upgrade from 1.2.2 to 2.0.0
		if ( '1.2.2' === $installed_version ) {

			switch ( get_option( 'wc_sku_generator_select' ) ) {

				case 'all':
					update_option( 'wc_sku_generator_simple', 'slugs' );
					update_option( 'wc_sku_generator_variation', 'slugs' );
				break;

				case 'simple':
					update_option( 'wc_sku_generator_simple', 'slugs' );
					update_option( 'wc_sku_generator_variation', 'never' );
				break;

				case 'variations':
					update_option( 'wc_sku_generator_simple', 'never' );
					update_option( 'wc_sku_generator_variation', 'slugs' );
				break;

			}

			// Delete the old option now that we've upgraded
			delete_option( 'wc_sku_generator_select' );
		}

		// Upgrade to version 2.2.0, this setting was only available in 2.1.0
		if ( '2.1.0' === $installed_version ) {

			if ( 'yes' === get_option( 'wc_sku_generator_attribute_spaces' ) ) {
				update_option( 'wc_sku_generator_attribute_spaces', 'underscore' );
			}
		}

		// update the installed version option
		update_option( 'wc_sku_generator_version', self::VERSION );
	}


}


/**
 * Returns the One True Instance of WC SKU Generator.
 *
 * @since 2.0.0
 * @return WC_SKU_Generator
 */
function wc_sku_generator() {
	return WC_SKU_Generator::instance();
}
