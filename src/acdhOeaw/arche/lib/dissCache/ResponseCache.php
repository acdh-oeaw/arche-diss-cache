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
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\SearchConfig;
use Psr\Log\LoggerInterface;

/**
 * Description of Cache
 *
 * @author zozlak
 */
class ResponseCache {

    const HASH_ALGO           = 'xxh128';
    const HARD_TTL_MULTIPLIER = 10;

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
    private int $ttlResource;
    private int $hardTtlResource;
    private int $ttlResponse;
    private SearchConfig $searchCfg;
    private LoggerInterface | null $log;
    private string $lastResponseKey;

    /**
     * 
     * @param callable $missHandler with the signature 
     *   `function(RepoResourceInterface $res): ResponseCacheItem`
     * @param array<RepoWrapperInterface> $repos
     */
    public function __construct(CacheInterface $cache, callable $missHandler,
                                int $ttlResource, int $ttlResponse,
                                array $repos, ?SearchConfig $config = null,
                                ?LoggerInterface $log = null,
                                ?int $hardTtlResource = null) {
        $this->cache           = $cache;
        $this->missHandler     = $missHandler;
        $this->ttlResource     = $ttlResource;
        $this->hardTtlResource = $hardTtlResource ?? $ttlResource * self::HARD_TTL_MULTIPLIER;
        $this->ttlResponse     = $ttlResponse;
        $this->repos           = $repos;
        $this->searchCfg       = $config ?? new SearchConfig();
        $this->log             = $log;
    }

    /**
     * 
     * @param string|object|array<mixed> $params
     */
    public function getResponse(string | object | array $params, string $resId): ResponseCacheItem {
        $now                   = time();
        $this->lastResponseKey = $this->hashParams($params, $resId);
        $res                   = null;
        $this->log?->info("Checking cache for resource $resId and response key $this->lastResponseKey");

        // first check if the resource exists in cache
        // this is needed to check the TTL on the resource level
        $resItem      = $this->cache->get($resId);
        $matchingRepo = false;
        if ($resItem !== false) {
            $resCacheCreation = (new DateTimeImmutable($resItem->created))->getTimestamp();
            $diffRes          = $now - $resCacheCreation;
            $this->log?->debug("Resource found in cache (diffRes $diffRes, resTtl $this->ttlResource)");
            // if resource in cache is too old, check the resource's modification date at source
            if ($diffRes >= $this->ttlResource) {
                $resLastMod = PHP_INT_MAX;
                foreach ($this->repos as $repo) {
                    try {
                        $resLastMod = $repo->getModificationTimestamp($resId);
                        if ($resLastMod < PHP_INT_MAX) {
                            $matchingRepo = $repo;
                            break;
                        }
                    } catch (NotFound) {
                        
                    }
                }
                $diffRes = $now - $resLastMod;
                if ($resLastMod > $resCacheCreation || $diffRes >= $this->hardTtlResource) {
                    // invalidate resource cache
                    $this->log?->debug("Invalidating resource's cache (resLastMod - resCacheCreation = " . ($resLastMod - $resCacheCreation) . ")");
                    $resItem = false;
                } else {
                    $this->log?->debug("Keeping resource's cache (resLastMod - resCacheCreation = " . ($resLastMod - $resCacheCreation) . ")");
                }
            }
            if ($resItem !== false) {
                // regenerate the response key using the canonical resource URI
                $res    = RepoResourceCacheItem::deserialize($resItem->value);
                $resUri = (string) $res->getUri();
                if ($resUri !== $resId) {
                    $this->lastResponseKey = $this->hashParams($params, (string) $resUri);
                    $this->log?->debug("Updating response key to $this->lastResponseKey");
                }

                $respItem = $this->cache->get($this->lastResponseKey);
                $respDiff = $respItem !== false ? $now - (new DateTimeImmutable($respItem->created))->getTimestamp() : 'not in cache';
                if ($respItem !== false && $respDiff < $this->ttlResponse) {
                    $respItem = ResponseCacheItem::deserialize($respItem->value);
                    if (!$respItem->file || file_exists($respItem->body)) {
                        $this->log?->info("Serving response from cache (respDiff $respDiff, respTtl $this->ttlResponse)");
                        return $respItem;
                    } else {
                        $this->log?->info("Regenerating response (missing file $respItem->body)");
                    }
                } else {
                    $this->log?->info("Regenerating response (respDiff $respDiff, respTtl $this->ttlResponse)");
                }
            }
        }
        // must be separate if as code block above may invalidate $resItem
        if (!$resItem) {
            $this->log?->debug("Fetching the resource");
            $res   = false;
            $repos = $matchingRepo ? [$matchingRepo] : $this->repos;
            foreach ($repos as $repo) {
                try {
                    $res = $repo->getResourceById($resId, $this->searchCfg);
                    break;
                } catch (NotFound) {
                    
                }
            }
            if (!$res) {
                throw new NotFound("Resource $resId can not be found", 400);
            }
            $resUri                = (string) $res->getUri();
            $this->lastResponseKey = $this->hashParams($params, (string) $resUri);
            $this->log?->debug("Updating response key to $this->lastResponseKey");
        }
        // finally generate the response
        $this->log?->info("Generating the response");
        try {
            $value = ($this->missHandler)($res, $params);
            $this->log?->info("Caching the response under a key $this->lastResponseKey");
            $this->cache->set([$this->lastResponseKey], $value->serialize(), null);
        } finally {
            if (!($res instanceof RepoResourceCacheItem)) {
                // so late to preserve any changes done to the resource metadata by the missHandler
                $this->log?->debug("Caching the resource");
                $this->cache->set($res->getIds(), RepoResourceCacheItem::serialize($res), null);
            }
        }

        return $value;
    }

    public function getLastResponseKey(): string | false {
        return isset($this->lastResponseKey) ? $this->lastResponseKey : false;
    }

    /**
     * Public only to simplify the testing.
     * 
     * @param string|object|array<mixed> $key
     * @return string
     */
    public function hashParams(string | object | array $key, string $resId): string {
        if (is_object($key)) {
            $key = get_object_vars($key);
        } elseif (!is_array($key)) {
            $key = [$key];
        }
        ksort($key);
        $key = (string) json_encode($key);
        $key = hash(self::HASH_ALGO, $key) . "_$resId";
        return $key;
    }

    public function pruneCacheForResource(string $resId): void {
        $resKeys = $this->cache->getKeys($resId);
        foreach ($resKeys as $key) {
            $this->cache->delete("%$key");
        }
    }
}
