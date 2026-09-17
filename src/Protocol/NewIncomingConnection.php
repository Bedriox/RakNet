<?php

declare(strict_types=1);

namespace Bedriox\RakNet\Protocol;

use Bedriox\RakNet\Exception\CodecException;

final readonly class NewIncomingConnection
{
    public const int ID = 0x13;
    public const int INTERNAL_ADDRESS_COUNT = 20;
    public const int MAXIMUM_LENGTH = 626;

    /** @var list<InternetAddress> */
    public array $internalAddresses;

    /** @param array<array-key, mixed> $internalAddresses */
    public function __construct(
        public InternetAddress $serverAddress,
        array $internalAddresses,
        public int $requestTimestamp,
        public int $clientTimestamp,
    ) {
        if (\count($internalAddresses) !== self::INTERNAL_ADDRESS_COUNT) {
            throw new CodecException('New incoming connection must contain exactly twenty internal addresses.');
        }
        foreach ($internalAddresses as $address) {
            if (!$address instanceof InternetAddress) {
                throw new CodecException('New-incoming internal address is invalid.');
            }
        }
        $this->internalAddresses = array_values($internalAddresses);
        if ($this->requestTimestamp < 0 || $this->clientTimestamp < 0) {
            throw new CodecException('New-incoming timestamps must be nonnegative.');
        }
    }

    public static function decode(string $bytes): self
    {
        $reader = new BinaryReader($bytes, self::MAXIMUM_LENGTH);
        if ($reader->readByte() !== self::ID) {
            throw new CodecException('New incoming connection has an invalid packet identifier.');
        }
        $serverAddress = InternetAddress::decode($reader);
        $internal = [];
        for ($index = 0; $index < self::INTERNAL_ADDRESS_COUNT; ++$index) {
            $internal[] = InternetAddress::decode($reader);
        }
        $packet = new self($serverAddress, $internal, $reader->readLong(), $reader->readLong());
        $reader->requireEnd();

        return $packet;
    }

    public function encode(): string
    {
        $writer = new BinaryWriter(self::MAXIMUM_LENGTH);
        $writer->writeByte(self::ID);
        $this->serverAddress->encode($writer);
        foreach ($this->internalAddresses as $address) {
            $address->encode($writer);
        }
        $writer->writeLong($this->requestTimestamp);
        $writer->writeLong($this->clientTimestamp);

        return $writer->bytes();
    }
}
