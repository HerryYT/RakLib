<?php

/*
 * RakLib network library
 *
 *
 * This project is not affiliated with Jenkins Software LLC nor RakNet.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace raklib\protocol;

#ifndef COMPILE
use pocketmine\utils\Binary;

#endif

#include <rules/RakLibPacket.h>

class EncapsulatedPacket{
	const RELIABILITY_SHIFT = 5;
	const RELIABILITY_FLAGS = 0b111 << self::RELIABILITY_SHIFT;

	const SPLIT_FLAG = 0b00010000;

	/** @var int */
	public $reliability;
	/** @var bool */
	public $hasSplit = false;
	/** @var int */
	public $length = 0;
	/** @var int|null */
	public $messageIndex;
	/** @var int|null */
	public $orderIndex;
	/** @var int|null */
	public $orderChannel;
	/** @var int|null */
	public $splitCount;
	/** @var int|null */
	public $splitID;
	/** @var int|null */
	public $splitIndex;
	/** @var string */
	public $buffer = "";
	/** @var bool */
	public $needACK = false;
	/** @var int|null */
	public $identifierACK;

	/**
	 * Decodes an EncapsulatedPacket from bytes generated by toInternalBinary().
	 *
	 * @param string   $bytes
	 * @param int|null &$offset Will be set to the number of bytes read
	 *
	 * @return EncapsulatedPacket
	 */
	public static function fromInternalBinary(string $bytes, ?int &$offset = null) : EncapsulatedPacket{
		$packet = new EncapsulatedPacket();

		$offset = 0;
		$packet->reliability = ord($bytes{$offset++});

		$length = Binary::readInt(substr($bytes, $offset, 4));
		$offset += 4;
		$packet->identifierACK = Binary::readInt(substr($bytes, $offset, 4)); //TODO: don't read this for non-ack-receipt reliabilities
		$offset += 4;

		if($packet->isSequenced()){
			$packet->orderChannel = ord($bytes{$offset++});
		}

		$packet->buffer = substr($bytes, $offset, $length);
		$offset += $length;
		return $packet;
	}

	/**
	 * Encodes data needed for the EncapsulatedPacket to be transmitted from RakLib to the implementation's thread.
	 * @return string
	 */
	public function toInternalBinary() : string{
		return
			chr($this->reliability) .
			Binary::writeInt(strlen($this->buffer)) .
			Binary::writeInt($this->identifierACK ?? -1) . //TODO: don't write this for non-ack-receipt reliabilities
			($this->isSequenced() ? chr($this->orderChannel) : "") .
			$this->buffer;
	}

	/**
	 * @param string $binary
	 * @param int    &$offset
	 *
	 * @return EncapsulatedPacket
	 */
	public static function fromBinary(string $binary, ?int &$offset = null) : EncapsulatedPacket{

		$packet = new EncapsulatedPacket();

		$flags = ord($binary{0});
		$packet->reliability = $reliability = ($flags & self::RELIABILITY_FLAGS) >> self::RELIABILITY_SHIFT;
		$packet->hasSplit = $hasSplit = ($flags & self::SPLIT_FLAG) > 0;

		$length = (int) ceil(Binary::readShort(substr($binary, 1, 2)) / 8);
		$offset = 3;

		if($reliability > PacketReliability::UNRELIABLE){
			if($reliability >= PacketReliability::RELIABLE and $reliability !== PacketReliability::UNRELIABLE_WITH_ACK_RECEIPT){
				$packet->messageIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
			}

			if($reliability <= PacketReliability::RELIABLE_SEQUENCED and $reliability !== PacketReliability::RELIABLE){
				$packet->orderIndex = Binary::readLTriad(substr($binary, $offset, 3));
				$offset += 3;
				$packet->orderChannel = ord($binary{$offset++});
			}
		}

		if($hasSplit){
			$packet->splitCount = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
			$packet->splitID = Binary::readShort(substr($binary, $offset, 2));
			$offset += 2;
			$packet->splitIndex = Binary::readInt(substr($binary, $offset, 4));
			$offset += 4;
		}

		$packet->buffer = substr($binary, $offset, $length);
		$offset += $length;

		return $packet;
	}

	/**
	 * @return string
	 */
	public function toBinary($internal = false){
		return
			chr(($this->reliability << self::RELIABILITY_SHIFT) | ($this->hasSplit ? self::SPLIT_FLAG : 0)) .
			Binary::writeShort(strlen($this->buffer) << 3) .
			($this->reliability > PacketReliability::UNRELIABLE ?
				($this->isReliable() ? Binary::writeLTriad($this->messageIndex) : "") .
				($this->isSequenced() ? Binary::writeLTriad($this->orderIndex) . chr($this->orderChannel) : "")
				: ""
			) .
			($this->hasSplit ? Binary::writeInt($this->splitCount) . Binary::writeShort($this->splitID) . Binary::writeInt($this->splitIndex) : "")
			. $this->buffer;
	}

	public function getTotalLength() : int{
		return 3 + strlen($this->buffer) + ($this->messageIndex !== null ? 3 : 0) + ($this->orderIndex !== null ? 4 : 0) + ($this->hasSplit ? 10 : 0);
	}

	public function isReliable() : bool{
		return (
			$this->reliability === PacketReliability::RELIABLE or
			$this->reliability === PacketReliability::RELIABLE_ORDERED or
			$this->reliability === PacketReliability::RELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_WITH_ACK_RECEIPT or
			$this->reliability === PacketReliability::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}

	public function isSequenced() : bool{
		return (
			$this->reliability === PacketReliability::UNRELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_ORDERED or
			$this->reliability === PacketReliability::RELIABLE_SEQUENCED or
			$this->reliability === PacketReliability::RELIABLE_ORDERED_WITH_ACK_RECEIPT
		);
	}

	public function __toString() : string{
		return $this->toBinary();
	}
}
