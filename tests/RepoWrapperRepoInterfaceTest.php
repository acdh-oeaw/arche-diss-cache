<?php

/*
 * The MIT License
 *
 * Copyright 2026 zozlak.
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
use quickRdf\DatasetNode;
use quickRdf\DataFactory as DF;
use zozlak\RdfConstants as RDF;
use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\Schema;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of RepoWrapperRepoInterfaceTest
 *
 * @author zozlak
 */
class RepoWrapperRepoInterfaceTest extends \PHPUnit\Framework\TestCase {

    public function testGetModificationTimestamp(): void {
        $repo    = $this->createStub(RepoInterface::class);
        $wrapper = new RepoWrapperRepoInterface($repo, false);
        $this->assertEquals(PHP_INT_MAX, $wrapper->getModificationTimestamp(''));

        $sbj         = DF::namedNode('http://foo');
        $date        = new DateTimeImmutable();
        $dateLiteral = DF::literal($date->format(DateTimeImmutable::ISO8601), null, RDF::XSD_DATE_TIME);
        $modProp     = DF::namedNode('http://modData');
        $graph       = new DatasetNode($sbj);
        $graph->add(DF::quadNoSubject($modProp, $dateLiteral));
        $res         = $this->createStub(RepoResourceInterface::class);
        $res->method('getGraph')->willReturn($graph);
        $schema      = new Schema(['modificationDate' => (string) $modProp]);
        $repo->method('getSchema')->willReturn($schema);
        $repo->method('getResourceById')->willReturn($res);
        $wrapper     = new RepoWrapperRepoInterface($repo, true);
        $this->assertEquals($date->getTimestamp(), $wrapper->getModificationTimestamp('foo'));
    }
}
