/**
 * The shared Switch: a checkbox that announces itself as a switch, as the settings toggle does.
 */
import { act } from 'react';
import { createRoot, useState } from '@wordpress/element';
import { Switch } from '@submex/ui';

let container;
let root;

beforeEach( () => {
	container = document.createElement( 'div' );
	document.body.appendChild( container );
	root = createRoot( container );
} );

afterEach( () => {
	act( () => root.unmount() );
	container.remove();
} );

function Controlled( { onCheckedChange } ) {
	const [ on, setOn ] = useState( false );

	return (
		<>
			<Switch
				id="renew"
				checked={ on }
				onCheckedChange={ ( next ) => {
					setOn( next );
					onCheckedChange( next );
				} }
			/>
			<label htmlFor="renew">Renew automatically</label>
		</>
	);
}

describe( 'Switch', () => {
	it( 'is a checkbox with the switch role, named by its label', () => {
		act( () => root.render( <Controlled onCheckedChange={ () => {} } /> ) );

		const input = container.querySelector( 'input' );

		expect( input.type ).toBe( 'checkbox' );
		expect( input.getAttribute( 'role' ) ).toBe( 'switch' );
		expect( input.labels[ 0 ].textContent ).toBe( 'Renew automatically' );
		expect( input.checked ).toBe( false );
	} );

	it( 'reports the new state and follows it', () => {
		const changes = [];
		act( () =>
			root.render(
				<Controlled
					onCheckedChange={ ( next ) => changes.push( next ) }
				/>
			)
		);

		const input = container.querySelector( 'input' );

		act( () => input.click() );
		expect( changes ).toEqual( [ true ] );
		expect( input.checked ).toBe( true );

		act( () => input.click() );
		expect( changes ).toEqual( [ true, false ] );
		expect( input.checked ).toBe( false );
	} );

	it( 'keeps the plain change handler, and does nothing when disabled', () => {
		const events = [];
		const changes = [];
		act( () =>
			root.render(
				<Switch
					defaultChecked
					name="auto_renew"
					onChange={ ( event ) => events.push( event.target.name ) }
					onCheckedChange={ ( next ) => changes.push( next ) }
				/>
			)
		);

		const input = container.querySelector( 'input' );

		act( () => input.click() );
		expect( events ).toEqual( [ 'auto_renew' ] );
		expect( changes ).toEqual( [ false ] );

		act( () =>
			root.render(
				<Switch
					disabled
					onCheckedChange={ ( next ) => changes.push( next ) }
				/>
			)
		);
		act( () => container.querySelector( 'input' ).click() );
		expect( changes ).toEqual( [ false ] );
	} );

	it( 'hides the drawn track from assistive technology', () => {
		act( () => root.render( <Switch className="sb-ml-2" /> ) );

		const wrapper = container.firstChild;
		const drawn = wrapper.querySelectorAll( 'span[aria-hidden="true"]' );

		expect( wrapper.className ).toContain( 'sb-ml-2' );
		expect( drawn ).toHaveLength( 2 );
	} );
} );
