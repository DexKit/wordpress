<?php
/**
 * Plugin Name:     Dexkit Aggregator
 * Description:     DEXKIT PLUGIN
 * Author:          Dexkit
 * Author URI:      https://dexkit.com
 * Text Domain:     dexkit-aggregator
 * Domain Path:     /languages
 * Version:         0.1.4
 * @package         Dexkit-Aggregator
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load here functions that loads template
require __DIR__ . '/functions.php';
add_action( 'plugins_loaded', 'dexkit_aggregator_bootstrap' );

define( 'AGGREGATOR_TAG', 'dexkit_aggregator' );
define( 'AGGREGATOR_CRON', 'cron_dexkit_aggregator' );
define( 'AGGREGATOR_VERSION', '0.1.4' );
define( 'AGGREGATOR_API', 'https://query.dexkit.com');
define( 'AGGREGATOR_EXPIRE_DAYS', 7 );
define( 'AGGREGATOR_BUILD', plugin_dir_url( __FILE__ ) . 'build/' );
define( 'AGGREGATOR_MANIFEST', AGGREGATOR_BUILD . 'asset-manifest.json' );

// register hooks
register_activation_hook( __FILE__, array( DexkitAGGREGATOR::get_instance(), 'activate' ) );
register_deactivation_hook( __FILE__, array( DexkitAGGREGATOR::get_instance(), 'deactivate' ) );

// register actions
// add_action( 'plugins_loaded', array( DexkitAGGREGATOR::get_instance(), 'get_instance' ) );
add_action( 'init', 'wpdocs_add_custom_shortcode' );
add_action( AGGREGATOR_CRON , array( DexkitAGGREGATOR::get_instance(), 'fetch'));
add_action( 'admin_menu', array( DexkitAGGREGATOR::get_instance(), 'create_plugin_settings_page' ) );
add_action( 'wp_enqueue_scripts', array(DexkitAGGREGATOR::get_instance(), 'load_app'), 45000 );
 
// register shortcodes
function wpdocs_add_custom_shortcode() {
	add_shortcode( AGGREGATOR_TAG, array( DexkitAGGREGATOR::get_instance(), 'get_shortcode' ) );
}


class DexkitAGGREGATOR
{

	protected static $instance = NULL;


	/**
	 * 
	 */
	public static function get_instance()
	{
		NULL === self::$instance and self::$instance = new self;
		return self::$instance;
	}

	/**
	 * 
	 */
	public function __construct()
  {
  }


	/**
	 * 
	 */
	public function activate()
	{
		if ( ! is_admin() && ! current_user_can( 'activate_plugins' )) {
			return;
		}

		global $wpdb;

		// create database
		$table = $wpdb->prefix . AGGREGATOR_TAG;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			api varchar(100) DEFAULT NULL NULL,
			config text DEFAULT NULL NULL,
			verify_date datetime DEFAULT NULL NULL,
			expire_date datetime DEFAULT NULL NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
		dbDelta( $sql );
	
		// insert some data
		$wpdb->insert( $wpdb->prefix . AGGREGATOR_TAG, array( 'config' => '{}' ) );

		// create cronjob
		$timestamp = wp_next_scheduled( AGGREGATOR_CRON );
    if( $timestamp === false ){
      wp_schedule_event( time(), 'weekly', AGGREGATOR_CRON );
    }
	}


	/**
	 * 
	 */
  public function deactivate()
	{
		if ( ! is_admin() && ! current_user_can( 'activate_plugins' )) {
			return;
		}

		global $wpdb;

		// remove database
		$table_name = $wpdb->prefix . AGGREGATOR_TAG;
		$sql = "DROP TABLE IF EXISTS $table_name";
		$wpdb->query($sql);

		// remove cronjob
		$timestamp = wp_next_scheduled( AGGREGATOR_CRON );
		wp_unschedule_event( $timestamp, AGGREGATOR_CRON );
  }
	

	/**
	 * GET CONFIG FROM API
	 * { active: bool, config: string }
	 */
	private function get_config_from_api()
	{
		//global $wpdb;

		//$result = $wpdb->get_results( "SELECT * FROM " . $wpdb->prefix . AGGREGATOR_TAG . " WHERE id = 1", OBJECT );

		$domain = get_site_url();
    	$domain = preg_replace( "#^[^:/.]*[:/]+#i", "", $domain);

		///v4/config-wordpress?domain=dexkit.local&type=('DEX' ||  'AGGREGATOR' ||  'MARKETPLACE')
		$request = wp_remote_get( AGGREGATOR_API . '/v4/config-wordpress?domain='.$domain.'&type=AGGREGATOR' );
		
		if( is_wp_error( $request ) ) {
		 	return false; // Bail early
		}

		$body = wp_remote_retrieve_body( $request );

		return json_decode( $body );
		//return json_decode( '{ "active": true, "config": { "id": 123 } }' );
	}


	/**
	 * GET CONFIG FROM DATABASE
	 * { 	id: number, api: string, config: string, verify_date: Date, expire_date: Date }
	 */
	private function get_config()
	{
		global $wpdb;
		$result = $wpdb->get_results( "SELECT * FROM " . $wpdb->prefix . AGGREGATOR_TAG . " WHERE id = 1", OBJECT );
		return $result[0];
	}


	/**
	 * SAVE CONFIG/EXPIRE ON DATABASE
	 */
	private function set_config( $config, $expire = NULL )
	{
		global $wpdb;

		$wpdb->update( $wpdb->prefix . AGGREGATOR_TAG,
			array(
				'config' => json_encode($config),
				'expire_date' => $expire
			),
			array( 'id' => '1' )
		);
	}


	/**
	 * SAVE VERIFY ON DATABASE
	 */
	private function set_verify()
	{
		global $wpdb;

		$wpdb->update( $wpdb->prefix . AGGREGATOR_TAG,
			array(
				'verify_date' => current_time( 'mysql' )
			),
			array( 'id' => '1' )
		);
	}


	/**
	 * SET API ON DATABASE
	 */
	private function set_api( $api )
	{
		global $wpdb;

		$wpdb->update( $wpdb->prefix . AGGREGATOR_TAG,
			array( 'api'=> $api ),
			array( 'id' => '1' )
		);
	}


	/**
	 * 
	 */
	public function fetch()
	{
		$apiConfig = $this->get_config_from_api();

		if ( !is_object($apiConfig) ) {
			return false;
		}
		
		if ( $apiConfig->active ) {
			$this->set_config( $apiConfig->config );
		}
		else {
			$localConfig = $this->get_config();

			$now = current_time( 'mysql' );
			$expire = date( 'Y-m-d H:i:s', strtotime( $now ) + (86400 * AGGREGATOR_EXPIRE_DAYS) );

			if ($localConfig->expire_date == NULL) {
				$this->set_config( $localConfig->config, $expire );
			}
			else if ( strtotime( current_time( 'mysql' ) ) > strtotime( $localConfig->expire_date ) ) {
				$this->set_config( NULL, $localConfig->expire_date );
			}
		}

		$this->set_verify();
	}


	/**
	 * 
	 */
	public function get_shortcode($params) {

		$localConfig = $this->get_config();
		$a = shortcode_atts([
			'affiliate' => '', 
			'height'=> '800px',
			'width' => '100%',
			'default_token_address_eth' => '',
			'default_token_address_bsc' => '',
			'default_token_address_matic' => '',
			'default_token_address_avax' => '',
			'brand_color'=> '',
			'brand_color_dark' => '',
			'logo' => '',
			'logo_dark' => '',
			'name'=> '',
			'matic_as_default'=> '',
			'bsc_as_default' => '',
			'avax_as_default' => '',
			'is_dark_mode' => '',
			'default_slippage' => ''
		], $params);

		$agg = '<style>
							.dexkit-widget-iframe {
								height: '.$a['height'].';
								width: '.$a['width'].';
								border: 0;
							}
							</style>';
		$agg .= '<iframe name="dexkit-aggregator" src="' . esc_url( AGGREGATOR_BUILD ) . '" class="dexkit-widget-iframe"></iframe>';

		if (
			$localConfig->expire_date != NULL &&
			strtotime( current_time( 'mysql' ) ) > strtotime( $localConfig->expire_date )
		) {
			
		}
		else {
		
			$relayData['config'] = json_decode($localConfig->config);
			$relayData['owner'] = $localConfig->owner;
			$relayData['signature'] = $localConfig->signature;
			$relayData['message'] = $localConfig->message;
			$relayData['slug'] = $localConfig->slug;
			$relayData['createdAt'] = $localConfig->createdAt;		
		}
		wp_localize_script( 'setup-dexkit-agg-plugin', 'dexkit_aggregator', array(
			'data' => $relayData,
			'affiliate'=> $a['affiliate'],
			'default_token_address_eth' => $a['default_token_address_eth'],
			'default_token_address_bsc' => $a['default_token_address_bsc'],
			'default_token_address_matic' => $a['default_token_address_matic'],
			'default_token_address_avax' => $a['default_token_address_avax'],
			'brand_color' => $a['brand_color'],
			'brand_color_dark' => $a['brand_color_dark'],
			'logo' => $a['logo'],
			'logo_dark' => $a['logo_dark'],
			'name' => $a['name'],
			'matic_as_default' => $a['matic_as_default'],
			'bsc_as_default' => $a['bsc_as_default'],
			'avax_as_default' => $a['avax_as_default'],
			'is_dark_mode' => $a['is_dark_mode'],
			'default_slippage' => $a['default_slippage']
		));
		return $agg;
	}


	/**
	 * 
	 */
	public function load_app()
	{
   		/*$assets_files = $this->get_assets( AGGREGATOR_MANIFEST );

   		$js_files   = array_filter( $assets_files,  fn($file_string) => pathinfo( $file_string, PATHINFO_EXTENSION ) === 'js');
		$css_files  = array_filter( $assets_files,  fn($file_string) => pathinfo( $file_string, PATHINFO_EXTENSION ) === 'css');

		foreach ( $css_files as $index => $css_file ) {
			wp_enqueue_style( 'react-agg-plugin-' . $index, AGGREGATOR_BUILD . $css_file );
		}

		foreach ( $js_files as $index => $js_file ) {
			wp_enqueue_script( 'react-agg-plugin-' . $index, AGGREGATOR_BUILD . $js_file, array(), AGGREGATOR_VERSION, true );
		}*/

		wp_enqueue_script( 'setup-dexkit-agg-plugin', plugin_dir_url( __FILE__ ) . '/scripts/init.js', array(), AGGREGATOR_VERSION, true );
  }


	/**
	 * 
	 */
    private function get_assets()
	{
		// Request manifest file.
		$request = file_get_contents( AGGREGATOR_MANIFEST );

		// If the remote request fails.
		if ( !$request  )
			return false;

		// Convert json to php array.
		$files_data = json_decode( $request );
		if ( $files_data === null )
			return;

		// No entry points found.
		if ( ! property_exists( $files_data, 'entrypoints' ) )
			return false;

		return $files_data->entrypoints;
  }

	/**
	 * ADD MENU
	 */
	public function create_plugin_settings_page()
	{
		// Add the menu item and page
		$page_title = 'Dexkit AGGREGATOR Page';
		$menu_title = 'Dexkit AGGREGATOR';
		$capability = 'manage_options';
		$slug = 'dexkit_aggregator';
		$callback = array( $this, 'plugin_settings_page_content' );
		$icon = 'dashicons-admin-plugins';
		$position = 100;

		add_menu_page( $page_title, $menu_title, $capability, $slug, $callback, $icon, $position );
	}


	/**
	 * ADD HTML PAGE
	 */
	public function plugin_settings_page_content()
	{
		if( $_POST['updated'] === 'true' ) {
			$this->handle_form();
		}

		$localConfig = $this->get_config();
?>


		<div class="wrap">
			
			<h2>Dexkit AGGREGATOR</h2>
			<p style="text-align:justify">DEXKIT is changing the game of decentralized trading. The next-generation DeFi toolkit contains a full-suite decentralized exchange (DEX) that leverages powerful 0x (ZRX) technology allowing for multiple order types including ZERO GAS FEE placement of stop and limit orders. The exchange is powered by the underlying DEXSwap aggregator which gathers information from over 14 exchanges in search of the best price and liquidity for tokens. Collectors can launch their own customizable NFT marketplace where they can exchange crypto art, in-game assets, and any other ERC721 or 1155 token. The DEXKIT dashboard is the main control room where users can monitor statistics from all over the crypto markets, customize deployed DEXKIT tools, and perform swaps within the onboard multicurrency wallet.</p>
			<p style="text-align:justify">In order to costumize this plugin you need to use  shortcode attributes</p>
			<p><a href="https://github.com/DexKit/wordpress/blob/main/README.md">Documentation and Installation guides</a></p>
			<p><a href="https://dexkit.com">https://dexkit.com</a></p>
			<p><a href="https://t.me/dexkit">Telegram</a></p>


			<?php if ( $localConfig->api != NULL ) { ?>
			<hr>
			
			<h3>Sync</h3>
			<form method="POST">
				<?php wp_nonce_field( AGGREGATOR_TAG . '_sync', AGGREGATOR_TAG.'_form_sync' ); ?>
				<input type="hidden" name="updated" value="true">
				<table class="form-table">
					<tbody>
						<tr>
							<th><label>Last update: <?php echo human_time_diff( strtotime( $localConfig->verify_date ), strtotime( current_time( 'mysql' ) ) ); ?> ago</label></th>
							<td><input type="submit" name="submit" id="submit" class="button button-secondary" value="Fetch"></td>
						</tr>
					</tbody>
				</table>
			</form>
			<?php } ?>
			
		</div> 
<?php
	}


	/**
	 * SAVE API KEY & UPDATE CONFIG
	 */
	public function handle_form()
	{
		
		if( isset( $_POST[AGGREGATOR_TAG . '_form_api'] ) ) {

			if ( ! wp_verify_nonce( $_POST[AGGREGATOR_TAG . '_form_api'], AGGREGATOR_TAG . '_api' ) ) { ?>
				<div class="error">
					<p>Sorry, your nonce was not correct. Please try again.</p>
				</div> <?php
				exit;
			}

			$api = sanitize_text_field( $_POST['api'] );
			$this->set_api( $api );
			$this->fetch(); ?>
  
		 	<div class="updated">
			  <p>Your fields were saved!</p>
			</div> <?php
		}

		else if( isset( $_POST[AGGREGATOR_TAG . '_form_sync'] ) ) {

			if ( ! wp_verify_nonce( $_POST[AGGREGATOR_TAG . '_form_sync'], AGGREGATOR_TAG . '_sync' ) ) { ?>
				<div class="error">
					<p>Sorry, your nonce was not correct. Please try again.</p>
				</div> <?php
				exit;
			}

			$this->fetch();

		}

		else { ?>
			<div class="error">
			  <p>Sorry, your nonce was not correct. Please try again.</p>
			</div> <?php
		}

	}
	
};
