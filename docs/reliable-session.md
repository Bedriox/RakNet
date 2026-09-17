# Reliable session state primitives

The `Bedriox\RakNet\Reliability` namespace provides deterministic state components used by the connected-session engine. These components do not open sockets or interpret Bedrock data.

## Connected-session API

`Bedriox\RakNet\Connected\ConnectedSession` is a single-owner state machine. It accepts validated `ConnectedDatagram`, `AckPacket`, and `NackPacket` values through `receive()`, or classifies their encoded forms through `receiveBytes()`. Applications queue nonempty byte payloads with `queuePayload()`, advance timers and output with `tick()`, and consume immutable payload, datagram, and expiry effects with `drainEffects()`. `clear()` releases all retained state and permanently closes the instance.

Draining effects transfers ownership of each complete outbound UDP datagram to the socket adapter. The engine records the transmission at that handoff boundary. An adapter must retain an unchanged datagram across a transient would-block or equivalent send failure and retry it; it must never discard it and ask the engine to reconstruct it. UDP partial writes and permanent socket failures are fatal and require closing the connected session. This ownership rule prevents socket backpressure from creating false acknowledgements or losing a reliable retry claim.

The initial send and receive surface supports RakNet reliability modes 0 (unreliable), 2 (reliable), and 3 (reliable ordered). Sequenced and ACK-receipt modes are rejected instead of being silently mapped to a different semantic. The negotiated MTU is an IPv4 IP-packet MTU; the engine subtracts the 20-byte IPv4 and 8-byte UDP headers before forming connected datagrams.

Reliable and reliable-ordered application payloads are split when one encapsulated frame cannot fit the UDP payload budget. Each fragment receives a distinct reliable index, while ordered fragments retain one logical ordering index. Unreliable payloads that do not fit one datagram are rejected atomically: without retransmission, a fragment lost to output or network backpressure could never complete safely. Ordinary unsplit unreliable payloads remain queued under bounded engine backpressure until their effect can be transferred. The engine retains the exact immutable `EncapsulatedFrame` envelope, including mode, bit payload, ordering metadata, and split metadata, for every pending reliable index so retransmission changes only the enclosing datagram sequence.

The current `FragmentReassembler` exposes complete assemblies as byte strings. Consequently, the connected adapter deliberately accepts only byte-aligned fragment payloads and pins reliability, ordering, split count, and expiry metadata separately for the life of an assembly. Arbitrary final-bit-length fragmentation is not supported by this milestone.

## Sequence domains

Datagram sequences, reliable message indexes, ordering indexes, and sequencing indexes occupy independent 24-bit serial-number domains. `Sequence24` validates scalar integers and applies modular comparisons. A forward distance of exactly half the sequence space is ambiguous and is rejected by receive windows. Configured windows must remain below half the sequence space.

`ReceiveSequenceWindow` accepts new and bounded out-of-order datagram sequences, rejects duplicates and stale values, and reports bounded gaps for NACK scheduling. A forward jump at least as large as the configured window is rejected before building a gap list. `ReliableMessageWindow` owns a separate instance for at-most-once reliable-message delivery.

## ACK and NACK state

`AcknowledgementAccumulator` stores unique ACK and NACK sequences under one hard count limit. ACK takes precedence and cancels a pending NACK for the same sequence. Drain operations sort and compact consecutive values into inclusive `SequenceRange` values. Ranges on opposite sides of the numeric wrap point remain separate so a later wire codec never needs an ambiguous wrapping range.

The connected layer adds accepted datagrams to ACK state and their reported gaps to NACK state. Arrival of a formerly missing sequence acknowledges it, removing its pending NACK. Drained numeric ranges are mapped to wire ranges and divided across as many packets as necessary, respecting both the negotiated UDP budget and the codec's 212-record bound. A conservative capacity check happens before either accumulator is drained, so output backpressure cannot destructively lose ACK/NACK state.

