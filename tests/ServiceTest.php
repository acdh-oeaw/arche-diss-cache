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

use DateTime;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of ServiceTest
 *
 * @author zozlak
 */
class ServiceTest extends \PHPUnit\Framework\TestCase {

    public function setUp(): void {
        parent::setUp();

        $toClean = array_merge(
            glob('/tmp/__log__') ?: [],
            glob('/tmp/cachePdo*') ?: []
        );
        foreach ($toClean as $i) {
            unlink($i);
        }

        unset($_SERVER['HTTP_CACHE_CONTROL']);
        unset($_GET['noCache']);
    }

    public function testGetNoCache(): void {
        $this->assertFalse(Service::getNoCache());

        $_SERVER['HTTP_CACHE_CONTROL'] = 'no-cache';
        $this->assertTrue(Service::getNoCache());
        $this->assertFalse(Service::getNoCache(false));

        $_SERVER['HTTP_CACHE_CONTROL'] = 'max-age=0';
        $this->assertTrue(Service::getNoCache());
        $this->assertFalse(Service::getNoCache(false));

        $_SERVER['HTTP_CACHE_CONTROL'] = 'no-cache, max-age=1000';
        $this->assertTrue(Service::getNoCache(true));
        $this->assertFalse(Service::getNoCache(false));

        $_SERVER['HTTP_CACHE_CONTROL'] = 'no-store, no-transform';
        $_GET['foo']                   = '';
        $this->assertTrue(Service::getNoCache(true, 'foo'));
        $this->assertFalse(Service::getNoCache());

        $_GET['noCache'] = '';
        $this->assertTrue(Service::getNoCache());
    }

    public function testService(): void {
        $clbck = function (RepoResourceInterface $res, array $param): ResponseCacheItem {
            return new ResponseCacheItem((string) $res->getUri(), 200, $param, false);
        };
        $service = new Service(__DIR__ . '/config.yaml');

        $this->assertInstanceOf(\zozlak\logging\Log::class, $service->getLog());
        $this->assertEquals(json_decode((string) json_encode(yaml_parse_file(__DIR__ . '/config.yaml'))), $service->getConfig());

        $service->setCallback($clbck);
        $param    = ['foo' => 'bar', 'baz' => '3'];
        $response = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $headers  = array_merge($param, ['Cache-Control' => 'max-age=3600, must-revalidate, immutable']);
        $ref      = new ResponseCacheItem('https://arche.acdh.oeaw.ac.at/api/21003', 200, $headers, false);
        $this->assertEquals($this->unify($ref, $response), $response);

        $response = $service->serveRequest('https://foo/bar', $param);
        $headers  = ['Cache-Control' => 'no-cache'];
        $ref      = new ResponseCacheItem("Requested resource https://foo/bar not in allowed namespace\n", 400, $headers, false);
        $this->assertEquals($this->unify($ref, $response), $response);

        $response = $service->serveRequest('', $param);
        $ref      = new ResponseCacheItem("Requested resource no identifer provided not in allowed namespace\n", 400, $headers, false);
        $this->assertEquals($this->unify($ref, $response), $response);
    }

    public function testCacheError(): void {
        $clbck = function (RepoResourceInterface $res, array $params): ResponseCacheItem {
            throw new ServiceException('foo', 456, null, ['custom' => 'header']);
        };
        $service    = new Service(__DIR__ . '/config.yaml');
        $service->setCallback($clbck);
        $headersRef = [
            'custom'        => 'header',
            'Cache-Control' => 'max-age=3600, must-revalidate, immutable',
        ];
        $param      = [];

        $t0      = microtime(true);
        $resp1   = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $t1      = microtime(true);
        $resp2   = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $t2      = microtime(true);
        $respRef = new ResponseCacheItem("foo\n", 456, $headersRef, false);
        $this->assertEquals($this->unify($respRef, $resp1), $resp1);
        $this->assertEquals($this->unify($respRef->withHit(true), $resp2), $resp2);
        // second one should come from cache and be much faster
        $t2      -= $t1;
        $t1      -= $t0;
        $this->assertGreaterThan($t2 * 10, $t1);
    }

    public function testClearCache(): void {
        $param   = [];
        $headers = ['Cache-Control' => 'max-age=3600, must-revalidate, immutable'];
        $clbck   = function (RepoResourceInterface $res, array $param): ResponseCacheItem {
            return new ResponseCacheItem((string) $res->getUri(), 200, $param, false);
        };
        $service = new Service(__DIR__ . '/config.yaml');
        $service->setCallback($clbck);
        $t0      = microtime(true);
        $resp1   = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $t1      = microtime(true);
        $resp2   = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $t2      = microtime(true);
        $resp3   = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param, true);
        $t3      = microtime(true);

        $t3       -= $t2;
        $t2       -= $t1;
        $t1       -= $t0;
        $refResp  = new ResponseCacheItem('https://arche.acdh.oeaw.ac.at/api/21003', 200, $headers, false);
        $this->assertEquals($this->unify($refResp, $resp1), $resp1);
        $this->assertEquals($this->unify($refResp, $resp3), $resp3);
        $this->assertEquals($this->unify($refResp->withHit(true), $resp2), $resp2);
        $this->assertGreaterThan($t2 * 10, $t1);
        $this->assertGreaterThan($t2 * 10, $t3);
        $lastMod1 = DateTime::createFromFormat(DateTime::RFC1123, $resp1->lastModified);
        $lastMod3 = DateTime::createFromFormat(DateTime::RFC1123, $resp3->lastModified);
        $this->assertNotFalse($lastMod1);
        $this->assertNotFalse($lastMod3);
        $this->assertGreaterThanOrEqual(0, $lastMod3->diff($lastMod1)->s);
    }

    public function testTtl(): void {
        $clbck = function (RepoResourceInterface $res, array $param): ResponseCacheItem {
            return new ResponseCacheItem((string) $res->getUri(), 200, $param, false);
        };
        $service  = new Service(__DIR__ . '/config.yaml');
        $service->setCallback($clbck);
        /** @phpstan-ignore property.notFound */
        $config   = $service->getConfig()->dissCacheService?->ttl;
        $param    = ['foo' => 'bar', 'baz' => '3'];
        $response = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $refTtl   = min($config->resource, $config->response);
        $this->assertEquals($refTtl, $response->getTtl($config->resource, $config->response));

        sleep(1);
        $response = $service->serveRequest('https://id.acdh.oeaw.ac.at/oeaw', $param);
        $this->assertEquals($refTtl - 1, $response->getTtl($config->resource, $config->response));
    }

    private function unify(ResponseCacheItem $ref, ResponseCacheItem $resp): ResponseCacheItem {
        return new ResponseCacheItem(
            $ref->body,
            $ref->responseCode,
            $ref->headers,
            $ref->hit,
            $ref->file,
            $ref->etag,
            $resp->lastModified,
            $resp->responseTimestamp,
            $resp->resourceTimestamp
        );
    }
}
