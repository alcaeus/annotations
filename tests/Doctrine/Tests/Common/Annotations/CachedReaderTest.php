<?php

namespace Doctrine\Tests\Common\Annotations;

use Doctrine\Common\Annotations\AnnotationReader;
use Doctrine\Common\Annotations\CachedReader;
use Doctrine\Common\Annotations\Reader;
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

class CachedReaderTest extends AbstractReaderTest
{
    /** @var CacheItemPoolInterface */
    private $cache;

    public function testIgnoresStaleCache(): void
    {
        $cache = time() - 10;
        touch(__DIR__ . '/Fixtures/Controller.php', $cache + 10);

        $this->doTestCacheStale(Fixtures\Controller::class, $cache);
    }

    /**
     * @group 62
     */
    public function testIgnoresStaleCacheWithParentClass(): void
    {
        $cache = time() - 10;
        touch(__DIR__ . '/Fixtures/ControllerWithParentClass.php', $cache - 10);
        touch(__DIR__ . '/Fixtures/AbstractController.php', $cache + 10);

        $this->doTestCacheStale(Fixtures\ControllerWithParentClass::class, $cache);
    }

    /**
     * @group 62
     */
    public function testIgnoresStaleCacheWithTraits(): void
    {
        $cache = time() - 10;
        touch(__DIR__ . '/Fixtures/ControllerWithTrait.php', $cache - 10);
        touch(__DIR__ . '/Fixtures/Traits/SecretRouteTrait.php', $cache + 10);

        $this->doTestCacheStale(Fixtures\ControllerWithTrait::class, $cache);
    }

    /**
     * @group 62
     */
    public function testIgnoresStaleCacheWithTraitsThatUseOtherTraits(): void
    {
        $cache = time() - 10;

        touch(__DIR__ . '/Fixtures/ClassThatUsesTraitThatUsesAnotherTrait.php', $cache - 10);
        touch(__DIR__ . '/Fixtures/Traits/EmptyTrait.php', $cache + 10);

        $this->doTestCacheStale(
            Fixtures\ClassThatUsesTraitThatUsesAnotherTrait::class,
            $cache
        );
    }

    /**
     * @group 62
     */
    public function testIgnoresStaleCacheWithInterfacesThatExtendOtherInterfaces(): void
    {
        $cache = time() - 10;

        touch(__DIR__ . '/Fixtures/InterfaceThatExtendsAnInterface.php', $cache - 10);
        touch(__DIR__ . '/Fixtures/EmptyInterface.php', $cache + 10);

        $this->doTestCacheStale(
            Fixtures\InterfaceThatExtendsAnInterface::class,
            $cache
        );
    }

    /**
     * @group 62
     * @group 105
     */
    public function testUsesFreshCacheWithTraitsThatUseOtherTraits(): void
    {
        $cacheTime = time();

        touch(__DIR__ . '/Fixtures/ClassThatUsesTraitThatUsesAnotherTrait.php', $cacheTime - 10);
        touch(__DIR__ . '/Fixtures/Traits/EmptyTrait.php', $cacheTime - 10);

        $this->doTestCacheFresh(
            'Doctrine\Tests\Common\Annotations\Fixtures\ClassThatUsesTraitThatUsesAnotherTrait',
            $cacheTime
        );
    }

    /**
     * @group 62
     */
    public function testPurgeLoadedAnnotations(): void
    {
        $cache = time() - 10;

        touch(__DIR__ . '/Fixtures/ClassThatUsesTraitThatUsesAnotherTrait.php', $cache - 10);
        touch(__DIR__ . '/Fixtures/Traits/EmptyTrait.php', $cache + 10);

        $reader = $this->doTestCacheStale(
            Fixtures\ClassThatUsesTraitThatUsesAnotherTrait::class,
            $cache
        );

        $classReader = new ReflectionClass(CachedReader::class);

        $loadedAnnotationsProperty = $classReader->getProperty('loadedAnnotations');
        $loadedAnnotationsProperty->setAccessible(true);
        $this->assertCount(1, $loadedAnnotationsProperty->getValue($reader));

        $loadedFilemtimesProperty = $classReader->getProperty('loadedFilemtimes');
        $loadedFilemtimesProperty->setAccessible(true);
        $this->assertCount(3, $loadedFilemtimesProperty->getValue($reader));

        $reader->clearLoadedAnnotations();

        $this->assertCount(0, $loadedAnnotationsProperty->getValue($reader));
        $this->assertCount(0, $loadedFilemtimesProperty->getValue($reader));
    }

