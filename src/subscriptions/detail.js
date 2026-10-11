/**
 * One subscription: what it is, what happens next, and the things you can do to it.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Badge,
	Button,
	Card,
	CardHeader,
	CardTitle,
	CardContent,
	Input,
	Skeleton,
	Stat,
} from '@submex/ui';
import {
	daysAgo,
	describeSchedule,
	formatDay,
	formatTime,
	statusVariant,
	whenDue,
} from './format';

function Fact( { label, children } ) {
	return (
		<div className="sb-flex sb-justify-between sb-gap-4 sb-border-b sb-border-border sb-py-2.5 last:sb-border-0">
			<span className="sb-text-muted-foreground">{ label }</span>
			<span className="sb-text-right">{ children }</span>
		</div>
	);
}

/**
 * What kind of entry this is, in a word.
 *
 * @param {string} type The entry type the API reports.
 * @return {string} A label for it.
 */
function entryLabel( type ) {
	switch ( type ) {
		case 'status_change':
			return __( 'Status', 'submex' );
		case 'charge_attempt':
			return __( 'Charge', 'submex' );
		case 'schedule_change':
			return __( 'Schedule', 'submex' );
		default:
			return __( 'Note', 'submex' );
	}
}

/**
 * The log, oldest entries last, split into the days they happened on.
 *
 * @param {Array} entries Activity entries from the API.
 * @return {Array} One entry per day, each with its own entries.
 */
function byDay( entries ) {
	const days = [];

	entries.forEach( ( entry ) => {
		const day = String( entry.date || '' ).slice( 0, 10 );
		const last = days[ days.length - 1 ];

		if ( last && last.day === day ) {
			last.entries.push( entry );
			return;
		}

		days.push( { day, entries: [ entry ] } );
	} );

	return days;
}

