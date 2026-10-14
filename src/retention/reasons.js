/**
 * The Reasons tab: what customers can pick, in order, and how the reasons screen reads.
 */
import { useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardContent,
	CardHeader,
	CardTitle,
	Input,
	Select,
	Switch,
} from '@submex/ui';
import { RichPreview, RichText } from './rich-text';
import { Field } from './field';

const TITLE_SIZES = [ 14, 16, 18, 20, 22, 24, 26, 28, 30, 32 ];
const DESCRIPTION_SIZES = [ 12, 13, 14, 15, 16, 17, 18, 19, 20 ];

export function moved( list, from, to ) {
	const next = [ ...list ];
	const [ item ] = next.splice( from, 1 );

	next.splice( to, 0, item );

	return next;
}

export function deleteQuestion( reason ) {
	if ( reason.usage > 0 ) {
		return sprintf(
			/* translators: 1: reason label, 2: number of offers */
			_n(
				'Delete “%1$s”? It is used by %2$d offer, which will no longer show for it.',
				'Delete “%1$s”? It is used by %2$d offers, which will no longer show for it.',
				reason.usage,
				'submex'
			),
			reason.label,
			reason.usage
		);
	}

	return sprintf(
		/* translators: %s: reason label */
		__(
			'Delete “%s”? Subscriptions that gave it keep it in their history.',
			'submex'
		),
		reason.label
	);
}

function ReasonRow( {
	reason,
	index,
	count,
	stats,
	onMove,
	onSave,
	onDelete,
	onDragStart,
	onDrop,
} ) {
	const [ editing, setEditing ] = useState( false );
	const [ label, setLabel ] = useState( reason.label );

	const save = ( event ) => {
		event.preventDefault();
		onSave( reason, { label } ).then( () => setEditing( false ) );
	};

	return (
		<li
			className="sb-flex sb-flex-wrap sb-items-center sb-gap-2 sb-border-b sb-border-border sb-py-2 last:sb-border-b-0"
			draggable
			onDragStart={ ( event ) => {
				event.dataTransfer?.setData( 'text/plain', reason.id );
				onDragStart( index );
			} }
			onDragOver={ ( event ) => event.preventDefault() }
			onDrop={ ( event ) => {
				event.preventDefault();
				onDrop( index );
			} }
		>
			<span
				className="sb-cursor-grab sb-select-none sb-text-muted-foreground"
				aria-hidden="true"
				title={ __( 'Drag to reorder', 'submex' ) }
			>
				⋮⋮
			</span>
			<span className="sb-flex sb-flex-col">
				<Button
					type="button"
					variant="ghost"
					size="sm"
					className="sb-h-5 sb-px-1"
					disabled={ index === 0 }
					aria-label={ sprintf(
						/* translators: %s: reason label */
						__( 'Move “%s” up', 'submex' ),
						reason.label
					) }
					onClick={ () => onMove( index, index - 1 ) }
				>
					<span aria-hidden="true">▲</span>
				</Button>
				<Button
					type="button"
					variant="ghost"
					size="sm"
					className="sb-h-5 sb-px-1"
					disabled={ index === count - 1 }
					aria-label={ sprintf(
						/* translators: %s: reason label */
						__( 'Move “%s” down', 'submex' ),
						reason.label
					) }
					onClick={ () => onMove( index, index + 1 ) }
				>
					<span aria-hidden="true">▼</span>
				</Button>
			</span>

			{ editing ? (
				<form
					className="sb-flex sb-flex-1 sb-flex-wrap sb-gap-2"
					onSubmit={ save }
				>
					<Input
						className="sb-max-w-xs"
						value={ label }
						maxLength={ 80 }
						required
						aria-label={ __( 'Reason', 'submex' ) }
						onChange={ ( event ) => setLabel( event.target.value ) }
					/>
					<Button type="submit" size="sm">
						{ __( 'Save', 'submex' ) }
					</Button>
					<Button
						type="button"
						size="sm"
						variant="ghost"
						onClick={ () => {
							setLabel( reason.label );
							setEditing( false );
						} }
					>
						{ __( 'Cancel', 'submex' ) }
					</Button>
				</form>
			) : (
				<span
					className={ `sb-flex-1 sb-text-sm ${
						reason.enabled ? '' : 'sb-text-muted-foreground'
					}` }
				>
					{ reason.label }
				</span>
			) }

			{ stats?.Row ? <stats.Row reason={ reason } /> : null }

			{ editing ? null : (
				<Button
					type="button"
					variant="ghost"
					size="sm"
					aria-label={ sprintf(
						/* translators: %s: reason label */
						__( 'Edit “%s”', 'submex' ),
						reason.label
					) }
					onClick={ () => setEditing( true ) }
				>
					{ __( 'Edit', 'submex' ) }
				</Button>
			) }
			<Switch
				checked={ reason.enabled }
				aria-label={ sprintf(
					/* translators: %s: reason label */
					__( 'Offer “%s” to customers', 'submex' ),
					reason.label
				) }
				onCheckedChange={ ( enabled ) => onSave( reason, { enabled } ) }
			/>
			<Button
				type="button"
				variant="destructive"
				size="sm"
				aria-label={ sprintf(
					/* translators: %s: reason label */
					__( 'Delete “%s”', 'submex' ),
					reason.label
				) }
				onClick={ () => onDelete( reason ) }
			>
				{ __( 'Delete', 'submex' ) }
			</Button>
		</li>
	);
}

