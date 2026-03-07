# Security Notes

- Do not commit secrets (API keys, passwords, tokens) to tracked files.
- Keep sensitive values in `src/config.local.php` (gitignored) or environment variables.
- Do not include local usernames, hostnames, internal paths, or private network details in code comments or documentation.
- Review diffs before commit to catch accidental exposure.
