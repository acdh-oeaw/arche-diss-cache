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

use Psr\Log\LoggerInterface;
use GuzzleHttp\Psr7\Request;
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\exception\NotFound;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of Cache
 *
 * @author zozlak
 */
class ResponseCache implements CallbackContextInterface {

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
    private AuthConfig | null $authConfig = null;
    private FileCache | null $fileCache   = null;
    private int $ttlResponse;
    private SearchConfig $searchCfg;
    private LoggerInterface | null $log;
    private string $lastResponseKey;
    private bool $noCache;

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
                                ?int $hardTtlResource = null,
                                ?AuthConfig $authConfig = null,
                                ?FileCache $fileCache = null) {
        $this->cache           = $cache;
        $this->missHandler     = $missHandler;
        $this->ttlResource     = $ttlResource;
        $this->hardTtlResource = $hardTtlResource ?? $ttlResource * self::HARD_TTL_MULTIPLIER;
        $this->authConfig      = $authConfig;
        $this->fileCache       = $fileCache;
        $this->ttlResponse     = $ttlResponse;
        $this->repos           = $repos;
        $this->searchCfg       = $config ?? new SearchConfig();
        $this->log             = $log;
    }

    /**
     * 
     * @param string|object|array<mixed> $params
     */
    public function getResponse(string | object | array $params, string $resId,
                                bool $noCache = false): ResponseCacheItem {
        $now                   = time();
        $this->lastResponseKey = $this->hashParams($params, $resId);
        $res                   = null;
        $this->log?->info("Checking cache for resource $resId and response key $this->lastResponseKey");

        $matchingRepo = false;
        $resItem      = false;
        if (!$noCache) {
            // first check if the resource exists in cache
            // this is needed to check the TTL on the resource level
            $resItem = $this->cache->get($resId);
        }
        if ($resItem !== false) {
            $diffRes = $resItem->getAge();
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
                $resCacheCreation = $resItem->getCreatedTimestamp();
                if ($resLastMod > $resCacheCreation || $diffRes >= $this->hardTtlResource) {
                    // invalidate resource cache
                    $this->log?->debug("Invalidating resource's cache (resLastMod - resCacheCreation = " . ($resLastMod - $resCacheCreation) . ", diffRes $diffRes, resHardTtl $this->hardTtlResource)");
                    $resItem = false;
                } else {
                    $this->log?->debug("Keeping resource's cache (resLastMod - resCacheCreation = " . ($resLastMod - $resCacheCreation) . ")");
                }
            }
            if ($resItem !== false) {
                // regenerate the response key using the canonical resource URI
                $res    = RepoResourceCacheItem::deserialize($resItem->value, $resItem->created);
                $resUri = (string) $res->getUri();
                if ($resUri !== $resId) {
                    $this->lastResponseKey = $this->hashParams($params, (string) $resUri);
                    $this->log?->debug("Updating response key to $this->lastResponseKey");
                }

                $this->checkAuth($res);

                $respItem = $this->cache->get($this->lastResponseKey);
                $respDiff = $respItem !== false ? $respItem->getAge() : 'not in cache';
                if ($respItem !== false && $respDiff < $this->ttlResponse) {
                    $respItem = ResponseCacheItem::deserialize($respItem->value, $respItem->created, $res->cacheTimestamp);
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

            $this->checkAuth($res);

            $resUri                = (string) $res->getUri();
            $this->lastResponseKey = $this->hashParams($params, (string) $resUri);
            $this->log?->debug("Updating response key to $this->lastResponseKey");
        }
        // finally generate the response
        $this->log?->info("Generating the response");
        try {
            $this->noCache = $noCache;
            $value         = ($this->missHandler)($res, $params, $this);
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

    public function getLog(): LoggerInterface | null {
        return $this->log;
    }

    public function getFileCache(): FileCache {
        if ($this->fileCache === null) {
            throw new FileCacheException('FileCache object not initialized - check the service configuration.');
        }
        return $this->fileCache;
    }

    public function getNoCache(): bool {
        return $this->noCache;
    }

    private function checkAuth(RepoResourceInterface $res): void {
        $authCfg = $this->authConfig;
        if ($authCfg === null) {
            return;
        }

        $tmpl  = new PT($authCfg->aclReadProperty);
        $roles = $res->getGraph()->listObjects($tmpl)->getValues();
        if (!empty($authCfg->publicRole) && in_array($authCfg->publicRole, $roles)) {
            return;
        }

        $repoBaseUrl = (string) preg_replace('/[0-9]+$/', '', (string) $res->getUri());
        $clientRoles = $this->getClientRoles($repoBaseUrl);
        if (count($clientRoles) === 0) {
            throw new UnauthorizedException();
        }

        if (!empty($authCfg->adminRole) && in_array($authCfg->adminRole, $clientRoles)) {
            return;
        }
        if (count(array_intersect($clientRoles, $roles)) > 0) {
            return;
        }

        throw new ForbiddenException();
    }

    /**
     * @return array<string>
     */
    public function getClientRoles(string $repoBaseUrl): array {
        $authCfg = $this->authConfig;
        if ($authCfg === null) {
            return [];
        }

        $roles = [];
        if (!empty($authCfg->publicRole)) {
            $roles[] = $authCfg->publicRole;
        }

        $trustedHeaderRole = $this->authConfig->getTrustedHeaderRole();
        if (!empty($trustedHeaderRole)) {
            $roles[] = $trustedHeaderRole;
            $roles[] = $authCfg->academicRole;
        }

        list($user, $pswd) = $this->authConfig->getUserPswd();
        if (!empty($user) && !empty($pswd)) {
            $authUrl = $repoBaseUrl . 'user/' . $user;
            $data    = $this->cache->get($authUrl);
            $failed  = true;

            if ($data && $data->getAge() < $authCfg->authTtl) {
                $data = json_decode($data->value);
                if (password_verify("$user:$pswd", $data->hash)) {
                    $roles  = array_merge($roles, $data->roles);
                    $failed = false;
                }
            }
            if ($failed) {
                $client = $this->authConfig->getClient(['auth' => [$user, $pswd]]);
                $resp   = $client->send(new Request('GET', $authUrl));
                if ($resp->getStatusCode() === 200) {
                    $userRoles   = json_decode((string) $resp->getBody())->groups;
                    $userRoles[] = $user;
                    $roles       = array_merge($roles, $userRoles);

                    $pswdOpts = ['cost' => $authCfg->passwordCost];
                    $data     = [
                        'hash'  => password_hash("$user:$pswd", PASSWORD_BCRYPT, $pswdOpts),
                        'roles' => $userRoles,
                    ];
                    $this->cache->set([$authUrl], (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), null);
                } else {
                    // don't store orphaned authentication entries in cache
                    $this->cache->delete($authUrl);
                }
            }
        }

        return array_values(array_unique($roles));
    }
}
