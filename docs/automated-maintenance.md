# Automated maintenance

Dependabot checks dependencies weekly. The trusted default-branch coordinator classifies dependency-only changes, waits for every required check from GitHub Actions, updates outdated branches, and squash-merges compatible minor/patch updates one at a time. Major versions, 0.x minor changes, manifest script changes, Rust source/build configuration edits, conflicts and repeated failures require human review. It never executes PR code with its write token.

Quality and Security are explicitly dispatched after a workflow-token merge because GitHub suppresses ordinary push workflows for those writes. Production still waits for the actual merged commit checks in Vercel. Scheduled data/formula changes keep their existing human review policy.

Failed jobs are retried once. Persistent failures and decisions appear in one assigned `Maintenance requires attention` issue; the issue is updated without repeated comments and closes when there is nothing actionable. Passing routine work only appears in Actions summaries. The daily fallback checks existing runs; it does not send mail, SMS or Meta messages.

Actions use read-only permissions by default. The maintenance job alone has the permissions needed to merge, retry, dispatch and maintain its issue. CODEOWNERS documents responsibility; an independent approval is not required for a sole maintainer. Secrets must stay in GitHub/Vercel settings and never in commits or artifacts.

Product changes prepare a version PR automatically (patch by default; conventional `feat:` commits choose a minor). Breaking changes require a reviewed major. After full checks, the tested ZIP is released to GitHub and SVN; publication completes only after WordPress.org serves identical files. `SVN_USERNAME` and `SVN_PASSWORD` are repository Actions secrets. A failed Release can be rerun for the same version without overwriting an existing different tag.

Public repository CodeQL uses the explicit workflow so generated version/contribution PRs can dispatch the same source analyses. Both language jobs are required, including for bot-generated changes; scheduled scans remain fresh.

The maintenance GitHub App mints an expiring token restricted to this repository. It creates generated PRs and updates outdated branches, so GitHub starts normal PR checks without per-run human approval. The coordinator retains a separate least-privilege workflow token for check/status reads, retries, merges and attention issues. Older failures are not reported while a newer run is pending or after the source head changed.
