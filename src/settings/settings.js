import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Skeleton, cn } from '@submex/ui';
import { Row } from './field';
import { getEmailEditor } from './extend';
import { fieldsOf, isShown } from './visibility';

export const PAGE = 'submex-settings';

const GENERAL = 'general';
const SAVED_FOR = 4000;

function sectionOf( params ) {
	return params.get( 'section' ) || GENERAL;
}

function sectionUrl( section ) {
	return `admin.php?page=${ PAGE }${
		section === GENERAL ? '' : `&section=${ encodeURIComponent( section ) }`
	}`;
}

// The page's own query goes along, so rows PHP draws can answer it (a repair just made, a licence change).
function withQuery( path, params ) {
	const query = new URLSearchParams( params );

	query.delete( 'section' );

	const text = query.toString();

	return text ? `${ path }?${ text }` : path;
}

function isPlainClick( event ) {
	return (
		event.button === 0 &&
		! event.metaKey &&
		! event.ctrlKey &&
		! event.shiftKey &&
		! event.altKey
	);
}

function messageOf( error ) {
	return error?.message || __( 'That did not work. Try again.', 'submex' );
}

function Nav( { groups, section, group, onPick } ) {
	const pick = ( target ) => ( event ) => {
		if ( ! isPlainClick( event ) ) {
			return;
		}

		// Stopped here so the shell does not also navigate this link.
		event.preventDefault();
		event.stopPropagation();
		onPick( target );
	};

	return (
		<nav
			className="submex-settings__nav"
			aria-label={ __( 'Settings sections', 'submex' ) }
		>
			<ul>
				{ groups.map( ( item ) => {
					const here = item.id === group;

					return (
						<li key={ item.id }>
							<a
								className={ cn(
									'submex-settings__tab',
									here && 'is-current'
								) }
								href={ sectionUrl( item.sections[ 0 ].id ) }
								aria-current={
									here && ! item.list ? 'page' : undefined
								}
								onClick={ pick( item.sections[ 0 ].id ) }
							>
								<svg
									viewBox="0 0 24 24"
									width="20"
									height="20"
									fill="none"
									stroke="currentColor"
									strokeWidth="1.6"
									strokeLinecap="round"
									strokeLinejoin="round"
									aria-hidden="true"
									focusable="false"
									// A static icon from the server's own group list.
									dangerouslySetInnerHTML={ {
										__html: item.icon,
									} }
								/>
								<span>{ item.label }</span>
							</a>
							{ here && item.list ? (
								<ul className="submex-settings__subnav">
									{ item.sections.map( ( entry ) => (
										<li key={ entry.id }>
											<a
												className={ cn(
													'submex-settings__subtab',
													entry.id === section &&
														'is-current'
												) }
												href={ sectionUrl( entry.id ) }
												aria-current={
													entry.id === section
														? 'page'
														: undefined
												}
												onClick={ pick( entry.id ) }
											>
												{ entry.title }
											</a>
										</li>
									) ) }
								</ul>
							) : null }
						</li>
					);
				} ) }
			</ul>
		</nav>
	);
}

function Cards( { page, valueOf, onChange, onEdit } ) {
	const byId = fieldsOf( page );

	return page.cards.map( ( card, index ) => (
		<section
			key={ `${ card.anchor }-${ index }` }
			className="submex-settings__card"
			id={ card.anchor ? `submex-section-${ card.anchor }` : undefined }
		>
			{ card.title || card.desc ? (
				<header className="submex-settings__card-head">
					{ card.title ? <h2>{ card.title }</h2> : null }
					{ card.desc ? (
						<p dangerouslySetInnerHTML={ { __html: card.desc } } />
					) : null }
				</header>
			) : null }
			<div className="submex-settings__rows">
				{ card.rows.map( ( row, at ) => {
					if ( ! row.id ) {
						return (
							<div
								key={ `html-${ at }` }
								className="submex-settings__row submex-settings__row--wide"
								// Drawn and escaped by the same PHP callbacks as the PHP page.
								dangerouslySetInnerHTML={ { __html: row.html } }
							/>
						);
					}

					return isShown( row, byId, valueOf ) ? (
						<Row
							key={ row.id }
							field={ row }
							valueOf={ valueOf }
							onChange={ onChange }
							onEdit={ onEdit }
						/>
					) : null;
				} ) }
			</div>
		</section>
	) );
}

