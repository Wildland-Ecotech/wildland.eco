<?php

use Illuminate\Support\Facades\Route;

Route::view('/', 'pages.home')->name('home');
Route::view('/menu', 'pages.menu')->name('menu');
Route::view('/donate', 'pages.donate')->name('donate');
Route::view('/leaves', 'pages.leaves')->name('leaves');
