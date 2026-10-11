/**
 * The Integrations route: what Submex can connect to, and whether each connection is live.
 */
import { useCallback, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Badge, Button, Card, CardContent, Skeleton } from '@submex/ui';
import { registerRoute } from '@submex/shell';

function Heading() {
	return (
		<div className="sb-mb-6">
			<h2 className="sb-text-2xl sb-font-semibold sb-tracking-tight">
				{ __( 'Integrations', 'submex' ) }
			</h2>
			<p className="sb-mt-1 sb-text-sm sb-text-muted-foreground">
				{ __(
					'Hand a subscription to the plugin that delivers what it pays for, and take it back when it ends.',
					'submex'
				) }
			</p>
		</div>
	);
}

function TileIcon( { title, icon } ) {
	const [ broken, setBroken ] = useState( false );
	const initial = ( title || '?' ).slice( 0, 1 ).toUpperCase();

	return (
		<span
			className="sb-grid sb-h-10 sb-w-10 sb-shrink-0 sb-place-items-center sb-overflow-hidden sb-rounded-md sb-bg-accent sb-font-semibold sb-text-primary"
			aria-hidden="true"
		>
			{ icon && ! broken ? (
				<img
					src={ icon }
					alt=""
					width="40"
					height="40"
					loading="lazy"
					onError={ () => setBroken( true ) }
				/>
			) : (
				initial
			) }
		</span>
	);
}

/**
 * Installs through the same admin-ajax action the server-rendered screen uses.
 *
 * @param {Object} installer
 * @param {string} slug
 * @return {Promise<Object>} What the installer answered.
 */
export function install( installer, slug ) {
	const body = new window.FormData();

	body.append( 'action', installer.action );
	body.append( '_wpnonce', installer.nonce );
	body.append( 'slug', slug );

	return window
		.fetch( installer.url, {
			method: 'POST',
			credentials: 'same-origin',
			body,
		} )
		.then( ( response ) => response.json() );
}

function Tile( { item, installer, onInstalled } ) {
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const run = () => {
		setBusy( true );
		setError( '' );

		install( installer, item.install )
			.then( ( result ) => {
				if ( result?.success ) {
					return onInstalled();
				}

				setBusy( false );
				setError(
					result?.data?.message ||
						__( 'That did not work.', 'submex' )
				);
			} )
			.catch( () => {
				setBusy( false );
				setError( __( 'That did not work.', 'submex' ) );
			} );
	};

	return (
		<Card className="sb-flex sb-flex-col sb-gap-3 sb-p-5">
			<div className="sb-flex sb-items-center sb-gap-3">
				<TileIcon title={ item.title } icon={ item.icon } />
				<div className="sb-min-w-0">
					<h3 className="sb-text-sm sb-font-medium">
						{ item.title }
					</h3>
					<p className="sb-text-xs sb-text-muted-foreground">
						{ sprintf(
							/* translators: %s: the plugin an integration needs */
							__( 'Needs %s', 'submex' ),
							item.requires
						) }
					</p>
				</div>
			</div>

			{ item.description ? (
				<p className="sb-text-sm sb-text-muted-foreground">
					{ item.description }
				</p>
			) : null }

			{ item.hint ? (
				<p className="sb-text-xs sb-text-muted-foreground">
					{ item.hint }
				</p>
			) : null }

			<div className="sb-mt-auto sb-flex sb-flex-wrap sb-items-center sb-justify-between sb-gap-2">
				<Badge variant={ item.active ? 'success' : 'secondary' }>
					{ item.active
						? __( 'Connected', 'submex' )
						: __( 'Not active', 'submex' ) }
				</Badge>
				<span className="sb-flex sb-flex-wrap sb-gap-2">
					{ item.configure_url ? (
						<Button asChild variant="outline" size="sm">
							<a href={ item.configure_url }>
								{ item.configure_label ||
									__( 'Settings', 'submex' ) }
							</a>
						</Button>
					) : null }
					{ item.install && installer ? (
						<Button size="sm" disabled={ busy } onClick={ run }>
							{ busy
								? __( 'Installing…', 'submex' )
								: __( 'Install', 'submex' ) }
						</Button>
					) : null }
					{ ! item.install && item.get_url ? (
						<Button asChild variant="outline" size="sm">
							<a
								href={ item.get_url }
								target="_blank"
								rel="noopener noreferrer"
							>
								{ __( 'Get it', 'submex' ) }{ ' ' }
								<span aria-hidden="true">↗</span>
							</a>
						</Button>
					) : null }
				</span>
			</div>

			{ error ? (
				<p className="sb-text-xs sb-text-destructive" role="alert">
					{ error }
				</p>
			) : null }
		</Card>
	);
}

export function Integrations( { onFail } ) {
	const [ data, setData ] = useState( null );

	const load = useCallback(
		() => apiFetch( { path: '/submex/v1/integrations' } ).then( setData ),
		[]
	);

	useEffect( () => {
		load().catch( () => onFail() );
		// Loaded once: onFail is a new closure on every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	if ( ! data ) {
		return (
			<div>
				<Heading />
				<div className="sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
					<Skeleton className="sb-h-40" />
					<Skeleton className="sb-h-40" />
					<Skeleton className="sb-h-40" />
				</div>
			</div>
		);
	}

	if ( ! data.integrations.length ) {
		return (
			<div>
				<Heading />
				<Card>
					<CardContent className="sb-flex sb-flex-col sb-items-center sb-gap-2 sb-p-10 sb-text-center">
						<p className="sb-font-medium">
							{ __( 'Nothing to connect yet', 'submex' ) }
						</p>
						<p className="sb-max-w-md sb-text-sm sb-text-muted-foreground">
							{ __(
								'Integrations hand a subscription to the plugin that grants what it pays for — a course, a mailing list, a licence key. Submex Pro adds them.',
								'submex'
							) }
						</p>
					</CardContent>
				</Card>
			</div>
		);
	}

	const groups = new Map();

	data.integrations.forEach( ( item ) => {
		groups.set( item.category, [
			...( groups.get( item.category ) || [] ),
			item,
		] );
	} );

	return (
		<div>
			<Heading />
			{ [ ...groups ].map( ( [ category, items ] ) => (
				<section key={ category } className="sb-mb-8">
					<h2 className="sb-mb-3 sb-text-base sb-font-semibold">
						{ category }
					</h2>
					<div className="sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
						{ items.map( ( item ) => (
							<Tile
								key={ item.title }
								item={ item }
								installer={ data.installer }
								onInstalled={ load }
							/>
						) ) }
					</div>
				</section>
			) ) }
			<p className="sb-text-sm sb-text-muted-foreground">
				{ __(
					'An integration only does anything while the plugin it connects to is active, and only for products you have configured it on.',
					'submex'
				) }
			</p>
		</div>
	);
}

registerRoute( {
	page: 'submex-integrations',
	title: __( 'Integrations', 'submex' ),
	render: ( ctx ) => <Integrations onFail={ ctx.fail } />,
} );
