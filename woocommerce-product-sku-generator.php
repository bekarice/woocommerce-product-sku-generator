<?php
/**
 * Plugin Name: WooCommerce Product SKU Generator
 * Plugin URI: http://www.skyverge.com/product/woocommerce-product-sku-generator/
 * Description: Automatically generate SKUs for products using the product / variation slug and/or ID
 * Author: SkyVerge
 * Author URI: http://www.skyverge.com/
 * Version: 2.0.0
 * Text Domain: wc-product-sku-generator
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2014-2015 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   WC-Product-SKU-Generator
 * @author    SkyVerge
 * @category  Admin
 * @copyright Copyright (c) 2014-2015, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 *
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Plugin Description
 *
 * Generate a SKU for products that is equal to the product slug or product ID.
 * Simple / parent products will have one SKU equal to the slug or ID.
 * Variable products
 */
 
 
class WC_SKU_Generator {
	
	
	const VERSION = '2.0.0';
	
	
	/** @var WC_SKU_Generator single instance of this plugin */
	protected static $instance;
	
	
	public function __construct() {
		
		// load translations
		add_action( 'init', array( $this, 'load_translation' ) );
		
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
		
			// add settings
			add_filter( 'woocommerce_products_general_settings', array( $this, 'add_settings' ) );
			add_filter( 'woocommerce_product_settings', array( $this, 'add_settings' ) );
			
			// add plugin links
			add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), array( $this, 'add_plugin_links' ) );
			
			// save the generated SKUs during product edit / bulk edit
			add_action( 'woocommerce_product_bulk_edit_save', array( $this, 'maybe_save_sku' ) );
			add_action( 'woocommerce_process_product_meta', array( $this, 'maybe_save_sku' ), 100, 2 );
			
			// disable SKU fields when being generated by us
			add_action( 'init', array( $this, 'maybe_disable_skus' ) );
			
			// run every time
			$this->install();
		}
	}
	
	
	/**
	 * Main Sku Generator Instance, ensures only one instance is/can be loaded
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
	 * Adds plugin page links
	 * 
	 * @since 2.0.0
	 * @param array $links all plugin links
	 * @return array $links all plugin links + our custom links (i.e., "Settings")
	 */
	public function add_plugin_links( $links ) {
	
		$plugin_links = array(
			'<a href="' . admin_url( 'admin.php?page=wc-settings&tab=products' ) . '">' . __( 'Configure', 'wc-product-sku-generator' ) . '</a>',
			'<a href="https://wordpress.org/support/plugin/woocommerce-product-sku-generator">' . __( 'Support', 'wc-product-sku-generator' ) . '</a>',
		);
		
		return array_merge( $plugin_links, $links );
	}
	
 
	/**
	 * Load Translations
	 *
	 * @since 1.2.1
	 */
	public function load_translation() {
		// localization
		load_plugin_textdomain( 'wc-product-sku-generator', false, dirname( plugin_basename( __FILE__ ) ) . '/i18n/languages' );
	}
	
	
	/**
	 * Generate and save a simple / parent product SKU from the product slug or ID
	 *
	 * @since 2.0.0
	 * @param object $product WC_Product object
	 * @return string $parent_sku the generated string to use for the SKU
	 */
	protected function generate_product_sku( $product ) {
	
		// get the original product SKU in case we're not generating it
		$parent_sku = $product->get_sku();
		
		if ( 'slugs' === get_option( 'wc_sku_generator_simple' ) ) {
			$parent_sku = $product->get_post_data()->post_name;
		}
		
		if ( 'ids' === get_option( 'wc_sku_generator_simple' ) ) {
			$parent_sku = $product->id;
		}
		
		return apply_filters( 'wc_sku_generator_sku', $parent_sku, $product );
	}
	
	
	/**
	 * Generate and save a product variation SKU using the product slug or ID
	 *
	 * @since 2.0.0
	 * @param object $variation WC_Product object
	 * @return string $variation_sku the generated string to append for the variation's SKU
	 */
	protected function generate_variation_sku( $variation ) {
		
		if ( 'slugs' === get_option( 'wc_sku_generator_variation' ) ) {
			
			/**
			 * Attributes in SKUs _could_ be sorted inconsistently in rare cases
			 * Return true here to ensure they're always sorted consistently
			 *
			 * @since 2.0.0
			 * @param bool $sort_atts true to force attribute sorting
			 * @see https://github.com/bekarice/woocommerce-product-sku-generator/pull/2
			 */
			if ( apply_filters( 'wc_sku_generator_force_attribute_sorting', false ) ) {
				ksort( $variation['attributes'] );
			}
			
			$separator = apply_filters( 'wc_sku_generator_attribute_separator', '-' );
			
			$variation_sku = implode( $variation['attributes'], $separator );
			$variation_sku = str_replace( 'attribute_', '', $variation_sku );
		}
		
		if ( 'ids' === get_option( 'wc_sku_generator_variation')  ) {
			
			$variation_sku = $variation['variation_id'];	
		}
		
		return apply_filters( 'wc_sku_generator_variation_sku', $variation_sku, $variation );
	}
	
	
	/**
	 * Update the product with the generated SKU
	 * 
	 * @since 2.0.0
	 * @param object|int $product WC_Product object or product ID
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
		
			foreach ( $product->get_available_variations() as $variation ) {
		
				$variation_sku = $this->generate_variation_sku( $variation );
				$sku = $product_sku . '-' . $variation_sku;
				
				$sku =  apply_filters( 'wc_sku_generator_variation_sku_format', $sku, $product_sku, $variation_sku );
				update_post_meta( $variation['variation_id'], '_sku', $sku );
			}
		}
		
		// Save the SKU for simple / external / parent products if we should
		if (  'never' !== get_option( 'wc_sku_generator_simple' ) )  {
			update_post_meta( $product->id, '_sku', $product_sku );
		}
	}
	
	
	/**
	 * Disable SKUs if they're being generated by the plugin
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

				?><p class="form-field"><label><?php esc_html_e( 'SKU', 'wc-product-sku-generator' ); ?></label><span><?php echo esc_html( get_post_meta( $thepostid, '_sku', true ) ); ?></span></p><?php

				add_filter( 'wc_product_sku_enabled', '__return_true' );
			}
			add_action( 'woocommerce_product_options_sku', 'wc_sku_generator_create_sku_label' );
			
		}
		
		// TODO 2015-08-10: maybe disable variations SKU fields if being generated?
		// would probably require js implementation
	}
	
	
	/**
	 * Add SKU generator to Settings > Products page at the end of the 'Product Data' section
	 * 
	 * @since 1.0
	 * @param array $settings the WooCommerce product settings
	 * @return array $updated_settings updated array with our settings added
	 */
	public function add_settings( $settings ) {
	
		$updated_settings = array();
	
		$setting_id = version_compare( WC_VERSION, '2.3', '>=' ) ? 'product_measurement_options' : 'product_data_options';
	
		foreach ( $settings as $setting ) {

			$updated_settings[] = $setting;

			if ( isset( $setting['id'] ) && $setting_id === $setting['id'] && 'sectionend' === $setting['type'] ) {

				$updated_settings = array_merge( $updated_settings, $this->get_settings() );
			}
		}
		return $updated_settings;
	}
	
	
	/**
	 * Create SKU Generator settings
	 *
	 * @since 1.2.0
	 * @return array $wc_sku_generator_settings plugin settings
	 */
	protected function get_settings() {

		$wc_sku_generator_settings = array(
		
			array(
				'title' 	=> __( 'Product SKUs', 'wc-product-sku-generator' ),
				'type' 		=> 'title',
				'id' 		=> 'product_sku_options'
			),
			
			array( 
				'title'    => __( 'Generate Simple / Parent SKUs:', 'wc-product-sku-generator' ),
				'desc'     => __( 'Generating simple / parent SKUs disables the SKU field while editing products.', 'wc-product-sku-generator' ),
				'id'       => 'wc_sku_generator_simple',
				'type'     => 'select',
				'options'  => array(
					'never'	=> __( 'Never (let me set them)', 'wc-product-sku-generator' ),
					'slugs'	=> __( 'Using the product slug (name)', 'wc-product-sku-generator' ),
					'ids'	=> __( 'Using the product ID', 'wc-product-sku-generator' ),
				),
				'default'  => 'slugs',
				'class'    => 'wc-enhanced-select chosen_select',
				'css'      => 'min-width:300px;',
				'desc_tip' => __( 'Determine how SKUs for simple, external, or parent products will be generated.', 'wc-product-sku-generator' ),
			),
			
			array( 
				'title'    => __( 'Generate Variation SKUs:', 'wc-product-sku-generator' ),
				'desc'     => __( 'Determine how SKUs for product variations will be generated.', 'wc-product-sku-generator' ),
				'id'       => 'wc_sku_generator_variation',
				'type'     => 'select',
				'options'  => array(
					'never'	=> __( 'Never (let me set them)', 'wc-product-sku-generator' ),
					'slugs'	=> __( 'Using the attribute slugs (names)', 'wc-product-sku-generator' ),
					'ids'	=> __( 'Using the variation ID', 'wc-product-sku-generator' ),
				),
				'default'  => 'slugs',
				'class'    => 'wc-enhanced-select chosen_select',
				'css'      => 'min-width:300px;',
				'desc_tip' => true,
			),
			
			array(
				'type' 	=> 'sectionend',
				'id' 	=> 'product_sku_options'
			),
				
		);
		
		return $wc_sku_generator_settings;
	}

	
	/**
	 * Run every time.  Used since the activation hook is not executed when updating a plugin
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
		
		// update the installed version option
		update_option( 'wc_sku_generator_version', self::VERSION );
	}

} // end \WC_SKU_Generator class


/**
 * Returns the One True Instance of WC SKU Generator
 *
 * @since 2.0.0
 * @return WC_SKU_Generator
 */
function wc_sku_generator() {
    return WC_SKU_Generator::instance();
}


/**
 * The WC_SKU_Generator global object, exists only for backwards compat
 *
 * @deprecated 2.0.0
 * @name $wc_sku_generator
 * @global WC_SKU_Generator $GLOBALS['wc_sku_generator']
 */
$GLOBALS['wc_sku_generator'] = wc_sku_generator();