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

use PDO;

/**
 * Description of CachePdo
 *
 * @author zozlak
 */
class CachePdo implements CacheInterface {

    private PDO $pdo;
    private string $lockPath;
    private string $driver;

    public function __construct(string $connString, ?string $cacheId = null) {
        $this->driver = strtolower(preg_replace('/:.*$/', '', $connString));
        if ($this->driver !== 'sqlite') {
            throw new \RuntimeException("Database driver $this->driver not supported");
        }
        $this->pdo = new PDO($connString);
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->lockPath = sys_get_temp_dir() . '/cachePdo_' . ($cacheId ?? hash('xxh128', __FILE__));

        $this->maintainDb();
    }

    public function get(string $key): CacheItem | false {
        $query = $this->pdo->prepare("
            SELECT id, created, value
            FROM vals v
            WHERE EXISTS (SELECT 1 FROM keys WHERE key = ? AND v.id = id)
        ");
        $query->execute([$key]);
        return $query->fetchObject(CacheItem::class);
    }

    public function set(array $keys, string $value, ?int $id): int {
        $query = $this->pdo->prepare("
            INSERT OR REPLACE INTO vals (id, created, value) 
            VALUES (?, current_timestamp, ?)
            RETURNING id
        ");
        $query->execute([$id, $value]);
        $id    = $query->fetchColumn();

        $query = $this->pdo->prepare("INSERT OR REPLACE INTO keys (key, id) VALUES (?, ?)");
        foreach ($keys as $i) {
            $query->execute([$i, $id]);
        }

        return $id;
    }

    private function maintainDb(): void {
        if (!file_exists($this->lockPath)) {
            $this->pdo->query("
                CREATE TABLE IF NOT EXISTS keys (
                    key text primary key not null,
                    id integer
                )
            ");

            $this->pdo->query("
                CREATE TABLE IF NOT EXISTS vals (
                    id integer primary key not null,
                    created timestamp not null,
                    value text not null
                )
            ");

            file_put_contents($this->lockPath, "");
        }
    }
}
