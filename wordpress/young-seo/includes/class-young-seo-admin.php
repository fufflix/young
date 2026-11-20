<?php
/**
 * Settings page (Impostazioni → Young SEO) and per-post meta box.
 */

defined( 'ABSPATH' ) || exit;

class Young_SEO_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_post', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_post', array( __CLASS__, 'save_meta' ), 10, 2 );
	}

	public static function menu() {
		add_options_page( 'Young SEO', 'Young SEO', 'manage_options', 'young-seo', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		register_setting( 'young_seo', Young_SEO::OPTION, array( 'sanitize_callback' => array( 'Young_SEO', 'sanitize' ) ) );
	}

	public static function meta_box() {
		add_meta_box( 'young-seo-ai', 'Intelligenza artificiale', array( __CLASS__, 'render_meta_box' ), 'post', 'side', 'default', array( '__back_compat_meta_box' => false ) );
	}

	public static function render_meta_box( $post ) {
		$flag   = get_post_meta( $post->ID, '_young_ai_disclosure', true );
		$by_mcp = metadata_exists( 'post', $post->ID, '_young_mcp_created_by' );
		$value  = '' === $flag ? ( $by_mcp ? '1' : '0' ) : $flag;
		wp_nonce_field( 'young_seo_meta', 'young_seo_meta_nonce' );
		echo '<input type="hidden" name="young_ai_disclosure_present" value="1">';
		printf(
			'<label><input type="checkbox" name="young_ai_disclosure" value="1" %s> Mostra la nota sull\'uso dell\'intelligenza artificiale</label>',
			checked( '1', $value, false )
		);
		if ( $by_mcp ) {
			echo '<p class="description">Articolo creato tramite Young MCP: la nota è mostrata salvo che tu tolga la spunta.</p>';
		}
	}

	public static function save_meta( $post_id, $post ) {
		if ( ! isset( $_POST['young_seo_meta_nonce'], $_POST['young_ai_disclosure_present'] )
			|| ! wp_verify_nonce( sanitize_key( $_POST['young_seo_meta_nonce'] ), 'young_seo_meta' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$checked = ! empty( $_POST['young_ai_disclosure'] ) ? '1' : '0';
		$by_mcp  = metadata_exists( 'post', $post_id, '_young_mcp_created_by' );
		$current = get_post_meta( $post_id, '_young_ai_disclosure', true );
		// Keep the meta empty when the box only confirms the default.
		if ( '' === $current && ( ( $by_mcp && '1' === $checked ) || ( ! $by_mcp && '0' === $checked ) ) ) {
			return;
		}
		update_post_meta( $post_id, '_young_ai_disclosure', $checked );
	}

	private static function checkbox( $key, $label ) {
		printf(
			'<label><input type="checkbox" name="%1$s[%2$s]" value="1" %3$s> %4$s</label>',
			esc_attr( Young_SEO::OPTION ),
			esc_attr( $key ),
			checked( Young_SEO::enabled( $key ), true, false ),
			esc_html( $label )
		);
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$opt  = Young_SEO::OPTION;
		$scan = null;
		if ( isset( $_POST['young_seo_scan'] ) && check_admin_referer( 'young_seo_scan' ) ) {
			$scan = Young_SEO_Domain::scan();
		}
		?>
		<div class="wrap">
			<h1>Young SEO</h1>
			<p>Aggiunte editoriali compatibili con Yoast SEO: il plugin non stampa meta tag, dati strutturati o sitemap e non modifica i contenuti nel database.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'young_seo' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Nota sull'intelligenza artificiale</th>
						<td>
							<?php self::checkbox( 'ai_disclosure', 'Mostra la nota in fondo agli articoli creati con l\'AI' ); ?>
							<p class="description">Compare negli articoli creati tramite Young MCP e in quelli con la spunta nel riquadro "Intelligenza artificiale" dell'editor; togliendo la spunta la nota viene nascosta.</p>
							<p><textarea name="<?php echo esc_attr( $opt ); ?>[disclosure_text]" rows="3" class="large-text"><?php echo esc_textarea( Young_SEO::get( 'disclosure_text' ) ); ?></textarea></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Data di aggiornamento</th>
						<td><?php self::checkbox( 'updated_date', 'Mostra "Aggiornato il …" se l\'articolo è stato modificato più di 24 ore dopo la pubblicazione' ); ?></td>
					</tr>
					<tr>
						<th scope="row">Box autore</th>
						<td>
							<?php self::checkbox( 'author_box', 'Mostra il box autore in fondo agli articoli' ); ?>
							<p class="description">Avatar, nome, biografia e link dal profilo utente (sito web, X, Facebook, LinkedIn, Instagram, YouTube). Lascialo spento se il tema mostra già un box autore.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Vecchio dominio</th>
						<td>
							<?php self::checkbox( 'domain_fix', 'Sostituisci al volo i link al vecchio dominio con quello attuale' ); ?>
							<p><input type="text" name="<?php echo esc_attr( $opt ); ?>[old_hosts]" value="<?php echo esc_attr( Young_SEO::get( 'old_hosts' ) ); ?>" class="regular-text"></p>
							<p class="description">Host separati da virgola. Vale per testo degli articoli, URL delle immagini, immagine social, canonical e URL Open Graph di Yoast. Il database non viene modificato.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">llms.txt</th>
						<td>
							<?php self::checkbox( 'llms', 'Pubblica /llms.txt (guida al sito per gli assistenti AI)' ); ?>
							<?php if ( Young_SEO::enabled( 'llms' ) ) : ?>
								<a href="<?php echo esc_url( home_url( '/llms.txt' ) ); ?>" target="_blank">Apri</a>
							<?php endif; ?>
							<p class="description">Nota sulla linea editoriale:</p>
							<p><textarea name="<?php echo esc_attr( $opt ); ?>[llms_policy]" rows="3" class="large-text"><?php echo esc_textarea( Young_SEO::get( 'llms_policy' ) ); ?></textarea></p>
						</td>
					</tr>
					<tr>
						<th scope="row">Meta description del tema</th>
						<td>
							<?php self::checkbox( 'theme_meta_fix', 'Evita meta description duplicata del tema' ); ?>
							<p class="description">Con Yoast o Rank Math attivo rimuove la meta description che il tema Hello Elementor ricava dal riassunto.</p>
						</td>
					</tr>
					<tr>
						<th scope="row">Sitemap Google News</th>
						<td>
							<?php self::checkbox( 'news_sitemap', 'Pubblica /news-sitemap.xml (articoli delle ultime 48 ore)' ); ?>
							<?php if ( Young_SEO::enabled( 'news_sitemap' ) ) : ?>
								<a href="<?php echo esc_url( Young_SEO_News::url() ); ?>" target="_blank">Apri</a>
							<?php endif; ?>
							<p><label>Nome della testata <input type="text" name="<?php echo esc_attr( $opt ); ?>[news_name]" value="<?php echo esc_attr( Young_SEO::get( 'news_name' ) ); ?>" class="regular-text"></label></p>
							<p class="description">Esclusi gli articoli con "noindex" in Yoast. Con Yoast attivo la sitemap viene aggiunta al suo indice (sitemap_index.xml); senza Yoast viene indicata in robots.txt.</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2>Scansione vecchio dominio</h2>
			<p>Conta (in sola lettura) le righe del database che contengono ancora gli host indicati. Nessuna modifica viene fatta.</p>
			<form method="post">
				<?php wp_nonce_field( 'young_seo_scan' ); ?>
				<?php submit_button( 'Scansione', 'secondary', 'young_seo_scan', false ); ?>
			</form>
			<?php if ( null !== $scan ) : ?>
				<?php if ( ! $scan ) : ?>
					<p>Nessun host da cercare.</p>
				<?php else : ?>
					<table class="widefat striped young-seo-scan" style="max-width:700px;margin-top:1em">
						<thead><tr><th>Host</th><th>Dove</th><th>Righe</th></tr></thead>
						<tbody>
						<?php foreach ( $scan as $host => $counts ) : ?>
							<?php foreach ( $counts as $where => $count ) : ?>
								<tr><td><?php echo esc_html( $host ); ?></td><td><code><?php echo esc_html( $where ); ?></code></td><td class="young-seo-count"><?php echo (int) $count; ?></td></tr>
							<?php endforeach; ?>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
			<?php endif; ?>
		</div>
		<?php
	}
}
