# Development

## Setup

Install 64-bit PHP `^8.4` (PHP 8.4 or later within PHP 8), enable `sockets`, install Composer 2, then run:

```shell
composer install
composer check
```

## Layout

- `src/` contains production classes under `Bedriox\RakNet`.
- `tests/` mirrors production behavior under `Bedriox\RakNet\Tests`.
- `docs/` documents public behavior and engineering constraints.
- `.github/workflows/` contains continuous-integration policy.

## Engineering standards

- Every PHP file uses strict types.
- Public behavior has tests and documentation.
- Inputs from the network are untrusted.
- APIs prefer immutable values and explicit failures.
- Do not add Minecraft/game-server dependencies.
- Avoid wall-clock and real-network dependencies in unit tests.
- Run `composer check` before submitting changes.

Use focused commit messages without personal-email trailers. Major changes require an accepted RFC in the Bedriox/RFCs repository before implementation.
