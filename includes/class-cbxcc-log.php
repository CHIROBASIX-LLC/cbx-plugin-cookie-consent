<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent log: a record of every Accept / Decline, kept in the site's own database.
 *
 * Nothing in US law requires this for a marketing website. It exists so the site owner can
 * answer "prove this visitor agreed" if a funder, an insurer or a regulator ever asks.
 *
 * By design the default records NO IP address. An IP is itself personal data, so storing one to
 * prove that somebody declined tracking works against the point. The record is a random consent
 * ID, a timestamp, the choices made, the policy version and the page. That is the pattern the
 * mainstream consent tools follow, and it is enough to evidence a decision without creating a
 * new pile of personal data. IP capture can be switched on (full or anonymised) for a site that
 * genuinely needs it.
 */
class CBXCC_Log {

	const TABLE  = 'cbxcc_consent_log';
	const CRON   = 'cbxcc_prune_log';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_route' ) );
		add_action( self::CRON, array( $this, 'prune' ) );
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_action( 'admin_init', array( $this, 'maybe_export_csv' ) );
		add_action( 'admin_init', array( $this, 'maybe_clear_log' ) );
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			consent_id varchar(64) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			analytics tinyint(1) NOT NULL DEFAULT 0,
			marketing tinyint(1) NOT NULL DEFAULT 0,
			policy_version varchar(16) NOT NULL DEFAULT '',
			region varchar(8) NOT NULL DEFAULT '',
			page varchar(255) NOT NULL DEFAULT '',
			ip varchar(64) NOT NULL DEFAULT '',
			user_agent varchar(255) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY consent_id (consent_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
	}

	public function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	public function register_route() {
		register_rest_route(
			'cbx-consent/v1',
			'/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Public by necessity: visitors are logged out.
			)
		);
	}

	/**
	 * Store one consent decision. Deliberately forgiving: this endpoint must never be able to
	 * stop a visitor's choice being honoured, because the choice is already applied in their
	 * browser by the time this runs.
	 */
	public function handle( WP_REST_Request $req ) {
		if ( ! cbxcc_get( 'logging' ) ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'disabled' ), 200 );
		}

		// Cheap abuse guard. Keyed on a hash so no raw IP is retained even transiently.
		$bucket = 'cbxcc_rl_' . substr( md5( $this->raw_ip() . gmdate( 'YmdH' ) ), 0, 20 );
		$hits   = (int) get_transient( $bucket );
		if ( $hits > 30 ) {
			return new WP_REST_Response( array( 'ok' => false, 'reason' => 'rate' ), 429 );
		}
		set_transient( $bucket, $hits + 1, HOUR_IN_SECONDS );

		$consent_id = sanitize_text_field( (string) $req->get_param( 'id' ) );
		if ( '' === $consent_id || strlen( $consent_id ) > 64 ) {
			$consent_id = wp_generate_uuid4();
		}

		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'consent_id'     => $consent_id,
				'created_at'     => current_time( 'mysql', true ),
				'analytics'      => $req->get_param( 'analytics' ) ? 1 : 0,
				'marketing'      => $req->get_param( 'marketing' ) ? 1 : 0,
				'policy_version' => substr( sanitize_text_field( (string) $req->get_param( 'version' ) ), 0, 16 ),
				'region'         => substr( sanitize_text_field( (string) $req->get_param( 'region' ) ), 0, 8 ),
				'page'           => substr( esc_url_raw( (string) $req->get_param( 'url' ) ), 0, 255 ),
				'ip'             => $this->ip_to_store(),
				'user_agent'     => substr( sanitize_text_field( (string) $req->get_header( 'user_agent' ) ), 0, 255 ),
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' )
		);

		return new WP_REST_Response( array( 'ok' => true ), 201 );
	}

	private function raw_ip() {
		// WP Engine and Cloudflare both front the origin, so the socket address is a proxy.
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ) as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}
			$val = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$val = trim( explode( ',', $val )[0] );
			if ( filter_var( $val, FILTER_VALIDATE_IP ) ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * 'none'       store nothing (default, and the right answer for almost every site)
	 * 'anonymised' drop the last octet of IPv4 / the last 80 bits of IPv6, the same truncation
	 *              Google Analytics uses, so a record cannot be tied back to one household
	 * 'full'       store it verbatim. Only for a site that has been told to.
	 */
	private function ip_to_store() {
		$mode = cbxcc_get( 'log_ip' );
		if ( 'none' === $mode || '' === $mode ) {
			return '';
		}

		$ip = $this->raw_ip();
		if ( '' === $ip ) {
			return '';
		}
		if ( 'full' === $mode ) {
			return $ip;
		}

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$p = explode( '.', $ip );
			return $p[0] . '.' . $p[1] . '.' . $p[2] . '.0';
		}

		$p = explode( ':', $ip );
		return implode( ':', array_slice( $p, 0, 3 ) ) . '::';
	}

	public function prune() {
		$days = (int) cbxcc_get( 'log_retention_days' );
		if ( $days < 1 ) {
			return;
		}
		global $wpdb;
		$table  = self::table();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) ); // phpcs:ignore
	}

	public static function count() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore
	}

	public static function recent( $limit = 20 ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", $limit ) ); // phpcs:ignore
	}

	public function maybe_export_csv() {
		if ( ! isset( $_GET['cbxcc_export'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'cbxcc_export' );

		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A ); // phpcs:ignore

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=consent-log-' . gmdate( 'Y-m-d' ) . '.csv' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'id', 'consent_id', 'created_at_utc', 'analytics', 'marketing', 'policy_version', 'region', 'page', 'ip', 'user_agent' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		exit;
	}

	public function maybe_clear_log() {
		if ( ! isset( $_GET['cbxcc_clear'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'cbxcc_clear' );

		global $wpdb;
		$table = self::table();
		$wpdb->query( "TRUNCATE TABLE {$table}" ); // phpcs:ignore

		wp_safe_redirect( admin_url( 'options-general.php?page=cbx-cookie-consent&tab=log&cleared=1' ) );
		exit;
	}
}
