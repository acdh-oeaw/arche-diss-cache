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

use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\SearchConfig;

/**
 * Description of Cache
 *
 * @author zozlak
 */
class ResponseCache {

    private CacheInterface $cache;

    /**
     * 
     * @var array<RepoWrapperInterface>
     */
    private array $repos;

    /**
     * 
     * @var callable
     */
    private $missHandler;
    private int | null $ttl;
    private SearchConfig $searchCfg;

    /**
     * 
     * @param callable $missHandler with the signature 
     *   `function(RepoResourceInterface $res): ResponseCacheItem`
     */
    public function __construct(CacheInterface $cache, callable $missHandler,
                                int $ttl) {
        $this->cache       = $cache;
        $this->missHandler = $missHandler;
        $this->ttl         = $ttl;
    }

    public function addRepo(RepoWrapperInterface $repo): self {
        $this->repos[] = $repo;
        return $this;
    }

    public function setSearchConfig(SearchConfig $config): self {
        $this->searchCfg = $config;
        return $this;
    }

    public function getResponse(string | object | array $params, string $resId): ResponseCacheItem {
        // first check full response cache
        $key      = $this->hashKey($params);
        $respItem = $this->cache->get($key);
        if ($respItem !== false) {
            return ResponseCacheItem::deserialize($respItem->value);
        }
        // then check resource cache
        $res     = null;
        $resItem = $this->cache->get($resId);
        if ($resItem !== false) {
            $res = RepoResourceCacheItem::deserialize($resItem->value);
        } else {
            // search in repositories as a last resort
            foreach ($this->repos as $repo) {
                try {
                    $res = $repo->getResourceById($resId, $this->searchCfg);
                } catch (NotFound) {
                    
                }
            }
            $this->cache->set($res->getIds(), RepoResourceCacheItem::serialize($res), null);
        }
        if ($res === null) {
            throw new NotFound();
        }
        // and generate the response based on a resource
        $value = ($this->missHandler)($res, $params);
        $this->cache->set([$key], $value->serialize(), null);

        return $value;
    }

    private function hashKey(string | object | array $key): string {
        if (is_object($key)) {
            $key = get_object_vars($key);
        }
        if (is_array($key)) {
            ksort($key);
            $key = json_encode($key);
            $key = hash('xxh128', $key);
        }
        return $key;
    }
}
