<?php
/*
Plugin Name: Google Analytics Popular Posts
Author: Aaron Brazell
Version: 1.0a
Description: Uses Google Analytics to determine popular posts. The real benefit is offloading expensive processing from the database and WordPress to an external service.
*/

class Google_Analytics_Popular {

	public $goauth_url;
	public $gclient_id;
	public $gredirect;
	public $token;
	public $client_secret;

	public $gapi_url;
	
	public function __construct()
	{
		$this->goauth_url = 'https://accounts.google.com/o/oauth2/auth';
		$this->gclient_id = get_option('gapp_client_id');
		$this->gredirect = get_option('gapp_client_redirect');
		$this->token = get_option( 'goauth_token' );
		$this->client_secret = get_option('gapp_client_secret');

		$this->gapi_url = 'https://www.googleapis.com/analytics/v3';
		$this->hooks();
	}
	
	public function hooks()
	{
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'admin_menu', array( $this, 'register_admin' ) );
		add_action( 'admin_init', array( $this, 'save' ) );
		add_action( 'template_redirect', array( $this, 'store_access_token' ) );
		add_filter( 'query_vars', array( $this, 'qv' ) );
		add_action( 'admin_head', array( $this, 'js' ) );
		add_action( 'admin_head', array( $this, 'css' ) );

