<?php

declare( strict_types=1 );

namespace WODO_Bridge\Admin;

use WODO_Bridge\Lib\Constants;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Admin_Page {

	public const SLUG = 'wodo-bridge';

	public const OPTION_AGGREGATOR_URL = 'wodo_bridge_v2_aggregator_url';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_init', array( $this, 'maybe_save_aggregator_url' ) );

		( new Admin_Assets() )->register();
	}

	public function add_menu(): void {
		add_options_page(
			esc_html__( 'WODO Bridge', 'wodo-bridge' ),
			esc_html__( 'WODO Bridge', 'wodo-bridge' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * Handle the inline aggregator-URL form on the Connections tab.
	 *
	 * Posts back to the same admin page; nonce-checked.
	 */
	public function maybe_save_aggregator_url(): void {
		if ( ! isset( $_POST['wodo_bridge_save_aggregator'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( 'wodo_bridge_save_aggregator', 'wodo_bridge_aggregator_nonce' );

		$url = isset( $_POST['wodo_bridge_aggregator_url'] ) ? esc_url_raw( wp_unslash( (string) $_POST['wodo_bridge_aggregator_url'] ) ) : '';
		// Require https when not localhost.
		if ( $url !== '' && ! preg_match( '#^https://#i', $url ) && ! preg_match( '#^https?://(localhost|127\.0\.0\.1)#i', $url ) ) {
			add_settings_error( 'wodo-bridge', 'aggregator_invalid', esc_html__( 'Aggregator URL must use HTTPS.', 'wodo-bridge' ) );
			return;
		}

		update_option( self::OPTION_AGGREGATOR_URL, $url, false );
		add_settings_error( 'wodo-bridge', 'aggregator_saved', esc_html__( 'Aggregator URL saved.', 'wodo-bridge' ), 'updated' );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'wodo-bridge' ) );
		}

		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'connections'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tabs       = array(
			'connections' => __( 'Connections', 'wodo-bridge' ),
			'webhooks'    => __( 'Webhooks', 'wodo-bridge' ),
			'activity'    => __( 'Activity', 'wodo-bridge' ),
		);
		if ( ! isset( $tabs[ $active_tab ] ) ) {
			$active_tab = 'connections';
		}

		$aggregator_url = (string) get_option( self::OPTION_AGGREGATOR_URL, '' );
		$site_url       = home_url();

		settings_errors( 'wodo-bridge' );
		?>
		<div class="wrap wodo-bridge-admin">
			<h1 class="wodo-bridge-admin__title"><?php echo esc_html__( 'WODO Bridge', 'wodo-bridge' ); ?></h1>
			<p class="wodo-bridge-admin__subtitle">
				<?php echo esc_html__( 'Scope-gated REST surface paired with the WODO Bridge aggregator.', 'wodo-bridge' ); ?>
			</p>

			<nav class="nav-tab-wrapper wodo-bridge-admin__tabs" aria-label="<?php echo esc_attr__( 'WODO Bridge sections', 'wodo-bridge' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::SLUG . '&tab=' . $slug ) ); ?>"
						class="nav-tab<?php echo $active_tab === $slug ? ' nav-tab-active' : ''; ?>"
					>
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<div class="wodo-bridge-admin__panel" data-active-tab="<?php echo esc_attr( $active_tab ); ?>">
				<?php
				switch ( $active_tab ) {
					case 'connections':
						$this->render_connections_tab( $aggregator_url, $site_url );
						break;
					case 'webhooks':
						$this->render_webhooks_tab();
						break;
					case 'activity':
						$this->render_activity_tab();
						break;
				}
				?>

				<div class="wodo-bridge-admin__notices" id="wodo-bridge-notices" aria-live="polite"></div>
			</div>
		</div>
		<?php
	}

	private function render_connections_tab( string $aggregator_url, string $site_url ): void {
		?>
		<section class="wodo-bridge-section" data-wodo-tab="connections">
			<h2 class="wodo-bridge-section__title"><?php echo esc_html__( 'Aggregator URL', 'wodo-bridge' ); ?></h2>
			<p class="wodo-bridge-section__hint">
				<?php echo esc_html__( 'The dashboard URL where this site is paired. Used to build the authorize link below.', 'wodo-bridge' ); ?>
			</p>
			<form method="post" class="wodo-bridge-aggregator-form">
				<?php wp_nonce_field( 'wodo_bridge_save_aggregator', 'wodo_bridge_aggregator_nonce' ); ?>
				<input type="hidden" name="wodo_bridge_save_aggregator" value="1" />
				<label class="screen-reader-text" for="wodo-bridge-aggregator-url"><?php echo esc_html__( 'Aggregator URL', 'wodo-bridge' ); ?></label>
				<input
					type="url"
					id="wodo-bridge-aggregator-url"
					name="wodo_bridge_aggregator_url"
					class="regular-text"
					placeholder="https://app.wodobridge.example"
					value="<?php echo esc_attr( $aggregator_url ); ?>"
				/>
				<button type="submit" class="button button-secondary"><?php echo esc_html__( 'Save', 'wodo-bridge' ); ?></button>
			</form>
		</section>

		<section class="wodo-bridge-section" data-wodo-tab="connections">
			<div class="wodo-bridge-section__header">
				<h2 class="wodo-bridge-section__title"><?php echo esc_html__( 'Add a new connection', 'wodo-bridge' ); ?></h2>
			</div>
			<div class="wodo-bridge-callout" id="wodo-bridge-authorize-callout">
				<p><?php echo esc_html__( 'Send the link below to the operator who needs access. They will be taken to this site\'s WordPress authorize page and routed back to the aggregator on approval.', 'wodo-bridge' ); ?></p>
				<div class="wodo-bridge-copy-row">
					<input
						type="text"
						readonly
						id="wodo-bridge-authorize-url"
						class="wodo-bridge-copy-row__input"
						aria-label="<?php echo esc_attr__( 'Authorize URL', 'wodo-bridge' ); ?>"
					/>
					<button
						type="button"
						class="button button-primary"
						data-wodo-action="copy-authorize"
					>
						<?php echo esc_html__( 'Copy link', 'wodo-bridge' ); ?>
					</button>
				</div>
				<p class="wodo-bridge-callout__meta">
					<?php
					/* translators: %s: site URL */
					echo esc_html( sprintf( __( 'Site: %s', 'wodo-bridge' ), $site_url ) );
					?>
				</p>
			</div>
		</section>

		<section class="wodo-bridge-section" data-wodo-tab="connections">
			<div class="wodo-bridge-section__header">
				<h2 class="wodo-bridge-section__title"><?php echo esc_html__( 'Active tokens', 'wodo-bridge' ); ?></h2>
				<div class="wodo-bridge-filters" role="group" aria-label="<?php echo esc_attr__( 'Token filters', 'wodo-bridge' ); ?>">
					<label>
						<span class="screen-reader-text"><?php echo esc_html__( 'Status', 'wodo-bridge' ); ?></span>
						<select id="wodo-bridge-token-status">
							<option value="active"><?php echo esc_html__( 'Active', 'wodo-bridge' ); ?></option>
							<option value="revoked"><?php echo esc_html__( 'Revoked', 'wodo-bridge' ); ?></option>
							<option value="all"><?php echo esc_html__( 'All', 'wodo-bridge' ); ?></option>
						</select>
					</label>
					<label>
						<span class="screen-reader-text"><?php echo esc_html__( 'Scope', 'wodo-bridge' ); ?></span>
						<select id="wodo-bridge-token-scope">
							<option value=""><?php echo esc_html__( 'All scopes', 'wodo-bridge' ); ?></option>
							<?php foreach ( Constants::SCOPES as $scope ) : ?>
								<option value="<?php echo esc_attr( $scope ); ?>"><?php echo esc_html( $scope ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>

			<div class="wodo-bridge-table-wrap" id="wodo-bridge-tokens-table-wrap">
				<table class="wp-list-table widefat fixed striped wodo-bridge-table">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Label', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'WP user', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Scopes', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Created', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last used', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'wodo-bridge' ); ?></th>
							<th scope="col" class="wodo-bridge-col-actions"><?php echo esc_html__( 'Actions', 'wodo-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody id="wodo-bridge-tokens-body">
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
					</tbody>
				</table>
			</div>

			<div class="wodo-bridge-pagination" id="wodo-bridge-tokens-pagination" aria-label="<?php echo esc_attr__( 'Tokens pagination', 'wodo-bridge' ); ?>"></div>
		</section>

		<dialog id="wodo-bridge-confirm-dialog" class="wodo-bridge-dialog">
			<form method="dialog" class="wodo-bridge-dialog__form">
				<h2 class="wodo-bridge-dialog__title" id="wodo-bridge-confirm-title"></h2>
				<p class="wodo-bridge-dialog__body" id="wodo-bridge-confirm-body"></p>
				<menu class="wodo-bridge-dialog__menu">
					<button type="button" value="cancel" class="button" data-wodo-dialog-cancel><?php echo esc_html__( 'Cancel', 'wodo-bridge' ); ?></button>
					<button type="button" value="confirm" class="button button-primary" data-wodo-dialog-confirm><?php echo esc_html__( 'Confirm', 'wodo-bridge' ); ?></button>
				</menu>
			</form>
		</dialog>
		<?php
	}

	private function render_webhooks_tab(): void {
		?>
		<section class="wodo-bridge-section" data-wodo-tab="webhooks">
			<div class="wodo-bridge-section__header">
				<h2 class="wodo-bridge-section__title"><?php echo esc_html__( 'Webhooks', 'wodo-bridge' ); ?></h2>
				<button
					type="button"
					class="button button-primary"
					data-wodo-action="open-add-webhook"
				>
					<?php echo esc_html__( 'Add webhook', 'wodo-bridge' ); ?>
				</button>
			</div>

			<div class="wodo-bridge-table-wrap" id="wodo-bridge-webhooks-table-wrap">
				<table class="wp-list-table widefat fixed striped wodo-bridge-table">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Target URL', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Events', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Last delivery', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'wodo-bridge' ); ?></th>
							<th scope="col" class="wodo-bridge-col-actions"><?php echo esc_html__( 'Actions', 'wodo-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody id="wodo-bridge-webhooks-body">
						<tr class="wodo-bridge-skeleton-row"><td colspan="5"><div class="wodo-bridge-skeleton"></div></td></tr>
						<tr class="wodo-bridge-skeleton-row"><td colspan="5"><div class="wodo-bridge-skeleton"></div></td></tr>
					</tbody>
				</table>
			</div>
		</section>

		<dialog id="wodo-bridge-webhook-add-dialog" class="wodo-bridge-dialog">
			<form method="dialog" class="wodo-bridge-dialog__form" id="wodo-bridge-webhook-add-form">
				<h2 class="wodo-bridge-dialog__title"><?php echo esc_html__( 'Add webhook', 'wodo-bridge' ); ?></h2>

				<label class="wodo-bridge-field">
					<span class="wodo-bridge-field__label"><?php echo esc_html__( 'Target URL', 'wodo-bridge' ); ?></span>
					<input type="url" name="target_url" required pattern="https://.*" placeholder="https://example.com/webhooks/wodo" class="regular-text" />
					<small class="wodo-bridge-field__hint"><?php echo esc_html__( 'Must be HTTPS. Public IPs only.', 'wodo-bridge' ); ?></small>
				</label>

				<fieldset class="wodo-bridge-field">
					<legend class="wodo-bridge-field__label"><?php echo esc_html__( 'Events', 'wodo-bridge' ); ?></legend>
					<?php foreach ( Constants::WEBHOOK_EVENTS as $event ) : ?>
						<label class="wodo-bridge-checkbox">
							<input type="checkbox" name="events[]" value="<?php echo esc_attr( $event ); ?>" />
							<span><?php echo esc_html( $event ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<menu class="wodo-bridge-dialog__menu">
					<button type="button" value="cancel" class="button" data-wodo-dialog-cancel><?php echo esc_html__( 'Cancel', 'wodo-bridge' ); ?></button>
					<button type="submit" value="confirm" class="button button-primary"><?php echo esc_html__( 'Create webhook', 'wodo-bridge' ); ?></button>
				</menu>
			</form>
		</dialog>

		<dialog id="wodo-bridge-webhook-secret-dialog" class="wodo-bridge-dialog">
			<form method="dialog" class="wodo-bridge-dialog__form">
				<h2 class="wodo-bridge-dialog__title"><?php echo esc_html__( 'Webhook secret', 'wodo-bridge' ); ?></h2>
				<p class="wodo-bridge-dialog__body"><?php echo esc_html__( 'Save this secret now. It is shown once and cannot be retrieved later.', 'wodo-bridge' ); ?></p>
				<div class="wodo-bridge-copy-row">
					<input type="text" readonly id="wodo-bridge-webhook-secret-value" class="wodo-bridge-copy-row__input" />
					<button type="button" class="button button-primary" data-wodo-action="copy-secret"><?php echo esc_html__( 'Copy', 'wodo-bridge' ); ?></button>
				</div>
				<menu class="wodo-bridge-dialog__menu">
					<button type="button" value="confirm" class="button button-primary" data-wodo-dialog-cancel><?php echo esc_html__( 'I have saved it', 'wodo-bridge' ); ?></button>
				</menu>
			</form>
		</dialog>

		<dialog id="wodo-bridge-webhook-deliveries-dialog" class="wodo-bridge-dialog wodo-bridge-dialog--wide">
			<form method="dialog" class="wodo-bridge-dialog__form">
				<h2 class="wodo-bridge-dialog__title"><?php echo esc_html__( 'Recent deliveries', 'wodo-bridge' ); ?></h2>
				<div id="wodo-bridge-deliveries-body" class="wodo-bridge-deliveries"></div>
				<menu class="wodo-bridge-dialog__menu">
					<button type="button" value="close" class="button" data-wodo-dialog-cancel><?php echo esc_html__( 'Close', 'wodo-bridge' ); ?></button>
				</menu>
			</form>
		</dialog>

		<dialog id="wodo-bridge-confirm-dialog" class="wodo-bridge-dialog">
			<form method="dialog" class="wodo-bridge-dialog__form">
				<h2 class="wodo-bridge-dialog__title" id="wodo-bridge-confirm-title"></h2>
				<p class="wodo-bridge-dialog__body" id="wodo-bridge-confirm-body"></p>
				<menu class="wodo-bridge-dialog__menu">
					<button type="button" value="cancel" class="button" data-wodo-dialog-cancel><?php echo esc_html__( 'Cancel', 'wodo-bridge' ); ?></button>
					<button type="button" value="confirm" class="button button-primary" data-wodo-dialog-confirm><?php echo esc_html__( 'Confirm', 'wodo-bridge' ); ?></button>
				</menu>
			</form>
		</dialog>
		<?php
	}

	private function render_activity_tab(): void {
		?>
		<section class="wodo-bridge-section" data-wodo-tab="activity">
			<div class="wodo-bridge-section__header">
				<h2 class="wodo-bridge-section__title"><?php echo esc_html__( 'Activity', 'wodo-bridge' ); ?></h2>
				<a
					href="#"
					class="button button-secondary"
					data-wodo-action="export-activity"
					id="wodo-bridge-export-activity"
				>
					<?php echo esc_html__( 'Export CSV', 'wodo-bridge' ); ?>
				</a>
			</div>

			<div class="wodo-bridge-filters" role="group" aria-label="<?php echo esc_attr__( 'Activity filters', 'wodo-bridge' ); ?>">
				<label>
					<span class="screen-reader-text"><?php echo esc_html__( 'Token', 'wodo-bridge' ); ?></span>
					<select id="wodo-bridge-activity-token">
						<option value=""><?php echo esc_html__( 'All tokens', 'wodo-bridge' ); ?></option>
					</select>
				</label>
				<label>
					<span class="screen-reader-text"><?php echo esc_html__( 'Endpoint', 'wodo-bridge' ); ?></span>
					<select id="wodo-bridge-activity-endpoint">
						<option value=""><?php echo esc_html__( 'All endpoints', 'wodo-bridge' ); ?></option>
					</select>
				</label>
				<label>
					<span class="screen-reader-text"><?php echo esc_html__( 'Status', 'wodo-bridge' ); ?></span>
					<select id="wodo-bridge-activity-status">
						<option value=""><?php echo esc_html__( 'All statuses', 'wodo-bridge' ); ?></option>
						<option value="success"><?php echo esc_html__( 'Success (2xx/3xx)', 'wodo-bridge' ); ?></option>
						<option value="error"><?php echo esc_html__( 'Error (4xx/5xx)', 'wodo-bridge' ); ?></option>
					</select>
				</label>
				<label>
					<span class="screen-reader-text"><?php echo esc_html__( 'From', 'wodo-bridge' ); ?></span>
					<input type="date" id="wodo-bridge-activity-from" />
				</label>
				<label>
					<span class="screen-reader-text"><?php echo esc_html__( 'To', 'wodo-bridge' ); ?></span>
					<input type="date" id="wodo-bridge-activity-to" />
				</label>
				<button type="button" class="button" data-wodo-action="apply-activity-filters"><?php echo esc_html__( 'Apply', 'wodo-bridge' ); ?></button>
			</div>

			<div class="wodo-bridge-table-wrap" id="wodo-bridge-activity-table-wrap">
				<table class="wp-list-table widefat fixed striped wodo-bridge-table wodo-bridge-table--activity">
					<thead>
						<tr>
							<th scope="col"><?php echo esc_html__( 'Timestamp', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Action', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Token', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Status', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Latency', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'IP', 'wodo-bridge' ); ?></th>
							<th scope="col"><?php echo esc_html__( 'Trace', 'wodo-bridge' ); ?></th>
						</tr>
					</thead>
					<tbody id="wodo-bridge-activity-body">
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
						<tr class="wodo-bridge-skeleton-row"><td colspan="7"><div class="wodo-bridge-skeleton"></div></td></tr>
					</tbody>
				</table>
			</div>

			<div class="wodo-bridge-pagination" id="wodo-bridge-activity-pagination" aria-label="<?php echo esc_attr__( 'Activity pagination', 'wodo-bridge' ); ?>"></div>
		</section>
		<?php
	}
}
