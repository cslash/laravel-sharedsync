<?php

namespace Cslash\SharedSync\Tests;

use Orchestra\Testbench\TestCase;
use Cslash\SharedSync\SharedSyncServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

class MigrateTest extends TestCase
{
    protected function getPackageProviders($app)
    {
        return [
            SharedSyncServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('app.key', 'base64:uHge+pbe7goY8o80000000000000000000000000000=');
    }

    public function test_migrate_route_requires_signature()
    {
        $response = $this->getJson('/sharedsync/migrate');
        $response->assertStatus(401);
    }

    public function test_migrate_route_success_with_valid_signature()
    {
        $url = URL::temporarySignedRoute('sharedsync.migrate', now()->addMinutes(30));
        
        // Mock Artisan
        $artisanMock = \Mockery::mock(\Illuminate\Contracts\Console\Kernel::class);
        $artisanMock->shouldReceive('call')->with('migrate', ['--force' => true])->once()->andReturn(0);
        $artisanMock->shouldReceive('output')->andReturn('Migration successful');
        
        $this->app->instance(\Illuminate\Contracts\Console\Kernel::class, $artisanMock);
        $this->app->instance('artisan', $artisanMock);

        $response = $this->getJson($url);

        $response->assertStatus(200)
            ->assertJson([
                'status' => 'success',
                'output' => 'Migration successful',
            ]);
    }

    public function test_migrate_command_triggers_remote_migration()
    {
        $this->app['config']->set('sharedsync.url', 'https://example.com');
        
        Http::fake([
            '*sharedsync/migrate*' => Http::response([
                'status' => 'success',
                'output' => 'Remote migration output'
            ], 200),
        ]);

        $this->artisan('sharedsync:migrate')
            ->expectsOutput('Triggering remote migrations...')
            ->expectsOutput('Remote migration successful.')
            ->expectsOutput('Remote migration output')
            ->assertExitCode(0);
    }

    public function test_migrate_command_handles_failure()
    {
        $this->app['config']->set('sharedsync.url', 'https://example.com');
        
        Http::fake([
            '*sharedsync/migrate*' => Http::response([
                'status' => 'error',
                'message' => 'Migration failed'
            ], 500),
        ]);

        $this->artisan('sharedsync:migrate')
            ->expectsOutput('Triggering remote migrations...')
            ->expectsOutput('Remote migration failed!')
            ->expectsOutput('Migration failed')
            ->assertExitCode(1);
    }
}
