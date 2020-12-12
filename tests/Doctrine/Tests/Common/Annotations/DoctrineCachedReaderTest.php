<?php

namespace Doctrine\Tests\Common\Annotations;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
use Doctrine\Common\Cache\ArrayCache;
use Doctrine\Tests\Common\Annotations\Fixtures\Annotation\Route;
use Doctrine\Tests\Common\Annotations\Fixtures\ClassThatUsesTraitThatUsesAnotherTraitWithMethods;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function str_replace;
use function time;
use function touch;

class DoctrineCachedReaderTest extends AbstractReaderTest
{
    protected function getReader(): Reader
    {
        return new CachedReader(new AnnotationReader(), new ArrayCache());
    }
}
