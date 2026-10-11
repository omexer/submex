/**
 * The Help route: where to look first, and the system report to send.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardContent, Skeleton } from '@submex/ui';
import { registerRoute } from '@submex/shell';

function Heading() {
	return (
		<div className="sb-mb-6">
			<h2 className="sb-text-2xl sb-font-semibold sb-tracking-tight">
				{ __( 'Help', 'submex' ) }
			</h2>
			<p className="sb-mt-1 sb-text-sm sb-text-muted-foreground">
				{ __(
					'Where to look first, and the report to send if you still need a hand.',
					'submex'
				) }
			</p>
		</div>
	);
}

/**
 * Copies the report, selecting it where the page has no clipboard API (plain http admin).
 *
 * @param {HTMLTextAreaElement} field
 * @return {Promise<void>} Settles once copied.
 */
export function copy( field ) {
	if ( window.navigator.clipboard && window.isSecureContext ) {
		return window.navigator.clipboard.writeText( field.value );
	}

	field.select();
	field.ownerDocument.execCommand( 'copy' );

	return Promise.resolve();
}

export function Help( { onFail } ) {
	const [ data, setData ] = useState( null );
	const [ copied, setCopied ] = useState( false );
	const field = useRef( null );

	useEffect( () => {
		apiFetch( { path: '/submex/v1/help' } )
			.then( setData )
			.catch( () => onFail() );
		// Loaded once: onFail is a new closure on every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! copied ) {
			return;
		}

		const timer = setTimeout( () => setCopied( false ), 1600 );

		return () => clearTimeout( timer );
	}, [ copied ] );

	if ( ! data ) {
		return (
			<div>
				<Heading />
				<Skeleton className="sb-mb-6 sb-h-40" />
				<Skeleton className="sb-h-64" />
			</div>
		);
	}

	const lines = data.report.split( '\n' ).length;

	return (
		<div>
			<Heading />

			<h2 className="sb-mb-3 sb-text-base sb-font-semibold">
				{ __( 'Check these first', 'submex' ) }
			</h2>
			<div className="sb-mb-8 sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fill,minmax(260px,1fr))]">
				{ data.tiles.map( ( tile, index ) => (
					<Card
						key={ tile.url }
						className="sb-flex sb-flex-col sb-gap-3 sb-p-5"
					>
						<div className="sb-flex sb-items-center sb-gap-3">
							<span
								className="sb-grid sb-h-8 sb-w-8 sb-shrink-0 sb-place-items-center sb-rounded-full sb-bg-accent sb-text-sm sb-font-semibold sb-text-primary"
								aria-hidden="true"
							>
								{ index + 1 }
							</span>
							<h3 className="sb-text-sm sb-font-medium">
								{ tile.title }
							</h3>
						</div>
						<p className="sb-text-sm sb-text-muted-foreground">
							{ tile.body }
						</p>
						<div className="sb-mt-auto">
							<Button asChild variant="outline" size="sm">
								<a href={ tile.url }>{ tile.label }</a>
							</Button>
						</div>
					</Card>
				) ) }
			</div>

			<h2 className="sb-mb-3 sb-text-base sb-font-semibold">
				{ __( 'System report', 'submex' ) }
			</h2>
			<Card>
				<CardContent className="sb-flex sb-flex-col sb-gap-3 sb-p-5">
					<div className="sb-flex sb-flex-wrap sb-items-center sb-justify-between sb-gap-3">
						<p className="sb-text-sm sb-text-muted-foreground">
							{ __(
								'Paste this into your support request. It contains no keys and no customer data.',
								'submex'
							) }
						</p>
						<Button
							size="sm"
							onClick={ () =>
								copy( field.current ).then( () =>
									setCopied( true )
								)
							}
						>
							{ copied
								? __( 'Copied', 'submex' )
								: __( 'Copy report', 'submex' ) }
						</Button>
					</div>
					<textarea
						ref={ field }
						readOnly
						aria-label={ __( 'System report', 'submex' ) }
						rows={ Math.min( 24, lines ) }
						value={ data.report }
						className="sb-w-full sb-rounded-md sb-border sb-border-input sb-bg-muted/40 sb-p-3 sb-font-mono sb-text-xs"
					/>
				</CardContent>
			</Card>
		</div>
	);
}

registerRoute( {
	page: 'submex-help',
	title: __( 'Help', 'submex' ),
	render: ( ctx ) => <Help onFail={ ctx.fail } />,
} );
