# Public release checklist

> Keep unchecked items open until verified. See RELEASE_VERIFICATION.md for local results and blockers.

## Product

- [ ] Name, one-sentence purpose and developer audience finalized.
- [ ] Four tools reliably exposed and documented; stable tool names verified.
- [ ] Fresh-clone install works on Windows and Linux with PHP 8.4+.
- [ ] Add realistic but harmless sample files and examples.
- [ ] Decide whether local STDIO should also be supported in v0.1.0.

## Safety â€” hard blockers for public source release

- [ ] `.env`, database secrets, tokens, private files and Inspector URLs are not tracked or present in git history.
- [ ] Add `/storage/app/mcp-files/*` ignore rule, preserving a placeholder `.gitignore` if required.
- [ ] Review `composer.json`, dependencies and generated files for private URLs and company names.
- [ ] Unit tests cover `../.env`, absolute paths, Windows separator tricks, symbolic links/junctions, case/encoding, size limits and missing files.
- [ ] Confirm no MCP tool can read or mutate data outside intended sandbox; keep endpoint loopback-only.
- [ ] Security policy accurate; no claim of public hosted service or production-ready authentication.
- [ ] Enable GitHub secret scanning and push protection; inspect the *whole git history*, not only the latest files.

## Developer experience

- [ ] README quick start copied and executed from a clean checkout.
- [ ] Include PHP/Laravel/MCP versions, tool schemas, outputs and limitations.
- [ ] Test Codex installation instructions and MCP Inspector instructions.
- [ ] Screenshots demonstrate a real successful end-to-end workflow.
- [ ] Contributing guide, changelog, issue templates, release notes and MIT copyright completed.
- [ ] CI runs tests and formatting checks on PRs; verify a green build.
- [ ] GitHub repository description, topics, About link and social preview completed.

## Ship

- [ ] Create a **new**, separate repository (not the DevTrack repository).
- [ ] Push code only after passing secret review.
- [ ] Tag `v0.1.0-alpha.1` (or chosen semver prerelease).
- [ ] Publish GitHub release with supported versions, screenshots, getting-started link and known limitations.
- [ ] Open a few beginner-friendly GitHub issues and label them `good first issue`.

## After release

- [ ] Collect feedback and bug reports; fix broken installation steps promptly.
- [ ] Enable Dependabot and handle security updates.
- [ ] Only consider a remotely accessible hosted endpoint after authentication, authorization and a dedicated security assessment.

