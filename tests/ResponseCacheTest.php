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

use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of ResponseCacheTest
 *
 * @author zozlak
 */
class ResponseCacheTest extends \PHPUnit\Framework\TestCase {

    static private RepoWrapperRepoInterface $repoWrapperRepo;
    static private RepoWrapperGuzzle $repoWrapperGuzzle;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();

        self::$repoWrapperRepo   = new RepoWrapperRepoInterface(Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/'));
        self::$repoWrapperGuzzle = new RepoWrapperGuzzle();
    }

    private CachePdo $cache;

    public function setUp(): void {
        parent::setUp();
        foreach (glob(sys_get_temp_dir() . '/cachePdo_*') as $i) {
            unlink($i);
        }
        $this->cache = new CachePdo('sqlite::memory:', 'testSimple');
    }

    public function testSimple(): void {
        $missHandler = function (RepoResourceInterface $x, array $params): ResponseCacheItem {
            return new ResponseCacheItem((string) $x->getUri(), 302, $params, false);
        };
        $respCache = $this->getResponseCache($missHandler);

        $resUrl = 'https://arche.acdh.oeaw.ac.at/api/1238';
        $params = ['foo' => 'bar'];
        $t1     = microtime(true);
        $resp1  = $respCache->getResponse($params, $resUrl);
        $t1     = microtime(true) - $t1;
        $this->assertEquals(new ResponseCacheItem($resUrl, 302, $params, false), $resp1);

        $t2    = microtime(true);
        $resp2 = $respCache->getResponse($params, $resUrl);
        $this->assertEquals(new ResponseCacheItem($resUrl, 302, $params, true), $resp2);
        $t2    = microtime(true) - $t2;

        // second one should come from cache and be much faster
        $this->assertGreaterThan($t2 * 10, $t1);
    }

    private function getResponseCache(callable $missHandler, int $ttl = 1,
                                      ?SearchConfig $config = null): ResponseCache {
        return (new ResponseCache($this->cache, $missHandler, $ttl))->
                addRepo(self::$repoWrapperRepo)->
                addRepo(self::$repoWrapperGuzzle)->
                setSearchConfig($config ?? new SearchConfig());
    }
}
