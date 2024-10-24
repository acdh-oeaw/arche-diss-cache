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

use quickRdf\Dataset;
use quickRdf\DatasetNode;
use quickRdf\DataFactory;
use quickRdfIo\NQuadsParser;
use quickRdfIo\NQuadsSerializer;
use acdhOeaw\arche\lib\RepoInterface;
use acdhOeaw\arche\lib\RepoResourceInterface;
use acdhOeaw\arche\lib\RepoResourceTrait;

/**
 * 
 *
 * @author zozlak
 */
class RepoResourceCacheItem implements RepoResourceInterface {

    use RepoResourceTrait;

    static private NQuadsParser $parser;
    static private NQuadsSerializer $serializer;

    static public function serialize(RepoResourceInterface $res): string {
        self::$serializer ??= new NQuadsSerializer();
        $data             = [
            'uri'      => (string) $res->getUri(),
            'metadata' => self::$serializer->serialize($res->getGraph()->getDataset()),
        ];
        return (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    static public function deserialize(string $data): self {
        self::$parser ??= new NQuadsParser(new DataFactory(), false, NQuadsParser::MODE_TRIPLES);

        $data  = json_decode($data);
        $graph = new Dataset();
        $graph->add(self::$parser->parse($data->metadata));
        $res   = new self($data->uri, null);
        $res->setGraph($graph);
        return $res;
    }

    /* @phpstan-ignore constructor.unusedParameter */
    public function __construct(string $url, ?RepoInterface $repo) {
        $this->metadata = new DatasetNode(DataFactory::namedNode($url));
    }

    public function loadMetadata(bool $force = false,
                                 string $mode = self::META_RESOURCE,
                                 ?string $parentProperty = null,
                                 array $resourceProperties = [],
                                 array $relativesProperties = []): void {
        if (count($this->metadata) === 0 || $force) {
            throw new \BadMethodCallException("Not implemented");
        }
    }
}
