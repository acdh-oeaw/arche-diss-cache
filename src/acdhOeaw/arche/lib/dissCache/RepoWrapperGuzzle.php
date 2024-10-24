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

use DateTimeImmutable;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\Repo;
use acdhOeaw\arche\lib\RepoResource;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\exception\NotFound;

/**
 * Description of RepoCurl
 *
 * @author zozlak
 */
class RepoWrapperGuzzle implements RepoWrapperInterface {

    private bool $checkModDate;
    /**
     * 
     * @var array<string, mixed>
     */
    private array $guzzleOpts;
    private Client $client;
    /**
     * 
     * @var array<Repo>
     */
    private array $repos = [];

    /**
     * 
     * @param bool $checkModificationDate
     * @param array<string, mixed> $guzzleOpts
     */
    public function __construct(bool $checkModificationDate = false,
                                array $guzzleOpts = []) {
        $this->checkModDate                               = $checkModificationDate;
        $this->guzzleOpts                                 = $guzzleOpts;
        $guzzleOpts['allow_redirects']                    ??= [];
        $guzzleOpts['allow_redirects']['track_redirects'] = true;
        $guzzleOpts['http_errors']                        = false;
        $this->client                                     = new Client($guzzleOpts);
    }

    public function getResourceById(string $id, ?SearchConfig $config = null): RepoResourceInterface {
        $uri  = $this->resolve($id);
        $repo = $this->getRepo($uri);
        return $repo->getResourceById($uri, $config);
    }

    public function getModificationTimestamp(string $id): int {
        if (!$this->checkModDate) {
            return PHP_INT_MAX;
        }
        
        $uri                        = $this->resolve($id);
        $repo                       = $this->getRepo($uri);
        $config                     = new SearchConfig();
        $config->metadataMode       = RepoResource::META_RESOURCE;
        $modDateProp                = $repo->getSchema()->modificationDate;
        $config->resourceProperties = [(string) $modDateProp];
        $res                        = $repo->getResourceById($uri, $config);
        $modDate                    = $res->getGraph()->getObjectValue(new PT($modDateProp));
        return (new DateTimeImmutable($modDate))->getTimestamp();
    }

    private function resolve(string $id): string {
        $resp = $this->client->send(new Request('head', $id));
        $code = $resp->getStatusCode();
        if ($code === 401) {
            throw new UnauthorizedException();
        } elseif ($code !== 200) {
            throw new NotFound("$id can not be resolved (HTTP status code $code)", $code);
        }
        $redirects = $resp->getHeader('X-Guzzle-Redirect-History');
        $url       = end($redirects) ?: $id;
        $uri       = preg_replace('`/metadata$`', '', $url);
        return $uri;
    }

    private function getRepo(string $uri): Repo {
        $baseUrl               = preg_replace('`[0-9]+$`', '', $uri);
        $this->repos[$baseUrl] ??= new Repo($baseUrl, $this->guzzleOpts);
        return $this->repos[$baseUrl];
    }
}
