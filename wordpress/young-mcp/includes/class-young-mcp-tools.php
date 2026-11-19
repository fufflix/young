<?php
/**
 * MCP tools. Every handler runs as the WordPress user bound to the token, so
 * WordPress capabilities apply on top of the plugin's own editorial policy.
 * There is deliberately no delete tool.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Tool_Error extends Exception {}

class Young_MCP_Tools {

	const POST_TYPES    = array( 'post', 'page' );
	const LIVE_STATUSES = array( 'publish', 'future', 'private' );
	const IMAGE_MIMES   = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);

	/* ---------------------------------------------------------------------
	 * Definitions
	 * ------------------------------------------------------------------- */

	private static function seo_schema() {
		return array(
			'type'        => 'object',
			'description' => 'Campi SEO (Yoast SEO o Rank Math, in automatico). Stringa vuota = ripristina il valore predefinito.',
			'properties'  => array(
				'title'          => array( 'type' => 'string', 'description' => 'Titolo SEO (tag <title>), idealmente 30-60 caratteri.' ),
				'description'    => array( 'type' => 'string', 'description' => 'Meta description, idealmente 120-160 caratteri.' ),
				'focus_keyword'  => array( 'type' => 'string', 'description' => 'Keyword principale.' ),
				'canonical'      => array( 'type' => 'string', 'description' => 'URL canonico, solo se diverso dall\'articolo stesso.' ),
				'noindex'        => array( 'type' => 'boolean', 'description' => 'true = escludi da Google; false = impostazione predefinita del sito.' ),
				'og_title'       => array( 'type' => 'string', 'description' => 'Titolo per anteprime social (Facebook/Open Graph).' ),
				'og_description' => array( 'type' => 'string', 'description' => 'Descrizione per anteprime social.' ),
				'og_image'       => array( 'type' => array( 'integer', 'string' ), 'description' => 'Immagine per anteprime social (Facebook/X): ID di un\'immagine della libreria media oppure URL dell\'immagine. Stringa vuota = ripristina.' ),
			),
		);
	}

	private static function post_fields_schema() {
		return array(
			'title'          => array( 'type' => 'string', 'description' => 'Titolo dell\'articolo.' ),
			'content'        => array( 'type' => 'string', 'description' => 'Corpo in markup a blocchi Gutenberg (preferito) o HTML. Niente H1.' ),
			'excerpt'        => array( 'type' => 'string', 'description' => 'Riassunto/occhiello.' ),
			'status'         => array(
				'type'        => 'string',
				'enum'        => array( 'draft', 'pending', 'publish', 'future' ),
				'description' => 'draft = bozza, pending = in attesa di revisione, publish = pubblica, future = programma (richiede date). publish/future solo se abilitati nelle impostazioni del plugin.',
			),
			'slug'           => array( 'type' => 'string', 'description' => 'Slug dell\'URL (breve, con la keyword).' ),
			'date'           => array( 'type' => 'string', 'description' => 'Data di pubblicazione: "AAAA-MM-GG HH:MM:SS" nel fuso orario del sito, oppure ISO 8601 con fuso.' ),
			'categories'     => array(
				'type'        => 'array',
				'items'       => array( 'type' => array( 'integer', 'string' ) ),
				'description' => 'Categorie esistenti (ID, slug o nome). Sostituisce le categorie attuali. Solo per gli articoli.',
			),
			'tags'           => array(
				'type'        => 'array',
				'items'       => array( 'type' => 'string' ),
				'description' => 'Tag per nome (quelli nuovi vengono creati). Sostituisce i tag attuali. Solo per gli articoli.',
			),
			'featured_media' => array( 'type' => 'integer', 'description' => 'ID dell\'immagine in evidenza (0 per rimuoverla).' ),
			'seo'            => self::seo_schema(),
		);
	}

	public static function definitions() {
		$defs = array();
		foreach ( self::registry() as $name => $tool ) {
			$def = array(
				'name'        => $name,
				'title'       => $tool['title'],
				'description' => $tool['description'],
				'inputSchema' => $tool['schema'],
				'annotations' => $tool['annotations'],
			);
			$defs[] = $def;
		}
		return $defs;
	}

	public static function exists( $name ) {
		return isset( self::registry()[ $name ] );
	}

	private static function registry() {
		static $registry = null;
		if ( null !== $registry ) {
			return $registry;
		}

		$read  = array( 'readOnlyHint' => true, 'openWorldHint' => false );
		$empty = array( 'type' => 'object', 'properties' => new stdClass() );

		$create_props          = self::post_fields_schema();
		$create_props['title'] = array( 'type' => 'string', 'description' => 'Titolo dell\'articolo.' );
		$create_props          = array( 'post_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES, 'description' => 'post (articolo, predefinito) o page.' ) ) + $create_props;

		$update_props = array( 'id' => array( 'type' => 'integer', 'description' => 'ID dell\'articolo o pagina.' ) ) + self::post_fields_schema();

		$seo_props = array( 'id' => array( 'type' => 'integer', 'description' => 'ID dell\'articolo o pagina.' ) ) + self::seo_schema()['properties'];

		$registry = array(
			'site_info'    => array(
				'title'       => 'Informazioni sul sito',
				'description' => 'Nome del sito, fuso orario, plugin SEO attivo, utente con cui agisce l\'AI e permessi editoriali attuali. Chiamalo per primo.',
				'schema'      => $empty,
				'annotations' => $read,
				'handler'     => 'site_info',
			),
			'list_posts'   => array(
				'title'       => 'Elenca articoli',
				'description' => 'Cerca ed elenca articoli o pagine, con filtri per stato, categoria, tag e testo. Restituisce metadati, non il corpo.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'search'    => array( 'type' => 'string', 'description' => 'Testo da cercare.' ),
						'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'draft', 'pending', 'future', 'private' ), 'description' => 'Predefinito: any.' ),
						'post_type' => array( 'type' => 'string', 'enum' => self::POST_TYPES, 'description' => 'Predefinito: post.' ),
						'category'  => array( 'type' => 'string', 'description' => 'ID o slug della categoria.' ),
						'tag'       => array( 'type' => 'string', 'description' => 'ID o slug del tag.' ),
						'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'relevance' ), 'description' => 'Predefinito: date.' ),
						'order'     => array( 'type' => 'string', 'enum' => array( 'desc', 'asc' ) ),
						'per_page'  => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Predefinito: 10.' ),
						'page'      => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'annotations' => $read,
				'handler'     => 'list_posts',
			),
			'get_post'     => array(
				'title'       => 'Leggi articolo',
				'description' => 'Restituisce un articolo o pagina completo: corpo (markup originale), riassunto, categorie, tag, immagine in evidenza, campi SEO e link di anteprima/modifica.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array( 'id' => array( 'type' => 'integer' ) ),
					'required'   => array( 'id' ),
				),
				'annotations' => $read,
				'handler'     => 'get_post',
			),
			'create_post'  => array(
				'title'       => 'Crea articolo',
				'description' => 'Crea un articolo (o pagina). Predefinito: bozza. Le categorie devono esistere (vedi list_terms). Restituisce ID e link di anteprima.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => $create_props,
					'required'   => array( 'title', 'content' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => false ),
				'handler'     => 'create_post',
			),
			'update_post'  => array(
				'title'       => 'Modifica articolo',
				'description' => 'Modifica i campi indicati di un articolo esistente; gli altri restano invariati. Le modifiche al testo generano una revisione WordPress ripristinabile. Gli articoli già pubblicati sono modificabili solo se abilitato nelle impostazioni.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => $update_props,
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
				'handler'     => 'update_post',
			),
			'list_terms'   => array(
				'title'       => 'Elenca categorie o tag',
				'description' => 'Elenca le categorie o i tag esistenti (ordinati per numero di articoli), per riusarli invece di crearne di nuovi.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ), 'description' => 'Predefinito: category.' ),
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'description' => 'Predefinito: 100.' ),
					),
				),
				'annotations' => $read,
				'handler'     => 'list_terms',
			),
			'list_media'   => array(
				'title'       => 'Cerca immagini',
				'description' => 'Cerca immagini già presenti nella libreria media (per riutilizzarle come immagine in evidenza).',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'search'   => array( 'type' => 'string' ),
						'per_page' => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'description' => 'Predefinito: 20.' ),
						'page'     => array( 'type' => 'integer', 'minimum' => 1 ),
					),
				),
				'annotations' => $read,
				'handler'     => 'list_media',
			),
			'upload_image' => array(
				'title'       => 'Carica immagine',
				'description' => 'Carica un\'immagine nella libreria media, scaricandola da un URL pubblico (url) oppure dai byte del file in base64 (data_base64, es. un file locale): indica esattamente uno dei due. Imposta testo alternativo e didascalia; opzionalmente la imposta come immagine in evidenza di un articolo. Usa solo immagini di cui la testata ha i diritti.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'url'          => array( 'type' => 'string', 'description' => 'URL http(s) dell\'immagine (JPEG, PNG, GIF, WebP, AVIF). In alternativa a data_base64.' ),
						'data_base64'  => array( 'type' => 'string', 'description' => 'Contenuto del file immagine codificato in base64 (anche con prefisso "data:image/...;base64,"). In alternativa a url.' ),
						'alt'          => array( 'type' => 'string', 'description' => 'Testo alternativo (accessibilità e SEO): descrivi cosa mostra l\'immagine.' ),
						'title'        => array( 'type' => 'string' ),
						'caption'      => array( 'type' => 'string', 'description' => 'Didascalia, includendo credito/fonte.' ),
						'filename'     => array( 'type' => 'string', 'description' => 'Nome file descrittivo, es. "bolletta-luce-aumenti".' ),
						'post_id'      => array( 'type' => 'integer', 'description' => 'Articolo a cui collegare l\'immagine.' ),
						'set_featured' => array( 'type' => 'boolean', 'description' => 'Imposta come immagine in evidenza di post_id.' ),
					),
					'required'   => array( 'alt' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => false, 'openWorldHint' => true ),
				'handler'     => 'upload_image',
			),
			'update_seo'   => array(
				'title'       => 'Aggiorna SEO',
				'description' => 'Aggiorna solo i campi SEO indicati (titolo SEO, meta description, keyword, canonical, noindex, Open Graph e immagine social) senza toccare il testo. Restituisce i valori prima/dopo, registrati anche nel log del plugin.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => $seo_props,
					'required'   => array( 'id' ),
				),
				'annotations' => array( 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false ),
				'handler'     => 'update_seo',
			),
			'seo_audit'    => array(
				'title'       => 'Analisi SEO',
				'description' => 'Analisi SEO euristica di un articolo: lunghezza di titolo e meta description, keyword (titolo, slug, introduzione, densità), sottotitoli, alt delle immagini, link interni/esterni, immagine in evidenza.',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'            => array( 'type' => 'integer' ),
						'focus_keyword' => array( 'type' => 'string', 'description' => 'Keyword da verificare; se omessa usa quella salvata nel plugin SEO.' ),
					),
					'required'   => array( 'id' ),
				),
				'annotations' => $read,
				'handler'     => 'seo_audit',
			),
		);
		return $registry;
	}

	/* ---------------------------------------------------------------------
	 * Dispatch
	 * ------------------------------------------------------------------- */

	public static function call( $name, array $args ) {
		$tool = self::registry()[ $name ];
		try {
			$args = self::validate( $tool['schema'], $args );
			list( $data, $summary ) = call_user_func( array( __CLASS__, 'tool_' . $tool['handler'] ), $args );
			if ( null !== $summary ) {
				Young_MCP_Log::add( $name, true, $summary );
			}
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
				'isError' => false,
			);
		} catch ( Young_MCP_Tool_Error $e ) {
			$message = $e->getMessage();
		} catch ( \Throwable $e ) {
			$message = 'Errore interno: ' . $e->getMessage();
		}
		Young_MCP_Log::add( $name, false, $message );
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	private static function fail( $message ) {
		throw new Young_MCP_Tool_Error( $message );
	}

	/* ---------------------------------------------------------------------
	 * Argument validation (subset of JSON Schema, with lenient coercion)
	 * ------------------------------------------------------------------- */

	private static function validate( array $schema, array $args ) {
		$props = is_array( $schema['properties'] ?? null ) ? $schema['properties'] : array();
		foreach ( $schema['required'] ?? array() as $required ) {
			if ( ! isset( $args[ $required ] ) || '' === $args[ $required ] ) {
				self::fail( 'Parametro obbligatorio mancante: ' . $required );
			}
		}
		$clean = array();
		foreach ( $args as $key => $value ) {
			if ( isset( $props[ $key ] ) && null !== $value ) {
				$clean[ $key ] = self::coerce( $key, $value, $props[ $key ] );
			}
		}
		return $clean;
	}

	private static function coerce( $key, $value, array $spec ) {
		$type = $spec['type'] ?? null;

		if ( is_array( $type ) ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				self::fail( "Parametro $key: valore non valido." );
			}
		} elseif ( 'string' === $type ) {
			if ( ! is_scalar( $value ) || is_bool( $value ) ) {
				self::fail( "Parametro $key: attesa una stringa." );
			}
			$value = (string) $value;
		} elseif ( 'integer' === $type ) {
			if ( is_string( $value ) && preg_match( '/^-?\d+$/', $value ) ) {
				$value = (int) $value;
			} elseif ( is_float( $value ) && floor( $value ) === $value ) {
				$value = (int) $value;
			}
			if ( ! is_int( $value ) ) {
				self::fail( "Parametro $key: atteso un numero intero." );
			}
			if ( isset( $spec['minimum'] ) && $value < $spec['minimum'] ) {
				self::fail( "Parametro $key: minimo {$spec['minimum']}." );
			}
			if ( isset( $spec['maximum'] ) && $value > $spec['maximum'] ) {
				self::fail( "Parametro $key: massimo {$spec['maximum']}." );
			}
		} elseif ( 'boolean' === $type ) {
			if ( ! is_bool( $value ) ) {
				$parsed = filter_var( $value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE );
				if ( null === $parsed ) {
					self::fail( "Parametro $key: atteso true/false." );
				}
				$value = $parsed;
			}
		} elseif ( 'array' === $type ) {
			if ( ! is_array( $value ) || ( $value && array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) ) {
				self::fail( "Parametro $key: attesa una lista." );
			}
			if ( isset( $spec['items'] ) ) {
				foreach ( $value as $i => $item ) {
					$value[ $i ] = self::coerce( $key . '[' . $i . ']', $item, $spec['items'] );
				}
			}
		} elseif ( 'object' === $type ) {
			if ( ! is_array( $value ) ) {
				self::fail( "Parametro $key: atteso un oggetto." );
			}
			$value = self::validate( $spec, $value );
		}

		if ( isset( $spec['enum'] ) && ! in_array( $value, $spec['enum'], true ) ) {
			self::fail( "Parametro $key: valori ammessi " . implode( ', ', $spec['enum'] ) . '.' );
		}
		return $value;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	private static function load_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || ! in_array( $post->post_type, self::POST_TYPES, true ) || in_array( $post->post_status, array( 'trash', 'auto-draft', 'inherit' ), true ) ) {
			self::fail( "Articolo o pagina $id non trovato." );
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			self::fail( "L'utente collegato al token non può modificare il contenuto $id." );
		}
		return $post;
	}

	private static function assert_can_edit_live( WP_Post $post ) {
		if ( in_array( $post->post_status, self::LIVE_STATUSES, true ) && ! Young_MCP_Settings::get( 'allow_edit_published' ) ) {
			self::fail( "Il contenuto {$post->ID} è già pubblicato (o programmato) e la modifica di contenuti pubblicati da parte dell'AI è disabilitata (Impostazioni → Young MCP). Proponi le modifiche alla redazione oppure lavora su una nuova bozza." );
		}
	}

	private static function assert_status_allowed( $status, $post_type ) {
		if ( ! in_array( $status, array( 'publish', 'future' ), true ) ) {
			return;
		}
		if ( ! Young_MCP_Settings::get( 'allow_publish' ) ) {
			self::fail( 'La pubblicazione da parte dell\'AI è disabilitata (Impostazioni → Young MCP). Usa status "draft" o "pending": la redazione pubblicherà dopo la verifica.' );
		}
		if ( ! current_user_can( get_post_type_object( $post_type )->cap->publish_posts ) ) {
			self::fail( 'L\'utente collegato al token non ha il permesso di pubblicare.' );
		}
	}

	/** @return array( local, gmt ) in "Y-m-d H:i:s". */
	private static function parse_date( $value ) {
		try {
			$date = new DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			self::fail( 'Data non valida: usa "AAAA-MM-GG HH:MM:SS".' );
		}
		return array(
			$date->setTimezone( wp_timezone() )->format( 'Y-m-d H:i:s' ),
			$date->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' ),
			$date->getTimestamp(),
		);
	}

	private static function resolve_categories( array $items ) {
		$ids     = array();
		$unknown = array();
		foreach ( $items as $item ) {
			$term = null;
			if ( is_int( $item ) || ctype_digit( (string) $item ) ) {
				$term = get_term( (int) $item, 'category' );
			} else {
				$term = get_term_by( 'slug', sanitize_title( $item ), 'category' );
				if ( ! $term ) {
					$term = get_term_by( 'name', $item, 'category' );
				}
			}
			if ( $term && ! is_wp_error( $term ) ) {
				$ids[] = (int) $term->term_id;
			} else {
				$unknown[] = (string) $item;
			}
		}
		if ( $unknown ) {
			self::fail( 'Categorie inesistenti: ' . implode( ', ', $unknown ) . '. Usa list_terms per vedere quelle disponibili (le nuove categorie vanno create dalla redazione).' );
		}
		return array_values( array_unique( $ids ) );
	}

	private static function sanitize_tags( array $tags ) {
		$tags = array_map( 'sanitize_text_field', $tags );
		return array_values( array_unique( array_filter( $tags, 'strlen' ) ) );
	}

	private static function assert_image( $attachment_id ) {
		if ( $attachment_id && ! wp_attachment_is_image( $attachment_id ) ) {
			self::fail( "featured_media: $attachment_id non è un'immagine della libreria media." );
		}
	}

	private static function terms_of( $post_id, $taxonomy ) {
		$terms = get_the_terms( $post_id, $taxonomy );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return array();
		}
		return array_map(
			static function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
			},
			$terms
		);
	}

	private static function summarize( WP_Post $post ) {
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => get_the_author_meta( 'display_name', $post->post_author ),
			'link'     => 'publish' === $post->post_status ? get_permalink( $post ) : get_preview_post_link( $post ),
		);
	}

	private static function image_info( $attachment_id ) {
		if ( ! $attachment_id ) {
			return null;
		}
		return array(
			'id'      => (int) $attachment_id,
			'url'     => wp_get_attachment_url( $attachment_id ),
			'alt'     => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'caption' => wp_get_attachment_caption( $attachment_id ),
		);
	}

	private static function seo_input( array $args ) {
		$seo = array();
		foreach ( Young_MCP_SEO::FIELDS as $field ) {
			if ( array_key_exists( $field, $args ) ) {
				$seo[ $field ] = $args[ $field ];
			}
		}
		if ( array_key_exists( 'og_image', $seo ) ) {
			// Resolved before anything is written, so an invalid image leaves the post untouched.
			$resolved = Young_MCP_SEO::resolve_og_image( $seo['og_image'] );
			if ( is_string( $resolved ) && '' !== $resolved ) {
				self::fail( $resolved );
			}
			$seo['og_image'] = $resolved;
		}
		return $seo;
	}

	private static function seo_diff( array $change ) {
		$diff = array();
		foreach ( Young_MCP_SEO::FIELDS as $field ) {
			if ( $change['before'][ $field ] !== $change['after'][ $field ] ) {
				$diff[ $field ] = array(
					'prima' => $change['before'][ $field ],
					'dopo'  => $change['after'][ $field ],
				);
			}
		}
		return $diff;
	}

	/* ---------------------------------------------------------------------
	 * Tools. Each returns array( $data, $log_summary|null ).
	 * ------------------------------------------------------------------- */

	private static function tool_site_info( array $args ) {
		$user  = wp_get_current_user();
		$token = Young_MCP_Auth::current();
		$count = wp_count_posts( 'post' );

		return array(
			array(
				'name'              => get_bloginfo( 'name' ),
				'description'       => get_bloginfo( 'description' ),
				'url'               => home_url( '/' ),
				'language'          => get_locale(),
				'timezone'          => wp_timezone_string(),
				'now_local'         => current_time( 'mysql' ),
				'wordpress_version' => get_bloginfo( 'version' ),
				'plugin_version'    => YOUNG_MCP_VERSION,
				'seo_plugin'        => Young_MCP_SEO::plugin(),
				'acting_as'         => array(
					'login'        => $user->user_login,
					'display_name' => $user->display_name,
					'roles'        => array_values( $user->roles ),
					'token'        => $token ? $token['label'] : '',
				),
				'permissions'       => array(
					'create_drafts'  => current_user_can( 'edit_posts' ),
					'publish'        => Young_MCP_Settings::get( 'allow_publish' ) && current_user_can( 'publish_posts' ),
					'edit_published' => Young_MCP_Settings::get( 'allow_edit_published' ) && current_user_can( 'edit_published_posts' ),
					'upload_images'  => current_user_can( 'upload_files' ),
					'delete'         => false,
				),
				'posts'             => array(
					'published' => (int) $count->publish,
					'drafts'    => (int) $count->draft,
					'pending'   => (int) $count->pending,
					'scheduled' => (int) $count->future,
				),
			),
			null,
		);
	}

	private static function tool_list_posts( array $args ) {
		$status = $args['status'] ?? 'any';
		$query  = array(
			'post_type'           => $args['post_type'] ?? 'post',
			'post_status'         => 'any' === $status ? array( 'publish', 'draft', 'pending', 'future', 'private' ) : $status,
			'posts_per_page'      => $args['per_page'] ?? 10,
			'paged'               => $args['page'] ?? 1,
			'orderby'             => $args['orderby'] ?? 'date',
			'order'               => strtoupper( $args['order'] ?? 'desc' ),
			'perm'                => 'readable',
			'ignore_sticky_posts' => true,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = $args['search'];
		} elseif ( 'relevance' === $query['orderby'] ) {
			$query['orderby'] = 'date';
		}
		if ( ! empty( $args['category'] ) ) {
			if ( ctype_digit( $args['category'] ) ) {
				$query['cat'] = (int) $args['category'];
			} else {
				$query['category_name'] = sanitize_title( $args['category'] );
			}
		}
		if ( ! empty( $args['tag'] ) ) {
			if ( ctype_digit( $args['tag'] ) ) {
				$query['tag_id'] = (int) $args['tag'];
			} else {
				$query['tag'] = sanitize_title( $args['tag'] );
			}
		}

		$q = new WP_Query( $query );
		return array(
			array(
				'total'       => (int) $q->found_posts,
				'total_pages' => (int) $q->max_num_pages,
				'page'        => (int) $query['paged'],
				'items'       => array_map( array( __CLASS__, 'summarize' ), $q->posts ),
			),
			null,
		);
	}

	private static function tool_get_post( array $args ) {
		$post = self::load_post( $args['id'] );
		$text = wp_strip_all_tags( strip_shortcodes( $post->post_content ) );

		$data = self::summarize( $post ) + array(
			'date_gmt'       => $post->post_date_gmt,
			'permalink'      => get_permalink( $post ),
			'preview_link'   => get_preview_post_link( $post ),
			'edit_link'      => get_edit_post_link( $post->ID, 'raw' ),
			'excerpt'        => $post->post_excerpt,
			'content'        => $post->post_content,
			'word_count'     => '' === trim( $text ) ? 0 : count( preg_split( '/\s+/u', trim( $text ) ) ),
			'categories'     => self::terms_of( $post->ID, 'category' ),
			'tags'           => self::terms_of( $post->ID, 'post_tag' ),
			'featured_image' => self::image_info( get_post_thumbnail_id( $post ) ),
			'seo'            => Young_MCP_SEO::get( $post->ID ),
			'created_by_ai'  => (string) get_post_meta( $post->ID, '_young_mcp_created_by', true ),
		);
		return array( $data, null );
	}

	private static function tool_create_post( array $args ) {
		$type   = $args['post_type'] ?? 'post';
		$status = $args['status'] ?? 'draft';

		if ( ! current_user_can( get_post_type_object( $type )->cap->create_posts ) ) {
			self::fail( 'L\'utente collegato al token non può creare contenuti di tipo ' . $type . '.' );
		}
		self::assert_status_allowed( $status, $type );
		$seo = ! empty( $args['seo'] ) ? self::seo_input( $args['seo'] ) : array();

		$postarr = array(
			'post_type'    => $type,
			'post_title'   => $args['title'],
			'post_content' => $args['content'],
			'post_status'  => $status,
			'post_author'  => get_current_user_id(),
		);
		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = $args['excerpt'];
		}
		if ( isset( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['date'] ) ) {
			list( $local, $gmt, $ts ) = self::parse_date( $args['date'] );
			$postarr['post_date']     = $local;
			$postarr['post_date_gmt'] = $gmt;
			if ( 'future' === $status && $ts <= time() ) {
				self::fail( 'Per programmare (status "future") la data deve essere nel futuro.' );
			}
		} elseif ( 'future' === $status ) {
			self::fail( 'Per programmare (status "future") indica la data di pubblicazione.' );
		}

		if ( 'page' === $type && ( isset( $args['categories'] ) || isset( $args['tags'] ) ) ) {
			self::fail( 'Le pagine non hanno categorie né tag.' );
		}
		if ( isset( $args['categories'] ) ) {
			$postarr['post_category'] = self::resolve_categories( $args['categories'] );
		}
		if ( isset( $args['tags'] ) ) {
			$postarr['tags_input'] = self::sanitize_tags( $args['tags'] );
		}
		if ( isset( $args['featured_media'] ) ) {
			self::assert_image( $args['featured_media'] );
		}

		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			self::fail( 'WordPress ha rifiutato il contenuto: ' . $id->get_error_message() );
		}

		$token = Young_MCP_Auth::current();
		update_post_meta( $id, '_young_mcp_created_by', wp_slash( $token ? $token['label'] : 'mcp' ) );

		if ( ! empty( $args['featured_media'] ) ) {
			set_post_thumbnail( $id, $args['featured_media'] );
		}
		if ( $seo ) {
			Young_MCP_SEO::update( $id, $seo );
		}

		$post = get_post( $id );
		$data = self::summarize( $post ) + array(
			'preview_link' => get_preview_post_link( $post ),
			'edit_link'    => get_edit_post_link( $id, 'raw' ),
			'seo'          => Young_MCP_SEO::get( $id ),
		);
		return array( $data, sprintf( '#%d creato (%s) «%s»', $id, $post->post_status, $post->post_title ) );
	}

	private static function tool_update_post( array $args ) {
		$post = self::load_post( $args['id'] );
		self::assert_can_edit_live( $post );
		$seo = ! empty( $args['seo'] ) ? self::seo_input( $args['seo'] ) : array();

		$postarr = array( 'ID' => $post->ID );
		$changed = array();

		foreach ( array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' ) as $arg => $field ) {
			if ( isset( $args[ $arg ] ) ) {
				$postarr[ $field ] = $args[ $arg ];
				$changed[]         = $arg;
			}
		}
		if ( isset( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
			$changed[]            = 'slug';
		}

		$new_status = $args['status'] ?? $post->post_status;
		if ( isset( $args['status'] ) && $args['status'] !== $post->post_status ) {
			self::assert_status_allowed( $args['status'], $post->post_type );
			$postarr['post_status'] = $args['status'];
			$changed[]              = 'status';
		}
		if ( isset( $args['date'] ) ) {
			list( $local, $gmt, $ts ) = self::parse_date( $args['date'] );
			$postarr['post_date']     = $local;
			$postarr['post_date_gmt'] = $gmt;
			$postarr['edit_date']     = true;
			$changed[]                = 'date';
			if ( 'future' === $new_status && $ts <= time() ) {
				self::fail( 'Per programmare (status "future") la data deve essere nel futuro.' );
			}
		} elseif ( 'future' === $new_status && 'future' !== $post->post_status ) {
			self::fail( 'Per programmare (status "future") indica la data di pubblicazione.' );
		}

		if ( 'page' === $post->post_type && ( isset( $args['categories'] ) || isset( $args['tags'] ) ) ) {
			self::fail( 'Le pagine non hanno categorie né tag.' );
		}
		$categories = isset( $args['categories'] ) ? self::resolve_categories( $args['categories'] ) : null;
		if ( isset( $args['featured_media'] ) ) {
			self::assert_image( $args['featured_media'] );
		}

		if ( count( $postarr ) > 1 ) {
			// WordPress stores only the NEW version as a revision: snapshot the current one
			// first (no-op if identical to the latest revision), so every AI edit is reversible.
			if ( wp_revisions_enabled( $post ) ) {
				wp_save_post_revision( $post->ID );
			}
			$result = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $result ) ) {
				self::fail( 'WordPress ha rifiutato la modifica: ' . $result->get_error_message() );
			}
		}
		if ( null !== $categories ) {
			wp_set_post_categories( $post->ID, $categories );
			$changed[] = 'categories';
		}
		if ( isset( $args['tags'] ) ) {
			wp_set_post_tags( $post->ID, self::sanitize_tags( $args['tags'] ), false );
			$changed[] = 'tags';
		}
		if ( isset( $args['featured_media'] ) ) {
			if ( $args['featured_media'] ) {
				set_post_thumbnail( $post->ID, $args['featured_media'] );
			} else {
				delete_post_thumbnail( $post->ID );
			}
			$changed[] = 'featured_media';
		}
		$seo_diff = array();
		if ( $seo ) {
			$seo_diff  = self::seo_diff( Young_MCP_SEO::update( $post->ID, $seo ) );
			$changed[] = 'seo';
		}

		if ( ! $changed ) {
			self::fail( 'Nessun campo da modificare indicato.' );
		}

		$post = get_post( $post->ID );
		$data = self::summarize( $post ) + array(
			'changed'      => $changed,
			'seo_changes'  => $seo_diff,
			'revisions'    => wp_revisions_enabled( $post ) ? count( wp_get_post_revisions( $post->ID, array( 'fields' => 'ids' ) ) ) : 'disattivate sul sito',
			'preview_link' => get_preview_post_link( $post ),
			'edit_link'    => get_edit_post_link( $post->ID, 'raw' ),
		);
		$summary = sprintf( '#%d modificato: %s', $post->ID, implode( ', ', $changed ) );
		if ( $seo_diff ) {
			$summary .= ' | SEO ' . wp_json_encode( $seo_diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		}
		return array( $data, $summary );
	}

	private static function tool_list_terms( array $args ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $args['taxonomy'] ?? 'category',
				'search'     => $args['search'] ?? '',
				'number'     => $args['per_page'] ?? 100,
				'hide_empty' => false,
				'orderby'    => 'count',
				'order'      => 'DESC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			self::fail( $terms->get_error_message() );
		}
		$items = array_map(
			static function ( $t ) {
				return array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'count'  => (int) $t->count,
					'parent' => (int) $t->parent,
				);
			},
			$terms
		);
		return array( array( 'items' => $items ), null );
	}

	private static function tool_list_media( array $args ) {
		$query = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image',
			'posts_per_page' => $args['per_page'] ?? 20,
			'paged'          => $args['page'] ?? 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = $args['search'];
		}
		$q     = new WP_Query( $query );
		$items = array();
		foreach ( $q->posts as $att ) {
			$items[] = array( 'title' => $att->post_title, 'date' => $att->post_date ) + self::image_info( $att->ID );
		}
		return array(
			array(
				'total' => (int) $q->found_posts,
				'items' => $items,
			),
			null,
		);
	}

	private static function tool_upload_image( array $args ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			self::fail( 'L\'utente collegato al token non può caricare file.' );
		}
		$post_id = $args['post_id'] ?? 0;
		if ( $post_id ) {
			$post = self::load_post( $post_id );
			if ( ! empty( $args['set_featured'] ) ) {
				self::assert_can_edit_live( $post );
			}
		} elseif ( ! empty( $args['set_featured'] ) ) {
			self::fail( 'Per impostare l\'immagine in evidenza indica post_id.' );
		}

		$has_url  = isset( $args['url'] ) && '' !== trim( $args['url'] );
		$has_data = isset( $args['data_base64'] ) && '' !== trim( $args['data_base64'] );
		if ( $has_url === $has_data ) {
			self::fail( 'Indica esattamente uno tra url e data_base64.' );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( $has_url ) {
			$url = esc_url_raw( $args['url'], array( 'http', 'https' ) );
			if ( ! $url || ! wp_http_validate_url( $url ) ) {
				self::fail( 'URL non valido o non raggiungibile in modo sicuro.' );
			}
			$tmp = download_url( $url, 30 );
			if ( is_wp_error( $tmp ) ) {
				self::fail( 'Download non riuscito: ' . $tmp->get_error_message() );
			}
			$source = $url;
		} else {
			$url  = '';
			$data = preg_replace( '/^data:[a-z0-9.+\/-]+;base64,/i', '', trim( $args['data_base64'] ) );
			$data = preg_replace( '/\s+/', '', $data );
			// Rough pre-check on the encoded size (base64 is ~4/3 of the bytes).
			if ( strlen( $data ) > wp_max_upload_size() * 4 / 3 + 4 ) {
				self::fail( 'Immagine più grande del limite di caricamento del sito (' . size_format( wp_max_upload_size() ) . ').' );
			}
			$bytes = '' !== $data ? base64_decode( $data, true ) : false; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $bytes || '' === $bytes ) {
				self::fail( 'data_base64 non è un contenuto base64 valido.' );
			}
			$tmp = wp_tempnam( 'young-mcp-upload' );
			if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				if ( $tmp ) {
					wp_delete_file( $tmp );
				}
				self::fail( 'Impossibile scrivere il file temporaneo.' );
			}
			unset( $bytes, $data );
			$source = 'upload-diretto';
		}
		$mime = wp_get_image_mime( $tmp );
		if ( ! $mime || ! isset( self::IMAGE_MIMES[ $mime ] ) ) {
			wp_delete_file( $tmp );
			self::fail( 'Il file ' . ( $has_url ? 'scaricato' : 'caricato' ) . ' non è un\'immagine JPEG, PNG, GIF, WebP o AVIF.' );
		}
		if ( filesize( $tmp ) > wp_max_upload_size() ) {
			wp_delete_file( $tmp );
			self::fail( 'Immagine più grande del limite di caricamento del sito (' . size_format( wp_max_upload_size() ) . ').' );
		}

		$base = ! empty( $args['filename'] ) ? $args['filename'] : ( $has_url ? pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) : 'immagine' );
		$base = sanitize_title( preg_replace( '/\.[a-z0-9]{2,5}$/i', '', $base ) );
		$name = ( '' !== $base ? $base : 'immagine' ) . '.' . self::IMAGE_MIMES[ $mime ];

		$post_data = array();
		if ( isset( $args['caption'] ) ) {
			$post_data['post_excerpt'] = sanitize_text_field( $args['caption'] );
		}
		$title = isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : null;

		$attachment_id = media_handle_sideload( array( 'name' => $name, 'tmp_name' => $tmp ), $post_id, $title, $post_data );
		if ( is_wp_error( $attachment_id ) ) {
			wp_delete_file( $tmp );
			self::fail( 'Caricamento non riuscito: ' . $attachment_id->get_error_message() );
		}

		update_post_meta( $attachment_id, '_wp_attachment_image_alt', wp_slash( sanitize_text_field( $args['alt'] ) ) );
		update_post_meta( $attachment_id, '_young_mcp_source_url', $has_url ? esc_url_raw( $url ) : $source );

		if ( $post_id && ! empty( $args['set_featured'] ) ) {
			set_post_thumbnail( $post_id, $attachment_id );
		}

		$data = self::image_info( $attachment_id ) + array(
			'attached_to' => $post_id,
			'featured'    => $post_id && ! empty( $args['set_featured'] ),
		);
		return array( $data, sprintf( 'immagine #%d da %s%s', $attachment_id, $source, $data['featured'] ? " (in evidenza su #$post_id)" : '' ) );
	}

	private static function tool_update_seo( array $args ) {
		$post = self::load_post( $args['id'] );
		self::assert_can_edit_live( $post );

		$fields = self::seo_input( $args );
		if ( ! $fields ) {
			self::fail( 'Nessun campo SEO indicato.' );
		}
		$diff = self::seo_diff( Young_MCP_SEO::update( $post->ID, $fields ) );

		return array(
			array(
				'id'         => $post->ID,
				'seo_plugin' => Young_MCP_SEO::plugin(),
				'changes'    => $diff,
				'seo'        => Young_MCP_SEO::get( $post->ID ),
			),
			sprintf( '#%d SEO %s', $post->ID, $diff ? wp_json_encode( $diff, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) : 'invariata' ),
		);
	}

	private static function tool_seo_audit( array $args ) {
		$post = self::load_post( $args['id'] );
		return array( Young_MCP_SEO::audit( $post, $args['focus_keyword'] ?? '' ), null );
	}
}
