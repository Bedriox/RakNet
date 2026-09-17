# Bedriox/RakNet contributor guide

## Purpose and boundary

This repository is the standalone RakNet transport layer maintained by the Bedriox team. It owns UDP I/O, RakNet discovery, offline negotiation, connection/session transport state, reliability, ordering, fragmentation, and retransmission. Congestion control remains future work.

It must not contain Minecraft Bedrock packet definitions, login/authentication, players, worlds, inventories, commands, plugins, or other game/server-domain types. Those belong in Bedriox/Protocol or the main Bedriox server. RakNet may expose validated application payloads and immutable transport events; it must not interpret them.

## Repository structure

- `src/Protocol/` contains bounded binary primitives and RakNet wire codecs.
- `src/DiscoveryServer.php` owns the current non-blocking IPv4 socket, central offline dispatcher, pending handshakes, and established session records.
- `src/TransportConfig.php` defines public resource and timing limits.
- `src/Clock.php` and `src/SystemClock.php` provide monotonic time; tests use an injected deterministic clock.
- `src/SessionInfo.php`, `src/PendingHandshake.php`, and `src/OfflineDatagramResult.php` are validated transport values.
- `src/Exception/` contains codec and transport failures.
- `src/Connected/`, `src/Reliability/`, and `src/Session/` own connected control, delivery/retry state, ordering, and bounded fragment reassembly.
- `tests/Protocol/` contains exact wire vectors and malformed-input coverage.
- `tests/Integration/` uses real loopback UDP sockets and independently constructed packets.
- `docs/` is the public architecture, usage, security, compatibility, testing, and troubleshooting contract.

## Architecture invariants

- One owning event loop mutates socket, handshake, and session state. Do not introduce concurrent mutation or callbacks that retain mutable internals.
- Every network-controlled length, count, index, queue, map, batch, timeout, retry, fragment set, and allocation has a hard upper bound validated before use.
- The observed UDP source address and port define peer identity. Never route a response or identify a session from an address embedded in a packet.
- Pending handshakes and sessions stay independently capped. Pending entries expire through the injected monotonic `Clock`; wall-clock time and arbitrary sleeps are not protocol state.
- A client GUID has at most one established endpoint. Maintain the bounded GUID-to-endpoint index; do not replace it with an attacker-controlled linear scan.
- Publish a session only after Reply 2 is sent successfully. Explicit removal and `close()` must release the session, pending entry, GUID ownership, and future transport resources.
- Malformed, unknown, out-of-state, duplicate, and stale input fails closed. Ignore traffic where the protocol requires silence; surface distinguishable local socket failures as `TransportException`.
- Wire integers and platform limits must be explicit. The current API intentionally restricts timestamps and GUIDs to nonnegative native 63-bit integers.
- Packet codecs must reject truncation, trailing bytes, wrong identifiers/magic, unsupported security modes, invalid addresses, and invalid MTUs. The server readiness handler intentionally treats the body of an in-state `NewIncomingConnection` (`0x13`) as opaque; do not reintroduce body parsing there without demonstrated consumer need and retail regression evidence.
- Keep the maximum parse datagram separate from the negotiated MTU ceiling: IPv4 probes through 1492 are accepted while the configured negotiated default is 1400.
- No performance claim is valid without a reproducible workload, hardware/runtime details, raw results, and correctness checks.

## Change safety and preservation

- State the exact transport behavior being changed and list protected discovery, negotiation, readiness, delivery, retry, cleanup, and shutdown paths before editing. Do not alter a working adjacent path without a demonstrated requirement.
- Capture the focused baseline first. Every reported timeout, join regression, dropped payload, status mismatch, or cleanup leak requires a deterministic regression test reproducing the defect.
- Discovery framing and byte retention are public wire contracts. RakNet must keep the application payload opaque; payload bytes, length bounds, server-GUID framing, and the open-connections response policy may change only with an explicit consumer requirement and independent exact-wire coverage.
- Never repair application behavior by teaching RakNet about Bedrock packets or gameplay. Never repair one peer by weakening endpoint identity, state order, bounds, or cleanup for all peers.
- Wire changes require independent literal vectors plus loopback tests. Reliability changes additionally require deterministic loss, duplication, reordering, retry, wraparound, and resource-exhaustion coverage as applicable.
- Preserve the connected-handshake boundary: `0x13` may open only an existing endpoint that is awaiting it, using reliable-ordered delivery on channel zero, and may publish readiness only once. Its unused body is never an identity, routing, authorization, timing, or allocation input.
- Before changing discovery, offline negotiation, connected-control envelopes, readiness, retransmission, MTU bounds, or endpoint identity, characterize the currently working PC, reconnect, and concurrent-client paths. Retail qualification must additionally exercise an iOS peer when the change touches connected admission.
- Run the owning gate and, for public-contract changes, Bedriox consumer tests and the workspace verifier. Record the prior component pin as the rollback point.
- Inspect the final diff and justify every changed file. Unrelated refactors, renames, defaults, status fields, and limit changes belong in separate reviewed changes.

Follow [`docs/change-safety.md`](docs/change-safety.md) and preserve the frozen [`docs/discovery-contract.md`](docs/discovery-contract.md).

## Security and licensing

Treat all datagrams as hostile. Preserve the amplification limits, fixed offline magic validation, bounded UTF-8 payload validation, endpoint-bound state, capacity controls, and safe logging rules in `docs/security.md`. New internet-facing behavior requires abuse analysis, rate/resource limits, malformed-input tests, and cleanup tests.

Original work is GPL-3.0-only. Do not copy or translate unlicensed, license-incompatible, decompiled, leaked, or rights-unclear implementations. Review the license of incorporated material and retain every legally required attribution in `THIRD_PARTY_NOTICES.md`.

## Development and verification

Use 64-bit PHP `^8.4`, Composer 2, and the `sockets` extension. From the repository root run:

```shell
composer install
composer check
composer validate --strict
composer audit --locked
```

`composer check` runs PHPUnit, maximum-level PHPStan, and PHP-CS-Fixer verification. Add unit tests for success and every invalid boundary. Wire changes need independent literal vectors and loopback integration tests that do not reuse the production encoder and decoder on both sides. Time-dependent state requires an injected clock. Network tests use ephemeral ports, deterministic deadlines, and cleanup in all outcomes. Re-run relevant loopback tests enough times to expose intermittent failures.

## Documentation and change discipline

Update public documentation and `CHANGELOG.md` in the same change as behavior. Keep `README.md`, `docs/usage.md`, and `docs/compatibility.md` factual about what works now. Update architecture for state ownership or dependency changes, security for trust/resource changes, testing for new qualification gates, third-party notices when legally required, and troubleshooting for operator-visible failures. Do not claim retail-client or production compatibility without its documented acceptance evidence.

Keep commits focused and reviewable. Follow `CONTRIBUTING.md`; commit messages must not contain personal email addresses or identity trailers. Do not commit generated caches, local logs, credentials, packet captures with personal/authentication data, or unrelated workspace changes.

## Definition of done

A change is complete only when its layer placement is correct; public inputs and constructed values are validated; resource ownership and all failure/cleanup paths are tested; required exact, negative, integration, and regression tests exist; `composer check`, strict Composer validation, and the locked audit pass; documentation, changelog, and legally required notices are current; and the diff contains only intentional, licensed work.
