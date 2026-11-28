<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\AuditController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BackupController;
use App\Http\Controllers\Api\BillController;
use App\Http\Controllers\Api\CaseHistoryController;
use App\Http\Controllers\Api\ChargeController;
use App\Http\Controllers\Api\ConsultantController;
use App\Http\Controllers\Api\ConsultationController;
use App\Http\Controllers\Api\DayCloseController;
use App\Http\Controllers\Api\DoctorLibraryController;
use App\Http\Controllers\Api\DrugReferenceController;
use App\Http\Controllers\Api\ExpenseController;
use App\Http\Controllers\Api\FormController;
use App\Http\Controllers\Api\FrontDeskController;
use App\Http\Controllers\Api\HealthSummaryController;
use App\Http\Controllers\Api\HospitalReportController;
use App\Http\Controllers\Api\IpdChartController;
use App\Http\Controllers\Api\IpdController;
use App\Http\Controllers\Api\IpdRecordsController;
use App\Http\Controllers\Api\LabController;
use App\Http\Controllers\Api\MedicalRepController;
use App\Http\Controllers\Api\RepCatalogueController;
use App\Http\Controllers\Api\PastRecordController;
use App\Http\Controllers\Api\PatientAccountController;
use App\Http\Controllers\Api\PatientAppController;
use App\Http\Controllers\Api\PatientAppExtrasController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PatientDocumentController;
use App\Http\Controllers\Api\PharmacyAuditController;
use App\Http\Controllers\Api\PharmacyController;
use App\Http\Controllers\Api\PharmacyEditController;
use App\Http\Controllers\Api\PharmacyManagerController;
use App\Http\Controllers\Api\PharmacyReportController;
use App\Http\Controllers\Api\PharmacySalesController;
use App\Http\Controllers\Api\PharmacyStockController;
use App\Http\Controllers\Api\PushController;
use App\Http\Controllers\Api\ToDoController;
use App\Http\Controllers\Api\RecordReaderController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\StaffController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Middleware\EnsureCanManageStaff;
use App\Http\Middleware\KeepPrices;
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

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1,staff-login');
Route::post('/login/google', [AuthController::class, 'googleLogin'])->middleware('throttle:10,1,staff-google');
// The hospital's name and logo, for the sign-in page.
Route::get('/brand', [AdminController::class, 'brand'])->middleware('throttle:60,1,brand');

// ---- The patient's mobile app: its own logins, kept apart from staff ----
Route::prefix('app')->controller(PatientAppController::class)->group(function () {
    Route::post('/register', 'register')->middleware('throttle:6,1,app-register');
    Route::post('/login', 'login')->middleware('throttle:10,1,app-login');
    Route::post('/google', 'google')->middleware('throttle:10,1,app-google');
    Route::post('/google/register', 'googleRegister')->middleware('throttle:6,1,app-register');

    Route::middleware(['auth:sanctum', 'actor:patient'])->group(function () {
        Route::get('/me', 'me');
        Route::post('/logout', 'logout');
        Route::post('/password', 'changePassword')->middleware('throttle:10,1');
        Route::post('/google/connect', 'connectGoogle')->middleware('throttle:10,1');
        Route::delete('/google/connect', 'disconnectGoogle');
        Route::get('/hospital', 'hospitalInfo');
        Route::get('/records', 'records');
        Route::get('/doctors', 'doctors');
        Route::get('/doctors/{doctor}/slots', 'slots');
        Route::get('/appointments', 'appointments');
        Route::post('/appointments', 'book')->middleware('throttle:20,1');
        Route::delete('/appointments/{appointment}', 'cancel');
        Route::put('/appointments/{appointment}', 'move')->middleware('throttle:20,1');
        Route::get('/medicines', 'medicines');
        Route::get('/subscriptions', 'subscriptions');
        Route::post('/subscriptions', 'storeSubscription');
        Route::put('/subscriptions/{subscription}', 'updateSubscription');
        Route::delete('/subscriptions/{subscription}', 'cancelSubscription');
    });
});
Route::prefix('app')->middleware(['auth:sanctum', 'actor:patient'])->controller(PatientAppExtrasController::class)->group(function () {
    Route::get('/home', 'home');
    Route::get('/health', 'health');
    Route::get('/bills', 'bills');
    Route::get('/family', 'family');
    Route::post('/family', 'addMember')->middleware('throttle:20,1');
    Route::delete('/family/{patient}', 'removeMember');
    Route::post('/account/delete', 'deleteAccount')->middleware('throttle:10,1');
});

