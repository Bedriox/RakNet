# Codebase map

Bedriox/RakNet owns transport bytes and lifecycle only. It exposes validated application payloads but never interprets Bedrock packet IDs.

| Area | Ownership |
|---|---|
| `DiscoveryServer.php` | UDP socket, central dispatch, discovery, offline negotiation, endpoint tables, adapter queues, polling, and shutdown. |
| `DiscoveryStatus.php` | Bounded opaque UTF-8 discovery payload and generic open-connections response policy. |
| `TransportConfig.php` | Socket, MTU, capacity, queue, timing, and work limits. |
| `Protocol/` | RakNet wire codecs, addresses, datagrams, ACK/NACK, frames, and control packets. |
| `Connected/` | Readiness handshake and per-peer connected-session state/effects. |
| `Reliability/` | Sequence windows, acknowledgement accumulation, reliable indexes, sent history, retry decisions, and RTO estimation. |
| `Session/` | Ordered delivery and bounded split-packet reassembly. |
| lifecycle/value files at `src/` | Immutable session information, received payloads, open/close events, clocks, and result values. |
| `tests/` | Exact vectors, state-machine tests, impaired-network simulation, real loopback integration, and regressions. |

Put RakNet framing or delivery here. Put Bedrock framing in Bedriox/Protocol and application policy in Bedriox. The observed UDP endpoint is always the routing identity.
