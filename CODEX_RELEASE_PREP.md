# Copy this task into Codex from `E:\mcp-server`

Prepare **only this standalone Laravel MCP server** (`E:\mcp-server`) for a safe public GitHub source-code release. **Do not inspect, modify, commit, connect to, or push any other project**, particularly `E:\dev-tracker`. Do not publish or push automatically.

A documentation starter pack is available separately. If the documentation files have been copied into the repository, use them as a starting point; otherwise generate matching documentation directly. Verify every documentation claim against the real code and tests, correcting inaccuracies.

## Inspect and plan

1. Inspect the actual Laravel 13, PHP and `laravel/mcp` versions, `routes/ai.php`, `MyMcpServer`, the four tools, Composer config, tests, `.gitignore` and repository status.
2. Summarize the current state and risks before editing. Keep the server local-only and read-only for this release; do not add write tools, shell execution, database access, or public deployment.
3. Identify security issues involving path traversal, Windows paths/junctions, symlinks, races, huge files/directories, non-text/binary files, error information disclosure, malformed Unicode and unauthenticated exposure.

## Implement release quality

4. Add thorough automated tests for tools, arguments, outputs, missing paths, invalid filenames, traversal and links. Use temporary test directories and fixtures. Never read actual private data. Make directory access configurable for tests without permitting arbitrary client-selected roots.
5. Strengthen tool implementation as needed, keep existing user-visible tool names stable, and ensure size/list bounds and deterministic error responses. Verify tool registration and MCP endpoint tests using the official Laravel MCP testing APIs for the installed version.
6. Ensure the server binds only to loopback by documented commands. Do not claim the unauthenticated HTTP endpoint is safe for remote users. Document the current threat model and future remote authentication options, rather than silently exposing it.
7. Keep sample fixtures harmless; exclude `.env`, storage files, generated files, secrets and IDE/Inspector tokens. Review both tracked files and existing git history for accidentally committed secrets. If secrets appear, stop and report remediation steps; do not paste secret values into output.
8. Add/verify README, thorough Windows/Linux installation docs, tool reference, real screenshot locations, security guide, contributing guide, changelog, MIT licence placeholder for maintainer review, GitHub issue/PR templates, and CI for the versions actually supported. Avoid badges pointing to non-existing CI workflows.
9. Run PHP tests and style checks; fix all failures without introducing PHPStan unless explicitly requested. Report verified results and any remaining blockers.

## Stop conditions

- Do not create a GitHub repository, alter remote URLs, push commits, create a public release, buy credits, or deploy endpoints without the maintainer's explicit confirmation.
- Do not fabricate screenshots or claim tests passed unless run.
- End with: summary of actual changes, tests run, unresolved risks, and a concise list of steps required for the maintainer to publish the separate GitHub repository.
