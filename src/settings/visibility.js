// Shown while its switch is on, and that switch's own switch, as the PHP page decides it.
export function isShown( field, byId, valueOf, depth = 0 ) {
	const parent = byId[ field.submex_show_if ];

	if ( ! parent || parent.type !== 'checkbox' || depth > 10 ) {
		return true;
	}

	return (
		valueOf( parent.id ) === 'yes' &&
		isShown( parent, byId, valueOf, depth + 1 )
	);
}

// Every field on a page by option id, including the ones drawn beside another.
export function fieldsOf( page ) {
	const byId = {};

	( page?.cards || [] ).forEach( ( card ) =>
		card.rows.forEach( ( row ) => {
			if ( ! row.id ) {
				return;
			}

			byId[ row.id ] = row;
			( row.joined || [] ).forEach( ( extra ) => {
				byId[ extra.id ] = extra;
			} );
		} )
	);

	return byId;
}
