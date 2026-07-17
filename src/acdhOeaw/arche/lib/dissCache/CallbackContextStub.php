<?php

/*
 * The MIT License
 *
 * Copyright 2026 zozlak.
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

/**
 * Stub object implementing CallbackContextInterface to be used in tests
 *
 * @author zozlak
 */
class CallbackContextStub implements CallbackContextInterface {

    public FileCache $fileCache;
    public LoggerInterface | null $log = null;
    public bool $noCache             = false;

    public function getFileCache(): FileCache {
        return $this->fileCache;
    }

    public function getLog(): LoggerInterface | null {
        return $this->log;
    }

    public function getNoCache(): bool {
        return $this->noCache;
    }
}
