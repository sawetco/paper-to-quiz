const wpPlugin = require("@wordpress/eslint-plugin");
const jestPlugin = require("eslint-plugin-jest");
module.exports = [
  {
    ignores: [
      "**/build/**",
      "**/node_modules/**",
      "**/vendor/**",
      "**/.wp-env-home/**",
    ],
  },
  { files: ["**/*.jsx", "**/*.ts", "**/*.tsx"] },
  ...wpPlugin.configs.recommended,
  {
    languageOptions: {
      parserOptions: {
        requireConfigFile: false,
        babelOptions: {
          presets: [require.resolve("@wordpress/babel-preset-default")],
        },
      },
    },
  },
  {
    ...jestPlugin.configs["flat/recommended"],
    files: ["**/*.test.{js,ts,tsx}"],
  },
];
