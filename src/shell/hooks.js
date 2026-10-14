/**
 * Where other plugins fill Submex screens, through the wp.hooks every plugin on the page shares.
 */
import { addFilter, applyFilters, removeFilter } from '@wordpress/hooks';

export const retentionHooks = Object.freeze( {
	offersTab: 'submex.retention.offersTab',
	reasonStats: 'submex.retention.reasonStats',
	messageTypes: 'submex.retention.messageTypes',
} );

export { addFilter, applyFilters, removeFilter };
