/**
 * The subscriptions list.
 *
 * Everything the WP_List_Table did: status tabs with counts, search, sorting, per-row
 * actions, bulk actions and pagination - without a page load between any of them.
 */
import { useEffect, useState, useCallback } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';
import {
	Badge,
	Button,
	Card,
	CardContent,
	Checkbox,
	Input,
	Select,
	Skeleton,
	Table,
	TableBody,
	TableCell,
	TableHead,
	TableHeader,
	TableRow,
} from '@submex/ui';
import { daysAgo, describeSchedule, statusVariant, whenDue } from './format';

const PER_PAGE = 20;

const BULK = [
	{ key: 'cancel', label: __( 'Cancel', 'submex' ) },
	{
		key: 'change_status',
		status: 'submex-on-hold',
		label: __( 'Put on hold', 'submex' ),
	},
	{ key: 'reactivate', label: __( 'Reactivate', 'submex' ) },
];

const SORTABLE = {
	id: 'ID',
	next_payment: 'next_payment',
	total: 'total',
};

/**
 * Nothing to show is two different situations, and the way out of each differs.
 * @param {Object}   props          Component props.
 * @param {boolean}  props.filtered Whether a status or search is narrowing the list.
 * @param {Function} props.onClear  Clears them.
 * @return {JSX.Element} The empty state.
 */
function Empty( { filtered, onClear } ) {
	return (
		<div className="sb-flex sb-flex-col sb-items-center sb-gap-2 sb-p-10 sb-text-center">
			<p className="sb-m-0 sb-font-medium">
				{ filtered
					? __( 'No subscriptions match that', 'submex' )
					: __( 'No subscriptions yet', 'submex' ) }
			</p>
			<p className="sb-m-0 sb-max-w-md sb-text-sm sb-text-muted-foreground">
				{ filtered
					? __(
							'Try a different status, or clear the search.',
							'submex'
					  )
					: __(
							'One appears here the moment somebody buys a subscription product. Nothing is charged until a gateway is connected.',
							'submex'
					  ) }
			</p>
			{ filtered ? (
				<Button variant="outline" size="sm" onClick={ onClear }>
					{ __( 'Clear filters', 'submex' ) }
				</Button>
			) : (
				<Button
					variant="outline"
					size="sm"
					onClick={ () => {
						window.location.href = new URL(
							'post-new.php?post_type=product',
							window.location.href
						).href;
					} }
				>
					{ __( 'New subscription product', 'submex' ) }
				</Button>
			) }
		</div>
	);
}

function SortHeader( { column, label, sort, onSort, className } ) {
	const active = sort.orderby === SORTABLE[ column ];
	const next = active && 'ASC' === sort.order ? 'DESC' : 'ASC';

	return (
		<TableHead className={ className }>
			<button
				type="button"
				onClick={ () =>
					onSort( { orderby: SORTABLE[ column ], order: next } )
				}
				className="sb-inline-flex sb-items-center sb-gap-1 sb-font-medium hover:sb-text-foreground"
				aria-label={ sprintf(
					/* translators: %s: column name. */
					__( 'Sort by %s', 'submex' ),
					label
				) }
			>
				{ label }
				<span
					aria-hidden="true"
					className={ active ? '' : 'sb-opacity-30' }
				>
					{ active && 'ASC' === sort.order ? '▲' : '▼' }
				</span>
			</button>
		</TableHead>
	);
}

