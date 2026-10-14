<?php

namespace Submex\Admin;

use Submex\Lifecycle\Retention_Flow;
use Submex\Lifecycle\Retention_Reasons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Submex → Retention Flow: "Ask before cancelling", its reasons, and what customers see.
 *
 * The screen is the app's route; this server render is what shows without JavaScript.
 */
class Retention_Page {

	public const SLUG = Menu::SLUG . '-retention';

	public function register(): void {
		// After Pro's Reports, which registers at 21; before Integrations either way.
		add_action( 'admin_menu', array( $this, 'add_page' ), 22 );
		add_action( 'submex_app_enqueue', array( $this, 'enqueue_route' ) );
	}

	public function add_page(): void {
		add_submenu_page(
			Menu::PARENT,
			__( 'Retention Flow', 'submex' ),
			__( 'Retention Flow', 'submex' ),
			Menu::CAPABILITY,
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function enqueue_route(): void {
		$asset = SUBMEX_PATH . 'build/retention.asset.php';

		if ( ! is_readable( $asset ) ) {
			return;
		}

		$asset = require $asset;

		wp_enqueue_script(
			'submex-retention',
			SUBMEX_URL . 'build/retention.js',
			array_merge( $asset['dependencies'], array( 'submex-ui', App_Host::HANDLE ) ),
			$asset['version'],
			true
		);

		wp_set_script_translations( 'submex-retention', 'submex', SUBMEX_PATH . 'languages' );
	}

	public function render(): void {
		if ( ! current_user_can( Menu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to manage subscriptions.', 'submex' ) );
		}

		App_Host::start( self::SLUG );

		Page_Shell::open(
			__( 'Retention Flow', 'submex' ),
			__( 'Ask customers why before they cancel, and say what happens next.', 'submex' )
		);

		printf(
			'<div class="submex-card"><h2>%s</h2><p>%s</p><p>%s</p>',
			esc_html__( 'Ask before cancelling', 'submex' ),
			Retention_Flow::is_on()
				? esc_html__( 'On: customers see the reasons screen before their subscription is cancelled.', 'submex' )
				: esc_html__( 'Off: customers cancel straight away, as before.', 'submex' ),
			esc_html__( 'Editing this screen needs JavaScript.', 'submex' )
		);

		echo '<ol>';

		foreach ( Retention_Reasons::all() as $reason ) {
			printf(
				'<li>%s%s</li>',
				esc_html( $reason['label'] ),
				$reason['enabled'] ? '' : ' <em>' . esc_html__( '(off)', 'submex' ) . '</em>'
			);
		}

		echo '</ol></div>';

		Page_Shell::close();

		App_Host::end();
	}
}
