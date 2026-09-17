# Change safety

Before editing, identify the transport state and wire identifiers involved, record focused baseline results, and list protected paths: discovery, Request 1/2, online control, readiness, application delivery, ACK/NACK, retry, fragmentation, removal, and shutdown.

Make the smallest change in the owning class. Add a deterministic regression for the original failure. Use independent bytes for wire tests, an injected clock for deadlines, the impairment harness for delivery behavior, and real ephemeral loopback sockets for integration. Never replace deterministic assertions with sleeps.

Run:

```shell
composer check
composer validate --strict
composer audit --locked
```

For changed public behavior, also run affected Bedriox consumer tests and the workspace verifier. Review the final diff for accidental status/default/limit changes, update documentation and legally required notices, and record the prior Bedriox/RakNet pin as the rollback point. A change is not complete if a working client journey regresses, even when local unit tests pass.
