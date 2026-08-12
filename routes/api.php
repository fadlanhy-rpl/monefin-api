<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\GoalController;
use App\Http\Controllers\IncomeSettingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SpendingController;
use App\Http\Controllers\SpendingThresholdController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

// ─── Public Auth Routes (rate limited) ───────────────────────────────────────
Route::middleware('throttle:60,1')->group(function () {
    Route::post('/auth/login',           [AuthController::class, 'login']);
    Route::post('/auth/register',        [AuthController::class, 'register']);
    Route::post('/auth/verify-email',    [AuthController::class, 'verifyEmail']);
    Route::post('/auth/resend-otp',      [AuthController::class, 'resendOtp']);
    Route::post('/auth/forgot-password', [AuthController::class, 'forgotPassword']);
    Route::post('/auth/reset-password',  [AuthController::class, 'resetPassword']);
});

// ─── Google OAuth ─────────────────────────────────────────────────────────────
Route::get('/auth/google',          [AuthController::class, 'redirectToGoogle']);
Route::get('/auth/google/callback', [AuthController::class, 'handleGoogleCallback']);

// ─── Backward-compatible aliases (agar kode frontend lama tidak break) ────────
Route::post('/login',    [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

// ─── Protected Routes (requires Sanctum token) ───────────────────────────────
Route::middleware('auth:sanctum')->group(function () {

    // Auth
    Route::get('/auth/me',        [AuthController::class, 'me']);
    Route::post('/auth/logout',   [AuthController::class, 'logout']);
    Route::post('/auth/profile',  [AuthController::class, 'updateProfile']);
    Route::post('/auth/password', [AuthController::class, 'updatePassword']);

    // Backward-compatible aliases
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user',    [AuthController::class, 'me']);

    // Accounts
    Route::put('accounts/reorder', [AccountController::class, 'reorder']);
    Route::apiResource('accounts', AccountController::class);
    Route::post('accounts/{account}/adjust-balance', [AccountController::class, 'adjustBalance']);

    // Categories
    Route::get('/categories',             [CategoryController::class, 'index']);
    Route::post('/categories',            [CategoryController::class, 'store']);
    Route::put('/categories/{category}',  [CategoryController::class, 'update']);
    Route::delete('/categories/{category}', [CategoryController::class, 'destroy']);

    // Transactions
    Route::apiResource('transactions', TransactionController::class);

    // Budgets
    Route::apiResource('budgets', BudgetController::class);

    // Goals
    Route::post('goals/{goal}/deposit',  [GoalController::class, 'deposit']);
    Route::post('goals/{goal}/withdraw', [GoalController::class, 'withdraw']);
    Route::apiResource('goals', GoalController::class);

    // Income Settings
    Route::get('/income-settings',  [IncomeSettingController::class, 'show']);
    Route::post('/income-settings', [IncomeSettingController::class, 'store']);

    // Spending Thresholds
    Route::get('/spending-thresholds',  [SpendingThresholdController::class, 'show']);
    Route::post('/spending-thresholds', [SpendingThresholdController::class, 'store']);

    // Spending Analysis
    Route::get('/spending-status',        [SpendingController::class, 'status']);
    Route::get('/notifications',          [SpendingController::class, 'notifications']);
    Route::patch('/notifications/{id}/read', [SpendingController::class, 'markRead']);

    // Dashboard
    Route::get('/dashboard/summary', [DashboardController::class, 'summary']);

    // Reports
    Route::get('/reports/compare',            [ReportController::class, 'compare']);
    Route::get('/reports/export',             [ReportController::class, 'export']);
    Route::get('/reports/category-breakdown', [ReportController::class, 'categoryBreakdown']);
});
