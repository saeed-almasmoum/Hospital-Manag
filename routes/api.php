<?php

use App\Http\Controllers\AboutUsController;
use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\ArticleController;
use App\Http\Controllers\DiagnosisController;
use App\Http\Controllers\DoctorController;
use App\Http\Controllers\JWTAuthController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\ServiceController;
use App\Http\Controllers\SpecialtyController;
use App\Http\Middleware\JwtMiddleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');


Route::group([
    'middleware' => 'api',
    'prefix' => 'user',
    'namespace' => ''
], function ($router) {

    Route::post('register', [JWTAuthController::class, 'register']);
    Route::post('login', [JWTAuthController::class, 'login']);
    Route::get('user-profile', [JWTAuthController::class, 'getUser']);
    Route::post('logout', [JWTAuthController::class, 'logout']);
});


Route::group([
    'middleware' => 'api',
    'prefix' => 'patient',
    'namespace' => ''
], function ($router) {

    Route::post('register', [PatientController::class, 'register']);
    Route::post('login', [PatientController::class, 'login']);
    Route::post('logout', [PatientController::class, 'logout']);
    Route::post('index', [PatientController::class, 'index']);
    Route::post('show/{id}', [PatientController::class, 'show']);
    Route::post('update/{id}', [PatientController::class, 'update']);
    Route::post('destroy/{id}', [PatientController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'doctor',
    'namespace' => ''
], function ($router) {

    Route::post('register', [DoctorController::class, 'register']);
    Route::post('login', [DoctorController::class, 'login']);
    Route::post('logout', [DoctorController::class, 'logout']);

    Route::post('index', [DoctorController::class, 'index']);
    Route::post('show/{id}', [DoctorController::class, 'show']);
    Route::post('update/{id}', [DoctorController::class, 'update']);
    Route::post('destroy/{id}', [DoctorController::class, 'destroy']);
});


Route::group([
    'middleware' => 'api',
    'prefix' => 'specialty',
    'namespace' => ''
], function ($router) {

    Route::post('index', [SpecialtyController::class, 'index']);
    Route::post('store', [SpecialtyController::class, 'store']);
    Route::post('update/{id}', [SpecialtyController::class, 'update']);
    Route::post('show/{id}', [SpecialtyController::class, 'show']);
    Route::post('destroy/{id}', [SpecialtyController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'appointment',
    'namespace' => ''
], function ($router) {

    Route::post('index/{id}', [AppointmentController::class, 'index']);
    Route::post('store', [AppointmentController::class, 'store']);
    Route::post('update/{id}', [AppointmentController::class, 'update']);
    Route::post('show/{id}', [AppointmentController::class, 'show']);
    Route::post('destroy/{id}', [AppointmentController::class, 'destroy']);
});


Route::group([
    'middleware' => 'api',
    'prefix' => 'article',
    'namespace' => ''
], function ($router) {

    Route::post('index', [ArticleController::class, 'index']);
    Route::post('store', [ArticleController::class, 'store']);
    Route::post('update/{id}', [ArticleController::class, 'update']);
    Route::post('show/{id}', [ArticleController::class, 'show']);
    Route::post('destroy/{id}', [ArticleController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'service',
    'namespace' => ''
], function ($router) {

    Route::post('index', [ServiceController::class, 'index']);
    Route::post('store', [ServiceController::class, 'store']);
    Route::post('update/{id}', [ServiceController::class, 'update']);
    Route::post('show/{id}', [ServiceController::class, 'show']);
    Route::post('destroy/{id}', [ServiceController::class, 'destroy']);
});

Route::group([
    'middleware' => 'api',
    'prefix' => 'aboutUs',
    'namespace' => ''
], function ($router) {

    Route::post('store', [AboutUsController::class, 'store']);
    Route::post('update/{id}', [AboutUsController::class, 'update']);
    Route::post('show', [AboutUsController::class, 'show']);
    Route::post('destroy/{id}', [AboutUsController::class, 'destroy']);
});


Route::post('/diagnose', [DiagnosisController::class, 'diagnose']);


// Route::apiResource('aboutUs',AboutUsController::class);