function Loading() {
	return (
		<section className="submex-settings__card" aria-busy="true">
			{ [ 0, 1, 2 ].map( ( key ) => (
				<div key={ key } className="submex-settings__row">
					<Skeleton className="sb-h-10 sb-w-3/5" />
					<Skeleton className="sb-h-6 sb-w-10" />
				</div>
			) ) }
		</section>
	);
}

export function Settings( { params, setParams } ) {
	const section = sectionOf( params );
	const EmailEditor = getEmailEditor();
	const email = ( EmailEditor && params.get( 'email' ) ) || '';
	const [ emailDirty, setEmailDirty ] = useState( false );
	const [ menu, setMenu ] = useState( null );
	const [ pages, setPages ] = useState( {} );
	const [ saved, setSaved ] = useState( {} );
	const [ drafts, setDrafts ] = useState( {} );
	const [ failure, setFailure ] = useState( '' );
	const [ errors, setErrors ] = useState( [] );
	const [ saving, setSaving ] = useState( false );
	const [ toast, setToast ] = useState( '' );
	const query = useRef( params );
	const asked = useRef( {} );

	useEffect( () => {
		apiFetch( {
			path: withQuery( '/submex/v1/settings', query.current ),
		} ).then( setMenu, ( error ) => setFailure( messageOf( error ) ) );
	}, [] );

	useEffect( () => {
		if ( asked.current[ section ] ) {
			return;
		}

		asked.current[ section ] = true;

		apiFetch( {
			path: withQuery(
				`/submex/v1/settings/${ encodeURIComponent( section ) }`,
				query.current
			),
		} ).then(
			( page ) => {
				const values = {};

				Object.values( fieldsOf( page ) ).forEach( ( field ) => {
					values[ field.id ] = field.value;
				} );

				setSaved( ( was ) => ( { ...was, ...values } ) );
				setPages( ( was ) => ( { ...was, [ section ]: page } ) );
			},
			( error ) => setFailure( messageOf( error ) )
		);
	}, [ section ] );

	useEffect( () => {
		if ( ! toast ) {
			return undefined;
		}

		const timer = setTimeout( () => setToast( '' ), SAVED_FOR );

		return () => clearTimeout( timer );
	}, [ toast ] );

	const valueOf = useCallback(
		( id ) => ( id in drafts ? drafts[ id ] : saved[ id ] ?? '' ),
		[ drafts, saved ]
	);

	const onChange = ( id, value ) =>
		setDrafts( ( was ) => {
			const next = { ...was };

			if ( value === saved[ id ] ) {
				delete next[ id ];
			} else {
				next[ id ] = value;
			}

			return next;
		} );

	const dirty = Object.keys( drafts ).length > 0;

	useLeaveGuard( dirty || emailDirty );

	const page = pages[ section ];
	const changed = {};

	Object.keys( fieldsOf( page ) ).forEach( ( id ) => {
		if ( id in drafts ) {
			changed[ id ] = drafts[ id ];
		}
	} );

	// An email's unsaved edits live only in its editor, so leaving it asks first.
	const keepEmailEdits = () =>
		emailDirty &&
		// eslint-disable-next-line no-alert -- the browser's own prompt is the accessible one here.
		! window.confirm(
			__(
				'You have unsaved changes to this email. Leave without saving them?',
				'submex'
			)
		);

	const sectionParams = section === GENERAL ? {} : { section };

	const openEmail = ( id ) => setParams( { ...sectionParams, email: id } );

	const closeEmail = () => {
		if ( ! keepEmailEdits() ) {
			setParams( sectionParams );
		}
	};

	const pick = ( target ) => {
		if ( keepEmailEdits() ) {
			return;
		}

		setErrors( [] );
		setMenu( ( was ) => ( { ...was, notices: [] } ) );
		setParams( target === GENERAL ? {} : { section: target } );
	};

	const save = () => {
		const ids = Object.keys( changed );

		if ( ! ids.length || saving ) {
			return;
		}

		setSaving( true );
		setErrors( [] );
		setToast( '' );

		apiFetch( {
			path: `/submex/v1/settings/${ encodeURIComponent( section ) }`,
			method: 'POST',
			data: { values: changed },
		} )
			.then(
				( result ) => {
					setSaved( ( was ) => ( { ...was, ...result.values } ) );
					setDrafts( ( was ) => {
						const next = { ...was };

						ids.forEach( ( id ) => delete next[ id ] );

						return next;
					} );

					if ( result.errors?.length ) {
						setErrors( result.errors );
					} else {
						setToast( __( 'Settings saved.', 'submex' ) );
					}
				},
				( error ) => setErrors( [ messageOf( error ) ] )
			)
			.finally( () => setSaving( false ) );
	};

	const onSubmit = ( event ) => {
		// PHP-drawn buttons, such as the licence's, post where they say.
		if ( event.nativeEvent?.submitter?.hasAttribute( 'formaction' ) ) {
			return;
		}

		event.preventDefault();
		save();
	};

	if ( failure ) {
		return (
			<div className="submex-notice submex-notice--bad" role="alert">
				{ failure }
			</div>
		);
	}

	const group =
		page?.group ||
		menu?.groups.find( ( item ) =>
			item.sections.some( ( entry ) => entry.id === section )
		)?.id;
	const pending = Object.keys( changed ).length > 0;

	return (
		<>
			{ ( menu?.notices || [] ).map( ( notice ) => (
				<div
					key={ notice.message }
					className={ `submex-notice submex-notice--${ notice.type }` }
				>
					{ notice.message }
				</div>
			) ) }
			<div className="submex-settings">
				{ menu ? (
					<Nav
						groups={ menu.groups }
						section={ section }
						group={ group }
						onPick={ pick }
					/>
				) : (
					<nav className="submex-settings__nav" aria-busy="true">
						<Skeleton className="sb-h-64 sb-w-full" />
					</nav>
				) }
				{ email ? (
					<EmailEditor
						id={ email }
						onBack={ closeEmail }
						onDirty={ setEmailDirty }
					/>
				) : (
					<form
						className="submex-settings__main"
						method="post"
						action=""
						onSubmit={ onSubmit }
					>
						{ page ? (
							<Cards
								page={ page }
								valueOf={ valueOf }
								onChange={ onChange }
								onEdit={ EmailEditor ? openEmail : undefined }
							/>
						) : (
							<Loading />
						) }
						{ errors.length ? (
							<div
								className="submex-notice submex-notice--bad"
								role="alert"
							>
								{ errors.map( ( error ) => (
									<p key={ error } className="sb-m-0">
										{ error }
									</p>
								) ) }
							</div>
						) : null }
						<div className="submex-settings__save">
							<span className="submex-settings__save-note">
								{ dirty
									? __(
											'You have unsaved changes.',
											'submex'
									  )
									: __(
											'Changes apply to new renewals and purchases from the moment you save.',
											'submex'
									  ) }
							</span>
							<button
								type="submit"
								className="button button-primary submex-btn submex-btn--primary"
								disabled={ ! pending || saving }
							>
								{ saving
									? __( 'Saving…', 'submex' )
									: __( 'Save changes', 'submex' ) }
							</button>
						</div>
					</form>
				) }
			</div>
			<div
				className="sb-fixed sb-bottom-24 sb-right-8 sb-z-50"
				role="status"
				aria-live="polite"
			>
				{ toast ? (
					<div className="submex-notice submex-notice--good sb-m-0 sb-shadow-lg">
						{ toast }
					</div>
				) : null }
			</div>
		</>
	);
}

