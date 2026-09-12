const base = require( '@wordpress/scripts/config/jest-unit.config' );

module.exports = {
	...base,
	modulePathIgnorePatterns: [ '<rootDir>/.wp-env-home/' ],
};
