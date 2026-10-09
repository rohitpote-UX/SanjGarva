<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExpenseCategoryController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\StockController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('health', function () {
    $dbStatus = 'unknown';
    $dbError = null;
    $tableCount = 0;
    $hasUsersTable = false;

    try {
        DB::connection()->getPdo();
        $dbStatus = 'connected';
        if (DB::getDriverName() === 'pgsql') {
            $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
            $tableNames = array_map(fn ($r) => $r->table_name ?? '', $tables);
        } else {
            $tables = DB::select("SELECT name as table_name FROM sqlite_master WHERE type='table'");
            $tableNames = array_map(fn ($r) => $r->table_name ?? '', $tables);
        }
        $tableCount = count($tables);
        $hasUsersTable = in_array('users', $tableNames, true);
    } catch (\Throwable $e) {
        $dbStatus = 'failed';
        $dbError = $e->getMessage();
    }

    $activeDriver = config('database.default');
    $connConfig = config("database.connections.{$activeDriver}", []);
    $rawHost = (string) ($connConfig['host'] ?? '');
    $isLocalHost = in_array($rawHost, ['127.0.0.1', 'localhost', '::1', ''], true);

    $errorCategory = 'healthy';
    if ($dbStatus === 'failed') {
        if ($isLocalHost && empty(env('DB_URL')) && empty(env('DATABASE_URL')) && empty(env('POSTGRES_URL'))) {
            $errorCategory = 'connection_refused_local_fallback';
        } elseif (str_contains((string) $dbError, 'password authentication failed') || str_contains((string) $dbError, 'no password supplied')) {
            $errorCategory = 'authentication_failed';
        } elseif (str_contains((string) $dbError, 'SSL') || str_contains((string) $dbError, 'certificate') || str_contains((string) $dbError, 'tls')) {
            $errorCategory = 'ssl_tls_error';
        } elseif (str_contains((string) $dbError, 'getaddrinfo') || str_contains((string) $dbError, 'Name or service not known')) {
            $errorCategory = 'dns_resolution_failed';
        } elseif (str_contains((string) $dbError, 'Connection refused')) {
            $errorCategory = 'connection_refused';
        } else {
            $errorCategory = 'connection_failed';
        }
    } elseif ($tableCount === 0 || ! $hasUsersTable) {
        $errorCategory = 'unmigrated_database';
    }

    $diagnostic = match ($errorCategory) {
        'connection_refused_local_fallback' => 'Database connection failed: application is attempting to connect to 127.0.0.1. Configure DATABASE_URL or DB_URL in Vercel environment variables.',
        'connection_refused' => 'Database server refused connection. Verify remote host and port accessibility.',
        'authentication_failed' => 'Database authentication failed. Verify database username and password in connection string.',
        'ssl_tls_error' => 'Database TLS/SSL error. Ensure sslmode=require is configured.',
        'dns_resolution_failed' => 'Database host address could not be resolved.',
        'unmigrated_database' => 'Database connected, but required tables (users) have not been migrated yet.',
        'connection_failed' => 'Database connection could not be established.',
        default => 'All systems operational',
    };

    $isReady = $dbStatus === 'connected' && $tableCount > 0 && $hasUsersTable;

    return response()->json([
        'status' => $isReady ? 'ready' : 'degraded',
        'app' => config('app.name'),
        'environment' => config('app.env'),
        'database' => [
            'status' => $dbStatus,
            'driver' => $activeDriver,
            'table_count' => $tableCount,
            'has_users_table' => $hasUsersTable,
            'host_type' => $isLocalHost ? 'local' : 'remote',
            'error_category' => $errorCategory,
            'diagnostic' => $diagnostic,
            'configuration_presence' => [
                'has_db_url' => ! empty(env('DB_URL')),
                'has_database_url' => ! empty(env('DATABASE_URL')),
                'has_postgres_url' => ! empty(env('POSTGRES_URL')),
                'has_neon_url' => ! empty(env('NEON_DATABASE_URL')) || ! empty(env('NEON_URL')),
                'has_remote_host' => ! empty(env('DB_HOST')) && ! in_array(env('DB_HOST'), ['127.0.0.1', 'localhost']),
                'has_app_key' => ! empty(env('APP_KEY')),
            ],
            'detected_env_database_keys' => array_values(array_filter(
                array_keys(array_merge($_SERVER, $_ENV)),
                fn ($k) => (bool) preg_match('/^(DATABASE_|DB_|POSTGRES_|NEON_)/i', $k)
            )),
        ],
        'timestamp' => now()->toIso8601String(),
    ], $isReady ? 200 : 503);
});

Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('auth/me', [AuthController::class, 'me']);
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::put('auth/password', [AuthController::class, 'changePassword']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::put('settings', [SettingsController::class, 'update']);
    Route::post('settings/logo', [SettingsController::class, 'uploadLogo']);
    Route::delete('settings/logo', [SettingsController::class, 'removeLogo']);

    Route::get('dashboard', DashboardController::class);

    Route::apiResource('categories', CategoryController::class)->except('show');
    Route::apiResource('products', ProductController::class);

    // Inventory
    Route::get('store-stock', [StockController::class, 'store']);
    Route::post('store-stock/add', [StockController::class, 'add']);
    Route::get('shop-stock', [StockController::class, 'shop']);
    Route::get('purchases', [StockController::class, 'purchases']);
    Route::post('stock/transfer', [StockController::class, 'transfer']);
    Route::post('stock/adjust', [StockController::class, 'adjust']);
    Route::get('stock/adjustments', [StockController::class, 'adjustments']);
    Route::get('stock/movements', [StockController::class, 'movements']);

    // Sales
    Route::get('sales', [SaleController::class, 'index']);
    Route::post('sales', [SaleController::class, 'store']);
    Route::get('sales/{sale}', [SaleController::class, 'show']);
    Route::post('sales/{sale}/void', [SaleController::class, 'void']);

    // Customers / udhari
    Route::apiResource('customers', CustomerController::class);
    Route::get('customers/{customer}/ledger', [CustomerController::class, 'ledger']);
    Route::post('customers/{customer}/payment', [CustomerController::class, 'payment']);

    // Expenses
    Route::apiResource('expense-categories', ExpenseCategoryController::class)->except('show');
    Route::apiResource('expenses', ExpenseController::class)->except('show');

    // Reports & export
    Route::get('reports/sales', [ReportController::class, 'sales']);
    Route::get('reports/stock', [ReportController::class, 'stock']);
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss']);
    Route::get('reports/udhari', [ReportController::class, 'udhari']);
    Route::get('reports/expenses', [ReportController::class, 'expenses']);
    Route::get('export/{type}', ExportController::class);
});
