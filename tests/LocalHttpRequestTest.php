<?php

declare(strict_types=1);

namespace Wedding\Tests;

use PHPUnit\Framework\TestCase;
use Wedding\Tests\support\OwnedProcess;
use Wedding\Tests\support\TemporaryDirectory;

final class LocalHttpRequestTest extends TestCase
{
    public function testUnavailableCurlIdentifiesTheTestPrerequisite(): void
    {
        $directory = new TemporaryDirectory('curl-prerequisite');
        $check = <<<'PHP'
            require $argv[1];
            try {
                \Wedding\Tests\support\LocalHttpRequest::requireCurl();
            } catch (RuntimeException $error) {
                echo $error->getMessage();
            }
            PHP;
        try {
            $process = new OwnedProcess([PHP_BINARY, '-d', 'disable_functions=curl_init', '-r', $check,
                dirname(__DIR__) . '/vendor/autoload.php'], $directory->path . '/check.log');
            $process->awaitExit();
            self::assertStringContainsString('require the PHP cURL extension (php-curl)', $process->output());
        } finally {
            if (isset($process)) {
                $process->stop();
            }
            $directory->remove();
        }
    }
}
