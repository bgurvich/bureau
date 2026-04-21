<?php

namespace App\Http\Controllers\WebAuthn;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

final class WebAuthnRegisterController extends Controller
{
    public function options(AttestationRequest $request): Responsable
    {
        return $request->fastRegistration()->toCreate();
    }

    public function register(AttestedRequest $request): JsonResponse
    {
        $alias = trim((string) $request->input('alias')) ?: __('Security key');
        $id = $request->save(['alias' => $alias]);

        return response()->json(['id' => $id, 'alias' => $alias]);
    }
}
