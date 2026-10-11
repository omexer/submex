/**
 * Submex's shared admin UI: shadcn/ui components, plus the few composites every Submex
 * screen needs.
 *
 * Published on a global rather than as a package. Submex Pro builds separately and cannot
 * import from this plugin at build time, so it consumes these as an external, the same way
 * everything consumes wp.components.
 */
import { __ } from '@wordpress/i18n';

import './globals.css';

export {
	Card,
	CardHeader,
	CardTitle,
	CardContent,
	CardFooter,
} from './components/ui/card';
export { Badge, badgeVariants } from './components/ui/badge';
export { Button, buttonVariants } from './components/ui/button';
export { Skeleton } from './components/ui/skeleton';
export {
	Table,
	TableHeader,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
} from './components/ui/table';
export { Input, Select, Checkbox } from './components/ui/input';
export { Switch } from './components/ui/switch';
export { cn } from './lib/utils';

import { Card, CardContent } from './components/ui/card';

function toneFor( delta ) {
	if ( typeof delta !== 'number' ) {
		return 'sb-text-muted-foreground';
	}

	return delta < 0 ? 'sb-text-destructive' : 'sb-text-success';
}

export function Stat( { label, value, meta, delta, children } ) {
	const tone = toneFor( delta );

	return (
		<Card>
			<CardContent className="sb-p-5">
				<span className="sb-block sb-text-sm sb-text-muted-foreground">
					{ label }
				</span>
				<span className="sb-mt-1 sb-block sb-text-3xl sb-font-semibold sb-tabular-nums sb-leading-tight">
					{ value }
				</span>
				{ meta ? (
					<span
						className={ `sb-mt-1.5 sb-block sb-text-xs ${ tone }` }
					>
						{ meta }
					</span>
				) : null }
				{ children }
			</CardContent>
		</Card>
	);
}

// A line over time: one series, no axes, no library.
export function Sparkline( { points, label } ) {
	const values = ( points || [] ).map( ( point ) => Number( point ) || 0 );

	if ( values.length < 2 ) {
		return null;
	}

	const top = Math.max( ...values );
	const floor = Math.min( ...values );
	// A flat line belongs in the middle, not on the floor or off the top.
	const span = top - floor || Math.max( top, 1 );
	const step = 100 / ( values.length - 1 );

	const coords = values.map( ( value, index ) => {
		const y = top === floor ? 50 : 100 - ( ( value - floor ) / span ) * 100;

		return `${ ( index * step ).toFixed( 2 ) },${ y.toFixed( 2 ) }`;
	} );

	return (
		<svg
			className="sb-mt-3 sb-block sb-h-12 sb-w-full sb-overflow-visible"
			viewBox="0 0 100 100"
			preserveAspectRatio="none"
			role="img"
			aria-label={ label || __( 'Trend over time', 'submex' ) }
			focusable="false"
		>
			<polyline
				points={ coords.join( ' ' ) }
				fill="none"
				stroke="hsl(var(--sb-primary))"
				strokeWidth="2"
				strokeLinecap="round"
				strokeLinejoin="round"
				vectorEffect="non-scaling-stroke"
			/>
		</svg>
	);
}

// One bar, every status in proportion, with a legend naming the colours.
export function StatusBar( { parts } ) {
	const rows = ( parts || [] ).filter( ( part ) => part.count > 0 );
	const total = rows.reduce( ( sum, part ) => sum + part.count, 0 );

	if ( ! total ) {
		return (
			<p className="sb-text-sm sb-text-muted-foreground">
				{ __( 'Nothing to show yet.', 'submex' ) }
			</p>
		);
	}

	return (
		<div>
			<div className="sb-flex sb-h-2.5 sb-overflow-hidden sb-rounded-full sb-bg-muted">
				{ rows.map( ( part ) => (
					<span
						key={ part.key }
						className="sb-h-full"
						style={ {
							width: `${ ( part.count / total ) * 100 }%`,
							background: part.colour,
						} }
						title={ `${ part.label }: ${ part.count }` }
					/>
				) ) }
			</div>
			<ul className="sb-mt-3.5 sb-flex sb-flex-wrap sb-gap-x-5 sb-gap-y-2 sb-text-sm">
				{ rows.map( ( part ) => (
					<li
						key={ part.key }
						className="sb-flex sb-items-center sb-gap-2"
					>
						<span
							className="sb-h-2.5 sb-w-2.5 sb-shrink-0 sb-rounded-sm"
							style={ { background: part.colour } }
							aria-hidden="true"
						/>
						<span>{ part.label }</span>
						<span className="sb-tabular-nums sb-text-muted-foreground">
							{ part.count }
						</span>
					</li>
				) ) }
			</ul>
		</div>
	);
}

export const version = '1';
