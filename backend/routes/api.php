<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Middleware\EnsureCanManageStaff;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/health', function () {
    try {
        DB::connection()->getPdo();
        $database = 'connected';
    } catch (Throwable) {
        $database = 'unavailable';
    }

    return response()->json([
        'app' => config('app.name'),
        'status' => 'ok',
        'database' => $database,
    ]);
});

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::apiResource('patients', PatientController::class)->except('destroy');

    Route::get('/doctors', [ServiceController::class, 'doctors']);
    Route::apiResource('services', ServiceController::class)->only(['index', 'store', 'update']);
    Route::apiResource('appointments', AppointmentController::class)->only(['index', 'store', 'update']);

    Route::apiResource('bills', BillController::class)->only(['show', 'store', 'update']);
    Route::post('/bills/{bill}/items', [BillController::class, 'addItem']);
    Route::delete('/bills/{bill}/items/{item}', [BillController::class, 'removeItem']);
    Route::post('/bills/{bill}/payments', [BillController::class, 'addPayment']);

    Route::get('/doctor/queue', [ConsultationController::class, 'queue']);
    Route::get('/appointments/{appointment}/consultation', [ConsultationController::class, 'show']);
    Route::put('/appointments/{appointment}/consultation', [ConsultationController::class, 'save']);
    Route::get('/patients/{patient}/consultations', [ConsultationController::class, 'history']);
    Route::get('/medicines/suggest', [ConsultationController::class, 'suggestMedicines']);

    Route::middleware(EnsureCanManageStaff::class)->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
    });
});
