<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Models\Workflow;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class WorkflowSlugImmutableTest extends TestCase
{
    private User $adminUser;
    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'database.default' => 'central',
            'database.connections.central' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('central');
        DB::reconnect('central');

        $this->withoutMiddleware();

        $this->ensureSchema();

        $this->resetState();

        [$this->adminUser, $this->tenant] = $this->createAdminUserTenant();
    }

    private function resetState(): void
    {
        Schema::connection('central')->disableForeignKeyConstraints();
        DB::connection('central')->table('users')->delete();
        DB::connection('central')->table('tenants')->delete();
        DB::connection('central')->table('personal_access_tokens')->delete();
        DB::connection('central')->table('workflows')->delete();
        Schema::connection('central')->enableForeignKeyConstraints();
    }

    private function ensureSchema(): void
    {
        if (!Schema::connection('central')->hasTable('users')) {
            Schema::connection('central')->create('users', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('email')->unique();
                $table->timestamp('email_verified_at')->nullable();
                $table->string('password');
                $table->boolean('is_admin')->default(false);
                $table->rememberToken();
                $table->timestamps();
            });
        }

        if (!Schema::connection('central')->hasTable('tenants')) {
            Schema::connection('central')->create('tenants', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->string('db_pool')->default('tenant_pool_1');
                $table->timestamps();
                $table->json('data')->nullable();
            });
        }

        if (!Schema::connection('central')->hasTable('personal_access_tokens')) {
            Schema::connection('central')->create('personal_access_tokens', function (Blueprint $table) {
                $table->id();
                $table->string('tokenable_type');
                $table->unsignedBigInteger('tokenable_id');
                $table->text('name');
                $table->string('token', 64)->unique();
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::connection('central')->hasTable('workflows')) {
            Schema::connection('central')->create('workflows', function (Blueprint $table) {
                $table->id();
                $table->string('name', 255);
                $table->string('slug', 255)->unique();
                $table->text('description')->nullable();
                $table->string('comfyui_workflow_path', 2048)->nullable();
                $table->json('properties')->nullable();
                $table->string('output_node_id', 64)->nullable();
                $table->string('output_extension', 16)->default('mp4');
                $table->string('output_mime_type', 255)->default('video/mp4');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->softDeletes();
            });
        }
    }

    private function createAdminUserTenant(): array
    {
        $user = User::factory()->create(['is_admin' => true]);
        $tenant = Tenant::query()->create([
            'id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'db_pool' => 'tenant_pool_1',
        ]);

        return [$user, $tenant];
    }

    private function actAsAdmin(): void
    {
        Sanctum::actingAs($this->adminUser);
    }

    public function test_workflow_slug_cannot_be_changed(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Original Workflow',
            'slug' => 'original-workflow',
            'is_active' => true,
        ]);

        $this->actAsAdmin();
        $this->patchJson("/api/admin/workflows/{$workflow->id}", [
            'slug' => 'updated-workflow',
        ])->assertStatus(422);
    }

    public function test_workflow_can_update_other_fields_without_slug_change(): void
    {
        $workflow = Workflow::query()->create([
            'name' => 'Original Workflow',
            'slug' => 'original-workflow',
            'is_active' => true,
        ]);

        $this->actAsAdmin();
        $this->patchJson("/api/admin/workflows/{$workflow->id}", [
            'name' => 'Updated Workflow',
        ])->assertStatus(200)
            ->assertJsonPath('data.name', 'Updated Workflow')
            ->assertJsonPath('data.slug', 'original-workflow');
    }
}
