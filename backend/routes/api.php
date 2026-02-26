<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\BaseController;
use App\Http\Middleware\EnsureTenantMatchesUser;
use App\Http\Middleware\InitializeTenancyByDomainOrUser;
use App\Http\Middleware\EnsureAdmin;
use App\Http\Middleware\EnsureWorkerToken;
use App\Http\Middleware\EnsureFleetSecret;
use App\Http\Middleware\EnsureAssetOpsSecret;
use App\Http\Controllers\ComfyUiWorkerController;
use App\Http\Controllers\ComfyUiAssetOpsController;
use App\Http\Controllers\Webhook\PaymentWebhookController;
use App\Http\Controllers\AiJobController;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;
use \App\Http\Controllers\ArticleController as ArticleController;
use \App\Http\Controllers\ActivityLogController as ActivityLogController;
use \App\Http\Controllers\RegisterController as RegisterController;
use \App\Http\Controllers\AuthController as AuthController;
use \App\Http\Controllers\TranslationsController as TranslationsController;
use \App\Http\Controllers\PasswordController as PasswordController;
use \App\Http\Controllers\MeController as MeController;
use \App\Http\Controllers\WalletController as WalletController;
use \App\Http\Controllers\EffectController as EffectController;
use \App\Http\Controllers\CategoryController as CategoryController;
use \App\Http\Controllers\FileController as FileController;
use \App\Http\Controllers\VideoController as VideoController;
use \App\Http\Controllers\GalleryController as GalleryController;
use \App\Http\Controllers\Admin\EffectsController as AdminEffectsController;
use \App\Http\Controllers\Admin\CategoriesController as AdminCategoriesController;
use \App\Http\Controllers\Admin\UiSettingsController as AdminUiSettingsController;
use \App\Http\Controllers\Admin\UsersController as AdminUsersController;
use \App\Http\Controllers\Admin\EconomicsAnalyticsController as AdminEconomicsAnalyticsController;
use \App\Http\Controllers\Admin\EconomicsSettingsController as AdminEconomicsSettingsController;
use \App\Http\Controllers\Admin\PartnerUsageAnalyticsController as AdminPartnerUsageAnalyticsController;
use \App\Http\Controllers\Admin\PartnerUsagePricingController as AdminPartnerUsagePricingController;
use \App\Http\Controllers\Admin\WorkflowsController as AdminWorkflowsController;
use \App\Http\Controllers\Admin\WorkersController as AdminWorkersController;
use \App\Http\Controllers\Admin\AuditLogsController as AdminAuditLogsController;
use \App\Http\Controllers\Admin\WorkloadController as AdminWorkloadController;
use \App\Http\Controllers\Admin\ComfyUiAssetsController as AdminComfyUiAssetsController;
use \App\Http\Controllers\Admin\ComfyUiFleetsController as AdminComfyUiFleetsController;
use \App\Http\Controllers\Admin\StudioWorkflowAnalysisController as AdminStudioWorkflowAnalysisController;
use \App\Http\Controllers\Admin\LoadTestScenariosController as AdminLoadTestScenariosController;
use \App\Http\Controllers\Admin\DevNodesController as AdminDevNodesController;
use \App\Http\Controllers\Admin\StudioEconomicsController as AdminStudioEconomicsController;
use \App\Http\Controllers\Admin\StudioExecutionEnvironmentsController as AdminStudioExecutionEnvironmentsController;
use \App\Http\Controllers\Admin\StudioTestInputSetsController as AdminStudioTestInputSetsController;
use \App\Http\Controllers\Admin\StudioEffectTestRunsController as AdminStudioEffectTestRunsController;
use \App\Http\Controllers\Admin\StudioLoadTestRunsController as AdminStudioLoadTestRunsController;
use \App\Http\Controllers\Admin\StudioWorkflowRevisionsController as AdminStudioWorkflowRevisionsController;
use \App\Http\Controllers\Admin\StudioWorkflowJsonController as AdminStudioWorkflowJsonController;
use \App\Http\Controllers\Admin\StudioWorkflowCloneController as AdminStudioWorkflowCloneController;
use \App\Http\Controllers\Admin\StudioEffectCloneController as AdminStudioEffectCloneController;
use \App\Http\Controllers\Admin\StudioExperimentVariantsController as AdminStudioExperimentVariantsController;
use \App\Http\Controllers\Admin\StudioFleetConfigSnapshotsController as AdminStudioFleetConfigSnapshotsController;
use \App\Http\Controllers\Admin\StudioProductionFleetSnapshotsController as AdminStudioProductionFleetSnapshotsController;
use \App\Http\Controllers\Admin\StudioRunArtifactsController as AdminStudioRunArtifactsController;

