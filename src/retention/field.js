export function Field( { id, label, children } ) {
	return (
		<div className="sb-grid sb-gap-1.5">
			<label htmlFor={ id } className="sb-text-sm sb-font-medium">
				{ label }
			</label>
			{ children }
		</div>
	);
}
