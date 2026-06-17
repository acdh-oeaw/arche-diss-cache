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

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use quickRdf\DataFactory as DF;
use quickRdf\DatasetNode;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\exception\NotFound;
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
        unset($_SERVER['HTTP_' . self::ACL_TRUSTED_HEADER]);
        unset($_SERVER['HTTP_AUTHORIZATION']);
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
        $refResp = (new ResponseCacheItem($resUrl, 302, $params, false));
        $this->assertEquals($refResp->unify($resp1), $resp1);

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
        $refResp = (new ResponseCacheItem($filePath, 302, [], false, true, hash(ResponseCacheItem::ETAG_HASH, '1')));
        $this->assertEquals($refResp->unify($resp), $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        $resp = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp->withHit(true), $resp);
        $this->assertEquals('1', file_get_contents($filePath));

        unlink($filePath);
        $resp    = $respCache->getResponse([], $resUrl);
        $refResp = (new ResponseCacheItem($filePath, 302, [], false, true, hash(ResponseCacheItem::ETAG_HASH, '2')));
        $this->assertEquals($refResp->unify($resp), $resp);
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

        // public
        $respCache = $this->getAclResponseCache(self::ROLE_PUBLIC);
        $resp      = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->unify($resp), $resp);

        // academic
        $respCache = $this->getAclResponseCache(self::ROLE_ACADEMIC);
        try {
            $respCache->getResponse([], self::ACL_RES_URL);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
        $this->setTrustedHeader('someAcademicFolk');
        $resp = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->unify($resp), $resp);

        // restricted
        $respCache = $this->getAclResponseCache(self::ROLE_RESTRICTED);
        try {
            $respCache->getResponse([], self::ACL_RES_URL);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException | UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
        $this->setTrustedHeader(self::ROLE_RESTRICTED);
        $resp = $respCache->getResponse([], self::ACL_RES_URL);
        $this->assertEquals($refResp->unify($resp), $resp);
    }

    public function testAuthReal(): void {
        $respCache = $this->getResponseCache(authCfg: $this->getAuthCfg());

        $resUrl  = 'https://arche.acdh.oeaw.ac.at/api/585588'; // sample title image
        $refResp = (new ResponseCacheItem($resUrl, 302, [], false));
        $resp    = $respCache->getResponse([], $resUrl);
        $this->assertEquals($refResp->unify($resp), $resp);

        $resUrl  = 'https://id.acdh.oeaw.ac.at/MadraRiverDelta/Photos/10YT-96-33b.tif'; // academic
        $realUrl = 'https://arche.acdh.oeaw.ac.at/api/66635';
        $refResp = (new ResponseCacheItem($realUrl, 302, [], false));

        // no auth - exception
        try {
            $respCache->getResponse([], $resUrl);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException | UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }

        // with auth - pass
        $this->setTrustedHeader('trustedRole');
        $t1    = microtime(true);
        $resp1 = $respCache->getResponse([], $resUrl);
        $t1    = microtime(true) - $t1;
        $t2    = microtime(true);
        $resp2 = $respCache->getResponse([], $resUrl);
        $t2    = microtime(true) - $t2;
        $this->assertEquals($refResp->unify($resp1), $resp1);
        $this->assertEquals($refResp->unify($resp2)->withHit(true), $resp2);
        $this->assertLessThan($t1 / 2, $t2);
        // different user doesn't affect response caching
        $this->setTrustedHeader('anotherRole');
        $t3    = microtime(true);
        $resp3 = $respCache->getResponse([], $resUrl);
        $t3    = microtime(true) - $t3;
        $this->assertEquals($refResp->unify($resp3)->withHit(true), $resp3);
        $this->assertLessThan($t1 / 2, $t3);
        // no authentication fails no matter we cached reponse for authenticated user
        $this->setTrustedHeader('');
        try {
            $respCache->getResponse([], $resUrl);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException | UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
    }

    public function testAuthRealBasic(): void {
        $authCfg   = $this->getAuthCfgDouble(5);
        $respCache = $this->getResponseCache(authCfg: $authCfg);

        $resUrl  = 'https://id.acdh.oeaw.ac.at/dostal-nachlass/008/001-100/AT-OeAW-ISA-WD-008-079.tif'; // restricted
        $realUrl = 'https://arche.acdh.oeaw.ac.at/api/536551';
        $refResp = (new ResponseCacheItem($realUrl, 302, [], false));

        // academic is not enought
        $authCfg->trustedHeaderRole = 'someRole';
        try {
            $respCache->getResponse([], $resUrl);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException | UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }

        // admin trouhg trusted header works
        $authCfg->trustedHeaderRole = self::ROLE_ADMIN;
        $t1                         = microtime(true);
        $resp1                      = $respCache->getResponse([], $resUrl);
        $t1                         = microtime(true) - $t1;
        $t2                         = microtime(true);
        $resp2                      = $respCache->getResponse([], $resUrl);
        $t2                         = microtime(true) - $t2;
        $this->assertEquals($refResp->unify($resp1), $resp1);
        $this->assertEquals($refResp->unify($resp2)->withHit(true), $resp2);
        $this->assertLessThan($t1 / 2, $t2);

        // HTTP basic works and reuses the response cache
        $authCfg->trustedHeaderRole = '';
        $authCfg->userPswd = ['rmandell', 'password'];
        $authCfg->client   = $this->getClient(200, '{"groups": []}');
        $t3                         = microtime(true);
        $resp3                      = $respCache->getResponse([], $resUrl);
        $t3                         = microtime(true) - $t3;
        $this->assertEquals($refResp->unify($resp3)->withHit(true), $resp3);
        $this->assertLessThan($t1 / 2, $t2);
        
        // wrong HTTP basic auth returns ForbiddenException
        // (user change to revalidate the auth against the server)
        $authCfg->userPswd = ['otherUser', 'password'];
        $authCfg->client   = $this->getClient(403, '');
        try {
            $respCache->getResponse([], $resUrl);
            /* @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (ForbiddenException | UnauthorizedException) {
            /* @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
    }

    public function testGetClientRolesNoCache(): void {
        # disable auth data caching
        $authCfg = $this->getAuthCfgDouble(0);
        $cache   = $this->getResponseCache(authCfg: $authCfg);

        // no auth data - only public
        $this->assertEqualsCanonicalizing([self::ROLE_PUBLIC], $cache->getClientRoles(''));

        // HTTP basic
        $authCfg->userPswd = ['john', 'password'];
        $authCfg->client   = $this->getClient(200, '{"groups": ["public", "group"]}');
        $ref               = [self::ROLE_PUBLIC, 'john', 'group'];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // HTTP basic and trusted header
        $authCfg->trustedHeaderRole = 'trusted';
        $ref                        = [
            self::ROLE_PUBLIC, self::ROLE_ACADEMIC, 'trusted', 'john', 'group'
        ];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // only trusted header (HTTP basic without password should be skipped)
        $authCfg->userPswd = ['john', ''];
        $ref               = [
            self::ROLE_PUBLIC, self::ROLE_ACADEMIC, 'trusted'
        ];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // back to public-only
        $authCfg->trustedHeaderRole = '';
        $this->assertEqualsCanonicalizing([self::ROLE_PUBLIC], $cache->getClientRoles(''));
    }

    public function testGetClientRolesCache(): void {
        $authCfg = $this->getAuthCfgDouble(1);
        $cache   = $this->getResponseCache(authCfg: $authCfg);

        // trusted header is not cached cause checking it has no cost
        $authCfg->trustedHeaderRole = 'trusted';
        $ref                        = [self::ROLE_PUBLIC, 'academic', 'trusted'];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));
        $authCfg->trustedHeaderRole = 'other';
        $ref                        = [self::ROLE_PUBLIC, 'academic', 'other'];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // HTTP basic is cached, trusted header is not
        $authCfg->userPswd          = ['john', 'password'];
        $authCfg->client            = $this->getClient(200, '{"groups": ["group"]}');
        $ref                        = [
            self::ROLE_PUBLIC, 'john', 'group', 'other', self::ROLE_ACADEMIC
        ];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));
        $authCfg->trustedHeaderRole = 'trusted';
        $authCfg->client            = $this->getClient(200, '{"groups": ["otherGroup"]}');
        $ref                        = [
            self::ROLE_PUBLIC, 'john', 'group', 'trusted', self::ROLE_ACADEMIC
        ];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // HTTP basic cache expires
        sleep(1);
        $ref             = [
            self::ROLE_PUBLIC, 'john', 'otherGroup', 'trusted', self::ROLE_ACADEMIC
        ];
        $authCfg->client = $this->getClient(200, '{"groups": ["otherGroup"]}');
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));

        // password change also expires the cache
        $authCfg->client   = $this->getClient(200, '{"groups": ["expired"]}');
        $authCfg->userPswd = ['john', 'notMatchingPassword'];
        $ref               = [
            self::ROLE_PUBLIC, 'john', 'expired', 'trusted', self::ROLE_ACADEMIC
        ];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));
        
        // auth failure ends up with a public role
        $authCfg->trustedHeaderRole = '';
        $authCfg->client   = $this->getClient(403, '');
        $authCfg->userPswd = ['john', 'doesNotMatter'];
        $ref               = [self::ROLE_PUBLIC];
        $this->assertEqualsCanonicalizing($ref, $cache->getClientRoles(''));
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
        $refResp    = $refResp->unify($resp);
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

    private function getClient(int $responseCode, string $responseBody): Client {
        $stub = $this->createStub(Client::class);
        $stub->method('send')->willReturn(new Response($responseCode, [], $responseBody));
        return $stub;
    }

    private function getAuthCfg(): AuthConfig {
        return new AuthConfig(self::ACL_READ_PROPERTY, self::ROLE_PUBLIC, self::ROLE_ACADEMIC, self::ACL_TRUSTED_HEADER, self::ROLE_ADMIN);
    }

    private function getAuthCfgDouble(int $authTtl): AuthConfigDouble {
        return new AuthConfigDouble(self::ACL_READ_PROPERTY, self::ROLE_PUBLIC, self::ROLE_ACADEMIC, self::ACL_TRUSTED_HEADER, self::ROLE_ADMIN, authTtl: $authTtl);
    }

    private function setTrustedHeader(string $value): void {
        $header = 'HTTP_' . self::ACL_TRUSTED_HEADER;
        if (empty($value)) {
            unset($_SERVER[$header]);
        } else {
            $_SERVER[$header] = $value;
        }
    }
}
