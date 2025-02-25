<?php

/*
 * The MIT License
 *
 * Copyright 2019 Austrian Centre for Digital Humanities.
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

use BadMethodCallException;
use RuntimeException;
use DirectoryIterator;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

/**
 * Helper functions for managing files cache
 *
 * @author zozlak
 */
class FileCache {

    const BY_MOD_TIME   = 1;
    const BY_SIZE       = 2;
    const REF_FILE_NAME = 'ref';

    private string $dir;
    private LoggerInterface | null $log;

    /**
     * 
     * @var array<string, object>
     */
    private array $localAccess;

    /**
     * 
     * @param string $cacheDir
     * @param LoggerInterface|null $log
     * @param array<string, object> $localAccess
     */
    public function __construct(string $cacheDir,
                                LoggerInterface | null $log = null,
                                array $localAccess = []) {
        $this->dir         = $cacheDir;
        $this->log         = $log;
        $this->localAccess = $localAccess;
    }

    public function mintPath(string $filename = ''): string {
        if (empty($filename)) {
            return tempnam($this->dir, '');
        }

        $path = $this->dir . '/' . $filename;
        file_put_contents($path, '');
        chmod($path, 0600);
        return $path;
    }

    /**
     * Returns the path to the original repository resource.
     * 
     * Access the resource file directly if possible. If not, downloads it.
     * 
     * Be aware in case of a local access this method doesn't check the access rights.
     * 
     * @param string $expectedMime expected mime type. If the resource content is 
     *   downloaded and the download reports different mime type, an error is thrown. 
     *   Passing an empty value skips the check.
     * @param array<string, mixed> $guzzleOpts guzzle Client options to be used
     *   if a resource binary is to be downloaded. Allows passing e.g. credentials.
     */
    public function getRefFilePath(string $resUrl, string $expectedMime = '',
                                   array $guzzleOpts = []): string {
        // direct local access
        foreach ($this->localAccess as $nmsp => $nmspCfg) {
            if (str_starts_with($resUrl, $nmsp)) {
                $id     = (int) preg_replace('`^.*/`', '', $resUrl);
                $level  = $nmspCfg->level;
                $path   = $nmspCfg->dir;
                $idPart = $id;
                while ($level > 0) {
                    $path   .= sprintf('/%02d', $idPart % 100);
                    $idPart = (int) ($idPart / 100);
                    $level--;
                }
                $path .= '/' . $id;
                if (!file_exists($path)) {
                    throw new FileCacheException('Resource has no binary content', FileCacheException::NO_BINARY);
                }
                return $path;
            }
        }

        // cache access
        $path = $this->dir . '/' . hash('xxh128', $resUrl) . '/ref';
        if (!file_exists($path)) {
            $this->fetchResourceBinary($path, $resUrl, $expectedMime, $guzzleOpts);
        }
        return $path;
    }

    /**
     * Fetches original resource
     * 
     * @param string $expectedMime expected mime type. If the resource content is 
     *   downloaded and the download reports different mime type, an error is thrown. 
     *   Passing an empty value skips the check.
     * @param array<string, mixed> $guzzleOpts
     */
    private function fetchResourceBinary(string $path, string $resUrl,
                                         string $expectedMime,
                                         array $guzzleOpts = []): void {
        $this->log?->info("Downloading " . $resUrl);

        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $pathTmp = "$path.tmp";

        $guzzleOpts['stream']      = true;
        $guzzleOpts['http_errors'] = false;
        $client                    = new Client($guzzleOpts);
        $resp                      = $client->send(new Request('get', $resUrl));
        if ($resp->getStatusCode() !== 200) {
            throw new FileCacheException('No such file', FileCacheException::NO_FILE);
        }
        // mime mismatch is most probably redirect to metadata
        $realMime = $resp->getHeader('Content-Type')[0] ?? 'lacking content type';
        if (!empty($expectedMime) && $expectedMime !== $realMime) {
            $this->log?->error("Mime mismatch: downloaded $realMime, expected $expectedMime");
            throw new FileCacheException('The requested file misses binary content', FileCacheException::NO_BINARY);
        }
        $body  = $resp->getBody();
        $fout  = fopen($pathTmp, 'w') ?: throw new RuntimeException("Can't open $pathTmp for writing");
        $chunk = 10 ^ 6; // 1 MB
        while (!$body->eof()) {
            fwrite($fout, (string) $body->read($chunk));
        }
        fclose($fout);
        if (!file_exists($path)) {
            rename($pathTmp, $path);
        }
    }

    /**
     * Assures caches doesn't exceed a given size.
     * @param float $maxSizeMb maximum cache size in MB
     * @param int $mode which files should be removed first
     *   - `ClearCache::BY_MOD_TIME` - oldest
     *   - `ClearCache::BY_SIZE` - biggest
     * @throws BadMethodCallException
     */
    public function clean(float $maxSizeMb, int $mode): void {
        if (!in_array($mode, [self::BY_MOD_TIME, self::BY_SIZE])) {
            throw new BadMethodCallException('unknown mode parameter value');
        }

        // collect information on files in the cache
        $flags     = FilesystemIterator::CURRENT_AS_FILEINFO | FilesystemIterator::SKIP_DOTS;
        $dirIter   = new RecursiveDirectoryIterator($this->dir, $flags);
        $dirIter   = new RecursiveIteratorIterator($dirIter);
        $bySize    = [];
        $byModTime = [];
        $sizeSum   = 0;
        foreach ($dirIter as $i) {
            if ($i->isFile()) {
                $size                         = $i->getSize() / 1024 / 1024;
                $bySize[$i->getPathname()]    = $size;
                $byModTime[$i->getPathname()] = $i->getMTime();
                $sizeSum                      += $size;
            }
        }
        arsort($byModTime);
        asort($bySize);
        $this->log?->info("Total cache size $sizeSum, limit $maxSizeMb");

        // assure cache size limit
        if ($sizeSum > $maxSizeMb) {
            $files = array_keys($mode === self::BY_SIZE ? $bySize : $byModTime);
            while ($sizeSum > $maxSizeMb && count($files) > 0) {
                $file    = array_pop($files);
                $this->log?->info("removing $file");
                unlink((string) $file);
                $sizeSum -= $bySize[$file];
            }
        }

        // remove empty directories
        $dirIter = new DirectoryIterator($this->dir);
        foreach ($dirIter as $i) {
            if (!$i->isDot() && $i->isDir() && count(scandir($i->getPathname()) ?: [
]) === 2) {
                rmdir($i->getPathname());
            }
        }
    }
}