export function Detail( { id, onBack, onFail } ) {
	const [ data, setData ] = useState( null );
	const [ activity, setActivity ] = useState( [] );
	const [ panels, setPanels ] = useState( '' );
	const [ dates, setDates ] = useState( { next_payment: '', end_date: '' } );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ missing, setMissing ] = useState( false );

	const load = useCallback( () => {
		setBusy( true );

		return Promise.all( [
			apiFetch( { path: `/submex/v1/subscriptions/${ id }` } ),
			apiFetch( {
				path: `/submex/v1/subscriptions/${ id }/activity`,
			} ).catch( () => [] ),
			apiFetch( {
				path: `/submex/v1/subscriptions/${ id }/panels`,
			} ).catch( () => ( {} ) ),
		] )
			.then( ( [ subscription, entries, extra ] ) => {
				setData( subscription );
				setActivity( entries );
				setPanels( extra?.html || '' );
				setDates( {
					next_payment: ( subscription.next_payment || '' )
						.replace( ' ', 'T' )
						.slice( 0, 16 ),
					end_date: ( subscription.end_date || '' )
						.replace( ' ', 'T' )
						.slice( 0, 16 ),
				} );
				setBusy( false );
			} )
			.catch( ( error ) => {
				setBusy( false );

				setData( ( current ) => {
					if ( current ) {
						// Already on screen: say the reload failed, keep what is there.
						setNotice( {
							ok: false,
							message: __(
								'That could not be reloaded.',
								'submex'
							),
						} );
					} else if ( 'submex_not_found' === error?.code ) {
						setMissing( true );
					} else {
						onFail();
					}

					return current;
				} );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ id ] );

	useEffect( () => {
		load();
	}, [ load ] );

	const act = ( action, question ) => {
		// eslint-disable-next-line no-alert
		if ( question && ! window.confirm( question ) ) {
			return;
		}

		setBusy( true );
		setNotice( null );

		apiFetch( {
			path: `/submex/v1/subscriptions/${ id }/actions`,
			method: 'POST',
			data: { action },
		} )
			.then( () => {
				setNotice( {
					ok: true,
					message: __( 'Done.', 'submex' ),
				} );

				return load();
			} )
			.catch( ( error ) => {
				setBusy( false );
				setNotice( {
					ok: false,
					message:
						error?.message || __( 'That did not work.', 'submex' ),
				} );
			} );
	};

	const saveDates = () => {
		setBusy( true );
		setNotice( null );

		apiFetch( {
			path: `/submex/v1/subscriptions/${ id }`,
			method: 'POST',
			data: {
				next_payment: dates.next_payment
					? dates.next_payment.replace( 'T', ' ' )
					: undefined,
				end_date: dates.end_date
					? dates.end_date.replace( 'T', ' ' )
					: undefined,
			},
		} )
			.then( () => {
				setNotice( {
					ok: true,
					message: __( 'The schedule was changed.', 'submex' ),
				} );

				return load();
			} )
			.catch( ( error ) => {
				setBusy( false );
				setNotice( {
					ok: false,
					message:
						error?.message ||
						__( 'That date could not be read.', 'submex' ),
				} );
			} );
	};

	if ( missing ) {
		return (
			<Card>
				<CardContent className="sb-p-6">
					<p>{ __( 'No subscription with that id.', 'submex' ) }</p>
				</CardContent>
			</Card>
		);
	}

	if ( ! data ) {
		return <Skeleton className="sb-my-4 sb-h-64" />;
	}

	let nextMeta = __( 'Nothing scheduled', 'submex' );

	// The date only moves once the provider confirms, so "overdue" would mislead.
	if ( data.payment_pending ) {
		nextMeta = __( 'Waiting for the payment', 'submex' );
	} else if ( data.next_payment ) {
		nextMeta = whenDue( data.next_payment );
	}

	return (
		<div className={ busy ? 'sb-opacity-60 sb-transition-opacity' : '' }>
			<div className="sb-mb-4 sb-flex sb-flex-wrap sb-items-start sb-gap-3">
				<div>
					<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-2">
						<h2 className="sb-m-0 sb-text-xl sb-font-semibold">
							{ sprintf(
								/* translators: %d: subscription id. */
								__( 'Subscription #%d', 'submex' ),
								data.id
							) }
						</h2>
						<Badge variant={ statusVariant( data.status ) }>
							{ data.status_label }
						</Badge>
					</div>
					<p className="sb-mt-1 sb-text-sm sb-text-muted-foreground">
						{ data.customer_name || data.customer_email || '—' }
						{ data.customer_name && data.customer_email
							? ` · ${ data.customer_email }`
							: '' }
					</p>
				</div>

				{ onBack ? (
					<Button
						variant="ghost"
						size="sm"
						onClick={ onBack }
						className="sb-ml-auto"
					>
						{ __( '← All subscriptions', 'submex' ) }
					</Button>
				) : null }
			</div>

			{ notice ? (
				<div
					className={ `sb-mb-4 sb-rounded-lg sb-border sb-p-3 sb-text-sm ${
						notice.ok
							? 'sb-border-success sb-text-success'
							: 'sb-border-destructive sb-text-destructive'
					}` }
					role="status"
				>
					{ notice.message }
				</div>
			) : null }

			{ data.payment_pending ? (
				<div
					className="sb-mb-4 sb-rounded-lg sb-border sb-p-3 sb-text-sm"
					role="status"
				>
					<strong>{ __( 'Payment processing', 'submex' ) }</strong>{ ' ' }
					{ sprintf(
						/* translators: 1: renewal order number, 2: how long ago, such as "3 days ago". */
						__(
							'Renewal order #%1$s was submitted %2$s and is waiting for the payment provider to confirm it.',
							'submex'
						),
						data.payment_pending_order.number,
						daysAgo( data.payment_pending_since )
					) }{ ' ' }
					<a href={ data.payment_pending_order.url }>
						{ __( 'View order', 'submex' ) }
					</a>
				</div>
			) : null }

			<div className="sb-mb-4 sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fit,minmax(200px,1fr))]">
				<Stat
					label={ __( 'Recurring total', 'submex' ) }
					value={ data.total_formatted }
					meta={ describeSchedule( data ) }
				/>
				<Stat
					label={ __( 'Next payment', 'submex' ) }
					value={ data.next_payment_formatted || '—' }
					meta={ nextMeta }
				/>
				<Stat
					label={ __( 'Payments made', 'submex' ) }
					value={ String( Number( data.period_index || 0 ) + 1 ) }
					meta={
						formatDay( data.date_created )
							? sprintf(
									/* translators: %s: date the subscription started. */
									__( 'since %s', 'submex' ),
									formatDay( data.date_created )
							  )
							: ''
					}
				/>
			</div>

			<div className="sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fit,minmax(320px,1fr))]">
				<Card>
					<CardHeader>
						<CardTitle>{ __( 'Details', 'submex' ) }</CardTitle>
					</CardHeader>
					<CardContent className="sb-text-sm">
						<Fact label={ __( 'Customer', 'submex' ) }>
							{ data.customer_name || data.customer_email || '—' }
						</Fact>
						<Fact label={ __( 'Email', 'submex' ) }>
							{ data.customer_email || '—' }
						</Fact>
						<Fact label={ __( 'Payment method', 'submex' ) }>
							{ data.payment_method_title ||
								data.payment_method ||
								'—' }
						</Fact>
						<Fact label={ __( 'Trial ends', 'submex' ) }>
							{ formatDay( data.trial_end ) || '—' }
						</Fact>
						<Fact label={ __( 'Ends', 'submex' ) }>
							{ formatDay( data.end_date ) ||
								__( 'Not set', 'submex' ) }
						</Fact>
						<Fact label={ __( 'Parent order', 'submex' ) }>
							{ data.parent_order_id
								? `#${ data.parent_order_id }`
								: '—' }
						</Fact>
					</CardContent>
				</Card>

				<Card>
					<CardHeader>
						<CardTitle>
							{ __( 'Things you can do', 'submex' ) }
						</CardTitle>
					</CardHeader>
					<CardContent className="sb-flex sb-flex-col sb-gap-5">
						<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-2">
							{ data.billable && ! data.payment_pending ? (
								<Button
									disabled={ busy }
									onClick={ () =>
										act(
											'renew_now',
											sprintf(
												/* translators: %s: recurring total. */
												__(
													'This charges the customer %s right now. Continue?',
													'submex'
												),
												data.total_formatted
											)
										)
									}
								>
									{ __( 'Renew now', 'submex' ) }
								</Button>
							) : null }
							<Button
								variant="outline"
								disabled={ busy }
								onClick={ () => act( 'reactivate' ) }
							>
								{ __( 'Reactivate', 'submex' ) }
							</Button>
						</div>

						{ data.billable && ! data.payment_pending ? (
							<p className="sb-m-0 sb-text-xs sb-text-muted-foreground">
								{ __(
									'Charging now takes the next payment immediately and moves the schedule on. It does not skip the queue for a failed one - the activity log below says what happened last.',
									'submex'
								) }
							</p>
						) : null }

						<div className="sb-flex sb-flex-col sb-gap-2 sb-border-t sb-pt-4">
							<h4 className="sb-m-0 sb-text-sm sb-font-medium">
								{ __( 'Change the schedule', 'submex' ) }
							</h4>
							<p className="sb-m-0 sb-mb-1 sb-text-xs sb-text-muted-foreground">
								{ __(
									'Moving the next payment changes when the customer is next charged; nothing is charged by saving. An end date stops renewals on or after it.',
									'submex'
								) }
							</p>

							<label
								className="sb-text-xs sb-text-muted-foreground"
								htmlFor="submex-next-payment"
							>
								{ __( 'Next payment', 'submex' ) }
							</label>
							<Input
								id="submex-next-payment"
								type="datetime-local"
								value={ dates.next_payment }
								onChange={ ( event ) =>
									setDates( {
										...dates,
										next_payment: event.target.value,
									} )
								}
							/>

							<label
								className="sb-text-xs sb-text-muted-foreground"
								htmlFor="submex-end-date"
							>
								{ __( 'Ends', 'submex' ) }
							</label>
							<Input
								id="submex-end-date"
								type="datetime-local"
								value={ dates.end_date }
								onChange={ ( event ) =>
									setDates( {
										...dates,
										end_date: event.target.value,
									} )
								}
							/>

							<Button
								variant="secondary"
								size="sm"
								disabled={ busy }
								onClick={ saveDates }
								className="sb-mt-1 sb-self-start"
							>
								{ __( 'Save the schedule', 'submex' ) }
							</Button>
						</div>

						<div className="sb-flex sb-flex-col sb-items-start sb-gap-2 sb-border-t sb-pt-4">
							<h4 className="sb-m-0 sb-text-sm sb-font-medium">
								{ __( 'End this subscription', 'submex' ) }
							</h4>
							<p className="sb-m-0 sb-text-xs sb-text-muted-foreground">
								{ __(
									'Billing stops and the customer keeps what the last payment covered. This cannot be undone.',
									'submex'
								) }
							</p>
							<Button
								variant="destructive"
								size="sm"
								disabled={ busy }
								onClick={ () =>
									act(
										'cancel',
										__(
											'Cancel this subscription? It cannot be reactivated afterwards.',
											'submex'
										)
									)
								}
							>
								{ __( 'Cancel subscription', 'submex' ) }
							</Button>
						</div>
					</CardContent>
				</Card>
			</div>

			<Card className="sb-mt-4">
				<CardHeader>
					<CardTitle>{ __( 'Activity', 'submex' ) }</CardTitle>
				</CardHeader>
				<CardContent>
					{ activity.length ? (
						<div className="sb-flex sb-flex-col sb-gap-5">
							{ byDay( activity ).map( ( group ) => (
								<div key={ group.day }>
									<h4 className="sb-m-0 sb-mb-2 sb-text-xs sb-font-semibold sb-uppercase sb-tracking-wide sb-text-muted-foreground">
										{ formatDay( group.day ) || group.day }
									</h4>
									<ol className="sb-m-0 sb-flex sb-list-none sb-flex-col sb-gap-2 sb-p-0 sb-text-sm">
										{ group.entries.map(
											( entry, index ) => (
												<li
													key={ `${ entry.date }-${ index }` }
													className="sb-flex sb-gap-3"
												>
													<span className="sb-w-20 sb-shrink-0 sb-whitespace-nowrap sb-tabular-nums sb-text-muted-foreground">
														{ formatTime(
															entry.date
														) }
													</span>
													<span className="sb-w-20 sb-shrink-0">
														<Badge variant="secondary">
															{ entryLabel(
																entry.type
															) }
														</Badge>
													</span>
													<span className="sb-min-w-0">
														{ entry.message }
														{ entry.actor ? (
															<span className="sb-text-muted-foreground">
																{ ' · ' }
																{ entry.actor }
															</span>
														) : null }
													</span>
												</li>
											)
										) }
									</ol>
								</div>
							) ) }
						</div>
					) : (
						<p className="sb-m-0 sb-text-sm sb-text-muted-foreground">
							{ __(
								'Nothing has happened to this subscription yet. Charges, status changes and schedule edits are all recorded here.',
								'submex'
							) }
						</p>
					) }
				</CardContent>
			</Card>

			{ panels ? (
				<div
					className="sb-mt-4"
					// Drawn by other plugins' PHP on submex_admin_subscription_detail, as the server screen draws it.
					dangerouslySetInnerHTML={ { __html: panels } }
				/>
			) : null }
		</div>
	);
}
