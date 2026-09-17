<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class ConnectionRequestAccepted
{
    public const int ID = 0x10;
    public const int INTERNAL_ADDRESS_COUNT = 20;
    public const int MAXIMUM_LENGTH = 628;

    /** @var list<InternetAddress> */
    public array $internalAddresses;

    /** @param array<array-key, mixed> $internalAddresses */
    public function __construct(
        public InternetAddress $clientAddress,
        public int $systemIndex,
        array $internalAddresses,
        public int $requestTimestamp,
        public int $acceptedTimestamp,
    ) {
        if ($this->systemIndex < 0 || $this->systemIndex > 0xffff) {
            throw new CodecException('Connection-accept system index is out of range.');
        }
        if (\count($internalAddresses) !== self::INTERNAL_ADDRESS_COUNT) {
            throw new CodecException('Connection accept must contain exactly twenty internal addresses.');
        }
        foreach ($internalAddresses as $address) {
            if (!$address instanceof InternetAddress) {
                throw new CodecException('Connection-accept internal address is invalid.');
            }
        }
        $this->internalAddresses = array_values($internalAddresses);
        if ($this->requestTimestamp < 0 || $this->acceptedTimestamp < 0) {
            throw new CodecException('Connection-accept timestamps must be nonnegative.');
        }
    }

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::MAXIMUM_LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('Connection accept has an invalid packet identifier.');
        }
        $clientAddress = InternetAddress::decode($reader);
        $systemIndex = $reader->readUnsignedShort();
        $internal = [];
        for ($index = 0; $index < self::INTERNAL_ADDRESS_COUNT; ++$index) {
            $internal[] = InternetAddress::decode($reader);
        }
        $packet = new self($clientAddress, $systemIndex, $internal, $reader->readLong(), $reader->readLong());
        $reader->requireEnd();

        return $packet;
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::MAXIMUM_LENGTH);
        $writer->writeByte(self::ID);
        $this->clientAddress->encode($writer);
        $writer->writeUnsignedShort($this->systemIndex);
        foreach ($this->internalAddresses as $address) {
            $address->encode($writer);
        }
        $writer->writeLong($this->requestTimestamp);
        $writer->writeLong($this->acceptedTimestamp);

        return $writer->bytes();
    }
}
