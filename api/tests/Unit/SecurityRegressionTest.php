<?php

namespace Tests\Unit;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Request;

class SecurityRegressionTest extends TestCase
{
    public function test_web_environment_ignores_cli_arguments(): void
    {
        $original = $_SERVER['argv'] ?? null;
        try {
            $_SERVER['argv'] = ['index.php', '--env=local'];
            $app = new class extends Application {
                public function runningInConsole() { return false; }
            };
            $this->assertSame('production', $app->detectEnvironment(fn () => 'production'));
        } finally {
            $_SERVER['argv'] = $original;
        }
    }

    public function test_request_headers_do_not_exhaust_the_regex_backtrack_limit(): void
    {
        $header = 'a'.str_repeat(' ', 25).',b';
        $this->assertSame([['a'], ['b']], HeaderUtils::split($header, ',='));
        $this->assertSame(PREG_NO_ERROR, preg_last_error());
        $request = Request::create('/', 'GET', [], [], [], ['HTTP_CACHE_CONTROL' => $header]);
        $this->assertTrue($request->headers->hasCacheControlDirective('a'));
        $this->assertTrue($request->headers->hasCacheControlDirective('b'));
    }
}
