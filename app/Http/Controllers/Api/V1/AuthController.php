<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! $user->isActive() || ! Hash::check($data['password'], $user->password)) {
            AuditLogger::record('auth.login_failed', 'user', $user?->id, null, ['email' => $data['email']]);
            return $this->error('INVALID_CREDENTIALS', 'Invalid email or password.', 401, $request);
        }

        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken('api', ['*'], now()->addHours(8))->plainTextToken;

        $request->setUserResolver(fn () => $user);
        AuditLogger::record('auth.login', 'user', $user->id);

        return response()->json([
            'data' => ['token' => $token, 'token_type' => 'Bearer', 'expires_in' => 8 * 3600],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        AuditLogger::record('auth.logout', 'user', $request->user()->id);
        return response()->noContent();
    }

    public function me(Request $request)
    {
        $u = $request->user();
        return response()->json([
            'data' => [
                'id'          => $u->id,
                'name'        => $u->name,
                'email'       => $u->email,
                'roles'       => $u->roles()->pluck('roles.code'),
                'offices'     => $u->offices()->pluck('offices.id'),
                'permissions' => $u->permissionCodes(),
            ],
            'meta' => ['request_id' => $request->attributes->get('request_id')],
        ]);
    }

    private function error(string $code, string $msg, int $status, Request $r)
    {
        return response()->json([
            'error' => ['code' => $code, 'message' => $msg],
            'meta'  => ['request_id' => $r->attributes->get('request_id')],
        ], $status);
    }
}
