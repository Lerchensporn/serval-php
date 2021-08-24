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
# - Null termination of strings and lists is not used, instead the length of strings and arrays is stored.
#   The reason is that PHP strings may contain null bytes and array items may start with null bytes.
# - A common bit mask is used to store null, bool and the used union type.
# - To optimize performance, I minimized the number of function calls in the loops and use some copy-pasted line.

# TODO:
# - Support associative arrays.

# Outlook:
# - Ideas for better compression: Merge bool+null masks of all items of an array.
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

	$maskIndex = 0;
	foreach ($properties as $property) {
		if (count($property->getAttributes('ServalIgnore')) > 0) {
			continue;
		}
		if ($property->getType()->allowsnull()) {
			if (!isset($serialized[(int) ($maskIndex / 8)])) {
				$serialized .= "\x0";
			}
			if ($object->{$property->getName()} === null) {
				$serialized[(int) ($maskIndex / 8)] = chr(ord($serialized[(int) ($maskIndex / 8)]) | 128 >> ($maskIndex % 8));
			}
			$maskIndex += 1;
		}
		if ($property->getType() instanceof ReflectionUnionType) {
			$types = $property->getType()->getTypes();
			$type = gettype($object->{$property->getName()});
			$type = match($type) {
				'integer' => 'int',
				'boolean' => 'bool',
				'object' => get_class($object->{$property->getName()}),
				default => $type
			};
			$typeIndex = array_search($type, array_map(function($type) { return $type->getName(); }, $types));
			$numBits = (int) ceil(log(count($types), 2));
			for ($i = $numBits - 1; $i >= 0; --$i) {
				if (!isset($serialized[(int) ($maskIndex / 8)])) {
					$serialized .= "\x0";
				}
				if ($typeIndex & (1 << $i)) {
					$serialized[(int) ($maskIndex / 8)] = chr(ord($serialized[(int) ($maskIndex / 8)]) | 128 >> ($maskIndex % 8));
				}
				$maskIndex += 1;
			}
		}
		if (gettype($object->{$property->getName()}) === 'boolean') {
			if (!isset($serialized[(int) ($maskIndex / 8)])) {
				$serialized .= "\x0";
			}
			if ($object->{$property->getName()} === true) {
				$serialized[(int) ($maskIndex / 8)] = chr(ord($serialized[(int) ($maskIndex / 8)]) | 128 >> ($maskIndex % 8));
			}
			$maskIndex += 1;
		}
	}
	foreach ($properties as $property) {
		if (count($property->getAttributes('ServalIgnore')) > 0) {
			continue;
		}
		if ($object->{$property->getName()} === null) {
			continue;
		}
		$typeName = gettype($object->{$property->getName()});
		if ($typeName === 'object') {
			$typeName = get_class($object->{$property->getName()});
		}

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
			if (count($object->{$property->getName()}) > 2**(8 * $bytes) - 1) {
				throw new Exception('Array is too long for serialization.');
			}
			$serialized .= pack($letter, count($object->{$property->getName()}));
			if ($className === 'int') {
				list($letter, $bytes) = [null, null];
				foreach ($property->getAttributes() as $attribute) {
					list($letter, $bytes) = match ($attribute->getName()) {
						'ServalInt8' => ['c', 1],
						'ServalUInt8' => ['C', 1],
						'ServalInt16' => ['s', 2],
						'ServalUInt16' => ['n', 2],
						'ServalInt32' => ['l', 4],
						'ServalUInt32' => ['N', 4],
						'ServalInt64' => ['q', 8],
						'ServalUInt64' => ['J', 8],
						default => [null, null]
					};
					if ($letter !== null) {
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
			else if ($className === 'bool') {
				$boolbyte = 0;
				for ($i = 0; $i < count($object->{$property->getName()}); ++$i) {
					if ($object->{$property->getName()}[$i] === true) {
						$boolbyte |= 128 >> ($i % 8);
					}
					if ($i > 0 && $i % 8 === 0) {
						$serialized .= chr($boolbyte);
						$boolbyte = 0;
					}
				}
				if ($i % 8 !== 0) {
					$serialized .= chr($boolbyte);
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
		else if ($typeName === 'integer') {
			list($letter, $bytes) = [null, null];
			foreach ($property->getAttributes() as $attribute) {
				list($letter, $bytes) = match ($attribute->getName()) {
					'ServalInt8' => ['c', 1],
					'ServalUInt8' => ['C', 1],
					'ServalInt16' => ['s', 2],
					'ServalUInt16' => ['n', 2],
					'ServalInt32' => ['l', 4],
					'ServalUInt32' => ['N', 4],
					'ServalInt64' => ['q', 8],
					'ServalUInt64' => ['J', 8],
					default => [null, null]
				};
				if ($letter !== null) {
					break;
				}
			}
			$serialized .= pack($letter ?? 'q', $object->{$property->getName()});
		}
		else if ($typeName === 'boolean') {
			continue;
		}
		else if ($typeName === 'float') {
			if (count($property->getAttributes('ServalSmallFloat')) > 0) {
				$serialized .= pack('G', $object->{$property->getName()});
			}
			else {
				$serialized .= pack('E', $object->{$property->getName()});
			}
		}
		else if ($property->getType() === null) {
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

	$unionTypes = [];
	$nulls = [];
	$maskIndex = 0;
	$properties = array_filter($properties, function($property) {
		return count($property->getAttributes('ServalIgnore')) === 0;
	});
	foreach ($properties as $property) {
		$type = $property->getType();
		if ($type->allowsnull()) {
			$nullBit = ord($data[$offset + (int) ($maskIndex / 8)]) & 128 >> ($maskIndex % 8);
			$maskIndex += 1;
			$nulls[] = $nullBit;
			if ($nullBit) {
				$unserialized->{$property->getName()} = null;
				continue;
			}
		}
		if ($type instanceof ReflectionUnionType) {
			$types = $property->getType()->getTypes();
			$numBits = (int) ceil(log(count($types), 2));
			$typeIndex = 0;
			for ($i = $numBits - 1; $i >= 0; --$i) {
				if (ord($data[$offset + (int) ($maskIndex / 8)]) & 128 >> ($maskIndex % 8)) {
					$typeIndex += 2 ** $i;
				}
				$maskIndex += 1;
			}
			$type = $types[$typeIndex]->getName();
		}
		else {
			$type = $type->getName();
		}
		if ($type === 'bool') {
			$boolval = ord($data[$offset + (int) ($maskIndex / 8)]) & 128 >> ($maskIndex % 8);
			$maskIndex += 1;
			if ($boolval) {
				$unserialized->{$property->getName()} = true;
			}
		}
		$unionTypes[] = $type;
	}
	$startOffset = $offset;
	$offset += (int) ceil($maskIndex / 8);
	unset($maskIndex);

	$unionIndex = 0;
	$nullIndex = 0;
	foreach ($properties as $property) {
		$type = $property->getType();
		if ($type->allowsnull() && $nulls[$nullIndex++]) {
			continue;
		}
		if ($type instanceof ReflectionNamedType) {
			$typeName = $type->getName();
		}
		else {
			$typeName = $unionTypes[$unionIndex];
			$unionIndex += 1;
		}
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
			$unserialized->{$property->getName()} = [];
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
				list($letter, $bytes) = [null, null];
				foreach ($property->getAttributes() as $attribute) {
					list($letter, $bytes) = match ($attribute->getName()) {
						'ServalInt8' => ['c', 1],
						'ServalUInt8' => ['C', 1],
						'ServalInt16' => ['s', 2],
						'ServalUInt16' => ['n', 2],
						'ServalInt32' => ['l', 4],
						'ServalUInt32' => ['N', 4],
						'ServalInt64' => ['q', 8],
						'ServalUInt64' => ['J', 8],
						default => [null, null]
					};
					if ($letter !== null) {
						break;
					}
				}
				for ($i = 0; $i < $count; ++$i) {
					$unserialized->{$property->getName()}[] = unpack($letter ?? 'q', $data, $offset)[1];
					$offset += $bytes ?? 8;
				}
			}
			else if ($className === 'bool') {
				for ($i = 0; $i < $count; ++$i) {
					$unserialized->{$property->getName()}[] = (bool) (ord($data[$offset + (int) ($i / 8)]) & 128 >> ($i % 8));
					if ($i > 0 && $i % 8 === 0) {
						$offset += 1;
					}
				}
				if ($i > 0 && $i % 8 !== 0) {
					$offset += 1;
				}
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
		else if ($typeName === 'bool') {
			continue;
		}
		else if ($typeName === 'int') {
			list($letter, $bytes) = [null, null];
			foreach ($property->getAttributes() as $attribute) {
				list($letter, $bytes) = match ($attribute->getName()) {
					'ServalInt8' => ['c', 1],
					'ServalUInt8' => ['C', 1],
					'ServalInt16' => ['s', 2],
					'ServalUInt16' => ['n', 2],
					'ServalInt32' => ['l', 4],
					'ServalUInt32' => ['N', 4],
					'ServalInt64' => ['q', 8],
					'ServalUInt64' => ['J', 8],
					default => [null, null]
				};
				if ($letter !== null) {
					break;
				}
			}
			$unserialized->{$property->getName()} = unpack($letter ?? 'q', $data, $offset)[1];
			$offset += $bytes ?? 8;
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
		else if ($property->getType() === null) {
			throw new Exception('A type hint is required for deserialization.');
		}
		else {
			$unserialized->{$property->getName()} = unserval($data, $typeName, $offset);
		}
	}
	return $unserialized;
}
