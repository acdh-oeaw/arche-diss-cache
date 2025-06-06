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

use GuzzleHttp\Client;

/**
 * Returns a Guzzle Client object with proxy settings applied from the environment
 * variables
 *
 * @author zozlak
 */
class ProxyClient {

    /**
     * 
     * @param array<string, mixed> $config
     */
    static public function factory(array $config = []): Client {
        $http  = (string) getenv('http_proxy');
        $https = (string) getenv('https_proxy');
        $no    = (string) getenv('no_proxy');
        if (!empty($http . $https . $no)) {
            $config['proxy'] = [
                'http'  => $http,
                'https' => $https,
                'no'    => explode(',', $no),
            ];
        }
        return new Client($config);
    }
}
