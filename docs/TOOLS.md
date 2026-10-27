# Tool reference

The server registers `system-info-tool`, `list-files-tool`, `read-file-tool`, and `search-files-tool` (the class names are SystemInfoTool, ListFilesTool, ReadFileTool, and SearchFilesTool). Responses use MCP text content; failures set the MCP error flag.

- System info: no arguments; returns JSON text with server, status, php_version, laravel_version and message.
- List files: no arguments; returns JSON text with total_files and files containing name and size_bytes, sorted case-insensitively with a bytewise tie-breaker.
- Search files: required query string, trimmed, 1–100 UTF-8 characters; control characters and malformed UTF-8 rejected. Case-insensitive filename substring search. Returns query, total_matches and files containing filename and size_bytes. Only the first 100 sorted matches are returned; total_matches counts all matches.
- Read file: required filename string, 1–100 ASCII characters overall, starts with a letter/digit and ends with .txt, .md, .json, .csv or .log (case-insensitive). Interior characters may be letters, digits, periods, underscores, spaces and hyphens. Windows device names, paths and alternate streams are rejected. Returns UTF-8 text, at most 1 MiB; invalid encoding and binary control characters are rejected. Empty files are supported. File formats are not parsed.

All file tools use the server-owned config/mcp-files.php directory (default storage/app/mcp-files). Clients cannot choose a root. Listing/search include only supported simple filenames and regular direct-child files; no recursion, dotfiles, links or unsupported names. A scan above 1,000 entries fails rather than returning an incomplete listing. Missing/disallowed roots and files return fixed errors without absolute paths. Symlinked roots and canonical path mismatches are rejected.

Reads use a bounded stream and compare descriptor identity with the checked file. These checks reduce races but cannot provide atomic portable no-follow access. Keep the root and its ancestors under trusted control; concurrent hostile filesystem writers and hard links are outside the threat model. See SECURITY.md.

