<?php

use App\Http\Controllers\PolyglotController;
use Illuminate\Support\Facades\Route;

Route::get('/health', [PolyglotController::class, 'health']);
Route::get('/', [PolyglotController::class, 'identity']);
Route::get('/v1/years', [PolyglotController::class, 'years']);
Route::get('/v1/speakers', [PolyglotController::class, 'speakers']);
Route::get('/v1/speakers/{year}/{slug}', [PolyglotController::class, 'speakerYear'])->where('year', '[0-9]+');
Route::get('/v1/speakers/{slug}', [PolyglotController::class, 'speaker']);
Route::get('/v1/sponsors', [PolyglotController::class, 'sponsors']);
Route::get('/v1/sponsors/{year}/{slug}', [PolyglotController::class, 'sponsorYear'])->where('year', '[0-9]+');
Route::get('/v1/sponsors/{slug}', [PolyglotController::class, 'sponsor']);
