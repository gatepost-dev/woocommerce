# AGENTS.md

This repo is part of Gatepost, unofficial open-source developer tools for Nigeria's National Digital Postcode. This repo holds the WordPress plugin "Gatepost Postcode for WooCommerce". It checks the postcode of a Nigerian address in the classic checkout and in the checkout block.

## Read first

- `CODING_STANDARDS.md` and the two files it names hold the rules. Cite rule IDs, such as `WP-3`, in reviews and commit bodies.
- `CONTEXT.md` is the glossary. Name things with its terms in code, tests, docs and commits.
- `docs/adr/` holds the decisions. When a change contradicts an ADR, say so in the pull request.
- `spec/` holds the grammar, the fixtures and the standards. The spec wins over code and over these docs.

## Rules that no config file shows

- If the task needs a hook, an option or a meta key that the design does not list, add nothing. Stop, and say that a design change must come first (CS-8).
- Call only the documented NIPOST gateway endpoints, and only through the bundled client. The tests answer every gateway request with `tests/Support/FakeGateway.php`.
- Commit only NIPOST's published test codes and synthetic values, such as `FC-01-Z99-ZZ-01`.
- A failed lookup never stops an order. It marks the order `error` and leaves an order note.
- Sign off each commit with `git commit -s`. The person who opens the pull request is the only author. Leave out co-author lines for AI tools.
- Change `main` only through pull requests. Never force-push to `main` or to other people's branches.
- Write prose in plain British English, with short sentences in the active voice.

## Done means all of these

1. A failing test came first, and it passes now.
2. `composer check` passes. It runs the formatter, linters, tests, coverage and `check-tells`.
3. Each user-visible change has a change file.
4. Each new domain term is in `CONTEXT.md`.
5. The diff touches only the lines that the task needs.