## Canonical reliable frames and sent history

`ReliableFrameTracker` owns one `CanonicalReliableFrame` per pending reliable index. Its payload and identity do not change across retransmissions. `SentDatagramHistory` stores only datagram metadata and reliable-index references; it never duplicates payload strings. Count, reference-count, frame-count, and payload-byte limits are checked atomically before state changes.

An ACK removes every acknowledged canonical frame and all stale history references to it. Duplicate or unknown ACKs are no-ops. A NACK consumes the matching history entry and makes each still-pending frame immediately eligible for one retry claim. Repeated NACKs and repeated retry collection cannot create duplicate queued work.

## Timers and retry policy

All time comes from the injected monotonic `Clock` in integer nanoseconds. Clock regression is a local programming error. `RtoEstimator` maintains integer smoothed RTT and variance, clamps the RTO between configured bounds, and provides capped exponential backoff.

Round-trip samples are accepted only from a datagram containing exclusively first transmissions. Once any referenced frame has been retransmitted, ACK timing is excluded under Karn's rule. A transmission attempt schedules its next retry using the current RTO and its attempt number. Frames expire deterministically when their maximum age is reached or when the retry deadline arrives after the maximum permitted attempt.

`collectDueRetries()` claims each due frame once. `ConnectedSession` retains each claim in its bounded retry queue until it can encode and expose the retransmission, then records the new enclosing datagram. Output backpressure therefore cannot strand a claimed retry.

## Integration order

1. Decode and validate a connected datagram within negotiated MTU limits.
2. Observe its datagram sequence in `ReceiveSequenceWindow`.
3. Accumulate its ACK and bounded missing-sequence NACK state.
4. For each reliable frame, observe its independent reliable index before delivery or later reassembly.
5. On outbound send, track each canonical reliable frame once, then record the datagram sequence and referenced indexes.
6. Route decoded ACK/NACK ranges only to currently bounded sent-history entries; never allocate by peer-provided range span.
7. Advance retry and expiry state from the event loop using `collectDueRetries()`.

`DiscoveryServer` provides the current socket/session adapter and follows the effect-ownership contract above. This milestone emits one encapsulated frame per connected datagram; later coalescing can improve efficiency without changing delivery semantics.

## Fixed send window

This milestone uses a conservative fixed reliable send window rather than adaptive congestion control. `maximumReliableDatagramsInFlight` caps sent reliable datagrams awaiting acknowledgement (64 by default); queued reliable frames resume only after an ACK releases history capacity. A NACK or RTO retry atomically supersedes its older history record, so retrying at a full window never increases the in-flight count. `maximumDatagramsPerTick` separately caps all new and retransmitted data datagrams emitted during one `tick()` call (256 by default). Retries consume this budget and run before new data. ACK/NACK control packets do not consume it and are flushed first, preventing a large application queue from starving transport feedback. Adaptive AIMD, pacing, and bandwidth estimation remain future work.

## Security invariants

- No receive jump allocates more entries than the configured receive window.
- No ACK/NACK insertion exceeds its combined count limit.
- No peer range should be expanded before intersecting it with bounded local history.
- No retransmission creates another canonical payload copy in tracking state.
- ACK/NACK processing is idempotent and cannot introduce new frames.
- Failed capacity checks leave frame attempts, byte accounting, and history unchanged.
- Admission that cannot retain required ACK, fragment, ordering, or delivery state fails closed; it never ACKs and deduplicates a reliable frame that was silently discarded.
- Split IDs remain leased while reliable fragments are pending and for a bounded cooldown afterward. Outbound unreliable fragmentation is intentionally unsupported.
- Retry attempts, frame age, history entries, history references, frame count, and payload bytes all have hard limits.
- Removing or expiring a frame releases payload bytes and every history reference.
- Sequence arithmetic never uses ordinary signed ordering across wrap.
- Application callbacks must run after the owning session finishes state mutation.
