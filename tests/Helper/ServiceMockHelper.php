<?php

declare(strict_types=1);

namespace App\Tests\Helper;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionException;

final class ServiceMockHelper
{
    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public static function setPrivateProperty(object $instance, string $property, mixed $value): void
    {
        $ref = new ReflectionClass($instance);
        while (false !== $ref && !$ref->hasProperty($property)) {
            $ref = $ref->getParentClass();
        }

        if (false === $ref) {
            throw new InvalidArgumentException(\sprintf("Property '%s' not found on ", $property).$instance::class);
        }

        $prop = $ref->getProperty($property);
        $prop->setValue($instance, $value);
    }

    /**
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public static function getPrivateProperty(object $instance, string $property): mixed
    {
        $ref = new ReflectionClass($instance);
        while (false !== $ref && !$ref->hasProperty($property)) {
            $ref = $ref->getParentClass();
        }

        if (false === $ref) {
            throw new InvalidArgumentException(\sprintf("Property '%s' not found on ", $property).$instance::class);
        }

        $prop = $ref->getProperty($property);

        return $prop->getValue($instance);
    }
}
