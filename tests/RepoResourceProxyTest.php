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
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;

/**
 * Description of RepoResourceProxyTest
 *
 * @author zozlak
 */
class RepoResourceProxyTest extends \PHPUnit\Framework\TestCase {

    static private Repo $repo;

    static public function setUpBeforeClass(): void {
        parent::setUpBeforeClass();
        self::$repo = Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/');
    }

    public function testSerialize(): void {
        $res        = new RepoResource('https://arche.acdh.oeaw.ac.at/api/1238', self::$repo);
        $ref        = '{"uri":"https://arche.acdh.oeaw.ac.at/api/1238","metadata":"';
        $cacheValue = RepoResourceCacheItem::serialize($res);
        $this->assertStringStartsWith($ref, $cacheValue);
    }

    public function testDeserialize(): void {
        $id   = DF::namedNode('http://foo/bar');
        $data = '{
            "uri": "http://foo/bar",
            "metadata": "<http://foo/bar> <http://prop/id> <http://foo/bar> ."
        }';
        $res  = RepoResourceCacheItem::deserialize($data);
        $this->assertInstanceOf(RepoResourceCacheItem::class, $res);
        $this->assertTrue($id->equals($res->getUri()));
        $meta = $res->getGraph();
        $this->assertCount(1, $meta);
        $this->assertContains(DF::quad($id, DF::namedNode('http://prop/id'), $id), $meta);
    }

    public function testRoundtrip(): void {
        $resOrig = new RepoResource('https://arche.acdh.oeaw.ac.at/api/1238', self::$repo);

        $metaOrig   = $resOrig->getGraph();
        $cacheValue = RepoResourceCacheItem::serialize($resOrig);
        $resDes     = RepoResourceCacheItem::deserialize($cacheValue);
        $metaDes    = $resDes->getGraph();
        $this->assertTrue($resOrig->getUri()->equals($resDes->getUri()));
        $this->assertTrue($metaOrig->getGraph()->equals($metaDes->getGraph()));
    }
}
