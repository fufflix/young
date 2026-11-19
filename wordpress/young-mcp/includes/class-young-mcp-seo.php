<?php
/**
 * SEO fields through whichever SEO plugin is active (Yoast SEO or Rank Math),
 * with a minimal built-in fallback when none is installed; plus a heuristic audit.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_SEO {

	const FIELDS = array( 'title', 'description', 'focus_keyword', 'canonical', 'noindex', 'og_title', 'og_description', 'og_image' );

	const META = array(
		'yoast'    => array(
			'title'          => '_yoast_wpseo_title',
			'description'    => '_yoast_wpseo_metadesc',
			'focus_keyword'  => '_yoast_wpseo_focuskw',
			'canonical'      => '_yoast_wpseo_canonical',
			'noindex'        => '_yoast_wpseo_meta-robots-noindex',
			'og_title'       => '_yoast_wpseo_opengraph-title',
			'og_description' => '_yoast_wpseo_opengraph-description',
			'og_image'       => '_yoast_wpseo_opengraph-image',
		),
		'rankmath' => array(
			'title'          => 'rank_math_title',
			'description'    => 'rank_math_description',
			'focus_keyword'  => 'rank_math_focus_keyword',
			'canonical'      => 'rank_math_canonical_url',
			'noindex'        => 'rank_math_robots',
			'og_title'       => 'rank_math_facebook_title',
			'og_description' => 'rank_math_facebook_description',
			'og_image'       => 'rank_math_facebook_image',
		),
		'none'     => array(
			'title'          => '_young_mcp_seo_title',
			'description'    => '_young_mcp_seo_description',
			'focus_keyword'  => '_young_mcp_seo_focus_keyword',
			'canonical'      => '_young_mcp_seo_canonical',
			'noindex'        => '_young_mcp_seo_noindex',
			'og_title'       => '_young_mcp_seo_og_title',
			'og_description' => '_young_mcp_seo_og_description',
			'og_image'       => '_young_mcp_seo_og_image',
		),
	);

	public static function init() {
		// Front-end output only when no SEO plugin handles it.
		add_action( 'wp', array( __CLASS__, 'maybe_fallback_output' ) );
	}

	public static function plugin() {
		if ( defined( 'WPSEO_VERSION' ) ) {
			return 'yoast';
		}
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return 'rankmath';
		}
		return 'none';
	}

	public static function get( $post_id ) {
		$plugin = self::plugin();
		$out    = array( 'plugin' => $plugin );
		foreach ( self::META[ $plugin ] as $field => $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( 'noindex' === $field ) {
				$out[ $field ] = self::read_noindex( $plugin, $value );
			} else {
				$out[ $field ] = is_string( $value ) ? $value : '';
			}
		}
		return $out;
	}

	/**
	 * Updates the given fields. An empty string resets a field to the plugin default.
	 * Returns array( 'before' => ..., 'after' => ... ).
	 */
	public static function update( $post_id, array $fields ) {
		$plugin = self::plugin();
		$before = self::get( $post_id );

		foreach ( $fields as $field => $value ) {
			if ( ! isset( self::META[ $plugin ][ $field ] ) ) {
				continue;
			}
			$key = self::META[ $plugin ][ $field ];

			if ( 'noindex' === $field ) {
				self::write_noindex( $plugin, $post_id, $key, (bool) $value );
				continue;
			}
			if ( 'og_image' === $field ) {
				self::write_og_image( $plugin, $post_id, $value );
				continue;
			}

			$value = 'canonical' === $field ? esc_url_raw( (string) $value ) : sanitize_text_field( (string) $value );
			if ( '' === $value ) {
				delete_post_meta( $post_id, $key );
			} else {
				update_post_meta( $post_id, $key, wp_slash( $value ) );
			}
		}

		self::refresh_plugin_cache( $plugin, $post_id );
		return array(
			'before' => $before,
			'after'  => self::get( $post_id ),
		);
	}

	/**
	 * Normalizes an og_image value: attachment ID (int or digits) or image URL.
	 * Returns array( 'id' => int, 'url' => string ), '' to reset, or an error
	 * message (non-empty string) when the value is not usable.
	 */
	public static function resolve_og_image( $value ) {
		if ( is_array( $value ) ) {
			return $value; // Already resolved.
		}
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			$id = (int) $value;
			if ( $id <= 0 ) {
				return 'og_image: ID immagine non valido.';
			}
			$url = wp_attachment_is_image( $id ) ? wp_get_attachment_url( $id ) : false;
			if ( ! $url ) {
				return "og_image: $id non è un'immagine della libreria media.";
			}
			return array(
				'id'  => $id,
				'url' => $url,
			);
		}
		$value = trim( (string) $value );
		if ( '' === $value ) {
			return array();
		}
		$url = esc_url_raw( $value, array( 'http', 'https' ) );
		if ( ! $url ) {
			return 'og_image: indica l\'ID di un\'immagine della libreria media o un URL http(s).';
		}
		$id = attachment_url_to_postid( $url );
		return array(
			'id'  => ( $id && wp_attachment_is_image( $id ) ) ? (int) $id : 0,
			'url' => $url,
		);
	}

	private static function write_og_image( $plugin, $post_id, $value ) {
		$image = self::resolve_og_image( $value );
		if ( is_string( $image ) ) {
			throw new Young_MCP_Tool_Error( $image );
		}
		$pairs = array(
			'yoast'    => array(
				'_yoast_wpseo_opengraph-image' => '_yoast_wpseo_opengraph-image-id',
				'_yoast_wpseo_twitter-image'   => '_yoast_wpseo_twitter-image-id',
			),
			'rankmath' => array( 'rank_math_facebook_image' => 'rank_math_facebook_image_id' ),
			'none'     => array( '_young_mcp_seo_og_image' => '' ),
		);
		foreach ( $pairs[ $plugin ] as $url_key => $id_key ) {
			if ( ! $image ) {
				delete_post_meta( $post_id, $url_key );
				if ( $id_key ) {
					delete_post_meta( $post_id, $id_key );
				}
				continue;
			}
			update_post_meta( $post_id, $url_key, wp_slash( $image['url'] ) );
			if ( $id_key ) {
				if ( $image['id'] ) {
					update_post_meta( $post_id, $id_key, (string) $image['id'] );
				} else {
					delete_post_meta( $post_id, $id_key );
				}
			}
		}
		if ( 'rankmath' === $plugin ) {
			if ( $image ) {
				update_post_meta( $post_id, 'rank_math_twitter_use_facebook', 'on' );
			}
		}
	}

	private static function read_noindex( $plugin, $value ) {
		if ( 'yoast' === $plugin ) {
			return '1' === (string) $value; // 1 = noindex, 2 = index, empty = default.
		}
		if ( 'rankmath' === $plugin ) {
			return is_array( $value ) && in_array( 'noindex', $value, true );
		}
		return '1' === (string) $value;
	}

	private static function write_noindex( $plugin, $post_id, $key, $noindex ) {
		if ( 'rankmath' === $plugin ) {
			$robots = get_post_meta( $post_id, $key, true );
			$robots = is_array( $robots ) ? array_values( array_diff( $robots, array( 'index', 'noindex' ) ) ) : array();
			array_unshift( $robots, $noindex ? 'noindex' : 'index' );
			update_post_meta( $post_id, $key, $robots );
			return;
		}
		if ( $noindex ) {
			update_post_meta( $post_id, $key, '1' );
		} else {
			delete_post_meta( $post_id, $key ); // Back to the site default.
		}
	}

	/**
	 * Yoast serves SEO data from its "indexables" table, not directly from post meta:
	 * rebuild the indexable so the new values show up on the site without a manual save.
	 */
	private static function refresh_plugin_cache( $plugin, $post_id ) {
		clean_post_cache( $post_id );
		if ( 'yoast' !== $plugin || ! function_exists( 'YoastSEO' ) ) {
			return;
		}
		try {
			$builder_class = '\Yoast\WP\SEO\Builders\Indexable_Builder';
			$repo_class    = '\Yoast\WP\SEO\Repositories\Indexable_Repository';
			if ( class_exists( $builder_class ) && class_exists( $repo_class ) ) {
				$repo      = YoastSEO()->classes->get( $repo_class );
				$builder   = YoastSEO()->classes->get( $builder_class );
				$indexable = $repo->find_by_id_and_type( $post_id, 'post', false );
				$builder->build_for_id_and_type( $post_id, 'post', $indexable );
			}
		} catch ( \Throwable $e ) {
			// Non-fatal: values are saved in post meta; Yoast resyncs on the next save.
			unset( $e );
		}
	}

	/* ---------------------------------------------------------------------
	 * Fallback output (no SEO plugin installed)
	 * ------------------------------------------------------------------- */

	public static function maybe_fallback_output() {
		if ( 'none' !== self::plugin() || ! is_singular() ) {
			return;
		}
		$post_id = get_queried_object_id();
		$seo     = self::get( $post_id );

		if ( '' !== $seo['title'] ) {
			add_filter(
				'pre_get_document_title',
				static function () use ( $seo ) {
					return $seo['title'];
				}
			);
		}
		if ( $seo['noindex'] ) {
			add_filter( 'wp_robots', 'wp_robots_no_robots' );
		}
		if ( '' !== $seo['canonical'] ) {
			add_filter(
				'get_canonical_url',
				static function () use ( $seo ) {
					return $seo['canonical'];
				}
			);
		}
		add_action(
			'wp_head',
			static function () use ( $seo ) {
				if ( '' !== $seo['description'] ) {
					echo '<meta name="description" content="' . esc_attr( $seo['description'] ) . '" />' . "\n";
				}
				$og = array(
					'og:title'       => $seo['og_title'],
					'og:description' => $seo['og_description'],
					'og:image'       => $seo['og_image'],
				);
				foreach ( $og as $property => $content ) {
					if ( '' !== $content ) {
						$content = 'og:image' === $property ? esc_url( $content ) : esc_attr( $content );
						echo '<meta property="' . esc_attr( $property ) . '" content="' . $content . '" />' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					}
				}
			},
			1
		);
	}

	/* ---------------------------------------------------------------------
	 * Audit
	 * ------------------------------------------------------------------- */

	/**
	 * Heuristic checks (common editorial SEO guidelines, not Google rules).
	 */
	public static function audit( WP_Post $post, $focus_keyword = '' ) {
		$seo     = self::get( $post->ID );
		$keyword = trim( '' !== $focus_keyword ? $focus_keyword : $seo['focus_keyword'] );
		$content = (string) $post->post_content;
		$text    = trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( strip_shortcodes( $content ) ) ) );
		$words   = '' === $text ? array() : preg_split( '/\s+/u', $text );
		$wcount  = count( $words );

		$dom = self::parse( $content );
		$h1  = $dom ? $dom->getElementsByTagName( 'h1' )->length : 0;
		$h2  = $dom ? $dom->getElementsByTagName( 'h2' )->length : 0;
		$h3  = $dom ? $dom->getElementsByTagName( 'h3' )->length : 0;

		$images      = array();
		$missing_alt = array();
		$internal    = 0;
		$external    = 0;
		$home_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$home_host   = preg_replace( '/^www\./', '', (string) $home_host );

		if ( $dom ) {
			foreach ( $dom->getElementsByTagName( 'img' ) as $img ) {
				$src      = $img->getAttribute( 'src' );
				$images[] = $src;
				if ( '' === trim( $img->getAttribute( 'alt' ) ) ) {
					$missing_alt[] = $src;
				}
			}
			foreach ( $dom->getElementsByTagName( 'a' ) as $a ) {
				$href = trim( $a->getAttribute( 'href' ) );
				if ( '' === $href || '#' === $href[0] || 0 === stripos( $href, 'mailto:' ) || 0 === stripos( $href, 'tel:' ) ) {
					continue;
				}
				$host = wp_parse_url( $href, PHP_URL_HOST );
				if ( ! $host || preg_replace( '/^www\./', '', strtolower( $host ) ) === strtolower( $home_host ) ) {
					++$internal;
				} else {
					++$external;
				}
			}
		}

		// Titles containing template variables (%%title%% in Yoast, %title% in Rank Math) cannot be measured here.
		$custom_title = '' !== $seo['title'] && ! preg_match( '/%%?[a-z_]+%%?/i', $seo['title'] );

		// Drafts have no slug until published: evaluate the one WordPress would generate.
		$slug       = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		$slug_label = '' !== $post->post_name ? $slug : $slug . ' (provvisorio, generato dal titolo)';
		$title        = $custom_title ? $seo['title'] : $post->post_title;
		$title_len    = mb_strlen( $title );
		$desc_len     = mb_strlen( $seo['description'] );

		$checks = array();
		$add    = static function ( $id, $status, $message ) use ( &$checks ) {
			$checks[] = array(
				'check'   => $id,
				'status'  => $status,
				'message' => $message,
			);
		};

		$add(
			'title_length',
			( $title_len >= 30 && $title_len <= 60 ) ? 'ok' : 'warn',
			sprintf( '%s di %d caratteri (indicativo: 30-60, oltre ~60 Google tende a troncare).', $custom_title ? 'Titolo SEO' : 'Titolo articolo (nessun titolo SEO personalizzato)', $title_len )
		);

		if ( 0 === $desc_len ) {
			$add( 'meta_description', 'fail', 'Meta description assente: Google userà un estratto automatico.' );
		} else {
			$add(
				'meta_description',
				( $desc_len >= 120 && $desc_len <= 160 ) ? 'ok' : 'warn',
				sprintf( 'Meta description di %d caratteri (indicativo: 120-160).', $desc_len )
			);
		}

		if ( '' === $keyword ) {
			$add( 'focus_keyword', 'warn', 'Nessuna keyword principale impostata: i controlli sulla keyword sono saltati.' );
		} else {
			$kw          = self::normalize( $keyword );
			$first_words = self::normalize( implode( ' ', array_slice( $words, 0, 100 ) ) );
			$occurrences = '' === $kw ? 0 : substr_count( self::normalize( $text ), $kw );
			$density     = $wcount ? round( $occurrences * count( preg_split( '/\s+/u', $keyword ) ) / $wcount * 100, 2 ) : 0;
			$slug_kw     = sanitize_title( $keyword );

			$add( 'keyword_in_title', false !== strpos( self::normalize( $title ), $kw ) ? 'ok' : 'warn', 'Keyword nel titolo SEO/articolo.' );
			$add( 'keyword_in_description', false !== strpos( self::normalize( $seo['description'] ), $kw ) ? 'ok' : 'warn', 'Keyword nella meta description.' );
			$add( 'keyword_in_slug', false !== strpos( $slug, $slug_kw ) ? 'ok' : 'warn', 'Keyword nello slug (URL): "' . $slug_label . '".' );
			$add( 'keyword_in_intro', false !== strpos( $first_words, $kw ) ? 'ok' : 'warn', 'Keyword nelle prime 100 parole.' );
			$add(
				'keyword_density',
				( $density >= 0.5 && $density <= 2.5 ) ? 'ok' : 'warn',
				sprintf( 'Keyword trovata %d volte, densità %.2f%% (indicativo 0,5-2,5%%; evitare keyword stuffing).', $occurrences, $density )
			);
		}

		$add( 'word_count', $wcount >= 300 ? 'ok' : 'warn', sprintf( '%d parole (sotto 300 il testo rischia di essere considerato poco approfondito; per le brevi di cronaca può essere accettabile).', $wcount ) );
		$add( 'h1_in_content', 0 === $h1 ? 'ok' : 'warn', 0 === $h1 ? 'Nessun H1 nel corpo (corretto: il titolo è già H1).' : sprintf( '%d H1 nel corpo: trasformali in H2.', $h1 ) );
		$add( 'subheadings', ( $wcount < 400 || $h2 + $h3 > 0 ) ? 'ok' : 'warn', sprintf( '%d sottotitoli (%d H2, %d H3).', $h2 + $h3, $h2, $h3 ) );
		$add( 'image_alt', $missing_alt ? 'warn' : 'ok', sprintf( '%d immagini nel corpo, %d senza testo alternativo.', count( $images ), count( $missing_alt ) ) );
		$add( 'internal_links', $internal > 0 ? 'ok' : 'warn', sprintf( '%d link interni.', $internal ) );
		$add( 'external_links', $external > 0 ? 'ok' : 'warn', sprintf( '%d link esterni (le fonti autorevoli citate rafforzano verificabilità e credibilità).', $external ) );
		$add( 'featured_image', has_post_thumbnail( $post ) ? 'ok' : 'warn', has_post_thumbnail( $post ) ? 'Immagine in evidenza presente.' : 'Immagine in evidenza assente (serve per anteprime social e Google Discover).' );
		$add( 'excerpt', '' !== trim( $post->post_excerpt ) ? 'ok' : 'warn', '' !== trim( $post->post_excerpt ) ? 'Riassunto presente.' : 'Riassunto (excerpt) assente.' );
		$add( 'slug_length', strlen( $slug ) <= 75 ? 'ok' : 'warn', sprintf( 'Slug "%s" di %d caratteri.', $slug_label, strlen( $slug ) ) );
		if ( 'publish' === $post->post_status && $seo['noindex'] ) {
			$add( 'noindex', 'fail', 'Articolo pubblicato ma impostato su noindex: non apparirà su Google.' );
		}

		$summary = array(
			'ok'   => 0,
			'warn' => 0,
			'fail' => 0,
		);
		foreach ( $checks as $c ) {
			++$summary[ $c['status'] ];
		}

		return array(
			'post_id'            => $post->ID,
			'focus_keyword'      => $keyword,
			'seo_plugin'         => $seo['plugin'],
			'summary'            => $summary,
			'checks'             => $checks,
			'images_missing_alt' => array_slice( $missing_alt, 0, 20 ),
			'note'               => 'Controlli euristici basati su linee guida editoriali diffuse (Yoast, Rank Math, Google Search Central): sono indicazioni, non regole ufficiali di ranking.',
		);
	}

	private static function normalize( $s ) {
		return mb_strtolower( remove_accents( wp_strip_all_tags( (string) $s ) ) );
	}

	private static function parse( $html ) {
		if ( '' === trim( $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom      = new DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="utf-8"?><div>' . $html . '</div>', LIBXML_NONET );
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		return $dom;
	}
}
