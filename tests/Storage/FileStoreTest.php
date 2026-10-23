<?php

declare(strict_types=1);

namespace Tests\Storage;

use App\Storage\FileStore;
use App\Storage\StorageException;

require_once __DIR__ . '/../TestCase.php';

class FileStoreTest extends \TestCase
{
    private function store(): FileStore
    {
        return new FileStore($this->tmpDir());
    }

    public function testWriteThenRead(): void
    {
        $s = $this->store();
        $s->write('games/game-001.json', ['id' => 'game-001', 'title' => 'Тест «кавычки»', 'n' => 1]);
        $data = $s->read('games/game-001.json');
        $this->assertSame('game-001', $data['id']);
        $this->assertSame('Тест «кавычки»', $data['title']); // JSON_UNESCAPED_UNICODE
    }

    public function testReadMissingReturnsDefault(): void
    {
        $s = $this->store();
        $this->assertSame([], $s->read('nope.json'));
        $this->assertSame('fallback', $s->read('nope.json', 'fallback'));
    }

    public function testBrokenJsonThrows(): void
    {
        $s = $this->store();
        file_put_contents($s->getRootDir() . '/broken.json', '{not json');
        $this->assertThrows(StorageException::class, fn () => $s->read('broken.json'));
    }

    public function testPathTraversalRejected(): void
    {
        $s = $this->store();
        foreach (['../../etc/passwd', '/abs/path.json', "nul\0file", 'bad path.json', 'a\\b.json'] as $p) {
            $this->assertThrows(StorageException::class, fn () => $s->read($p), "path: {$p}");
        }
    }

    public function testDeleteAndExists(): void
    {
        $s = $this->store();
        $s->write('x.json', ['a' => 1]);
        $this->assertTrue($s->exists('x.json'));
        $s->delete('x.json');
        $this->assertFalse($s->exists('x.json'));
        // удаление отсутствующего — без ошибки
        $s->delete('x.json');
    }

    public function testMutateAppliesAndPersists(): void
    {
        $s = $this->store();
        $s->write('list.json', [1, 2]);
        $s->mutate('list.json', function (&$d): bool {
            $d[] = 3;
            return true;
        });
        $this->assertSame([1, 2, 3], $s->read('list.json'));
    }

    public function testMutateReturnFalseSkipsSave(): void
    {
        $s = $this->store();
        $s->write('list.json', [1, 2]);
        $s->mutate('list.json', function (&$d): bool {
            $d[] = 99;
            return false;
        });
        $this->assertSame([1, 2], $s->read('list.json'));
    }

    public function testMutateExceptionLeavesFileUnchanged(): void
    {
        // Требование ТЗ 2.5: mutate с исключением в мутаторе — файл не изменён.
        $s = $this->store();
        $s->write('list.json', [1, 2]);
        try {
            $s->mutate('list.json', function (&$d): bool {
                throw new \RuntimeException('boom');
            });
            $this->assertTrue(false, 'ожидалось исключение');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }
        $this->assertSame([1, 2], $s->read('list.json'));
    }

    public function testConcurrentWritesDoNotCorrupt(): void
    {
        // Требование ТЗ 2.5: 1000 параллельных записей из 10 процессов — файл не повреждён.
        if (!function_exists('pcntl_fork')) {
            // Fallback: последовательная проверка читаемости после каждой записи.
            $s = $this->store();
            for ($i = 0; $i < 200; $i++) {
                $s->write('conc.json', ['counter' => $i]);
                $d = $s->read('conc.json');
                $this->assertSame($i, $d['counter']);
            }
            return;
        }
        $dir = $this->tmpDir();
        $procs = [];
        for ($p = 0; $p < 10; $p++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->assertTrue(false, 'fork failed');
            }
            if ($pid === 0) {
                $s = new FileStore($dir);
                for ($i = 0; $i < 100; $i++) {
                    $s->write('conc.json', ['proc' => $p, 'seq' => $i]);
                }
                exit(0);
            }
            $procs[] = $pid;
        }
        foreach ($procs as $pid) {
            pcntl_waitpid($pid, $st);
        }
        $s = new FileStore($dir);
        $data = $s->read('conc.json'); // не должно бросить StorageException
        $this->assertTrue(is_array($data) && isset($data['proc'], $data['seq']), 'файл читается как валидный JSON');
    }

    public function testAtomicityNoTmpLeftovers(): void
    {
        $s = $this->store();
        $s->write('a.json', ['k' => 'v']);
        $leftovers = glob($s->getRootDir() . '/a.json.tmp.*') ?: [];
        $this->assertCount(0, $leftovers);
    }
}
