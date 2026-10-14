/**
 * The little rich text the retention screens allow: bold, italic, underline, lists and links.
 *
 * The server filters it again on save and on output; this keeps the preview honest about what survives.
 */
import { useEffect, useRef } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

const ALLOWED = [ 'p', 'br', 'strong', 'em', 'u', 'ul', 'ol', 'li', 'a' ];
const DROPPED = [ 'script', 'style', 'iframe', 'object', 'embed', 'template' ];
// What browsers write for these commands and for a new line.
const RENAMED = { b: 'strong', i: 'em', div: 'p' };
const SAFE_HREF = /^(https?:|mailto:|tel:|\/|#)/i;

function copy( from, into, doc ) {
	from.childNodes.forEach( ( node ) => {
		if ( node.nodeType === node.TEXT_NODE ) {
			into.appendChild( doc.createTextNode( node.textContent ) );
			return;
		}

		if ( node.nodeType !== node.ELEMENT_NODE ) {
			return;
		}

		const raw = node.tagName.toLowerCase();
		const tag = RENAMED[ raw ] || raw;

		if ( DROPPED.includes( tag ) ) {
			return;
		}

		if ( ! ALLOWED.includes( tag ) ) {
			copy( node, into, doc );
			return;
		}

		const element = doc.createElement( tag );

		if ( 'a' === tag ) {
			const href = ( node.getAttribute( 'href' ) || '' ).trim();

			if ( SAFE_HREF.test( href ) ) {
				element.setAttribute( 'href', href );
			}

			[ 'target', 'rel' ].forEach( ( name ) => {
				if ( node.hasAttribute( name ) ) {
					element.setAttribute( name, node.getAttribute( name ) );
				}
			} );
		}

		copy( node, element, doc );
		into.appendChild( element );
	} );
}

export function cleanRichText( html ) {
	const parsed = new window.DOMParser().parseFromString(
		`<!doctype html><body>${ html || '' }</body>`,
		'text/html'
	);
	const doc = document.implementation.createHTMLDocument( '' );
	const root = doc.createElement( 'div' );

	copy( parsed.body, root, doc );

	return root.innerHTML;
}

export function RichPreview( { html, className, style } ) {
	return (
		<div
			className={ className }
			style={ style }
			// Already reduced to the allowlist above, as the server does.
			dangerouslySetInnerHTML={ { __html: cleanRichText( html ) } }
		/>
	);
}

const COMMANDS = [
	{ command: 'bold', label: __( 'Bold', 'submex' ), glyph: 'B' },
	{ command: 'italic', label: __( 'Italic', 'submex' ), glyph: 'I' },
	{ command: 'underline', label: __( 'Underline', 'submex' ), glyph: 'U' },
	{
		command: 'insertUnorderedList',
		label: __( 'Bulleted list', 'submex' ),
		glyph: '•',
	},
	{
		command: 'insertOrderedList',
		label: __( 'Numbered list', 'submex' ),
		glyph: '1.',
	},
];

export function RichText( { id, label, value, onChange } ) {
	const ref = useRef( null );

	// Written only from outside: rewriting while the merchant types would move the caret.
	useEffect( () => {
		const node = ref.current;

		if (
			node &&
			node.ownerDocument.activeElement !== node &&
			node.innerHTML !== value
		) {
			node.innerHTML = cleanRichText( value );
		}
	}, [ value ] );

	const changed = () => onChange( cleanRichText( ref.current.innerHTML ) );

	const run = ( command ) => {
		ref.current.focus();
		ref.current.ownerDocument.execCommand?.( command );
		changed();
	};

	return (
		<div className="sb-grid sb-gap-1.5">
			<span id={ `${ id }-label` } className="sb-text-sm sb-font-medium">
				{ label }
			</span>
			<div className="sb-rounded-md sb-border sb-border-input">
				<div
					role="toolbar"
					aria-label={ sprintf(
						/* translators: %s: the field being formatted, such as Description. */
						__( '%s formatting', 'submex' ),
						label
					) }
					aria-controls={ id }
					className="sb-flex sb-gap-1 sb-border-b sb-border-input sb-p-1"
				>
					{ COMMANDS.map( ( item ) => (
						<button
							key={ item.command }
							type="button"
							aria-label={ item.label }
							title={ item.label }
							className="sb-h-7 sb-min-w-7 sb-rounded sb-px-1.5 sb-text-sm hover:sb-bg-accent"
							// Keeps the selection in the editor, which a click would otherwise take.
							onMouseDown={ ( event ) => event.preventDefault() }
							onClick={ () => run( item.command ) }
						>
							<span aria-hidden="true">{ item.glyph }</span>
						</button>
					) ) }
				</div>
				<div
					id={ id }
					ref={ ref }
					role="textbox"
					aria-multiline="true"
					aria-labelledby={ `${ id }-label` }
					contentEditable
					suppressContentEditableWarning
					tabIndex={ 0 }
					className="sb-min-h-20 sb-px-3 sb-py-2 sb-text-sm focus-visible:sb-outline-none focus-visible:sb-ring-2 focus-visible:sb-ring-ring"
					onInput={ changed }
					onBlur={ changed }
				/>
			</div>
		</div>
	);
}
