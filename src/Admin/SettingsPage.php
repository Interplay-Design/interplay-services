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
use Interplay\Services\Log\Logger;
use Interplay\Services\Registry\Contracts\ProductInterface;
use Interplay\Services\Registry\ProductRegistry;
use Interplay\Services\Updater\UpdateManager;

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
		private readonly UpdateManager   $updates,
	) {}

	public function register_hooks(): void {
		add_action( 'admin_menu',       [ $this, 'add_menu_page' ] );
		add_action( 'admin_init',       [ $this, 'register_settings' ] );
		add_action( 'admin_notices',    [ $this, 'maybe_show_config_notice' ] );

		// AJAX: manual update check / cache-bust.
		add_action( 'wp_ajax_interplay_services_check_updates', [ $this, 'ajax_check_updates' ] );
		add_action( 'wp_ajax_interplay_services_create_issue', [ $this, 'ajax_create_issue' ] );
		add_action( 'wp_ajax_interplay_services_clear_log', [ $this, 'ajax_clear_log' ] );
		add_action( 'wp_ajax_interplay_services_update_product', [ $this, 'ajax_update_product' ] );
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

			<h2><?php esc_html_e( 'Activity Log', 'interplay-services' ); ?></h2>
			<?php $this->render_activity_log(); ?>

			<h2><?php esc_html_e( 'Open Intro Issues', 'interplay-services' ); ?></h2>
			<?php $this->render_open_issues_table(); ?>

			<h2><?php esc_html_e( 'Open Interplay Services Issues', 'interplay-services' ); ?></h2>
			<?php $this->render_open_plugin_issues_table(); ?>

			<?php $this->render_issue_create_form(); ?>
		</div>

		<script>
		(function() {
			var checkNonce  = '<?php echo esc_js( wp_create_nonce( 'interplay_services_check_updates' ) ); ?>';
			var updateNonce = '<?php echo esc_js( wp_create_nonce( 'interplay_services_update_product' ) ); ?>';
			var msgChecking = '<?php echo esc_js( __( 'Checking…', 'interplay-services' ) ); ?>';
			var msgDone     = '<?php echo esc_js( __( 'Done.', 'interplay-services' ) ); ?>';
			var msgFailed   = '<?php echo esc_js( __( 'Request failed.', 'interplay-services' ) ); ?>';
			var msgUpdating = '<?php echo esc_js( __( 'Updating…', 'interplay-services' ) ); ?>';

			function replaceRows(html) {
				if (typeof html !== 'string') return;
				var tbody = document.querySelector('#interplay-products-table tbody');
				if (tbody) tbody.innerHTML = html;
			}

			function postForm(body) {
				return fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					credentials: 'same-origin',
					body: body
				}).then(function(r) { return r.json(); });
			}

			document.getElementById('interplay-check-updates').addEventListener('click', function() {
				var status = document.getElementById('interplay-check-updates-status');
				status.textContent = msgChecking;
				postForm('action=interplay_services_check_updates&_wpnonce=' + encodeURIComponent(checkNonce))
					.then(function(data) {
						var d = data && data.data ? data.data : {};
						status.textContent = d.message || msgDone;
						replaceRows(d.rows);
					})
					.catch(function() { status.textContent = msgFailed; });
			});

			// Update buttons (delegated so AJAX-replaced rows still work).
			document.getElementById('interplay-products-table').addEventListener('click', function(e) {
				var btn = e.target.closest('.ips-update-btn');
				if (!btn) return;
				var productId   = btn.getAttribute('data-product-id');
				var productType = btn.getAttribute('data-product-type');
				if (!productId || !productType) return;

				var originalLabel = btn.textContent;
				btn.disabled = true;
				btn.textContent = msgUpdating;
				var status = document.getElementById('interplay-check-updates-status');
				if (status) status.textContent = msgUpdating + ' ' + productId;

				var body = 'action=interplay_services_update_product'
					+ '&_wpnonce=' + encodeURIComponent(updateNonce)
					+ '&product_id=' + encodeURIComponent(productId)
					+ '&product_type=' + encodeURIComponent(productType);

				postForm(body)
					.then(function(data) {
						var d = data && data.data ? data.data : {};
						if (status) status.textContent = d.message || msgDone;
						if (d.rows) {
							replaceRows(d.rows);
						} else {
							btn.disabled = false;
							btn.textContent = originalLabel;
						}
					})
					.catch(function() {
						btn.disabled = false;
						btn.textContent = originalLabel;
						if (status) status.textContent = msgFailed;
					});
			});
		})();

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

		var clearLogBtn = document.getElementById('interplay-clear-log');
		if (clearLogBtn) {
			clearLogBtn.addEventListener('click', function() {
				if (!confirm('<?php echo esc_js( __( 'Clear the activity log?', 'interplay-services' ) ); ?>')) {
					return;
				}
				fetch(ajaxurl, {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'action=interplay_services_clear_log&_wpnonce=<?php echo esc_js( wp_create_nonce( 'interplay_services_clear_log' ) ); ?>'
				}).then(function(r){ return r.json(); }).then(function(){ location.reload(); });
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
				<li><?php esc_html_e( 'Resource owner: Interplay-Design', 'interplay-services' ); ?></li>
				<li><?php esc_html_e( 'Repository access: Only select repositories', 'interplay-services' ); ?></li>
				<li><?php esc_html_e( 'Selected repositories: Intro (Interplay-Design/Intro) and Interplay Services (Interplay-Design/interplay-services)', 'interplay-services' ); ?></li>
				<li>
					<strong><?php esc_html_e( 'Permissions:', 'interplay-services' ); ?></strong>
					<ul style="list-style:disc;padding-left:20px;margin:4px 0 0;">
						<li><?php esc_html_e( 'Contents: Read-only', 'interplay-services' ); ?></li>
						<li><?php esc_html_e( 'Deployments: Read-only', 'interplay-services' ); ?></li>
						<li><?php esc_html_e( 'Metadata: Read-only (required by GitHub)', 'interplay-services' ); ?></li>
						<li><?php esc_html_e( 'Issues: Read and write (required only if you want to create issues from this admin page)', 'interplay-services' ); ?></li>
						<li><?php esc_html_e( 'Pull requests: Read-only (optional for future release/changelog workflows)', 'interplay-services' ); ?></li>
					</ul>
				</li>
				<li>
					<strong><?php esc_html_e( 'Expiration:', 'interplay-services' ); ?></strong>
					<ul style="list-style:disc;padding-left:20px;margin:4px 0 0;">
						<li><?php esc_html_e( 'Choose No Expiry if allowed; org policy may enforce an expiry.', 'interplay-services' ); ?></li>
					</ul>
				</li>
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
		?>
		<table id="interplay-products-table" class="widefat striped" style="max-width:1000px">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Product', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Type', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Installed', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'GitHub Release', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Update Source', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'License', 'interplay-services' ); ?></th>
					<th><?php esc_html_e( 'Action', 'interplay-services' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php echo $this->render_products_table_rows(); // phpcs:ignore WordPress.Security.EscapeOutput ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render only the <tr> rows for the products table. Used both for the
	 * initial server render and for AJAX refresh responses (so the JS can
	 * just innerHTML the tbody).
	 */
	private function render_products_table_rows(): string {
		$products = $this->registry->all();
		if ( empty( $products ) ) {
			return '<tr><td colspan="7">'
				. esc_html__( 'No products registered.', 'interplay-services' )
				. '</td></tr>';
		}

		ob_start();
		foreach ( $products as $product ) {
			echo $this->render_product_row( $product ); // phpcs:ignore WordPress.Security.EscapeOutput
		}
		return (string) ob_get_clean();
	}

	private function render_product_row( ProductInterface $product ): string {
		$source           = $product->get_update_source();
		$installed        = $product->get_installed_version();
		$latest_result    = $this->updates->latest_for_product( $product );
		$latest_version   = $latest_result?->version ?? '';
		$update_available = $latest_result !== null && $latest_result->is_update_available( $installed );

		ob_start();
		?>
		<tr data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
		    data-product-type="<?php echo esc_attr( $product->get_type() ); ?>">
			<td><strong><?php echo esc_html( $product->get_name() ); ?></strong></td>
			<td><?php echo esc_html( $product->get_type() ); ?></td>
			<td class="ips-col-installed"><?php echo esc_html( $installed ); ?></td>
			<td class="ips-col-latest">
				<?php if ( $latest_version !== '' ) : ?>
					<?php echo esc_html( $latest_version ); ?>
					<?php if ( $update_available ) : ?>
						<span style="color:#d63638;" title="<?php esc_attr_e( 'Update available', 'interplay-services' ); ?>">●</span>
					<?php endif; ?>
				<?php else : ?>
					<span style="color:#888">—</span>
				<?php endif; ?>
			</td>
			<td>
				<?php echo esc_html( (string) ( $source['driver'] ?? '—' ) ); ?>
				<?php if ( ! empty( $source['repository'] ) ) : ?>
					&nbsp;(<code><?php echo esc_html( (string) $source['repository'] ); ?></code>)
				<?php endif; ?>
			</td>
			<td>
				<?php if ( $product->requires_license() ) : ?>
					<?php esc_html_e( 'Required', 'interplay-services' ); ?>
				<?php else : ?>
					<span style="color:#888"><?php esc_html_e( 'None', 'interplay-services' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="ips-col-action">
				<?php if ( $update_available ) : ?>
					<button type="button"
					        class="button button-primary ips-update-btn"
					        data-product-id="<?php echo esc_attr( $product->get_id() ); ?>"
					        data-product-type="<?php echo esc_attr( $product->get_type() ); ?>">
						<?php
						printf(
							/* translators: %s: version number */
							esc_html__( 'Update to %s', 'interplay-services' ),
							esc_html( $latest_version )
						);
						?>
					</button>
				<?php else : ?>
					<span style="color:#888"><?php esc_html_e( 'Up to date', 'interplay-services' ); ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
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
	 * Fetch up to 10 open issues from Interplay-Design/interplay-services.
	 * Pull requests are excluded because GitHub returns PRs in the issues API.
	 *
	 * @return array<int,array<string,mixed>>|null
	 */
	private function fetch_open_plugin_issues(): ?array {
		$url = add_query_arg(
			[
				'state'     => 'open',
				'per_page'  => 10,
				'sort'      => 'updated',
				'direction' => 'desc',
			],
			'https://api.github.com/repos/Interplay-Design/interplay-services/issues'
		);

		$data = $this->http->get_json( $url );
		if ( ! is_array( $data ) ) {
			return null;
		}

		return array_values(
			array_filter(
				$data,
				static fn( $item ) => is_array( $item ) && ! isset( $item['pull_request'] )
			)
		);
	}

	/**
	 * Render the open Interplay Services issues table.
	 */
	private function render_open_plugin_issues_table(): void {
		$token = $this->get_github_token();
		if ( $token === '' ) {
			echo '<p>' . esc_html__( 'Add a GitHub token above to load open issues from Interplay-Design/interplay-services.', 'interplay-services' ) . '</p>';
			return;
		}

		$issues = $this->fetch_open_plugin_issues();

		if ( $issues === null ) {
			echo '<p>' . esc_html__( 'Could not load Interplay Services issues. Check your token and network access.', 'interplay-services' ) . '</p>';
			return;
		}

		if ( count( $issues ) === 0 ) {
			echo '<p>' . esc_html__( 'No open Interplay Services issues.', 'interplay-services' ) . '</p>';
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
					<td><a href="<?php echo esc_url( (string) ( $issue['html_url'] ?? '#' ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( (string) ( $issue['title'] ?? '' ) ); ?></a></td>
					<td>#<?php echo esc_html( (string) ( $issue['number'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( ucfirst( (string) ( $issue['state'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( $this->format_github_datetime( (string) ( $issue['updated_at'] ?? '' ) ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
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

	// ─── Activity log ─────────────────────────────────────────────────────────

	private function render_activity_log(): void {
		$entries = Logger::instance()->get_recent( 50 );
		?>
		<p class="description" style="margin-bottom:8px;">
			<?php esc_html_e( 'Most recent first. Errors and warnings are always recorded; debug entries appear only when WP_DEBUG is on. Tail wp-content/debug.log for the live stream.', 'interplay-services' ); ?>
		</p>
		<?php if ( empty( $entries ) ) : ?>
			<p style="color:#666;font-style:italic;"><?php esc_html_e( 'No activity recorded yet.', 'interplay-services' ); ?></p>
		<?php else : ?>
			<table class="widefat striped" style="max-width:1100px;">
				<thead>
					<tr>
						<th style="width:160px;"><?php esc_html_e( 'Time', 'interplay-services' ); ?></th>
						<th style="width:80px;"><?php esc_html_e( 'Level', 'interplay-services' ); ?></th>
						<th><?php esc_html_e( 'Message', 'interplay-services' ); ?></th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ( $entries as $entry ) :
					$time    = (int) ( $entry['time'] ?? 0 );
					$level   = (string) ( $entry['level'] ?? '' );
					$message = (string) ( $entry['message'] ?? '' );
					$context = (array) ( $entry['context'] ?? [] );
					$colour  = $this->level_colour( $level );
				?>
					<tr>
						<td><code><?php echo esc_html( $time ? gmdate( 'Y-m-d H:i:s', $time ) . ' UTC' : '—' ); ?></code></td>
						<td><strong style="color:<?php echo esc_attr( $colour ); ?>;"><?php echo esc_html( strtoupper( $level ) ); ?></strong></td>
						<td>
							<div><?php echo esc_html( $message ); ?></div>
							<?php if ( ! empty( $context ) ) : ?>
								<details style="margin-top:4px;">
									<summary style="cursor:pointer;color:#666;font-size:11px;"><?php esc_html_e( 'Context', 'interplay-services' ); ?></summary>
									<pre style="background:#f6f7f7;padding:8px;border-radius:4px;font-size:11px;overflow:auto;max-height:200px;margin:4px 0 0;"><?php echo esc_html( wp_json_encode( $context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) ); ?></pre>
								</details>
							<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p style="margin-top:8px;">
				<button type="button" id="interplay-clear-log" class="button button-secondary">
					<?php esc_html_e( 'Clear log', 'interplay-services' ); ?>
				</button>
			</p>
		<?php endif;
	}

	private function level_colour( string $level ): string {
		switch ( $level ) {
			case Logger::LEVEL_ERROR: return '#d63638';
			case Logger::LEVEL_WARN:  return '#dba617';
			case Logger::LEVEL_INFO:  return '#2271b1';
			default:                  return '#666';
		}
	}

	// ─── AJAX ─────────────────────────────────────────────────────────────────

	public function ajax_check_updates(): void {
		check_ajax_referer( 'interplay_services_check_updates' );

		if ( ! current_user_can( 'update_plugins' ) && ! current_user_can( 'update_themes' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'interplay-services' ) ] );
		}

		// Bust the Interplay GitHub release cache so the next check hits the API fresh.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_interplay_update_%'
			    OR option_name LIKE '_transient_timeout_interplay_update_%'"
		);

		// Clear both WordPress update transients.
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );

		// Force WordPress to re-run both checks.
		wp_update_themes();
		wp_update_plugins();

		wp_send_json_success( [
			'message' => __( 'Update check complete.', 'interplay-services' ),
			'rows'    => $this->render_products_table_rows(),
		] );
	}

	/**
	 * Run an actual WP update for a single registered product.
	 *
	 * Expects POST params:
	 *   action      = interplay_services_update_product
	 *   _wpnonce    = interplay_services_update_product nonce
	 *   product_id  = e.g. 'intro' or 'interplay-services/interplay-services.php'
	 *   product_type = 'theme' | 'plugin'
	 */
	public function ajax_update_product(): void {
		check_ajax_referer( 'interplay_services_update_product' );

		$product_id   = isset( $_POST['product_id'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['product_id'] ) ) : '';
		$product_type = isset( $_POST['product_type'] ) ? sanitize_key( wp_unslash( (string) $_POST['product_type'] ) ) : '';

		if ( $product_id === '' || $product_type === '' ) {
			wp_send_json_error( [ 'message' => __( 'Missing product identifier.', 'interplay-services' ) ] );
		}

		$capability = $product_type === 'theme' ? 'update_themes' : 'update_plugins';
		if ( ! current_user_can( $capability ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'interplay-services' ) ] );
		}

		$product = $this->registry->find( $product_id );
		if ( $product === null || $product->get_type() !== $product_type ) {
			wp_send_json_error( [ 'message' => __( 'Unknown product.', 'interplay-services' ) ] );
		}

		Logger::instance()->info( 'admin: update requested', [
			'product' => $product_id,
			'type'    => $product_type,
		] );

		// Bust caches so the upgrader sees the latest GitHub release.
		global $wpdb;
		$wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_interplay_update_%'
			    OR option_name LIKE '_transient_timeout_interplay_update_%'"
		);
		delete_site_transient( 'update_themes' );
		delete_site_transient( 'update_plugins' );

		// Required upgrader plumbing.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		// Force a fresh update check so the transient is populated.
		if ( $product_type === 'theme' ) {
			wp_update_themes();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $product_id );
		} else {
			wp_update_plugins();
			$skin     = new \Automatic_Upgrader_Skin();
			$upgrader = new \Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $product_id );
		}

		if ( is_wp_error( $result ) ) {
			Logger::instance()->error( 'admin: update failed', [
				'product' => $product_id,
				'error'   => $result->get_error_message(),
			] );
			wp_send_json_error( [
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Update failed: %s', 'interplay-services' ),
					$result->get_error_message()
				),
				'rows' => $this->render_products_table_rows(),
			] );
		}

		if ( $result === false ) {
			$skin_errors = $skin->get_errors();
			$message = is_wp_error( $skin_errors ) && $skin_errors->has_errors()
				? $skin_errors->get_error_message()
				: __( 'No update available, or the upgrader reported a non-specific failure.', 'interplay-services' );
			Logger::instance()->warn( 'admin: update returned false', [
				'product' => $product_id,
				'message' => $message,
			] );
			wp_send_json_error( [
				'message' => $message,
				'rows'    => $this->render_products_table_rows(),
			] );
		}

		// Re-activate the plugin if the upgrader deactivated it (Plugin_Upgrader does this).
		if ( $product_type === 'plugin' && ! is_plugin_active( $product_id ) ) {
			activate_plugin( $product_id );
		}

		Logger::instance()->info( 'admin: update succeeded', [ 'product' => $product_id ] );

		wp_send_json_success( [
			'message' => __( 'Update complete.', 'interplay-services' ),
			'rows'    => $this->render_products_table_rows(),
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

	public function ajax_clear_log(): void {
		check_ajax_referer( 'interplay_services_clear_log' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => __( 'Insufficient permissions.', 'interplay-services' ) ] );
		}

		Logger::instance()->clear();
		wp_send_json_success( [ 'message' => __( 'Log cleared.', 'interplay-services' ) ] );
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
