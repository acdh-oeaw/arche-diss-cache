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

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\exception\NotFound;

/**
 * Description of RepoCurl
 *
 * @author zozlak
 */
class RepoWrapperGuzzle implements RepoWrapperInterface {

    private array $guzzleOpts;
    private Client $client;
    private array $repos = [];

    public function __construct(array $guzzleOpts = []) {
        $this->guzzleOpts                                 = $guzzleOpts;
        $guzzleOpts['allow_redirects']                    ??= [];
        $guzzleOpts['allow_redirects']['track_redirects'] = true;
        $guzzleOpts['http_errors']                        = false;
        $this->client                                     = new Client($guzzleOpts);
    }

    public function getResourceById(string $id, ?SearchConfig $config = null): RepoResource {
        $resp = $this->client->send(new Request('head', $id));
        if ($resp->getStatusCode() !== 200) {
            throw new NotFound("$id can not be resolved (HTTP status code " . $resp->getStatusCode() . ")");
        }
        $redirects             = $resp->getHeader('X-Guzzle-Redirect-History');
        $url                   = end($redirects) ?: $id;
        $uri                   = preg_replace('`/metadata$`', '', $url);
        $baseUrl               = preg_replace('`[0-9]+$`', '', $uri);
        $this->repos[$baseUrl] ??= new Repo($baseUrl, $this->guzzleOpts);
        return $this->repos[$baseUrl]->getResourceById($uri, $config);
    }
}
