<?php

/*
 * The MIT License
 *
 * Copyright 2024 zozlak.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\arche\lib\dissCache;

use quickRdf\DataFactory;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\RepoWrapperInterface;
use zozlak\logging\Log;

/**
 * Description of ResponseCacheTest
 *
 * @author zozlak
 */
class ResponseCacheTest extends \PHPUnit\Framework\TestCase {

    static private RepoWrapperRepoInterface $repoWrapperRepo;
    static private RepoWrapperGuzzle $repoWrapperGuzzle;
    static private string $logPath;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$repoWrapperRepo   = new RepoWrapperRepoInterface(Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/'));
        self::$repoWrapperGuzzle = new RepoWrapperGuzzle();
        self::$logPath           = sys_get_temp_dir() . '/cachePdo_log';
    }

    private CachePdo $cache;
    private Log $log;

    /**
     * 
     * @var callable
     */
    private $missHandler;

    public function setUp(): void {
        parent::setUp();
        foreach (glob(sys_get_temp_dir() . '/cachePdo_*') as $i) {
            unlink($i);
        }
        $this->cache       = new CachePdo('sqlite::memory:', 'cache');
        $this->log         = new Log(self::$logPath, \Psr\Log\LogLevel::DEBUG, '{MESSAGE}');
        $this->missHandler = function (RepoResourceInterface $x, array $params): ResponseCacheItem {
            return new ResponseCacheItem((string) $x->getUri(), 302, $params, false);
        };
    }

    public function testSimple(): void {
        $respCache = $this->getResponseCache($this->missHandler);
        $resUrl    = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $params    = ['foo' => 'bar'];
        $refResp   = new ResponseCacheItem($resUrl, 302, $params, false);

        $t1    = microtime(true);
        $resp1 = $respCache->getResponse($params, $resUrl);
        $t1    = microtime(true) - $t1;
        $this->assertEquals($refResp, $resp1);

        $t2           = microtime(true);
        $resp2        = $respCache->getResponse($params, $resUrl);
        $refResp->hit = true;
        $this->assertEquals($refResp, $resp2);
        $t2           = microtime(true) - $t2;

        // second one should come from cache and be much faster
        $this->assertGreaterThan($t2 * 10, $t1);
    }

