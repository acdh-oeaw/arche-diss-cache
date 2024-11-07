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

use zozlak\logging\Log;
use acdhOeaw\arche\lib\RepoDb;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\exception\NotFound;

/**
 * Wrapper handling boilerplate code around the ARCHE microservice initialization
 * 
 * Requires following config file structure:
 * ```
 * dissCacheService:
 *   db: sqlite:/some/path
 *   log:
 *     file: /some/path
 *     level: debug
 *    ttl:
 *      resource: 3600
 *      response: 31536000
 *    repoDb:
 *    - repoConfigPath.yaml
 *    allowedNmsp:
 *    - https://one
 *    - https://another
 *    metadataMode: parents
 *    parentProperty: ""
 *    resourceProperties: []
 *    relativesProperties: []
 * ```
 * @author zozlak
 */
class Service {

    private object $config;
    private Log $log;
    private CachePdo $cacheDb;

    /**
     * 
     * @var callable
     */
    private $clbck;

    public function __construct(string $confFile) {
        $this->config = json_decode(json_encode(yaml_parse_file($confFile)));

        $logId     = sprintf("%08d", rand(0, 99999999));
        $tmpl      = "{TIMESTAMP}:$logId:{LEVEL}\t{MESSAGE}";
        $logCfg    = $this->config->dissCacheService->log;
        $this->log = new Log($logCfg->file, $logCfg->level, $tmpl);
    }

    /**
     * 
     * @param callable $clbck response cache miss handler with signature
     *   `fn(acdhOeaw\arche\lib\RepoResourceInterface $res, array $param)`
     *   where the `$param` will passed from the `serveRequest()` method 
     *   `$param` parameter.
     */
    public function setCallback(callable $clbck): void {
        $this->clbck = $clbck;
    }

    public function getConfig(): object {
        return $this->config;
    }

    public function getLog(): Log {
        return $this->log;
    }

    /**
     * 
     * @param array<mixed> $param
     */
    public function serveRequest(string $id, array $param, bool $clearCache = false): ResponseCacheItem {
        try {
            $t0  = microtime(true);
            $cfg = $this->config->dissCacheService;

            if (empty($id)) {
                $id = 'no identifer provided';
            }
            $this->log->info("Getting response for $id");
            $allowed = false;
            foreach ($cfg->allowedNmsp as $i) {
                if (str_starts_with($id, $i)) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                throw new ServiceException("Requested resource $id not in allowed namespace", 400);
            }

            $this->cacheDb ??= new CachePdo($cfg->db, $cfg->dbId ?? null);
            
            $repos = [];
            foreach ($cfg->repoDb ?? [] as $i) {
                $repos[] = new RepoWrapperRepoInterface(RepoDb::factory($i), true);
            }
            $repos[] = new RepoWrapperGuzzle(false);

            $sc                         = new SearchConfig();
            $sc->metadataMode           = $cfg->metadataMode ?? RepoResourceInterface::META_RESOURCE;
            $sc->metadataParentProperty = $cfg->parentProperty ?? '';
            $sc->resourceProperties     = $cfg->resourceProperties ?? [];
            $sc->relativesProperties    = $cfg->relativesProperties ?? [];

            $cache = new ResponseCache($this->cacheDb, $this->clbck, $cfg->ttl->resource, $cfg->ttl->response, $repos, $sc, $this->log);

            if ($clearCache) {
                $this->log->info("Clearing the cache");
                $cache->pruneCacheForResource($id);
            }

            $response = $cache->getResponse($param, $id);
            $this->log->info("Ended in " . round(microtime(true) - $t0, 3) . " s");
            return $response;
        } catch (\Throwable $e) {
            $response = $this->processException($e);
            if ($e instanceof ServiceException && ($cache ?? null) instanceof ResponseCache) {
                $key = $cache->getLastResponseKey();
                $this->log->info("Caching the error response under a key $key");
                $this->cacheDb->set([$key], $response->serialize(), null);
            }
            return $response;
        }
    }

    public function processException(\Throwable $e): ResponseCacheItem {
        $code              = $e->getCode();
        $ordinaryException = $e instanceof ServiceException || $e instanceof NotFound;

        $logMsg = "$code: " . $e->getMessage() . ($ordinaryException ? '' : "\n" . $e->getFile() . ":" . $e->getLine() . "\n" . $e->getTraceAsString());
        $this->log->error($logMsg);

        if ($code < 400 || $code >= 500) {
            $code = 500;
        }
        $body    = $ordinaryException ? $e->getMessage() . "\n" : "Internal Server Error\n";
        $headers = $e instanceof ServiceException ? $e->getHeaders() : [];
        return new ResponseCacheItem($body, $code, $headers, false);
    }
}