export function List( {
	initialStatus = '',
	initialSearch = '',
	onOpen,
	onFail,
} ) {
	const [ rows, setRows ] = useState( null );
	const [ statuses, setStatuses ] = useState( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ query, setQuery ] = useState( {
		status: initialStatus,
		search: initialSearch,
		page: 1,
		orderby: 'date',
		order: 'DESC',
	} );
	const [ chosen, setChosen ] = useState( [] );
	const [ bulk, setBulk ] = useState( BULK[ 0 ].label );
	const [ busy, setBusy ] = useState( false );
	const [ notice, setNotice ] = useState( null );

	const load = useCallback( ( next ) => {
		const params = new URLSearchParams( {
			page: String( next.page ),
			per_page: String( PER_PAGE ),
			orderby: next.orderby,
			order: next.order,
		} );

		if ( next.status ) {
			params.set( 'status', next.status );
		}

		if ( next.search ) {
			params.set( 'search', next.search );
		}

		setBusy( true );

		return apiFetch( {
			path: `/submex/v1/subscriptions?${ params.toString() }`,
			parse: false,
		} )
			.then( ( response ) =>
				response.json().then( ( items ) => {
					setRows( items );
					setTotal(
						Number(
							response.headers.get( 'X-WP-Total' ) || items.length
						)
					);
					setBusy( false );
				} )
			)
			.catch( () => {
				setBusy( false );

				// Only the first load fails the screen: after that there are rows worth keeping.
				setRows( ( current ) => {
					if ( ! current ) {
						onFail();
					}

					return current;
				} );
			} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	const refreshStatuses = useCallback(
		() =>
			apiFetch( { path: '/submex/v1/subscriptions/statuses' } )
				.then( setStatuses )
				.catch( () => {} ),
		[]
	);

	useEffect( () => {
		const timer = setTimeout( () => load( query ), query.search ? 300 : 0 );

		return () => clearTimeout( timer );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ query.status, query.search, query.page, query.orderby, query.order ] );

	useEffect( () => {
		refreshStatuses();
	}, [ refreshStatuses ] );

	const set = ( changes ) => {
		setChosen( [] );
		setQuery( ( current ) => ( { ...current, ...changes } ) );
	};

	const runBulk = () => {
		const chosenAction = BULK.find( ( item ) => item.label === bulk );

		if ( ! chosenAction || ! chosen.length ) {
			return;
		}

		setBusy( true );
		setNotice( null );

		apiFetch( {
			path: '/submex/v1/subscriptions/actions',
			method: 'POST',
			data: {
				ids: chosen,
				action: chosenAction.key,
				status: chosenAction.status,
			},
		} )
			.then( ( result ) => {
				const changed = result.changed?.length || 0;
				const held = Object.keys( result.held || {} ).length;

				setNotice( {
					ok: changed > 0,
					message:
						sprintf(
							/* translators: %d: how many subscriptions changed. */
							_n(
								'%d subscription updated.',
								'%d subscriptions updated.',
								changed,
								'submex'
							),
							changed
						) +
						( held
							? ' ' +
							  sprintf(
									/* translators: %d: how many were left alone. */
									_n(
										'%d was left alone because that change is not allowed from its current status.',
										'%d were left alone because that change is not allowed from their current status.',
										held,
										'submex'
									),
									held
							  )
							: '' ),
				} );

				setChosen( [] );
				refreshStatuses();

				return load( query );
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

	const renew = ( row ) => {
		// This charges a real customer, so it names the amount and asks first.
		const question = sprintf(
			/* translators: %s: recurring total. */
			__( 'This charges the customer %s right now. Continue?', 'submex' ),
			row.total_formatted
		);

		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}

		setBusy( true );
		setNotice( null );

		apiFetch( {
			path: `/submex/v1/subscriptions/${ row.id }/actions`,
			method: 'POST',
			data: { action: 'renew_now' },
		} )
			.then( () => {
				setNotice( {
					ok: true,
					message: __(
						'The renewal was put through. The activity log says what happened.',
						'submex'
					),
				} );

				return load( query );
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

	if ( ! rows ) {
		return <Skeleton className="sb-my-4 sb-h-64" />;
	}

	const pages = Math.max( 1, Math.ceil( total / PER_PAGE ) );
	const allChosen = rows.length > 0 && chosen.length === rows.length;
	const filtered = '' !== query.status || '' !== query.search;

	const tab = ( key, label, count ) => (
		<button
			key={ key || 'all' }
			type="button"
			aria-pressed={ query.status === key }
			onClick={ () => set( { status: key, page: 1 } ) }
			className={ `sb-rounded-md sb-px-2.5 sb-py-1 sb-text-xs sb-font-medium ${
				query.status === key
					? 'sb-bg-primary sb-text-primary-foreground'
					: 'sb-text-muted-foreground hover:sb-bg-accent hover:sb-text-foreground'
			}` }
		>
			{ label }
			{ undefined === count ? null : (
				<span className="sb-ml-1 sb-opacity-70">{ count }</span>
			) }
		</button>
	);

	return (
		<div className={ busy ? 'sb-opacity-60 sb-transition-opacity' : '' }>
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

			<Card>
				<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-2 sb-border-b sb-p-3">
					<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-1">
						{ tab(
							'',
							__( 'All', 'submex' ),
							total && ! filtered ? total : undefined
						) }
						{ statuses
							.filter( ( status ) => status.count > 0 )
							.map( ( status ) =>
								tab( status.key, status.label, status.count )
							) }
					</div>

					<Input
						type="search"
						value={ query.search }
						placeholder={ __(
							'Search name, email or id',
							'submex'
						) }
						onChange={ ( event ) =>
							set( { search: event.target.value, page: 1 } )
						}
						className="sb-ml-auto sb-w-64"
					/>
				</div>

				{ chosen.length ? (
					<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-2 sb-border-b sb-bg-secondary sb-px-3 sb-py-2">
						<span className="sb-text-sm sb-font-medium">
							{ sprintf(
								/* translators: %d: how many rows are selected. */
								_n(
									'%d selected',
									'%d selected',
									chosen.length,
									'submex'
								),
								chosen.length
							) }
						</span>
						<Select
							value={ bulk }
							onChange={ ( event ) =>
								setBulk( event.target.value )
							}
							aria-label={ __( 'Bulk action', 'submex' ) }
							className="sb-h-8"
						>
							{ BULK.map( ( item ) => (
								<option key={ item.label } value={ item.label }>
									{ item.label }
								</option>
							) ) }
						</Select>
						<Button size="sm" disabled={ busy } onClick={ runBulk }>
							{ __( 'Apply', 'submex' ) }
						</Button>
						<Button
							variant="ghost"
							size="sm"
							onClick={ () => setChosen( [] ) }
						>
							{ __( 'Clear', 'submex' ) }
						</Button>
					</div>
				) : null }

				<CardContent className="sb-p-0">
					<Table>
						<TableHeader>
							<TableRow>
								<TableHead className="sb-w-10">
									<Checkbox
										checked={ allChosen }
										aria-label={ __(
											'Select all',
											'submex'
										) }
										onChange={ ( event ) =>
											setChosen(
												event.target.checked
													? rows.map(
															( row ) => row.id
													  )
													: []
											)
										}
									/>
								</TableHead>
								<TableHead>
									{ __( 'Customer', 'submex' ) }
								</TableHead>
								<SortHeader
									column="id"
									label={ __( 'Subscription', 'submex' ) }
									sort={ query }
									onSort={ set }
								/>
								<TableHead>
									{ __( 'Status', 'submex' ) }
								</TableHead>
								<SortHeader
									column="next_payment"
									label={ __( 'Next payment', 'submex' ) }
									sort={ query }
									onSort={ set }
								/>
								<SortHeader
									column="total"
									label={ __( 'Recurring total', 'submex' ) }
									sort={ query }
									onSort={ set }
									className="sb-text-right"
								/>
								<TableHead className="sb-text-right">
									{ __( 'Actions', 'submex' ) }
								</TableHead>
							</TableRow>
						</TableHeader>

						<TableBody>
							{ rows.length ? (
								rows.map( ( row ) => (
									<TableRow
										key={ row.id }
										className="hover:sb-bg-muted/40"
									>
										<TableCell>
											<Checkbox
												checked={ chosen.includes(
													row.id
												) }
												aria-label={ sprintf(
													/* translators: %d: subscription id. */
													__(
														'Select subscription %d',
														'submex'
													),
													row.id
												) }
												onChange={ ( event ) =>
													setChosen( ( current ) =>
														event.target.checked
															? [
																	...current,
																	row.id,
															  ]
															: current.filter(
																	( id ) =>
																		id !==
																		row.id
															  )
													)
												}
											/>
										</TableCell>
										<TableCell>
											<a
												href={ row.edit_url }
												onClick={ ( event ) => {
													if ( onOpen ) {
														event.preventDefault();
														onOpen( row.id );
													}
												} }
												className="sb-font-medium sb-text-foreground hover:sb-text-primary"
											>
												{ row.customer_name ||
													row.customer_email ||
													__( 'Guest', 'submex' ) }
											</a>
											{ row.customer_name &&
											row.customer_email ? (
												<div className="sb-text-xs sb-text-muted-foreground">
													{ row.customer_email }
												</div>
											) : null }
										</TableCell>
										<TableCell>
											<span className="sb-tabular-nums sb-text-muted-foreground">
												#{ row.id }
											</span>
											<div className="sb-text-xs sb-text-muted-foreground">
												{ describeSchedule( row ) }
											</div>
										</TableCell>
										<TableCell>
											<Badge
												variant={ statusVariant(
													row.status
												) }
											>
												{ row.status_label }
											</Badge>
											{ row.payment_pending ? (
												<div className="sb-mt-1 sb-text-xs sb-text-muted-foreground">
													{ __(
														'Payment processing',
														'submex'
													) }
													{ ' · ' }
													<a
														href={
															row
																.payment_pending_order
																.url
														}
													>
														{ sprintf(
															/* translators: %s: renewal order number. */
															__(
																'order #%s',
																'submex'
															),
															row
																.payment_pending_order
																.number
														) }
													</a>
													{ ' · ' }
													{ sprintf(
														/* translators: %s: how long ago, such as "3 days ago". */
														__(
															'submitted %s',
															'submex'
														),
														daysAgo(
															row.payment_pending_since
														)
													) }
												</div>
											) : null }
										</TableCell>
										<TableCell>
											{ row.next_payment_formatted ? (
												<>
													<div>
														{
															row.next_payment_formatted
														}
													</div>
													<div className="sb-text-xs sb-text-muted-foreground">
														{ row.payment_pending
															? __(
																	'waiting for the payment',
																	'submex'
															  )
															: whenDue(
																	row.next_payment
															  ) }
													</div>
												</>
											) : (
												<span className="sb-text-muted-foreground">
													—
												</span>
											) }
										</TableCell>
										<TableCell className="sb-text-right sb-font-medium sb-tabular-nums">
											{ row.total_formatted }
											<div className="sb-text-xs sb-font-normal sb-text-muted-foreground">
												{ row.payment_method_title ||
													row.payment_method ||
													'—' }
											</div>
										</TableCell>
										<TableCell className="sb-text-right">
											{ row.billable &&
											! row.payment_pending ? (
												<Button
													variant="outline"
													size="sm"
													onClick={ () =>
														renew( row )
													}
												>
													{ __(
														'Renew now',
														'submex'
													) }
												</Button>
											) : null }
										</TableCell>
									</TableRow>
								) )
							) : (
								<TableRow>
									<TableCell colSpan={ 7 } className="sb-p-0">
										<Empty
											filtered={ filtered }
											onClear={ () =>
												set( {
													status: '',
													search: '',
													page: 1,
												} )
											}
										/>
									</TableCell>
								</TableRow>
							) }
						</TableBody>
					</Table>
				</CardContent>

				{ rows.length ? (
					<div className="sb-flex sb-flex-wrap sb-items-center sb-gap-3 sb-border-t sb-p-3 sb-text-sm">
						<span className="sb-text-muted-foreground">
							{ sprintf(
								/* translators: %d: how many subscriptions matched. */
								_n(
									'%d subscription',
									'%d subscriptions',
									total,
									'submex'
								),
								total
							) }
						</span>
						{ pages > 1 ? (
							<div className="sb-ml-auto sb-flex sb-items-center sb-gap-3">
								<Button
									variant="outline"
									size="sm"
									disabled={ query.page <= 1 || busy }
									onClick={ () =>
										set( { page: query.page - 1 } )
									}
								>
									{ __( 'Previous', 'submex' ) }
								</Button>
								<span className="sb-text-muted-foreground">
									{ sprintf(
										/* translators: 1: current page, 2: total pages. */
										__( 'Page %1$d of %2$d', 'submex' ),
										query.page,
										pages
									) }
								</span>
								<Button
									variant="outline"
									size="sm"
									disabled={ query.page >= pages || busy }
									onClick={ () =>
										set( { page: query.page + 1 } )
									}
								>
									{ __( 'Next', 'submex' ) }
								</Button>
							</div>
						) : null }
					</div>
				) : null }
			</Card>
		</div>
	);
}
