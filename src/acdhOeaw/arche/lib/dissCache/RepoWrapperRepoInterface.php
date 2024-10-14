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
use termTemplates\PredicateTemplate as PT;
use acdhOeaw\arche\lib\SearchConfig;
use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\RepoResourceInterface;

/**
 * Description of RepoWrapperRepoInterface
 *
 * @author zozlak
 */
class RepoWrapperRepoInterface implements RepoWrapperInterface {

    private RepoInterface $repo;
    private bool $checkModDate;

    public function __construct(RepoInterface $repo,
                                bool $checkModificationDate = false) {
        $this->repo         = $repo;
        $this->checkModDate = $checkModificationDate;
    }

    public function getResourceById(string $id, ?SearchConfig $config = null): RepoResourceInterface {
        return $this->repo->getResourceById($id, $config);
    }

    public function getModificationTimestamp(string $id): int {
        if (!$this->checkModDate) {
            return PHP_INT_MAX;
        }

        $config                     = new SearchConfig();
        $config->metadataMode       = RepoResourceInterface::META_RESOURCE;
        $modDateProp                = $this->repo->getSchema()->modificationDate;
        $config->resourceProperties = [(string) $modDateProp];
        $res                        = $this->repo->getResourceById($id, $config);
        $modDate                    = $res->getGraph()->getObjectValue(new PT($modDateProp));
        return (new DateTimeImmutable($modDate))->getTimestamp();
    }
}
