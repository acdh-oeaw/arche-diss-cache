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

use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of ServiceTest
 *
 * @author zozlak
 */
class ServiceTest extends \PHPUnit\Framework\TestCase {

    public function setUp(): void {
        parent::setUp();

        foreach (array_merge(['/tmp/__db__', '/tmp/__log__'], glob('/tmp/cachePdo*')) as $i) {
            unlink($i);
        }
    }

    public function testService(): void {
        $clbck = function (RepoResourceInterface $res, array $param): ResponseCacheItem {
            return new ResponseCacheItem((string) $res->getUri(), 200, $param, false);
        };
        $service  = new Service(__DIR__ . '/config.yaml');
        $service->setCallback($clbck);
        $param    = ['foo', 3];
        $response = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $ref      = new ResponseCacheItem('https://arche.acdh.oeaw.ac.at/api/21003', 200, $param, false);
        $this->assertEquals($ref, $response);

        $response = $service->serveRequest('https://foo/bar', $param);
        $ref      = new ResponseCacheItem("Requested resource https://foo/bar not in allowed namespace\n", 400, [
            ], false);
        $this->assertEquals($ref, $response);

        $response = $service->serveRequest('', $param);
        $ref      = new ResponseCacheItem("Requested resource no identifer provided not in allowed namespace\n", 400, [
            ], false);
        $this->assertEquals($ref, $response);
    }
}