// The hospital's reading computer, which signs in with its own key and can only fetch and return scans to read.
Route::prefix('reader')->middleware('throttle:240,1,reader')->controller(RecordReaderController::class)->group(function () {
    Route::post('/hello', 'hello');
    Route::post('/next', 'next');
    Route::get('/pages/{attachment}', 'page');
    Route::post('/records/{record}/done', 'done');
});

Route::middleware(['auth:sanctum', 'actor:staff', 'limits', 'activity', 'money'])->group(function () {
    Route::middleware('ability:canRunPharmacy')->controller(SubscriptionController::class)->group(function () {
        Route::get('/pharmacy/subscriptions', 'index');
        Route::post('/pharmacy/subscriptions/{subscription}/prepared', 'prepared');
    });
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::post('/account/google', [AuthController::class, 'connectGoogle'])->middleware('throttle:10,1');
    Route::delete('/account/google', [AuthController::class, 'disconnectGoogle']);
    Route::get('/backups', [BackupController::class, 'index']);
    Route::post('/backups', [BackupController::class, 'store'])->middleware('throttle:6,1');
    Route::get('/backups/files', [BackupController::class, 'files'])->middleware('throttle:20,1');
    Route::post('/backups/files/done', [BackupController::class, 'filesDone']);
    Route::get('/backups/{name}/download', [BackupController::class, 'download']);
    Route::put('/me/tabs', [AuthController::class, 'saveTabs']);
    Route::put('/me/bar', [AuthController::class, 'saveBar']);
    // Medical representatives: the companies, their people, and each visit to a doctor, logged at the front desk.
    Route::prefix('reps')->controller(MedicalRepController::class)->group(function () {
        Route::get('/visits', 'visits');
        Route::get('/waiting', 'waiting');
        Route::post('/visits', 'logVisit');
        Route::put('/visits/{visit}', 'updateVisit');
        Route::delete('/visits/{visit}', 'removeVisit');
        Route::get('/people', 'reps');
        Route::post('/people', 'storeRep');
        Route::get('/people/{rep}', 'showRep');
        Route::put('/people/{rep}', 'updateRep');
        Route::get('/companies', 'companies');
        Route::put('/companies/{company}', 'updateCompany');
        Route::post('/companies/{company}/files', 'storeFile');
        Route::get('/files/{file}/download', 'downloadFile');
        Route::delete('/files/{file}', 'removeFile');
        Route::put('/hours', 'saveHours');
    });
    // The companies' catalogues: each brand, what it contains and what is on offer.
    Route::prefix('reps/products')->controller(RepCatalogueController::class)->group(function () {
        Route::get('/', 'index');
        Route::post('/', 'store');
        Route::post('/sheet', 'sheet');
        Route::put('/{product}', 'update');
        Route::delete('/{product}', 'destroy');
    });
    // Each person's To do screen: the work waiting for them, and the tasks written for them.
    Route::controller(ToDoController::class)->group(function () {
        Route::get('/todo', 'index');
        Route::get('/todo/count', 'count');
        Route::get('/tasks/given', 'given');
        Route::get('/tasks/people', 'people');
        Route::post('/tasks', 'store')->middleware('throttle:60,1');
        Route::put('/tasks/{task}', 'update');
        Route::post('/tasks/{task}/done', 'done');
        Route::post('/tasks/{task}/reopen', 'reopen');
        Route::delete('/tasks/{task}', 'destroy');
    });
    // Notifications on the person's own phone or computer.
    Route::get('/me/push/key', [PushController::class, 'key']);
    Route::post('/me/push', [PushController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/me/push', [PushController::class, 'destroy']);
    Route::post('/me/push/test', [PushController::class, 'test'])->middleware('throttle:6,1');
    Route::put('/me/appearance', [AuthController::class, 'saveAppearance']);
    Route::post('/me/password', [AuthController::class, 'changePassword'])->middleware('throttle:10,1');

    // Before the patient routes below, so "recent" is not read as a patient's number.
    Route::get('/patients/recent', [PatientController::class, 'recent']);
    Route::get('/patients/export', [PatientController::class, 'export']);
    Route::apiResource('patients', PatientController::class)->except('destroy');

    Route::get('/doctors', [ServiceController::class, 'doctors']);
    Route::get('/services', [ServiceController::class, 'index']);
    Route::get('/appointments', [AppointmentController::class, 'index']);
    Route::get('/appointments/calendar', [AppointmentController::class, 'calendar']);
    Route::get('/appointments/follow-up', [AppointmentController::class, 'followUp']);

    Route::middleware('ability:canManageSetup')->group(function () {
        // A price is set only by someone allowed to set prices; the rest of a service is setup like any other.
        Route::post('/services', [ServiceController::class, 'store'])->middleware(KeepPrices::class);
        Route::put('/services/{service}', [ServiceController::class, 'update'])->middleware(KeepPrices::class);
        Route::put('/appointments/calendar-settings', [AppointmentController::class, 'saveCalendarSettings']);
    });

    Route::middleware('ability:canWorkFrontDesk')->group(function () {
        Route::post('/appointments', [AppointmentController::class, 'store']);
        Route::put('/appointments/{appointment}', [AppointmentController::class, 'update']);
        Route::post('/doctor-blocks', [AppointmentController::class, 'storeBlock']);
        Route::delete('/doctor-blocks/{block}', [AppointmentController::class, 'destroyBlock']);
    });

    Route::apiResource('bills', BillController::class)->only(['index', 'show', 'store', 'update']);
    Route::post('/bills/{bill}/use-deposit', [BillController::class, 'useDeposit'])->middleware('ability:canWorkFrontDesk');
    Route::post('/patients/temporary', [PatientController::class, 'storeTemporary']);
    Route::post('/bills/{bill}/items', [BillController::class, 'addItem']);
    Route::delete('/bills/{bill}/items/{item}', [BillController::class, 'removeItem']);
    Route::post('/bills/{bill}/payments', [BillController::class, 'addPayment']);
    Route::post('/bills/{bill}/cancel', [BillController::class, 'cancel']);
    Route::put('/bills/{bill}/payments/{payment}', [BillController::class, 'updatePayment']);

    // Day-to-day expenses, written down by whoever keeps the cash.
    // Bigger ones go for approval first; salaries and advances are written in by payroll and advances themselves.
    Route::middleware('ability:canRecordExpenses')->controller(ExpenseController::class)->group(function () {
        Route::get('/expenses', 'index');
        Route::post('/expenses', 'store');
        Route::get('/expenses/summary', 'summary');
        Route::get('/expenses/export', 'export');
        Route::get('/expenses/headings', 'headings');
        Route::post('/expenses/headings', 'saveHeading');
        Route::put('/expenses/headings/{heading}', 'saveHeading')->whereNumber('heading');
        Route::get('/expenses/repeating', 'templates');
        Route::post('/expenses/repeating', 'saveTemplate');
        Route::put('/expenses/repeating/{template}', 'saveTemplate')->whereNumber('template');
        Route::post('/expenses/repeating/{template}/use', 'useTemplate')->whereNumber('template');
        Route::put('/expenses/{expense}', 'update')->whereNumber('expense');
        Route::post('/expenses/{expense}/submit', 'submit')->whereNumber('expense');
        Route::post('/expenses/{expense}/decide', 'decide')->whereNumber('expense');
        Route::post('/expenses/{expense}/pay', 'pay')->whereNumber('expense');
        Route::post('/expenses/{expense}/cancel', 'cancel')->whereNumber('expense');
        Route::post('/expenses/{expense}/receipt', 'attachReceipt')->whereNumber('expense');
        Route::get('/expenses/{expense}/receipt', 'receipt')->whereNumber('expense');
    });

    // Billing from the front desk in one go: services and lab tests together, then one payment for all of it.
    Route::middleware('ability:canWorkFrontDesk')->controller(ChargeController::class)->group(function () {
        Route::get('/front-desk/due', 'due');
        Route::get('/front-desk/day-bills', 'day');
        Route::post('/front-desk/charges', 'store');
        Route::post('/front-desk/collect', 'collect');
    });

    Route::controller(FrontDeskController::class)->group(function () {
        Route::get('/front-desk/bills', 'walkInBills');
        Route::get('/front-desk/summary', 'summary');
        Route::get('/front-desk/money', 'money');
        Route::get('/front-desk/queue', 'queue');
        Route::get('/front-desk/follow-ups', 'followUps');
        Route::get('/front-desk/not-returned', 'notReturned');
        Route::get('/patients/{patient}/overview', 'overview');
        Route::get('/patients/{patient}/attachments', 'attachments');
        Route::get('/attachments/{attachment}/download', 'download');

        Route::middleware('ability:canWorkFrontDesk')->group(function () {
            Route::put('/appointments/{appointment}/vitals', 'saveVitals');
            Route::post('/patients/{patient}/attachments', 'storeAttachment');
            Route::post('/patients/{patient}/deposits', 'storeDeposit');
            Route::delete('/attachments/{attachment}', 'destroyAttachment');
        });
    });

    // Old papers a patient brings: scanned, read into a draft, checked, then filed.
    Route::controller(PastRecordController::class)->group(function () {
        Route::middleware('ability:canManageSetup')->group(function () {
            Route::get('/past-records/setup', 'setup');
            Route::post('/past-records/setup/reader-key', 'newReaderKey');
            Route::delete('/past-records/setup/reader-key', 'removeReaderKey');
        });
        Route::middleware('ability:canFilePastRecords')->group(function () {
            Route::get('/past-records', 'index');
            Route::get('/patients/{patient}/past-records', 'forPatient');
            Route::post('/patients/{patient}/past-records', 'store');
            Route::get('/past-records/{record}', 'show');
            Route::put('/past-records/{record}', 'update');
            Route::post('/past-records/{record}/read', 'read');
            Route::post('/past-records/{record}/file', 'file');
            Route::delete('/past-records/{record}', 'destroy');
        });
    });

    Route::get('/doctor/queue', [ConsultationController::class, 'queue']);
    Route::post('/doctor/visits', [CaseHistoryController::class, 'startVisit'])->middleware('ability:canConsult');
    Route::get('/doctor/abnormal-results', [CaseHistoryController::class, 'abnormalResults'])->middleware('ability:canConsult');
    Route::controller(HealthSummaryController::class)->group(function () {
        Route::get('/patients/{patient}/health', 'show');
        Route::post('/patients/{patient}/health', 'store');
        Route::put('/patients/{patient}/health/allergies', 'allergies');
        Route::put('/health-items/{item}', 'update');
        Route::delete('/health-items/{item}', 'destroy');
    });
    Route::controller(PatientDocumentController::class)->group(function () {
        Route::get('/patients/{patient}/documents', 'index');
        Route::get('/patients/{patient}/documents/draft', 'draft');
        Route::post('/patients/{patient}/documents', 'store');
        Route::get('/documents/{document}', 'show');
        Route::post('/documents/{document}/cancel', 'cancel');
    });
    Route::controller(PatientAccountController::class)->middleware('ability:canWorkFrontDesk')->group(function () {
        Route::get('/patients/{patient}/app-account', 'show');
        Route::post('/patients/{patient}/app-account/verify', 'verify');
        Route::post('/patients/{patient}/app-account/password', 'resetPassword');
        Route::post('/patients/{patient}/app-account/active', 'setActive');
    });
    Route::get('/patients/{patient}/case-history', [CaseHistoryController::class, 'show']);
    Route::get('/patients/{patient}/trends', [CaseHistoryController::class, 'trends']);

    Route::prefix('doctor')->controller(DoctorLibraryController::class)->group(function () {
        Route::get('/consults', 'consults');
        Route::get('/library', 'show');
        Route::post('/phrases', 'storePhrase');
        Route::put('/phrases/{phrase}', 'updatePhrase');
        Route::delete('/phrases', 'destroyPhrases');
        Route::post('/rx-groups', 'storeRxGroup');
        Route::put('/rx-groups/{group}', 'updateRxGroup');
        Route::delete('/rx-groups/{group}', 'destroyRxGroup');
        Route::post('/templates', 'storeTemplate');
        Route::delete('/templates/{template}', 'destroyTemplate');
        Route::post('/medicine-defaults', 'storeMedicineDefault');
        Route::put('/medicine-defaults/{default}', 'updateMedicineDefault');
        Route::delete('/medicine-defaults/{default}', 'destroyMedicineDefault');
        Route::put('/rx-settings', 'saveRxSettings');
        Route::post('/seal', 'saveSeal');
        Route::put('/pad-sections', 'savePadSections');
    });
    Route::get('/appointments/{appointment}/consultation', [ConsultationController::class, 'show']);
    Route::put('/appointments/{appointment}/consultation', [ConsultationController::class, 'save']);
    Route::post('/appointments/{appointment}/consultation/amendments', [ConsultationController::class, 'amend']);
    Route::post('/patients/{patient}/prescription-check', [ConsultationController::class, 'checkPrescription']);
    Route::get('/patients/{patient}/audit-log', [AuditController::class, 'forPatient']);
    Route::get('/patients/{patient}/consultations', [ConsultationController::class, 'history']);
    Route::get('/medicines/suggest', [ConsultationController::class, 'suggestMedicines']);
    Route::get('/medicines/find', [ConsultationController::class, 'findMedicines']);
    Route::get('/medicines/describe', [ConsultationController::class, 'describeMedicines']);
    Route::get('/icd-codes', [ConsultationController::class, 'icdCodes']);

    Route::get('/lab/tests', [LabController::class, 'tests']);
    Route::post('/lab/tests', [LabController::class, 'storeTest']);
    Route::put('/lab/tests/order', [LabController::class, 'orderTests']);
    Route::get('/lab/tests/department-suggestions', [LabController::class, 'departmentSuggestions']);
    Route::put('/lab/tests/departments', [LabController::class, 'assignDepartments']);
    Route::put('/lab/tests/{test}', [LabController::class, 'updateTest']);
    Route::get('/lab/tests/{test}/versions', [LabController::class, 'versions']);
    Route::get('/lab/orders', [LabController::class, 'orders']);
    Route::post('/lab/orders', [LabController::class, 'storeOrder']);
    Route::get('/lab/orders/{order}', [LabController::class, 'showOrder']);
    Route::put('/lab/orders/{order}/items/{item}', [LabController::class, 'saveResults']);
    Route::put('/lab/orders/{order}/items/{item}/collect', [LabController::class, 'collectSample']);
    Route::put('/lab/orders/{order}/items/{item}/outside', [LabController::class, 'outside']);
    Route::get('/lab/outside-labs', [LabController::class, 'outsideLabs']);
    Route::put('/lab/orders/{order}/items/{item}/approve', [LabController::class, 'approve']);
    Route::put('/lab/orders/{order}/collect', [LabController::class, 'collectAll']);
    Route::put('/lab/orders/{order}/approve', [LabController::class, 'approveAll']);
    // One payment over several of a patient's lab bills. Who may take money on each bill is checked bill by bill.
    Route::post('/lab/collect', [ChargeController::class, 'collect']);
    Route::get('/lab/packages', [LabController::class, 'packages']);
    Route::post('/lab/packages', [LabController::class, 'storePackage']);
    Route::put('/lab/packages/{package}', [LabController::class, 'updatePackage']);
    Route::post('/lab/orders/{order}/files', [LabController::class, 'storeFile']);
    Route::get('/lab/files/{file}/download', [LabController::class, 'downloadFile']);
    Route::delete('/lab/files/{file}', [LabController::class, 'destroyFile']);
    Route::post('/lab/settings/signature', [LabController::class, 'saveSignature']);
    Route::delete('/lab/settings/signature/{slot}', [LabController::class, 'destroySignature']);
    Route::get('/lab/settings', [LabController::class, 'settings']);
    Route::put('/lab/settings', [LabController::class, 'saveSettings']);

    // The national drug list (Common Drug Codes for India) and linking medicines to it.
    Route::prefix('pharmacy')->controller(DrugReferenceController::class)->group(function () {
        Route::get('/national-list', 'status');
        Route::get('/national-list/next', 'next');
        Route::get('/national-list/search', 'search');
        Route::get('/national-list/medicines/{medicine}', 'show');
        Route::post('/medicines/{medicine}/national-link', 'link');
        Route::delete('/medicines/{medicine}/national-link', 'unlink');
    });

    Route::prefix('pharmacy')->controller(PharmacyController::class)->group(function () {
        Route::get('/medicines', 'medicines');
        Route::get('/medicines/options', 'medicineOptions');
        Route::post('/medicines/compositions/import', 'importCompositions');
        Route::post('/medicines', 'storeMedicine');
        Route::put('/medicines/{medicine}', 'updateMedicine');
        Route::put('/medicines/{medicine}/composition', 'setComposition');
        Route::get('/stockists', 'stockists');
        Route::get('/stockists/{stockist}', 'showStockist');
        Route::post('/stockists', 'storeStockist');
        Route::put('/stockists/{stockist}', 'updateStockist');
        Route::get('/purchases', 'purchases');
        Route::post('/purchases', 'storePurchase');
        Route::get('/purchases/{purchase}', 'showPurchase');
        Route::get('/stock', 'stock');
        Route::get('/sell-search', 'sellSearch');
        Route::get('/shelves', 'shelves');
        Route::put('/shelves', 'placeMedicines');
        Route::get('/prescriptions', 'prescriptions');
        Route::get('/inpatients', 'inpatients');
        Route::get('/sales', 'sales');
        Route::post('/sales', 'storeSale');
        Route::get('/sales/{sale}', 'showSale');
    });

    Route::prefix('pharmacy')->controller(PharmacySalesController::class)->group(function () {
        Route::get('/dashboard', 'dashboard');
        Route::get('/money', 'money');
        Route::get('/customer-bills', 'customerBills');
        Route::post('/collect', 'collectMany');
        Route::get('/orders', 'orders');
        Route::post('/sales/{sale}/payments', 'collect');
        Route::post('/sales/{sale}/returns', 'returnMedicines');
        Route::delete('/sales/{sale}', 'void');
    });

    Route::prefix('pharmacy')->controller(PharmacyManagerController::class)->group(function () {
        Route::get('/manager', 'show');
        Route::post('/manager/orders', 'makeOrders');
        Route::get('/stockists/{stockist}/statement', 'statement');
    });

    Route::prefix('pharmacy')->controller(PharmacyStockController::class)->group(function () {
        Route::get('/manufacturers', 'manufacturers');
        Route::post('/manufacturers', 'storeManufacturer');
        Route::put('/manufacturers/{manufacturer}', 'updateManufacturer');
        Route::post('/opening-stock', 'openingStock');
        Route::post('/opening-stock/import', 'importOpeningStock');
        Route::get('/inventory', 'inventory');
        Route::post('/purchases/{purchase}/payments', 'payInvoice');
        Route::post('/stockists/{stockist}/payments', 'payStockist');
        Route::get('/stock-returns', 'stockReturns');
        Route::post('/stock-returns', 'storeStockReturn');
        Route::get('/purchase-orders', 'purchaseOrders');
        Route::post('/purchase-orders', 'storePurchaseOrder');
        Route::get('/purchase-orders/{order}', 'showPurchaseOrder');
        Route::put('/purchase-orders/{order}', 'updatePurchaseOrder');
    });

    // Stock audit: counting the shelf against the records, approving the differences, and seeing where stock went.
    Route::prefix('pharmacy')->controller(PharmacyAuditController::class)->group(function () {
        Route::post('/stock/{batch}/adjust', 'correctBatch');
        Route::get('/audit/overview', 'overview');
        Route::get('/audit/scopes', 'scopes');
        Route::get('/audit/batches', 'findBatches');
        Route::get('/audit/missing', 'missing');
        Route::get('/audit/history', 'history');
        Route::get('/audit/counts', 'counts');
        Route::post('/audit/counts', 'storeCount');
        Route::get('/audit/counts/{count}', 'showCount');
        Route::put('/audit/counts/{count}/lines/{line}', 'countLine');
        Route::post('/audit/counts/{count}/lines', 'addLine');
        Route::post('/audit/counts/{count}/submit', 'submit');
        Route::put('/audit/counts/{count}/review', 'review');
        Route::post('/audit/counts/{count}/reopen', 'reopen');
        Route::post('/audit/counts/{count}/approve', 'approve');
        Route::post('/audit/counts/{count}/cancel', 'cancel');
    });

    Route::prefix('pharmacy')->controller(PharmacyEditController::class)->group(function () {
        Route::put('/sales/{sale}', 'updateSale');
        Route::put('/sales/{sale}/payments/{payment}', 'updatePayment');
        Route::put('/purchases/{purchase}', 'updatePurchase');
        Route::put('/stock/{batch}', 'updateBatch');
    });

    Route::get('/pharmacy/reports/{type}', [PharmacyReportController::class, 'show']);

    Route::prefix('ipd/admissions/{admission}/chart')->controller(IpdChartController::class)->group(function () {
        Route::get('/', 'show');
        Route::post('/vitals', 'storeVitals');
        Route::delete('/vitals/{vital}', 'destroyVitals');
        Route::post('/orders', 'storeOrder');
        Route::post('/orders/{order}/stop', 'stopOrder');
        Route::post('/orders/{order}/doses', 'storeDose');
        Route::delete('/doses/{dose}', 'destroyDose');
        Route::post('/fluids', 'storeFluid');
        Route::delete('/fluids/{fluid}', 'destroyFluid');
    });

    Route::prefix('ipd')->controller(IpdController::class)->group(function () {
        // Anyone signed in can look; changing things needs the right role.
        Route::get('/beds', 'beds');
        Route::get('/services', 'services');
        Route::get('/round-times', 'roundTimes');
        Route::get('/bed-charging', 'bedCharging');
        Route::get('/admissions', 'admissions');
        Route::get('/admissions/{admission}', 'show');
        Route::get('/admissions/{admission}/outstanding', 'outstanding');
        Route::get('/admissions/{admission}/investigations', 'investigations');

        Route::middleware('ability:canManageSetup')->group(function () {
            Route::post('/beds', 'storeBed')->middleware(KeepPrices::class);
            Route::put('/beds/{bed}', 'updateBed')->middleware(KeepPrices::class);
            Route::delete('/beds/{bed}', 'destroyBed');
            Route::post('/services', 'storeService')->middleware(KeepPrices::class);
            Route::put('/services/{service}', 'updateService')->middleware(KeepPrices::class);
            Route::put('/round-times', 'saveRoundTimes');
            Route::put('/bed-charging', 'saveBedCharging');
        });

        Route::post('/admissions/{admission}/approve-bill', 'approveBill');
        Route::post('/admissions/{admission}/reopen', 'reopen');

        Route::middleware('ability:canWorkIpd')->group(function () {
            Route::post('/admissions/{admission}/templates/{template}', 'applyTemplate');
            Route::post('/admissions', 'admit');
            Route::put('/admissions/{admission}', 'update');
            Route::post('/admissions/{admission}/charges', 'addCharge');
            Route::put('/admissions/{admission}/charges/{charge}', 'updateCharge');
            Route::delete('/admissions/{admission}/charges/{charge}', 'removeCharge');
            Route::delete('/admissions/{admission}/payments/{payment}', 'removePayment');
            Route::post('/admissions/{admission}/payments', 'addPayment');
            Route::put('/admissions/{admission}/payments/{payment}', 'updatePayment');
            Route::post('/admissions/{admission}/discharge', 'discharge');
            Route::post('/admissions/{admission}/discharge-time', 'correctDischarge');
            Route::post('/admissions/{admission}/transfer', 'transfer');
            Route::post('/admissions/{admission}/bills', 'makeBill');
            Route::delete('/admissions/{admission}/bills/{bill}', 'cancelBill');
        });
    });

    Route::get('/users/{user}/signature', [StaffController::class, 'signature']);
    Route::get('/users/{user}/seal', [StaffController::class, 'seal']);
    Route::prefix('ipd')->controller(IpdRecordsController::class)->group(function () {
        Route::get('/admissions/{admission}/notes', 'notes');
        Route::get('/admissions/{admission}/summary', 'summary');
        Route::get('/templates', 'templates');
        Route::get('/reports/{type}', 'report');
        Route::post('/admissions/{admission}/summary/approve', 'approveSummary');

        Route::middleware('ability:canWorkIpd')->group(function () {
            Route::post('/admissions/{admission}/notes', 'storeNote');
            Route::put('/admissions/{admission}/notes/{note}', 'updateNote');
            Route::delete('/admissions/{admission}/notes/{note}', 'destroyNote');
            Route::get('/admissions/{admission}/summary/from-stay', 'summaryFromStay');
            Route::put('/admissions/{admission}/summary', 'saveSummary');
        });
        Route::middleware('ability:canManageSetup')->group(function () {
            Route::post('/templates', 'storeTemplate');
            Route::put('/templates/{template}', 'updateTemplate');
            Route::delete('/templates/{template}', 'destroyTemplate');
        });
    });

    Route::controller(AdminController::class)->group(function () {
        Route::get('/clinic', 'clinic');
        Route::get('/referral-doctors', 'referralDoctors');
        Route::get('/billing-preferences', 'billingPreferences');
        Route::get('/admin-lists/{kind}', 'entries');

        Route::middleware('ability:canManageSetup')->group(function () {
            Route::put('/clinic', 'saveClinic');
            Route::put('/billing-preferences', 'saveBillingPreferences');
            Route::post('/admin-lists/{kind}', 'storeEntry');
            Route::put('/admin-lists/{kind}/{entry}', 'updateEntry');
            Route::post('/clinic/logo', 'saveLogo');
            Route::delete('/clinic/logo', 'destroyLogo');
            Route::post('/clinic/letterhead', 'saveLetterhead');
            Route::delete('/clinic/letterhead/{slot}', 'destroyLetterhead');
            Route::post('/referral-doctors', 'storeReferralDoctor');
            Route::put('/referral-doctors/{doctor}', 'updateReferralDoctor');
            Route::delete('/referral-doctors/{doctor}', 'destroyReferralDoctor');
            Route::get('/patient-merges', 'merges');
            Route::post('/patient-merges', 'merge');
        });
    });

    Route::controller(FormController::class)->group(function () {
        Route::get('/form-templates', 'templates');
        Route::get('/ipd/admissions/{admission}/forms', 'forAdmission');
        Route::get('/forms/{record}', 'show');

        Route::middleware('ability:canManageSetup')->group(function () {
            Route::post('/form-templates', 'storeTemplate');
            Route::put('/form-templates/{template}', 'updateTemplate');
        });
        Route::get('/appointments/{appointment}/forms', 'forAppointment');
        Route::post('/ipd/admissions/{admission}/forms', 'store')->middleware('ability:canWorkIpd');
        Route::post('/appointments/{appointment}/forms', 'storeForAppointment')->middleware('ability:canConsult');
        // Who may change a filled-in form depends on where it was filled in; the controller checks.
        Route::put('/forms/{record}', 'update');
        Route::delete('/forms/{record}', 'destroy');
    });

    Route::get('/reports/summary', [ReportController::class, 'summary']);
    Route::get('/reports/owner', [ReportController::class, 'owner']);
    Route::controller(DayCloseController::class)->group(function () {
        Route::get('/day-close', 'show');
        Route::post('/day-close', 'close');
        Route::post('/day-close/{close}/reopen', 'reopen');
    });
    Route::get('/reports/find-patients', [HospitalReportController::class, 'findPatients']);
    Route::get('/reports/list/{type}', [HospitalReportController::class, 'show']);

    // Consultants: their visits to other doctors' inpatients, what they are owed, and what was paid to them.
    Route::controller(ConsultantController::class)->group(function () {
        Route::middleware('ability:canWorkIpd')->group(function () {
            Route::get('/ipd/admissions/{admission}/consultant-visits', 'visits');
            Route::post('/ipd/admissions/{admission}/consultant-visits', 'addVisit');
            Route::post('/consultant-visits/{visit}/cancel', 'cancelVisit');
        });
        Route::middleware('ability:canPayConsultants')->prefix('consultants')->group(function () {
            Route::get('/', 'month');
            Route::put('/rates', 'saveRates');
            Route::get('/{doctor}', 'statement')->whereNumber('doctor');
            Route::post('/{doctor}/payments', 'pay')->whereNumber('doctor');
            Route::post('/payments/{payment}/cancel', 'cancelPayment')->whereNumber('payment');
        });
    });

    Route::middleware(EnsureCanManageStaff::class)->group(function () {
        Route::get('/staff', [StaffController::class, 'index']);
        Route::get('/staff/catalogue', [StaffController::class, 'catalogue']);
        Route::get('/staff/{staff}', [StaffController::class, 'show'])->whereNumber('staff');
        Route::post('/staff', [StaffController::class, 'store']);
        Route::put('/staff/{staff}', [StaffController::class, 'update']);
        Route::get('/staff/{staff}/permission-history', [StaffController::class, 'permissionHistory']);
        Route::post('/staff/{staff}/signature', [StaffController::class, 'saveSignature']);
        Route::delete('/staff/{staff}/signature', [StaffController::class, 'destroySignature']);
    });
    // A login given to a member of staff from their own page, and a forgotten PIN replaced. Each checks who may:
    // those who manage logins, and for an own-record login also those who keep the staff records.
    Route::post('/staff/for/{employee}', [StaffController::class, 'storeFor'])->whereNumber('employee');
    Route::post('/staff/for-all', [StaffController::class, 'storeForAll'])->middleware('throttle:6,1');
    Route::put('/staff/doctor-for/{employee}', [StaffController::class, 'doctorFor'])->whereNumber('employee');
    Route::post('/staff/{staff}/starting-pin', [StaffController::class, 'startingPin'])->whereNumber('staff');
    Route::post('/staff/{staff}/new-password', [StaffController::class, 'newPassword'])->whereNumber('staff');
});

// ---- Workforce: staff records, attendance, leave, payroll, advances and expenses (routes/workforce.php) ----
Route::middleware(['auth:sanctum', 'actor:staff', 'limits', 'activity'])->prefix('workforce')->group(base_path('routes/workforce.php'));
