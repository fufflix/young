<?php
/*
 * Young Archivio: pagine indice e veri 404.
 * Il tema (header.php, riga 5) rimanda a young.it ogni pagina che non è un articolo.
 * Qui intercettiamo home, anni, mesi, categorie, tag, ricerca e 404 prima del tema
 * e mostriamo una pagina semplice, con i link agli articoli.
 */

add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() || ! $q->is_main_query() ) {
		return;
	}
	if ( $q->is_home() || $q->is_date() || $q->is_category() || $q->is_tag() || $q->is_search() ) {
		$q->set( 'post_type', 'post' );
		$q->set( 'posts_per_page', 100 );
	}
} );

add_action( 'template_redirect', function () {
	if ( is_admin() || wp_doing_ajax() || is_feed() || is_robots() || is_singular() || is_embed() ) {
		return;
	}
	$is404 = is_404();
	if ( ! ( $is404 || is_home() || is_front_page() || is_date() || is_category() || is_tag() || is_search() ) ) {
		return;
	}
	if ( $is404 ) {
		status_header( 404 );
		nocache_headers();
	}
	ya_indice_render( $is404 );
	exit;
}, 5 );

if ( ! function_exists( 'ya_indice_conteggi' ) ) {
function ya_indice_conteggi() {
	$c = get_transient( 'ya_indice_conteggi' );
	if ( false === $c ) {
		global $wpdb;
		$c = $wpdb->get_results( "SELECT YEAR(post_date) a, MONTH(post_date) m, COUNT(*) n FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish' GROUP BY a, m ORDER BY a, m", ARRAY_A ); // phpcs:ignore
		set_transient( 'ya_indice_conteggi', $c, DAY_IN_SECONDS );
	}
	return is_array( $c ) ? $c : array();
}
}

if ( ! function_exists( 'ya_indice_mese' ) ) {
function ya_indice_mese( $m ) {
	$mesi = array( 1 => 'gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre' );
	return isset( $mesi[ (int) $m ] ) ? $mesi[ (int) $m ] : '';
}
}

if ( ! function_exists( 'ya_indice_anno_label' ) ) {
function ya_indice_anno_label( $a ) {
	return ( (int) $a < 2000 ) ? 'Senza data' : (string) $a;
}
}

