#!/usr/bin/env bash
set -euo pipefail

# Hosted runners use an Azure-prioritized mirrorlist. Its DNS outage must not
# block browser setup; keep official Ubuntu HTTPS mirrors and signature checks.
if [[ -f /etc/apt/apt-mirrors.txt ]]; then
	printf '%s\n' \
		'https://archive.ubuntu.com/ubuntu/' \
		'https://security.ubuntu.com/ubuntu/' |
		sudo tee /etc/apt/apt-mirrors.txt > /dev/null
fi

npx playwright install --with-deps chromium
