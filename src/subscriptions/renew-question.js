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
