# Troubleshooting

## Composer rejects the PHP version

Use 64-bit PHP `^8.4` (PHP 8.4 or later within PHP 8) and confirm the CLI invoked by Composer with `php --version`.

## The sockets extension is missing

Run `php --ri sockets`. Enable the extension in the CLI `php.ini`, then rerun `composer install`.

## Tests cannot find classes

Run `composer install` or `composer dump-autoload` from the repository root.

## Style or analysis fails

Run the failing command shown by `composer check`. PHP-CS-Fixer reports required formatting without modifying files; run `vendor/bin/php-cs-fixer fix` locally, review the diff, and rerun the checks.

## A client cannot connect

Inspect `sessionCount()` separately from `readySessionCount()`. A nonzero session count with zero ready sessions means offline negotiation completed but the connected `ConnectionRequest`/`NewIncomingConnection` exchange did not finish before the configured handshake timeout. Confirm the client uses RakNet protocol 11, repeats its offline GUID, and requests `security=false`.

Drain `drainHandshakeDiagnostics()` every event-loop turn when investigating admission. Its bounded events identify the observed endpoint, stage, stable rejection reason, packet identifiers, payload length, reliability, and ordering channel without retaining packet bodies or authentication data. A nonzero `droppedEventCount` means the diagnostic queue saturated; it does not mean lifecycle events or transport behavior were dropped.

If discovery itself fails, verify UDP firewall rules, ensure the configured address is local, and confirm no other process owns the port. Port `0` chooses an ephemeral port; query `DiscoveryServer::localPort()` to learn it.

Application sends are intentionally rejected until `SessionOpenedEvent`. Drain lifecycle events on every event-loop turn. If the lifecycle queue fills, the responsible session is removed and a transport failure is surfaced; increase the bounded limit only after confirming the consumer drains promptly.
