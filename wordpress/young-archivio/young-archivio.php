<?php
/**
 * Plugin Name: Young Archivio
 * Description: Accesso da amministratore, tramite chiave, per la manutenzione dell'archivio di young.it da parte di Claude: tutta l'API REST di WordPress più strumenti di ricerca e sostituzione in tutto il database, con prova a vuoto e annullamento.
 * Version:     1.0.0
 * Requires PHP: 7.4
 * Author:      YOUng
 * License:     GPL-2.0-or-later
 *
 * @package YoungArchivio
 */

defined( 'ABSPATH' ) || exit;

final class Young_Archivio {

	const VERSION = '1.0.0';
	const OPTION  = 'young_archivio_token';   // array( hash, user_id, created, last_used )
	const HEADER  = 'HTTP_X_ARCHIVIO_TOKEN';  // intestazione X-Archivio-Token
	const NS      = 'young-archivio/v1';
	const TABLE   = 'young_archivio_log';
	const LIFETIME = 30 * DAY_IN_SECONDS;

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
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_young_archivio_token', array( __CLASS__, 'create_token' ) );
		add_action( 'admin_post_young_archivio_revoke', array( __CLASS__, 'revoke_token' ) );
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

	private static function presented() {
		return empty( $_SERVER[ self::HEADER ] ) ? '' : trim( (string) wp_unslash( $_SERVER[ self::HEADER ] ) ); // phpcs:ignore
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
		if ( '' === $token || ! self::is_rest_request() || ! preg_match( '/^yarc_[A-Za-z0-9]{40}$/', $token ) ) {
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
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="margin-top:1em"><input type="hidden" name="action" value="young_archivio_token">';
		wp_nonce_field( 'young_archivio_token' );
		submit_button( is_array( $rec ) ? 'Genera una chiave nuova (sostituisce quella attuale)' : 'Genera la chiave', 'primary', 'submit', false );
		echo '</form></div>';
	}
}

Young_Archivio::init();
