<?php

namespace Cslash\SharedSync\Tests;

use Cslash\SharedSync\Http\Middleware\AuthenticateSharedSync;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Orchestra\Testbench\TestCase;

class MiddlewareTest extends TestCase
{
    public function test_middleware_denies_unauthorized_request()
    {
        $middleware = new AuthenticateSharedSync();
        $request = Request::create('/sharedsync', 'POST');

        $response = $middleware->handle($request, function () {
            $this->fail('Middleware should not have called the next closure.');
        });

        $this->assertEquals(401, $response->getStatusCode());
    }

    public function test_middleware_allows_authorized_request()
    {
        $token = 'test-token';
        $tokenFile = base_path('.sharedsync-token');
        File::put($tokenFile, $token);

        try {
            $middleware = new AuthenticateSharedSync();
            $request = Request::create('/sharedsync', 'POST');
            $request->headers->set('X-SharedSync-Token', $token);

            $called = false;
            $middleware->handle($request, function () use (&$called) {
                $called = true;
                return response()->json(['success' => true]);
            });

            $this->assertTrue($called);
        } finally {
            if (File::exists($tokenFile)) {
                File::delete($tokenFile);
            }
        }
    }
}
