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

/**
 * Description of ResponseCacheItem
 *
 * @author zozlak
 */
class ResponseCacheItem {

    static public function deserialize(string $data): self {
        $data            = json_decode($data);
        $d               = new ResponseCacheItem();
        $d->body         = $data->body;
        $d->responseCode = $data->responseCode;
        $d->headers      = (array) $data->headers;
        $d->hit          = true;
        $d->file         = $data->file;
        return $d;
    }

    public string $body;
    public int $responseCode;

    /**
     * 
     * @var array<string, string|array<string>>
     */
    public array $headers;
    public bool $hit;
    public bool $file;

    /**
     * 
     * @param array<string, string|array<string>> $headers
     */
    public function __construct(string $body = '', int $responseCode = 0,
                                array $headers = [], bool $hit = false,
                                bool $file = false) {
        $this->body         = $body;
        $this->responseCode = $responseCode;
        $this->headers      = $headers;
        $this->hit          = $hit;
        $this->file         = $file;
    }

    public function serialize(): string {
        return (string) json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function send(bool $compress = false): void {
        http_response_code($this->responseCode);
        foreach ($this->headers as $header => $values) {
            $values = is_array($values) ? $values : [$values];
            foreach ($values as $i) {
                header("$header: $i");
            }
        }
        if ($compress && str_contains($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '', 'gzip') && $this->file) {
            header('Content-Encoding: gzip');
            echo gzencode($this->body);
        } elseif ($this->file) {
            readfile($this->body);
        } else {
            echo $this->body;
        }
    }
}
