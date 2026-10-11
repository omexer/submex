<?php

namespace Submex\Rest;

use Submex\Admin\Menu;
use Submex\Admin\Settings_Page;
use Submex\Emails\Notification_Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Settings screen over REST, through the PHP page's own fields, groups and save.
 */
final class Settings_Controller {

	public const NAMESPACE = 'submex/v1';

	/**
	 * WooCommerce's default section has an empty id, which a URL path cannot carry.
	 */
	private const GENERAL = 'general';

	/**
	 * Types the screen draws a control for; anything else is PHP's markup and not written here.
	 */
	private const EDITABLE = array( 'checkbox', 'select', 'text', 'password', 'number', 'email', 'url', 'textarea' );

	public function __construct( private readonly Settings_Page $page ) {}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'menu' ),
				'permission_callback' => array( $this, 'may_manage' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings/(?P<section>[\w-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'section' ),
					'permission_callback' => array( $this, 'may_manage' ),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( $this, 'may_manage' ),
					'args'                => array(
						'values' => array(
							'required' => true,
							'type'     => 'object',
						),
					),
				),
			)
		);
	}

	public function may_manage(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function menu( \WP_REST_Request $request ) {
		$sections = $this->sections();

		if ( $sections instanceof \WP_Error ) {
			return $sections;
		}

		$groups = array_map(
			static function ( array $group ): array {
				$group['sections'] = array_map(
					static fn( array $section ): array => array(
						'id'    => self::slug( $section['id'] ),
						'title' => $section['title'],
					),
					$group['sections']
				);

				return $group;
			},
			$this->page->menu( $sections )
		);

		return rest_ensure_response(
			array(
				'groups'  => $groups,
				'notices' => $this->page->notices( wp_slash( $request->get_query_params() ) ),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function section( \WP_REST_Request $request ) {
		$sections = $this->sections();

		if ( $sections instanceof \WP_Error ) {
			return $sections;
		}

		$section = $this->asked( $request, $sections );

		return $section instanceof \WP_Error ? $section : rest_ensure_response( $this->describe( $section, $sections ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		$sections = $this->sections();

		if ( $sections instanceof \WP_Error ) {
			return $sections;
		}

		$section = $this->asked( $request, $sections );

		if ( $section instanceof \WP_Error ) {
			return $section;
		}

		$values   = (array) $request['values'];
		$editable = $this->editable( $section, $sections );
		$unknown  = array_keys( array_diff_key( $values, $editable ) );

		if ( $unknown ) {
			return new \WP_Error(
				'submex_unknown_setting',
				/* translators: %s: comma-separated option names */
				sprintf( __( 'Not saved: %s is not a setting on this page.', 'submex' ), implode( ', ', array_map( 'strval', $unknown ) ) ),
				array( 'status' => 400 )
			);
		}

		$invalid = array_keys( array_filter( $values, static fn( $value ): bool => ! is_scalar( $value ) && null !== $value ) );

		if ( $invalid ) {
			return new \WP_Error(
				'submex_invalid_setting',
				/* translators: %s: comma-separated option names */
				sprintf( __( 'Not saved: %s needs a single value.', 'submex' ), implode( ', ', array_map( 'strval', $invalid ) ) ),
				array( 'status' => 400 )
			);
		}

		$errors   = self::woo_notes( 'errors' );
		$messages = self::woo_notes( 'messages' );
		$saved    = $values && $this->page->save( $section, $sections, array_map( static fn( $value ): string => (string) $value, $values ) );

		return rest_ensure_response(
			array(
				'saved'    => $saved,
				'values'   => array_map( array( $this, 'value' ), $this->editable( $section, $sections ) ),
				'errors'   => array_values( array_slice( self::woo_notes( 'errors' ), count( $errors ) ) ),
				'messages' => array_values( array_slice( self::woo_notes( 'messages' ), count( $messages ) ) ),
			)
		);
	}

	/**
	 * @param array<string, string> $sections
	 * @return array<string, mixed>
	 */
	private function describe( string $section, array $sections ): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only context for rows PHP draws.
		$query = $_GET;

		// Rows drawn by PHP, such as the tax repair, link back to the screen they are on.
		$_GET['page'] = Settings_Page::SLUG;
		$cards        = $this->page->cards( $this->page->page_fields( $section, $sections ) );
		$_GET         = $query;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		return array(
			'section'  => self::slug( $section ),
			'group'    => Settings_Page::group_of( $section ),
			'sections' => array_map( array( self::class, 'slug' ), $this->page->page_sections( $section, $sections ) ),
			'cards'    => array_map(
				fn( array $card ): array => array(
					'title'  => $card['title'],
					'desc'   => wp_kses_post( $card['desc'] ),
					'anchor' => null === $card['anchor'] ? null : self::slug( $card['anchor'] ),
					'rows'   => array_map( array( $this, 'row' ), $card['rows'] ),
				),
				$cards
			),
		);
	}

	/**
	 * @param array{field: array<string, mixed>, joined: array<int, array<string, mixed>>, html: ?string} $row
	 * @return array<string, mixed>
	 */
	private function row( array $row ): array {
		$field = $row['field'];

		if ( null !== $row['html'] || ! in_array( $field['type'] ?? '', self::EDITABLE, true ) ) {
			return array(
				'type' => (string) ( $field['type'] ?? '' ),
				'html' => $row['html'] ?? $this->page->woo_markup( $field ),
			);
		}

		return $this->field( $field ) + array( 'joined' => array_map( array( $this, 'field' ), $row['joined'] ) );
	}

	/**
	 * @param array<string, mixed> $field
	 * @return array<string, mixed>
	 */
	private function field( array $field ): array {
		$options = array();

		foreach ( (array) ( $field['options'] ?? array() ) as $value => $label ) {
			$options[] = array(
				'value' => (string) $value,
				'label' => (string) $label,
			);
		}

		return array(
			'id'                => (string) $field['id'],
			'type'              => (string) $field['type'],
			'title'             => (string) ( $field['title'] ?? '' ),
			'desc'              => (string) ( $field['desc'] ?? '' ),
			'desc_tip'          => $field['desc_tip'] ?? false,
			'help'              => wp_kses_post( $this->page->help_text( $field ) ),
			'suffix'            => wp_kses_post( $this->page->suffix( $field ) ),
			'options'           => $options,
			'default'           => $field['default'] ?? '',
			'value'             => $this->value( $field ),
			'placeholder'       => (string) ( $field['placeholder'] ?? '' ),
			'custom_attributes' => (object) array_map( 'strval', (array) ( $field['custom_attributes'] ?? array() ) ),
			'submex_show_if'    => (string) ( $field['submex_show_if'] ?? '' ),
			'submex_joins'      => (string) ( $field['submex_joins'] ?? '' ),
			'submex_email'      => (string) ( $field['submex_email'] ?? '' ),
			'preview_url'       => Notification_Settings::preview_url( (string) ( $field['submex_email'] ?? '' ) ),
		);
	}

	/**
	 * @param array<string, mixed> $field
	 */
	private function value( array $field ): string {
		$value = $this->page->field_value( $field );

		return is_scalar( $value ) ? (string) $value : '';
	}

	/**
	 * Every field this page can write, by option id: the only ids a save may name.
	 *
	 * @param array<string, string> $sections
	 * @return array<string, array<string, mixed>>
	 */
	private function editable( string $section, array $sections ): array {
		$editable = array();

		foreach ( $this->page->page_fields( $section, $sections ) as $fields ) {
			foreach ( $fields as $field ) {
				$id = (string) ( $field['id'] ?? '' );

				if ( '' !== $id && in_array( $field['type'] ?? '', self::EDITABLE, true ) && false !== ( $field['is_option'] ?? true ) ) {
					$editable[ $id ] = $field;
				}
			}
		}

		return $editable;
	}

	/**
	 * @return array<string, string>|\WP_Error
	 */
	private function sections() {
		$sections = $this->page->sections();

		return $sections ? $sections : new \WP_Error(
			'submex_settings_unavailable',
			__( "WooCommerce's settings could not be loaded, so Submex's settings cannot be shown. Check that WooCommerce is active.", 'submex' ),
			array( 'status' => 503 )
		);
	}

	/**
	 * @param array<string, string> $sections
	 * @return string|\WP_Error
	 */
	private function asked( \WP_REST_Request $request, array $sections ) {
		$asked   = (string) $request['section'];
		$section = self::GENERAL === $asked ? '' : $asked;

		return isset( $sections[ $section ] ) ? $section : new \WP_Error(
			'submex_unknown_section',
			__( 'There is no such settings section.', 'submex' ),
			array( 'status' => 404 )
		);
	}

	private static function slug( string $section ): string {
		return '' === $section ? self::GENERAL : $section;
	}

	/**
	 * WooCommerce keeps these to print on its own screen and has no getter for them.
	 *
	 * @return string[]
	 */
	private static function woo_notes( string $kind ): array {
		if ( ! property_exists( \WC_Admin_Settings::class, $kind ) ) {
			return array();
		}

		return array_map( 'strval', (array) ( new \ReflectionProperty( \WC_Admin_Settings::class, $kind ) )->getValue() );
	}
}
