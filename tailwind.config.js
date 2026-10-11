/**
 * Tailwind, made safe for wp-admin.
 *
 * Two settings do that work. Preflight is off, because its global reset would restyle
 * every core admin element on the page, not just ours. Every class is prefixed, so a
 * utility of ours can never match a core rule or the other way round.
 */
module.exports = {
	prefix: 'sb-',
	darkMode: [ 'class', '.submex-ui--dark' ],
	corePlugins: { preflight: false },
	content: [ './src/**/*.{js,jsx}' ],
	theme: {
		extend: {
			colors: {
				border: 'hsl(var(--sb-border))',
				input: 'hsl(var(--sb-input))',
				ring: 'hsl(var(--sb-ring))',
				background: 'hsl(var(--sb-background))',
				foreground: 'hsl(var(--sb-foreground))',
				primary: {
					DEFAULT: 'hsl(var(--sb-primary))',
					foreground: 'hsl(var(--sb-primary-foreground))',
				},
				secondary: {
					DEFAULT: 'hsl(var(--sb-secondary))',
					foreground: 'hsl(var(--sb-secondary-foreground))',
				},
				muted: {
					DEFAULT: 'hsl(var(--sb-muted))',
					foreground: 'hsl(var(--sb-muted-foreground))',
				},
				accent: {
					DEFAULT: 'hsl(var(--sb-accent))',
					foreground: 'hsl(var(--sb-accent-foreground))',
				},
				destructive: {
					DEFAULT: 'hsl(var(--sb-destructive))',
					foreground: 'hsl(var(--sb-destructive-foreground))',
				},
				success: {
					DEFAULT: 'hsl(var(--sb-success))',
					foreground: 'hsl(var(--sb-success-foreground))',
				},
				card: {
					DEFAULT: 'hsl(var(--sb-card))',
					foreground: 'hsl(var(--sb-card-foreground))',
				},
			},
			borderRadius: {
				lg: 'var(--sb-radius)',
				md: 'calc(var(--sb-radius) - 2px)',
				sm: 'calc(var(--sb-radius) - 4px)',
			},
		},
	},
	plugins: [ require( 'tailwindcss-animate' ) ],
};
