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

use PDO;

/**
 * Description of CachePdoTest
 *
 * @author zozlak
 */
class CachePdoTest extends \PHPUnit\Framework\TestCase {

    public function setUp(): void {
        parent::setUp();
        foreach (glob(sys_get_temp_dir() . '/cachePdo_*') as $i) {
            unlink($i);
        }
    }

    public function testSetGet(): void {
        $cache = new CachePdo('sqlite::memory:', 'testSimple');

        $refData = 'baz';
        $keys    = ['foo', 'bar'];
        $id      = $cache->set($keys, $refData, null);
        $this->assertFalse($cache->get($id));
        $this->assertFalse($cache->get('someKey'));
        $this->assertFalse($cache->get(-123));
        foreach ($keys as $key) {
            $data = $cache->get($key);
            $this->assertInstanceOf(CacheItem::class, $data);
            $this->assertEquals($refData, $data->value);
            $this->assertEquals($id, $data->id);
        }
    }

    public function testAddKey(): void {
        $cache = new CachePdo('sqlite::memory:', 'testSimple');

        $refData = 'baz';
        $id      = $cache->set(['foo'], $refData, null);
        $data1   = $cache->get('foo');
        $this->assertInstanceOf(CacheItem::class, $data1);
        $this->assertEquals($refData, $data1->value);
        $this->assertEquals($id, $data1->id);
        $this->assertFalse($cache->get('bar'));

        $id2   = $cache->set(['bar'], $refData, $id);
        $this->assertEquals($id, $id2);
        $data2 = $cache->get('foo');
        $data3 = $cache->get('bar');
        $this->assertEquals($data1, $data2);
        $this->assertEquals($data1, $data3);
    }

    public function testTakeOverKey(): void {
        $cache = new CachePdo('sqlite::memory:', 'testSimple');

        $refData1 = 'data1';
        $refData2 = 'data2';
        $id1      = $cache->set(['foo', 'bar'], $refData1, null);
        $data1    = $cache->get('foo');
        $data2    = $cache->get('bar');
        $this->assertEquals($data1, $data2);
        $this->assertInstanceOf(CacheItem::class, $data1);
        $this->assertEquals($refData1, $data1->value);
        $this->assertEquals($id1, $data1->id);

        $id2   = $cache->set(['bar'], $refData2, null);
        $this->assertNotEquals($id2, $id1);
        $data1 = $cache->get('foo');
        $data2 = $cache->get('bar');
        $this->assertInstanceOf(CacheItem::class, $data1);
        $this->assertInstanceOf(CacheItem::class, $data2);
        $this->assertEquals($refData1, $data1->value);
        $this->assertEquals($refData2, $data2->value);
        $this->assertEquals($id1, $data1->id);
        $this->assertEquals($id2, $data2->id);
    }
    public function testUpdate(): void {
        $cache = new CachePdo('sqlite::memory:', 'testSimple');

        $refData1 = 'data1';
        $id1      = $cache->set(['foo'], $refData1, null);
        $data1    = $cache->get('foo');
        $this->assertInstanceOf(CacheItem::class, $data1);
        $this->assertEquals($refData1, $data1->value);
        $this->assertEquals($id1, $data1->id);
        
        sleep(1);
        
        $refData2 = 'data2';
        $id2      = $cache->set([], $refData2, $id1);
        $this->assertEquals($id1, $id2);
        $data2    = $cache->get('foo');
        $this->assertInstanceOf(CacheItem::class, $data2);
        $this->assertEquals($refData2, $data2->value);
        $this->assertEquals($id1, $data2->id);
        $this->assertGreaterThan($data1->created, $data2->created);
        
    }
}
