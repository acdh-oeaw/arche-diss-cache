<?php

/*
 * The MIT License
 *
 * Copyright 2025 zozlak.
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
 * Description of FileCacheTest
 *
 * @author zozlak
 */
class FileCacheTest extends \PHPUnit\Framework\TestCase {

    const CACHE_DIR  = '/tmp/__cachefile__';
    const RES_BINARY = 'https://arche.acdh.oeaw.ac.at/api/1447709';
    const RES_META   = 'https://arche.acdh.oeaw.ac.at/api/1441225';
    const MB         = 1048576;

    public function setUp(): void {
        parent::setUp();

        mkdir(self::CACHE_DIR, 0700, true);
    }

    public function tearDown(): void {
        parent::tearDown();

        system('rm -fR "' . self::CACHE_DIR . '"');
    }

    public function testGetRefFilePathBinary(): void {
        $cache   = new FileCache(self::CACHE_DIR);
        $refPath = self::CACHE_DIR . '/' . hash('xxh128', self::RES_BINARY) . '/' . FileCache::REF_FILE_NAME;

        // no mime check
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY));
        $this->assertFileExists($refPath);
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY));

        // mime check - pass
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY, 'application/xml'));
        $this->assertFileExists($refPath);
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY, 'application/xml'));

        // wrong mime but served from cache
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY, 'foo/bar'));

        // mime check - fail
        unlink($refPath);
        try {
            $cache->getRefFilePath(self::RES_BINARY, 'foo/bar');
            $this->assertTrue(false);
        } catch (FileCacheException $e) {
            $this->assertEquals(FileCacheException::NO_BINARY, $e->getCode());
        }
        $this->assertFileDoesNotExist($refPath);
    }

    public function testGetRefFilePathMeta(): void {
        $cache   = new FileCache(self::CACHE_DIR);
        $refPath = self::CACHE_DIR . '/' . hash('xxh128', self::RES_META) . '/' . FileCache::REF_FILE_NAME;

        // no mime check
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_META));
        $this->assertFileExists($refPath);
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_META));

        // wrong mime but served from cache
        $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_META, 'foo/bar'));

        // mime check - fail
        unlink($refPath);
        try {
            $cache->getRefFilePath(self::RES_META, 'foo/bar');
            $this->assertTrue(false);
        } catch (FileCacheException $e) {
            $this->assertEquals(FileCacheException::NO_BINARY, $e->getCode());
        }
        $this->assertFileDoesNotExist($refPath);
    }

    public function testGetRefFilePathLocal(): void {
        $localCfg = [
            'https://arche.acdh.oeaw.ac.at/api/' => (object) [
                'dir'   => self::CACHE_DIR,
                'level' => 1
            ],
        ];
        $cache    = new FileCache(self::CACHE_DIR, null, $localCfg);
        $refPath  = sprintf('%s/%02d/%s', self::CACHE_DIR, ((int) basename(self::RES_BINARY)) % 100, basename(self::RES_BINARY));
        mkdir(dirname($refPath), 0700, true);

        // no corresponding local file
        foreach (['', 'application/xml', 'foo/bar'] as $mime) {
            try {
                $cache->getRefFilePath(self::RES_BINARY, $mime);
                $this->assertTrue(false, $mime);
            } catch (FileCacheException $e) {
                $this->assertEquals(FileCacheException::NO_BINARY, $e->getCode(), $mime ?? 'NULL');
            }
        }

        file_put_contents($refPath, 'FOO');
        // no corresponding local file
        foreach (['', 'application/xml', 'foo/bar'] as $mime) {
            $this->assertEquals($refPath, $cache->getRefFilePath(self::RES_BINARY, $mime ?? 'NULL'));
        }
    }

    public function testMintPath(): void {
        $cache = new FileCache(self::CACHE_DIR);

        $path = $cache->mintPath();
        $this->assertEquals(self::CACHE_DIR, dirname($path));
        $this->assertFileExists($path);

        $path = $cache->mintPath('foobar');
        $this->assertEquals(self::CACHE_DIR . '/foobar', $path);
        $this->assertFileExists($path);
    }

    public function testClean(): void {
        $cache = new FileCache(self::CACHE_DIR);

        file_put_contents(self::CACHE_DIR . '/1MB', str_repeat('0', self::MB));
        file_put_contents(self::CACHE_DIR . '/2MB', str_repeat('0', self::MB * 2));

        foreach ([FileCache::BY_SIZE, FileCache::BY_MOD_TIME] as $mode) {
            $cache->clean(3, $mode);
            $this->assertFileExists(self::CACHE_DIR . '/1MB', "mode: $mode");
            $this->assertFileExists(self::CACHE_DIR . '/2MB', "mode: $mode");
        }

        $cache->clean(1, FileCache::BY_SIZE);
        $this->assertFileExists(self::CACHE_DIR . '/1MB');
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/2MB');

        $cache->clean(0.5, FileCache::BY_SIZE);
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/1MB');
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/2MB');

        file_put_contents(self::CACHE_DIR . '/1MB', str_repeat('0', self::MB));
        sleep(1);
        file_put_contents(self::CACHE_DIR . '/2MB', str_repeat('0', self::MB * 2));
        $cache->clean(2, FileCache::BY_MOD_TIME);
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/1MB');
        $this->assertFileExists(self::CACHE_DIR . '/2MB');

        $cache->clean(0.5, FileCache::BY_MOD_TIME);
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/1MB');
        $this->assertFileDoesNotExist(self::CACHE_DIR . '/2MB');
    }
}
