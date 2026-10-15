module.exports = {
	preset: "@wordpress/jest-preset-default",
	transform: {
		"\\.[jt]sx?$": [
			"babel-jest",
			{ presets: ["@wordpress/babel-preset-default"] },
		],
	},
	modulePathIgnorePatterns: ["<rootDir>/.wp-env-home/"],
};
