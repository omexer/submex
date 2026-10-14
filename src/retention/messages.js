/**
 * The Confirmation Message tab: what customers see after they cancel, pause or accept an offer.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Card, CardContent, Input, Switch } from '@submex/ui';
import { applyFilters, retentionHooks } from '@submex/shell';
import { RichPreview, RichText } from './rich-text';
import { Field } from './field';

const NAMES = {
	cancel: __( 'Subscription Cancel', 'submex' ),
	pause: __( 'Subscription Pause', 'submex' ),
	offer_accept: __( 'Offer Accept', 'submex' ),
};

function Check() {
	return (
		<span
			className="sb-inline-grid sb-h-9 sb-w-9 sb-shrink-0 sb-place-items-center sb-rounded-full sb-bg-success sb-text-success-foreground"
			aria-hidden="true"
		>
			✓
		</span>
	);
}

/**
 * The rows to list: one per message the server has, named, and open to extensions.
 *
 * @param {Object} messages By type, as the server returned them.
 * @return {Array<{id: string, label: string}>} Rows in order.
 */
export function messageTypes( messages ) {
	const types = Object.keys( messages ).map( ( id ) => ( {
		id,
		label: NAMES[ id ] || id,
	} ) );

	return applyFilters( retentionHooks.messageTypes, types, messages ).filter(
		( type ) => type && messages[ type.id ]
	);
}

function Editor( { type, message, onSave, onClose } ) {
	const [ draft, setDraft ] = useState( message );
	const [ busy, setBusy ] = useState( false );
	const first = useRef( null );
	const titleId = `submex-message-${ type.id }-title`;

	const close = useRef( onClose );

	close.current = onClose;

	useEffect( () => {
		const doc = first.current?.ownerDocument || document;
		const onKey = ( event ) => {
			if ( 'Escape' === event.key ) {
				close.current();
			}
		};

		first.current?.focus();
		doc.addEventListener( 'keydown', onKey );

		return () => doc.removeEventListener( 'keydown', onKey );
	}, [] );

	const set = ( key ) => ( event ) =>
		setDraft( { ...draft, [ key ]: event.target.value } );

	return (
		<div className="sb-fixed sb-inset-0 sb-z-[100000] sb-grid sb-place-items-center sb-bg-black/40 sb-p-4">
			<div
				role="dialog"
				aria-modal="true"
				aria-labelledby={ titleId }
				className="sb-max-h-full sb-w-full sb-max-w-3xl sb-overflow-auto sb-rounded-lg sb-bg-background sb-p-6 sb-shadow-lg"
			>
				<h2
					id={ titleId }
					className="sb-mt-0 sb-text-lg sb-font-semibold"
				>
					{ sprintf(
						/* translators: %s: message name, such as Subscription Cancel */
						__( 'Edit %s message', 'submex' ),
						type.label
					) }
				</h2>
				<div className="sb-grid sb-gap-6 sb-grid-cols-[repeat(auto-fit,minmax(260px,1fr))]">
					<form
						className="sb-grid sb-gap-4"
						onSubmit={ ( event ) => {
							event.preventDefault();
							setBusy( true );
							onSave( draft ).catch( () => setBusy( false ) );
						} }
					>
						<Field
							id={ `submex-message-${ type.id }-title-field` }
							label={ __( 'Title', 'submex' ) }
						>
							<Input
								id={ `submex-message-${ type.id }-title-field` }
								ref={ first }
								value={ draft.title }
								maxLength={ 120 }
								onChange={ set( 'title' ) }
							/>
						</Field>
						<RichText
							id={ `submex-message-${ type.id }-description` }
							label={ __( 'Description', 'submex' ) }
							value={ draft.description }
							onChange={ ( description ) =>
								setDraft( { ...draft, description } )
							}
						/>
						<Field
							id={ `submex-message-${ type.id }-button` }
							label={ __( 'Button text', 'submex' ) }
						>
							<Input
								id={ `submex-message-${ type.id }-button` }
								value={ draft.button_text }
								maxLength={ 40 }
								required
								onChange={ set( 'button_text' ) }
							/>
						</Field>
						<div className="sb-flex sb-gap-2">
							<Button type="submit" disabled={ busy }>
								{ __( 'Save', 'submex' ) }
							</Button>
							<Button
								type="button"
								variant="ghost"
								onClick={ onClose }
							>
								{ __( 'Cancel', 'submex' ) }
							</Button>
						</div>
					</form>
					<div
						className="sb-grid sb-content-start sb-justify-items-center sb-gap-2 sb-rounded-lg sb-border sb-border-border sb-p-5 sb-text-center"
						data-testid="message-preview"
					>
						<Check />
						<p className="sb-m-0 sb-font-semibold">
							{ draft.title }
						</p>
						<RichPreview
							html={ draft.description }
							className="sb-text-sm sb-text-muted-foreground"
						/>
						<span className="sb-rounded-md sb-border sb-border-input sb-px-4 sb-py-2 sb-text-sm">
							{ draft.button_text }
						</span>
					</div>
				</div>
			</div>
		</div>
	);
}

export function Messages( { messages, setMessages, request } ) {
	const [ editing, setEditing ] = useState( null );

	const save = ( type, changes ) =>
		request( {
			path: `/submex/v1/retention/messages/${ type }`,
			method: 'POST',
			data: changes,
		} ).then( ( saved ) =>
			setMessages( ( current ) => ( { ...current, [ type ]: saved } ) )
		);

	return (
		<div className="sb-grid sb-gap-4">
			<div>
				<p className="sb-m-0 sb-text-sm">
					{ __(
						'What customers see after they cancel, pause or accept an offer.',
						'submex'
					) }
				</p>
				<p className="sb-m-0 sb-text-xs sb-text-muted-foreground">
					{ __(
						'Shown while Ask before cancelling is on. Switched off, customers see a one-line notice instead.',
						'submex'
					) }
				</p>
			</div>
			<Card>
				<CardContent className="sb-p-0">
					<ul className="sb-m-0 sb-list-none sb-p-0">
						{ messageTypes( messages ).map( ( type ) => (
							<li
								key={ type.id }
								className="sb-flex sb-items-center sb-gap-3 sb-border-b sb-border-border sb-px-5 sb-py-3 last:sb-border-b-0"
							>
								<Check />
								<span className="sb-flex-1 sb-text-sm sb-font-medium">
									{ type.label }
								</span>
								<Button
									type="button"
									variant="ghost"
									size="sm"
									aria-label={ sprintf(
										/* translators: %s: message name */
										__( 'Edit the %s message', 'submex' ),
										type.label
									) }
									onClick={ () => setEditing( type ) }
								>
									{ __( 'Edit', 'submex' ) }
								</Button>
								<Switch
									checked={ !! messages[ type.id ].enabled }
									aria-label={ sprintf(
										/* translators: %s: message name */
										__( 'Show the %s message', 'submex' ),
										type.label
									) }
									onCheckedChange={ ( enabled ) =>
										save( type.id, { enabled } )
									}
								/>
							</li>
						) ) }
					</ul>
				</CardContent>
			</Card>
			{ editing ? (
				<Editor
					type={ editing }
					message={ messages[ editing.id ] }
					onClose={ () => setEditing( null ) }
					onSave={ ( draft ) =>
						save( editing.id, {
							title: draft.title,
							description: draft.description,
							button_text: draft.button_text,
						} ).then( () => setEditing( null ) )
					}
				/>
			) : null }
		</div>
	);
}
