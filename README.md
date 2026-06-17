# Caching library for ARCHE dissemination services

[![Latest Stable Version](https://poser.pugx.org/acdh-oeaw/arche-diss-cache/v/stable)](https://packagist.org/packages/acdh-oeaw/arche-diss-cache)
[![Build Status](https://github.com/acdh-oeaw/arche-diss-cache/actions/workflows/test.yaml/badge.svg)](https://github.com/acdh-oeaw/arche-diss-cache/actions/workflows/test.yaml)
[![Coverage Status](https://coveralls.io/repos/github/acdh-oeaw/arche-diss-cache/badge.svg?branch=master)](https://coveralls.io/github/acdh-oeaw/arche-diss-cache?branch=master)
[![License](https://poser.pugx.org/acdh-oeaw/arche-diss-cache/license)](https://packagist.org/packages/acdh-oeaw/arche-diss-cache)

Basic use:

```php
<?php
use Psr\Log\AbstractLogger;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\dissCache\Service;
use acdhOeaw\arche\lib\dissCache\ResponseCacheItem;


class MyClass {
    static public method cacheHandler(
        RepositoryResourceInterface $res, 
        array $serveRequestParam, 
        bool $noCache,
        AbstractLogger $log,
        object $config
    ){
        ...whatever needed...
        return new ResponseCacheItem($responseBody, $responseStatusCode, $responseHttpHeaders);
    }
}

$service = new Service('path_to_config.yaml');
$config  = $service->getConfig();
$service->setCallback(fn($res, $param, $noCache) => MyClass::cacheHandler($res, $param, $noCache, $service->getLog(), $service->getConfig()));
$resId    = ...obtainResourceIdentifier...;
$param    = [...readRequestParameters...];
$response = $service->serveRequest($resId, $param);
$response->send();
```

The sample YAML config file can be found in `tests/config.yaml`.