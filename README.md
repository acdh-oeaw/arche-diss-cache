# Caching library for ARCHE dissemination services

[![Latest Stable Version](https://poser.pugx.org/acdh-oeaw/arche-diss-cache/v/stable)](https://packagist.org/packages/acdh-oeaw/arche-diss-cache)
[![Build Status](https://github.com/acdh-oeaw/arche-diss-cache/actions/workflows/test.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-diss-cache/actions/workflows/test.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-diss-cache/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-diss-cache?branch=master)
[![License](https://poser.pugx.org/acdh-oeaw/arche-diss-cache/license)](https://packagist.org/packages/acdh-oeaw/arche-diss-cache)

Provides a framework for writing ARCHE Suite dissemination services.

Takes care of:

* Caching dissemination service responses.
* Fetching and caching repository resource metadata.
* Fetching and caching repository resource binaries.
* Checking aurhorization.
* Support of web standards like honoring  `Cache-Control` and `Accepted-Encoding` 
  HTTP request headers, emitting `Cache-Control` HTTP header, etc.

## Basic use

```php
<?php
use Psr\Log\AbstractLogger;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\Service;
use acdhOeaw\arche\lib\dissCache\ResponseCache;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;


class MyClass {
    static public method cacheHandler(
        RepositoryResourceInterface $res, 
        array $serveRequestParam,
        ResponseCache $cache,
        object $config
    ){
        ...whatever needed...
        return new ResponseCacheItem($responseBody, $responseStatusCode, $responseHttpHeaders);
    }
}

$service = new Service('path_to_config.yaml');
$service->setCallback(fn($res, $param, $responseCache) => MyClass::cacheHandler($res, $param, $responseCache, $service->getConfig()));
$resId    = ...obtainResourceIdentifierFromTheRequest...;
$param    = [...readRequestParametersFromTheRequest...];
$response = $service->serveRequest($resId, $param);
$response->send();
```

The sample YAML config file can be found in `tests/config.yaml`.


### Getting access to the logger

```php
$cache->getLog()
```

### Getting acces to the repository resource binary content

```php
$pathToFile = $cache->getFileCache()->getResourceBinaryPath($res);
```