if ( ! function_exists( 'ya_indice_render' ) ) {
function ya_indice_render( $is404 ) {
	$conteggi = ya_indice_conteggi();
	$anni     = array();
	foreach ( $conteggi as $r ) {
		$anni[ (int) $r['a'] ] = ( isset( $anni[ (int) $r['a'] ] ) ? $anni[ (int) $r['a'] ] : 0 ) + (int) $r['n'];
	}
	$base = trailingslashit( home_url() );

	if ( $is404 ) {
		$h1    = 'Pagina non trovata';
		$intro = 'Questa pagina non esiste o non è più disponibile. Puoi cercare tra gli articoli dell’archivio oppure sfogliarli per anno.';
	} elseif ( is_search() ) {
		$h1    = 'Risultati per “' . get_search_query() . '”';
		$intro = '';
	} elseif ( is_category() || is_tag() ) {
		$h1    = single_term_title( '', false );
		$intro = wp_strip_all_tags( term_description() );
	} elseif ( is_month() ) {
		$a     = (int) get_query_var( 'year' );
		$h1    = ( $a < 2000 ) ? 'Articoli senza data' : 'Articoli di ' . ya_indice_mese( get_query_var( 'monthnum' ) ) . ' ' . $a;
		$intro = '';
	} elseif ( is_year() ) {
		$a     = (int) get_query_var( 'year' );
		$h1    = ( $a < 2000 ) ? 'Articoli senza data' : 'Articoli del ' . $a;
		$intro = '';
	} elseif ( is_date() ) {
		$h1    = 'Articoli del ' . get_the_date( 'j F Y' );
		$intro = '';
	} else {
		$h1    = 'Archivio YOUng';
		$intro = 'Gli articoli pubblicati da YOUng dal 2012 al 2021, divisi per anno e per argomento.';
	}

	$titolo = wp_get_document_title();
	?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html( $titolo ); ?></title>
<?php wp_head(); ?>
<style>
body.ya-indice{margin:0;background:#faf8f4;color:#1c1c1c;font:17px/1.6 Georgia,"Times New Roman",serif}
.ya-wrap{max-width:860px;margin:0 auto;padding:24px 16px 64px}
.ya-top{display:flex;justify-content:space-between;align-items:center;gap:12px;border-bottom:2px solid #1c1c1c;padding-bottom:12px;margin-bottom:24px;font-family:Arial,Helvetica,sans-serif;font-size:15px}
.ya-top a{color:#1c1c1c;text-decoration:none;font-weight:700}
.ya-wrap h1{font-size:34px;line-height:1.2;margin:0 0 8px}
.ya-wrap h2{font-size:22px;margin:32px 0 8px}
.ya-intro{color:#444;margin:0 0 20px}
.ya-cerca{display:flex;gap:8px;margin:0 0 24px}
.ya-cerca input[type=search]{flex:1;min-width:0;padding:10px;border:1px solid #999;font-size:16px}
.ya-cerca button{padding:10px 16px;border:0;background:#1c1c1c;color:#fff;font-size:16px;cursor:pointer}
.ya-griglia{list-style:none;padding:0;margin:0;display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:8px}
.ya-griglia a{display:block;padding:10px 12px;background:#fff;border:1px solid #ddd;color:#1c1c1c;text-decoration:none}
.ya-griglia a:hover{border-color:#1c1c1c}
.ya-griglia small{display:block;color:#666;font-family:Arial,Helvetica,sans-serif;font-size:13px}
.ya-lista{list-style:none;padding:0;margin:0}
.ya-lista li{padding:10px 0;border-bottom:1px solid #e3ded4}
.ya-lista a{color:#1c1c1c;text-decoration:none;font-weight:700}
.ya-lista a:hover{text-decoration:underline}
.ya-lista time{display:block;color:#666;font-family:Arial,Helvetica,sans-serif;font-size:13px}
.ya-pag{margin:24px 0;font-family:Arial,Helvetica,sans-serif}
.ya-pag .page-numbers{display:inline-block;padding:6px 10px;margin:2px;border:1px solid #ccc;color:#1c1c1c;text-decoration:none}
.ya-pag .current{background:#1c1c1c;color:#fff;border-color:#1c1c1c}
.ya-sponsor{margin:0 0 24px;text-align:center}
.ya-sponsor__t{margin:0 0 4px;font-family:Arial,Helvetica,sans-serif;font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:#777}
.ya-sponsor img{display:block;width:100%;height:auto}
.ya-piede{margin-top:48px;padding-top:12px;border-top:1px solid #ccc;font-family:Arial,Helvetica,sans-serif;font-size:14px;color:#555}
.ya-piede a{color:#1c1c1c}
</style>
</head>
<body class="ya-indice">
<div class="ya-wrap">
<header class="ya-top"><a href="https://young.it/">YOUng</a><a href="<?php echo esc_url( $base ); ?>">Archivio</a></header>
<div class="site-content">
<main>
<h1><?php echo esc_html( $h1 ); ?></h1>
<?php if ( $intro ) : ?><p class="ya-intro"><?php echo esc_html( $intro ); ?></p><?php endif; ?>
<form class="ya-cerca" role="search" method="get" action="<?php echo esc_url( $base ); ?>">
<input type="search" name="s" value="<?php echo esc_attr( get_search_query() ); ?>" placeholder="Cerca nell’archivio" aria-label="Cerca nell’archivio">
<button type="submit">Cerca</button>
</form>
<?php
	if ( $is404 || ( is_home() && ! is_paged() ) ) {
		$sezioni = array();
		foreach ( array( 'blog', 'news', 'culture' ) as $slug ) {
			$t = get_term_by( 'slug', $slug, 'category' );
			if ( $t && $t->count > 0 ) {
				$sezioni[] = $t;
			}
		}
		if ( $sezioni ) {
			echo '<h2>Le sezioni storiche</h2><ul class="ya-griglia">';
			foreach ( $sezioni as $t ) {
				echo '<li><a href="' . esc_url( get_term_link( $t ) ) . '">' . esc_html( $t->name ) . '<small>' . esc_html( number_format_i18n( $t->count ) ) . ' articoli</small></a></li>';
			}
			echo '</ul>';
		}
		echo '<h2>Per anno</h2><ul class="ya-griglia">';
		foreach ( $anni as $a => $n ) {
			echo '<li><a href="' . esc_url( $base . $a . '/' ) . '">' . esc_html( ya_indice_anno_label( $a ) ) . '<small>' . esc_html( number_format_i18n( $n ) ) . ' articoli</small></a></li>';
		}
		echo '</ul>';
		$escludi = array( (int) get_option( 'default_category' ) );
		foreach ( $sezioni as $t ) {
			$escludi[] = (int) $t->term_id;
		}
		$cats = get_terms( array( 'taxonomy' => 'category', 'orderby' => 'count', 'order' => 'DESC', 'number' => 40, 'hide_empty' => true, 'exclude' => $escludi ) );
		if ( ! is_wp_error( $cats ) && $cats ) {
			echo '<h2>Per argomento</h2><ul class="ya-griglia">';
			foreach ( $cats as $t ) {
				echo '<li><a href="' . esc_url( get_term_link( $t ) ) . '">' . esc_html( $t->name ) . '<small>' . esc_html( number_format_i18n( $t->count ) ) . ' articoli</small></a></li>';
			}
			echo '</ul>';
		}
	} elseif ( is_year() ) {
		$a = (int) get_query_var( 'year' );
		echo '<h2>Per mese</h2><ul class="ya-griglia">';
		foreach ( $conteggi as $r ) {
			if ( (int) $r['a'] !== $a ) {
				continue;
			}
			$label = ( $a < 2000 ) ? 'Senza data' : ucfirst( ya_indice_mese( $r['m'] ) );
			echo '<li><a href="' . esc_url( $base . $a . '/' . sprintf( '%02d', (int) $r['m'] ) . '/' ) . '">' . esc_html( $label ) . '<small>' . esc_html( number_format_i18n( (int) $r['n'] ) ) . ' articoli</small></a></li>';
		}
		echo '</ul>';
	} else {
		if ( have_posts() ) {
			echo '<ul class="ya-lista">';
			while ( have_posts() ) {
				the_post();
				$d = ( (int) get_the_date( 'Y' ) < 2000 ) ? '' : get_the_date( 'j F Y' );
				echo '<li><a href="' . esc_url( get_permalink() ) . '">' . esc_html( wp_strip_all_tags( get_the_title() ) ) . '</a>' . ( $d ? '<time datetime="' . esc_attr( get_the_date( 'c' ) ) . '">' . esc_html( $d ) . '</time>' : '' ) . '</li>';
			}
			echo '</ul>';
			$pag = paginate_links( array( 'type' => 'plain', 'prev_text' => '‹ Precedenti', 'next_text' => 'Successivi ›' ) );
			if ( $pag ) {
				echo '<nav class="ya-pag" aria-label="Pagine">' . $pag . '</nav>'; // phpcs:ignore
			}
		} else {
			echo '<p>Nessun articolo trovato.</p>';
		}
	}
?>
</main>
</div>
<footer class="ya-piede">Archivio di <a href="https://young.it/">YOUng</a>, testata giornalistica di Mastino S.R.L.</footer>
</div>
<?php wp_footer(); ?>
</body>
</html>
<?php
}
}
