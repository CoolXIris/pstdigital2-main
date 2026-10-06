<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\KonsultasiAdminController;
use App\Http\Controllers\KonsultasiController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Route::get('/', function () {
//     return view('landing');
// })->name('/');

// Route::get('auth_user', [AuthController::class, 'auth_user']);
// Route::get('auth_admin', [AuthController::class, 'auth_admin']);
// Route::get('login', [AuthController::class, 'login'])->name('login');
// Route::get('/google_login', [AuthController::class, 'redirectToGoogle'])->name('google_login');
// Route::get('/google_callback', [AuthController::class, 'handleGoogleCallback']);
// Route::get('logout', [AuthController::class, 'logout'])->name('logout');

// Route::group(['middleware' => ['auth']], function () {
//     Route::get('profile', [ProfileController::class, 'index'])->name('profile');
//     Route::post('profile/{id}', [ProfileController::class, 'update']);
//     Route::get('konsultasi', [KonsultasiController::class, 'index']);
//     Route::post('konsultasi', [KonsultasiController::class, 'store']);
//     Route::post('konsultasi_rating/{id}', [KonsultasiController::class, 'rating_post']);
//     Route::get('chatbot', [ChatbotController::class, 'index']);
// });

// Route::group(['middleware' => ['auth', 'role:admin|super_admin']], function () {
//     Route::get('dashboard', [HomeController::class, 'index'])->name('dashboard');
//     Route::resource('users', UserController::class);
//     Route::get('users_export', [UserController::class, 'export']);
//     Route::put('konsultasi/{id}', [KonsultasiController::class, 'update']);
//     Route::delete('konsultasi/{id}', [KonsultasiController::class, 'destroy']);
// });

// Route::group(['middleware' => ['auth', 'role:super_admin']], function () {
//     Route::resource('roles', RoleController::class);
// });

Route::get('/', function () {
    return view('landing');
})->name('/');

Route::get('auth_user', [AuthController::class, 'auth_user']);
Route::get('auth_admin', [AuthController::class, 'auth_admin']);
Route::get('login', [AuthController::class, 'login'])->name('login');
Route::get('/google_login', [AuthController::class, 'redirectToGoogle'])->name('google_login');
Route::get('/google_callback', [AuthController::class, 'handleGoogleCallback']);
Route::get('logout', [AuthController::class, 'logout'])->name('logout');


Route::group(['middleware' => ['auth', 'role:admin|super_admin']], function () {
    Route::get('dashboard', [HomeController::class, 'index'])->name('dashboard');
    Route::resource('users', UserController::class);
    Route::get('users_export', [UserController::class, 'export'])->name('users_export');
    Route::get('admin/konsultasi', [KonsultasiAdminController::class, 'index'])->name('admin.konsultasi');
    Route::patch('admin/konsultasi/{meeting}/status', [KonsultasiAdminController::class, 'updateStatus'])
        ->name('admin.konsultasi.status');
    Route::get('admin/chatbot', [ChatbotController::class, 'management'])->name('admin.chatbot');
    Route::post('admin/chatbot/test', [ChatbotController::class, 'testMessage'])->name('admin.chatbot.test');
    Route::post('admin/chatbot/bps-api-key', [ChatbotController::class, 'saveBpsApiKey'])->name('admin.chatbot.bps-api-key');
    Route::post('admin/chatbot/knowledge', [ChatbotController::class, 'uploadKnowledge'])->name('admin.chatbot.knowledge.store');
    Route::delete('admin/chatbot/knowledge/{source}', [ChatbotController::class, 'deleteKnowledge'])->name('admin.chatbot.knowledge.destroy');
});

Route::group(['middleware' => ['auth']], function () {
    Route::get('profile', [ProfileController::class, 'index'])->name('profile');
    Route::put('profile/{id}', [ProfileController::class, 'update'])->name('profile.update');
    Route::get('konsultasi', [KonsultasiController::class, 'index'])->name('konsultasi.index');
    Route::post('konsultasi', [KonsultasiController::class, 'store']);
    Route::post('konsultasi_rating/{id}', [KonsultasiController::class, 'rating_post']);
    Route::get('konsultasi/{meeting}/room', [KonsultasiAdminController::class, 'room'])->name('konsultasi.room');
    Route::get('konsultasi/{meeting}/signals', [KonsultasiAdminController::class, 'signals'])->middleware('throttle:120,1')->name('konsultasi.signals.poll');
    Route::get('konsultasi/{meeting}/presence', [KonsultasiAdminController::class, 'presence'])->middleware('throttle:120,1')->name('konsultasi.presence');
    Route::get('konsultasi/{meeting}/messages', [KonsultasiAdminController::class, 'messages'])->middleware('throttle:120,1')->name('konsultasi.messages');
    Route::get('konsultasi/{meeting}/messages/status', [KonsultasiAdminController::class, 'messageStatus'])->middleware('throttle:120,1')->name('konsultasi.messages.status');
    Route::post('konsultasi/{meeting}/messages', [KonsultasiAdminController::class, 'sendMessage'])->middleware('throttle:120,1')->name('konsultasi.messages.send');
    Route::post('konsultasi/{meeting}/signals', [KonsultasiAdminController::class, 'signals'])->middleware('throttle:120,1')->name('konsultasi.signals.send');
    Route::get('konsultasi/{meeting}/documentation', [KonsultasiAdminController::class, 'documentation'])->name('konsultasi.documentation');
    Route::get('chatbot', [ChatbotController::class, 'index'])->name('chatbot.index');
    Route::post('chatbot/message', [ChatbotController::class, 'sendMessage'])->name('chatbot.message');
    Route::get('chatbot/conversations/{conversation}', [ChatbotController::class, 'conversation'])->name('chatbot.conversation');
});
