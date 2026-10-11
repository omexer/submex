/**
 * The Submex admin app, published as window.submex.shell for every route bundle.
 */
import { registerRoute, navigate, current } from './router';
import { boot } from './app';
import './shell.css';

export { registerRoute, navigate, current };

document.addEventListener( 'DOMContentLoaded', boot );
