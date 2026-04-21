<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Basic-auth gate for third-party webhooks where we control the URL shared
 * with the sender (Postmark, etc.). The webhook URL is configured as
 * https://user:pass@host/... so the credential is sent in every request via
 * the Authorization: Basic header. Not a substitute for signature verification
 * on providers that offer it — add signature checks where the provider supports.
 *
 * Config path: services.{name}.webhook_user / _password. Skipped when both
 * are empty (dev convenience; production must set them).
 */
class VerifyWebhookBasicAuth
{
    public function handle(Request $request, Closure $next, string $configPrefix): Response
    {
        $expectedUser = (string) config("services.{$configPrefix}.webhook_user", '');
        $expectedPass = (string) config("services.{$configPrefix}.webhook_password", '');

        if ($expectedUser === '' && $expectedPass === '') {
            // Fail closed in real environments — an unconfigured webhook is
            // not a license to accept anonymous POSTs. Only `local` and
            // `testing` skip the check so dev/tests don't need a fake cred.
            if (! app()->environment(['local', 'testing'])) {
                return response()->json(['ok' => false, 'reason' => 'webhook-credentials-unset'], 401);
            }

            return $next($request);
        }

        $user = (string) ($request->getUser() ?? '');
        $pass = (string) ($request->getPassword() ?? '');

        if (! hash_equals($expectedUser, $user) || ! hash_equals($expectedPass, $pass)) {
            return response()->json(['ok' => false, 'reason' => 'unauthorized'], 401);
        }

        return $next($request);
    }
}
