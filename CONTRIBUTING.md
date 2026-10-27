# Contributing

Thanks for improving this Laravel MCP example.

1. Check existing issues and propose a focused change before building a large feature.
2. Fork the repository, create a descriptive branch and keep pull requests small.
3. Include tests for new behavior, especially filename validation, filesystem boundaries and MCP schemas.
4. Run `composer install`, `php artisan test` and `vendor/bin/pint --test` before a pull request; fix formatting with `vendor/bin/pint` if required.
5. Update tool documentation and screenshots if behavior changes. No real credentials, customer data or personal documents in tests.
6. Explain expected behavior, screenshots or sample MCP input/output, and how you tested the change.

## Development principles

- Laravel 13; use PHP 8.4+ for locked development dependencies. PHP 8.5.10 verified locally.
- Clear, narrow MCP tools rather than unrestricted access.
- Read-only by default; any mutating tool needs explicit design and approval controls.
- Consistent errors and stable tool names, because AI clients depend on them.
- Do not expand the permitted filesystem root silently.
- Protect compatibility with Windows and Linux.

## Good first issues

- Expand Windows reparse-point and filesystem race coverage.
- Add pagination to bounded file listings.
- Improve typed JSON responses and error-code consistency.
- Add optional STDIO transport after testing client compatibility.
- Add deterministic demo fixtures and screenshot automation.

## Security issues

Please follow [SECURITY.md](SECURITY.md) rather than opening public issues containing exploits.

