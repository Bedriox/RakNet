# Changelog

All notable changes to Bedriox/RakNet are documented here. The project follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and Semantic Versioning.

## [Unreleased]

### Added

- Established-session idle expiry with periodic connected pings; inactive peers no longer retain a player identity indefinitely.

### Fixed

- Isolate connected-session tick failures to the affected endpoint so one
  saturated or invalid session cannot stop the shared transport server.
- Kept the shared Windows UDP listener alive when a late response to a closed peer reports `WSAECONNRESET`.

## [0.1.0-alpha.1] - 2026-09-17

### Added

- Initial Bedriox/RakNet PHP 8.4 library release.
- Bounded IPv4 UDP discovery and RakNet protocol 11 offline negotiation.
- Connected-session reliability, ordering, fragmentation, ACK/NACK, retransmission, and lifecycle handling.
- Exact wire-codec, malformed-input, loopback, impairment, and resource-limit test coverage.

[Unreleased]: https://github.com/Bedriox/RakNet/compare/v0.1.0-alpha.1...HEAD
[0.1.0-alpha.1]: https://github.com/Bedriox/RakNet/releases/tag/v0.1.0-alpha.1
