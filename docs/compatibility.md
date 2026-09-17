# Compatibility

## Current foundation target

| Component | Support |
|---|---|
| PHP | `^8.4`, 64-bit (PHP 8.4 or later within PHP 8) |
| PHP extension | `sockets` |
| Operating systems | Windows x86-64, Linux x86-64 |
| Composer | 2.x |
| RakNet wire compatibility | Protocol 11 IPv4 discovery, offline and online control negotiation with state-bound opaque final acknowledgement bodies, and bounded connected modes 0, 2, and 3 |
| Application discovery payload | Opaque valid UTF-8, 1 through 352 bytes |
| Minecraft Bedrock versions | No Bedrock advertisement, login, or game-packet semantics |

The discovery codec uses big-endian 64-bit fields, the standard 16-byte RakNet offline magic, and a big-endian unsigned-short-prefixed status string. Timestamps and locally generated server GUIDs use nonnegative PHP integers. Client GUIDs preserve all 64 wire bits: values with bit 63 set are exposed as negative PHP integers with the identical two's-complement bit pattern.

Standard unconnected ping (`0x01`) receives a status pong containing the current opaque application payload. Ping-open-connections (`0x02`) receives the same bounded pong only while the application's explicit `acceptingConnections` policy is true. RakNet does not interpret capacity or any other field embedded in the payload. Offline `0x05`/`0x06`/`0x07`/`0x08` negotiation and incompatible-version `0x19` are implemented without RakNet security cookies. Connected control implements `0x00`, `0x03`, `0x09`, `0x10`, `0x13`, and `0x15`. The final `0x13` body is opaque after strict endpoint, state, reliability, ordering, and datagram-bound checks, allowing deployed 10- and 20-address variants without trusting either form. Only after online completion do endpoints support application payloads over connected-data flags, ACK (`0xc0`), and NACK (`0xa0`) for unreliable, reliable, and reliable-ordered modes. Sequenced and ACK-receipt modes, congestion control, established idle timeout, IPv6 socket binding, Bedrock packets, and broad retail-client compatibility claims are not implemented.

A Bedriox release will pin an exact compatible Bedriox/RakNet version through Composer and its compatibility manifest.

Linux ARM64 is planned after the initial Windows/Linux x86-64 target is stable.