    public function testResourceModified(): void {
        $params     = ['foo' => 'bar'];
        $resUrl     = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $refResp    = new ResponseCacheItem($resUrl, 302, $params, false);
        $repoDouble = $this->createStub(RepoWrapperInterface::class);
        $repoDouble->method('getModificationTimestamp')->willReturn(time() + 10);
        $repoDouble->method('getResourceById')->willReturn($this->getResourceDouble($resUrl));

        $respCache  = $this->getResponseCache($this->missHandler, 0, 100, [$repoDouble]);
        $paramsHash = $respCache->hashParams($params, $resUrl);

        $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1 = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nInvalidating resource's cache (resLastMod - resCacheCreation = ";
        $refLog2 = "\nFetching the resource\nUpdating response key to 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nGenerating the response\nCaching the response under a key 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nCaching the resource\n";
        $resp    = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, file_get_contents(self::$logPath));
        $this->assertStringEndsWith($refLog2, file_get_contents(self::$logPath));
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }

    public function testResourceNotModified(): void {
        $params     = ['foo' => 'bar'];
        $resUrl     = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $refResp    = new ResponseCacheItem($resUrl, 302, $params, false);
        $repoDouble = $this->createStub(RepoWrapperInterface::class);
        $repoDouble->method('getModificationTimestamp')->willReturn(time() - 10);
        $repoDouble->method('getResourceById')->willReturn($this->getResourceDouble($resUrl));

        $respCache  = $this->getResponseCache($this->missHandler, 0, 100, [$repoDouble], hardTtlRes: 100);
        $paramsHash = $respCache->hashParams($params, $resUrl);

        $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1      = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nKeeping resource's cache (resLastMod - resCacheCreation = -";
        $refLog2      = "\nServing response from cache (respDiff 0, respTtl 100)\n";
        $resp         = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, file_get_contents(self::$logPath));
        $this->assertStringEndsWith($refLog2, file_get_contents(self::$logPath));
        $refResp->hit = true;
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }

    public function testResourceNotModifiedResponseExpired(): void {
        $params     = ['foo' => 'bar'];
        $resUrl     = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $refResp    = new ResponseCacheItem($resUrl, 302, $params, false);
        $repoDouble = $this->createStub(RepoWrapperInterface::class);
        $repoDouble->method('getModificationTimestamp')->willReturn(time() - 10);
        $repoDouble->method('getResourceById')->willReturn($this->getResourceDouble($resUrl));

        $respCache  = $this->getResponseCache($this->missHandler, 0, 0, [$repoDouble], hardTtlRes: 100);
        $paramsHash = $respCache->hashParams($params, $resUrl);

        $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nKeeping resource's cache (resLastMod - resCacheCreation = -10)\nRegenerating response (respDiff 0, respTtl 0)\nGenerating the response\nCaching the response under a key 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\n";
        $resp   = $respCache->getResponse($params, $resUrl);
        $this->assertEquals($refLog, file_get_contents(self::$logPath));
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }
    
    public function testResourceNotModifiedHardTtl(): void {
        $params     = ['foo' => 'bar'];
        $resUrl     = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $refResp    = new ResponseCacheItem($resUrl, 302, $params, false);
        $repoDouble = $this->createStub(RepoWrapperInterface::class);
        $repoDouble->method('getModificationTimestamp')->willReturn(time() - 10);
        $repoDouble->method('getResourceById')->willReturn($this->getResourceDouble($resUrl));

        $respCache  = $this->getResponseCache($this->missHandler, 0, 100, [$repoDouble], hardTtlRes: 0);
        $paramsHash = $respCache->hashParams($params, $resUrl);

        $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1      = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nInvalidating resource's cache (resLastMod - resCacheCreation = -";
        $refLog2      = "/Invalidating resource's cache [(]resLastMod - resCacheCreation = -[0-9]+, diffRes [0-9]+, resHardTtl 0[)]/m";
        $resp         = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, file_get_contents(self::$logPath));
        $this->assertMatchesRegularExpression($refLog2, file_get_contents(self::$logPath));
        $refResp->hit = false;
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }

    public function testIdsAddedByHandler(): void {
        $clbck = function (RepoResourceInterface $res, array $params): ResponseCacheItem {
            $idProp = $res->getRepo()->getSchema()->id;
            $res->getGraph()->add(DataFactory::quad($res->getUri(), $idProp, DataFactory::namedNode('http://new/id')));
            return new ResponseCacheItem('nothing', 200, [], false);
        };
        $resUrl    = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $repo      = new RepoWrapperGuzzle();
        $respCache = $this->getResponseCache($clbck, 0, 0, [$repo]);
        $resp      = $respCache->getResponse([], $resUrl);
        $this->assertEquals(new ResponseCacheItem('nothing', 200, [], false), $resp);

        $resCacheItem = $this->cache->get('http://new/id');
        $this->assertInstanceOf(CacheItem::class, $resCacheItem);
    }

    public function testMultipleResourceIds(): void {
        $respCache = $this->getResponseCache($this->missHandler);
        $resUrl1   = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $resUrl2   = 'https://id.acdh.oeaw.ac.at/acdh-schema';
        $params    = ['foo' => 'bar'];

        $t1    = microtime(true);
        $resp1 = $respCache->getResponse($params, $resUrl1);
        $t1    = microtime(true) - $t1;
        $this->assertEquals(new ResponseCacheItem($resUrl1, 302, $params, false), $resp1);

        $t2    = microtime(true);
        $resp2 = $respCache->getResponse($params, $resUrl2);
        $this->assertEquals(new ResponseCacheItem($resUrl1, 302, $params, true), $resp2);
        $t2    = microtime(true) - $t2;

        // second one should come from cache and be much faster
        $this->assertGreaterThan($t2 * 10, $t1);
    }

    public function testMissingResponseItemFile(): void {
        $resUrl      = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $filePath    = tempnam(sys_get_temp_dir(), '');
        $count       = 0;
        $missHandler = function (RepoResourceInterface $x, array $params) use ($filePath,
                                                                               &$count): ResponseCacheItem {
            $count++;
            file_put_contents($filePath, (string) $count);
            return new ResponseCacheItem($filePath, 302, $params, false, true);
        };
        $refResp   = new ResponseCacheItem($filePath, 302, [], false, true);
        $respCache = $this->getResponseCache($missHandler);

        $resp = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp, $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        $refResp->hit = true;
        $resp         = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp, $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        unlink($filePath);
        $refResp->hit = false;
        $resp         = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp, $resp);
        $this->assertEquals('2', file_get_contents($filePath));

        unlink($filePath);
    }

    /**
     * 
     * @param array<mixed> $params
     */
    private function checkFirstResponse(ResponseCache $respCache, array $params,
                                        string $resUrl,
                                        ResponseCacheItem $refResp): void {
        $paramsHash = $respCache->hashParams($params, $resUrl);
        $refLog     = "Checking cache for resource $resUrl and response key $paramsHash\nFetching the resource\nUpdating response key to 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nGenerating the response\nCaching the response under a key 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nCaching the resource\n";
        $resp       = $respCache->getResponse($params, $resUrl);
        $this->assertEquals($refLog, file_get_contents(self::$logPath));
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }

    /**
     * 
     * @param null|array<RepoWrapperInterface> $repos
     */
    private function getResponseCache(callable $missHandler, int $ttlRes = 1,
                                      int $ttlResp = 1, ?array $repos = null,
                                      ?SearchConfig $config = null,
                                      ?int $hardTtlRes = null): ResponseCache {
        $repos ??= [self::$repoWrapperRepo, self::$repoWrapperGuzzle];
        return new ResponseCache($this->cache, $missHandler, $ttlRes, $ttlResp, $repos, $config, $this->log, $hardTtlRes);
    }

    private function getResourceDouble(string $uri): RepoResourceInterface {
        $resDouble = $this->createStub(RepoResourceInterface::class);
        $resDouble->method('getUri')->willReturn(DataFactory::namedNode($uri));
        $resDouble->method('getIds')->willReturn([$uri]);
        return $resDouble;
    }
}
