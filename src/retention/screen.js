/**
 * Submex → Retention Flow: the "Ask before cancelling" switch and its three tabs.
 */
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import { Button, Card, CardContent, Skeleton, Switch, cn } from '@submex/ui';
import { applyFilters, retentionHooks } from '@submex/shell';
import { Reasons } from './reasons';
import { Messages } from './messages';

export const PAGE = 'submex-retention';

const TABS = [
	{ id: 'reasons', label: __( 'Reasons', 'submex' ) },
	{ id: 'offers', label: __( 'Offers', 'submex' ) },
	{ id: 'messages', label: __( 'Confirmation Message', 'submex' ) },
];

export function ProCard( { upgradeUrl } ) {
	return (
		<Card>
			<CardContent className="sb-flex sb-flex-col sb-items-start sb-gap-2 sb-p-6">
				<p className="sb-m-0 sb-font-medium">
					{ __( 'Offers are part of Submex Pro', 'submex' ) }
				</p>
				<p className="sb-m-0 sb-max-w-xl sb-text-sm sb-text-muted-foreground">
					{ __(
						'With Submex Pro, a customer who is about to cancel can be shown one offer that matches their reason: a discount, a free gift, a pause or a way to reach you.',
						'submex'
					) }
				</p>
				{ upgradeUrl ? (
					<Button asChild variant="outline" size="sm">
						<a
							href={ upgradeUrl }
							target="_blank"
							rel="noopener noreferrer"
						>
							{ __( 'About Submex Pro', 'submex' ) }
							<span className="screen-reader-text">
								{ ' ' }
								{ __( '(opens in a new tab)', 'submex' ) }
							</span>
						</a>
					</Button>
				) : null }
			</CardContent>
		</Card>
	);
}

function Heading() {
	return (
		<div>
			<h2 className="sb-m-0 sb-text-2xl sb-font-semibold sb-tracking-tight">
				{ __( 'Retention Flow', 'submex' ) }
			</h2>
			<p className="sb-mt-1 sb-text-sm sb-text-muted-foreground">
				{ __(
					'Ask customers why before they cancel, and say what happens next.',
					'submex'
				) }
			</p>
		</div>
	);
}

export function Retention( { params, setParams, onFail } ) {
	const [ data, setData ] = useState( null );
	const [ reasons, setReasons ] = useState( [] );
	const [ messages, setMessages ] = useState( {} );
	const [ error, setError ] = useState( '' );
	const asked = params?.get( 'tab' );
	const tab = TABS.some( ( item ) => item.id === asked ) ? asked : 'reasons';

	useEffect( () => {
		apiFetch( { path: '/submex/v1/retention' } )
			.then( ( screen ) => {
				setData( screen );
				setReasons( screen.reasons );
				setMessages( screen.messages );
			} )
			.catch( () => onFail?.() );
		// Loaded once: onFail is a new closure on every render.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const request = useCallback( ( options ) => {
		setError( '' );

		return apiFetch( options ).catch( ( failure ) => {
			setError(
				failure?.message ||
					__( 'That did not work. Try again.', 'submex' )
			);

			throw failure;
		} );
	}, [] );

	const OffersTab = useMemo(
		() => applyFilters( retentionHooks.offersTab, ProCard ),
		[]
	);
	const stats = useMemo(
		() => applyFilters( retentionHooks.reasonStats, null, reasons ),
		[ reasons ]
	);

	if ( ! data ) {
		return (
			<div className="sb-grid sb-gap-4">
				<Heading />
				<Skeleton className="sb-h-48" />
			</div>
		);
	}

	const toggle = ( enabled ) => {
		setData( { ...data, enabled } );
		request( {
			path: '/submex/v1/retention',
			method: 'POST',
			data: { enabled },
		} ).catch( () => setData( { ...data, enabled: ! enabled } ) );
	};

	const pick = ( id ) =>
		setParams?.( { tab: 'reasons' === id ? '' : id }, { replace: true } );

	const onTabKey = ( event ) => {
		const step = { ArrowRight: 1, ArrowLeft: -1 }[ event.key ];

		if ( ! step ) {
			return;
		}

		event.preventDefault();

		const at = TABS.findIndex( ( item ) => item.id === tab );
		const next = TABS[ ( at + step + TABS.length ) % TABS.length ];

		pick( next.id );
		event.currentTarget.ownerDocument
			.getElementById( `submex-retention-tab-${ next.id }` )
			?.focus();
	};

	return (
		<div className="sb-grid sb-gap-5">
			<div className="sb-flex sb-flex-wrap sb-items-start sb-justify-between sb-gap-4">
				<Heading />
				<div className="sb-grid sb-gap-1">
					<span className="sb-flex sb-items-center sb-gap-2">
						<Switch
							id="submex-retention-enabled"
							checked={ !! data.enabled }
							aria-describedby="submex-retention-help"
							onCheckedChange={ toggle }
						/>
						<label
							htmlFor="submex-retention-enabled"
							className="sb-text-sm sb-font-medium"
						>
							{ __( 'Ask before cancelling', 'submex' ) }
						</label>
					</span>
					<p
						id="submex-retention-help"
						className="sb-m-0 sb-max-w-sm sb-text-xs sb-text-muted-foreground"
					>
						{ __(
							'Customers see the reasons screen before they cancel, and can always continue canceling in one click.',
							'submex'
						) }
					</p>
				</div>
			</div>

			{ error ? (
				<p
					className="sb-m-0 sb-text-sm sb-text-destructive"
					role="alert"
				>
					{ error }
				</p>
			) : null }

			<div
				role="tablist"
				aria-label={ __( 'Retention Flow', 'submex' ) }
				className="sb-flex sb-gap-1 sb-border-b sb-border-border"
			>
				{ TABS.map( ( item ) => (
					<button
						key={ item.id }
						id={ `submex-retention-tab-${ item.id }` }
						type="button"
						role="tab"
						aria-selected={ item.id === tab }
						aria-controls="submex-retention-panel"
						tabIndex={ item.id === tab ? 0 : -1 }
						className={ cn(
							'sb--mb-px sb-border-b-2 sb-border-transparent sb-bg-transparent sb-px-3 sb-py-2 sb-text-sm sb-font-medium',
							item.id === tab
								? 'sb-border-primary sb-text-foreground'
								: 'sb-text-muted-foreground'
						) }
						onClick={ () => pick( item.id ) }
						onKeyDown={ onTabKey }
					>
						{ item.label }
					</button>
				) ) }
			</div>

			<div
				id="submex-retention-panel"
				role="tabpanel"
				aria-labelledby={ `submex-retention-tab-${ tab }` }
			>
				{ 'reasons' === tab ? (
					<Reasons
						reasons={ reasons }
						setReasons={ setReasons }
						display={ data.display }
						setDisplay={ ( display ) =>
							setData( { ...data, display } )
						}
						request={ request }
						stats={ stats }
					/>
				) : null }
				{ 'offers' === tab ? (
					<OffersTab
						reasons={ reasons }
						upgradeUrl={ data.upgrade_url }
					/>
				) : null }
				{ 'messages' === tab ? (
					<Messages
						messages={ messages }
						setMessages={ setMessages }
						request={ request }
					/>
				) : null }
			</div>
		</div>
	);
}
