<?php
/**
 * Plugin Name:       WP Plugin Health Check
 * Plugin URI:        https://github.com/Azanumer/wp-plugin-health-check
 * Description:       Audits your active plugins against WordPress.org — flags abandoned plugins, untested WordPress versions and PHP incompatibilities, with a weekly email digest.
 * Version:           1.0.0
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Azan Umer
 * Author URI:        https://azanumer.cv
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-plugin-health-check
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPHC_VERSION', '1.0.0' );
define( 'WPHC_OPTION', 'wphc_settings' );
define( 'WPHC_CRON_HOOK', 'wphc_weekly_digest' );

/**
 * Default settings.
 *
 * @return array
 */
function wphc_default_settings() {
	return array(
		'abandoned_months' => 24,
		'email_digest'     => 1,
		'email_address'    => get_option( 'admin_email' ),
	);
}

/**
 * Settings merged over defaults.
 *
 * @return array
 */
function wphc_get_settings() {
	$saved = get_option( WPHC_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, wphc_default_settings() );
}

/**
 * Derive the WordPress.org slug for a plugin file path.
 *
 * Directory plugins use the directory name; single-file plugins use the
 * file name without the .php extension.
 *
 * @param string $plugin_file Plugin path relative to wp-content/plugins.
 * @return string
 */
function wphc_plugin_slug( $plugin_file ) {
	$dir = dirname( $plugin_file );
	if ( '.' === $dir ) {
		return basename( $plugin_file, '.php' );
	}
	return $dir;
}

/**
 * Fetch WordPress.org data for one plugin slug, cached 12 hours.
 *
 * Returns null when the plugin is not on WordPress.org or the API fails.
 *
 * @param string $slug Plugin slug.
 * @return array|null
 */
function wphc_fetch_org_data( $slug ) {
	$transient = 'wphc_api_' . sanitize_key( $slug );
	$cached    = get_transient( $transient );
	if ( false !== $cached ) {
		return $cached;
	}

	if ( ! function_exists( 'plugins_api' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	}

	$api = plugins_api(
		'plugin_information',
		array(
			'slug'   => $slug,
			'fields' => array(
				'sections'      => false,
				'tags'          => false,
				'versions'      => false,
				'active_installs' => true,
			),
		)
	);

	if ( is_wp_error( $api ) || ! is_object( $api ) || empty( $api->slug ) ) {
		// Cache failures briefly so a dead API does not hammer every load.
		set_transient( $transient, null, 15 * MINUTE_IN_SECONDS );
		return null;
	}

	$data = array(
		'slug'            => $api->slug,
		'last_updated'    => isset( $api->last_updated ) ? $api->last_updated : '',
		'tested'          => isset( $api->tested ) ? $api->tested : '',
		'requires_php'    => isset( $api->requires_php ) ? $api->requires_php : '',
		'requires'        => isset( $api->requires ) ? $api->requires : '',
		'active_installs' => isset( $api->active_installs ) ? (int) $api->active_installs : 0,
		'rating'          => isset( $api->rating ) ? (int) $api->rating : 0,
	);

	set_transient( $transient, $data, 12 * HOUR_IN_SECONDS );
	return $data;
}

/**
 * Audit every active plugin and return a health report.
 *
 * Each entry: name, version, slug, status (ok|warning|critical|unknown),
 * issues (string list), org data (or null).
 *
 * @return array
 */
function wphc_audit_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$settings  = wphc_get_settings();
	$wp_ver    = get_bloginfo( 'version' );
	$php_ver   = PHP_VERSION;
	$abandoned = (int) apply_filters( 'wphc_abandoned_months', (int) $settings['abandoned_months'] );
	$cutoff    = time() - ( $abandoned * 30 * DAY_IN_SECONDS );
	$all       = get_plugins();
	$active    = get_option( 'active_plugins', array() );
	$report    = array();

	foreach ( $active as $file ) {
		if ( ! isset( $all[ $file ] ) ) {
			continue;
		}

		$info   = $all[ $file ];
		$slug   = wphc_plugin_slug( $file );
		$org    = wphc_fetch_org_data( $slug );
		$issues = array();
		$status = 'ok';

		if ( null === $org ) {
			$status   = 'unknown';
			$issues[] = __( 'Not listed on WordPress.org — could not verify its update status.', 'wp-plugin-health-check' );
		} else {
			if ( ! empty( $org['last_updated'] ) ) {
				$updated_ts = strtotime( $org['last_updated'] );
				if ( false !== $updated_ts && $updated_ts < $cutoff ) {
					$status   = 'critical';
					$issues[] = sprintf(
						/* translators: %s: last updated date. */
						__( 'Abandoned: last updated %s (over %d months ago).', 'wp-plugin-health-check' ),
						date_i18n( get_option( 'date_format' ), $updated_ts ),
						$abandoned
					);
				}
			}

			if ( ! empty( $org['tested'] ) && version_compare( $wp_ver, $org['tested'], '>' ) ) {
				if ( 'critical' !== $status ) {
					$status = 'warning';
				}
				$issues[] = sprintf(
					/* translators: 1: tested WP version, 2: current WP version. */
					__( 'Only tested up to WordPress %1$s — you run %2$s.', 'wp-plugin-health-check' ),
					$org['tested'],
					$wp_ver
				);
			}

			if ( ! empty( $org['requires_php'] ) && version_compare( $php_ver, $org['requires_php'], '<' ) ) {
				$status   = 'critical';
				$issues[] = sprintf(
					/* translators: 1: required PHP version, 2: current PHP version. */
					__( 'Requires PHP %1$s — this server runs PHP %2$s.', 'wp-plugin-health-check' ),
					$org['requires_php'],
					$php_ver
				);
			}
		}

		$report[] = array(
			'name'    => $info['Name'],
			'version' => $info['Version'],
			'slug'    => $slug,
			'status'  => $status,
			'issues'  => $issues,
			'org'     => $org,
		);
	}

	return $report;
}

