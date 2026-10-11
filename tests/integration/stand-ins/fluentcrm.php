<?php
/**
 * The FluentCRM API Submex calls, for tests run where FluentCRM is not active; require it only then.
 *
 * Shaped like FluentCRM 2.x where it matters: tags and lists answer all() through __call, and a
 * contact's tags and lists are relations read as properties.
 *
 * @package Submex
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$GLOBALS['submex_fcrm'] = array(
	'tags'     => array(
		5 => 'Member',
		7 => 'VIP',
		8 => 'Variable member',
	),
	'lists'    => array(
		20 => 'Members newsletter',
		21 => 'Product news',
	),
	'contacts' => array(),
	'calls'    => array(),
);

// Like FluentCRM's collections: iterable, but an (array) cast does not give the items.
class ES_FCRM_Collection implements IteratorAggregate {

	public function __construct( protected array $items ) {}

	public function getIterator(): Iterator {
		return new ArrayIterator( $this->items );
	}
}

class ES_FCRM_Contact {

	public function __construct( public string $email ) {}

	public function __isset( $name ) {
		return in_array( $name, array( 'tags', 'lists' ), true );
	}

	public function __get( $name ) {
		$items = array();
		foreach ( $GLOBALS['submex_fcrm']['contacts'][ $this->email ][ $name ] ?? array() as $id ) {
			$items[] = (object) array( 'id' => $id, 'title' => $GLOBALS['submex_fcrm'][ $name ][ $id ] ?? '' );
		}
		return new ES_FCRM_Collection( $items );
	}

	public function attachTags( $ids ) {
		$this->change( 'tags', 'attachTags', $ids, true );
	}

	public function detachTags( $ids ) {
		$this->change( 'tags', 'detachTags', $ids, false );
	}

	public function attachLists( $ids ) {
		$this->change( 'lists', 'attachLists', $ids, true );
	}

	public function detachLists( $ids ) {
		$this->change( 'lists', 'detachLists', $ids, false );
	}

	private function change( string $relation, string $method, array $ids, bool $add ): void {
		$GLOBALS['submex_fcrm']['calls'][] = array( $method, $this->email, array_values( $ids ) );
		$held                          = $GLOBALS['submex_fcrm']['contacts'][ $this->email ][ $relation ] ?? array();
		$held                          = $add ? array_merge( $held, $ids ) : array_diff( $held, $ids );

		$GLOBALS['submex_fcrm']['contacts'][ $this->email ][ $relation ] = array_values( array_unique( array_map( 'intval', $held ) ) );
	}
}

class ES_FCRM_Contacts {

	public function createOrUpdate( $data ) {
		$email = (string) $data['email'];
		if ( ! isset( $GLOBALS['submex_fcrm']['contacts'][ $email ] ) ) {
			$GLOBALS['submex_fcrm']['contacts'][ $email ] = array(
				'tags'  => array(),
				'lists' => array(),
			);
		}
		return new ES_FCRM_Contact( $email );
	}
}

class ES_FCRM_Taxonomy {

	public function __construct( private string $resource ) {}

	public function __call( $method, $params ) {
		if ( 'all' !== $method ) {
			throw new Exception( "Method {$method} does not exist." );
		}
		$items = array();
		foreach ( $GLOBALS['submex_fcrm'][ $this->resource ] as $id => $title ) {
			$items[] = (object) array( 'id' => $id, 'title' => $title );
		}
		return new ES_FCRM_Collection( $items );
	}
}

// FluentCRM hands every API out through FCApi, a final class with nothing but __call.
final class ES_FCRM_Api {

	public function __construct( private object $instance ) {}

	public function __call( $method, $params ) {
		try {
			return call_user_func_array( array( $this->instance, $method ), $params );
		} catch ( \Exception $e ) {
			return null;
		}
	}
}

function FluentCrmApi( $key ) {
	return new ES_FCRM_Api( 'contacts' === $key ? new ES_FCRM_Contacts() : new ES_FCRM_Taxonomy( $key ) );
}
