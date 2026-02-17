<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

class PasswordController extends BaseController
{
    public function sendResetLink(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
        ]);

        $status = Password::sendResetLink(['email' => $validated['email']]);

        if ($status === Password::RESET_LINK_SENT) {
            return $this->sendResponse([], trans($status));
        }

        return $this->sendError(trans($status), [], 400);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required',
            'email' => 'required|email',
            'password' => 'required|min:8|confirmed',
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, $password) {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return $this->sendResponse([], trans($status));
        }

        return $this->sendError(trans($status), [], 400);
    }
}


