<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen: Settings > Cookie Consent.
 *
 * Everything about how the banner looks and what it says is edited here. Nothing needs to be
 * written into a theme file.
 */
class CBXCC_Settings {

	const PAGE  = 'cbx-cookie-consent';
	const GROUP = 'cbxcc_group';

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( CBXCC_FILE ), array( $this, 'action_link' ) );
	}

	public function menu() {
		add_options_page(
			'Cookie Consent',
			'Cookie Consent',
			'manage_options',
			self::PAGE,
			array( $this, 'render' )
		);
	}

	public function action_link( $links ) {
		$url = admin_url( 'options-general.php?page=' . self::PAGE );
		array_unshift( $links, '<a href="' . esc_url( $url ) . '">Settings</a>' );
		return $links;
	}

	public function assets( $hook ) {
		if ( 'settings_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-color-picker' );
		wp_enqueue_script( 'wp-color-picker' );
		wp_add_inline_script(
			'wp-color-picker',
			'jQuery(function($){$(".cbxcc-color").wpColorPicker();});'
		);
	}

	public function register() {
		register_setting(
			self::GROUP,
			CBXCC_OPTION,
			array( 'sanitize_callback' => array( $this, 'sanitize' ) )
		);
	}

	/**
	 * Only one tab is submitted at a time, so merge over what is already saved rather than
	 * replacing it. Without this, saving the Text tab would wipe the Design tab.
	 */
	public function sanitize( $input ) {
		$current  = wp_parse_args( get_option( CBXCC_OPTION, array() ), cbxcc_defaults() );
		$input    = is_array( $input ) ? $input : array();
		$out      = $current;

		$colors   = array( 'bg', 'fg', 'muted', 'border', 'accept_bg', 'accept_fg', 'reject_bg', 'reject_fg' );
		$plain    = array(
			'title', 'accept_label', 'reject_label', 'prefs_label', 'save_label', 'policy_label',
			'cat_necessary', 'cat_analytics', 'cat_marketing',
		);
		$textarea = array( 'body', 'cat_necessary_desc', 'cat_analytics_desc', 'cat_marketing_desc' );
		$ints     = array(
			'offset'             => array( 0, 80 ),
			'radius'             => array( 0, 40 ),
			'button_radius'      => array( 0, 200 ),
			'max_width'          => array( 280, 900 ),
			'remember_days'      => array( 1, 730 ),
			'policy_version'     => array( 1, 9999 ),
			'log_retention_days' => array( 0, 3650 ),
		);

		foreach ( $colors as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$c = sanitize_hex_color( $input[ $k ] );
				if ( $c ) {
					$out[ $k ] = $c;
				}
			}
		}
		foreach ( $plain as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( $input[ $k ] );
			}
		}
		foreach ( $textarea as $k ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = sanitize_textarea_field( $input[ $k ] );
			}
		}
		foreach ( $ints as $k => $range ) {
			if ( isset( $input[ $k ] ) ) {
				$out[ $k ] = max( $range[0], min( $range[1], (int) $input[ $k ] ) );
			}
		}

		if ( isset( $input['policy_url'] ) ) {
			$out['policy_url'] = esc_url_raw( trim( $input['policy_url'] ) );
		}
		if ( isset( $input['position'] ) ) {
			$allowed          = array( 'bottom-left', 'bottom-right', 'bottom-center', 'bottom-bar' );
			$out['position']  = in_array( $input['position'], $allowed, true ) ? $input['position'] : 'bottom-left';
		}
		if ( isset( $input['default_outside_eu'] ) ) {
			$out['default_outside_eu'] = ( 'denied' === $input['default_outside_eu'] ) ? 'denied' : 'granted';
		}
		if ( isset( $input['log_ip'] ) ) {
			$allowed        = array( 'none', 'anonymised', 'full' );
			$out['log_ip']  = in_array( $input['log_ip'], $allowed, true ) ? $input['log_ip'] : 'none';
		}

		// Checkboxes: only trust the hidden marker that says this tab was the one submitted.
		if ( isset( $input['_tab'] ) && 'behaviour' === $input['_tab'] ) {
			$out['enabled'] = empty( $input['enabled'] ) ? 0 : 1;
			$out['logging'] = empty( $input['logging'] ) ? 0 : 1;
		}

		return $out;
	}

	private function tabs() {
		return array(
			'design'    => 'Design',
			'text'      => 'Wording',
			'behaviour' => 'Behaviour',
			'log'       => 'Consent Log',
			'help'      => 'Setup',
		);
	}

	public function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s   = cbxcc_settings();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'design'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! array_key_exists( $tab, $this->tabs() ) ) {
			$tab = 'design';
		}
		?>
		<div class="wrap">
			<h1>Cookie Consent</h1>
			<p style="max-width:70em">
				Controls the cookie banner on the front of the site and, through Google Consent Mode,
				whether Google Analytics is allowed to track a visitor.
				<?php if ( ! $s['enabled'] ) : ?>
					<strong style="color:#b32d2e">The banner is currently switched off under Behaviour.</strong>
				<?php endif; ?>
			</p>

			<h2 class="nav-tab-wrapper">
				<?php foreach ( $this->tabs() as $key => $label ) : ?>
					<a href="<?php echo esc_url( admin_url( 'options-general.php?page=' . self::PAGE . '&tab=' . $key ) ); ?>"
					   class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</h2>

			<?php
			if ( 'log' === $tab ) {
				$this->render_log( $s );
				return;
			}
			if ( 'help' === $tab ) {
				$this->render_help( $s );
				return;
			}
			?>

			<form method="post" action="options.php">
				<?php settings_fields( self::GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( CBXCC_OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<?php
				if ( 'design' === $tab ) {
					$this->render_design( $s );
				} elseif ( 'text' === $tab ) {
					$this->render_text( $s );
				} else {
					$this->render_behaviour( $s );
				}
				submit_button();
				?>
			</form>
		</div>
		<?php
	}

	private function name( $key ) {
		return CBXCC_OPTION . '[' . $key . ']';
	}

	private function color_row( $label, $key, $val, $hint = '' ) {
		?>
		<tr>
			<th scope="row"><label><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input type="text" class="cbxcc-color" name="<?php echo esc_attr( $this->name( $key ) ); ?>"
				       value="<?php echo esc_attr( $val ); ?>" data-default-color="<?php echo esc_attr( $val ); ?>">
				<?php if ( $hint ) : ?><p class="description"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function text_row( $label, $key, $val, $hint = '', $rows = 0 ) {
		?>
		<tr>
			<th scope="row"><label for="cbxcc-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<?php if ( $rows ) : ?>
					<textarea id="cbxcc-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( $this->name( $key ) ); ?>"
					          rows="<?php echo (int) $rows; ?>" class="large-text"><?php echo esc_textarea( $val ); ?></textarea>
				<?php else : ?>
					<input type="text" id="cbxcc-<?php echo esc_attr( $key ); ?>" class="regular-text"
					       name="<?php echo esc_attr( $this->name( $key ) ); ?>" value="<?php echo esc_attr( $val ); ?>">
				<?php endif; ?>
				<?php if ( $hint ) : ?><p class="description"><?php echo esc_html( $hint ); ?></p><?php endif; ?>
			</td>
		</tr>
		<?php
	}

	private function render_design( $s ) {
		?>
		<h2>Position and shape</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="cbxcc-position">Position</label></th>
				<td>
					<select id="cbxcc-position" name="<?php echo esc_attr( $this->name( 'position' ) ); ?>">
						<?php
						$opts = array(
							'bottom-left'   => 'Bottom left',
							'bottom-center' => 'Bottom centre',
							'bottom-right'  => 'Bottom right',
							'bottom-bar'    => 'Full width bar along the bottom',
						);
						foreach ( $opts as $k => $l ) {
							printf( '<option value="%s"%s>%s</option>', esc_attr( $k ), selected( $s['position'], $k, false ), esc_html( $l ) );
						}
						?>
					</select>
					<p class="description">Avoid bottom right if the site has a floating accessibility or chat button there. Those buttons usually sit on top of everything and cannot be covered.</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-max_width">Width</label></th>
				<td><input type="number" id="cbxcc-max_width" name="<?php echo esc_attr( $this->name( 'max_width' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['max_width'] ); ?>" min="280" max="900" class="small-text"> pixels
				    <p class="description">Ignored when the position is a full width bar.</p></td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-offset">Distance from the edge</label></th>
				<td><input type="number" id="cbxcc-offset" name="<?php echo esc_attr( $this->name( 'offset' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['offset'] ); ?>" min="0" max="80" class="small-text"> pixels</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-radius">Corner rounding, box</label></th>
				<td><input type="number" id="cbxcc-radius" name="<?php echo esc_attr( $this->name( 'radius' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['radius'] ); ?>" min="0" max="40" class="small-text"> pixels</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-button_radius">Corner rounding, buttons</label></th>
				<td><input type="number" id="cbxcc-button_radius" name="<?php echo esc_attr( $this->name( 'button_radius' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['button_radius'] ); ?>" min="0" max="200" class="small-text"> pixels
				    <p class="description">Use a large number such as 200 for fully rounded pill buttons, to match a site whose buttons are pills.</p></td>
			</tr>
		</table>

		<h2>Colours</h2>
		<table class="form-table" role="presentation">
			<?php
			$this->color_row( 'Background', 'bg', $s['bg'] );
			$this->color_row( 'Heading text', 'fg', $s['fg'] );
			$this->color_row( 'Body text', 'muted', $s['muted'], 'Also used for the small print and the preferences link.' );
			$this->color_row( 'Border', 'border', $s['border'] );
			$this->color_row( 'Accept button background', 'accept_bg', $s['accept_bg'] );
			$this->color_row( 'Accept button text', 'accept_fg', $s['accept_fg'] );
			$this->color_row( 'Decline button background', 'reject_bg', $s['reject_bg'] );
			$this->color_row( 'Decline button text', 'reject_fg', $s['reject_fg'] );
			?>
		</table>
		<p class="description" style="max-width:70em">
			Keep the two buttons similarly prominent. Making Decline hard to find raises the number of
			people who accept, and it is the specific thing regulators call a dark pattern.
		</p>
		<?php
	}

	private function render_text( $s ) {
		?>
		<h2>The banner</h2>
		<table class="form-table" role="presentation">
			<?php
			$this->text_row( 'Heading', 'title', $s['title'] );
			$this->text_row( 'Message', 'body', $s['body'], '', 3 );
			$this->text_row( 'Accept button', 'accept_label', $s['accept_label'] );
			$this->text_row( 'Decline button', 'reject_label', $s['reject_label'] );
			$this->text_row( 'Preferences link', 'prefs_label', $s['prefs_label'] );
			$this->text_row( 'Save button', 'save_label', $s['save_label'] );
			$this->text_row( 'Policy link text', 'policy_label', $s['policy_label'] );
			$this->text_row( 'Policy link address', 'policy_url', $s['policy_url'], 'Leave blank to hide the link.' );
			?>
		</table>

		<h2>The three categories</h2>
		<p class="description" style="max-width:70em">Shown when somebody opens the preferences panel.</p>
		<table class="form-table" role="presentation">
			<?php
			$this->text_row( 'Necessary, name', 'cat_necessary', $s['cat_necessary'] );
			$this->text_row( 'Necessary, description', 'cat_necessary_desc', $s['cat_necessary_desc'], '', 2 );
			$this->text_row( 'Analytics, name', 'cat_analytics', $s['cat_analytics'] );
			$this->text_row( 'Analytics, description', 'cat_analytics_desc', $s['cat_analytics_desc'], '', 2 );
			$this->text_row( 'Advertising, name', 'cat_marketing', $s['cat_marketing'] );
			$this->text_row( 'Advertising, description', 'cat_marketing_desc', $s['cat_marketing_desc'], '', 2 );
			?>
		</table>
		<?php
	}

	private function render_behaviour( $s ) {
		?>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Banner</th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'enabled' ) ); ?>" value="1"
					<?php checked( $s['enabled'], 1 ); ?>> Show the cookie banner on the site</label></td>
			</tr>
			<tr>
				<th scope="row">Visitors outside Europe</th>
				<td>
					<label><input type="radio" name="<?php echo esc_attr( $this->name( 'default_outside_eu' ) ); ?>" value="granted"
						<?php checked( $s['default_outside_eu'], 'granted' ); ?>>
						<strong>Track until they decline.</strong> Analytics runs straight away, and the banner lets them turn it off.</label><br>
					<label><input type="radio" name="<?php echo esc_attr( $this->name( 'default_outside_eu' ) ); ?>" value="denied"
						<?php checked( $s['default_outside_eu'], 'denied' ); ?>>
						<strong>Wait until they accept.</strong> Nothing tracks anyone until they click Accept.</label>
					<p class="description" style="max-width:60em">
						Visitors in Europe and the UK are always asked first, whichever of these is chosen.
						The first option keeps almost all of the site's analytics. The second is stricter and
						will lose a meaningful share of it.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-remember_days">Remember a choice for</label></th>
				<td><input type="number" id="cbxcc-remember_days" name="<?php echo esc_attr( $this->name( 'remember_days' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['remember_days'] ); ?>" min="1" max="730" class="small-text"> days</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-policy_version">Policy version</label></th>
				<td>
					<input type="number" id="cbxcc-policy_version" name="<?php echo esc_attr( $this->name( 'policy_version' ) ); ?>"
					       value="<?php echo esc_attr( (int) $s['policy_version'] ); ?>" min="1" max="9999" class="small-text">
					<p class="description">Raise this number by one if the cookie policy changes in a meaningful way. Everyone will be asked again.</p>
				</td>
			</tr>
		</table>

		<h2>Consent log</h2>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row">Keep a record</th>
				<td><label><input type="checkbox" name="<?php echo esc_attr( $this->name( 'logging' ) ); ?>" value="1"
					<?php checked( $s['logging'], 1 ); ?>> Record every Accept and Decline in the database</label></td>
			</tr>
			<tr>
				<th scope="row">IP addresses</th>
				<td>
					<?php
					$ipopts = array(
						'none'       => 'Do not record them (recommended)',
						'anonymised' => 'Record a blurred version, with the last part removed',
						'full'       => 'Record the full address',
					);
					foreach ( $ipopts as $k => $l ) {
						printf(
							'<label><input type="radio" name="%s" value="%s"%s> %s</label><br>',
							esc_attr( $this->name( 'log_ip' ) ),
							esc_attr( $k ),
							checked( $s['log_ip'], $k, false ),
							esc_html( $l )
						);
					}
					?>
					<p class="description" style="max-width:60em">
						An IP address is itself personal information, so storing one to prove that somebody
						declined tracking works against the point. The record already has a unique reference,
						a timestamp, the choice made and the page it was made on, which is what the
						mainstream cookie tools keep.
					</p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="cbxcc-log_retention_days">Delete records after</label></th>
				<td><input type="number" id="cbxcc-log_retention_days" name="<?php echo esc_attr( $this->name( 'log_retention_days' ) ); ?>"
				           value="<?php echo esc_attr( (int) $s['log_retention_days'] ); ?>" min="0" max="3650" class="small-text"> days
				    <p class="description">Set to 0 to keep them forever.</p></td>
			</tr>
		</table>
		<?php
	}

	private function render_log( $s ) {
		$total  = CBXCC_Log::count();
		$rows   = CBXCC_Log::recent( 50 );
		$export = wp_nonce_url( admin_url( 'options-general.php?page=' . self::PAGE . '&cbxcc_export=1' ), 'cbxcc_export' );
		$clear  = wp_nonce_url( admin_url( 'options-general.php?page=' . self::PAGE . '&cbxcc_clear=1' ), 'cbxcc_clear' );
		?>
		<?php if ( isset( $_GET['cleared'] ) ) : // phpcs:ignore ?>
			<div class="notice notice-success is-dismissible"><p>Consent log cleared.</p></div>
		<?php endif; ?>

		<p style="max-width:70em">
			<?php if ( ! $s['logging'] ) : ?>
				<strong>Logging is switched off under Behaviour.</strong>
			<?php else : ?>
				Every Accept and Decline is recorded here.
				<strong><?php echo esc_html( number_format_i18n( $total ) ); ?></strong> record<?php echo 1 === $total ? '' : 's'; ?> stored,
				showing the most recent 50.
			<?php endif; ?>
		</p>

		<p>
			<a href="<?php echo esc_url( $export ); ?>" class="button">Download all as CSV</a>
			<a href="<?php echo esc_url( $clear ); ?>" class="button button-link-delete"
			   onclick="return confirm('Delete every consent record? This cannot be undone.');">Clear the log</a>
		</p>

		<table class="wp-list-table widefat striped">
			<thead><tr>
				<th>When (UTC)</th><th>Analytics</th><th>Advertising</th><th>Page</th>
				<th>Policy</th><th>Region</th><th>Reference</th>
				<?php if ( 'none' !== $s['log_ip'] ) : ?><th>IP</th><?php endif; ?>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="8">Nothing recorded yet. Visit the site in a private window and click a button on the banner.</td></tr>
			<?php else : ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r->created_at ); ?></td>
						<td><?php echo $r->analytics ? '<span style="color:#1f6f43">Allowed</span>' : '<span style="color:#b32d2e">Declined</span>'; ?></td>
						<td><?php echo $r->marketing ? '<span style="color:#1f6f43">Allowed</span>' : '<span style="color:#b32d2e">Declined</span>'; ?></td>
						<td><?php echo esc_html( $r->page ); ?></td>
						<td><?php echo esc_html( $r->policy_version ); ?></td>
						<td><?php echo esc_html( $r->region ); ?></td>
						<td><code style="font-size:11px"><?php echo esc_html( substr( $r->consent_id, 0, 13 ) ); ?></code></td>
						<?php if ( 'none' !== $s['log_ip'] ) : ?><td><?php echo esc_html( $r->ip ); ?></td><?php endif; ?>
					</tr>
				<?php endforeach; ?>
			<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_help( $s ) {
		$site = home_url( '/' );
		?>
		<div style="max-width:70em">
			<h2>Two things to set up once</h2>

			<h3>1. Add a "Cookie settings" link to the footer</h3>
			<p>So people can change their mind later. Put this link anywhere, usually next to the Privacy Policy link:</p>
			<p><code>&lt;a href="#cookie-settings"&gt;Cookie settings&lt;/a&gt;</code></p>
			<p>In Elementor, add a normal link and set its address to <code>#cookie-settings</code>. Clicking it reopens the banner.</p>

			<h3>2. Google Tag Manager, only if the site has a Facebook pixel</h3>
			<p>
				Google Analytics is handled automatically. A Facebook pixel is not, because it ignores
				Google's consent system. In Tag Manager create a trigger of type <strong>Custom Event</strong>
				named exactly:
			</p>
			<p><code>cbx_consent_marketing_granted</code></p>
			<p>
				Then open the Facebook pixel tag and make that its only trigger, replacing All Pages.
				Do not tick the tag's own "Require additional consent" box, which does not work the way
				it reads.
			</p>

			<h2>Checking it works</h2>
			<ol>
				<li>Open <a href="<?php echo esc_url( $site ); ?>" target="_blank" rel="noopener">the site</a> in a private window. The banner should appear.</li>
				<li>Click Decline, then look at the Consent Log tab here. A record should appear.</li>
				<li>Reload the site. The banner should stay away.</li>
				<li>Click the footer "Cookie settings" link. The banner should come back.</li>
			</ol>

			<h2>Good to know</h2>
			<ul style="list-style:disc;padding-left:22px">
				<li>The banner never changes the page layout, so it cannot affect page speed scores.</li>
				<li>Nothing is loaded from another company. No subscription, no per-visit limits.</li>
				<li>If the site caches pages, no cache clearing is needed when wording or colours change here, because the banner is drawn in the visitor's browser.</li>
				<li>Declining also deletes tracking already stored on that visitor's device.</li>
			</ul>
		</div>
		<?php
	}
}
