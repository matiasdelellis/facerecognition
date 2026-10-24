module.exports = {
	extends: '@nextcloud/eslint-config',
	env: {
		browser: true,
		es2022: true,
	},
	globals: {
		OC: 'readonly',
		OCP: 'readonly',
		OCA: 'readonly',
		Handlebars: 'readonly',
		lozad: 'readonly',
		t: 'readonly',
		n: 'readonly',
	},
	rules: {
		// The code predates the current Nextcloud formatting rules. They are
		// kept off so that lint reports real problems and not a full reformat.
		indent: 'off',
		semi: 'off',
		'space-before-function-paren': 'off',
		'object-shorthand': 'off',
		'comma-dangle': 'off',
		'multiline-ternary': 'off',
		'quote-props': 'off',
		'dot-notation': 'off',
		quotes: 'off',
		'no-new': 'off',
		'vue/html-quotes': 'off',
		'vue/max-attributes-per-line': 'off',
		'vue/html-closing-bracket-spacing': 'off',
		'vue/first-attribute-linebreak': 'off',
		'vue/html-closing-bracket-newline': 'off',
		'vue/html-self-closing': 'off',
		'vue/v-on-style': 'off',
		'vue/attributes-order': 'off',
		'vue/this-in-template': 'off',
	},
}
