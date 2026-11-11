#!/usr/bin/env bash
set -euo pipefail

# Hosted Ubuntu already supplies Chromium's native libraries and Latin fonts.
# Do not depend on apt DNS to install optional font families for every job.
# Keep Playwright's host validation and an actual launch/render preflight required.
npx playwright install chromium
node .github/automation/verify-test-browser.cjs
