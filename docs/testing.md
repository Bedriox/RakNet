# Testing

Run the entire local quality gate with:

```shell
composer check
```

Individual commands are:

```shell
composer test
composer analyse
composer style
```

## Test layers

- Unit tests cover codecs, range arithmetic, session transitions, limits, and cleanup.
- Property tests will cover round-trip codecs and sequence-number boundaries.
- Seeded virtual-network tests will model loss, duplication, reordering, delay, and jitter using a fake monotonic clock.
- Fuzz tests will exercise truncated and malformed datagrams, oversized ranges, and fragment exhaustion.
- Black-box tests will use an independently encoded client so server and test code cannot share the same defect.
- Soak and load tests will validate stable memory, queues, retransmission behavior, and latency.

The discovery integration suite binds the responder to IPv4 loopback on an ephemeral port. It verifies malformed traffic is ignored, `0x01` and policy-eligible `0x02` queries receive pongs, disabled open-connections policy ignores `0x02`, bind-failure cleanup, opaque status updates, clean/idempotent close behavior, and 100 sequential ping/pong exchanges with exact timestamp echoing. Independent raw tests construct and inspect UDP bytes without production discovery codecs, pin the previously working application payload byte-for-byte, and prove that a 352-byte opaque payload produces the unchanged 387-byte maximum response.

Offline-negotiation tests include independent exact vectors for `0x05`, `0x06`, `0x07`, `0x08`, and `0x19`; invalid magic/length/padding/address/security cases; Request 2 before Request 1; endpoint-authoritative reply routing; deterministic handshake expiration via an injected clock; pending-capacity enforcement; retransmitted Request 2; unsupported protocol behavior; and a raw 1492-byte path-MTU probe clamped to 1400.

Negative codec coverage truncates every supported offline packet at every byte boundary and checks wrong identifiers/magic, first/middle/last padding corruption, invalid MTUs, security flags, and IPv4 edge vectors. Exact vectors and a complete online loopback handshake cover client GUIDs with bit 63 set. Lifecycle integration covers session-capacity recovery, GUID collision rejection, explicit removal and GUID reuse, established-endpoint Request 1 replay without pending allocation, replacement-GUID rejection, and complete close cleanup.

Connected transport uses both a seeded in-memory impairment harness and real IPv4 loopback sockets. The simulator covers loss, duplication, delay, reordering, exactly-once reliable delivery, ordered-channel monotonicity, fragmentation, ACK/NACK recovery, retry exhaustion, backpressure, resource limits, and isolation between session pairs. Socket tests independently construct the full online handshake and application vectors, including both reliable and reliable-ordered Connection Request envelopes observed across implementations. Final-acknowledgement coverage includes identifier-only, 10-address-shaped, 20-address-shaped, platform-family, truncated-body, duplicate, wrong-state, wrong-reliability, wrong-channel, and unknown-endpoint cases while preserving MTU bounds. The suite also covers readiness gating, connected ping/pong, request replay, lost-acceptance retransmission, malformed and empty payload cleanup, control timeout, bounded diagnostic saturation, peer isolation, immutable open/close events, lifecycle exhaustion, endpoint routing, reliable egress with client ACK, duplicate suppression, removal and shutdown cleanup, negotiated-MTU enforcement, fragmentation, and global receive-effect exhaustion.

Every reported crash or protocol defect must receive a regression test. Tests that depend on arbitrary `sleep()` timing are not accepted when an injectable clock can make the behavior deterministic.

CI runs supported checks on Windows and Linux. Network integration and longer fuzz/load suites will be added as their corresponding features land.
