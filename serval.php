<?php

# serval - SERialization of VALues only

# For the storage and network transfer of data with nested structures and
# arrays, there exists the need for memory-efficient serialization. My use
# case is distributed computing (web crawling) using gearman. Another popular
# use case of serialization is object caching in Redis, Memcached or SQL BLOBs.

# Serialization is quite inefficient without a model of the data structure,
# because all the attribute names and data types have to be specified in the
# serialized string. This is the approach of JSON and PHP's serialize function.
# But it would be more efficient when the sender and receiver know the data model
# and only the properly formatted data values are transferred.

# Typing and object-oriented features allow to describe the data; many
# languages use classes for this. In PHP, we can additionally make use of
# type hints and further detail the data model using attributes. Especially
# arrays can be serialized much more efficiently when all elements have the
# same specified type.

# Details:
# - NULL termination of strings and lists is not used, instead the length of strings and arrays is stored.
#   The reason is that PHP strings may contain NULL bytes and array items may start with NULL bytes.
# - A bit mask is used to store the NULL value state of class variables.
# - To optimize performance, I minimized the number of function calls in the loops and use some copy-pasted line.

# Outlook:
# - Ideas for better compression: Merge bool values with NULL mask, merge bool+NULL masks of all items of an array.
# - One may implement this also in Python and Javascript.
# - An extension of this approach would be a language-independent description of the data model.
#   This would allow more straight-forward interoperability across different languages.

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalLongString { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalLongArray { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalIgnore { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalInt8 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalUInt8 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalInt16 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalUInt16 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalInt32 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalUInt32 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalInt64 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalUInt64 { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalSmallFloat { }

#[Attribute(Attribute::TARGET_PROPERTY)]
class ServalItemType
{
	private string $type;
	function __construct(string $type)
	{
		$this->type = $type;
	}
}

function serval(object $object) : string
{
	$serialized = '';
	$className = get_class($object);
	$properties = (new ReflectionClass($className))->getProperties(ReflectionProperty::IS_PUBLIC);

	$nullableIndex = 0;
	foreach ($properties as $property) {
		if (count($property->getAttributes('ServalIgnore')) > 0) {
			continue;
		}
		if ($property->getType()->allowsNull()) {
			if (!isset($serialized[(int) ($nullableIndex / 8)])) {
				$serialized .= "\x00";
			}
			if ($object->{$property->getName()} === NULL) {
				$serialized[(int) ($nullableIndex / 8)] = chr(ord($serialized[(int) ($nullableIndex / 8)]) | 1 << ($nullableIndex % 8));
			}
			$nullableIndex += 1;
		}
	}
	foreach ($properties as $property) {
		if (count($property->getAttributes('ServalIgnore')) > 0) {
			continue;
		}
		if (!$property->getType() instanceof ReflectionNamedType) {
			throw new Exception('Serialization requires non-union type hints.');
		}
		if ($object->{$property->getName()} === NULL) {
			continue;
		}
		$typeName = $property->getType()->getName();
		if ($typeName === 'string') {
			list($letter, $bytes) = count($property->getAttributes('ServalLongString')) > 0 ? ['l', 4] : ['s', 2];
			$strlen = strlen($object->{$property->getName()});
			if ($strlen > 2**(8 * $bytes) - 1) {
				throw new Exception('String is too long for serialization.');
			}
			$serialized .= pack($letter, $strlen);
			$serialized .= $object->{$property->getName()};
		}
		else if ($typeName === 'array') {
			if (count($property->getAttributes('ServalItemType')) === 0) {
				throw new Exception('A ServalItemType attribute is required to serialize the array.');
			}
			$className = $property->getAttributes('ServalItemType')[0]->getArguments()[0];
			list($letter, $bytes) = count($property->getAttributes('ServalLongArray')) > 0 ? ['l', 4] : ['s', 2];
			if ($strlen > 2**(8 * $bytes) - 1) {
				throw new Exception('Array is too long for serialization.');
			}
			$serialized .= pack($letter, count($object->{$property->getName()}));
			if ($className === 'int') {
				foreach ($property->getAttributes() as $attribute) {
					list($letter, $bytes) = match ($attribute) {
						'ServalInt8' => ['c', 1],
						'ServalUInt8' => ['C', 1],
						'ServalInt16' => ['s', 2],
						'ServalUInt16' => ['n', 2],
						'ServalInt32' => ['l', 4],
						'ServalUInt32' => ['N', 4],
						'ServalInt64' => ['q', 8],
						'ServalUInt64' => ['J', 8],
						default => [NULL, NULL]
					};
					if ($letter !== NULL) {
						break;
					}
				}
				foreach ($object->{$property->getName()} as $item) {
					$serialized .= pack($letter ?? 'q', $item);
				}
			}
			else if ($className === 'string') {
				list($letter, $bytes) = count($property->getAttributes('ServalLongString')) > 0 ? ['l', 4] : ['s', 2];
				foreach ($object->{$property->getName()} as $item) {
					$strlen = strlen($item);
					if ($strlen > 2**(8 * $bytes) - 1) {
						throw new Exception('String is too long for serialization.');
					}
					$serialized .= pack($letter, $strlen) . $item;
				}
			}
			else if ($className === 'bool') {
				foreach ($object->{$property->getName()} as $item) {
					$serialized .= pack('c', $item);
				}
			}
			else if ($className === 'float') {
				if (count($property->getAttributes('ServalSmallFloat')) > 0) {
					foreach ($object->{$property->getName()} as $item) {
						$serialized .= pack('G', $item);
					}
				}
				else {
					foreach ($object->{$property->getName()} as $item) {
						$serialized .= pack('E', $item);
					}
				}
			}
			else {
				foreach ($object->{$property->getName()} as $item) {
					if (is_subclass_of($item, $className)) {
						throw new Exception('Array item type does not match the specification.');
					}
					$serialized .= serval($item);
				}
			}
		}
		else if ($typeName === 'int') {
			foreach ($property->getAttributes() as $attribute) {
				list($letter, $bytes) = match ($attribute) {
					'ServalInt8' => ['c', 1],
					'ServalUInt8' => ['C', 1],
					'ServalInt16' => ['s', 2],
					'ServalUInt16' => ['n', 2],
					'ServalInt32' => ['l', 4],
					'ServalUInt32' => ['N', 4],
					'ServalInt64' => ['q', 8],
					'ServalUInt64' => ['J', 8],
					default => [NULL, NULL]
				};
				if ($letter !== NULL) {
					break;
				}
			}
			$serialized .= pack($letter ?? 'q', $object->{$property->getName()});
		}
		else if ($typeName === 'bool') {
			$serialized .= pack('c', $object->{$property->getName()});
		}
		else if ($typeName === 'float') {
			if (count($property->getAttributes('ServalSmallFloat')) > 0) {
				$serialized .= pack('G', $object->{$property->getName()});
			}
			else {
				$serialized .= pack('E', $object->{$property->getName()});
			}
		}
		else if ($property->getType() === NULL) {
			throw new Exception('A type hint is required for serialization.');
		}
		else {
			$serialized .= serval($object->{$property->getName()});
		}
	}
	return $serialized;
}

function unserval(string $data, string $className, int &$offset=0) : object
{
	$class = new ReflectionClass($className);
	$unserialized = $class->newInstanceWithoutConstructor();
	$properties = $class->getProperties(ReflectionProperty::IS_PUBLIC);

	$numNullables = 0;
	foreach ($properties as $property) {
		if ($property->getType()->allowsNull()) {
			$numNullables += 1;
		}
	}
	$startOffset = $offset;
	$offset += (int) ceil($numNullables / 8);
	unset($numNullables);

	$nullableIndex = 0;
	foreach ($properties as $property) {
		if (count($property->getAttributes('ServalIgnore')) > 0) {
			continue;
		}
		$type = $property->getType();
		if (!$type instanceof ReflectionNamedType) {
			throw new Exception('Deserialization requires non-union type hints.');
		}
		if ($type->allowsNull()) {
			$nullBit = ord($data[$startOffset + (int) ($nullableIndex / 8)]) & 1 << ($nullableIndex % 8);
			$nullableIndex += 1;
			if ($nullBit) {
				$unserialized->{$property->getName()} = NULL;
				continue;
			}
		}
		$typeName = $type->getName();
		if ($typeName === 'string') {
			list($letter, $bytes) = count($property->getAttributes('ServalLongString')) > 0 ? ['l', 4] : ['s', 2];
			$strlen = unpack($letter, $data, $offset)[1];
			$offset += $bytes;
			$unserialized->{$property->getName()} = substr($data, $offset, $strlen);
			$offset += $strlen;
		}
		else if ($typeName === 'array') {
			$itemType = $property->getAttributes('ServalItemType');
			if (count($itemType) === 0) {
				throw new Exception('A ServalItemType attribute is required to serialize the array.');
			}
			list($letter, $bytes) = count($property->getAttributes('ServalLongArray')) > 0 ? ['l', 4] : ['s', 2];
			$count = unpack($letter, $data, $offset)[1];
			$offset += $bytes;
			$className = $itemType[0]->getArguments()[0];
			if ($className === 'string') {
				list($letter, $bytes) = count($property->getAttributes('ServalLongString')) > 0 ? ['l', 4] : ['s', 2];
				for ($i = 0; $i < $count; ++$i) {
					$strlen = unpack($letter, $data, $offset)[1];
					$offset += $bytes;
					$unserialized->{$property->getName()}[] = substr($data, $offset, $strlen);
					$offset += $strlen;
				}
			}
			else if ($className === 'int') {
				foreach ($property->getAttributes() as $attribute) {
					list($letter, $bytes) = match ($attribute) {
						'ServalInt8' => ['c', 1],
						'ServalUInt8' => ['C', 1],
						'ServalInt16' => ['s', 2],
						'ServalUInt16' => ['n', 2],
						'ServalInt32' => ['l', 4],
						'ServalUInt32' => ['N', 4],
						'ServalInt64' => ['q', 8],
						'ServalUInt64' => ['J', 8],
						default => [NULL, NULL]
					};
					if ($letter !== NULL) {
						break;
					}
				}
				for ($i = 0; $i < $count; ++$i) {
					$unserialized->{$property->getName()}[] = unpack($letter ?? 'q', $data, $offset)[1];
					$offset += $bytes ?? 8;
				}
			}
			else if ($className === 'bool') {
				$unserialized->{$property->getName()} = unpack('c', $data, $offset)[1];
			}
			else if ($className === 'float') {
				if (count($property->getAttributes('ServalSmallFloat')) > 0) {
					for ($i = 0; $i < $count; ++$i) {
						$unserialized->{$property->getName()}[] = unpack('G', $data, $offset)[1];
						$offset += 4;
					}
				}
				else {
					for ($i = 0; $i < $count; ++$i) {
						$unserialized->{$property->getName()}[] = unpack('E', $data, $offset)[1];
						$offset += 8;
					}
				}
			}
			else {
				for ($i = 0; $i < $count; ++$i) {
					$unserialized->{$property->getName()}[] = unserval($data, $className, $offset);
				}
			}
		}
		else if ($typeName === 'int') {
			foreach ($property->getAttributes() as $attribute) {
				list($letter, $bytes) = match ($attribute) {
					'ServalInt8' => ['c', 1],
					'ServalUInt8' => ['C', 1],
					'ServalInt16' => ['s', 2],
					'ServalUInt16' => ['n', 2],
					'ServalInt32' => ['l', 4],
					'ServalUInt32' => ['N', 4],
					'ServalInt64' => ['q', 8],
					'ServalUInt64' => ['J', 8],
					default => [NULL, NULL]
				};
				if ($letter !== NULL) {
					break;
				}
			}
			$unserialized->{$property->getName()} = unpack($letter ?? 'q', $data, $offset)[1];
			$offset += $bytes ?? 8;
		}
		else if ($typeName === 'bool') {
			$unserialized->{$property->getName()} = unpack('c', $data, $offset)[1];
		}
		else if ($typeName === 'float') {
			if (count($property->getAttributes('ServalSmallFloat')) > 0) {
				$unserialized->{$property->getName()} = unpack('G', $data, $offset)[1];
				$offset += 4;
			}
			else {
				$unserialized->{$property->getName()} = unpack('E', $data, $offset)[1];
				$offset += 8;
			}
		}
		else if ($property->getType() === NULL) {
			throw new Exception('A type hint is required for deserialization.');
		}
		else {
			$className = $property->getType();
			$unserialized->{$property->getName()} = unserval($data, $className, $offset);
		}
	}
	return $unserialized;
}
