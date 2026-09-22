<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Consent log: a record of every Accept / Decline, kept in the site's own database.
 *
 * WHAT THE LAW ACTUALLY ASKS FOR
 * No US law requires cookie-consent logging at all. GDPR Article 7(1) requires a controller to
 * be ABLE TO DEMONSTRATE consent, but every regulator that has spelled out what that means
 * describes system-level evidence, not per-visitor personal data:
 *   - EDPB Guidelines 05/2020 para 106: the duty to demonstrate consent "should not in itself
 *     lead to excessive amounts of additional data processing".
 *   - The German DSK guidance para 84: proving consent requires no long-lived unique-ID cookies,
 *     and the result should be stored "without a UID or other excessive information".
 *   - CNIL's four recommended proof methods are all system-level: a published hash of the
 *     consent code, timestamped screenshots per banner version, third-party audits, and
 *     timestamped retention of the banner's successive configurations.
 *   - The ICO's own bad example of a consent record is one keyed to an IP address; its good
 *     example uses an ID plus a timestamp plus the version of the form in use at the time.
 * So the useful record is the BANNER VERSION HISTORY, which this class also keeps, and a
 * minimal per-decision row.
 *
 * WHAT IS DELIBERATELY NOT STORED
 * No IP address by default. No user agent, which is a high-entropy fingerprinting vector that
 * adds nothing to proof of consent. No page URL by default: on a healthcare site, pairing a
 * visitor identifier with a timestamped visit to a specific condition page is the exact artifact
 * the 2022-2024 tracking-technology dispute was about, and building it in would manufacture
 * evidence for a claim a client would otherwise not face.
 *
 * The row is therefore a random consent ID, a timestamp, the choices made, how they were made,
 * and the policy version. That matches ISO/IEC TS 27560:2023, which has no IP field either.
 */
class CBXCC_Log {

	const TABLE     = 'cbxcc_consent_log';
	const CRON      = 'cbxcc_prune_log';
	const DB_OPTION = 'cbxcc_db_version';
	const DB_VERSION = 2;

	public function __construct() {
		add_action( 'admin_init', array( $this, 'maybe_upgrade' ) );
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
			method varchar(16) NOT NULL DEFAULT '',
			page varchar(255) NOT NULL DEFAULT '',
			ip varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			UNIQUE KEY consent_id (consent_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );
		update_option( self::DB_OPTION, self::DB_VERSION, false );
	}

	/**
	 * Bring an existing table up to the current shape. dbDelta adds new columns but never drops
	 * old ones, so the user_agent column retired in 1.2.0 is dropped explicitly along with the
	 * data in it.
	 */
	public function maybe_upgrade() {
		if ( (int) get_option( self::DB_OPTION, 0 ) >= self::DB_VERSION ) {
			return;
		}

		self::create_table();

		global $wpdb;
		$table = self::table();
		$cols  = $wpdb->get_col( "DESC {$table}", 0 ); // phpcs:ignore
		if ( is_array( $cols ) && in_array( 'user_agent', $cols, true ) ) {
			$wpdb->query( "ALTER TABLE {$table} DROP COLUMN user_agent" ); // phpcs:ignore
		}

		update_option( self::DB_OPTION, self::DB_VERSION, false );
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
				'method'         => in_array( $req->get_param( 'method' ), array( 'accept_all', 'reject_all', 'custom' ), true )
					? $req->get_param( 'method' ) : '',
				'page'           => cbxcc_get( 'log_page' )
					? substr( esc_url_raw( (string) $req->get_param( 'url' ) ), 0, 255 ) : '',
				'ip'             => $this->ip_to_store(),
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
		fputcsv( $out, array( 'id', 'consent_id', 'created_at_utc', 'analytics', 'marketing', 'policy_version', 'region', 'method', 'page', 'ip' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, $r );
		}
		fclose( $out );
		exit;
	}

	/**
	 * Banner version history. CNIL and the German DSK both name "the banner's successive
	 * configurations, retained with a timestamp" as proof of consent. This snapshots the wording
	 * and design every time they change, which is the evidence that actually matters and costs
	 * no visitor privacy at all.
	 */
	const SNAPSHOTS = 'cbxcc_banner_versions';

	public static function snapshot_if_changed( $settings ) {
		$watch = array(
			'title', 'body', 'accept_label', 'reject_label', 'prefs_label', 'save_label',
			'policy_url', 'policy_label', 'cat_necessary', 'cat_necessary_desc',
			'cat_analytics', 'cat_analytics_desc', 'cat_marketing', 'cat_marketing_desc',
			'position', 'bg', 'fg', 'muted', 'border', 'accept_bg', 'accept_fg',
			'reject_bg', 'reject_fg', 'default_outside_eu', 'policy_version',
		);

		$now = array();
		foreach ( $watch as $k ) {
			$now[ $k ] = isset( $settings[ $k ] ) ? $settings[ $k ] : '';
		}

		$history = get_option( self::SNAPSHOTS, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$last = end( $history );
		if ( $last && isset( $last['config'] ) && $last['config'] === $now ) {
			return; // Nothing meaningful changed.
		}

		$history[] = array(
			'saved_at' => current_time( 'mysql', true ),
			'by'       => wp_get_current_user() ? wp_get_current_user()->user_login : '',
			'config'   => $now,
		);

		// Keep the last 50 versions; older ones are rarely useful and the option should stay small.
		if ( count( $history ) > 50 ) {
			$history = array_slice( $history, -50 );
		}

		update_option( self::SNAPSHOTS, $history, false );
	}

	public static function snapshots() {
		$h = get_option( self::SNAPSHOTS, array() );
		return is_array( $h ) ? array_reverse( $h ) : array();
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
