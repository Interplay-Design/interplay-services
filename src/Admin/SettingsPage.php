<?php
/**
 * Admin settings page: Interplay Services.
 *
 * Registered under Settings > Interplay Services (or as a top-level menu
 * if we want more real estate in the future — easy to change).
 *
 * Sections:
 *  1. Update Source Credentials  — GitHub PAT for private repo access
 *  2. License                    — license key input (shown but not enforced in beta)
 *  3. Registered Products        — read-only table of managed products + their update status
 *
 * @package InterplayServices
 */

namespace Interplay\Services\Admin;

use Interplay\Services\Http\Client;
use Interplay\Services\License\LicenseManager;
use Interplay\Services\Registry\ProductRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SettingsPage {

	private const MENU_SLUG    = 'interplay-services';
	private const OPTION_GROUP = 'interplay_services_options';

	public function __construct(
		private readonly ProductRegistry $registry,
		private readonly LicenseManager  $license,
		private readonly Client          $http,
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu',       [ $this, 'add_menu_page' ] );
		add_action( 'admin_init',       [ $this, 'register_settings' ] );
		add_action( 'admin_notices',    [ $this, 'maybe_show_config_notice' ] );

		// AJAX: manual update check / cache-bust.
		add_action( 'wp_ajax_interplay_services_check_updates', [ $this, 'ajax_check_updates' ] );
		add_action( 'wp_ajax_interplay_services_create_issue', [ $this, 'ajax_create_issue' ] );
	}

	// ─── Menu ─────────────────────────────────────────────────────────────────

	public function add_menu_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'Interplay Services', 'interplay-services' ),
			__( 'Interplay Services', 'interplay-services' ),
			'manage_options',
			self::MENU_SLUG,
			[ $this, 'render_settings_page' ]
		);
	}

	// ─── Settings ─────────────────────────────────────────────────────────────

	public function register_settings(): void {
		// Credentials section.
		add_settings_section(
			'interplay_credentials',
			__( 'Update Source Credentials', 'interplay-services' ),
			[ $this, 'render_credentials_section_intro' ],
			self::MENU_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			'interplay_services_github_token',
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_github_token' ],
				'default'           => '',
			]
		);

		add_settings_field(
			'interplay_services_github_token',
			__( 'GitHub Personal Access Token', 'interplay-services' ),
			[ $this, 'render_github_token_field' ],
			self::MENU_SLUG,
			'interplay_credentials'
		);

		// License section.
		add_settings_section(
			'interplay_license',
			__( 'License', 'interplay-services' ),
			[ $this, 'render_license_section_intro' ],
			self::MENU_SLUG
		);

		register_setting(
			self::OPTION_GROUP,
			LicenseManager::OPTION_LICENSE_KEY,
			[
				'type'              => 'string',
				'sanitize_callback' => [ $this, 'sanitize_license_key' ],
				'default'           => '',
			]
		);

		add_settings_field(
			LicenseManager::OPTION_LICENSE_KEY,
			__( 'License Key', 'interplay-services' ),
			[ $this, 'render_license_key_field' ],
			self::MENU_SLUG,
			'interplay_license'
		);
	}

	public function sanitize_github_token( $value ): string {
		if ( $this->is_github_token_externally_managed() ) {
			return (string) get_option( 'interplay_services_github_token', '' );
		}

		return sanitize_text_field( (string) $value );
	}

	public function sanitize_license_key( $value ): string {
		if ( $this->is_license_key_externally_managed() ) {
			return (string) get_option( LicenseManager::OPTION_LICENSE_KEY, '' );
		}

		return sanitize_text_field( (string) $value );
	}

	// ─── Page render ──────────────────────────────────────────────────────────

	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Interplay Services', 'interplay-services' ); ?></h1>
			<p class="description">
				<?php esc_html_e(
					'Central update delivery, license management, and product registry for Interplay products.',
					'interplay-services'
				); ?>
				<?php printf(
					/* translators: %s: version string */
					esc_html__( 'Version %s.', 'interplay-services' ),
					esc_html( INTERPLAY_SERVICES_VERSION )
				); ?>
			</p>

			<form method="post" action="options.php">
				<?php
				settings_fields( self::OPTION_GROUP );
				do_settings_sections( self::MENU_SLUG );
				submit_button( __( 'Save Settings', 'interplay-services' ) );
				?>
			</form>

			<hr />

			<h2><?php esc_html_e( 'Registered Products', 'interplay-services' ); ?></h2>
			<?php $this->render_products_table(); ?>
			<p style="margin-top:8px;">
				<button type="button" id="interplay-check-updates" class="button button-secondary">
					<?php esc_html_e( 'Check for Updates Now', 'interplay-services' ); ?>
				</button>
				<span id="interplay-check-updates-status" style="margin-left:10px;"></span>
			</p>

			<h2><?php esc_html_e( 'Open Intro Issues', 'interplay-services' ); ?></h2>
			<?php $this->render_open_issues_table(); ?>
			<?php $this->render_issue_create_form(); ?>
		</div>

		<script>
		document.getElementById('interplay-check-updates').addEventListener('click', function() {
			var status = document.getElementById('interplay-check-updates-status');
			status.textContent = '<?php echo esc_js( __( 'Checking…', 'interplay-services' ) ); ?>';
			fetch(ajaxurl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: 'action=interplay_services_check_updates&_wpnonce=<?php echo esc_js( wp_create_nonce( 'interplay_services_check_updates' ) ); ?>'
			})
			.then(r => r.json())
			.then(data => {
				status.textContent = data.data?.message ?? '<?php echo esc_js( __( 'Done.', 'interplay-services' ) ); ?>';
			})
			.catch(() => {
				status.textContent = '<?php echo esc_js( __( 'Request failed.', 'interplay-services' ) ); ?>';
			});
		});

		var issueForm = document.getElementById('interplay-create-issue-form');
		if (issueForm) {
			issueForm.addEventListener('submit', function(e) {
				e.preventDefault();
				var status = document.getElementById('interplay-create-issue-status');
				var repository = document.getElementById('interplay-create-issue-repository').value;
				var title = document.getElementById('interplay-create-issue-title').value.trim();
				var body = document.getElementById('interplay-create-issue-body').value.trim();
				if (!title) {
					status.textContent = '<?php echo esc_js( __( 'Issue title is required.', 'interplay-services' ) ); ?>';
					return;
				}
				status.textContent = '<?php echo esc_js( __( 'Creating issue…', 'interplay-services' ) ); ?>';

				var params = new URLSearchParams();
				params.set('action', 'interplay_services_create_issue');
				params.set('_wpnonce', '<?php echo esc_js( wp_create_nonce( 'interplay_services_create_issue' ) ); ?>');
				params.set('repository', repository);
				params.set('title', title);
				params.set('body', body);

				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: params.toString()
				})
				.then(function(r) { return r.json(); })
				.then(function(data) {
					if (!data.success) {
						status.textContent = data.data && data.data.message ? data.data.message : '<?php echo esc_js( __( 'Issue creation failed.', 'interplay-services' ) ); ?>';
						return;
					}
					status.innerHTML = data.data && data.data.message ? data.data.message : '<?php echo esc_js( __( 'Issue created.', 'interplay-services' ) ); ?>';
					issueForm.reset();
				})
				.catch(function() {
					status.textContent = '<?php echo esc_js( __( 'Request failed.', 'interplay-services' ) ); ?>';
				});
			});
		}
		</script>
		<?php
	}

	// ─── Section callbacks ────────────────────────────────────────────────────

	public function render_credentials_section_intro(): void {
		echo '<p>' . esc_html__(
			'Use a fine-grained GitHub personal access token (PAT) for least-privilege access. For Intro and Interplay Services updates, grant read-only Contents access and limit repository access to Interplay-Design/Intro plus Interplay-Design/interplay-services.',
			'interplay-services'
		) . '</p>';
	}

	public function render_license_section_intro(): void {
		echo '<p>' . esc_html__(
			'License enforcement is not active in this beta. Enter your license key to prepare for when it is activated.',
			'interplay-services'
		) . '</p>';
	}

	// ─── Field callbacks ──────────────────────────────────────────────────────

	public function render_github_token_field(): void {
		$value = $this->get_github_token();
		$externally_managed = $this->is_github_token_externally_managed();
		$masked = $value !== '' ? str_repeat( '•', min( 20, strlen( $value ) - 4 ) ) . substr( $value, -4 ) : '';
		$fine_grained_url = $this->get_fine_grained_token_url();
		$classic_url = 'https://github.com/settings/tokens/new?scopes=repo&description=Interplay+Services';
		?>
		<input type="password"
			   id="interplay_services_github_token"
			   name="interplay_services_github_token"
			   value="<?php echo esc_attr( $externally_managed ? '' : $value ); ?>"
			   class="regular-text"
			   autocomplete="new-password"
			   <?php disabled( $externally_managed ); ?>
		/>
		<?php if ( $masked ) : ?>
			<p class="description">
				<?php printf(
					/* translators: %s: masked token */
					esc_html__( 'Current token: %s', 'interplay-services' ),
					'<code>' . esc_html( $masked ) . '</code>'
				); ?>
			</p>
		<?php endif; ?>
		<?php if ( $externally_managed ) : ?>
			<p class="description">
				<?php esc_html_e( 'Token is managed externally via INTERPLAY_SERVICES_GITHUB_TOKEN (constant or environment variable).', 'interplay-services' ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'For stronger security, set secrets in wp-config.php or environment variables instead of storing them in the database.', 'interplay-services' ); ?>
		</p>
		<p class="description">
			<?php printf(
				'<a class="button button-secondary" href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( $fine_grained_url ),
				esc_html__( 'Create Fine-Grained Token on GitHub', 'interplay-services' )
			); ?>
		</p>
		<p class="description">
			<?php echo esc_html__( 'Minimum required settings (updates only):', 'interplay-services' ); ?>
		</p>
		<div style="background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:12px 14px;max-width:900px;margin:0 0 10px;">
			<p style="margin:0 0 8px;font-weight:600;"><?php esc_html_e( 'Token configuration:', 'interplay-services' ); ?></p>
			<ul style="margin:0;list-style:disc;padding-left:20px;">
			<li><?php esc_html_e( 'Resource owner: interplaydesign', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Repository access: Only select repositories', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Selected repositories: Intro (interplaydesign/Intro) and Interplay Services (interplaydesign/interplay-services)', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Permissions:', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Contents: Read-only', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Deployments: Read-only', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Metadata: Read-only (required by GitHub)', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Expiration:', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Choose No Expiry if allowed; org policy may enforce an expiry.', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Issues:', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Read and write (required only if you want to create issues from this admin page).', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Pull requests:', 'interplay-services' ); ?></li>
			<li><?php esc_html_e( 'Read-only (optional for future release/changelog workflows).', 'interplay-services' ); ?></li>
			</ul>
		</div>
		<p class="description">
			<?php printf(
				/* translators: %s: GitHub URL */
				esc_html__( 'Legacy fallback (broader scope): %s', 'interplay-services' ),
				'<a href="' . esc_url( $classic_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Classic PAT', 'interplay-services' ) . '</a>'
			); ?>
		</p>
		<?php
	}

	/**
	 * Build a prefilled GitHub fine-grained token URL.
	 *
	 * GitHub does not currently support pre-selecting an individual repository
	 * or every permission in URL query args, so this helper prefills what is
	 * reliably supported and leaves final repo/permission selection to the user.
	 */
	private function get_fine_grained_token_url(): string {
		return add_query_arg(
			[
				'name'        => 'Interplay Services - Intro Updates',
				'description' => 'Read-only update checks for Interplay Intro theme updates',
				'target_name' => 'Interplay-Design',
				'expires_in'  => 'none',
			],
			'https://github.com/settings/personal-access-tokens/new'
		);
	}

	public function render_license_key_field(): void {
		$value  = $this->license->get_license_key();
		$externally_managed = $this->is_license_key_externally_managed();
		$status = $this->license->get_status();
		?>
		<input type="text"
			   id="<?php echo esc_attr( LicenseManager::OPTION_LICENSE_KEY ); ?>"
			   name="<?php echo esc_attr( LicenseManager::OPTION_LICENSE_KEY ); ?>"
			   value="<?php echo esc_attr( $externally_managed ? '' : $value ); ?>"
			   class="regular-text"
			   placeholder="XXXXXX-XXXXXX-XXXXXX-XXXXXX"
			   <?php disabled( $externally_managed ); ?>
		/>
		<?php if ( $externally_managed ) : ?>
			<p class="description">
				<?php esc_html_e( 'License key is managed externally via INTERPLAY_SERVICES_LICENSE_KEY (constant or environment variable).', 'interplay-services' ); ?>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php printf(
				/* translators: %s: status label */
				esc_html__( 'Status: %s', 'interplay-services' ),
				'<strong>' . esc_html( $status ) . '</strong>'
			); ?>
			&nbsp;
			<em><?php esc_html_e( '(enforcement not active in beta)', 'interplay-services' ); ?></em>
		</p>
		<?php
	}

	// ─── Products table ───────────────────────────────────────────────────────

	private function render_products_table(): void {
		$products = $this->registry->all();
		if ( empty( $products ) ) {
			echo '<p>' . esc_html__( 'No products registered.', 'interplay-services' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:800px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Type', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Installed', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Update Source', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'License', 'interplay-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $products as $product ) :
				$source = $product->get_update_source();
			?>
				<tr>
					<td><strong><?php echo esc_html( $product->get_name() ); ?></strong></td>
					<td><?php echo esc_html( $product->get_type() ); ?></td>
					<td><?php echo esc_html( $product->get_installed_version() ); ?></td>
					<td>
						<?php echo esc_html( $source['driver'] ?? '—' ); ?>
						<?php if ( ! empty( $source['repository'] ) ) : ?>
							&nbsp;(<code><?php echo esc_html( $source['repository'] ); ?></code>)
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $product->requires_license() ) : ?>
							<?php esc_html_e( 'Required', 'interplay-services' ); ?>
						<?php else : ?>
							<span style="color:#888"><?php esc_html_e( 'None', 'interplay-services' ); ?></span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_open_issues_table(): void {
		$token = $this->get_github_token();
		if ( $token === '' ) {
			echo '<p>' . esc_html__( 'Add a GitHub token above to load open issues from Interplay-Design/Intro.', 'interplay-services' ) . '</p>';
			return;
		}

		$issues = $this->fetch_open_intro_issues();

		if ( $issues === null ) {
			echo '<p>' . esc_html__( 'Could not load issues from GitHub. Check token permissions and repository selection.', 'interplay-services' ) . '</p>';
			return;
		}

		if ( empty( $issues ) ) {
			echo '<p>' . esc_html__( 'No open issues found.', 'interplay-services' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped" style="max-width:1100px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Issue', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Number', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'State', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Updated', 'interplay-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $issues as $issue ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( (string) ( $issue['html_url'] ?? '' ) ); ?>" target="_blank" rel="noopener">
							<?php echo esc_html( (string) ( $issue['title'] ?? '' ) ); ?>
						</a>
					</td>
					<td>#<?php echo esc_html( (string) ( $issue['number'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( ucfirst( (string) ( $issue['state'] ?? 'open' ) ) ); ?></td>
					<td><?php echo esc_html( $this->format_github_datetime( (string) ( $issue['updated_at'] ?? '' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description" style="margin-top:8px;">
			<?php printf(
				esc_html__( 'Showing up to %d most recently updated open issues.', 'interplay-services' ),
				10
			); ?>
		</p>
		<?php
	}

	private function render_issue_create_form(): void {
		$token = $this->get_github_token();
		if ( $token === '' ) {
			return;
		}
		$repositories = $this->get_issue_repositories();
		?>
		<div style="margin-top:16px;max-width:1100px;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:14px;">
			<h3 style="margin-top:0;"><?php esc_html_e( 'Create Issue', 'interplay-services' ); ?></h3>
			<p class="description" style="margin-top:0;">
				<?php esc_html_e( 'Creates a new issue in the selected repository using your configured token.', 'interplay-services' ); ?>
			</p>
			<form id="interplay-create-issue-form">
				<p>
					<label for="interplay-create-issue-repository"><strong><?php esc_html_e( 'Product', 'interplay-services' ); ?></strong></label><br />
					<select id="interplay-create-issue-repository" style="max-width:320px;width:100%;">
						<?php foreach ( $repositories as $repo => $label ) : ?>
							<option value="<?php echo esc_attr( $repo ); ?>"><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
				<p>
					<label for="interplay-create-issue-title"><strong><?php esc_html_e( 'Title', 'interplay-services' ); ?></strong></label><br />
					<input id="interplay-create-issue-title" type="text" class="regular-text" style="max-width:900px;width:100%;" required />
				</p>
				<p>
					<label for="interplay-create-issue-body"><strong><?php esc_html_e( 'Body', 'interplay-services' ); ?></strong></label><br />
					<textarea id="interplay-create-issue-body" rows="8" style="max-width:900px;width:100%;"></textarea>
				</p>
				<p>
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Create Issue on GitHub', 'interplay-services' ); ?></button>
					<span id="interplay-create-issue-status" style="margin-left:10px;"></span>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Fetch up to 10 open issues from Interplay-Design/Intro.
	 * Pull requests are excluded because GitHub returns PRs in the issues API.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function fetch_open_intro_issues(): ?array {
		$url = add_query_arg(
			[
				'state'     => 'open',
				'per_page'  => 10,
				'sort'      => 'updated',
				'direction' => 'desc',
			],
			'https://api.github.com/repos/Interplay-Design/Intro/issues'
		);

		$data = $this->http->get_json( $url );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$issues = array_values(
			array_filter(
				$data,
				static fn( $item ) => is_array( $item ) && ! isset( $item['pull_request'] )
			)
		);

		return $issues;
	}

	/**
	 * @return array<string,string>
	 */
	private function get_issue_repositories(): array {
		return [
			'Interplay-Design/Intro'              => __( 'Intro', 'interplay-services' ),
			'Interplay-Design/interplay-services' => __( 'Interplay Services', 'interplay-services' ),
		];
	}

	private function format_github_datetime( string $value ): string {
		if ( $value === '' ) {
			return '—';
		}

		$timestamp = strtotime( $value );
		if ( $timestamp === false ) {
			return $value;
		}

		return gmdate( 'Y-m-d H:i', $timestamp ) . ' UTC';
	}

	// ─── AJAX ─────────────────────────────────────────────────────────────────

	public function ajax_check_updates(): void {
		check_ajax_referer( 'interplay_services_check_updates' );

		if ( ! current_user_can( 'update_themes' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'interplay-services' ) ] );
		}

		// Bust the Interplay GitHub release cache so the next check hits the API fresh.
		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_interplay_update_%' OR option_name LIKE '_transient_timeout_interplay_update_%'" );

		// Clear both WordPress update transients.
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );

		// Force WordPress to re-run both checks.
		wp_update_themes();
		wp_update_plugins();

		wp_send_json_success( [
			'message' => __( 'Update check complete. Reload the Updates screen to see results.', 'interplay-services' ),
		] );
	}

	public function ajax_create_issue(): void {
		check_ajax_referer( 'interplay_services_create_issue' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'interplay-services' ) ] );
		}

		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['title'] ) ) : '';
		$body  = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST['body'] ) ) : '';
		$repository = isset( $_POST['repository'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['repository'] ) ) : 'Interplay-Design/Intro';
		$repositories = $this->get_issue_repositories();

		if ( $title === '' ) {
			wp_send_json_error( [ 'message' => __( 'Issue title is required.', 'interplay-services' ) ] );
		}

		if ( ! isset( $repositories[ $repository ] ) ) {
			wp_send_json_error( [ 'message' => __( 'Invalid repository selected.', 'interplay-services' ) ] );
		}

		$response = $this->http->post(
			'https://api.github.com/repos/' . $repository . '/issues',
			[
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body' => wp_json_encode(
					[
						'title' => $title,
						'body'  => $body,
					]
				),
			]
		);

		if ( is_wp_error( $response ) ) {
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'GitHub request failed: %s', 'interplay-services' ),
					$response->get_error_message()
				),
			] );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			$message = is_array( $data ) && ! empty( $data['message'] ) ? (string) $data['message'] : __( 'GitHub rejected the request.', 'interplay-services' );
			wp_send_json_error( [ 'message' => $message ] );
		}

		$issue_url = is_array( $data ) && ! empty( $data['html_url'] ) ? (string) $data['html_url'] : '';
		if ( $issue_url !== '' ) {
			wp_send_json_success( [
				'message' => sprintf(
					'<a href="%1$s" target="_blank" rel="noopener">%2$s</a>',
					esc_url( $issue_url ),
					esc_html__( 'Issue created. Open on GitHub.', 'interplay-services' )
				),
			] );
		}

		wp_send_json_success( [ 'message' => __( 'Issue created.', 'interplay-services' ) ] );
	}

	// ─── Config notice ────────────────────────────────────────────────────────

	public function maybe_show_config_notice(): void {
		$token = $this->get_github_token();
		if ( $token !== '' ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, [ 'update-core', 'themes', 'settings_page_' . self::MENU_SLUG ], true ) ) {
			return;
		}

		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s <a href="%s">%s</a></p></div>',
			esc_html__( 'Interplay Services: no GitHub token is configured — theme updates cannot be checked.', 'interplay-services' ),
			esc_url( admin_url( 'options-general.php?page=' . self::MENU_SLUG ) ),
			esc_html__( 'Configure now', 'interplay-services' )
		);
	}

	private function get_github_token(): string {
		if ( defined( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) && constant( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) !== '' ) {
			return (string) constant( 'INTERPLAY_SERVICES_GITHUB_TOKEN' );
		}

		$env = getenv( 'INTERPLAY_SERVICES_GITHUB_TOKEN' );
		if ( is_string( $env ) && $env !== '' ) {
			return $env;
		}

		return (string) get_option( 'interplay_services_github_token', '' );
	}

	private function is_github_token_externally_managed(): bool {
		if ( defined( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) && constant( 'INTERPLAY_SERVICES_GITHUB_TOKEN' ) !== '' ) {
			return true;
		}

		$env = getenv( 'INTERPLAY_SERVICES_GITHUB_TOKEN' );
		return is_string( $env ) && $env !== '';
	}

	private function is_license_key_externally_managed(): bool {
		if ( defined( 'INTERPLAY_SERVICES_LICENSE_KEY' ) && constant( 'INTERPLAY_SERVICES_LICENSE_KEY' ) !== '' ) {
			return true;
		}

		$env = getenv( 'INTERPLAY_SERVICES_LICENSE_KEY' );
		return is_string( $env ) && $env !== '';
	}
}