/**
 * Central/public routes (no tenant initialization required).
 */
Route::post('login', [RegisterController::class,'login']);
Route::post('register', [RegisterController::class,'register']);
Route::post('password/reset', [PasswordController::class,'sendResetLink']);
Route::post('password/reset/confirm', [PasswordController::class,'reset']);

// Google OAuth
Route::get('auth/google/signin', [AuthController::class, 'redirectToGoogleSignIn']);
Route::get('auth/google/signin/callback', [AuthController::class, 'handleGoogleSignInCallback']);
Route::get('auth/google/signup', [AuthController::class, 'redirectToGoogleSignUp']);
Route::get('auth/google/signup/callback', [AuthController::class, 'handleGoogleSignUpCallback']);

// TikTok OAuth
Route::get('auth/tiktok/signin', [AuthController::class, 'redirectToTikTokSignIn']);
Route::get('auth/tiktok/signin/callback', [AuthController::class, 'handleTikTokSignInCallback']);
Route::get('auth/tiktok/signup', [AuthController::class, 'redirectToTikTokSignUp']);
Route::get('auth/tiktok/signup/callback', [AuthController::class, 'handleTikTokSignUpCallback']);

// Apple OAuth (callbacks are POST — Apple uses response_mode=form_post)
Route::get('auth/apple/signin', [AuthController::class, 'redirectToAppleSignIn']);
Route::post('auth/apple/signin/callback', [AuthController::class, 'handleAppleSignInCallback']);
Route::get('auth/apple/signup', [AuthController::class, 'redirectToAppleSignUp']);
Route::post('auth/apple/signup/callback', [AuthController::class, 'handleAppleSignUpCallback']);

// Translations
Route::get('translations/{lang}', [TranslationsController::class, 'show']);

// Public catalog endpoints (no tenant init required).
Route::get('effects', [EffectController::class,'index']);
Route::get('effects/{slugOrId}', [EffectController::class,'show']);
Route::get('categories', [CategoryController::class,'index']);
Route::get('categories/{slugOrId}', [CategoryController::class,'show']);
Route::get('gallery', [GalleryController::class,'index']);
Route::get('gallery/{id}', [GalleryController::class,'show']);

// Central-domain webhooks (tenant is resolved from the central purchase record).
Route::post('webhooks/payments', [PaymentWebhookController::class, 'handle']);

// Fleet self-registration (fleet-secret protected, rate-limited).
Route::middleware([EnsureFleetSecret::class, 'throttle:fleet-register'])
    ->prefix('worker')
    ->group(function () {
        Route::post('register', [ComfyUiWorkerController::class, 'register']);
    });

// Worker endpoints (central, token-protected).
Route::middleware([EnsureWorkerToken::class])->prefix('worker')->group(function () {
    Route::post('poll', [ComfyUiWorkerController::class, 'poll']);
    Route::post('heartbeat', [ComfyUiWorkerController::class, 'heartbeat']);
    Route::post('complete', [ComfyUiWorkerController::class, 'complete']);
    Route::post('fail', [ComfyUiWorkerController::class, 'fail']);
    Route::post('requeue', [ComfyUiWorkerController::class, 'requeue']);
    Route::post('deregister', [ComfyUiWorkerController::class, 'deregister']);
});

// Asset ops endpoints (central, secret-protected)
Route::middleware([EnsureAssetOpsSecret::class])
    ->prefix('ops/comfyui-assets')
    ->group(function () {
        Route::post('/sync-logs', [ComfyUiAssetOpsController::class, 'storeSyncLog']);
    });

/**
 * Tenant routes (tenant resolved by domain/subdomain).
 */
