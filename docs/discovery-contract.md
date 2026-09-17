# Discovery contract

Discovery is a compatibility-critical public wire contract. A valid unconnected ping receives a bounded pong containing the standard RakNet magic, server GUID, echoed timestamp, and a length-prefixed opaque application status:

```text
packet-id | timestamp | server-guid | offline-magic | payload-length | application-payload
```

`DiscoveryStatus` requires valid UTF-8 and 1 through 352 bytes, then retains the payload exactly. RakNet does not parse fields, delimiters, capacity, versions, ports, game modes, or client compatibility. Those semantics and any required sanitation belong to the application protocol. The server GUID is independently bound transport state and is not extracted from or compared with the payload.

Standard ping (`0x01`) always receives the current status. Ping-open-connections (`0x02`) receives it only when `acceptingConnections` is true. Applications must update that policy consistently with any capacity data they encode.

Any change to field order, delimiters, defaults, numeric representation, version text, capacity behavior, GUID, or advertised ports requires:

- an independently composed exact pong vector;
- an assertion that the opaque application payload is retained byte-for-byte;
- both `0x01` and capacity-dependent `0x02` loopback coverage;
- a Bedriox consumer test using its real status composition;
- a retail discovery/join smoke test when advertised output changes;
- documented compatibility reason and rollback to the prior component pin.

Unrelated transport work must not modify discovery output. Application field semantics belong outside RakNet, but a transport change that alters previously supplied payload bytes or makes a known client stop before Request 1 is a release-blocking regression even when the pong is structurally valid.
