<?php

use App\Http\Controllers\SmsWebhookController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// The root path serves the built SPA in production. In development there is no
// public/spa directory, so fall back to the Vite-powered welcome page instead.
Route::get('/', function () {
    $spaIndex = public_path('spa/index.html');

    if (is_file($spaIndex)) {
        return response()->file($spaIndex);
    }

    return view('welcome');
});

// Africa's Talking SMS webhooks (delivery reports + inbound replies).
// AT issues a GET (older) or POST (newer) callback; both are accepted.
Route::match(['get', 'post'], '/sms/delivery-report', [SmsWebhookController::class, 'deliveryReport']);
Route::match(['get', 'post'], '/sms/inbox', [SmsWebhookController::class, 'inbox']);

// Serve the built React SPA from public/spa (single-origin deployment).
// In development this directory does not exist, so /api and other routes
// continue to work normally and the Vite dev server handles the UI.
if (is_dir(public_path('spa'))) {
    Route::get('/{any}', function (Request $request) {
        // Never let the SPA fallback answer API calls. An unmatched /api path
        // must stay a JSON 404: the tenant signing page reads the response body
        // to tell "link expired" apart from "app not loaded".
        if ($request->is('api', 'api/*')) {
            return response()->json([
                'success' => false,
                'message' => 'Endpoint not found.',
            ], 404);
        }

        $file = public_path('spa/'.$request->path());
        $extension = strtolower(pathinfo($request->path(), PATHINFO_EXTENSION));

        // Serve real asset files if they exist, otherwise fall back to index.html
        // for client-side routing.
        $serveable = ['js', 'mjs', 'css', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'ico', 'webp',
            'woff', 'woff2', 'ttf', 'eot', 'json', 'webmanifest', 'xml', 'txt', 'map'];

        if (is_file($file) && in_array($extension, $serveable, true)) {
            // Content types must be explicit: browsers refuse to execute a
            // <script type="module"> served as text/plain, and a manifest served
            // as text/html breaks PWA installation.
            $types = [
                'js' => 'text/javascript',
                'mjs' => 'text/javascript',
                'map' => 'application/json',
                'css' => 'text/css',
                'json' => 'application/json',
                'webmanifest' => 'application/manifest+json',
                'xml' => 'application/xml',
                'svg' => 'image/svg+xml',
                'png' => 'image/png',
                'jpg' => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'ico' => 'image/x-icon',
                'webp' => 'image/webp',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'ttf' => 'font/ttf',
                'eot' => 'application/vnd.ms-fontobject',
                'txt' => 'text/plain',
            ];

            return response()->file($file, array_filter([
                'Content-Type' => $types[$extension] ?? null,
            ]));
        }

        return response()->file(public_path('spa/index.html'), [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    })->where('any', '.*');
}
