# Reliability Model

This document records the transport invariants that implementation and tests must preserve. The bounded wire-value and packet codecs described below are implemented. Stateful acknowledgement, retransmission, ordering, and delivery processing remain outside this milestone.

## Implemented wire layer

The protocol layer provides unsigned 24-bit little-endian sequence encoding, wrap-safe sequence comparison, reliability modes `0` through `7`, exact-bit-length payloads, split metadata, encapsulated frames, connected datagrams, inclusive sequence ranges, and ACK/NACK packets. These are representation and validation types only: decoding a packet never creates or mutates a session.

Canonical encoders write RakNet's mixed-endian framing: sequence and message indexes are 24-bit little-endian, while payload bit lengths, split IDs, split counts/indexes, and ACK/NACK record counts use their documented network byte order. Decoders require complete fields and reject trailing bytes where the packet has an explicit end.

Hard limits currently enforced at this boundary are:

- 1,492 bytes per connected or acknowledgement datagram and 11,936 payload bits per frame value;
- 256 frames per connected datagram and 32 ordering channels (`0` through `31`);
- 1,024 fragments per split payload, with an index strictly below its declared count;
- 212 ACK/NACK records, 8,192 represented sequences per packet, and 8,192 sequences per inclusive range.

Non-canonical unused payload bits, reserved frame flags, invalid reliability metadata combinations, descending acknowledgement ranges, and out-of-range indexes are rejected with `CodecException`. Ranges do not wrap; rollover is handled with `SequenceMath` before ranges are constructed.

## Datagram sequence flow

```text
sender                                      receiver
  |--- datagram sequence N ------------------->|
  |--- datagram sequence N+2 ----------------->| detect N+1 gap
  |<--------------------- ACK N, N+2 ----------|
  |<--------------------- NACK N+1 ------------|
  |--- retransmitted datagram N+1 ------------>|
  |<--------------------- ACK N+1 -------------|
```

Sequence arithmetic must use the RakNet sequence width and compare values correctly across wraparound. Duplicate or stale datagrams are acknowledged when appropriate but never delivered twice.

## ACK and NACK behavior

- Received datagram sequences are accumulated into compact inclusive ranges.
- Ranges are validated before expansion; malformed, reversed, or excessive ranges are rejected.
- An ACK removes acknowledged datagrams from retransmission tracking and contributes to round-trip estimates.
- A NACK makes an eligible datagram available for prompt retransmission without creating duplicate tracking entries.
- Retransmissions are bounded by time, attempt count, session lifetime, and memory limits.
- ACK/NACK batches are size-bounded and emitted on deterministic clock-driven deadlines.

## Ordering and reliability

The future public send API will require an explicit reliability mode. Reliable payloads receive message indexes. Ordered and sequenced payloads additionally carry per-channel ordering indexes. An ordered payload is delivered only after all earlier payloads on that channel; traffic on one channel must not corrupt another channel's ordering state.

Unreliable payloads are never placed in retransmission history. Reliable payloads are delivered at most once to the application even if their containing datagram is duplicated.

## Fragment lifecycle

```text
payload larger than MTU
  -> assign split identifier
  -> divide into bounded fragment count
  -> transmit fragments under selected reliability mode
  -> collect by session + split identifier
  -> validate consistent count and metadata
  -> assemble only when every unique part exists
  -> emit one payload and discard fragment state
```

Each session has hard limits for active split identifiers, fragments per payload, aggregate reassembly bytes, and lifetime. Conflicting metadata invalidates the affected split. Duplicate parts do not increase memory accounting. Disconnect and timeout discard all associated fragments.

## Required deterministic tests

- Missing sequence followed by ACK/NACK recovery.
- Duplicate, reordered, delayed, and stale datagrams.
- Sequence, reliable-message, and ordering-index wraparound.
- ACK/NACK range compaction and malformed-range rejection.
- Retransmission timeout and maximum-attempt cleanup.
- Interleaved ordered channels.
- Duplicate, missing, inconsistent, oversized, and expired fragments.
- Session teardown with queued sends and active fragment assemblies.
