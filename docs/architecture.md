# Architecture

Bedriox/RakNet is the transport boundary beneath Bedriox/Protocol. It understands RakNet datagrams and reliability semantics, but it must not understand Minecraft Bedrock packets.

## Intended flow

```text
UDP datagram
  -> datagram size and source validation
  -> RakNet handshake or established session lookup
  -> sequence and reliability processing
  -> bounded fragment reassembly
  -> connected-control handshake and readiness gate
  -> application payload event

application payload
  -> reliability/ordering assignment
  -> fragmentation when required
  -> datagram scheduling
  -> UDP send
```

## Implemented components

- `DiscoveryServer`: non-blocking UDP I/O, discovery, negotiation, session tables, routing, and adapter queues.
- `ConnectedControlSession`: readiness handshake and transport-owned control messages.
- `ConnectedSession`: one peer's bounded connected transport state and effects.
- `Reliability`: sequence numbers, ordering channels, ACK/NACK ranges, sent history, and retransmission.
- `Session`: bounded split-packet reassembly and ordered delivery.
- `Clock`: monotonic, injectable time for deterministic tests.
- `TransportConfig` and limit values: immutable configuration for memory, rates, queues, and timeouts.

## Design rules

- Transport state is owned by one event loop; callbacks cannot mutate it concurrently.
- All queues, buffers, and fragment collections are bounded.
- Packet parsing fails closed and never performs application work before validation.
- Network time uses a monotonic clock.
- Public APIs expose payloads and transport events, not mutable internal session objects.
- No dependency may introduce Minecraft or Bedriox server-domain types.
- Performance changes require reproducible benchmarks and correctness tests.

`DiscoveryServer` accepts strictly framed discovery and offline-negotiation datagrams on a non-blocking IPv4 UDP socket. Unknown identifiers, invalid offline magic, wrong lengths, and malformed fields are discarded.

The discovery status body is a bounded opaque UTF-8 application payload. `DiscoveryStatus` validates only its 1-to-352-byte transport envelope and carries the generic decision to answer ping-open-connections. RakNet does not parse, sanitize, generate, or correlate application fields, and the server GUID remains a separate transport value.

```text
OpenConnectionRequest1 (0x05, observed endpoint)
  -> validate magic, protocol, zero padding, and probe length
  -> clamp 1492-byte probe to configured negotiated MTU (default 1400)
  -> store bounded, expiring pending handshake
  -> OpenConnectionReply1 (0x06)

OpenConnectionRequest2 (0x07, same observed endpoint)
  -> validate magic, IPv4 address shape, MTU, GUID, and pending state
  -> OpenConnectionReply2 (0x08) to observed UDP source
  -> only after successful send, publish immutable SessionInfo
```

Unsupported RakNet versions receive bounded `0x19`. The address embedded by Request 2 is decoded for structural validity but never controls response routing; the observed datagram source is authoritative. Pending handshakes are keyed by that source address and port, capped, and expired using an injectable monotonic `Clock`. Established records are independently capped.

Client GUIDs are unique across established endpoints through a bounded GUID-to-endpoint index. A collision from another endpoint is ignored. Request 1 from an established endpoint is answered idempotently using the existing MTU without allocating pending state; Request 2 with a replacement GUID is ignored until the owner explicitly removes the old session. `close()` clears pending state, sessions, and the GUID index.

Reply 2 allocates transport state but does not make the endpoint application-ready. A per-endpoint `ConnectedControlSession` then owns the bounded online phase:

```text
ConnectionRequest (0x09, reliable ordered)
  -> exact framing, offline GUID match, security=false
  -> ConnectionRequestAccepted (0x10, reliable ordered)
NewIncomingConnection (0x13, state-bound acknowledgement, reliable ordered channel 0)
  -> SessionOpenedEvent
  -> application payload delivery enabled
```

The final `0x13` body is deliberately opaque at the readiness boundary. Implementations disagree about the number of unused internal addresses and the platform-specific numeric value carried in IPv6 family fields. Bedriox/RakNet already owns the authoritative observed UDP endpoint, negotiated session, MTU bound, and reliability envelope, so no body field controls identity, routing, authorization, timing, or allocation. The packet identifier, pending control state, reliable-ordered channel-zero envelope, endpoint ownership, datagram bounds, and one-time readiness transition remain strict.

Connected ping `0x00` is consumed and answered with connected pong `0x03` in every live phase. Pong `0x03`, handshake replays, and disconnect notification `0x15` are also transport-owned and never reach the application. The online phase shares the configured monotonic handshake deadline. Timeout, malformed/out-of-state control input, queue exhaustion, explicit removal, remote disconnect, and socket failure release all endpoint-owned state.

The central dispatcher is the only path from datagram identifiers to handlers. `TransportConfig` provides immutable socket/state limits. After Reply 2 is written, `DiscoveryServer` owns one reliability session and one control session beside immutable `SessionInfo`. Exact ACK (`0xc0`) and NACK (`0xa0`) identifiers take priority; otherwise, all connected-data flag combinations accepted by `ConnectedDatagram::acceptsFlags()` route from that observed endpoint into the connected engine. The server exposes endpoint-keyed raw payload, lifecycle, and receive-drain APIs; it never interprets application bytes as Bedrock packets.

Each poll turn receives a bounded batch, transfers connected effects into globally bounded adapter queues, flushes adapter-owned complete datagrams, and advances sessions whose prior output is no longer blocked. Would-block retains the exact datagram and pauses further ticking for that endpoint. Partial or permanent socket-send failure clears and removes the engine, session metadata, GUID index, and pending output atomically.

`sessionCount()` retains its allocated-transport meaning, while `readySessionCount()` and `isSessionReady()` expose the readiness gate. Lifecycle events are fixed-size immutable values in a bounded queue. Normal queue exhaustion removes the responsible session and surfaces failure. A separate bounded, best-effort handshake-diagnostic queue reports allowlisted pre-ready metadata without consuming lifecycle capacity or retaining packet bodies. `close()` deliberately replaces undrained lifecycle events with one observable `SERVER_CLOSED` event per formerly ready session; repeated close is idempotent and draining events remains valid afterward.

Congestion control, IPv6 binding, and Bedrock protocol handling remain planned.
