<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MobileReleaseController extends Controller
{
    public function android(Request $request): BinaryFileResponse|JsonResponse|Response
    {
        $apkPath = storage_path('app/releases/cpf-android-latest.apk');

        if (is_file($apkPath)) {
            return response()->download($apkPath, 'cpf-android-latest.apk', [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]);
        }

        if ($request->expectsJson()) {
            return response()->json([
                'status' => 'coming_soon',
                'platform' => 'android',
                'message' => 'CPF Mobile Android APK is coming soon. Please check back once the release build is published.',
            ], 404);
        }

        $homeUrl = e(url('/'));

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>CPF Mobile for Android — Coming Soon</title>
    <style>
        body {
            margin: 0;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            background: #FBF6EE;
            color: #1E293B;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }
        .card {
            max-width: 28rem;
            width: 100%;
            background: #FFFFFF;
            border: 1px solid rgba(15, 23, 42, 0.1);
            border-radius: 1.5rem;
            padding: 2rem;
            box-shadow: 0 12px 30px rgba(15, 23, 42, 0.06);
            text-align: center;
        }
        .badge {
            display: inline-block;
            background: rgba(3, 132, 197, 0.12);
            color: #0384C5;
            font-size: 0.75rem;
            font-weight: 700;
            padding: 0.35rem 0.75rem;
            border-radius: 9999px;
            margin-bottom: 1rem;
        }
        h1 {
            margin: 0 0 0.75rem;
            font-size: 1.375rem;
            color: #0F172A;
        }
        p {
            margin: 0 0 1.5rem;
            font-size: 0.9rem;
            line-height: 1.6;
            color: #475569;
        }
        a {
            display: inline-block;
            background: #FF8D06;
            color: #FFFFFF;
            text-decoration: none;
            font-weight: 600;
            font-size: 0.875rem;
            padding: 0.7rem 1.25rem;
            border-radius: 0.75rem;
        }
    </style>
</head>
<body>
    <main class="card">
        <span class="badge">CHMSU Circle of Peer Facilitators</span>
        <h1>CPF Mobile APK Coming Soon</h1>
        <p>The Android installer (<code>cpf-android-latest.apk</code>) has not been uploaded to this server yet. You can continue using the CPF Web Portal on any phone or PC browser.</p>
        <a href="{$homeUrl}">Return to CPF Portal</a>
    </main>
</body>
</html>
HTML;

        return response($html, 404, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
