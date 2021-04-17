<?php
/**
 * Plugin Name:     Dexkit DEX
 * Plugin URI:      https://dexkit.com
 * Description:     DEXKIT PLUGIN
 * Author:          Dexkit DEX
 * Author URI:      https://dexkit.com
 * Text Domain:     dexkit-dex
 * Domain Path:     /languages
 * Version:         0.1.0
 *
 * @package         Dexkit-DEX
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


define( 'DEX_TAG', 'dexkit_dex' );
define( 'DEX_CRON', 'cron_dexkit_dex' );
define( 'DEX_VERSION', '0.1.0' );
define( 'DEX_API', 'https://' );
define( 'DEX_EXPIRE_DAYS', 7 );
define( 'DEX_BUILD', plugin_dir_url( __FILE__ ) . 'build/' );
define( 'DEX_MANIFEST', DEX_BUILD . 'asset-manifest.json' );


// register hooks
register_activation_hook( __FILE__, array( DexkitDEX::get_instance(), 'activate' ) );
register_deactivation_hook( __FILE__, array( DexkitDEX::get_instance(), 'deactivate' ) );

// register actions
// add_action( 'plugins_loaded', array( DexkitDEX::get_instance(), 'get_instance' ) );
add_action( 'init', 'dex_add_custom_shortcode' );
add_action( DEX_CRON , array( DexkitDEX::get_instance(), 'fetch'));
add_action( 'admin_menu', array( DexkitDEX::get_instance(), 'create_plugin_settings_page' ) );
add_action( 'wp_enqueue_scripts', array(DexkitDEX::get_instance(), 'load_app'), 45000 );
 
// register shortcodes
function dex_add_custom_shortcode() {
	add_shortcode( DEX_TAG, array( DexkitDEX::get_instance(), 'get_shortcode' ) );
}


class DexkitDEX
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
		$table = $wpdb->prefix . DEX_TAG;
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
		$wpdb->insert( $wpdb->prefix . DEX_TAG, array( 'config' => '{}' ) );

		// create cronjob
		$timestamp = wp_next_scheduled( DEX_CRON );
    if( $timestamp === false ){
      wp_schedule_event( time(), 'weekly', DEX_CRON );
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
		$table_name = $wpdb->prefix . DEX_TAG;
    $sql = "DROP TABLE IF EXISTS $table_name";
    $wpdb->query($sql);

		// remove cronjob
		$timestamp = wp_next_scheduled( DEX_CRON );
		wp_unschedule_event( $timestamp, DEX_CRON );
  }
	

	/**
	 * GET CONFIG FROM API
	 * { active: bool, config: string }
	 */
	private function get_config_from_api()
	{
		// global $wpdb;

		// $result = $wpdb->get_results( "SELECT * FROM " . $wpdb->prefix . DEX_TAG . " WHERE id = 1", OBJECT );

		// $request = wp_remote_get( DEX_API . '/' . $result[0]->api );

		// if( is_wp_error( $request ) ) {
		// 	return false; // Bail early
		// }

		// $body = wp_remote_retrieve_body( $request );
		// return json_encode( $body );
		return json_decode( '{ "active": true, "config": { "id": 123 } }' );
	}


	/**
	 * GET CONFIG FROM DATABASE
	 * { 	id: number, api: string, config: string, verify_date: Date, expire_date: Date }
	 */
	private function get_config()
	{
		global $wpdb;
		$result = $wpdb->get_results( "SELECT * FROM " . $wpdb->prefix . DEX_TAG . " WHERE id = 1", OBJECT );
		return $result[0];
	}


	/**
	 * SAVE CONFIG/EXPIRE ON DATABASE
	 */
	private function set_config( $config, $expire = NULL )
	{
		global $wpdb;

		$wpdb->update( $wpdb->prefix . DEX_TAG,
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

		$wpdb->update( $wpdb->prefix . DEX_TAG,
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

		$wpdb->update( $wpdb->prefix . DEX_TAG,
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
			$expire = date( 'Y-m-d H:i:s', strtotime( $now ) + (86400 * DEX_EXPIRE_DAYS) );

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

		if (
			$localConfig->expire_date != NULL &&
			strtotime( current_time( 'mysql' ) ) > strtotime( $localConfig->expire_date )
		) {
			echo 'marketplace expired';
		}
		else {
			$a = shortcode_atts(['block' => 'false', 'selector' => 'page'], $params);
	
			if ( $a['block'] == "true") {
				// Variables for app use - These variables will be available in window.rpReactPlugin variable.
				echo '<div id="' . $a['selector'] . '" style="width: 100%; height: 100%;"></div>';		
			}

			$relayData['config'] = "{\"theme\":{\"general\":{\"title\":\"MARKETPLACE\"},\"componentsTheme\":{\"background\":\"#F4F7FE\",\"backgroundERC721\":\"#F4F7FE\"}}}"; // $localConfig->config
			$relayData['owner'] = '';
			$relayData['signature'] = '';
			$relayData['message'] = '';
			$relayData['slug'] = '';
			$relayData['createdAt'] = '';

			wp_localize_script( 'react-plugin-1', 'dexkit_dex', array(
				'selector' => '#' . $a['selector'],
				'data' => $relayData
			));
		}

	}


	/**
	 * 
	 */
	public function load_app() {
    $assets_files = $this->get_assets( DEX_MANIFEST );

    $js_files   = array_filter( $assets_files,  fn($file_string) => pathinfo( $file_string, PATHINFO_EXTENSION ) === 'js');
		$css_files  = array_filter( $assets_files,  fn($file_string) => pathinfo( $file_string, PATHINFO_EXTENSION ) === 'css');

		foreach ( $css_files as $index => $css_file ) {
			wp_enqueue_style( 'react-plugin-' . $index, DEX_BUILD . $css_file );
		}

		foreach ( $js_files as $index => $js_file ) {
			wp_enqueue_script( 'react-plugin-' . $index, DEX_BUILD . $js_file, array(), DEX_VERSION, true );
		}
  }


	/**
	 * 
	 */
  private function get_assets()
	{
		// Request manifest file.
		$request = file_get_contents( DEX_MANIFEST );

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
		$page_title = 'Dexkit DEX Page';
		$menu_title = 'Dexkit DEX';
		$capability = 'manage_options';
		$slug = 'dexkit_dex';
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
			
			<h2>Dexkit DEX</h2>
			<p style="text-align:justify">DEXKIT is changing the game of decentralized trading. The next-generation DeFi toolkit contains a full-suite decentralized exchange (DEX) that leverages powerful 0x (ZRX) technology allowing for multiple order types including ZERO GAS FEE placement of stop and limit orders. The exchange is powered by the underlying DEXSwap aggregator which gathers information from over 14 exchanges in search of the best price and liquidity for tokens. Collectors can launch their own customizable DEX marketplace where they can exchange crypto art, in-game assets, and any other ERC721 or 1155 token. The DEXKIT dashboard is the main control room where users can monitor statistics from all over the crypto markets, customize deployed DEXKIT tools, and perform swaps within the onboard multicurrency wallet.</p>
			<p><a href="https://dexkit.com">https://dexkit.com</a></p>


			<?php if ( $localConfig->api != NULL ) { ?>
			<hr>
			
			<h3>Sync</h3>
			<form method="POST">
				<?php wp_nonce_field( DEX_TAG . '_sync', DEX_TAG.'_form_sync' ); ?>
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
			
			<hr>

			<h3>Settings</h3>
			<form method="POST">
				<?php wp_nonce_field( DEX_TAG . '_api', DEX_TAG . '_form_api' ); ?>
				<input type="hidden" name="updated" value="true">

				<?php if ( $localConfig->expire_date != NULL && strtotime( current_time( 'mysql' ) ) > strtotime( $localConfig->expire_date ) ) { ?>
					<div style="color: red"><label>Your key has expired in <?php echo human_time_diff(strtotime( $localConfig->expire_date ), strtotime( current_time( 'mysql' ) ) ); ?> ago</div>
				<?php } else if ( $localConfig->expire_date != NULL ) { ?>
					<div style="color: red"><label>Your API Key will expire in <?php echo human_time_diff( strtotime( current_time( 'mysql' ) ), strtotime( $localConfig->expire_date ) ); ?></div>
				<?php } ?>

				<table class="form-table">
					<tbody>
						<tr>
							<th><label for="api">API Key</label></th>
							<td><input name="api" id="api" type="text" value="<?php echo $localConfig->api ?>" class="regular-text" /></td>
						</tr>
					</tbody>
				</table>
				<p class="submit">
					<input type="submit" name="submit" id="submit" class="button button-primary" value="Save">
				</p>
			</form>
			
		</div> 
<?php
	}


	/**
	 * SAVE API KEY & UPDATE CONFIG
	 */
	public function handle_form()
	{
		
		if( isset( $_POST[DEX_TAG . '_form_api'] ) ) {

			if ( ! wp_verify_nonce( $_POST[DEX_TAG . '_form_api'], DEX_TAG . '_api' ) ) { ?>
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

		else if( isset( $_POST[DEX_TAG . '_form_sync'] ) ) {

			if ( ! wp_verify_nonce( $_POST[DEX_TAG . '_form_sync'], DEX_TAG . '_sync' ) ) { ?>
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
