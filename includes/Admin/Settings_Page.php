<?php

namespace Submex\Admin;

use Submex\Emails\Notification_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submex → Settings.
 *
 * The fields are WooCommerce's own definitions, read from the settings tab and its filters,
 * so free and Pro declare a setting once and it appears here, saved by Woo's own handler.
 * Only the layout is ours: WooCommerce's sections are gathered into the menu's groups.
 */
class Settings_Page {

	public const SLUG = Menu::SLUG . '-settings';

	private const NONCE = 'submex_save_settings';

	/**
	 * A section missing from every group's list lands in Integrations.
	 */
	private const FALLBACK_GROUP = 'integrations';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_page' ), 95 );
		add_action( 'submex_app_enqueue', array( $this, 'enqueue_route' ) );
		add_action( 'load-woocommerce_page_wc-settings', array( $this, 'redirect_woo_tab' ) );
	}

	/**
	 * Bookmarks and older links to the WooCommerce tab land here, keeping whatever else they carry.
	 */
	public function redirect_woo_tab(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		if ( ! isset( $_GET['tab'] ) || 'submex' !== sanitize_key( wp_unslash( $_GET['tab'] ) ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$args = map_deep( wp_unslash( $_GET ), 'sanitize_text_field' );
		unset( $args['page'], $args['tab'] );

		wp_safe_redirect( add_query_arg( urlencode_deep( $args ), self::section_url() ) );
		exit;
	}

	/**
	 * On every app page, not just this one: the shell swaps screens without a page load.
	 */
	public function enqueue_route(): void {
		$asset = SUBMEX_PATH . 'build/settings.asset.php';

		if ( ! is_readable( $asset ) ) {
			return;
		}

		$asset = require $asset;

		wp_enqueue_script(
			'submex-settings',
			SUBMEX_URL . 'build/settings.js',
			array_merge( $asset['dependencies'], array( 'submex-ui', 'submex-shell' ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( 'submex-settings', 'submex', SUBMEX_PATH . 'languages' );
	}

	public function add_page(): void {
		$hook = add_submenu_page(
			Menu::PARENT,
			__( 'Settings', 'submex' ),
			__( 'Settings', 'submex' ),
			Menu::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);

		// This screen's own load, not admin_init: the licence buttons post to admin-post.php
		// carrying this form's fields, and an admin_init handler would answer for them.
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'maybe_save' ) );
		}
	}

	/**
	 * Saved by WooCommerce, which reads $_POST itself and applies each field's own
	 * sanitisation - the same path as its settings screen. Every section drawn on the
	 * page is saved, since a group stacks several into one form.
	 */
	public function maybe_save(): void {
		if ( ! isset( $_POST['submex_settings_nonce'] ) || ! current_user_can( Menu::CAPABILITY ) ) {
			return;
		}

		check_admin_referer( self::NONCE, 'submex_settings_nonce' );

		$section  = isset( $_POST['submex_section'] ) ? sanitize_key( wp_unslash( $_POST['submex_section'] ) ) : '';
		$sections = $this->sections();

		if ( ! isset( $sections[ $section ] ) ) {
			return;
		}

		if ( ! $this->save( $section, $sections ) ) {
			return;
		}

		wp_safe_redirect( add_query_arg( 'submex_saved', '1', self::section_url( $section ) ) );
		exit;
	}

	/**
	 * Saves the page as WooCommerce's tab does; given values save only the fields they name, as Woo blanks the rest.
	 *
	 * @param array<string, string>     $sections
	 * @param array<string, mixed>|null $values Unslashed, keyed by option id.
	 */
	public function save( string $section, array $sections, ?array $values = null ): bool {
		$saved = array();

		foreach ( $this->page_sections( $section, $sections ) as $id ) {
			$fields = $this->settings_for( $id );

			if ( null !== $values ) {
				$fields = array_values( array_filter( $fields, static fn( array $field ): bool => array_key_exists( (string) ( $field['id'] ?? '' ), $values ) ) );
			}

			if ( $fields ) {
				\WC_Admin_Settings::save_fields( $fields, null === $values ? null : wp_slash( self::as_posted( $values ) ) );
				$saved[] = $id;
			}
		}

		// After the whole stack is written: a section's checks may read another's settings.
		foreach ( array_filter( $saved, static fn( string $id ): bool => '' !== $id ) as $id ) {
			do_action( 'woocommerce_update_options_submex_' . $id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's own settings hook.
		}

		return (bool) $saved;
	}

	/**
	 * An array-option id such as woocommerce_x_settings[enabled] nested as a browser posts it, which is where WooCommerce reads it.
	 *
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	private static function as_posted( array $values ): array {
		$posted = array();

		foreach ( $values as $id => $value ) {
			if ( preg_match( '/^([^\[\]]+)\[([^\[\]]+)\]$/', (string) $id, $parts ) ) {
				$posted[ $parts[1] ][ $parts[2] ] = $value;
			} else {
				$posted[ $id ] = $value;
			}
		}

		return $posted;
	}

	public function render(): void {
		App_Host::start( self::SLUG );
		$this->render_screen();
		App_Host::end();
	}

	private function render_screen(): void {
		$sections = $this->sections();
		$current  = $this->current_section( $sections );

		Page_Shell::open( __( 'Settings', 'submex' ), '', array( array( 'label' => __( 'Settings', 'submex' ) ) ), '', false );

		$this->render_notices();

		if ( ! $sections ) {
			printf(
				'<div class="submex-notice submex-notice--bad">%s</div>',
				esc_html__( "WooCommerce's settings could not be loaded, so Submex's settings cannot be shown. Check that WooCommerce is active.", 'submex' )
			);
			Page_Shell::close();

			return;
		}

		echo '<div class="submex-settings">';

		$this->render_nav( $sections, $current );

		echo '<form class="submex-settings__main" method="post" action="">';
		wp_nonce_field( self::NONCE, 'submex_settings_nonce' );
		printf( '<input type="hidden" name="submex_section" value="%s" />', esc_attr( $current ) );

		$this->render_cards( $this->page_fields( $current, $sections ) );

		printf(
			'<div class="submex-settings__save"><span class="submex-settings__save-note">%s</span>'
				. '<button type="submit" class="button button-primary submex-btn submex-btn--primary">%s</button></div>',
			esc_html__( 'Changes apply to new renewals and purchases from the moment you save.', 'submex' ),
			esc_html__( 'Save changes', 'submex' )
		);

		echo '</form></div>';

		Page_Shell::close();
	}

	/**
	 * The menu's groups, in menu order. A stacked group draws all its sections on one page;
	 * a listed one draws one section at a time, chosen from a list under the group.
	 *
	 * @return array<string, array{label: string, icon: string, sections: string[], list?: bool}>
	 */
	public static function groups(): array {
		$groups = array(
			'general'            => array(
				'label'    => __( 'General', 'submex' ),
				'icon'     => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6h-6v6H4a1 1 0 0 1-1-1z"/>',
				'sections' => array( '', 'license' ),
			),
			'customers'          => array(
				'label'    => __( 'Customer Controls', 'submex' ),
				'icon'     => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.2 18.4a6.5 6.5 0 0 1 11.6 0"/>',
				'sections' => array( 'customer_controls' ),
			),
			'billing'            => array(
				'label'    => __( 'Renewal & Billing', 'submex' ),
				'icon'     => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2z"/><path d="M14.5 8h-3.2a1.3 1.3 0 0 0 0 2.6h1.4a1.3 1.3 0 0 1 0 2.6H9.5M12 6.5V8m0 5.2v1.5"/>',
				'sections' => array( 'recovery', 'renewal' ),
			),
			'switching'          => array(
				'label'    => __( 'Upgrade & Downgrade', 'submex' ),
				'icon'     => '<path d="M7 20V4m0 0L3.5 7.5M7 4l3.5 3.5M17 4v16m0 0 3.5-3.5M17 20l-3.5-3.5"/>',
				'sections' => array( 'switching', 'upsell' ),
			),
			'checkout'           => array(
				'label'    => __( 'Cart & Checkout', 'submex' ),
				'icon'     => '<circle cx="9" cy="20" r="1.4"/><circle cx="18" cy="20" r="1.4"/><path d="M2 3h3l2.7 12.2a1 1 0 0 0 1 .8h9.6a1 1 0 0 0 1-.8L21 7H6"/>',
				'sections' => array( 'checkout' ),
			),
			'shipping'           => array(
				'label'    => __( 'Shipping', 'submex' ),
				'icon'     => '<path d="M2.5 6h11v10h-11zM13.5 9.5h4l3 3V16h-7"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/>',
				'sections' => array( 'live-qr' ),
			),
			'notifications'      => array(
				'label'    => __( 'Email Notifications', 'submex' ),
				'icon'     => '<path d="M6 8a6 6 0 1 1 12 0c0 7 3 9 3 9H3s3-2 3-9"/><path d="M10.3 21a1.9 1.9 0 0 0 3.4 0"/>',
				'sections' => array( 'notifications', 'payment_methods' ),
			),
			'payments'           => array(
				'label'    => __( 'Payments', 'submex' ),
				'icon'     => '<rect x="2.5" y="5" width="19" height="14" rx="2"/><path d="M2.5 10h19M6.5 15h3"/>',
				'sections' => array( 'stripe', 'paypal', 'mollie', 'razorpay', 'xendit', 'square', 'authorize_net', 'braintree', 'adyen', 'gocardless', 'woopayments', 'paddle', 'bkash', 'sslcommerz' ),
				'list'     => true,
			),
			self::FALLBACK_GROUP => array(
				'label'    => __( 'Integrations', 'submex' ),
				'icon'     => '<path d="M9 2.5V7m6-4.5V7M6 7h12v4.5a6 6 0 0 1-12 0zM12 17.5v4"/>',
				'sections' => array( 'whatsapp', 'affiliatewp', 'anniversary', 'winback' ),
				'list'     => true,
			),
		);

		/**
		 * Filter the Settings menu's groups, in menu order.
		 *
		 * @param array<string, array{label: string, icon: string, sections: string[], list?: bool}> $groups
		 */
		return (array) apply_filters( 'submex_settings_groups', $groups );
	}

	/**
	 * Which group a section is drawn in.
	 */
	public static function group_of( string $section ): string {
		foreach ( self::groups() as $id => $group ) {
			if ( in_array( $section, $group['sections'], true ) ) {
				return $id;
			}
		}

		return self::FALLBACK_GROUP;
	}

	/**
	 * The group's sections that exist on this site, in the group's order, then any the
	 * group only holds as the fallback, in the order they were registered.
	 *
	 * @param array<string, string> $sections
	 * @return string[]
	 */
	private function group_sections( string $group, array $sections ): array {
		$listed = self::groups()[ $group ]['sections'] ?? array();
		$found  = array_values( array_filter( $listed, static fn( string $id ): bool => isset( $sections[ $id ] ) ) );

		foreach ( array_keys( $sections ) as $id ) {
			if ( self::group_of( (string) $id ) === $group && ! in_array( (string) $id, $found, true ) ) {
				$found[] = (string) $id;
			}
		}

		return $found;
	}

	/**
	 * The sections drawn together with this one: its whole group when stacked, else itself.
	 *
	 * @param array<string, string> $sections
	 * @return string[]
	 */
	public function page_sections( string $current, array $sections ): array {
		$group = self::group_of( $current );

		return empty( self::groups()[ $group ]['list'] ) ? $this->group_sections( $group, $sections ) : array( $current );
	}

	/**
	 * @param array<string, string> $sections
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	public function page_fields( string $current, array $sections ): array {
		$fields = array();

		foreach ( $this->page_sections( $current, $sections ) as $id ) {
			$fields[ $id ] = $this->settings_for( $id );
		}

		return $fields;
	}

	/**
	 * Groups with a section on this site; `list` when it shows its sections one at a time.
	 *
	 * @param array<string, string> $sections
	 * @return array<int, array{id: string, label: string, icon: string, list: bool, sections: array<int, array{id: string, title: string}>}>
	 */
	public function menu( array $sections ): array {
		$menu = array();

		foreach ( self::groups() as $id => $group ) {
			$members = $this->group_sections( $id, $sections );

			// A group with nothing in it on this site is left out rather than drawn empty.
			if ( ! $members ) {
				continue;
			}

			$menu[] = array(
				'id'       => $id,
				'label'    => $group['label'],
				'icon'     => $group['icon'],
				'list'     => ! empty( $group['list'] ) && count( $members ) > 1,
				'sections' => array_map(
					static fn( string $section ): array => array(
						'id'    => $section,
						'title' => (string) $sections[ $section ],
					),
					$members
				),
			);
		}

		return $menu;
	}

	/**
	 * What just happened: a save of our own, or a licence action that came back from Pro.
	 *
	 * @param array<string, mixed> $query Slashed, as $_GET is.
	 * @return array<int, array{type: string, message: string}>
	 */
	public function notices( array $query ): array {
		$notices = array();

		if ( isset( $query['submex_saved'] ) ) {
			$notices[] = array(
				'type'    => 'good',
				'message' => __( 'Settings saved.', 'submex' ),
			);
		}

		$licence = isset( $query['submex_license_notice'] ) ? sanitize_text_field( wp_unslash( (string) $query['submex_license_notice'] ) ) : '';

		if ( '' === $licence ) {
			return $notices;
		}

		$known = array(
			'activated'   => __( 'Licence activated.', 'submex' ),
			'deactivated' => __( 'Licence deactivated.', 'submex' ),
			'ok'          => __( 'Licence updated.', 'submex' ),
		);

		$notices[] = array(
			'type'    => isset( $known[ $licence ] ) ? 'good' : 'bad',
			'message' => $known[ $licence ] ?? $licence,
		);

		return $notices;
	}

	private function render_notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- redirect flags, not actions.
		foreach ( $this->notices( $_GET ) as $notice ) {
			printf( '<div class="submex-notice submex-notice--%s">%s</div>', esc_attr( $notice['type'] ), esc_html( $notice['message'] ) );
		}
	}

	/**
	 * @param array<string, string> $sections
	 */
	private function render_nav( array $sections, string $current ): void {
		$active = self::group_of( $current );

		echo '<nav class="submex-settings__nav" aria-label="' . esc_attr__( 'Settings sections', 'submex' ) . '"><ul>';

		foreach ( $this->menu( $sections ) as $group ) {
			$here    = $group['id'] === $active;
			$members = array_column( $group['sections'], 'id' );

			printf(
				'<li><a class="submex-settings__tab%s" href="%s"%s><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg><span>%s</span></a>',
				$here ? ' is-current' : '',
				esc_url( self::section_url( $members[0] ) ),
				$here && ! $group['list'] ? ' aria-current="page"' : '',
				wp_kses( $group['icon'], Allowed_Html::svg() ),
				esc_html( $group['label'] )
			);

			if ( $here && $group['list'] ) {
				echo '<ul class="submex-settings__subnav">';

				foreach ( $group['sections'] as $section ) {
					printf(
						'<li><a class="submex-settings__subtab%s" href="%s"%s>%s</a></li>',
						$section['id'] === $current ? ' is-current' : '',
						esc_url( self::section_url( $section['id'] ) ),
						$section['id'] === $current ? ' aria-current="page"' : '',
						esc_html( $section['title'] )
					);
				}

				echo '</ul>';
			}

			echo '</li>';
		}

		echo '</ul></nav>';
	}

	/**
	 * WooCommerce's settings array is a flat list where a "title" opens a group and
	 * "sectionend" closes it. Each group becomes a card; a page of one card needs no heading,
	 * since the menu already names it. A row PHP has to draw carries its markup.
	 *
	 * @param array<string, array<int, array<string, mixed>>> $by_section
	 * @return array<int, array{title: string, desc: string, anchor: ?string, rows: array<int, array{field: array<string, mixed>, joined: array<int, array<string, mixed>>, html: ?string}>}>
	 */
	public function cards( array $by_section ): array {
		$all    = $by_section ? array_merge( ...array_values( $by_section ) ) : array();
		$joined = $this->joined_fields( $all );
		$cards  = array();

		foreach ( $by_section as $section => $fields ) {
			$card  = null;
			$rows  = array();
			$first = true;

			foreach ( $fields as $field ) {
				$type = (string) ( $field['type'] ?? '' );

				if ( 'title' === $type || 'sectionend' === $type ) {
					if ( $rows ) {
						$cards[] = array( $card, $rows, $first ? (string) $section : null );
						$first   = false;
					}

					$rows = array();
					$card = 'title' === $type
						? array( (string) ( $field['title'] ?? '' ), (string) ( $field['desc'] ?? '' ) )
						: null;
					continue;
				}

				if ( isset( $field['submex_joins'] ) ) {
					continue;
				}

				$html = '' === (string) ( $field['id'] ?? '' ) ? $this->wide_markup( $field ) : null;

				// A custom row with nothing to say, such as the tax repair with nothing to repair, draws nothing.
				if ( '' === $html ) {
					continue;
				}

				$rows[] = array(
					'field'  => $field,
					'joined' => $joined[ (string) ( $field['id'] ?? '' ) ] ?? array(),
					'html'   => $html,
				);
			}

			if ( $rows ) {
				$cards[] = array( $card, $rows, $first ? (string) $section : null );
			}
		}

		$titled = count( $cards ) > 1;

		return array_map(
			static fn( array $card ): array => array(
				'title'  => $titled ? (string) ( $card[0][0] ?? '' ) : '',
				'desc'   => (string) ( $card[0][1] ?? '' ),
				'anchor' => $card[2],
				'rows'   => $card[1],
			),
			$cards
		);
	}

	/**
	 * @param array<string, array<int, array<string, mixed>>> $by_section
	 */
	private function render_cards( array $by_section ): void {
		$toggles = $this->toggle_states( $by_section ? array_merge( ...array_values( $by_section ) ) : array() );

		foreach ( $this->cards( $by_section ) as $card ) {
			printf( '<section class="submex-settings__card"%s>', null === $card['anchor'] ? '' : ' id="' . esc_attr( 'submex-section-' . ( '' === $card['anchor'] ? 'general' : $card['anchor'] ) ) . '"' );

			if ( '' !== $card['title'] || '' !== $card['desc'] ) {
				echo '<header class="submex-settings__card-head">';
				if ( '' !== $card['title'] ) {
					printf( '<h2>%s</h2>', esc_html( $card['title'] ) );
				}
				if ( '' !== $card['desc'] ) {
					printf( '<p>%s</p>', wp_kses_post( $card['desc'] ) );
				}
				echo '</header>';
			}

			echo '<div class="submex-settings__rows">';

			foreach ( $card['rows'] as $row ) {
				if ( null === $row['html'] ) {
					$this->render_row( $row['field'], $toggles, $row['joined'] );
				} else {
					echo '<div class="submex-settings__row submex-settings__row--wide">' . wp_kses( $row['html'], Allowed_Html::form() ) . '</div>';
				}
			}

			echo '</div></section>';
		}
	}

	/**
	 * Every switch on the page and whether it is on, so a row that depends on one starts
	 * out hidden when it is off.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<string, array{on: bool, parent: string}>
	 */
	private function toggle_states( array $fields ): array {
		$toggles = array();

		foreach ( $fields as $field ) {
			if ( 'checkbox' === ( $field['type'] ?? '' ) && ! empty( $field['id'] ) ) {
				$toggles[ (string) $field['id'] ] = array(
					'on'     => 'yes' === \WC_Admin_Settings::get_option( (string) $field['id'], $field['default'] ?? '' ),
					'parent' => (string) ( $field['submex_show_if'] ?? '' ),
				);
			}
		}

		return $toggles;
	}

	/**
	 * Fields drawn beside another field's control instead of on a row of their own, such as
	 * a unit beside a number.
	 *
	 * @param array<int, array<string, mixed>> $fields
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function joined_fields( array $fields ): array {
		$joined = array();

		foreach ( $fields as $field ) {
			if ( isset( $field['submex_joins'] ) ) {
				$joined[ (string) $field['submex_joins'] ][] = $field;
			}
		}

		return $joined;
	}

	/**
	 * @param array<string, array{on: bool, parent: string}> $toggles
	 */
	private function is_shown( string $parent, array $toggles, int $depth = 0 ): bool {
		if ( '' === $parent || ! isset( $toggles[ $parent ] ) || $depth > 10 ) {
			return true;
		}

		return $toggles[ $parent ]['on'] && $this->is_shown( $toggles[ $parent ]['parent'], $toggles, $depth + 1 );
	}

	/**
	 * @param array<string, mixed>                            $field
	 * @param array<string, array{on: bool, parent: string}> $toggles
	 * @param array<int, array<string, mixed>>                $joined
	 */
	private function render_row( array $field, array $toggles, array $joined ): void {
		$id     = (string) ( $field['id'] ?? '' );
		$help   = $this->help_text( $field );
		$parent = (string) ( $field['submex_show_if'] ?? '' );

		printf(
			'<div class="submex-settings__row%s"%s>',
			$this->is_shown( $parent, $toggles ) ? '' : ' is-hidden',
			'' === $parent ? '' : ' data-submex-show-if="' . esc_attr( $parent ) . '"'
		);
		printf( '<div class="submex-settings__label"><label class="submex-settings__title" for="%s">%s</label>', esc_attr( $id ), esc_html( (string) ( $field['title'] ?? '' ) ) );
		if ( '' !== $help ) {
			printf( '<p class="submex-settings__help" id="%s">%s</p>', esc_attr( $id . '-help' ), wp_kses_post( $help ) );
		}
		echo '</div><div class="submex-settings__control">';

		$preview = Notification_Settings::preview_url( (string) ( $field['submex_email'] ?? '' ) );

		if ( '' !== $preview ) {
			printf(
				'<a class="submex-btn submex-btn--sm" href="%s" target="_blank" rel="noopener noreferrer">%s<span class="screen-reader-text"> %s</span></a>',
				esc_url( $preview ),
				esc_html__( 'Preview', 'submex' ),
				/* translators: %s: the email's name, such as "Renewal reminder" */
				esc_html( sprintf( __( '%s (opens in a new tab)', 'submex' ), (string) ( $field['title'] ?? '' ) ) )
			);
		}

		$this->render_control( $field, '' !== $help );

		foreach ( $joined as $extra ) {
			printf( '<label class="screen-reader-text" for="%s">%s</label>', esc_attr( (string) ( $extra['id'] ?? '' ) ), esc_html( (string) ( $extra['title'] ?? '' ) ) );
			$this->render_control( $extra, false );
		}

		if ( '' !== $this->suffix( $field ) ) {
			printf( '<span class="submex-settings__suffix">%s</span>', wp_kses_post( $this->suffix( $field ) ) );
		}

		echo '</div></div>';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function render_control( array $field, bool $described ): void {
		$id    = (string) ( $field['id'] ?? '' );
		$type  = (string) ( $field['type'] ?? 'text' );
		$value = (string) $this->field_value( $field );
		$aria  = $described ? sprintf( ' aria-describedby="%s"', esc_attr( $id . '-help' ) ) : '';

		switch ( $type ) {
			case 'checkbox':
				$html = sprintf(
					'<span class="submex-switch"><input type="checkbox" role="switch" name="%1$s" id="%1$s" value="1"%2$s%3$s /><span class="submex-switch__track" aria-hidden="true"></span></span>',
					esc_attr( $id ),
					checked( $value, 'yes', false ),
					$aria
				);
				break;

			case 'select':
				$html = sprintf( '<select name="%1$s" id="%1$s" class="submex-input"%2$s>', esc_attr( $id ), $aria );
				foreach ( (array) ( $field['options'] ?? array() ) as $option => $option_label ) {
					$html .= sprintf(
						'<option value="%s"%s>%s</option>',
						esc_attr( (string) $option ),
						selected( $value, (string) $option, false ),
						esc_html( (string) $option_label )
					);
				}
				$html .= '</select>';
				break;

			case 'textarea':
				$html = sprintf(
					'<textarea name="%1$s" id="%1$s" class="submex-input submex-input--wide" rows="4"%2$s%3$s>%4$s</textarea>',
					esc_attr( $id ),
					$aria,
					$this->attributes( $field ),
					esc_textarea( $value )
				);
				break;

			case 'text':
			case 'password':
			case 'number':
			case 'email':
			case 'url':
				$html = sprintf(
					'<input type="%1$s" name="%2$s" id="%2$s" class="submex-input%3$s" value="%4$s"%5$s%6$s />',
					esc_attr( $type ),
					esc_attr( $id ),
					'number' === $type ? ' submex-input--short' : ' submex-input--wide',
					esc_attr( $value ),
					$aria,
					$this->attributes( $field )
				);
				break;

			default:
				$html = $this->woo_markup( $field );
		}

		echo wp_kses( $html, Allowed_Html::form() );
	}

	/**
	 * What a field holds now: a fixed value it shows, such as a webhook address, or the saved option.
	 *
	 * @param array<string, mixed> $field
	 * @return mixed
	 */
	public function field_value( array $field ) {
		return array_key_exists( 'value', $field )
			? $field['value']
			: \WC_Admin_Settings::get_option( (string) ( $field['id'] ?? '' ), $field['default'] ?? '' );
	}

	/**
	 * A row with no option of its own, such as the health checks, drawn whole by PHP.
	 *
	 * @param array<string, mixed> $field
	 */
	public function wide_markup( array $field ): string {
		if ( 'submex_status' !== ( $field['type'] ?? '' ) ) {
			return $this->woo_markup( $field );
		}

		ob_start();

		foreach ( Settings::status_checks() as $check ) {
			Settings::render_check( $check );
		}

		return '<div class="submex-checks">' . ob_get_clean() . '</div>';
	}

	/**
	 * Anything this screen has no markup for - a custom type another plugin registered -
	 * is handed back to WooCommerce so it still renders and still saves.
	 *
	 * @param array<string, mixed> $field
	 */
	public function woo_markup( array $field ): string {
		ob_start();
		\WC_Admin_Settings::output_fields( array( $field ) );
		$markup = trim( (string) ob_get_clean() );

		return '' === $markup ? '' : '<table class="form-table submex-settings__woo">' . $markup . '</table>';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	public function help_text( array $field ): string {
		$desc = '' === $this->suffix( $field ) ? trim( (string) ( $field['desc'] ?? '' ) ) : '';
		$tip  = isset( $field['desc_tip'] ) && is_string( $field['desc_tip'] ) ? trim( $field['desc_tip'] ) : '';

		if ( '' === $desc || '' === $tip ) {
			return $desc . $tip;
		}

		// WooCommerce draws these apart, so a checkbox's label rarely ends a sentence.
		return ( preg_match( '/[.!?:]$/u', $desc ) ? $desc : $desc . '.' ) . ' ' . $tip;
	}

	/**
	 * A number's description is the unit WooCommerce prints after it, such as "days ahead",
	 * unless it is shown as the tooltip.
	 *
	 * @param array<string, mixed> $field
	 */
	public function suffix( array $field ): string {
		return 'number' === ( $field['type'] ?? '' ) && true !== ( $field['desc_tip'] ?? false ) ? trim( (string) ( $field['desc'] ?? '' ) ) : '';
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function attributes( array $field ): string {
		$out = '';

		foreach ( (array) ( $field['custom_attributes'] ?? array() ) as $name => $value ) {
			$out .= sprintf( ' %s="%s"', esc_attr( (string) $name ), esc_attr( (string) $value ) );
		}

		if ( isset( $field['placeholder'] ) ) {
			$out .= sprintf( ' placeholder="%s"', esc_attr( (string) $field['placeholder'] ) );
		}

		return $out;
	}

	/**
	 * @return array<string, string>
	 */
	public function sections(): array {
		$page = $this->woo_page();

		return $page ? (array) $page->get_sections() : array();
	}

	/**
	 * @param array<string, string> $sections
	 */
	private function current_section( array $sections ): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen routing.
		$asked = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';

		return isset( $sections[ $asked ] ) ? $asked : '';
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function settings_for( string $section ): array {
		$page = $this->woo_page();

		return $page ? (array) $page->get_settings_for_section( $section ) : array();
	}

	private function woo_page(): ?\WC_Settings_Page {
		// No include: WooCommerce's autoloader maps wc_admin* to includes/admin/, so asking
		// for the class is what loads it.
		if ( ! class_exists( '\WC_Admin_Settings' ) ) {
			return null;
		}

		foreach ( \WC_Admin_Settings::get_settings_pages() as $page ) {
			if ( $page instanceof \WC_Settings_Page && 'submex' === $page->get_id() ) {
				return $page;
			}
		}

		return null;
	}

	/**
	 * Any section's address, including one drawn stacked inside a group.
	 */
	public static function section_url( string $section = '' ): string {
		$args = array( 'page' => self::SLUG );

		if ( '' !== $section ) {
			$args['section'] = $section;
		}

		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}
}