    /**
     * As there is a cache on loadedAnnotations, we need to test two different
     * method's annotations of the same file
     *
     * We test four things
     * 1. we load the file (and its filemtime) for method1 annotation with fresh cache
     * 2. we load the file for method2 with stale cache => but still no save, because seen as fresh
     * 3. we purge loaded annotations and filemtime
     * 4. same as 2, but this time without filemtime cache, so file seen as stale and new cache is saved
     *
     * @group 62
     * @group 105
     */
    public function testAvoidCallingFilemtimeTooMuch(): void
    {
        $className = ClassThatUsesTraitThatUsesAnotherTraitWithMethods::class;
        $cacheKey  = str_replace('\\', '_', $className);
        $cacheTime = time() - 10;

        $cacheKeyMethod1 = $cacheKey . '#method1';
        $cacheKeyMethod2 = $cacheKey . '#method2';

        $route1          = new Route();
        $route1->pattern = '/someprefix';
        $route2          = new Route();
        $route2->pattern = '/someotherprefix';

        $cacheItem1 = $this->createMock(CacheItemInterface::class);
        $cacheItem1
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $cacheItem1
            ->expects($this->any())
            ->method('get')
            ->willReturn([$route1]);

        $cacheItem2 = $this->createMock(CacheItemInterface::class);
        $cacheItem2
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $cacheItem2
            ->expects($this->any())
            ->method('get')
            ->willReturn([$route2]);

        $timeCacheItem = $this->createMock(CacheItemInterface::class);
        $timeCacheItem
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $timeCacheItem
            ->expects($this->any())
            ->method('get')
            ->willReturn($cacheTime);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache
            ->expects($this->any())
            ->method('getItem')
            ->willReturnMap([
                [$cacheKeyMethod1, $cacheItem1],
                [$cacheKeyMethod2, $cacheItem2],
                ['[C]' . $cacheKeyMethod1, $timeCacheItem],
                ['[C]' . $cacheKeyMethod2, $timeCacheItem],
            ]);
        $cache
            ->expects($this->exactly(2))
            ->method('saveDeferred')
            ->withConsecutive(
                [$cacheItem1],
                [$cacheItem2]
            );
        $cache->expects($this->atLeastOnce())->method('commit');

        $reader = new CachedReader(new AnnotationReader(), $cache, true);

        touch(__DIR__ . '/Fixtures/ClassThatUsesTraitThatUsesAnotherTraitWithMethods.php', $cacheTime - 20);
        touch(__DIR__ . '/Fixtures/Traits/EmptyTrait.php', $cacheTime - 20);
        $this->assertEquals([$route1], $reader->getMethodAnnotations(new ReflectionMethod($className, 'method1')));

        // only filemtime changes, but not cleared => no change
        touch(__DIR__ . '/Fixtures/ClassThatUsesTraitThatUsesAnotherTrait.php', $cacheTime + 5);
        touch(__DIR__ . '/Fixtures/Traits/EmptyTrait.php', $cacheTime + 5);
        $this->assertEquals([$route2], $reader->getMethodAnnotations(new ReflectionMethod($className, 'method2')));

        $reader->clearLoadedAnnotations();
        $this->assertEquals([$route2], $reader->getMethodAnnotations(new ReflectionMethod($className, 'method2')));
    }

    protected function doTestCacheStale(string $className, int $lastCacheModification): CachedReader
    {
        $cacheKey = str_replace('\\', '_', $className);

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $cacheItem
            ->expects($this->any())
            ->method('get')
            ->willReturn([]); // Result was cached, but there was no annotation

        $timeCacheItem = $this->createMock(CacheItemInterface::class);
        $timeCacheItem
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $timeCacheItem
            ->expects($this->any())
            ->method('get')
            ->willReturn($lastCacheModification);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache
            ->expects($this->any())
            ->method('getItem')
            ->willReturnMap([
                [$cacheKey, $cacheItem],
                ['[C]' . $cacheKey, $timeCacheItem],
            ]);
        $cache
            ->expects($this->exactly(2))
            ->method('saveDeferred')
            ->withConsecutive([$cacheItem], [$timeCacheItem]);
        $cache->expects($this->once())->method('commit');

        $reader         = new CachedReader(new AnnotationReader(), $cache, true);
        $route          = new Route();
        $route->pattern = '/someprefix';

        self::assertEquals([$route], $reader->getClassAnnotations(new ReflectionClass($className)));

        return $reader;
    }

    protected function doTestCacheFresh(string $className, int $lastCacheModification): void
    {
        $cacheKey       = str_replace('\\', '_', $className);
        $route          = new Route();
        $route->pattern = '/someprefix';

        $cacheItem = $this->createMock(CacheItemInterface::class);
        $cacheItem
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $cacheItem
            ->expects($this->any())
            ->method('get')
            ->willReturn([$route]);

        $timeCacheItem = $this->createMock(CacheItemInterface::class);
        $timeCacheItem
            ->expects($this->any())
            ->method('isHit')
            ->willReturn(true);
        $timeCacheItem
            ->expects($this->any())
            ->method('get')
            ->willReturn($lastCacheModification);

        $cache = $this->createMock(CacheItemPoolInterface::class);
        $cache
            ->expects($this->any())
            ->method('getItem')
            ->willReturnMap([
                [$cacheKey, $cacheItem],
                ['[C]' . $cacheKey, $timeCacheItem],
            ]);
        $cache->expects(self::never())->method('save');
        $cache->expects(self::never())->method('commit');

        $reader = new CachedReader(new AnnotationReader(), $cache, true);

        $this->assertEquals([$route], $reader->getClassAnnotations(new ReflectionClass($className)));
    }

    protected function getReader(): Reader
    {
        $this->cache = new ArrayAdapter();

        return new CachedReader(new AnnotationReader(), $this->cache);
    }
}
