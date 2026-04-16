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

use quickRdf\DataFactory as DF;
use quickRdf\Dataset;
use quickRdf\DatasetNode;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\dissCache\RepoWrapperInterface;
use zozlak\logging\Log;

/**
 * Description of ResponseCacheTest
 *
 * @author zozlak
 */
class ResponseCacheTest extends \PHPUnit\Framework\TestCase {

    const ACL_READ_PROPERTY  = 'https://vocabs.acdh.oeaw.ac.at/schema#aclRead';
    const ACL_RES_URL        = 'https://foo.bar/baz';
    const ACL_TRUSTED_HEADER = 'EPPN';
    const ROLE_PUBLIC        = 'public';
    const ROLE_ACADEMIC      = 'academic';
    const ROLE_RESTRICTED    = 'JohnDoe';
    const ROLE_ADMIN         = 'admin';

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
        foreach (glob(sys_get_temp_dir() . '/cachePdo_*') ?: [] as $i) {
            unlink($i);
        }
        $this->cache       = new CachePdo('sqlite::memory:', 'cache');
        $this->log         = new Log(self::$logPath, \Psr\Log\LogLevel::DEBUG, '{MESSAGE}');
        $this->missHandler = function (RepoResourceInterface $x, array $params): ResponseCacheItem {
            return new ResponseCacheItem((string) $x->getUri(), 302, $params, false);
        };
    }

    public function tearDown(): void {
        unset($_SERVER['HTTTP_' . self::ACL_TRUSTED_HEADER]);
        unset($_SERVER['HTTTP_AUTHORIZATION']);
    }

    public function testHashParams(): void {
        $respCache = $this->getResponseCache($this->missHandler);

        $hash1 = $respCache->hashParams('foo', 'id');
        $hash2 = $respCache->hashParams(['foo'], 'id');
        $this->assertEquals($hash2, $hash1);

        $param = ['foo' => 'bar'];
        $hash1 = $respCache->hashParams($param, 'id');
        $hash2 = $respCache->hashParams((object) $param, 'id');
        $this->assertEquals($hash2, $hash1);

        $hash1 = $respCache->hashParams(['a' => 1, 'b' => 2], 'id');
        $hash2 = $respCache->hashParams(['b' => 2, 'a' => 1], 'id');
        $this->assertEquals($hash2, $hash1);

        $hash1 = $respCache->hashParams('foo', 'id1');
        $hash2 = $respCache->hashParams('foo', 'id2');
        $this->assertNotEquals($hash2, $hash1);

        $hash1 = $respCache->hashParams(['a' => 1, 'b' => 2], 'id');
        $hash2 = $respCache->hashParams(['a' => 1, 'b' => '2'], 'id');
        $this->assertNotEquals($hash2, $hash1);
    }

    public function testSimple(): void {
        $respCache = $this->getResponseCache($this->missHandler);
        $resUrl    = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $params    = ['foo' => 'bar'];

        $t1      = microtime(true);
        $resp1   = $respCache->getResponse($params, $resUrl);
        $t1      = microtime(true) - $t1;
        $refResp = (new ResponseCacheItem($resUrl, 302, $params, false))->withLastModified($resp1->lastModified);
        $this->assertEquals($refResp, $resp1);

        $t2    = microtime(true);
        $resp2 = $respCache->getResponse($params, $resUrl);
        $this->assertEquals($refResp->withHit(true), $resp2);
        $t2    = microtime(true) - $t2;

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

        $refResp = $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1 = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nInvalidating resource's cache (resLastMod - resCacheCreation = ";
        $refLog2 = "\nFetching the resource\nUpdating response key to 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nGenerating the response\nCaching the response under a key 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nCaching the resource\n";
        $resp    = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, (string) file_get_contents(self::$logPath));
        $this->assertStringEndsWith($refLog2, (string) file_get_contents(self::$logPath));
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

        $refResp = $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1 = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nKeeping resource's cache (resLastMod - resCacheCreation = -";
        $refLog2 = "\nServing response from cache (respDiff 0, respTtl 100)\n";
        $resp    = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, (string) file_get_contents(self::$logPath));
        $this->assertStringEndsWith($refLog2, (string) file_get_contents(self::$logPath));
        $this->assertEquals($refResp->withHit(true), $resp);
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

        $refResp = $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

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

        $refResp = $this->checkFirstResponse($respCache, $params, $resUrl, $refResp);

        unlink(self::$logPath);
        $refLog1 = "Checking cache for resource $resUrl and response key $paramsHash\nResource found in cache (diffRes 0, resTtl 0)\nInvalidating resource's cache (resLastMod - resCacheCreation = -";
        $refLog2 = "/Invalidating resource's cache [(]resLastMod - resCacheCreation = -[0-9]+, diffRes [0-9]+, resHardTtl 0[)]/m";
        $resp    = $respCache->getResponse($params, $resUrl);
        $this->assertStringStartsWith($refLog1, (string) file_get_contents(self::$logPath));
        $this->assertMatchesRegularExpression($refLog2, (string) file_get_contents(self::$logPath));
        $this->assertEquals($refResp->withHit(false), $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
    }

    public function testIdsAddedByHandler(): void {
        $clbck = function (RepoResourceInterface $res, array $params): ResponseCacheItem {
            $idProp = $res->getRepo()->getSchema()->id;
            $res->getGraph()->add(DF::quad($res->getUri(), $idProp, DF::namedNode('http://new/id')));
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
        $respCache = $this->getResponseCache($missHandler);

        $resp    = $respCache->getResponse([], $resUrl);
        $refResp = (new ResponseCacheItem($filePath, 302, [], false, true, hash(ResponseCacheItem::ETAG_HASH, '1')))->withLastModified($resp->lastModified);
        $this->assertEquals($refResp, $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        $resp = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp->withHit(true), $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        unlink($filePath);
        $resp    = $respCache->getResponse([], $resUrl);
        $refResp = (new ResponseCacheItem($filePath, 302, [], false, true, hash(ResponseCacheItem::ETAG_HASH, '2')))->withLastModified($resp->lastModified);
        $this->assertEquals($refResp, $resp);
        $this->assertEquals('2', file_get_contents($filePath));

        unlink($filePath);
    }

    public function testNotFound(): void {
        $repo      = $this->createStub(RepoWrapperInterface::class);
        $repo->method('getResourceById')->willThrowException(new NotFound());
        $respCache = $this->getResponseCache($this->missHandler, repos: [$repo]);

        $this->expectException(NotFound::class);
        $respCache->getResponse([], 'https://arche.acdh.oeaw.ac.at/api/1234');
    }

    public function testAuthSimple(): void {
        $refResp = (new ResponseCacheItem(self::ACL_RES_URL, 302, [], false));
        $header  = 'HTTP_' . self::ACL_TRUSTED_HEADER;

        // public
        $respCache = $this->getAclResponseCache(self::ROLE_PUBLIC);
        $resp      = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->withLastModified($resp->lastModified), $resp);

        // academic
        $respCache = $this->getAclResponseCache(self::ROLE_ACADEMIC);
        try {
            $resp = $respCache->getResponse([], self::ACL_RES_URL);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
        $_SERVER[$header] = 'someAcademicFolk';
        $resp             = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->withLastModified($resp->lastModified), $resp);

        // restricted
        $respCache = $this->getAclResponseCache(self::ROLE_RESTRICTED);
        try {
            $resp = $respCache->getResponse([], self::ACL_RES_URL);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
        $_SERVER[$header] = self::ROLE_RESTRICTED;
        $resp             = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->withLastModified($resp->lastModified), $resp);
    }

    public function testAuthReal(): void {
        $respCache = $this->getResponseCache(authCfg: $this->getAuthCfg());

        $resUrl  = 'https://arche.acdh.oeaw.ac.at/api/585588'; // sample title image
        $refResp = (new ResponseCacheItem($resUrl, 302, [], false));
        $resp    = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp->withLastModified($resp->lastModified), $resp);

        $resUrl  = 'https://id.acdh.oeaw.ac.at/MadraRiverDelta/Photos/10YT-96-33b.tif'; // https://arche.acdh.oeaw.ac.at/api/66635
        $realUrl = 'https://arche.acdh.oeaw.ac.at/api/66635';
        $refResp = (new ResponseCacheItem($realUrl, 302, [], false));
        try {
            $resp = $respCache->getResponse([], $resUrl);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }

        $_SERVER['HTTP_AUTHORIZATION'] = 'basic ' . base64_encode('');

        $t1    = microtime(true);
        $resp1 = $respCache->getResponse([], $resUrl);
        $t1    = microtime(true) - $t1;
        $t2    = microtime(true);
        $resp2 = $respCache->getResponse([], $resUrl);
        $t2    = microtime(true) - $t2;
        $this->assertEquals($refResp->withLastModified($resp1->lastModified), $resp1);
        $this->assertEquals($refResp->withLastModified($resp1->lastModified)->withHit(true), $resp2);
        $this->assertLessThan($t1 / 2, $t2);

        //TODO test caching
    }

    /**
     * 
     * @param array<mixed> $params
     */
    private function checkFirstResponse(ResponseCache $respCache, array $params,
                                        string $resUrl,
                                        ResponseCacheItem $refResp): ResponseCacheItem {
        $paramsHash = $respCache->hashParams($params, $resUrl);
        $refLog     = "Checking cache for resource $resUrl and response key $paramsHash\nFetching the resource\nUpdating response key to 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nGenerating the response\nCaching the response under a key 58326945f389775660e59287a9843ad9_https://arche.acdh.oeaw.ac.at/api/1238\nCaching the resource\n";
        $resp       = $respCache->getResponse($params, $resUrl);
        $refResp    = $refResp->withLastModified($resp->lastModified);
        $this->assertEquals($refLog, file_get_contents(self::$logPath));
        $this->assertEquals($refResp, $resp);
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($resUrl));
        $this->assertInstanceOf(CacheItem::class, $this->cache->get($paramsHash));
        return $refResp;
    }

    /**
     * 
     * @param null|array<RepoWrapperInterface> $repos
     */
    private function getResponseCache(?callable $missHandler = null,
                                      int $ttlRes = 1, int $ttlResp = 1,
                                      ?array $repos = null,
                                      ?SearchConfig $config = null,
                                      ?int $hardTtlRes = null,
                                      ?AuthConfig $authCfg = null): ResponseCache {
        $missHandler ??= $this->missHandler;
        $repos       ??= [self::$repoWrapperRepo, self::$repoWrapperGuzzle];
        return new ResponseCache($this->cache, $missHandler, $ttlRes, $ttlResp, $repos, $config, $this->log, $hardTtlRes, $authCfg);
    }

    private function getResourceDouble(string $uri): RepoResourceInterface {
        $resDouble = $this->createStub(RepoResourceInterface::class);
        $resDouble->method('getUri')->willReturn(DF::namedNode($uri));
        $resDouble->method('getIds')->willReturn([$uri]);
        return $resDouble;
    }

    private function getAclResponseCache(string $acl): ResponseCache {
        static $n = 0;
        $n++;

        $resDouble = $this->getResourceDouble(self::ACL_RES_URL);
        $meta      = new DatasetNode(DF::namedNode(self::ACL_RES_URL));
        $meta->add(DF::quadNoSubject(DF::namedNode(self::ACL_READ_PROPERTY), DF::literal($acl)));
        /* @phpstan-ignore method.notFound */
        $resDouble->method('getGraph')->willReturn($meta);

        $repoDouble = $this->createStub(RepoWrapperInterface::class);
        $repoDouble->method('getModificationTimestamp')->willReturn(time() - 10);
        $repoDouble->method('getResourceById')->willReturn($resDouble);

        $cache = new CachePdo('sqlite::memory:', "cache_$n");
        return new ResponseCache($cache, $this->missHandler, 1, 1, [$repoDouble], authConfig: $this->getAuthCfg());
    }

    private function getAuthCfg(): AuthConfig {
        return new AuthConfig(self::ACL_READ_PROPERTY, self::ROLE_PUBLIC, self::ROLE_ACADEMIC, self::ACL_TRUSTED_HEADER, self::ROLE_ADMIN);
    }
}
