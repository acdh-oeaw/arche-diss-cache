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

use RuntimeException;
use zozlak\httpAccept\Accept;
use zozlak\httpAccept\NoMatchException;

/**
 * Description of ResponseCacheItem
 *
 * @author zozlak
 */
class ResponseCacheItem {

    const OUTPUT_CHUNK = 1048576; // 1 MB
    const ETAG_HASH    = 'xxh128';

    static public function deserialize(string $data): self {
        $data = json_decode($data);
        $d    = new ResponseCacheItem($data->body, $data->responseCode, (array) $data->headers, true, $data->file, $data->etag);
#        $d->auth         = $data->auth;
        return $d;
    }

    public readonly string $etag;

    /**
     * 
     * @param array<string, string|array<string>> $headers
     */
    public function __construct(readonly string $body = '',
                                readonly int $responseCode = 0,
                                public array $headers = [],
                                readonly bool $hit = false,
                                readonly bool $file = false, string $etag = '') {
        if (!empty($etag)) {
            $this->etag = $etag;
        } elseif ($file) {
            $this->etag = $this->getFileHash();
        } else {
            $this->etag = hash(self::ETAG_HASH, $body);
        }
#        $this->auth         = $auth;
    }

    public function serialize(): string {
        return (string) json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function send(bool $compress = true): void {
        http_response_code($this->responseCode);
        foreach ($this->headers as $header => $values) {
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $i) {
                header("$header: $i");
            }
        }
        header('ETag: "' . $this->etag . '"');
        if ($compress) {
            $encoding = $_SERVER['HTTP_ACCEPT_ENCODING'] ?? 'identity';
            $encoding = new Accept($encoding);
            try {
                $encoding = $encoding->getBestMatch(['gzip', 'deflate'])->type;
                $this->sendCompressed($encoding);
                return;
            } catch (NoMatchException) {
                
            }
        }

        if ($this->file) {
            readfile($this->body);
        } else {
            echo $this->body;
        }
    }

    /**
     * Helper for tests
     */
    public function withHit(bool $hit): self {
        $ret = new self($this->body, $this->responseCode, $this->headers, $hit, $this->file, $this->etag);
        return $ret;
    }

    private function sendCompressed(string $encoding): void {
        header("Content-Encoding: $encoding");
        $encoding = match ($encoding) {
            'gzip' => ZLIB_ENCODING_GZIP,
            'deflate' => ZLIB_ENCODING_DEFLATE,
            default => throw new \BadMethodCallException('Unsupported encoding'),
        };
        if (!$this->file) {
            echo gzencode($this->body, -1, $encoding);
        } else {
            $file = fopen($this->body, 'r');
            if ($file === false) {
                throw new ServiceException("Can't open output file", 500);
            }
            $encoder = deflate_init($encoding);
            if ($encoder === false) {
                throw new ServiceException("Can't initialize output encoder", 500);
            }
            while (!feof($file)) {
                $chunk = (string) fread($file, self::OUTPUT_CHUNK);
                echo deflate_add($encoder, $chunk, ZLIB_BLOCK);
            }
            echo deflate_add($encoder, '', ZLIB_FINISH);
            fclose($file);
        }
    }

    private function getFileHash(): string {
        $file = fopen($this->body, 'r');
        if ($file === false) {
            throw new FileCacheException("Can't open file " . $this->body);
        }
        $hash = hash_init(self::ETAG_HASH);
        while (!feof($file)) {
            hash_update($hash, (string) fread($file, self::OUTPUT_CHUNK));
        }
        return hash_final($hash);
    }
}