Route::middleware([
    PreventAccessFromCentralDomains::class,
    'auth:sanctum',
    InitializeTenancyByDomainOrUser::class,
    EnsureTenantMatchesUser::class,
])->group(function () {
    Route::get('/me', [MeController::class,'show']);
    Route::post('/me', [MeController::class,'update']);
    Route::get('/wallet', [WalletController::class, 'show']);

    Route::middleware([EnsureAdmin::class])->group(function () {
        Route::get('/filters', [BaseController::class, 'getAvailableFilers']);
        Route::get('/filter-options', [BaseController::class, 'getFilterOptions']);
        Route::get('/columns', [BaseController::class, 'getAvailableColumns']);

        Route::prefix('admin')->group(function () {
            Route::get('/effects', [AdminEffectsController::class, 'index']);
            Route::get('/effects/create', [AdminEffectsController::class, 'create']);
            Route::post('/effects/uploads', [AdminEffectsController::class, 'createUpload']);
            Route::post('/effects', [AdminEffectsController::class, 'store']);
            Route::get('/effects/{id}', [AdminEffectsController::class, 'show']);
            Route::patch('/effects/{id}', [AdminEffectsController::class, 'update']);
            Route::delete('/effects/{id}', [AdminEffectsController::class, 'destroy']);
            Route::post('/effects/{id}/stress-test', [AdminEffectsController::class, 'stressTest']);
            Route::get('/effects/{id}/revisions', [AdminEffectsController::class, 'revisions']);
            Route::post('/effects/{id}/revisions', [AdminEffectsController::class, 'createRevision']);
            Route::post('/effects/{id}/publish', [AdminEffectsController::class, 'publish']);
            Route::post('/effects/{id}/unpublish', [AdminEffectsController::class, 'unpublish']);

            Route::get('/categories', [AdminCategoriesController::class, 'index']);
            Route::get('/categories/create', [AdminCategoriesController::class, 'create']);
            Route::post('/categories', [AdminCategoriesController::class, 'store']);
            Route::get('/categories/{id}', [AdminCategoriesController::class, 'show']);
            Route::patch('/categories/{id}', [AdminCategoriesController::class, 'update']);
            Route::delete('/categories/{id}', [AdminCategoriesController::class, 'destroy']);

            Route::get('/ui-settings', [AdminUiSettingsController::class, 'index']);
            Route::put('/ui-settings', [AdminUiSettingsController::class, 'update']);
            Route::delete('/ui-settings', [AdminUiSettingsController::class, 'destroy']);

            Route::get('/users', [AdminUsersController::class, 'index']);
            Route::get('/users/{id}', [AdminUsersController::class, 'show']);
            Route::get('/users/{id}/purchases', [AdminUsersController::class, 'purchases']);
            Route::get('/users/{id}/tokens', [AdminUsersController::class, 'tokens']);

            Route::get('/economics/settings', [AdminEconomicsSettingsController::class, 'index']);
            Route::put('/economics/settings', [AdminEconomicsSettingsController::class, 'update']);
            Route::get('/economics/unit-economics', [AdminEconomicsAnalyticsController::class, 'unitEconomics']);
            Route::get('/economics/partner-pricing', [AdminPartnerUsagePricingController::class, 'index']);
            Route::put('/economics/partner-pricing', [AdminPartnerUsagePricingController::class, 'update']);
            Route::get('/economics/partner-usage', [AdminPartnerUsageAnalyticsController::class, 'index']);

            // Workflows
            Route::get('/workflows', [AdminWorkflowsController::class, 'index']);
            Route::get('/workflows/create', [AdminWorkflowsController::class, 'create']);
            Route::post('/workflows/uploads', [AdminWorkflowsController::class, 'createUpload']);
            Route::post('/workflows', [AdminWorkflowsController::class, 'store']);
            Route::get('/workflows/{id}', [AdminWorkflowsController::class, 'show']);
            Route::patch('/workflows/{id}', [AdminWorkflowsController::class, 'update']);
            Route::put('/workflows/{id}/fleet-assignments', [AdminWorkflowsController::class, 'assignFleets']);
            Route::delete('/workflows/{id}', [AdminWorkflowsController::class, 'destroy']);

            // ComfyUI Assets
            Route::get('/comfyui-assets/files', [AdminComfyUiAssetsController::class, 'filesIndex']);
            Route::post('/comfyui-assets/uploads', [AdminComfyUiAssetsController::class, 'createUpload']);
            Route::post('/comfyui-assets/uploads/multipart', [AdminComfyUiAssetsController::class, 'createMultipartUpload']);
            Route::post('/comfyui-assets/uploads/multipart/complete', [AdminComfyUiAssetsController::class, 'completeMultipartUpload']);
            Route::post('/comfyui-assets/uploads/multipart/abort', [AdminComfyUiAssetsController::class, 'abortMultipartUpload']);
            Route::post('/comfyui-assets/files', [AdminComfyUiAssetsController::class, 'filesStore']);
            Route::patch('/comfyui-assets/files/{id}', [AdminComfyUiAssetsController::class, 'filesUpdate']);
            Route::get('/comfyui-assets/bundles', [AdminComfyUiAssetsController::class, 'bundlesIndex']);
            Route::get('/comfyui-assets/cleanup-candidates', [AdminComfyUiAssetsController::class, 'cleanupCandidates']);
            Route::get('/comfyui-assets/cleanup-assets', [AdminComfyUiAssetsController::class, 'cleanupAssetCandidates']);
            Route::delete('/comfyui-assets/bundles/{id}', [AdminComfyUiAssetsController::class, 'bundlesDestroy']);
            Route::delete('/comfyui-assets/files/{id}', [AdminComfyUiAssetsController::class, 'filesDestroy']);
            Route::post('/comfyui-assets/bundles', [AdminComfyUiAssetsController::class, 'bundlesStore']);
            Route::patch('/comfyui-assets/bundles/{id}', [AdminComfyUiAssetsController::class, 'bundlesUpdate']);
            Route::get('/comfyui-assets/bundles/{id}/manifest', [AdminComfyUiAssetsController::class, 'bundleManifest']);
            Route::get('/comfyui-assets/audit-logs', [AdminComfyUiAssetsController::class, 'auditLogsIndex']);
            Route::get('/comfyui-assets/audit-logs/export', [AdminComfyUiAssetsController::class, 'auditLogsExport']);

            // ComfyUI Fleets
            Route::get('/comfyui-fleets/templates', [AdminComfyUiFleetsController::class, 'templates']);
            Route::get('/comfyui-fleets', [AdminComfyUiFleetsController::class, 'index']);
            Route::post('/comfyui-fleets', [AdminComfyUiFleetsController::class, 'store']);
            Route::get('/comfyui-fleets/{id}', [AdminComfyUiFleetsController::class, 'show']);
            Route::patch('/comfyui-fleets/{id}', [AdminComfyUiFleetsController::class, 'update']);
            Route::put('/comfyui-fleets/{id}/workflows', [AdminComfyUiFleetsController::class, 'assignWorkflows']);
            Route::post('/comfyui-fleets/{id}/activate-bundle', [AdminComfyUiFleetsController::class, 'activateBundle']);

            // Workers
            Route::get('/workers', [AdminWorkersController::class, 'index']);
            Route::post('/workers', [AdminWorkersController::class, 'store']);
            Route::get('/workers/{id}', [AdminWorkersController::class, 'show']);
            Route::patch('/workers/{id}', [AdminWorkersController::class, 'update']);
            Route::post('/workers/{id}/approve', [AdminWorkersController::class, 'approve']);
            Route::post('/workers/{id}/revoke', [AdminWorkersController::class, 'revoke']);
            Route::post('/workers/{id}/rotate-token', [AdminWorkersController::class, 'rotateToken']);
            Route::get('/workers/{id}/audit-logs', [AdminWorkersController::class, 'auditLogs']);

            // Workload
            Route::get('/workload', [AdminWorkloadController::class, 'index']);

            // Studio
            Route::post('/studio/workflow-analyze', [AdminStudioWorkflowAnalysisController::class, 'store']);
            Route::get('/studio/workflow-analyze/{id}', [AdminStudioWorkflowAnalysisController::class, 'show']);
            Route::get('/studio/workflows/{id}/revisions', [AdminStudioWorkflowRevisionsController::class, 'index']);
            Route::post('/studio/workflows/{id}/revisions', [AdminStudioWorkflowRevisionsController::class, 'store']);
            Route::get('/studio/workflows/{id}/json', [AdminStudioWorkflowJsonController::class, 'show']);
            Route::put('/studio/workflows/{id}/json', [AdminStudioWorkflowJsonController::class, 'update']);
            Route::post('/studio/workflows/{id}/clone', [AdminStudioWorkflowCloneController::class, 'store']);
            Route::post('/studio/effects/{id}/clone', [AdminStudioEffectCloneController::class, 'store']);
            Route::get('/studio/dev-nodes', [AdminDevNodesController::class, 'index']);
            Route::post('/studio/dev-nodes', [AdminDevNodesController::class, 'store']);
            Route::get('/studio/dev-nodes/{id}', [AdminDevNodesController::class, 'show']);
            Route::patch('/studio/dev-nodes/{id}', [AdminDevNodesController::class, 'update']);
            Route::post('/studio/economics/cost-model', [AdminStudioEconomicsController::class, 'costModel']);
            Route::get('/studio/load-test-scenarios', [AdminLoadTestScenariosController::class, 'index']);
            Route::post('/studio/load-test-scenarios', [AdminLoadTestScenariosController::class, 'store']);
            Route::get('/studio/load-test-scenarios/{id}', [AdminLoadTestScenariosController::class, 'show']);
            Route::patch('/studio/load-test-scenarios/{id}', [AdminLoadTestScenariosController::class, 'update']);
            Route::get('/studio/execution-environments', [AdminStudioExecutionEnvironmentsController::class, 'index']);
            Route::get('/studio/execution-environments/{id}', [AdminStudioExecutionEnvironmentsController::class, 'show']);
            Route::get('/studio/test-input-sets', [AdminStudioTestInputSetsController::class, 'index']);
            Route::post('/studio/test-input-sets', [AdminStudioTestInputSetsController::class, 'store']);
            Route::get('/studio/test-input-sets/{id}', [AdminStudioTestInputSetsController::class, 'show']);
            Route::get('/studio/effect-test-runs', [AdminStudioEffectTestRunsController::class, 'index']);
            Route::post('/studio/effect-test-runs', [AdminStudioEffectTestRunsController::class, 'store']);
            Route::get('/studio/effect-test-runs/{id}', [AdminStudioEffectTestRunsController::class, 'show']);
            Route::get('/studio/load-test-runs', [AdminStudioLoadTestRunsController::class, 'index']);
            Route::post('/studio/load-test-runs', [AdminStudioLoadTestRunsController::class, 'store']);
            Route::get('/studio/load-test-runs/{id}', [AdminStudioLoadTestRunsController::class, 'show']);
            Route::get('/studio/experiment-variants', [AdminStudioExperimentVariantsController::class, 'index']);
            Route::post('/studio/experiment-variants', [AdminStudioExperimentVariantsController::class, 'store']);
            Route::get('/studio/experiment-variants/{id}', [AdminStudioExperimentVariantsController::class, 'show']);
            Route::patch('/studio/experiment-variants/{id}', [AdminStudioExperimentVariantsController::class, 'update']);
            Route::get('/studio/fleet-config-snapshots', [AdminStudioFleetConfigSnapshotsController::class, 'index']);
            Route::post('/studio/fleet-config-snapshots', [AdminStudioFleetConfigSnapshotsController::class, 'store']);
            Route::get('/studio/fleet-config-snapshots/{id}', [AdminStudioFleetConfigSnapshotsController::class, 'show']);
            Route::get('/studio/production-fleet-snapshots', [AdminStudioProductionFleetSnapshotsController::class, 'index']);
            Route::post('/studio/production-fleet-snapshots', [AdminStudioProductionFleetSnapshotsController::class, 'store']);
            Route::get('/studio/production-fleet-snapshots/{id}', [AdminStudioProductionFleetSnapshotsController::class, 'show']);
            Route::get('/studio/run-artifacts', [AdminStudioRunArtifactsController::class, 'index']);
            Route::post('/studio/run-artifacts', [AdminStudioRunArtifactsController::class, 'store']);
            Route::get('/studio/run-artifacts/{id}', [AdminStudioRunArtifactsController::class, 'show']);

            // Audit Logs
            Route::get('/audit-logs', [AdminAuditLogsController::class, 'index']);
        });
    });

    Route::post('/ai-jobs', [AiJobController::class, 'store']);
    Route::get('/files', [FileController::class, 'index']);
    Route::post('/files/uploads', [FileController::class, 'createUpload']);
    Route::post('/videos/uploads', [VideoController::class, 'createUpload']);
    Route::get('/videos', [VideoController::class, 'index']);
    Route::post('/videos', [VideoController::class, 'store']);
    Route::get('/videos/{id}', [VideoController::class, 'show']);
    Route::patch('/videos/{id}', [VideoController::class, 'update']);
    Route::delete('/videos/{id}', [VideoController::class, 'destroy']);
    Route::post('/videos/{video}/publish', [VideoController::class, 'publish']);
    Route::post('/videos/{video}/unpublish', [VideoController::class, 'unpublish']);

    Route::resource('articles', ArticleController::class)->except(['edit']);
    Route::resource('activity-logs', ActivityLogController::class)->except(['edit']);
});
