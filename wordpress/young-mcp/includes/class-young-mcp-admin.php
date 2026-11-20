<?php
/**
 * Settings → Young MCP: endpoint, editorial permissions, tokens, activity log.
 */

defined( 'ABSPATH' ) || exit;

class Young_MCP_Admin {

	const SLUG = 'young-mcp';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_young_mcp_create_token', array( __CLASS__, 'create_token' ) );
		add_action( 'admin_post_young_mcp_revoke_token', array( __CLASS__, 'revoke_token' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( YOUNG_MCP_DIR . 'young-mcp.php' ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'Young MCP', 'Young MCP', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">Impostazioni</a>' );
		return $links;
	}

	public static function register_settings() {
		register_setting(
			'young_mcp',
			Young_MCP_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( 'Young_MCP_Settings', 'sanitize' ),
				'default'           => Young_MCP_Settings::defaults(),
			)
		);
	}

	private static function page_url() {
		return admin_url( 'options-general.php?page=' . self::SLUG );
	}

	public static function create_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		check_admin_referer( 'young_mcp_create_token' );

		$label   = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
		$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
		$user    = get_userdata( $user_id );

		// Never bind a token to an administrator: a leaked token must not control the site.
		if ( '' === $label || ! $user || ! user_can( $user, 'edit_posts' ) || user_can( $user, 'manage_options' ) ) {
			wp_safe_redirect( add_query_arg( 'young_mcp_error', 'token', self::page_url() ) );
			exit;
		}

		$token = Young_MCP_Auth::create( $label, $user_id );
		// Shown once on the next page load, then gone.
		set_transient( 'young_mcp_new_token_' . get_current_user_id(), $token, 120 );
		wp_safe_redirect( add_query_arg( 'young_mcp_created', '1', self::page_url() ) );
		exit;
	}

