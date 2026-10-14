<?php

namespace Submex\Rest;

use Submex\Admin\Menu;
use Submex\Admin\Page_Shell;
use Submex\Lifecycle\Retention_Flow;
use Submex\Lifecycle\Retention_Reasons;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The Retention Flow screen: the switch, the reasons, the reasons screen's text and the confirmation messages.
 */
final class Retention_Controller {

	public const NAMESPACE = 'submex/v1';

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		$manage = array( $this, 'may_manage' );

		register_rest_route(
			self::NAMESPACE,
			'/retention',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'screen' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $manage,
					'args'                => array(
						'enabled' => array( 'type' => 'boolean' ),
						'display' => self::display_schema(),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/retention/messages/(?P<type>[\w-]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'save_message' ),
				'permission_callback' => $manage,
				'args'                => array(
					'enabled'     => array( 'type' => 'boolean' ),
					'title'       => array(
						'type'      => 'string',
						'maxLength' => Retention_Flow::MAX_TITLE,
					),
					'description' => array( 'type' => 'string' ),
					'button_text' => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => Retention_Flow::MAX_BUTTON,
					),
				),
			)
		);

		$label = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => Retention_Reasons::MAX_LABEL,
		);

		register_rest_route(
			self::NAMESPACE,
			'/retention/reasons',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'reasons' ),
					'permission_callback' => $manage,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_reason' ),
					'permission_callback' => $manage,
					'args'                => array( 'label' => $label + array( 'required' => true ) ),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( $this, 'reorder_reasons' ),
					'permission_callback' => $manage,
					'args'                => array(
						'order' => array(
							'required' => true,
							'type'     => 'array',
							'items'    => array( 'type' => 'string' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/retention/reasons/(?P<id>[\w-]+)',
			array(
				array(
					'methods'             => \WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_reason' ),
					'permission_callback' => $manage,
					'args'                => array(
						'label'   => $label,
						'enabled' => array( 'type' => 'boolean' ),
					),
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_reason' ),
					'permission_callback' => $manage,
				),
			)
		);
	}

	public function may_manage(): bool {
		return current_user_can( Menu::CAPABILITY );
	}

	public function screen(): \WP_REST_Response {
		$messages = array();

		foreach ( Retention_Flow::message_types() as $type ) {
			$messages[ $type ] = Retention_Flow::message( $type );
		}

		return rest_ensure_response(
			array(
				'enabled'     => Retention_Flow::is_on(),
				'reasons'     => $this->described_reasons(),
				'display'     => Retention_Flow::display(),
				'messages'    => (object) $messages,
				'upgrade_url' => did_action( 'submex_pro_loaded' ) ? '' : Page_Shell::UPGRADE_URL,
				'defaults'    => array(
					'display'  => Retention_Flow::display_defaults(),
					'messages' => array_intersect_key( Retention_Flow::message_defaults(), $messages ),
				),
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save( \WP_REST_Request $request ) {
		if ( null !== $request['display'] ) {
			$saved = Retention_Flow::save_display( (array) $request['display'] );

			if ( $saved instanceof \WP_Error ) {
				return $saved;
			}
		}

		if ( null !== $request['enabled'] ) {
			Retention_Flow::set( (bool) $request['enabled'] );
		}

		return $this->screen();
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function save_message( \WP_REST_Request $request ) {
		$values = array_intersect_key( $request->get_params(), array_flip( array( 'enabled', 'title', 'description', 'button_text' ) ) );
		$saved  = Retention_Flow::save_message( (string) $request['type'], $values );

		return $saved instanceof \WP_Error ? $saved : rest_ensure_response( $saved );
	}

	public function reasons(): \WP_REST_Response {
		return rest_ensure_response( $this->described_reasons() );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_reason( \WP_REST_Request $request ) {
		$reason = Retention_Reasons::create( (string) $request['label'] );

		if ( $reason instanceof \WP_Error ) {
			return $reason;
		}

		$response = rest_ensure_response( $this->describe( $reason ) );
		$response->set_status( 201 );

		return $response;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_reason( \WP_REST_Request $request ) {
		$reason = Retention_Reasons::update(
			(string) $request['id'],
			null === $request['label'] ? null : (string) $request['label'],
			null === $request['enabled'] ? null : (bool) $request['enabled']
		);

		if ( null === $reason ) {
			return self::not_found();
		}

		return $reason instanceof \WP_Error ? $reason : rest_ensure_response( $this->describe( $reason ) );
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_reason( \WP_REST_Request $request ) {
		$id = (string) $request['id'];

		if ( ! Retention_Reasons::delete( $id ) ) {
			return self::not_found();
		}

		return rest_ensure_response(
			array(
				'deleted' => true,
				'id'      => $id,
			)
		);
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function reorder_reasons( \WP_REST_Request $request ) {
		$result = Retention_Reasons::reorder( (array) $request['order'] );

		return $result instanceof \WP_Error ? $result : $this->reasons();
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function described_reasons(): array {
		return array_map( array( $this, 'describe' ), Retention_Reasons::all() );
	}

	/**
	 * @param array{id: string, label: string, enabled: bool} $reason
	 * @return array<string, mixed>
	 */
	private function describe( array $reason ): array {
		return $reason + array( 'usage' => Retention_Reasons::usage( $reason['id'] ) );
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function display_schema(): array {
		$text = static fn( int $min, int $max ): array => array(
			'type'      => 'string',
			'minLength' => $min,
			'maxLength' => $max,
		);

		return array(
			'type'                 => 'object',
			'additionalProperties' => false,
			'properties'           => array(
				'title'            => $text( 0, Retention_Flow::MAX_TITLE ),
				'description'      => array( 'type' => 'string' ),
				'keep_text'        => $text( 1, Retention_Flow::MAX_BUTTON ),
				'continue_text'    => $text( 1, Retention_Flow::MAX_BUTTON ),
				'title_size'       => array(
					'type'    => 'integer',
					'minimum' => Retention_Flow::SIZES['title_size'][0],
					'maximum' => Retention_Flow::SIZES['title_size'][1],
				),
				'description_size' => array(
					'type'    => 'integer',
					'minimum' => Retention_Flow::SIZES['description_size'][0],
					'maximum' => Retention_Flow::SIZES['description_size'][1],
				),
				'align'            => array(
					'type' => 'string',
					'enum' => Retention_Flow::ALIGNS,
				),
			),
		);
	}

	private static function not_found(): \WP_Error {
		return new \WP_Error( 'submex_reason_not_found', __( 'There is no such reason.', 'submex' ), array( 'status' => 404 ) );
	}
}
