<?php

namespace Doctrine\Common\Annotations;

use BadMethodCallException;
use Closure;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;
use Psr\Cache\CacheItemPoolInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Cache\Adapter\DoctrineAdapter;

use function array_map;
use function array_merge;
use function assert;
use function filemtime;
use function max;
use function sprintf;
use function str_replace;
use function time;
use function trigger_error;

use const E_USER_DEPRECATED;

/**
 * A cache aware annotation reader.
 */
final class CachedReader implements Reader
{
    /** @var Reader */
    private $delegate;

    /** @var CacheItemPoolInterface */
    private $cache;

    /** @var bool */
    private $debug;

    /** @var array<string, array<object>> */
    private $loadedAnnotations = [];

    /** @var int[] */
    private $loadedFilemtimes = [];

    /**
     * @param Cache|CacheItemPoolInterface $cache
     * @param bool                         $debug
     */
    public function __construct(Reader $reader, $cache, $debug = false)
    {
        $this->delegate = $reader;
        $this->debug    = (bool) $debug;

        if ($cache instanceof Cache) {
            if (! $cache instanceof CacheProvider) {
                throw new BadMethodCallException('Cannot convert cache to PSR-6 cache');
            }

            $cache = new DoctrineAdapter($cache);

            @trigger_error(
                // phpcs:ignore Generic.Files.LineLength.TooLong
                'Creating a cached annotation reader with a doctrine/cache instance is deprecated since 1.11. Please provide a PSR-6 cache instead.',
                E_USER_DEPRECATED
            );
        }

        if (! $cache instanceof CacheItemPoolInterface) {
            throw new BadMethodCallException(sprintf(
                'Invalid cache given: need an instance of %s or %s',
                CacheProvider::class,
                CacheItemPoolInterface::class
            ));
        }

        $this->cache = $cache;
    }

    /**
     * {@inheritDoc}
     */
    public function getClassAnnotations(ReflectionClass $class)
    {
        $cacheKey = $this->getCacheKey($class);

        if (isset($this->loadedAnnotations[$cacheKey])) {
            return $this->loadedAnnotations[$cacheKey];
        }

        $annots = $this->fetchCached($cacheKey, $class, function () use ($class): array {
            return $this->delegate->getClassAnnotations($class);
        });

        return $this->loadedAnnotations[$cacheKey] = $annots;
    }

    /**
     * {@inheritDoc}
     */
    public function getClassAnnotation(ReflectionClass $class, $annotationName)
    {
        foreach ($this->getClassAnnotations($class) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getPropertyAnnotations(ReflectionProperty $property)
    {
        $class    = $property->getDeclaringClass();
        $cacheKey = $this->getCacheKey($class) . '$' . $property->getName();

        if (isset($this->loadedAnnotations[$cacheKey])) {
            return $this->loadedAnnotations[$cacheKey];
        }

        $annots = $this->fetchCached($cacheKey, $class, function () use ($property): array {
            return $this->delegate->getPropertyAnnotations($property);
        });

        return $this->loadedAnnotations[$cacheKey] = $annots;
    }

    /**
     * {@inheritDoc}
     */
    public function getPropertyAnnotation(ReflectionProperty $property, $annotationName)
    {
        foreach ($this->getPropertyAnnotations($property) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function getMethodAnnotations(ReflectionMethod $method)
    {
        $class    = $method->getDeclaringClass();
        $cacheKey = $this->getCacheKey($class) . '#' . $method->getName();

        if (isset($this->loadedAnnotations[$cacheKey])) {
            return $this->loadedAnnotations[$cacheKey];
        }

        $annots = $this->fetchCached($cacheKey, $class, function () use ($method): array {
            return $this->delegate->getMethodAnnotations($method);
        });

        return $this->loadedAnnotations[$cacheKey] = $annots;
    }

    /**
     * {@inheritDoc}
     */
    public function getMethodAnnotation(ReflectionMethod $method, $annotationName)
    {
        foreach ($this->getMethodAnnotations($method) as $annot) {
            if ($annot instanceof $annotationName) {
                return $annot;
            }
        }

        return null;
    }

    /**
     * Clears loaded annotations.
     *
     * @return void
     */
    public function clearLoadedAnnotations()
    {
        $this->loadedAnnotations = [];
        $this->loadedFilemtimes  = [];
    }

    /** @return mixed */
    private function fetchCached(string $cacheKey, ReflectionClass $class, Closure $getAnnotations)
    {
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            if ($this->isCacheFresh($cacheKey, $class)) {
                return $item->get();
            }
        }

        $data = $getAnnotations();
        $item->set($data);

        if ($this->debug) {
            $cachedAt = $this->cache->getItem('[C]' . $cacheKey);
            $cachedAt->set(time());

            $this->cache->saveDeferred($item);
            $this->cache->saveDeferred($cachedAt);
            $this->cache->commit();
        } else {
            $this->cache->save($item);
        }

        return $data;
    }

    /**
     * Checks if the cache is fresh.
     *
     * @param string $cacheKey
     *
     * @return bool
     */
    private function isCacheFresh($cacheKey, ReflectionClass $class)
    {
        if (! $this->debug) {
            return true;
        }

        $lastModification = $this->getLastModification($class);
        if ($lastModification === 0) {
            return true;
        }

        $item = $this->cache->getItem('[C]' . $cacheKey);

        return $item->isHit() && $item->get() >= $lastModification;
    }

    /**
     * Returns the time the class was last modified, testing traits and parents
     */
    private function getLastModification(ReflectionClass $class): int
    {
        $filename = $class->getFileName();

        if (isset($this->loadedFilemtimes[$filename])) {
            return $this->loadedFilemtimes[$filename];
        }

        $parent = $class->getParentClass();

        $lastModification =  max(array_merge(
            [$filename ? filemtime($filename) : 0],
            array_map(function (ReflectionClass $reflectionTrait): int {
                return $this->getTraitLastModificationTime($reflectionTrait);
            }, $class->getTraits()),
            array_map(function (ReflectionClass $class): int {
                return $this->getLastModification($class);
            }, $class->getInterfaces()),
            $parent ? [$this->getLastModification($parent)] : []
        ));

        assert($lastModification !== false);

        return $this->loadedFilemtimes[$filename] = $lastModification;
    }

    private function getTraitLastModificationTime(ReflectionClass $reflectionTrait): int
    {
        $fileName = $reflectionTrait->getFileName();

        if (isset($this->loadedFilemtimes[$fileName])) {
            return $this->loadedFilemtimes[$fileName];
        }

        $lastModificationTime = max(array_merge(
            [$fileName ? filemtime($fileName) : 0],
            array_map(function (ReflectionClass $reflectionTrait): int {
                return $this->getTraitLastModificationTime($reflectionTrait);
            }, $reflectionTrait->getTraits())
        ));

        assert($lastModificationTime !== false);

        return $this->loadedFilemtimes[$fileName] = $lastModificationTime;
    }

    private function getCacheKey(ReflectionClass $class): string
    {
        return str_replace('\\', '_', $class->getName());
    }
}
