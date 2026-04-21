<?php

namespace App\Http\Controllers\WebAuthn;

use App\Http\Controllers\Controller;
use App\Support\LoginRecorder;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;

final class WebAuthnLoginController extends Controller
{
    public function options(AssertionRequest $request): Responsable
    {
        return $request->toVerify($request->validate(['email' => 'sometimes|email|string']));
    }

    public function login(AssertedRequest $request): JsonResponse
    {
        if ($request->login()) {
            $request->session()->regenerate();
            LoginRecorder::success(LoginRecorder::METHOD_PASSKEY, Auth::user(), $request);

            return response()->json(['redirect' => route('dashboard')]);
        }

        LoginRecorder::failure(LoginRecorder::METHOD_PASSKEY, 'assertion-rejected', null, $request);

        return response()->json(['error' => 'authentication-failed'], 422);
    }
}