		// AJAX
		add_action( 'wp_ajax_update_ga_account', array( $this, 'update_ga_account' ) );
	}

	public function qv( $qv )
	{
		$qv[] = 'gappauth';
		return $qv;
	}
	
	public function js()
	{
		?>
		<script>
		jQuery(document).ready(function(){
			jQuery('#ga_account').change(function(){
				jQuery('#ga-account-select-spinner').show().css( 'display', 'inline');
				var val = jQuery(this).val();
				var _ga_acct_nonce = '<?php echo wp_create_nonce("_ga_acct_nonce") ?>';

				var ajax_data = {
					action 			: "update_ga_account",
					ga_account 		: val,
					_ga_acct_nonce 	: _ga_acct_nonce,
				}

				jQuery.post( ajaxurl, ajax_data, function(data) {
					//console.log('foo');
					if( data == "true" )
					{
						jQuery('#ga-account-select-spinner').fadeOut(600).html('<span class="ajax_success"><?php _e( "Saved.", "gapp" ) ?></span>' );
					}
					else
						jQuery('#ga-account-select-spinner').fadeOut(600).html('<span class="ajax_error"><?php _e( "Save Failed.", "gapp" ) ?></span>' );
					console.debug(data);
				});
			});
		});
		</script>
		<?php
	}
	
	public function css()
	{
		?>
		<style>
		#ga-account-select-spinner {
			display:none;
			font-weight:bold;
			margin-left:10px;
		}
		#ga-account-select-spinner img { height: 20px; width: 20px; vertical-align:middle; }
		.ajax_error { color: #f00; }
		.ajax_success { color: #107802;}
		</style>
		<?php
	}
	
	public function register_admin()
	{
		// TODO: Multisite Compat
		add_plugins_page( __( 'Google Analytics Popular Posts', 'gapp' ), __( 'GA Popular Posts', 'gapp'), 'administrator', 'gapp-options', array( $this, 'admin' ) );
	}
	
	public function admin()
	{
		?>
		<div class="wrap">
			<h2><?php _e( 'Google Analytics Popular Posts', 'gapp' ) ?></h2>
			<form action="" method="post">
				<input type="hidden" name="_wpnonce_gapp" value="<?php echo wp_create_nonce('wp_nonce_gapp') ?>" />
				<p>
					<label for="gclient_id">
						<input type="text" id="gclient_id" name="gclient_id" value="<?php echo get_option('gapp_client_id') ?>"required> Client ID
					</label>
				</p>
				<p>
					<label for="gclient_secret">
						<input type="text" id="gclient_secret" name="gclient_secret" value="<?php echo get_option('gapp_client_secret') ?>"required> Client Secret
					</label>
				</p>
				<p>
					<label for="gauth_redirect">
						<input type="text" id="gauth_redirect" name="gauth_redirect" value="<?php echo get_option('gapp_client_redirect') ?>"required> Redirect URL
					</label>
				</p>
				<p class="submit">
					<input type="submit" name="check" value="Save" />
				</p>
			</form>

			<?php
			if( get_option( 'gapp_client_secret') && get_option( 'gapp_client_id') && get_option( 'gapp_client_redirect' ) )
			{
				if( get_option( 'goauth_token' ) )
				{
					//echo get_option( 'goauth_token' );
					?>
					<h2><?php _e( 'Choose Account to Use', 'gapp' ) ?></h2>
					<form action="" method="post">
						<select name="ga_account" id="ga_account">
					<?php
					$accounts = $this->get_accounts();
					$active_account = get_option( 'ga_active_account' );
					foreach( $accounts->items as $ga_account )
					{
						if( $ga_account->id == $active_account )
							echo '<option selected value="' . esc_attr( $ga_account->id ) . '">' . esc_html( $ga_account->name . ' (UA-' . $ga_account->id . '-XX)' );
						else
							echo '<option value="' . esc_attr( $ga_account->id ) . '">' . esc_html( $ga_account->name . ' (UA-' . $ga_account->id . '-XX)' );
					}
					?>
						</select>
						<p id="ga-account-select-spinner"><img src="<?php echo plugins_url( 'images/spinner.gif', __FILE__ ); ?>" /></p>
					</form>
					<?php
				}
				else
				{
					?>
					<a class="button button-primary" href="<?php echo $this->_oauth_access_token() ?>">Authorize</a>
					<?php
				}
			}
			?>
		</div>
		<?php
	}

	public function save()
	{
		if( !wp_verify_nonce( $_POST['_wpnonce_gapp'], 'wp_nonce_gapp' ) )
			return false;

		update_option( 'gapp_client_secret', $_POST['gclient_secret'] );
		update_option( 'gapp_client_id', $_POST['gclient_id'] );
		update_option( 'gapp_client_redirect', $_POST['gauth_redirect'] );
	}

	
	private function _oauth_access_token()
	{
		if( !$this->gclient_id )
			return false;

		global $wp;
		$url = $this->goauth_url . '?response_type=code&approval_type=auto&client_id=' . $this->gclient_id . '&redirect_uri=' . $this->gredirect . '&scope=https://www.googleapis.com/auth/analytics.readonly+https://www.googleapis.com/auth/userinfo.email&access_type=offline&state=' .  home_url('') . $_SERVER['REQUEST_URI'];
		$url = esc_url_raw( $url );
		return $url;
	}

	public function store_access_token()
	{
		global $wp;
		if( !array_search( 'gappauth', $wp->query_vars ) )
			return false;

		$this_url = home_url('') . $_SERVER['REQUEST_URI'];
		$b = parse_url( $this_url );
		$parts = explode( '&', $b['query'] );
		
		foreach( $parts as $part )
		{
			$f = explode( '=', $part );
			if( $f[0] == 'state' )
				$redirect_url = urldecode( $f[1] );

			if( $f[0] == 'code' )
				$token = urldecode( $f[1] );
		}

		$args = array(
					'code'			=> $token,
					'client_id'		=> $this->gclient_id,
					'client_secret'	=> $this->client_secret,
					'redirect_uri'	=> $this->gredirect,
					'grant_type'	=> 'authorization_code',
				);

		$response = wp_remote_post( 'https://accounts.google.com/o/oauth2/token', array( 
			'body' => $args
			)
		);
		$json = wp_remote_retrieve_body( $response );
		$json = json_decode( $json );
		update_option( 'goauth_token', $json->access_token );
		wp_redirect( urldecode($redirect_url) );		
		exit;
	}

	public function rewrite()
	{
		global $wp_rewrite;
		add_rewrite_endpoint( 'gappauth', EP_PERMALINK );
		$wp_rewrite->flush_rules();
	}

	public function get_accounts()
	{
		$url = $this->gapi_url . '/management/accounts';
		$url = esc_url_raw( $url );
		$response = wp_remote_get( $url, array( 'headers' => array( 'Authorization' => 'OAuth ' . $this->token) ) );
		$json = json_decode( wp_remote_retrieve_body( $response ) );
		return $json;
	}

	/* AJAX HANDLERS */
	public function update_ga_account()
	{
		if( update_option( 'ga_active_account', $_POST['ga_account'] ) )
			echo "true";
		else
			echo "false";
		exit;
	}
}

$gapp = new Google_Analytics_Popular;