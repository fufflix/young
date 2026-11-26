<?php
/**
 * Plugin Name: Young Archivio
 * Description: Accesso da amministratore, tramite chiave, per la manutenzione dell'archivio di young.it da parte di Claude: tutta l'API REST di WordPress più strumenti di ricerca e sostituzione in tutto il database, con prova a vuoto e annullamento.
 * Version:     1.3.0
 * Requires PHP: 7.4
 * Author:      YOUng
 * License:     GPL-2.0-or-later
 *
 * @package YoungArchivio
 */

defined( 'ABSPATH' ) || exit;

final class Young_Archivio {

	const VERSION = '1.3.0';
	const OPTION  = 'young_archivio_token';   // array( hash, user_id, created, last_used )
	const HEADER  = 'HTTP_X_ARCHIVIO_TOKEN';  // intestazione X-Archivio-Token
	const NS      = 'young-archivio/v1';
	const TABLE   = 'young_archivio_log';
	const LIFETIME = 30 * DAY_IN_SECONDS;
	const RULES    = 'young_archivio_output_rules'; // sostituzioni nell'HTML delle pagine pubbliche

	/** Opzioni che la sostituzione non tocca mai. */
	const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'active_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'db_version', 'initial_db_version', self::OPTION );

	/** @var int|false ID dell'amministratore autenticato con la chiave. */
	private static $authed = false;

	/** Evita che il controllo si richiami da solo (update_option può interrogare di nuovo l'utente corrente). */
	private static $checking = false;