/**
 * Count report entries by status.
 *
 * @param array  $report Report from wphc_audit_plugins().
 * @param string $status Status to count.
 * @return int
 */
function wphc_count_status( $report, $status ) {
	$count = 0;
	foreach ( $report as $entry ) {
		if ( $entry['status'] === $status ) {
			$count++;
		}
	}
	return $count;
}

/**
 * Register the Tools page.
 */
function wphc_register_page() {
	add_management_page(
		__( 'Plugin Health', 'wp-plugin-health-check' ),
		__( 'Plugin Health', 'wp-plugin-health-check' ),
		'manage_options',
		'wp-plugin-health-check',
		'wphc_render_page'
	);
}
add_action( 'admin_menu', 'wphc_register_page' );

/**
 * Render the Tools -> Plugin Health page.
 */
function wphc_render_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to view this page.', 'wp-plugin-health-check' ) );
	}

	// Handle the settings form.
	if ( isset( $_POST['wphc_save_settings'] ) ) {
		check_admin_referer( 'wphc_settings' );

		$settings = array(
			'abandoned_months' => max( 3, (int) $_POST['wphc_abandoned_months'] ),
			'email_digest'     => isset( $_POST['wphc_email_digest'] ) ? 1 : 0,
			'email_address'    => sanitize_email( wp_unslash( $_POST['wphc_email_address'] ) ),
		);
		update_option( WPHC_OPTION, $settings );

		// Flush cached API data so the new threshold applies immediately.
		$all = get_plugins();
		foreach ( array_keys( $all ) as $file ) {
			delete_transient( 'wphc_api_' . sanitize_key( wphc_plugin_slug( $file ) ) );
		}

		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'wp-plugin-health-check' ) . '</p></div>';
	}

	$refresh  = isset( $_GET['wphc_refresh'] ) && '1' === $_GET['wphc_refresh'];
	$settings = wphc_get_settings();

	if ( $refresh ) {
		check_admin_referer( 'wphc_refresh' );
		$all = get_plugins();
		foreach ( array_keys( $all ) as $file ) {
			delete_transient( 'wphc_api_' . sanitize_key( wphc_plugin_slug( $file ) ) );
		}
	}

	$report    = wphc_audit_plugins();
	$critical  = wphc_count_status( $report, 'critical' );
	$warning   = wphc_count_status( $report, 'warning' );
	$unknown   = wphc_count_status( $report, 'unknown' );
	$ok        = wphc_count_status( $report, 'ok' );
	$refresh_url = wp_nonce_url( admin_url( 'tools.php?page=wp-plugin-health-check&wphc_refresh=1' ), 'wphc_refresh' );

	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Plugin Health', 'wp-plugin-health-check' ); ?></h1>
		<p><?php esc_html_e( 'Each active plugin is checked against WordPress.org: last update date, tested WordPress version and required PHP version.', 'wp-plugin-health-check' ); ?></p>
		<p>
			<a href="<?php echo esc_url( $refresh_url ); ?>" class="button"><?php esc_html_e( 'Refresh data', 'wp-plugin-health-check' ); ?></a>
			<span style="margin-left:12px;">
				<strong><?php echo (int) $critical; ?></strong> <?php esc_html_e( 'critical', 'wp-plugin-health-check' ); ?> &middot;
				<strong><?php echo (int) $warning; ?></strong> <?php esc_html_e( 'warning', 'wp-plugin-health-check' ); ?> &middot;
				<strong><?php echo (int) $unknown; ?></strong> <?php esc_html_e( 'unknown', 'wp-plugin-health-check' ); ?> &middot;
				<strong><?php echo (int) $ok; ?></strong> <?php esc_html_e( 'healthy', 'wp-plugin-health-check' ); ?>
			</span>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Plugin', 'wp-plugin-health-check' ); ?></th>
					<th><?php esc_html_e( 'Status', 'wp-plugin-health-check' ); ?></th>
					<th><?php esc_html_e( 'Last updated', 'wp-plugin-health-check' ); ?></th>
					<th><?php esc_html_e( 'Tested up to', 'wp-plugin-health-check' ); ?></th>
					<th><?php esc_html_e( 'Issues', 'wp-plugin-health-check' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $report as $entry ) : ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $entry['name'] ); ?></strong><br>
						<span class="description">v<?php echo esc_html( $entry['version'] ); ?> &middot; <?php echo esc_html( $entry['slug'] ); ?></span>
					</td>
					<td>
						<?php if ( 'ok' === $entry['status'] ) : ?>
							<span style="color:#008000;">&#10003; <?php esc_html_e( 'Healthy', 'wp-plugin-health-check' ); ?></span>
						<?php elseif ( 'warning' === $entry['status'] ) : ?>
							<span style="color:#b26a00;">&#9888; <?php esc_html_e( 'Warning', 'wp-plugin-health-check' ); ?></span>
						<?php elseif ( 'critical' === $entry['status'] ) : ?>
							<span style="color:#b32d2e;">&#10007; <?php esc_html_e( 'Critical', 'wp-plugin-health-check' ); ?></span>
						<?php else : ?>
							<span style="color:#666;">? <?php esc_html_e( 'Unknown', 'wp-plugin-health-check' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php
						if ( ! empty( $entry['org']['last_updated'] ) ) {
							$ts = strtotime( $entry['org']['last_updated'] );
							echo esc_html( false !== $ts ? date_i18n( get_option( 'date_format' ), $ts ) : $entry['org']['last_updated'] );
						} else {
							echo '&mdash;';
						}
						?>
					</td>
					<td><?php echo ! empty( $entry['org']['tested'] ) ? esc_html( $entry['org']['tested'] ) : '&mdash;'; ?></td>
					<td>
						<?php if ( empty( $entry['issues'] ) ) : ?>
							&mdash;
						<?php else : ?>
							<ul style="margin:0;">
							<?php foreach ( $entry['issues'] as $issue ) : ?>
								<li><?php echo esc_html( $issue ); ?></li>
							<?php endforeach; ?>
							</ul>
						<?php endif; ?>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Settings', 'wp-plugin-health-check' ); ?></h2>
		<form method="post" action="">
			<?php wp_nonce_field( 'wphc_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wphc_abandoned_months"><?php esc_html_e( 'Abandoned after (months)', 'wp-plugin-health-check' ); ?></label></th>
					<td><input type="number" id="wphc_abandoned_months" name="wphc_abandoned_months" value="<?php echo (int) $settings['abandoned_months']; ?>" min="3" max="120" class="small-text">
					<p class="description"><?php esc_html_e( 'A plugin is flagged as abandoned when WordPress.org shows no update for this long.', 'wp-plugin-health-check' ); ?></p></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Weekly email digest', 'wp-plugin-health-check' ); ?></th>
					<td><label><input type="checkbox" name="wphc_email_digest" value="1" <?php checked( $settings['email_digest'], 1 ); ?>> <?php esc_html_e( 'Email me when a plugin turns critical', 'wp-plugin-health-check' ); ?></label></td>
				</tr>
				<tr>
					<th scope="row"><label for="wphc_email_address"><?php esc_html_e( 'Digest email address', 'wp-plugin-health-check' ); ?></label></th>
					<td><input type="email" id="wphc_email_address" name="wphc_email_address" value="<?php echo esc_attr( $settings['email_address'] ); ?>" class="regular-text"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Settings', 'wp-plugin-health-check' ), 'primary', 'wphc_save_settings' ); ?>
		</form>
	</div>
	<?php
}

/**
 * Weekly digest: email the admin only when a plugin is critical.
 */
function wphc_weekly_digest() {
	$settings = wphc_get_settings();
	if ( empty( $settings['email_digest'] ) || empty( $settings['email_address'] ) ) {
		return;
	}

	$report   = wphc_audit_plugins();
	$critical = array();
	foreach ( $report as $entry ) {
		if ( 'critical' === $entry['status'] ) {
			$critical[] = $entry;
		}
	}

	if ( empty( $critical ) ) {
		return;
	}

	$lines   = array();
	$lines[] = __( 'The following plugins on your site need attention:', 'wp-plugin-health-check' );
	$lines[] = '';
	foreach ( $critical as $entry ) {
		$lines[] = '- ' . $entry['name'] . ' v' . $entry['version'];
		foreach ( $entry['issues'] as $issue ) {
			$lines[] = '    ' . $issue;
		}
	}
	$lines[] = '';
	$lines[] = admin_url( 'tools.php?page=wp-plugin-health-check' );

	wp_mail(
		$settings['email_address'],
		sprintf(
			/* translators: %s: site name. */
			__( '[%s] Plugin health alert', 'wp-plugin-health-check' ),
			get_bloginfo( 'name' )
		),
		implode( "\n", $lines )
	);
}
add_action( WPHC_CRON_HOOK, 'wphc_weekly_digest' );

/**
 * Schedule the weekly digest on activation.
 */
function wphc_activate() {
	if ( ! wp_next_scheduled( WPHC_CRON_HOOK ) ) {
		wp_schedule_event( time(), 'weekly', WPHC_CRON_HOOK );
	}
}
register_activation_hook( __FILE__, 'wphc_activate' );

/**
 * Unschedule on deactivation.
 */
function wphc_deactivate() {
	$timestamp = wp_next_scheduled( WPHC_CRON_HOOK );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, WPHC_CRON_HOOK );
	}
}
register_deactivation_hook( __FILE__, 'wphc_deactivate' );
