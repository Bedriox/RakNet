# Usage

Bedriox/RakNet provides discovery, offline negotiation, and a bounded connected RakNet byte-payload transport. It does not decode Minecraft Bedrock packets.

## Configuration

```php
use Bedriox\RakNet\TransportConfig;

$config = new TransportConfig(
    bindAddress: '0.0.0.0',
    port: 19132,
    maximumTransmissionUnit: 1400,
    maximumSessions: 1024,
    maximumReceivedPayloads: 4096,
    maximumReceivedPayloadBytes: 2097152,
    maximumPendingOutboundDatagrams: 4096,
    maximumPendingOutboundBytes: 4194304,
    maximumSessionEvents: 65535,
    maximumHandshakeDiagnosticEvents: 1024,
);
```

Construction throws `InvalidArgumentException` when any endpoint, MTU, session, handshake, received-payload, pending-output, lifecycle, or diagnostic limit is outside its documented range. `maximumSessionEvents` must be at least `maximumSessions`, guaranteeing room for shutdown events. Handshake diagnostics use an independent bounded queue and never consume lifecycle capacity. The pending-output byte limit must hold at least one complete UDP payload for the configured MTU and can never be below 548 bytes. Port `0` requests an ephemeral operating-system-assigned port and is intended for tests.

## Discovery server

```php
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;

$guid = 8675309;
$status = new DiscoveryStatus(
    payload: 'application-owned bounded status',
    acceptingConnections: true,
);

$server = DiscoveryServer::bind($config, $guid, $status);
$server->poll(); // handles at most 64 waiting datagrams without blocking
$server->close();
```

Call `poll()` frequently from an owning event loop. Its optional batch limit is constrained to `1..1024`. It returns the number of received datagrams inspected, including malformed datagrams. Calling `close()` repeatedly is safe; polling after close throws `TransportException`.

`DiscoveryStatus` treats the application payload as opaque. It requires valid UTF-8 and between 1 and 352 bytes but does not parse, sanitize, infer, or generate application fields. The application protocol owns its delimiters, text policy, versions, capacity values, and other semantics.

Timestamps and locally generated server GUIDs are restricted to nonnegative PHP integers. Client GUIDs preserve the complete 64-bit wire pattern; GUIDs with bit 63 set appear as negative PHP integers. Use `updateDiscoveryStatus()` to atomically replace the opaque snapshot and its open-connections response policy; the separately bound server GUID remains unchanged.

`acceptingConnections` controls only whether ping-open-connections (`0x02`) receives a pong. Standard unconnected ping (`0x01`) continues to receive the current payload. The application must update this policy together with any capacity fields it encodes in its own payload.

## Offline negotiation result

The server supports RakNet protocol 11 by default. Override it with the fourth `DiscoveryServer::bind()` argument. Request 1 accepts IPv4 path-MTU probes through 1492 bytes and clamps the negotiated value to `TransportConfig::$maximumTransmissionUnit` (1400 by default). Request 2 must come from the same observed address and port before the configurable handshake timeout.

```php
$session = $server->sessionFor('127.0.0.1', 50000);
if ($session !== null) {
    echo $session->clientGuid;
    echo $session->mtu;
    echo $session->rakNetProtocolVersion;
}
```

`SessionInfo` and its connected engine become visible only after Reply 2 is successfully written. This is allocated transport state, not application readiness. The embedded Request 2 address is never used to identify a session or route replies.

After Reply 2, the transport consumes `ConnectionRequest`, emits `ConnectionRequestAccepted`, and waits for `NewIncomingConnection` under the same bounded monotonic timeout. Use readiness and lifecycle APIs to attach application protocol state:

```php
foreach ($server->drainSessionEvents() as $event) {
    if ($event instanceof \Bedriox\RakNet\SessionOpenedEvent) {
        // Create the application protocol session for $event->session.
    } else {
        // Release it; inspect $event->reason for the stable close reason.
    }
}
```

`sessionCount()` counts allocated Reply-2 sessions. `readySessionCount()` and `isSessionReady($address, $port)` count or query sessions that completed connected control. The event queue is bounded; drain it every loop turn.

The final `NewIncomingConnection` body is intentionally opaque because its unused address table and timing representation vary between deployed clients. The observed endpoint, pending state, packet identifier, reliable-ordered channel-zero envelope, negotiated MTU, and one-time readiness transition remain authoritative.

Drain safe pre-ready rejection metadata independently from lifecycle events:

```php
$batch = $server->drainHandshakeDiagnostics();
foreach ($batch->events as $event) {
    echo $event->remoteAddress . ':' . $event->remotePort;
    echo $event->stage->value . ' ' . $event->reason->value;
}
echo $batch->droppedEventCount;
```

Diagnostic events contain only the observed endpoint, stage, stable reason, optional packet identifiers, payload length, reliability, and ordering channel. They never include packet bodies, GUIDs, embedded addresses, tokens, or exception messages. When the diagnostic queue fills, additional events are counted and dropped without changing transport behavior; draining resets both the queue and dropped count.

## Connected payloads

Only the observed address and port from negotiation identify a connected endpoint. Application sends before readiness fail. Once a `SessionOpenedEvent` arrives, queue application bytes for modes 0 (unreliable), 2 (reliable), or 3 (reliable ordered), then continue polling:

```php
use Bedriox\RakNet\Protocol\Reliability;

$server->sendPayload('127.0.0.1', 50000, 'transport bytes', Reliability::ReliableOrdered, 0);
$server->poll();

foreach ($server->drainReceivedPayloads() as $received) {
    echo $received->remoteAddress . ':' . $received->remotePort;
    echo $received->payload;
}
```

`ReceivedPayload` is immutable and records the observed endpoint, raw bytes, reliability mode, and ordering channel. Drain received effects regularly. Their global count and byte limits are configured through `TransportConfig`; exhaustion removes the responsible endpoint rather than growing memory without bound. Unknown endpoints and unsupported connected identifiers are ignored.

Each `poll()` also ticks connected engines and sends their ACKs, NACKs, new data, and retries. Whole datagrams transferred by the engine are retained unchanged when a non-blocking socket reports would-block. A partial UDP write or permanent send error atomically clears and removes that endpoint before surfacing `TransportException`.

Explicit operator removal is available when needed:

```php
$removed = $server->removeSession('127.0.0.1', 50000);
```

Remote disconnect and connected-handshake timeout clean up automatically. Removal is idempotent, recovers session capacity, clears any pending state for that endpoint, emits `LOCAL_REMOVAL` for a ready session, and permits its client GUID to be reused by another endpoint. Closing clears transport state and replaces undrained lifecycle events with bounded `SERVER_CLOSED` events; these remain drainable after close.
