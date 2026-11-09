import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { cn } from '@submex/ui';
import { matchesWhen } from './visibility';

const COPIED_FOR = 2000;

function hasValue( value ) {
	return String( value ?? '' ).trim() !== '' && value !== 'no';
}

// The same states the PHP card draws, read from the page's values as they are edited.
export function stepsOf( setup, valueOf ) {
	let current = false;

	return setup.steps
		.filter( ( step ) => matchesWhen( step.submex_show_when, valueOf ) )
		.map( ( step ) => {
			let done = step.done;

			if ( step.filled.length ) {
				done =
					step.filled.every( ( id ) => hasValue( valueOf( id ) ) ) &&
					step.done !== false;
			}

			let state = 'manual';

			if ( done === true ) {
				state = 'done';
			} else if ( done === false ) {
				state = current ? 'next' : 'current';
				current = true;
			}

			return { ...step, state };
		} );
}

function copyText( input ) {
	// Older browsers, and frames without clipboard permission, can still copy a selection.
	const bySelection = () => {
		input.select();

		return document.execCommand( 'copy' )
			? Promise.resolve()
			: Promise.reject();
	};

	if ( window.navigator?.clipboard?.writeText ) {
		return window.navigator.clipboard
			.writeText( input.value )
			.catch( bySelection );
	}

	return bySelection();
}

function CopyBox( { id, label, value } ) {
	const input = useRef( null );
	const [ status, setStatus ] = useState( '' );

	useEffect( () => {
		if ( status !== 'copied' ) {
			return undefined;
		}

		const timer = setTimeout( () => setStatus( '' ), COPIED_FOR );

		return () => clearTimeout( timer );
	}, [ status ] );

	const copy = () =>
		copyText( input.current ).then(
			() => setStatus( 'copied' ),
			() => {
				input.current.select();
				setStatus( 'selected' );
			}
		);

	const said = {
		copied: __( 'Copied', 'submex' ),
		selected: __(
			'Selected. Press Ctrl+C or Command+C to copy.',
			'submex'
		),
	}[ status ];

	return (
		<div className="submex-setup__copy">
			<label className="submex-setup__copy-label" htmlFor={ id }>
				{ label }
			</label>
			<div className="submex-setup__copy-row">
				<input
					ref={ input }
					type="text"
					id={ id }
					className="submex-input submex-setup__copy-input"
					value={ value }
					readOnly
					onFocus={ ( event ) => event.target.select() }
				/>
				<button
					type="button"
					className="submex-btn submex-btn--sm submex-setup__copy-button"
					onClick={ copy }
				>
					{ status === 'copied'
						? __( 'Copied', 'submex' )
						: __( 'Copy', 'submex' ) }
				</button>
			</div>
			<span className="screen-reader-text" role="status">
				{ said || '' }
			</span>
		</div>
	);
}

function Action( { action, primary } ) {
	const className = cn(
		'submex-btn submex-btn--sm',
		primary && 'submex-btn--primary'
	);

	if ( ! action.external ) {
		return (
			<a className={ className } href={ action.url }>
				{ action.text }
			</a>
		);
	}

	return (
		<a
			className={ className }
			href={ action.url }
			target="_blank"
			rel="noopener noreferrer"
		>
			{ action.text }
			<span className="screen-reader-text">
				{ ' ' }
				{ __( '(opens in a new tab)', 'submex' ) }
			</span>
		</a>
	);
}

const SAID = {
	done: () => __( 'done', 'submex' ),
	current: () => __( 'to do', 'submex' ),
	next: () => __( 'to do', 'submex' ),
};

export function SetupCard( { setup, valueOf } ) {
	const steps = stepsOf( setup, valueOf );

	if ( ! steps.length ) {
		return null;
	}

	const checked = steps.filter( ( step ) => step.state !== 'manual' );
	const done = checked.filter( ( step ) => step.state === 'done' ).length;
	const complete =
		checked.length > 0 &&
		done === checked.length &&
		checked.length === steps.length;

	return (
		<div className={ cn( 'submex-setup', complete && 'is-complete' ) }>
			<div className="submex-setup__head">
				<div className="submex-setup__intro">
					<h3 className="submex-setup__title">
						{ complete && setup.done ? setup.done : setup.title }
					</h3>
					{ setup.desc ? (
						<p
							className="submex-setup__desc"
							// Kept to wp_kses_post() by the server.
							dangerouslySetInnerHTML={ { __html: setup.desc } }
						/>
					) : null }
				</div>
				{ checked.length ? (
					<span className="submex-setup__count">
						{ sprintf(
							/* translators: 1: steps done, 2: steps that can be checked */
							__( '%1$d of %2$d done', 'submex' ),
							done,
							checked.length
						) }
					</span>
				) : null }
			</div>
			<ol className="submex-setup__steps">
				{ steps.map( ( step, index ) => (
					<li
						key={ `${ index }-${ step.label }` }
						className={ `submex-setup__step is-${ step.state }` }
					>
						<span
							className="submex-setup__marker"
							aria-hidden="true"
						>
							{ step.state === 'done' ? '✓' : index + 1 }
						</span>
						<div className="submex-setup__text">
							<span className="submex-setup__label">
								{ step.label }
								{ SAID[ step.state ] ? (
									<span className="screen-reader-text">
										{ ` (${ SAID[ step.state ]() })` }
									</span>
								) : null }
							</span>
							{ step.detail ? (
								<span
									className="submex-setup__detail"
									// Kept to wp_kses_post() by the server.
									dangerouslySetInnerHTML={ {
										__html: step.detail,
									} }
								/>
							) : null }
							{ step.copy ? (
								<CopyBox
									id={ `${ setup.id }-copy-${ index + 1 }` }
									label={ step.copy.label }
									value={ step.copy.value }
								/>
							) : null }
							{ step.list ? (
								<details className="submex-setup__list">
									<summary>{ step.list.summary }</summary>
									<ul>
										{ step.list.items.map( ( item ) => (
											<li key={ item }>
												<code>{ item }</code>
											</li>
										) ) }
									</ul>
								</details>
							) : null }
						</div>
						{ step.action &&
						( step.state === 'current' ||
							step.state === 'manual' ) ? (
							<Action
								action={ step.action }
								primary={ step.state === 'current' }
							/>
						) : null }
					</li>
				) ) }
			</ol>
		</div>
	);
}
