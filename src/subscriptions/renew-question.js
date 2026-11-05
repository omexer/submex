import { __, sprintf } from '@wordpress/i18n';

export function renewQuestion( paysByLink, total ) {
	return paysByLink
		? sprintf(
				/* translators: %s: recurring total. */
				__(
					'This raises a renewal for %s and emails the customer a link to pay it. Continue?',
					'submex'
				),
				total
		  )
		: sprintf(
				/* translators: %s: recurring total. */
				__(
					'This charges the customer %s right now. Continue?',
					'submex'
				),
				total
		  );
}

export function renewNotice( result ) {
	switch ( result ) {
		case 'paid':
			return {
				ok: true,
				message: __( 'The renewal was paid.', 'submex' ),
			};
		case 'pending':
			return {
				ok: true,
				message: __(
					'The payment was submitted and has not cleared yet. It can take a few working days.',
					'submex'
				),
			};
		case 'needs_customer':
			return {
				ok: true,
				message: __(
					'A renewal was raised and the customer was emailed a link to pay or confirm it.',
					'submex'
				),
			};
		default:
			return {
				ok: false,
				message: __(
					'We could not confirm the result. Check the activity log before trying again.',
					'submex'
				),
			};
	}
}
