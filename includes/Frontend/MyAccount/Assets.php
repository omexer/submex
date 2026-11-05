<?php

namespace Submex\Frontend\MyAccount;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Styles for the account screens. Cards below 768px, table-ish above.
 *
 * Most customers manage a subscription from a phone, arriving from an email, so the
 * card layout is the default and the wide layout is the adaptation.
 */
class Assets {

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function enqueue(): void {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		wp_register_style( 'submex-account', false, array(), SUBMEX_VERSION );
		wp_enqueue_style( 'submex-account' );
		wp_add_inline_style( 'submex-account', $this->css() );
	}

	private function css(): string {
		return '
		.submex{list-style:none;margin:0;padding:0;display:grid;gap:1rem}
		.submex-card{border:1px solid rgba(0,0,0,.12);border-radius:8px;padding:1rem}
		.submex-card--warning{border-left:4px solid #b8860b}
		.submex-card--positive{border-left:4px solid #2e7d32}
		.submex-card--muted{background:rgba(0,0,0,.02)}
		.submex-card__head{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between}
		.submex-card__title{margin:0;font-size:1.05rem}
		.submex-card__price{margin:.35rem 0;font-weight:600}
		.submex-price__cycle{font-weight:400;font-size:.9em}
		.submex-card__detail{margin:.25rem 0;font-size:.9em;opacity:.85}
		.submex-badge{display:inline-block;padding:.2em .6em;border-radius:999px;font-size:.8em;border:1px solid currentColor}
		.submex-badge--positive{color:#2e7d32}
		.submex-badge--warning{color:#8a6100}
		.submex-badge--muted{color:#666}
		.submex-card__actions{margin-top:.75rem;display:flex;flex-wrap:wrap;gap:.6rem 1rem;align-items:center}
		/* The theme colours buttons; block themes through wp-element-button. Their size and layout are set here so they hold on any theme. 44px: cancel must be reachable on a phone. */
		.submex-btn{display:inline-flex;align-items:center;justify-content:center;box-sizing:border-box;min-height:44px;padding:.5rem 1.25rem;line-height:1.3;text-align:center;text-decoration:none;cursor:pointer}
		.submex-items{margin:.5rem 0 1rem;padding-left:1.1rem}
		.submex-back{margin:0 0 1rem}
		.submex-detail .submex-card__head h2{margin:0;font-size:1.5rem}
		.submex-detail__state{margin:.5rem 0 1rem}
		.submex-detail__action{margin:0 0 1.25rem}
		.submex-detail>h3{margin:1.5rem 0 .6rem;padding-top:1.25rem;border-top:1px solid rgba(0,0,0,.12);font-size:1.05rem}
		.submex-detail__total{margin:.5rem 0}
		.submex-detail form{margin:0}
		.submex-actions{display:flex;flex-wrap:wrap;gap:.6rem;margin:1rem 0}
		/* Equal visual weight: cancel is never a grey link next to a primary button. */
		.submex-cancel__actions{display:flex;flex-wrap:wrap;gap:.6rem;margin-top:.75rem}
		.submex-cancel__actions .submex-btn{flex:1 1 12rem}
		.submex-card__link{font-size:.9em}
		.submex-empty{padding:1rem 0}
		.submex-flow{display:grid;gap:1rem;max-width:36rem}
		.submex-flow--center{text-align:center;margin:0 auto}
		.submex-flow--center .submex-cancel__actions{justify-content:center}
		.submex-flow--right{text-align:right;margin-left:auto}
		.submex-flow--right .submex-cancel__actions{justify-content:flex-end}
		.submex-flow__title{margin:0}
		/* An aligned offer would shrink to its button, leaving it narrower than the decline beside it. */
		.submex-flow--offer>*{width:100%}
		.submex-flow__reasons{border:0;margin:0;padding:0;display:grid;gap:.5rem;text-align:left}
		.submex-flow__reason{border:1px solid rgba(0,0,0,.12);border-radius:8px;padding:.6rem .8rem}
		/* Shown for "other" by CSS alone, so the screen needs no JavaScript. */
		.submex-flow__detail{display:none;margin-top:.5rem}
		.submex-flow__reason input:checked~.submex-flow__detail{display:block}
		.submex-flow__detail label{display:block}
		.submex-flow__detail textarea{width:100%}
		.submex-message{border:1px solid rgba(0,0,0,.12);border-radius:8px;padding:1.25rem;margin:0 0 1.5rem;text-align:center}
		.submex-message__icon{display:inline-grid;place-items:center;width:2.5rem;height:2.5rem;border-radius:50%;background:#2e7d32;color:#fff;font-size:1.25rem}
		.submex-message__title{margin:.5rem 0}
		@media(min-width:768px){.submex{gap:.75rem}}
		';
	}
}
