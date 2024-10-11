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
        return $d;
    }

    public string $body;
    public int $responseCode;
    public array $headers;
    public bool $hit;

    public function __construct(string $body = '', int $responseCode = 0,
                                array $headers = [], bool $hit = false) {
        $this->body         = $body;
        $this->responseCode = $responseCode;
        $this->headers      = $headers;
        $this->hit          = $hit;
    }

    public function serialize(): string {
        return json_encode($this, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}