<?php
/**
 * Proof-of-consent log.
 *
 * GDPR Art. 7(1) puts the burden of demonstrating consent on the controller, so
 * we store a minimal, pseudonymised record of every consent action: what was
 * accepted, under which policy revision, when, and from which (hashed) IP.
 *
 * @package Archer_Cookie_Consent
 */

defined( 'ABSPATH' ) || exit;

/**
 * Consent log storage and REST endpoint.
 */
class Archer_CC_Consent_Log {

	const REST_NAMESPACE = 'archer-cookie-consent/v1';
	const CRON_HOOK      = 'archer_cc_purge_consent_log';

	/**
	 * Fully qualified table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;

		return $wpdb->prefix . 'archer_cc_consent_log';
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ), 10, 0 );
		add_action( self::CRON_HOOK, array( $this, 'purge_expired' ), 10, 0 );
	}

	/**
	 * Create or upgrade the log table.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = self::table();
		$collate = $wpdb->get_charset_collate();

		// PERF: consent_id is indexed so repeat actions from one visitor are cheap
		// to look up; created_at is indexed for the retention purge.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			consent_id CHAR(36) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL,
			action VARCHAR(20) NOT NULL DEFAULT '',
			accepted_categories VARCHAR(255) NOT NULL DEFAULT '',
			rejected_categories VARCHAR(255) NOT NULL DEFAULT '',
			revision SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			language VARCHAR(10) NOT NULL DEFAULT '',
			page_url VARCHAR(255) NOT NULL DEFAULT '',
			ip_hash CHAR(64) NOT NULL DEFAULT '',
			user_agent VARCHAR(255) NOT NULL DEFAULT '',
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY consent_id (consent_id),
			KEY created_at (created_at)
		) {$collate};";

		dbDelta( $sql );

		update_option( 'archer_cc_db_version', ARCHER_CC_DB_VERSION, false );
	}

	/**
	 * Drop the log table (uninstall only).
	 *
	 * @return void
	 */
	public static function drop_table() {
		global $wpdb;

		$table = self::table();

		// SECURITY: the table name is built from $wpdb->prefix and a literal, never
		// from input, and identifiers cannot be bound via prepare().
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Rotating, cache-safe token that authorises the public log endpoint.
	 *
	 * A per-user nonce cannot be used here: consent is given by anonymous
	 * visitors on pages that are usually served from a full-page cache, where a
	 * per-session nonce would be baked into the cached HTML and expire. This
	 * token is not an identity check — it only proves the request came from a
	 * page this site rendered recently, which together with the strict payload
	 * validation and per-IP rate limit is what keeps the endpoint from being
	 * useful to an attacker. Logged-in requests are additionally nonce-checked
	 * by the REST infrastructure itself.
	 *
	 * @param int $offset Number of days to shift (used to accept yesterday's token).
	 * @return string
	 */
	public static function public_token( $offset = 0 ) {
		$day = (int) floor( time() / DAY_IN_SECONDS ) - (int) $offset;

		return wp_hash( 'archer-cc-consent-log|' . $day );
	}

	/**
	 * Register the REST route.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/consent',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_consent' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'token'      => array(
						'required' => true,
						'type'     => 'string',
					),
					'consent_id' => array(
						'required' => true,
						'type'     => 'string',
					),
					'action'     => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'first_consent', 'change' ),
					),
					'accepted'   => array(
						'required' => true,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
					),
					'rejected'   => array(
						'required' => false,
						'type'     => 'array',
						'items'    => array( 'type' => 'string' ),
					),
					'revision'   => array(
						'required' => false,
						'type'     => 'integer',
					),
					'language'   => array(
						'required' => false,
						'type'     => 'string',
					),
					'url'        => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: token match plus a per-IP rate limit.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return true|WP_Error
	 */
	public function check_permission( $request ) {
		if ( ! Archer_CC_Settings::get( 'log_enabled' ) ) {
			return new WP_Error( 'archer_cc_log_disabled', __( 'Consent logging is disabled.', 'archer-cookie-consent' ), array( 'status' => 403 ) );
		}

		$token = (string) $request->get_param( 'token' );

		// SECURITY: hash_equals() keeps the comparison timing-safe. Yesterday's
		// token is accepted so a page cached just before midnight still works.
		if ( ! hash_equals( self::public_token( 0 ), $token ) && ! hash_equals( self::public_token( 1 ), $token ) ) {
			return new WP_Error( 'archer_cc_bad_token', __( 'Invalid or expired token.', 'archer-cookie-consent' ), array( 'status' => 403 ) );
		}

		// Rate limit per (IP, consent id). Keying on the IP alone would silence
		// logging for every visitor behind one corporate NAT or mobile carrier,
		// which is the opposite of what an Art. 7(1) audit trail is for.
		$bucket = 'archer_cc_rl_' . substr( hash( 'sha256', self::ip_hash() . '|' . (string) $request->get_param( 'consent_id' ) ), 0, 32 );
		$hits   = (int) get_transient( $bucket );

		if ( $hits >= 30 ) {
			return new WP_Error( 'archer_cc_rate_limited', __( 'Too many consent writes for this visitor.', 'archer-cookie-consent' ), array( 'status' => 429 ) );
		}

		set_transient( $bucket, $hits + 1, HOUR_IN_SECONDS );

		return true;
	}

	/**
	 * Store one consent record.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_consent( $request ) {
		global $wpdb;

		$categories = array_keys( Archer_CC_Settings::categories() );

		$consent_id = (string) $request->get_param( 'consent_id' );
		// CookieConsent v3 emits a 36-character UUIDv4; the column is CHAR(36).
		if ( ! preg_match( '/^[a-f0-9\-]{8,36}$/i', $consent_id ) ) {
			return new WP_Error( 'archer_cc_bad_consent_id', __( 'Malformed consent id.', 'archer-cookie-consent' ), array( 'status' => 400 ) );
		}

		$accepted = array_values( array_intersect( $categories, array_map( 'sanitize_key', (array) $request->get_param( 'accepted' ) ) ) );
		$rejected = array_values( array_intersect( $categories, array_map( 'sanitize_key', (array) $request->get_param( 'rejected' ) ) ) );

		$language = sanitize_key( (string) $request->get_param( 'language' ) );
		$url      = esc_url_raw( (string) $request->get_param( 'url' ), array( 'http', 'https' ) );

		// Only keep a same-origin path — no need to store arbitrary URLs.
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		$url_host  = wp_parse_url( $url, PHP_URL_HOST );
		if ( $url_host !== $home_host ) {
			$url = '';
		}

		$user_agent = '';
		if ( Archer_CC_Settings::get( 'log_user_agent' ) && isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// SECURITY: superglobal is unslashed and sanitised before storage.
			$user_agent = substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 );
		}

		// SECURITY: $wpdb->insert() prepares every value internally.
		// A custom table is the right store here — this is append-only evidence,
		// not content, so there is nothing to cache and postmeta would not scale.
		$inserted = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			self::table(),
			array(
				'consent_id'          => $consent_id,
				'created_at'          => current_time( 'mysql', true ),
				'action'              => sanitize_key( (string) $request->get_param( 'action' ) ),
				'accepted_categories' => implode( ',', $accepted ),
				'rejected_categories' => implode( ',', $rejected ),
				'revision'            => min( 65535, absint( $request->get_param( 'revision' ) ) ),
				'language'            => substr( $language, 0, 10 ),
				'page_url'            => substr( $url, 0, 255 ),
				'ip_hash'             => self::ip_hash(),
				'user_agent'          => $user_agent,
				'user_id'             => get_current_user_id(),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d' )
		);

		if ( false === $inserted ) {
			return new WP_Error( 'archer_cc_insert_failed', __( 'Could not store the consent record.', 'archer-cookie-consent' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response( array( 'stored' => true ), 201 );
	}

	/**
	 * Salted, truncated hash of the visitor IP.
	 *
	 * The raw address is never persisted: a salted SHA-256 keeps the record
	 * useful as evidence (same visitor, same address) while staying
	 * pseudonymised and data-minimal.
	 *
	 * @return string
	 */
	public static function ip_hash() {
		$ip = '';

		if ( isset( $_SERVER['REMOTE_ADDR'] ) ) {
			// SECURITY: never trust proxy headers for this; REMOTE_ADDR only.
			$candidate = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
			$valid     = filter_var( $candidate, FILTER_VALIDATE_IP );
			$ip        = false === $valid ? '' : $valid;
		}

		return hash( 'sha256', 'archer-cc-ip|' . wp_salt( 'nonce' ) . '|' . $ip );
	}

	/**
	 * Delete records older than the configured retention window.
	 *
	 * @return int Rows deleted.
	 */
	public function purge_expired() {
		global $wpdb;

		$days  = absint( Archer_CC_Settings::get( 'log_retention_days', 400 ) );
		$table = self::table();

		if ( $days < 1 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// SECURITY: value is bound through prepare(); only the identifier is inlined.
		$deleted = $wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->prepare( "DELETE FROM {$table} WHERE created_at < %s", $cutoff ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return (int) $deleted;
	}

	/**
	 * Fetch a page of log rows for the admin screen.
	 *
	 * @param int $per_page Rows per page.
	 * @param int $page     1-based page number.
	 * @return array{rows:array,total:int}
	 */
	public static function get_page( $per_page = 25, $page = 1 ) {
		global $wpdb;

		$table    = self::table();
		$per_page = max( 1, min( 200, absint( $per_page ) ) );
		$page     = max( 1, absint( $page ) );
		$offset   = ( $page - 1 ) * $per_page;

		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $per_page, $offset ), // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			ARRAY_A
		);

		return array(
			'rows'  => is_array( $rows ) ? $rows : array(),
			'total' => $total,
		);
	}

	/**
	 * Stream the whole log as CSV.
	 *
	 * @return void
	 */
	public static function export_csv() {
		global $wpdb;

		// SECURITY: capability + nonce are both required before anything is sent.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to export the consent log.', 'archer-cookie-consent' ), 403 );
		}

		check_admin_referer( 'archer_cc_export_log' );

		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=archer-cc-consent-log-' . gmdate( 'Ymd-His' ) . '.csv' );

		// STANDARDS: WP_Filesystem cannot stream to php://output — this writes a
		// download to the response body, it does not touch the filesystem.
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( false === $out ) {
			wp_die( esc_html__( 'Could not open the output stream.', 'archer-cookie-consent' ) );
		}

		// STANDARDS: PHP 8.4 deprecates relying on the default $escape value, so
		// all three format arguments are passed explicitly. An empty escape
		// character is also the RFC 4180-correct behaviour.
		fputcsv( $out, array( 'id', 'consent_id', 'created_at_utc', 'action', 'accepted', 'rejected', 'revision', 'language', 'page_url', 'ip_hash', 'user_agent', 'user_id' ), ',', '"', '' );

		foreach ( (array) $rows as $row ) {
			fputcsv(
				$out,
				array(
					$row['id'],
					$row['consent_id'],
					$row['created_at'],
					$row['action'],
					$row['accepted_categories'],
					$row['rejected_categories'],
					$row['revision'],
					$row['language'],
					$row['page_url'],
					$row['ip_hash'],
					$row['user_agent'],
					$row['user_id'],
				),
				',',
				'"',
				''
			);
		}

		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}
}
