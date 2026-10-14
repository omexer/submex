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
		.submex-card--muted{opacity:.75}
		.submex-card__head{display:flex;flex-wrap:wrap;gap:.5rem;align-items:center;justify-content:space-between}
		.submex-card__title{margin:0;font-size:1.05rem}
		.submex-card__price{margin:.35rem 0;font-weight:600}
		.submex-card__detail{margin:.25rem 0;font-size:.9em;opacity:.85}
		.submex-badge{display:inline-block;padding:.2em .6em;border-radius:999px;font-size:.8em;border:1px solid currentColor}
		.submex-badge--positive{color:#2e7d32}
		.submex-badge--warning{color:#8a6100}
		.submex-badge--muted{color:#666}
		.submex-card__actions{margin-top:.75rem}
		/* 44px minimum touch targets: cancel must be reachable on a phone. */
		.submex-card__actions .button,.submex-cancel__actions .button{min-height:44px;line-height:44px;padding:0 1rem}
		.submex-items{margin:.5rem 0 1rem;padding-left:1.1rem}
		/* Equal visual weight: cancel is never a grey link next to a primary button. */
		.submex-cancel__actions{display:flex;flex-wrap:wrap;gap:.6rem}
		.submex-cancel__actions .submex-btn--primary{background:#7f54b3;border-color:#7f54b3;color:#fff;font-weight:600}
			.submex-btn--primary:hover{background:#6b4699;border-color:#6b4699;color:#fff}
			.submex-card__link{align-self:center;font-size:.9em}
			.submex-detail__action{margin:0 0 1.25rem}
			.submex-btn{flex:1 1 12rem;text-align:center}
		.submex-empty{padding:1rem 0}
		.submex-flow{display:grid;gap:1rem;max-width:36rem}
		.submex-flow--center{text-align:center;margin:0 auto}
		.submex-flow--center .submex-cancel__actions{justify-content:center}
		.submex-flow--right{text-align:right;margin-left:auto}
		.submex-flow--right .submex-cancel__actions{justify-content:flex-end}
		.submex-flow__title{margin:0}
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
