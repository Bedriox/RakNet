# Bedriox/RakNet

Bedriox/RakNet is a standalone RakNet transport library written in modern PHP for Bedriox and other PHP applications. It is developed and maintained by the [Bedriox](https://bedriox.com) team with performance, reliability, security, and testability in mind.

> **Project status:** Early development. IPv4 discovery, offline and connected-control negotiation, and a bounded connected transport for raw application byte payloads are implemented. Bedrock packet decoding is not. Do not use this package in production.

## Purpose

This repository owns UDP discovery, connection negotiation, reliable and ordered delivery, ACK/NACK processing, fragmentation, reassembly, retransmission, and transport lifecycle events. It deliberately contains no Minecraft packets, player state, world logic, or game behavior.

## Requirements

- 64-bit PHP `^8.4` (PHP 8.4 or later within the PHP 8 major line)
- PHP `sockets` extension
- Composer 2
- Windows x86-64 or Linux x86-64

## Install for development

```shell
composer install
composer check
```

The package is not published on Packagist during private development. Bedriox consumes it as a Composer VCS repository.

## Smallest example

The current scaffold provides a validated transport configuration object:

```php
use Bedriox\RakNet\TransportConfig;

$config = new TransportConfig(port: 19132, maximumSessions: 512);
```

Established sessions send a connected ping every five seconds and expire after 30 seconds without inbound activity. `sessionPingIntervalMilliseconds` and `sessionIdleTimeoutMilliseconds` on `TransportConfig` can be adjusted for a deployment's network conditions.

Run a non-blocking discovery responder from an existing event loop:

```php
use Bedriox\RakNet\DiscoveryServer;
use Bedriox\RakNet\DiscoveryStatus;
use Bedriox\RakNet\TransportConfig;

$guid = 8675309;
$status = new DiscoveryStatus('application-owned bounded status');
$server = DiscoveryServer::bind(new TransportConfig(port: 19132), $guid, $status);

while (true) {
    $server->poll();
    // Perform other event-loop work here.
}
```

The status payload is opaque to RakNet. Applications own its format and semantics and must encode it before constructing `DiscoveryStatus`. `poll()` handles RakNet protocol 11 offline and connected-control negotiation, routes ready endpoint traffic, advances retries, and flushes complete UDP datagrams. Applications must drain lifecycle events and only attach protocol state after `SessionOpenedEvent`.

## Documentation

- [Architecture](docs/architecture.md)
- [Codebase map](docs/codebase-map.md)
- [Discovery contract](docs/discovery-contract.md)
- [Change safety](docs/change-safety.md)
- [Reliability model](docs/reliability.md)
- [Development](docs/development.md)
- [Usage](docs/usage.md)
- [Testing](docs/testing.md)
- [Security](docs/security.md)
- [Compatibility](docs/compatibility.md)
- [Troubleshooting](docs/troubleshooting.md)

See [CONTRIBUTING.md](CONTRIBUTING.md) before proposing a change and [SECURITY.md](SECURITY.md) for private vulnerability reporting guidance.

## License and trademarks

Original source code is licensed under the [GNU General Public License v3.0 only](LICENSE). Required third-party legal notices are documented in [THIRD_PARTY_NOTICES.md](THIRD_PARTY_NOTICES.md).

Bedriox is an independent project and is not affiliated with or endorsed by Mojang Studios or Microsoft. Minecraft is a trademark of Microsoft Corporation. The Bedriox name and marks are not granted by the GPL.
