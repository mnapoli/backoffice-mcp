<?php declare(strict_types=1);

namespace BackofficeMcp\Tests;

use BackofficeMcp\BackofficeMcpServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Laravel\Mcp\Server\McpServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use Spatie\QueryBuilder\QueryBuilderServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, QueryBuilderServiceProvider::class, BackofficeMcpServiceProvider::class];
    }

    /**
     * SQLite in memory by default. `DB_CONNECTION=mysql|mariadb|pgsql` with the usual `DB_*`
     * variables runs the same suite against a real server.
     */
    protected function defineEnvironment($app): void
    {
        $connection = env('DB_CONNECTION');

        if (! is_string($connection) || $connection === '' || $connection === 'sqlite') {
            $app['config']->set('database.default', 'testing');

            return;
        }

        $app['config']->set('database.default', 'real');
        $app['config']->set('database.connections.real', [
            'driver' => $connection,
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT'),
            'database' => env('DB_DATABASE', 'test'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', 'root'),
            'charset' => $connection === 'pgsql' ? 'utf8' : 'utf8mb4',
            'prefix' => '',
        ]);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::dropAllTables();

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('password')->nullable();
            $table->timestamps();
        });
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('plan')->default('free');
            $table->string('secret')->nullable();
            $table->foreignId('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('team_user', function (Blueprint $table) {
            $table->foreignId('team_id');
            $table->foreignId('user_id');
        });
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id');
            $table->string('status')->default('success');
            $table->text('log')->nullable();
            $table->timestamps();
        });
    }
}
