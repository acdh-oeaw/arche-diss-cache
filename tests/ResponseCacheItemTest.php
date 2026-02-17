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

/**
 * Description of ResponseCacheItemTest
 *
 * @author zozlak
 */
class ResponseCacheItemTest extends \PHPUnit\Framework\TestCase {

    public function setUp(): void {
        unset($_SERVER['HTTP_ACCEPT_ENCODING']);
    }

    public function testSendStringRaw(): void {
        $this->expectOutputString('foobarbaz');

        $item = new ResponseCacheItem('foo', 404, ['foo' => 'bar'], false, false);
        $item->send();
        $this->assertEquals(404, http_response_code());

        // header is present but send(false) disallows compression - falling back to identity
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
        $item                            = new ResponseCacheItem('bar', 200, [], true, false);
        $item->send(false);
        $this->assertEquals(200, http_response_code());

        // unsupported compression method so falling back to identity
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'dcz';
        $item                            = new ResponseCacheItem('baz', 201, [], true, false);
        $item->send(true);
        $this->assertEquals(201, http_response_code());
    }

    public function testSendStringGzip(): void {
        $this->expectOutputString(gzencode('baz', -1, ZLIB_ENCODING_GZIP));
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
        $item                            = new ResponseCacheItem('baz', 201, [], true, false);
        $item->send(true);
    }

    public function testSendStringDeflate(): void {
        $this->expectOutputString(gzencode('baz', -1, ZLIB_ENCODING_DEFLATE));
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip;q=0.2,deflate';
        $item                            = new ResponseCacheItem('baz', 201, [], true, false);
        $item->send();
    }

    public function testSendFileRaw(): void {
        $headers = [];
        $file    = file_get_contents(__FILE__);
        $this->expectOutputString("$file$file$file");

        $item = new ResponseCacheItem(__FILE__, 404, ['foo' => 'bar'], false, true);
        $item->send();
        $this->assertEquals(404, http_response_code());

        // header is present but send(false) disallows compression - falling back to identity
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
        $item                            = new ResponseCacheItem(__FILE__, 200, $headers, true, true);
        $item->send(false);
        $this->assertEquals(200, http_response_code());

        // unsupported compression method so falling back to identity
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'dcz';
        $item                            = new ResponseCacheItem(__FILE__, 201, $headers, true, true);
        $item->send(true);
        $this->assertEquals(201, http_response_code());
    }

    public function testSendFileGzip(): void {
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip';
        $headers                         = [];
        $item                            = new ResponseCacheItem(__FILE__, 200, $headers, true, true);
        $this->expectOutputString($this->getCompressed(ZLIB_ENCODING_GZIP));
        $item->send(true);
        $this->assertEquals(200, http_response_code());
    }

    public function testSendFileDeflate(): void {
        $_SERVER['HTTP_ACCEPT_ENCODING'] = 'gzip;q=0.2,deflate';
        $headers                         = [];
        $item                            = new ResponseCacheItem(__FILE__, 400, $headers, false, true);
        $this->expectOutputString($this->getCompressed(ZLIB_ENCODING_DEFLATE));
        $item->send(true);
        $this->assertEquals(400, http_response_code());
    }

    private function getCompressed(int $encoding): string {
        $encoder = deflate_init($encoding);
        $file    = fopen(__FILE__, 'r');
        $output  = '';
        while (!feof($file)) {
            $chunk  = fread($file, ResponseCacheItem::OUTPUT_CHUNK);
            $output .= deflate_add($encoder, $chunk, ZLIB_BLOCK);
        }
        $output .= deflate_add($encoder, '', ZLIB_FINISH);
        fclose($file);
        return $output;
    }
}
