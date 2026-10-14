/**
 * The Submex admin app, published as window.submex.shell for every route bundle.
 */
import { registerRoute, navigate, current } from './router';
import { boot } from './app';
import './shell.css';

export { registerRoute, navigate, current };
export { retentionHooks, addFilter, applyFilters, removeFilter } from './hooks';

document.addEventListener( 'DOMContentLoaded', boot );
