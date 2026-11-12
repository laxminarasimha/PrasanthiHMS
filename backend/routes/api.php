<?php

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\IpdController;
use App\Http\Controllers\Api\LabController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\ReportController;
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
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/appointments', [AppointmentController::class, 'index']);

    Route::middleware('ability:canManageSetup')->group(function () {
        Route::post('/services', [ServiceController::class, 'store']);
        Route::put('/services/{service}', [ServiceController::class, 'update']);
    });

    Route::middleware('ability:canWorkFrontDesk')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
    });

    Route::apiResource('bills', BillController::class)->only(['show', 'store', 'update']);
    Route::post('/bills/{bill}/items', [BillController::class, 'addItem']);
    Route::delete('/bills/{bill}/items/{item}', [BillController::class, 'removeItem']);
    Route::post('/bills/{bill}/payments', [BillController::class, 'addPayment']);

    Route::get('/doctor/queue', [ConsultationController::class, 'queue']);
    Route::get('/appointments/{appointment}/consultation', [ConsultationController::class, 'show']);
    Route::put('/appointments/{appointment}/consultation', [ConsultationController::class, 'save']);
    Route::get('/patients/{patient}/consultations', [ConsultationController::class, 'history']);
    Route::get('/medicines/suggest', [ConsultationController::class, 'suggestMedicines']);

    Route::get('/lab/tests', [LabController::class, 'tests']);
    Route::post('/lab/tests', [LabController::class, 'storeTest']);
    Route::put('/lab/tests/{test}', [LabController::class, 'updateTest']);
    Route::get('/lab/orders', [LabController::class, 'orders']);
    Route::post('/lab/orders', [LabController::class, 'storeOrder']);
    Route::get('/lab/orders/{order}', [LabController::class, 'showOrder']);
    Route::put('/lab/orders/{order}/items/{item}', [LabController::class, 'saveResults']);

    Route::prefix('pharmacy')->controller(PharmacyController::class)->group(function () {
        Route::get('/medicines', 'medicines');
        Route::post('/medicines', 'storeMedicine');
        Route::put('/medicines/{medicine}', 'updateMedicine');
        Route::get('/stockists', 'stockists');
        Route::post('/stockists', 'storeStockist');
        Route::put('/stockists/{stockist}', 'updateStockist');
        Route::get('/purchases', 'purchases');
        Route::post('/purchases', 'storePurchase');
        Route::get('/purchases/{purchase}', 'showPurchase');
        Route::get('/stock', 'stock');
        Route::get('/sell-search', 'sellSearch');
        Route::get('/prescriptions', 'prescriptions');
        Route::get('/sales', 'sales');
        Route::post('/sales', 'storeSale');
        Route::get('/sales/{sale}', 'showSale');
    });

    Route::prefix('ipd')->controller(IpdController::class)->group(function () {
        // Anyone signed in can look; changing things needs the right role.
        Route::get('/beds', 'beds');
        Route::get('/services', 'services');
        Route::get('/admissions', 'admissions');
        Route::get('/admissions/{admission}', 'show');
        Route::get('/admissions/{admission}/outstanding', 'outstanding');

        Route::middleware('ability:canManageSetup')->group(function () {
            Route::post('/beds', 'storeBed');
            Route::put('/beds/{bed}', 'updateBed');
            Route::post('/services', 'storeService');
            Route::put('/services/{service}', 'updateService');
        });

        Route::middleware('ability:canWorkIpd')->group(function () {
            Route::post('/admissions', 'admit');
            Route::put('/admissions/{admission}', 'update');
            Route::post('/admissions/{admission}/charges', 'addCharge');
            Route::delete('/admissions/{admission}/charges/{charge}', 'removeCharge');
            Route::post('/admissions/{admission}/payments', 'addPayment');
            Route::post('/admissions/{admission}/discharge', 'discharge');
            Route::post('/admissions/{admission}/transfer', 'transfer');
        });
    });

    Route::get('/reports/summary', [ReportController::class, 'summary']);

    Route::middleware(EnsureCanManageStaff::class)->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
    });
});
