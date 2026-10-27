# Security policy

## Supported scope

**Pre-release local development only.** This project currently exposes an unauthenticated MCP web endpoint and must not be bound to a public interface or made reachable from an untrusted network.

## Important protections and gaps

- Use `php artisan serve --host=127.0.0.1 --port=8123`. A local address reduces network exposure but does not authenticate clients on that machine.
- Tool reads are restricted by code to `storage/app/mcp-files`. Keep this directory free of secrets and proprietary documents.
- Reads validate UTF-8 and reject binary control characters, with a bounded 1 MiB stream. Text may still contain malicious agent instructions.
- Filename validation, canonical path checks and link rejection have regression coverage. Link creation was unavailable locally on Windows; Linux CI must run that test. Native Windows reparse-point variants need maintainer validation.
- Treat tool responses as untrusted content. Documents may contain instructions intended to manipulate AI agents; clients must not treat file text as privileged commands.
- Never commit `.env`, `.env.*` secrets, real test documents, database files, access tokens, Inspector auth URLs, or private customer information.
- Do not include example credentials or keys in screenshots or issue reports.
- Do not enable arbitrary filesystem write, shell execution, database query, or remote URL fetching tools without a separate threat model and approval model.

## Before any remotely hosted version

Implement and test proper authentication (for example, Laravel Sanctum for controlled token-based clients, or Passport/OAuth for broader interoperable clients), authorization, TLS, request and tool rate limits, output/size limits, logging that excludes sensitive data, network access restrictions, and protections against path traversal, symlink/junction abuse and resource exhaustion. Review Laravel MCP's official authentication guidance and the MCP specification.

## Reporting a vulnerability

Please use GitHub's **Report a vulnerability** private security-advisory mechanism after the repository has been published and private reporting enabled. Until that is configured, open a normal issue **without** sharing exploit details, secrets or sensitive files, asking the maintainer for a secure contact method.

## Dependency management

Enable Dependabot security updates and GitHub secret scanning/push protection. Audit Composer dependencies before each release and keep Laravel and `laravel/mcp` on supported versions.

The sandbox and all ancestor directories must be writable only by trusted users. Portable PHP checks cannot eliminate file-swap races or distinguish malicious hard links. Do not use this as an isolation boundary against local filesystem writers. List/search scans stop above 1,000 entries. Server directory configuration is trusted and never client-selected.