	public static function revoke_token() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permesso negato.' );
		}
		$id = isset( $_POST['token_id'] ) ? sanitize_key( wp_unslash( $_POST['token_id'] ) ) : '';
		check_admin_referer( 'young_mcp_revoke_' . $id );
		Young_MCP_Auth::revoke( $id );
		wp_safe_redirect( add_query_arg( 'young_mcp_revoked', '1', self::page_url() ) );
		exit;
	}

	private static function date( $timestamp ) {
		return $timestamp ? wp_date( 'd/m/Y H:i', $timestamp ) : '—';
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$endpoint  = Young_MCP_Server::endpoint_url();
		$fallback  = add_query_arg( 'rest_route', '/' . Young_MCP_Server::NS . Young_MCP_Server::ROUTE, home_url( '/' ) );
		$new_token = get_transient( 'young_mcp_new_token_' . get_current_user_id() );
		if ( $new_token ) {
			delete_transient( 'young_mcp_new_token_' . get_current_user_id() );
		}
		$tokens = Young_MCP_Auth::all();
		$log    = array_slice( Young_MCP_Log::all(), 0, 100 );
		?>
		<div class="wrap">
			<h1>Young MCP</h1>
			<p>Server <a href="https://modelcontextprotocol.io" target="_blank" rel="noopener">MCP</a> per assistenti AI (Claude Code, Codex). Ogni token agisce con i permessi dell'utente WordPress a cui è collegato: usa un utente dedicato con ruolo <strong>Editore</strong> o <strong>Autore</strong>, mai un amministratore.</p>

			<?php if ( isset( $_GET['young_mcp_error'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-error"><p>Indica un nome e un utente che possa scrivere articoli e che <strong>non</strong> sia amministratore (crea un utente dedicato con ruolo Editore da Utenti → Aggiungi nuovo).</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['young_mcp_revoked'] ) ) : // phpcs:ignore ?>
				<div class="notice notice-success"><p>Token revocato: smette di funzionare immediatamente.</p></div>
			<?php endif; ?>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-warning" style="padding:12px 16px">
					<p><strong>Nuovo token — copialo ora: non sarà più mostrato.</strong></p>
					<p><input type="text" readonly class="large-text code" value="<?php echo esc_attr( $new_token ); ?>" onclick="this.select()"></p>
					<p><strong>Claude Code</strong> (terminale):</p>
					<pre style="white-space:pre-wrap;background:#f6f7f7;padding:8px">claude mcp add --transport http --scope user young <?php echo esc_html( $endpoint ); ?> --header "Authorization: Bearer <?php echo esc_html( $new_token ); ?>"</pre>
					<p><strong>Codex</strong>: imposta la variabile d'ambiente <code>YOUNG_MCP_TOKEN</code> con il token e aggiungi a <code>~/.codex/config.toml</code>:</p>
					<pre style="background:#f6f7f7;padding:8px">[mcp_servers.young]
url = "<?php echo esc_html( $endpoint ); ?>"
bearer_token_env_var = "YOUNG_MCP_TOKEN"</pre>
				</div>
			<?php endif; ?>

			<h2>Endpoint</h2>
			<p><code><?php echo esc_html( $endpoint ); ?></code></p>
			<p class="description">Se i permalink o un plugin di sicurezza bloccano <code>/wp-json/</code>, usa l'indirizzo alternativo: <code><?php echo esc_html( $fallback ); ?></code></p>

			<h2>Permessi editoriali dell'AI</h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'young_mcp' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Lettura e bozze</th>
						<td>Sempre consentite (leggere articoli, creare e modificare bozze, analisi SEO). L'eliminazione non è mai disponibile.</td>
					</tr>
					<tr>
						<th scope="row">Pubblicazione</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Young_MCP_Settings::OPTION ); ?>[allow_publish]" value="1" <?php checked( Young_MCP_Settings::get( 'allow_publish' ) ); ?>> Consenti all'AI di pubblicare o programmare articoli</label>
						<p class="description">Disattivato: l'AI può salvare solo bozze o articoli "in attesa di revisione"; pubblica una persona.</p></td>
					</tr>
					<tr>
						<th scope="row">Articoli già pubblicati</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( Young_MCP_Settings::OPTION ); ?>[allow_edit_published]" value="1" <?php checked( Young_MCP_Settings::get( 'allow_edit_published' ) ); ?>> Consenti all'AI di modificare articoli già online (testo, SEO, immagine in evidenza)</label>
						<p class="description">Necessario per l'ottimizzazione SEO degli articoli esistenti. Le modifiche al testo creano revisioni ripristinabili; quelle SEO sono registrate nel log con i valori precedenti.</p></td>
					</tr>
				</table>
				<?php submit_button( 'Salva permessi' ); ?>
			</form>

			<h2>Token di accesso</h2>
			<table class="widefat striped" style="max-width:1000px">
				<thead><tr><th>Nome</th><th>Utente WordPress</th><th>Token</th><th>Creato</th><th>Ultimo uso</th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $tokens ) : ?>
					<tr><td colspan="6">Nessun token.</td></tr>
				<?php endif; ?>
				<?php foreach ( $tokens as $t ) : ?>
					<?php $u = get_userdata( (int) $t['user_id'] ); ?>
					<tr>
						<td><?php echo esc_html( $t['label'] ); ?></td>
						<td><?php echo $u ? esc_html( $u->user_login . ' (' . implode( ', ', $u->roles ) . ')' ) : '<em>utente eliminato</em>'; ?></td>
						<td><code><?php echo esc_html( $t['hint'] ); ?></code></td>
						<td><?php echo esc_html( self::date( $t['created'] ) ); ?></td>
						<td><?php echo esc_html( self::date( $t['last_used'] ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Revocare questo token?');">
								<input type="hidden" name="action" value="young_mcp_revoke_token">
								<input type="hidden" name="token_id" value="<?php echo esc_attr( $t['id'] ); ?>">
								<?php wp_nonce_field( 'young_mcp_revoke_' . $t['id'] ); ?>
								<button class="button button-link-delete">Revoca</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>

			<h3>Nuovo token</h3>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="young_mcp_create_token">
				<?php wp_nonce_field( 'young_mcp_create_token' ); ?>
				<p>
					<label>Nome <input type="text" name="label" placeholder="es. Claude Code, Codex" required></label>
					<label style="margin-left:12px">Agisce come
						<?php
						wp_dropdown_users(
							array(
								'name'              => 'user_id',
								'capability'        => array( 'edit_posts' ),
								'role__not_in'      => array( 'administrator' ),
								'show_option_none'  => '— scegli un utente (non amministratore) —',
								'option_none_value' => 0,
							)
						);
						?>
					</label>
					<?php submit_button( 'Crea token', 'secondary', 'submit', false ); ?>
				</p>
				<p class="description">Crea un token per ciascun assistente: potrai revocarli separatamente e il log mostrerà chi ha fatto cosa.</p>
			</form>

			<h2>Attività recenti</h2>
			<table class="widefat striped">
				<thead><tr><th style="width:130px">Data</th><th style="width:140px">Token</th><th style="width:120px">Strumento</th><th style="width:60px">Esito</th><th>Dettagli</th></tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="5">Nessuna attività registrata (le sole letture non vengono registrate).</td></tr>
				<?php endif; ?>
				<?php foreach ( $log as $entry ) : ?>
					<tr>
						<td><?php echo esc_html( self::date( $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( $entry['token'] . ' / ' . $entry['user'] ); ?></td>
						<td><code><?php echo esc_html( $entry['tool'] ); ?></code></td>
						<td><?php echo $entry['ok'] ? '✅' : '❌'; ?></td>
						<td style="word-break:break-word"><?php echo esc_html( $entry['summary'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