function Preview( { display, reasons } ) {
	const enabled = reasons.filter( ( reason ) => reason.enabled );

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Live preview', 'submex' ) }</CardTitle>
			</CardHeader>
			<CardContent>
				<div
					className="sb-grid sb-gap-3"
					style={ { textAlign: display.align } }
					data-testid="retention-preview"
				>
					<p
						className="sb-m-0 sb-font-semibold"
						style={ { fontSize: `${ display.title_size }px` } }
					>
						{ display.title }
					</p>
					<RichPreview
						html={ display.description }
						className="sb-text-muted-foreground"
						style={ {
							fontSize: `${ display.description_size }px`,
						} }
					/>
					<ul className="sb-m-0 sb-grid sb-list-none sb-gap-2 sb-p-0 sb-text-left">
						{ enabled.map( ( reason ) => (
							<li
								key={ reason.id }
								className="sb-flex sb-items-center sb-gap-2 sb-rounded-md sb-border sb-border-border sb-px-3 sb-py-2 sb-text-sm"
							>
								<span
									className="sb-h-3.5 sb-w-3.5 sb-rounded-full sb-border sb-border-input"
									aria-hidden="true"
								/>
								{ reason.label }
							</li>
						) ) }
					</ul>
					<div
						className="sb-flex sb-flex-wrap sb-gap-2"
						style={ {
							justifyContent: {
								left: 'flex-start',
								center: 'center',
								right: 'flex-end',
							}[ display.align ],
						} }
					>
						<span className="sb-rounded-md sb-bg-primary sb-px-4 sb-py-2 sb-text-sm sb-text-primary-foreground">
							{ display.keep_text }
						</span>
						<span className="sb-rounded-md sb-border sb-border-input sb-px-4 sb-py-2 sb-text-sm">
							{ display.continue_text }
						</span>
					</div>
				</div>
			</CardContent>
		</Card>
	);
}

function DisplaySettings( { draft, setDraft, onSave, busy } ) {
	const set = ( key ) => ( event ) =>
		setDraft( { ...draft, [ key ]: event.target.value } );
	const setSize = ( key ) => ( event ) =>
		setDraft( { ...draft, [ key ]: Number( event.target.value ) } );

	return (
		<Card>
			<CardHeader>
				<CardTitle>{ __( 'Display Settings', 'submex' ) }</CardTitle>
			</CardHeader>
			<CardContent>
				<form
					className="sb-grid sb-gap-4"
					onSubmit={ ( event ) => {
						event.preventDefault();
						onSave();
					} }
				>
					<Field
						id="submex-retention-title"
						label={ __( 'Title', 'submex' ) }
					>
						<Input
							id="submex-retention-title"
							value={ draft.title }
							maxLength={ 120 }
							onChange={ set( 'title' ) }
						/>
					</Field>
					<RichText
						id="submex-retention-description"
						label={ __( 'Description', 'submex' ) }
						value={ draft.description }
						onChange={ ( description ) =>
							setDraft( { ...draft, description } )
						}
					/>
					<div className="sb-grid sb-gap-4 sb-grid-cols-[repeat(auto-fit,minmax(140px,1fr))]">
						<Field
							id="submex-retention-title-size"
							label={ __( 'Title size', 'submex' ) }
						>
							<Select
								id="submex-retention-title-size"
								value={ draft.title_size }
								onChange={ setSize( 'title_size' ) }
							>
								{ TITLE_SIZES.map( ( size ) => (
									<option key={ size } value={ size }>
										{ `${ size }px` }
									</option>
								) ) }
							</Select>
						</Field>
						<Field
							id="submex-retention-description-size"
							label={ __( 'Description size', 'submex' ) }
						>
							<Select
								id="submex-retention-description-size"
								value={ draft.description_size }
								onChange={ setSize( 'description_size' ) }
							>
								{ DESCRIPTION_SIZES.map( ( size ) => (
									<option key={ size } value={ size }>
										{ `${ size }px` }
									</option>
								) ) }
							</Select>
						</Field>
						<Field
							id="submex-retention-align"
							label={ __( 'Alignment', 'submex' ) }
						>
							<Select
								id="submex-retention-align"
								value={ draft.align }
								onChange={ set( 'align' ) }
							>
								<option value="left">
									{ __( 'Left', 'submex' ) }
								</option>
								<option value="center">
									{ __( 'Center', 'submex' ) }
								</option>
								<option value="right">
									{ __( 'Right', 'submex' ) }
								</option>
							</Select>
						</Field>
					</div>
					<Field
						id="submex-retention-keep"
						label={ __( 'Keep button text', 'submex' ) }
					>
						<Input
							id="submex-retention-keep"
							value={ draft.keep_text }
							maxLength={ 40 }
							required
							onChange={ set( 'keep_text' ) }
						/>
					</Field>
					<Field
						id="submex-retention-continue"
						label={ __( 'Continue canceling text', 'submex' ) }
					>
						<Input
							id="submex-retention-continue"
							value={ draft.continue_text }
							maxLength={ 40 }
							required
							onChange={ set( 'continue_text' ) }
						/>
					</Field>
					<div>
						<Button type="submit" disabled={ busy }>
							{ __( 'Save display settings', 'submex' ) }
						</Button>
					</div>
				</form>
			</CardContent>
		</Card>
	);
}

