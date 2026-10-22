<?php

use App\Http\Controllers\LeafApplicationController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/menu', 'pages.menu')->name('menu');
Route::view('/donate', 'pages.donate')->name('donate');
Route::view('/leaves', 'pages.leaves')->name('leaves');
Route::get('/leaves/apply', [LeafApplicationController::class, 'create'])->name('leaves.apply');
Route::post('/leaves/apply', [LeafApplicationController::class, 'store'])->name('leaves.apply.store');
Route::view('/faq', 'pages.faq')->name('faq');