function isThisRoute( href ) {
	const url = new URL( href, window.location.href );

	return (
		url.pathname === window.location.pathname &&
		url.searchParams.get( 'page' ) === PAGE
	);
}

// Leaving with changes asks first: a link out of Settings, or closing and reloading the page.
function useLeaveGuard( dirty ) {
	useEffect( () => {
		if ( ! dirty ) {
			return undefined;
		}

		const onClick = ( event ) => {
			const link = event.target.closest?.( 'a[href]' );

			if (
				! link ||
				! isPlainClick( event ) ||
				link.target === '_blank' ||
				isThisRoute( link.href )
			) {
				return;
			}

			// eslint-disable-next-line no-alert -- the browser's own prompt is the accessible one here.
			const leave = window.confirm(
				__(
					'You have unsaved changes. Leave without saving them?',
					'submex'
				)
			);

			if ( ! leave ) {
				event.preventDefault();
				event.stopPropagation();
			}
		};

		const onUnload = ( event ) => {
			event.preventDefault();
			event.returnValue = '';
		};

		// Capturing on window runs before the shell's own link handling.
		window.addEventListener( 'click', onClick, true );
		window.addEventListener( 'beforeunload', onUnload );

		return () => {
			window.removeEventListener( 'click', onClick, true );
			window.removeEventListener( 'beforeunload', onUnload );
		};
	}, [ dirty ] );
}
