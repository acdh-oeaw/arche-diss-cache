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

use DateTimeImmutable;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\exception\NotFound;

/**
 * Description of RepoGuzzleTest
 *
 * @author zozlak
 */
class RepoWrapperGuzzleTest extends \PHPUnit\Framework\TestCase {

    public function testDirect(): void {
        $repo = new RepoWrapperGuzzle();
        $res  = $repo->getResourceById('https://arche.acdh.oeaw.ac.at/api/1238/metadata');
        $this->assertInstanceOf(RepoResource::class, $res);
    }

    public function testResolver(): void {
        $repo = new RepoWrapperGuzzle();

        $res1 = $repo->getResourceById('https://id.acdh.oeaw.ac.at/acdh-schema');
        $this->assertInstanceOf(RepoResource::class, $res1);

        $res2 = $repo->getResourceById('https://hdl.handle.net/21.11115/0000-0011-0DBA-E');
        $this->assertInstanceOf(RepoResource::class, $res2);

        $this->assertEquals((string) $res1->getUri(), (string) $res2->getUri());
    }

    public function testNotFound(): void {
        $repo = new RepoWrapperGuzzle();
        try {
            $res = $repo->getResourceById('https://arche.acdh.oeaw.ac.at/api/0');
            /** @phpstan-ignore method.impossibleType */
            $this->assertTrue(false);
        } catch (NotFound) {
            /** @phpstan-ignore method.alreadyNarrowedType */
            $this->assertTrue(true);
        }
    }

    public function testGetModificationTimestamp(): void {
        $wrapper = new RepoWrapperGuzzle();
        $this->assertEquals(PHP_INT_MAX, $wrapper->getModificationTimestamp(''));

        $uri     = 'https://arche.acdh.oeaw.ac.at/api/19641';
        $refRepo = Repo::factoryFromUrl('https://arche.acdh.oeaw.ac.at/api/');
        $modProp = $refRepo->getSchema()->modificationDate;
        $refRes  = $refRepo->getResourceById($uri);
        $refTime = $refRes->getGraph()->getObjectValue(new PT($modProp));
        $refTime = (new DateTimeImmutable($refTime))->getTimestamp();
        $wrapper = new RepoWrapperGuzzle(true, []);
        $this->assertEquals($refTime, $wrapper->getModificationTimestamp($uri));
    }
}