	public static function init() {
		register_activation_hook( __FILE__, array( __CLASS__, 'install' ) );
		add_filter( 'determine_current_user', array( __CLASS__, 'determine_user' ), 40 );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_auth' ), 99 );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'template_redirect', array( __CLASS__, 'start_buffer' ), 0 );
		// BuddyPress 7.1.0 ha una falla nell'API REST (CVE-2021-21389: un utente iscritto può diventare
		// amministratore tramite buddypress/v1/members/me). L'archivio non usa quell'API: la spegniamo.
		add_filter( 'bp_rest_api_is_available', '__return_false', 99 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_young_archivio_token', array( __CLASS__, 'create_token' ) );
		add_action( 'admin_post_young_archivio_revoke', array( __CLASS__, 'revoke_token' ) );
		add_action( 'admin_post_young_archivio_use', array( __CLASS__, 'use_token' ) );
	}

	/* ---------- installazione ---------- */

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = $wpdb->prefix . self::TABLE;
		dbDelta(
			"CREATE TABLE $t (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			run_id varchar(32) NOT NULL,
			area varchar(20) NOT NULL,
			obj_ref varchar(191) NOT NULL,
			field varchar(64) NOT NULL,
			old_value longtext NOT NULL,
			created datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id)
			) {$wpdb->get_charset_collate()};"
		);
	}

	/* ---------- autenticazione: solo richieste REST con la chiave giusta ---------- */

	private static function is_rest_request() {
		if ( isset( $_GET['rest_route'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}
		$uri    = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore
		$prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		return false !== strpos( (string) wp_parse_url( $uri, PHP_URL_PATH ), $prefix );
	}

	/**
	 * La chiave arriva in X-Archivio-Token oppure in "Authorization: Bearer": così una sola chiave
	 * (quella di Young MCP del sito principale) può valere per entrambi i siti sullo stesso dominio.
	 */
	private static function presented() {
		if ( ! empty( $_SERVER[ self::HEADER ] ) ) {
			return trim( (string) wp_unslash( $_SERVER[ self::HEADER ] ) ); // phpcs:ignore
		}
		$headers = array();
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$headers[] = (string) wp_unslash( $_SERVER[ $key ] ); // phpcs:ignore
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( (string) $name ) ) {
					$headers[] = (string) $value;
				}
			}
		}
		foreach ( $headers as $h ) {
			if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $h, $m ) ) {
				return $m[1];
			}
		}
		return '';
	}

	/** Chiave generata qui (yarc_) o chiave di Young MCP del sito principale (ymcp_). */
	private static function valid_format( $token ) {
		return (bool) preg_match( '/^(yarc_[A-Za-z0-9]{40}|ymcp_[a-f0-9]{48})$/', (string) $token );
	}

	public static function determine_user( $user_id ) {
		if ( self::$checking ) {
			return $user_id;
		}
		if ( self::$authed ) {
			return self::$authed;
		}
		self::$checking = true;
		$result         = self::check_token( $user_id );
		self::$checking = false;
		return $result;
	}

	private static function check_token( $user_id ) {
		$token = self::presented();
		// Solo API, solo HTTPS (in locale si può provare con YOUNG_ARCHIVIO_ALLOW_HTTP), chiave nel formato giusto.
		if ( '' === $token || ! self::is_rest_request() || ! self::valid_format( $token ) ) {
			return $user_id;
		}
		if ( ! is_ssl() && ! ( defined( 'YOUNG_ARCHIVIO_ALLOW_HTTP' ) && YOUNG_ARCHIVIO_ALLOW_HTTP ) ) {
			return $user_id;
		}
		$rec = get_option( self::OPTION );
		if ( ! is_array( $rec ) || empty( $rec['hash'] ) || ! hash_equals( $rec['hash'], hash( 'sha256', $token ) ) ) {
			return $user_id;
		}
		// La chiave scade da sola 30 giorni dopo la creazione: per lavorare ancora se ne genera una nuova.
		if ( empty( $rec['created'] ) || time() - (int) $rec['created'] > self::LIFETIME ) {
			return $user_id;
		}
		$user = get_userdata( (int) $rec['user_id'] );
		if ( ! $user || ! user_can( $user, 'manage_options' ) ) {
			return $user_id;
		}
		self::$authed = (int) $user->ID;
		if ( empty( $rec['last_used'] ) || time() - (int) $rec['last_used'] > 300 ) {
			$rec['last_used'] = time();
			update_option( self::OPTION, $rec, false );
		}
		nocache_headers();
		return $user->ID;
	}

	/** Con la chiave valida la richiesta non ha bisogno del nonce dei cookie. */
	public static function rest_auth( $result ) {
		return self::$authed ? true : $result;
	}

	/* ---------- correzioni dell'HTML pubblico (testi fissi nel tema) ---------- */

	/**
	 * Le regole stanno nell'opzione young_archivio_output_rules: una lista di
	 * { "cerca": "...", "sostituisci": "...", "regex": false }. Valgono solo per le pagine pubbliche.
	 */
	public static function start_buffer() {
		if ( is_admin() || is_feed() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_robots() ) {
			return;
		}
		$rules = get_option( self::RULES );
		if ( empty( $rules ) || ! is_array( $rules ) ) {
			return;
		}
		ob_start(
			static function ( $html ) use ( $rules ) {
				return self::apply_rules( (string) $html, $rules );
			}
		);
	}

	public static function apply_rules( $html, $rules ) {
		foreach ( $rules as $r ) {
			if ( ! is_array( $r ) || ! isset( $r['cerca'], $r['sostituisci'] ) || '' === (string) $r['cerca'] ) {
				continue;
			}
			if ( ! empty( $r['regex'] ) ) {
				$out = @preg_replace( (string) $r['cerca'], (string) $r['sostituisci'], $html ); // phpcs:ignore
				if ( null !== $out ) {
					$html = $out;
				}
			} else {
				$html = str_replace( (string) $r['cerca'], (string) $r['sostituisci'], $html );
			}
		}
		return $html;
	}

	/* ---------- strumenti ---------- */

	public static function routes() {
		register_rest_route(
			self::NS,
			'/run',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'run' ),
				'permission_callback' => static function () {
					return self::$authed && current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run( WP_REST_Request $req ) {
		$tool = sanitize_key( (string) $req->get_param( 'tool' ) );
		$args = (array) $req->get_param( 'args' );
		$map  = array(
			'info'          => 'tool_info',
			'cerca'         => 'tool_search',
			'sostituisci'   => 'tool_replace',
			'annulla'       => 'tool_undo',
			'registro'      => 'tool_log',
			'leggi_opzione' => 'tool_get_option',
			'scrivi_opzione' => 'tool_set_option',
			'sql'           => 'tool_sql',
			'svuota_cache'  => 'tool_flush',
			'prova_regole'  => 'tool_test_rules',
			'tema_usa'      => 'tool_theme_uses',
		);
		if ( ! isset( $map[ $tool ] ) ) {
			return new WP_Error( 'young_archivio_tool', 'Strumento sconosciuto. Disponibili: ' . implode( ', ', array_keys( $map ) ), array( 'status' => 400 ) );
		}
		$out = call_user_func( array( __CLASS__, $map[ $tool ] ), $args );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	private static function tool_info() {
		global $wpdb, $wp_version;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $p ) {
			$plugins[] = array( 'file' => $file, 'nome' => $p['Name'], 'versione' => $p['Version'], 'attivo' => is_plugin_active( $file ) );
		}
		$counts = array();
		foreach ( $wpdb->get_results( "SELECT post_type, post_status, COUNT(*) n FROM {$wpdb->posts} GROUP BY post_type, post_status" ) as $r ) { // phpcs:ignore
			$counts[ $r->post_type ][ $r->post_status ] = (int) $r->n;
		}
		$theme = wp_get_theme();
		return array(
			'nome'      => get_bloginfo( 'name' ),
			'url'       => home_url( '/' ),
			'wordpress' => $wp_version,
			'php'       => PHP_VERSION,
			'tema'      => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) . ' (' . get_stylesheet() . ')',
			'prefisso'  => $wpdb->prefix,
			'utente'    => wp_get_current_user()->user_login,
			'plugin'    => $plugins,
			'contenuti' => $counts,
			'widget'    => array_keys( (array) get_option( 'sidebars_widgets', array() ) ),
		);
	}

	private static function areas( $args ) {
		$all  = array( 'posts', 'postmeta', 'options', 'termmeta', 'terms', 'comments', 'usermeta' );
		$want = isset( $args['dove'] ) ? array_intersect( $all, (array) $args['dove'] ) : $all;
		return $want ? array_values( $want ) : $all;
	}

	/** Tabelle e colonne per ogni area: chiave primaria, colonna di riferimento, colonne di testo. */
	private static function area_def( $area ) {
		global $wpdb;
		$d = array(
			'posts'    => array( $wpdb->posts, 'ID', 'post_type', array( 'post_content', 'post_title', 'post_excerpt' ) ),
			'postmeta' => array( $wpdb->postmeta, 'meta_id', 'meta_key', array( 'meta_value' ) ),
			'options'  => array( $wpdb->options, 'option_name', 'option_name', array( 'option_value' ) ),
			'termmeta' => array( $wpdb->termmeta, 'meta_id', 'meta_key', array( 'meta_value' ) ),
			'terms'    => array( $wpdb->terms, 'term_id', 'slug', array( 'name' ) ),
			'comments' => array( $wpdb->comments, 'comment_ID', 'comment_post_ID', array( 'comment_content', 'comment_author' ) ),
			'usermeta' => array( $wpdb->usermeta, 'umeta_id', 'meta_key', array( 'meta_value' ) ),
		);
		return $d[ $area ];
	}

	private static function snippet( $text, $needle ) {
		$text = wp_strip_all_tags( (string) $text );
		$i    = mb_stripos( $text, $needle );
		if ( false === $i ) {
			return mb_substr( $text, 0, 160 );
		}
		return trim( mb_substr( $text, max( 0, $i - 80 ), mb_strlen( $needle ) + 160 ) );
	}

	private static function tool_search( $args ) {
		global $wpdb;
		$needle = isset( $args['testo'] ) ? (string) $args['testo'] : '';
		if ( '' === $needle ) {
			return new WP_Error( 'young_archivio_args', 'Serve "testo".', array( 'status' => 400 ) );
		}
		$limit = min( 200, max( 1, (int) ( $args['esempi'] ?? 10 ) ) );
		$like  = '%' . $wpdb->esc_like( $needle ) . '%';
		$out   = array();
		foreach ( self::areas( $args ) as $area ) {
			list( $table, $pk, $ref, $cols ) = self::area_def( $area );
			$where = implode( ' OR ', array_map( static function ( $c ) { return "`$c` LIKE %s"; }, $cols ) );
			$params = array_fill( 0, count( $cols ), $like );
			$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `$table` WHERE $where", $params ) ); // phpcs:ignore
			if ( ! $total ) {
				continue;
			}
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS id, `$ref` AS ref, " . implode( ',', array_map( static function ( $c ) { return "`$c`"; }, $cols ) ) . " FROM `$table` WHERE $where LIMIT %d", array_merge( $params, array( $limit ) ) ), ARRAY_A ); // phpcs:ignore
			$samples = array();
			foreach ( $rows as $r ) {
				foreach ( $cols as $c ) {
					if ( false !== mb_stripos( (string) $r[ $c ], $needle ) ) {
						$samples[] = array( 'id' => $r['id'], 'rif' => $r['ref'], 'campo' => $c, 'testo' => self::snippet( $r[ $c ], $needle ) );
					}
				}
			}
			$out[ $area ] = array( 'righe' => $total, 'esempi' => $samples );
		}
		return array( 'testo' => $needle, 'risultati' => $out );
	}

	private static function replace_deep( $data, $from, $to, &$count ) {
		if ( is_string( $data ) ) {
			$n = 0;
			$data = str_replace( $from, $to, $data, $n );
			$count += $n;
			return $data;
		}
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $v ) {
				$data[ $k ] = self::replace_deep( $v, $from, $to, $count );
			}
			return $data;
		}
		if ( is_object( $data ) && ! ( $data instanceof __PHP_Incomplete_Class ) ) {
			foreach ( get_object_vars( $data ) as $k => $v ) {
				$data->$k = self::replace_deep( $v, $from, $to, $count );
			}
		}
		return $data;
	}

	/** Sostituisce anche dentro i dati serializzati senza romperli. */
	private static function replace_value( $raw, $from, $to, &$count ) {
		if ( is_serialized( $raw ) ) {
			$data = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( false === $data && 'b:0;' !== $raw ) {
				return $raw; // illeggibile: meglio non toccarlo
			}
			$new = self::replace_deep( $data, $from, $to, $count );
			return $count ? serialize( $new ) : $raw; // phpcs:ignore
		}
		return self::replace_deep( (string) $raw, $from, $to, $count );
	}

	private static function tool_replace( $args ) {
		global $wpdb;
		$from = isset( $args['cerca'] ) ? (string) $args['cerca'] : '';
		$to   = isset( $args['sostituisci'] ) ? (string) $args['sostituisci'] : null;
		if ( '' === $from || null === $to ) {
			return new WP_Error( 'young_archivio_args', 'Servono "cerca" e "sostituisci".', array( 'status' => 400 ) );
		}
		$dry    = ! isset( $args['prova'] ) || false !== filter_var( $args['prova'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
		$max    = min( 5000, max( 1, (int) ( $args['limite'] ?? 500 ) ) );
		$run_id = $dry ? '' : gmdate( 'YmdHis' ) . wp_generate_password( 6, false );
		$like   = '%' . $wpdb->esc_like( $from ) . '%';
		$log    = $wpdb->prefix . self::TABLE;
		$report = array();
		$left   = $max;
		foreach ( self::areas( $args ) as $area ) {
			list( $table, $pk, $ref, $cols ) = self::area_def( $area );
			$done = array( 'righe' => 0, 'sostituzioni' => 0, 'saltate' => array(), 'esempi' => array() );
			foreach ( $cols as $c ) {
				if ( $left <= 0 ) {
					break;
				}
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT `$pk` AS id, `$ref` AS ref, `$c` AS val FROM `$table` WHERE BINARY `$c` LIKE BINARY %s LIMIT %d", $like, $left ), ARRAY_A ); // phpcs:ignore
				foreach ( $rows as $r ) {
					if ( 'options' === $area && ( in_array( $r['id'], self::PROTECTED_OPTIONS, true ) || $r['id'] === $wpdb->prefix . 'user_roles' || false !== strpos( $r['id'], '_transient' ) ) ) {
						$done['saltate'][] = $r['id'];
						continue;
					}
					$n   = 0;
					$new = self::replace_value( $r['val'], $from, $to, $n );
					if ( ! $n || $new === $r['val'] ) {
						continue;
					}
					$done['righe']++;
					$done['sostituzioni'] += $n;
					$left--;
					if ( count( $done['esempi'] ) < 5 ) {
						$done['esempi'][] = array( 'id' => $r['id'], 'rif' => $r['ref'], 'campo' => $c, 'prima' => self::snippet( $r['val'], $from ), 'dopo' => self::snippet( $new, $to ) );
					}
					if ( ! $dry ) {
						$wpdb->insert( $log, array( 'run_id' => $run_id, 'area' => $area, 'obj_ref' => (string) $r['id'], 'field' => $c, 'old_value' => $r['val'], 'created' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
						$wpdb->update( $table, array( $c => $new ), array( $pk => $r['id'] ), array( '%s' ), array( 'options' === $area ? '%s' : '%d' ) );
						self::clean_cache( $area, $r );
					}
				}
			}
			if ( $done['righe'] || $done['saltate'] ) {
				$report[ $area ] = $done;
			}
		}
		if ( ! $dry ) {
			wp_cache_flush();
		}
		return array( 'prova_a_vuoto' => $dry, 'run_id' => $run_id, 'limite_raggiunto' => $left <= 0, 'risultati' => $report );
	}

	private static function clean_cache( $area, $r ) {
		if ( 'posts' === $area ) {
			clean_post_cache( (int) $r['id'] );
		} elseif ( 'options' === $area ) {
			wp_cache_delete( $r['id'], 'options' );
			wp_cache_delete( 'alloptions', 'options' );
		}
	}

	private static function tool_undo( $args ) {
		global $wpdb;
		$run_id = isset( $args['run_id'] ) ? preg_replace( '/[^A-Za-z0-9]/', '', (string) $args['run_id'] ) : '';
		$log    = $wpdb->prefix . self::TABLE;
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `$log` WHERE run_id = %s ORDER BY id DESC", $run_id ), ARRAY_A ); // phpcs:ignore
		if ( ! $rows ) {
			return new WP_Error( 'young_archivio_undo', 'Nessuna modifica con questo run_id.', array( 'status' => 404 ) );
		}
		foreach ( $rows as $row ) {
			list( $table, $pk ) = self::area_def( $row['area'] );
			$wpdb->update( $table, array( $row['field'] => $row['old_value'] ), array( $pk => $row['obj_ref'] ), array( '%s' ), array( 'options' === $row['area'] ? '%s' : '%d' ) );
			self::clean_cache( $row['area'], array( 'id' => $row['obj_ref'] ) );
		}
		$wpdb->delete( $log, array( 'run_id' => $run_id ) );
		wp_cache_flush();
		return array( 'ripristinate' => count( $rows ), 'run_id' => $run_id );
	}

	private static function tool_log() {
		global $wpdb;
		$log = $wpdb->prefix . self::TABLE;
		return $wpdb->get_results( "SELECT run_id, area, COUNT(*) righe, MIN(created) quando FROM `$log` GROUP BY run_id, area ORDER BY quando DESC LIMIT 50", ARRAY_A ); // phpcs:ignore
	}

	private static function tool_get_option( $args ) {
		$name = isset( $args['nome'] ) ? (string) $args['nome'] : '';
		if ( self::OPTION === $name ) {
			return new WP_Error( 'young_archivio_option', 'Opzione riservata.', array( 'status' => 403 ) );
		}
		return array( 'nome' => $name, 'esiste' => false !== get_option( $name, false ), 'valore' => get_option( $name ) );
	}

	private static function tool_set_option( $args ) {
		$name = isset( $args['nome'] ) ? (string) $args['nome'] : '';
		if ( '' === $name || in_array( $name, self::PROTECTED_OPTIONS, true ) ) {
			return new WP_Error( 'young_archivio_option', 'Opzione protetta o nome mancante.', array( 'status' => 403 ) );
		}
		global $wpdb;
		$old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $name ) ); // phpcs:ignore
		$run_id = gmdate( 'YmdHis' ) . wp_generate_password( 6, false );
		if ( null !== $old ) {
			$wpdb->insert( $wpdb->prefix . self::TABLE, array( 'run_id' => $run_id, 'area' => 'options', 'obj_ref' => $name, 'field' => 'option_value', 'old_value' => $old, 'created' => current_time( 'mysql', true ) ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
		}
		update_option( $name, $args['valore'] ?? '' );
		return array( 'nome' => $name, 'run_id' => null !== $old ? $run_id : '', 'valore' => get_option( $name ) );
	}

	/** Solo lettura: SELECT, SHOW, DESCRIBE, EXPLAIN. */
	private static function tool_sql( $args ) {
		global $wpdb;
		$q = trim( (string) ( $args['query'] ?? '' ) );
		// Niente file (INTO OUTFILE/DUMPFILE, LOAD_FILE), niente password o chiave, niente pause volute.
		$blocked = '/\b(INTO|LOAD_FILE|OUTFILE|DUMPFILE|SLEEP|BENCHMARK|user_pass|user_activation_key|' . self::OPTION . ')\b/i';
		if ( ! preg_match( '/^(SELECT|SHOW|DESCRIBE|EXPLAIN)\b/i', $q ) || false !== strpos( $q, ';' ) || preg_match( $blocked, $q ) ) {
			return new WP_Error( 'young_archivio_sql', 'Solo una query SELECT, SHOW, DESCRIBE o EXPLAIN, senza punto e virgola.', array( 'status' => 400 ) );
		}
		$rows = $wpdb->get_results( $q, ARRAY_A ); // phpcs:ignore
		return array( 'righe' => is_array( $rows ) ? array_slice( $rows, 0, 500 ) : array(), 'errore' => $wpdb->last_error );
	}

	/** Applica le regole (quelle date o quelle salvate) a una pagina del sito e restituisce i pezzi cambiati. */
	private static function tool_test_rules( $args ) {
		$rules = isset( $args['regole'] ) ? (array) $args['regole'] : (array) get_option( self::RULES, array() );
		$url   = isset( $args['url'] ) ? esc_url_raw( (string) $args['url'] ) : home_url( '/' );
		if ( 0 !== strpos( $url, home_url() ) ) {
			return new WP_Error( 'young_archivio_url', 'Solo pagine di questo sito.', array( 'status' => 400 ) );
		}
		$res = wp_remote_get( add_query_arg( 'ya_nocache', time(), $url ), array( 'timeout' => 30, 'redirection' => 0 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$before = (string) wp_remote_retrieve_body( $res );
		$after  = self::apply_rules( $before, $rules );
		$hits   = array();
		foreach ( $rules as $i => $r ) {
			$n = 0;
			if ( ! empty( $r['regex'] ) ) {
				$n = (int) @preg_match_all( (string) $r['cerca'], $before ); // phpcs:ignore
			} elseif ( isset( $r['cerca'] ) && '' !== (string) $r['cerca'] ) {
				$n = substr_count( $before, (string) $r['cerca'] );
			}
			$hits[] = array( 'regola' => $i, 'trovate' => $n );
		}
		return array( 'url' => $url, 'stato' => wp_remote_retrieve_response_code( $res ), 'regole' => $hits, 'cambiata' => $before !== $after, 'lunghezza_prima' => strlen( $before ), 'lunghezza_dopo' => strlen( $after ) );
	}

	/** Sola lettura: righe dei file PHP del tema attivo che contengono i testi cercati (per esempio "bp_"). */
	private static function tool_theme_uses( $args ) {
		$needles = array_values( array_filter( array_map( 'strval', (array) ( $args['cerca'] ?? array( 'bp_', 'buddypress' ) ) ) ) );
		$dirs    = array_unique( array( get_stylesheet_directory(), get_template_directory() ) );
		$out     = array();
		$total   = 0;
		foreach ( $dirs as $dir ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' !== strtolower( $file->getExtension() ) ) {
					continue;
				}
				$lines = @file( $file->getPathname() ); // phpcs:ignore
				if ( ! $lines ) {
					continue;
				}
				foreach ( $lines as $n => $line ) {
					foreach ( $needles as $needle ) {
						if ( false !== stripos( $line, $needle ) ) {
							$total++;
							if ( count( $out ) < 300 ) {
								$out[] = array( 'file' => str_replace( WP_CONTENT_DIR, '', $file->getPathname() ), 'riga' => $n + 1, 'testo' => mb_substr( trim( $line ), 0, 200 ) );
							}
							break;
						}
					}
				}
			}
		}
		return array( 'cercati' => $needles, 'totale' => $total, 'righe' => $out );
	}

	private static function tool_flush() {
		$done = array( 'oggetti' );
		wp_cache_flush();
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
			$done[] = 'WP Super Cache';
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
			$done[] = 'W3 Total Cache';
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done[] = 'WP Rocket';
		}
		if ( has_action( 'litespeed_purge_all' ) ) {
			do_action( 'litespeed_purge_all' );
			$done[] = 'LiteSpeed';
		}
		if ( class_exists( 'autoptimizeCache' ) ) {
			autoptimizeCache::clearall();
			$done[] = 'Autoptimize';
		}
		if ( function_exists( 'elementor_load_plugin_textdomain' ) && class_exists( '\Elementor\Plugin' ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
			$done[] = 'Elementor CSS';
		}
		return array( 'svuotate' => $done );
	}

	/* ---------- pagina in Strumenti ---------- */

	public static function menu() {
		add_management_page( 'Young Archivio', 'Young Archivio', 'manage_options', 'young-archivio', array( __CLASS__, 'page' ) );
	}

	private static function page_url() {
		return admin_url( 'tools.php?page=young-archivio' );
	}

	public static function create_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'young_archivio_token' );
		self::install();
		$token = 'yarc_' . wp_generate_password( 40, false );
		update_option( self::OPTION, array( 'hash' => hash( 'sha256', $token ), 'user_id' => get_current_user_id(), 'created' => time(), 'last_used' => 0 ), false );
		set_transient( 'young_archivio_new_' . get_current_user_id(), $token, 300 );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	/** Usa una chiave già esistente, per esempio quella di Young MCP del sito principale. */
	public static function use_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'young_archivio_use' );
		$token = isset( $_POST['chiave'] ) ? trim( (string) wp_unslash( $_POST['chiave'] ) ) : ''; // phpcs:ignore
		if ( ! self::valid_format( $token ) ) {
			wp_safe_redirect( add_query_arg( 'young_archivio_error', '1', self::page_url() ) );
			exit;
		}
		self::install();
		update_option( self::OPTION, array( 'hash' => hash( 'sha256', $token ), 'user_id' => get_current_user_id(), 'created' => time(), 'last_used' => 0 ), false );
		wp_safe_redirect( add_query_arg( 'young_archivio_saved', '1', self::page_url() ) );
		exit;
	}

	public static function revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'young_archivio_revoke' );
		delete_option( self::OPTION );
		wp_safe_redirect( self::page_url() );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$rec   = get_option( self::OPTION );
		$new   = get_transient( 'young_archivio_new_' . get_current_user_id() );
		delete_transient( 'young_archivio_new_' . get_current_user_id() );
		echo '<div class="wrap"><h1>Young Archivio</h1>';
		echo '<p>Dà a Claude l\'accesso da amministratore a questo sito tramite l\'API, con la chiave che generi qui. Vale solo per le richieste API che portano la chiave nell\'intestazione <code>X-Archivio-Token</code>: le pagine pubbliche non cambiano.</p>';
		if ( $new ) {
			echo '<div class="notice notice-warning"><p><strong>Chiave nuova, visibile solo adesso:</strong></p><p><input type="text" readonly class="large-text code" value="' . esc_attr( $new ) . '" onclick="this.select()"></p><p>Copiala e incollala nel modulo sicuro che ti apre Claude (nome intestazione <code>X-Archivio-Token</code>, senza prefisso). Non incollarla in chat.</p></div>';
		}
		if ( is_array( $rec ) && ! empty( $rec['hash'] ) ) {
			$u = get_userdata( (int) $rec['user_id'] );
			printf( '<p>Chiave attiva dal %s (scade il %s), collegata a <strong>%s</strong>. Ultimo uso: %s.</p>', esc_html( wp_date( 'd/m/Y H:i', (int) $rec['created'] ) ), esc_html( wp_date( 'd/m/Y H:i', (int) $rec['created'] + self::LIFETIME ) ), esc_html( $u ? $u->user_login : '?' ), esc_html( $rec['last_used'] ? wp_date( 'd/m/Y H:i', (int) $rec['last_used'] ) : 'mai' ) );
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="young_archivio_revoke">';
			wp_nonce_field( 'young_archivio_revoke' );
			submit_button( 'Revoca la chiave', 'delete', 'submit', false );
			echo '</form>';
		}
		if ( isset( $_GET['young_archivio_error'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-error"><p>Chiave non valida: deve iniziare con ymcp_ (Young MCP) o yarc_.</p></div>';
		}
		if ( isset( $_GET['young_archivio_saved'] ) ) { // phpcs:ignore
			echo '<div class="notice notice-success"><p>Chiave salvata.</p></div>';
		}
		echo '<h2>Usa la chiave di Young MCP del sito principale</h2><p>Incolla qui la chiave creata in Young MCP su young.it (inizia con <code>ymcp_</code>): la stessa chiave varrà per entrambi i siti. Qui ne resta solo l\'impronta cifrata.</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="young_archivio_use">';
		wp_nonce_field( 'young_archivio_use' );
		echo '<p><input type="password" name="chiave" class="large-text code" autocomplete="off" placeholder="ymcp_…"></p>';
		submit_button( 'Salva questa chiave', 'primary', 'submit', false );
		echo '</form><h2>Oppure genera una chiave solo per l\'archivio</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="young_archivio_token">';
		wp_nonce_field( 'young_archivio_token' );
		submit_button( is_array( $rec ) ? 'Genera una chiave nuova (sostituisce quella attuale)' : 'Genera la chiave', 'secondary', 'submit', false );
		echo '</form></div>';
	}
}

Young_Archivio::init();

/*
 * Il tema dell'archivio chiama smlsubform(), la funzione del modulo di Mail Subscribe List.
 * Quel plugin è stato tolto (ed è ritirato da wordpress.org): senza questa funzione le pagine
 * darebbero errore. Qui la funzione e lo shortcode [smlsubform] mostrano il pulsante per
 * iscriversi alla newsletter su Ghost.
 */
if ( ! function_exists( 'smlsubform' ) ) {
	function smlsubform( $atts = array() ) {
		return '<p class="ya-newsletter" style="margin:1em 0;text-align:center"><a class="btn btn-warning" href="https://newsletter.fufflix.it/" target="_blank" rel="noopener" style="font-weight:700">Iscriviti alla newsletter</a></p>';
	}
	add_action(
		'init',
		static function () {
			if ( ! shortcode_exists( 'smlsubform' ) ) {
				add_shortcode( 'smlsubform', 'smlsubform' );
			}
		}
	);
}
