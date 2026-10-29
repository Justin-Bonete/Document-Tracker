
<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'application' => 'Document Tracker System',
        'status' => 'Running',
        'message' => 'Welcome to the Document Tracker API'
    ]);
});
