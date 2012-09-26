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
	
	public function __construct()
	{
		$this->goauth_url = 'https://accounts.google.com/o/oauth2/auth';
		$this->gclient_id = get_option('gapp_client_id');
		$this->gredirect = get_option('gapp_client_redirect');
		$this->token = get_option( 'goauth_token' )
		$this->hooks();
	}
	
	public function hooks()
	{
		add_action( 'init', array( $this, 'rewrite' ) );
		add_action( 'admin_menu', array( $this, 'register_admin' ) );
		add_action( 'admin_init', array( $this, 'save' ) );
		add_action( 'template_redirect', array( $this, 'store_access_token' ) );
		add_filter( 'query_vars', array( $this, 'qv' ) );
	}

	public function qv( $qv )
	{
		$qv[] = 'gappauth';
		return $qv;
	}
	
	public function js()
	{
		
	}
	
	public function css()
	{
		
	}
	
	public function register_admin()
	{
		// TODO: Multisite Compat
		add_plugins_page( __( 'Google Analyticsa Popular Posts', 'gapp' ), __( 'GA Popular Posts', 'gapp'), 'administrator', 'gapp-options', array( $this, 'admin' ) );
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
					echo get_option( 'goauth_token' );
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
		$url = $this->goauth_url . '?response_type=code&client_id=' . $this->gclient_id . '&redirect_uri=' . $this->gredirect . '&scope=https://www.googleapis.com/auth/userinfo.email&state=' .  home_url('') . $_SERVER['REQUEST_URI'];
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
				$redirect_url = $f[1];

			if( $f[0] == 'code' )
				$token = $f[1];
		}
		update_option( 'goauth_token', $token );
		wp_redirect( urldecode($redirect_url) );
		
		//echo'<pre>';print_r($parts);echo'</pre>';
		exit;
	}

	public function rewrite()
	{
		global $wp_rewrite;
		add_rewrite_endpoint( 'gappauth', EP_PERMALINK );
		$wp_rewrite->flush_rules();
	}
}

$gapp = new Google_Analytics_Popular;