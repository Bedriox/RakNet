<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class InternetAddress
{
    public const int IPV4_LENGTH = 7;
    public const int IPV6_LENGTH = 29;
    private const int IPV6_FAMILY = 23;

    public function __construct(
        public string $address,
        public int $port,
        public int $flowInfo = 0,
        public int $scopeId = 0,
    ) {
        $ipv4 = filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
        $ipv6 = filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
        if (!$ipv4 && !$ipv6) {
            throw new CodecException('Internet address must be valid IPv4 or IPv6.');
        }

        if ($this->port < 0 || $this->port > 65_535) {
            throw new CodecException('Internet address port is out of range.');
        }
        if ($this->flowInfo < 0 || $this->flowInfo > 0xffff_ffff || $this->scopeId < 0 || $this->scopeId > 0xffff_ffff) {
            throw new CodecException('IPv6 flow information or scope ID is out of range.');
        }
        if ($ipv4 && ($this->flowInfo !== 0 || $this->scopeId !== 0)) {
            throw new CodecException('IPv4 addresses cannot carry IPv6 metadata.');
        }
    }

    public static function decode(BinaryReader $reader): self
    {
        $version = $reader->readByte();
        if ($version === 4) {
            $octets = [];
            for ($index = 0; $index < 4; ++$index) {
                $octets[] = (~$reader->readByte()) & 0xff;
            }

            return new self(implode('.', $octets), $reader->readUnsignedShort());
        }
        if ($version !== 6) {
            throw new CodecException('RakNet internet address has an unsupported IP version.');
        }

        /** @var array{value: int} $family */
        $family = unpack('vvalue', $reader->readBytes(2));
        if ($family['value'] !== self::IPV6_FAMILY) {
            throw new CodecException('RakNet IPv6 address has an invalid address family.');
        }
        $port = $reader->readUnsignedShort();
        $flowInfo = $reader->readUnsignedInt();
        $address = inet_ntop($reader->readBytes(16));
        if ($address === false) {
            throw new CodecException('RakNet IPv6 address bytes are invalid.');
        }

        return new self($address, $port, $flowInfo, $reader->readUnsignedInt());
    }

    public function encode(BinaryWriter $writer): void
    {
        if (filter_var($this->address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $writer->writeByte(4);
            foreach (explode('.', $this->address) as $octet) {
                $writer->writeByte((~(int) $octet) & 0xff);
            }
            $writer->writeUnsignedShort($this->port);

            return;
        }

        $packed = inet_pton($this->address);
        if ($packed === false || \strlen($packed) !== 16) {
            throw new CodecException('Unable to encode RakNet IPv6 address.');
        }
        $writer->writeByte(6);
        $writer->writeBytes(pack('v', self::IPV6_FAMILY));
        $writer->writeUnsignedShort($this->port);
        $writer->writeUnsignedInt($this->flowInfo);
        $writer->writeBytes($packed);
        $writer->writeUnsignedInt($this->scopeId);
    }
}