export function Reasons( {
	reasons,
	setReasons,
	display,
	setDisplay,
	request,
	stats,
} ) {
	const [ added, setAdded ] = useState( '' );
	const [ draft, setDraft ] = useState( display );
	const [ dragging, setDragging ] = useState( null );
	const [ busy, setBusy ] = useState( false );

	const reorder = ( next ) => {
		const before = reasons;

		setReasons( next );

		return request( {
			path: '/submex/v1/retention/reasons',
			method: 'PUT',
			data: { order: next.map( ( reason ) => reason.id ) },
		} )
			.then( setReasons )
			.catch( () => setReasons( before ) );
	};

	const move = ( from, to ) => {
		if ( to < 0 || to >= reasons.length || from === to ) {
			return;
		}

		reorder( moved( reasons, from, to ) );
	};

	const save = ( reason, changes ) =>
		request( {
			path: `/submex/v1/retention/reasons/${ reason.id }`,
			method: 'POST',
			data: changes,
		} ).then( ( saved ) =>
			setReasons( ( list ) =>
				list.map( ( item ) => ( item.id === saved.id ? saved : item ) )
			)
		);

	const remove = ( reason ) => {
		// eslint-disable-next-line no-alert -- the browser's own prompt is the accessible one here.
		if ( ! window.confirm( deleteQuestion( reason ) ) ) {
			return;
		}

		request( {
			path: `/submex/v1/retention/reasons/${ reason.id }`,
			method: 'DELETE',
		} ).then( () =>
			setReasons( ( list ) =>
				list.filter( ( item ) => item.id !== reason.id )
			)
		);
	};

	const add = ( event ) => {
		event.preventDefault();

		if ( ! added.trim() ) {
			return;
		}

		request( {
			path: '/submex/v1/retention/reasons',
			method: 'POST',
			data: { label: added.trim() },
		} ).then( ( reason ) => {
			setReasons( ( list ) => [ ...list, reason ] );
			setAdded( '' );
		} );
	};

	const saveDisplay = () => {
		setBusy( true );

		request( {
			path: '/submex/v1/retention',
			method: 'POST',
			data: { display: draft },
		} )
			.then( ( screen ) => {
				setDisplay( screen.display );
				setDraft( screen.display );
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<div className="sb-grid sb-gap-6">
			{ stats?.Header ? <stats.Header reasons={ reasons } /> : null }

			<Card>
				<CardHeader>
					<CardTitle>{ __( 'Reasons', 'submex' ) }</CardTitle>
				</CardHeader>
				<CardContent>
					<ul
						className="sb-m-0 sb-list-none sb-p-0"
						aria-label={ __( 'Cancellation reasons', 'submex' ) }
					>
						{ reasons.map( ( reason, index ) => (
							<ReasonRow
								key={ reason.id }
								reason={ reason }
								index={ index }
								count={ reasons.length }
								stats={ stats }
								onMove={ move }
								onSave={ save }
								onDelete={ remove }
								onDragStart={ setDragging }
								onDrop={ ( to ) => {
									if ( null !== dragging ) {
										move( dragging, to );
									}

									setDragging( null );
								} }
							/>
						) ) }
					</ul>
					<form
						className="sb-mt-4 sb-flex sb-flex-wrap sb-gap-2"
						onSubmit={ add }
					>
						<Input
							className="sb-max-w-sm"
							value={ added }
							maxLength={ 80 }
							placeholder={ __( 'Write the reason', 'submex' ) }
							aria-label={ __( 'New reason', 'submex' ) }
							onChange={ ( event ) =>
								setAdded( event.target.value )
							}
						/>
						<Button type="submit">
							{ __( 'Add Reason', 'submex' ) }
						</Button>
					</form>
				</CardContent>
			</Card>

			<div className="sb-grid sb-gap-6 sb-grid-cols-[repeat(auto-fit,minmax(320px,1fr))]">
				<DisplaySettings
					draft={ draft }
					setDraft={ setDraft }
					onSave={ saveDisplay }
					busy={ busy }
				/>
				<Preview display={ draft } reasons={ reasons } />
			</div>
		</div>
	);
}
