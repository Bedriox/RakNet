# Contributing

Thank you for helping the Bedriox team build Bedriox/RakNet.

## Before submitting

1. Read the [codebase map](docs/codebase-map.md), [discovery contract](docs/discovery-contract.md), and [change-safety guide](docs/change-safety.md).
2. Discuss substantial API or architecture changes before implementation.
3. Identify the owning state machine and record baseline tests plus the discovery, negotiation, delivery, and cleanup behavior that must remain unchanged.
4. Keep RakNet transport independent of Minecraft packets and server-domain logic.
5. Make the smallest necessary change. Do not combine feature work with unrelated defaults, refactors, opaque status framing, response-policy changes, or limit changes.
6. Add exact independent wire vectors, malformed/boundary tests, deterministic state tests, and a regression reproducing the motivating defect.
7. Run `composer check`, `composer validate --strict`, and `composer audit --locked`. Run Bedriox consumer and workspace checks for public-contract changes.
8. Update documentation and `CHANGELOG.md`, disclose external sources, review the final diff, and describe compatibility risk and rollback.
9. Use focused commit messages that describe the behavior changed without embedding personal email addresses.

Pull requests should explain the objective, changed ownership boundary, protected behavior, baseline, regression evidence, risks, validation performed, compatibility effects, and rollback point. Required reviews and CI checks must pass before merge. Bedriox maintainers may decline changes that expand scope, weaken safety limits, or lack reproducible tests.

By contributing, you agree that your contribution is licensed under GPL-3.0-only.
