# Security Model

Every datagram, address, timing value, and session transition is attacker-controlled input. The transport must remain available under malformed traffic and predictable resource pressure.

## Required controls

- Global and per-source discovery/handshake rate limits.
- Maximum datagram, session, queue, ACK range, ordering channel, fragment count, and reassembly sizes.
- Deadlines for every handshake and established-session state.
- Bounded retransmission attempts and history.
- Safe sequence-number arithmetic around wraparound.
- Duplicate and stale datagram rejection.
- Reassembly cleanup on timeout and disconnect.
- No secrets or full payloads in default logs.
- Structured logging resistant to control-character injection.

Defaults must be safe for an internet-facing host. Raising limits must be explicit. Parser errors close or discard the smallest affected scope without crashing the process.

The discovery slice reads at most the configured MTU per UDP datagram, accepts only the exact 33-byte shape for unconnected ping identifiers `0x01` and `0x02`, validates the 16-byte offline magic with `hash_equals()`, requires a valid UTF-8 status payload of 1 through 352 bytes, and bounds each `poll()` batch. The full pong is therefore at most 387 bytes. Identifier `0x02` receives no response when the application's explicit `acceptingConnections` policy is false.

Discovery is an unauthenticated UDP amplification surface: the maximum response is larger than its 33-byte request. Unknown, policy-disabled `0x02`, bad-magic, and malformed datagrams receive no response, and status size is deliberately kept below common MTUs and below 400 total response bytes. A bounded admission guard applies global byte/datagram budgets, unauthenticated per-address budgets, handshake budgets, malformed-datagram escalation, and temporary address blocks before a packet reaches protocol decoding.

Offline connection requests are also unauthenticated. Pending state is keyed by the observed source endpoint, not the client-supplied address, with configurable capacity and monotonic expiration. Request 2 cannot raise the MTU negotiated by Request 1. Established session records are capped independently and are published only after Reply 2 is sent. Rate limits and blocks are intentionally bounded and expire using the monotonic clock; source validation still comes from the observed endpoint and the ordered handshake rather than trusting packet-embedded addresses.

Connected sessions receive independent per-endpoint budgets so several legitimate clients behind one address do not consume a single shared connected allowance. Unauthenticated traffic remains address-scoped because it has no established endpoint identity. Repeated offenses inside the escalation window lengthen a block up to the configured maximum. Expired entries and excess tracking records are removed without unbounded memory growth.

A bounded GUID-to-endpoint index prevents duplicate client GUID ownership without an attacker-controlled linear session scan. Collisions and same-endpoint GUID replacement attempts receive no response. Explicit session removal and server close clear GUID ownership so capacity and identifiers can be safely reused.

Established traffic is accepted only from the observed negotiated endpoint and only for the connected, ACK, and NACK identifiers owned by the connected engine. Connected input is constrained by the negotiated IPv4 UDP payload budget. Received application effects and adapter-owned outbound datagrams have independent global count and byte limits in addition to per-session reliability, fragment, ordering, queue, and effect limits. Capacity failure clears the affected session instead of silently accepting data that cannot be retained.

Reply 2 does not authorize application traffic. Connected control requires an exact `0x09`, delivered either reliable or reliable-ordered on channel zero, carrying the GUID established by offline Request 2 and `security=false`. It must be followed by a reliable-ordered channel-zero `0x13` from the same observed endpoint while that control session is awaiting the final acknowledgement. The `0x13` body is intentionally ignored because its self-reported internal addresses and timing fields are unused and differ across clients; it never controls identity, routing, authorization, timing, or allocation. Datagram/MTU bounds, endpoint ownership, state order, reliability, ordering channel, and the one-time readiness transition remain enforced. Application bytes before readiness, contradictory replays, unsupported security, invalid handshake envelopes, and malformed connected framing fail closed. Connected-control state has a monotonic deadline and no unbounded retry or address collection.

Pre-ready diagnostics use a separate bounded, best-effort queue. Events contain only the observed endpoint and allowlisted structural metadata; they never retain payload bytes, GUIDs, embedded addresses, authentication material, or exception text. Saturation drops diagnostics rather than consuming lifecycle capacity or changing transport behavior.

Lifecycle events are immutable and fixed-size, and their queue is explicitly bounded. An opening transition cannot publish readiness if no lifecycle capacity remains. Close-event exhaustion still removes the endpoint before surfacing failure. Server shutdown clears earlier events and emits at most one `SERVER_CLOSED` event per ready session, which fits because event capacity cannot be configured below session capacity.

Draining a connected engine transfers ownership of complete datagrams to the socket adapter. Transient would-block retains the exact bytes and pauses further engine ticks for that endpoint. A partial write or permanent send failure clears the connected engine and every endpoint index before the error is surfaced, so transmission state is never left falsely healthy.

Report suspected vulnerabilities using the private process in [SECURITY.md](../SECURITY.md), not a public issue.
