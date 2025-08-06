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

use quickRdf\Dataset;
use quickRdf\DataFactory as DF;

/**
 * Description of RepoResourceCacheItemTest
 *
 * @author zozlak
 */
class RepoResourceCacheItemTest extends \PHPUnit\Framework\TestCase {

    /**
     * Checks if whole RDF graph is kept, even if it contains other nodes
     * 
     * @return void
     */
    public function testSerialize(): void {
        $resUri  = DF::namedNode('http://res');
        $res2Uri = DF::namedNode('http://res2');
        $prop    = DF::namedNode('http://prop');
        $res     = new RepoResourceCacheItem((string) $resUri, null);
        $dataset = new Dataset();
        $dataset->add([
            DF::quad($resUri, $prop, $res2Uri),
            DF::quad($res2Uri, $prop, DF::literal("foo")),
        ]);
        $res->setGraph($dataset);

        $res = RepoResourceCacheItem::deserialize(RepoResourceCacheItem::serialize($res));
        $this->assertTrue($dataset->equals($res->getGraph()->getDataset()));
    }

    public function testGetRepo(): void {
        $resUri = DF::namedNode('https://arche.acdh.oeaw.ac.at/api/1819726');
        $res    = new RepoResourceCacheItem((string) $resUri, null);
        $this->assertInstanceOf(\acdhOeaw\arche\lib\Repo::class, $res->getRepo());
    }
}
