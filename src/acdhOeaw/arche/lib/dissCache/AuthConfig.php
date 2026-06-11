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

use GuzzleHttp\Client;
use zozlak\ProxyClient;

/**
 * Container for authorization config.
 * 
 * getUserPswd(), getTrustedHeaderRole() and getClient() methods allow easy test doubles generation
 *
 * @author zozlak
 */
class AuthConfig {

    public function __construct(readonly string $aclReadProperty,
                                readonly string $publicRole = '',
                                readonly string $academicRole = '',
                                readonly string $roleTrustedHeader = '',
                                readonly string $adminRole = '',
                                readonly int $authTtl = 60,
                                readonly int $passwordCost = 10) {
        ;
    }

    /**
     * 
     * @return array{0: string, 1: string}
     */
    public function getUserPswd(): array {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['AUTHORIZATION'] ?? '');
        if (strtolower(substr($authHeader, 0, 6)) === 'basic ') {
            $userPswd = base64_decode(trim(substr($authHeader, 6)));
            list($user, $pswd) = explode(':', $userPswd . ':');
            return [$user, $pswd];
        }
        return ['', ''];
    }

    public function getTrustedHeaderRole(): string {
        return $_SERVER['HTTP_' . $this->roleTrustedHeader] ?? '';
    }

    /**
     * 
     * @param array{0: string, 1:string} $auth
     */
    public function getClient(array $auth): Client {
        return ProxyClient::factory(['auth' => $auth, 'http_errors' => false]);
    }
}
