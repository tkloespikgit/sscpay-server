<?php
/**
 * SSCPay 插件更新服务客户端。只请求配置的订单系统，不发送商户密钥。
 *
 * @package SSCPay_Paypal
 */

defined( 'ABSPATH' ) || exit;

final class SSCPay_Paypal_Updater {
	/** @var array */
	private $config;
	/** @var string */
	private $cache_key;

	public function __construct( array $config ) {
		$this->config = $config;
		$this->config['base_url'] = rtrim( $config['base_url'], '/' );
		$this->cache_key = 'sscpay_paypal_update_' . md5( $this->config['base_url'] );
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( $this, 'download_package' ), 10, 3 );
		add_filter( 'plugin_action_links_' . $config['basename'], array( $this, 'action_links' ) );
		add_action( 'admin_post_sscpay_paypal_check_update', array( $this, 'manual_check' ) );
	}

	/** @return bool */
	private function same_origin( $url ) {
		$base = wp_parse_url( $this->config['base_url'] );
		$target = wp_parse_url( $url );
		return is_array( $base ) && is_array( $target ) && empty( $target['user'] ) && empty( $target['pass'] )
			&& 'https' === ( $base['scheme'] ?? '' ) && 'https' === ( $target['scheme'] ?? '' )
			&& ! empty( $base['host'] ) && ( $base['host'] === ( $target['host'] ?? '' ) )
			&& ( $base['port'] ?? 443 ) === ( $target['port'] ?? 443 );
	}

	/** @return array|false */
	public function metadata( $force = false ) {
		if ( ! $this->same_origin( $this->config['base_url'] ) ) {
			return false;
		}
		if ( ! $force ) {
			$cached = get_site_transient( $this->cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
			if ( 'unavailable' === $cached ) {
				return false;
			}
		}
		$response = wp_remote_get( $this->config['base_url'] . '/plugins/updates/v1/' . rawurlencode( $this->config['slug'] ), array(
			'timeout' => 15, 'redirection' => 0, 'limit_response_size' => 131072,
			'headers' => array( 'Accept' => 'application/json' ),
		) );
		$data = ! is_wp_error( $response ) && 200 === (int) wp_remote_retrieve_response_code( $response )
			? json_decode( wp_remote_retrieve_body( $response ), true ) : null;
		if ( ! is_array( $data ) || ( $data['slug'] ?? '' ) !== $this->config['slug']
			|| ! is_string( $data['version'] ?? null ) || ! is_string( $data['sha256'] ?? null ) || ! is_string( $data['download_url'] ?? null )
			|| ! preg_match( '/^[0-9]+\.[0-9]+\.[0-9]+$/D', $data['version'] ?? '' )
			|| ! preg_match( '/^[a-f0-9]{64}$/D', $data['sha256'] ?? '' )
			|| ! is_int( $data['size'] ?? null ) || $data['size'] < 1 || $data['size'] > 20971520
			|| ! $this->same_origin( $data['download_url'] ?? '' ) ) {
			set_site_transient( $this->cache_key, 'unavailable', 15 * MINUTE_IN_SECONDS );
			return false;
		}
		set_site_transient( $this->cache_key, $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	/** @return object */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$data = $this->metadata();
		if ( ! $data ) {
			return $transient;
		}
		$item = (object) array(
			'id' => $this->config['slug'], 'slug' => $this->config['slug'], 'plugin' => $this->config['basename'],
			'new_version' => $data['version'], 'url' => $data['homepage'] ?? $this->config['base_url'] . '/plugins',
			'package' => $data['download_url'], 'requires' => $data['requires'] ?? '6.0',
			'requires_php' => $data['requires_php'] ?? '7.4', 'tested' => $data['tested'] ?? '',
		);
		$bucket = version_compare( $data['version'], $this->config['version'], '>' ) ? 'response' : 'no_update';
		$other = 'response' === $bucket ? 'no_update' : 'response';
		if ( ! isset( $transient->$bucket ) ) {
			$transient->$bucket = array();
		}
		$transient->{$bucket}[ $this->config['basename'] ] = $item;
		unset( $transient->{$other}[ $this->config['basename'] ] );
		return $transient;
	}

	/** @return object|mixed */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ( $args->slug ?? '' ) !== $this->config['slug'] ) {
			return $result;
		}
		$data = $this->metadata();
		if ( ! $data ) {
			return $result;
		}
		return (object) array(
			'name' => $data['name'], 'slug' => $data['slug'], 'version' => $data['version'],
			'author' => 'SSCPay', 'requires' => $data['requires'] ?? '6.0', 'requires_php' => $data['requires_php'] ?? '7.4',
			'last_updated' => $data['published_at'] ?? '', 'download_link' => $data['download_url'],
			'sections' => array( 'description' => esc_html( $data['description'] ?? '' ), 'changelog' => nl2br( esc_html( $data['changelog'] ?? '' ) ) ),
		);
	}

	/** @return string|WP_Error|mixed */
	public function download_package( $reply, $package, $upgrader = null ) {
		$data = $this->metadata();
		if ( ! $data || $package !== $data['download_url'] ) {
			return $reply;
		}
		$file = wp_tempnam( $this->config['slug'] . '.zip' );
		if ( ! $file ) {
			return new WP_Error( 'sscpay_update_temp', 'Cannot create temporary update file.' );
		}
		$response = wp_remote_get( $package, array(
			'timeout' => 300, 'redirection' => 0, 'stream' => true, 'filename' => $file,
			'limit_response_size' => $data['size'] + 1,
		) );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response )
			|| filesize( $file ) !== $data['size'] || ! hash_equals( $data['sha256'], hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'sscpay_update_integrity', 'Plugin download failed integrity verification.' );
		}
		return $file;
	}

	/** @return array */
	public function action_links( $links ) {
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=sscpay_paypal_check_update' ), 'sscpay_paypal_check_update' ) ) . '">' . esc_html__( '立即检查更新', 'sscpay-payment-paypal' ) . '</a>';
		return $links;
	}

	public function manual_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( '没有更新插件的权限。', 'sscpay-payment-paypal' ) );
		}
		check_admin_referer( 'sscpay_paypal_check_update' );
		$this->metadata( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		wp_safe_redirect( network_admin_url( 'plugins.php' ) );
		exit;
	}
}
