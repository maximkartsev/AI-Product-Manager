<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Traits\CreatesUserTenant;
use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends BaseController
{
    use CreatesUserTenant;

    public function redirectToGoogleSignIn(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signin']));

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/google/signin/callback')
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'Google sign-in redirect URL');
    }

    public function handleGoogleSignInCallback(Request $request)
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->sendError('Authorization code is required.', [], 400);
        }

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/google/signin/callback')
                ->user();
        } catch (ClientException $e) {
            return $this->sendError('Invalid credentials.', [], 422);
        } catch (\Exception $e) {
            return $this->sendError('OAuth authentication failed.', [], 500);
        }

        $user = User::where('email', $googleUser->getEmail())->first();

        if (!$user) {
            return $this->sendError('No account found with this email. Please sign up first.', [], 404);
        }

        // Update login tracking
        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        // Parse device info
        $userAgent = $request->header('User-Agent', '');
        $deviceInfo = $this->parseUserAgent($userAgent);
        $deviceName = $deviceInfo['browser'] . ' on ' . $deviceInfo['platform'];

        $token = $user->createToken($deviceName, ['*']);

        $token->accessToken->update([
            'device_name' => $deviceName,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'ip_address' => $request->ip(),
        ]);

        $tenant = Tenant::query()->where('user_id', $user->id)->first();

        $result = [
            'type' => 'signin',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
        ];

        if ($tenant) {
            $result['tenant'] = [
                'id' => $tenant->id,
                'domain' => $tenant->domains()->first()?->domain,
                'db_pool' => $tenant->db_pool,
            ];
        }

        return $this->sendResponse($result, 'Google sign-in successful');
    }

    public function redirectToGoogleSignUp(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signup']));

        $redirectUrl = Socialite::driver('google')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/google/signup/callback')
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'Google sign-up redirect URL');
    }

    public function handleGoogleSignUpCallback(Request $request)
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->sendError('Authorization code is required.', [], 400);
        }

        try {
            $googleUser = Socialite::driver('google')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/google/signup/callback')
                ->user();
        } catch (ClientException $e) {
            return $this->sendError('Invalid credentials.', [], 422);
        } catch (\Exception $e) {
            return $this->sendError('OAuth authentication failed.', [], 500);
        }

        $existingUser = User::where('email', $googleUser->getEmail())->first();

        if ($existingUser) {
            // User already exists — sign them in instead
            $existingUser->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

            $userAgent = $request->header('User-Agent', '');
            $deviceInfo = $this->parseUserAgent($userAgent);
            $deviceName = $deviceInfo['browser'] . ' on ' . $deviceInfo['platform'];

            $token = $existingUser->createToken($deviceName, ['*']);

            $token->accessToken->update([
                'device_name' => $deviceName,
                'device_type' => $deviceInfo['device_type'],
                'browser' => $deviceInfo['browser'],
                'platform' => $deviceInfo['platform'],
                'ip_address' => $request->ip(),
            ]);

            $tenant = Tenant::query()->where('user_id', $existingUser->id)->first();

            $result = [
                'type' => 'signup',
                'user' => [
                    'id' => $existingUser->id,
                    'name' => $existingUser->name,
                    'email' => $existingUser->email,
                ],
                'access_token' => $token->plainTextToken,
                'token_type' => 'Bearer',
            ];

            if ($tenant) {
                $result['tenant'] = [
                    'id' => $tenant->id,
                    'domain' => $tenant->domains()->first()?->domain,
                    'db_pool' => $tenant->db_pool,
                ];
            }

            return $this->sendResponse($result, 'User already exists, signed in successfully');
        }

        // Split Google name into first/last
        $fullName = $googleUser->getName() ?? '';
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $user = User::create([
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $googleUser->getEmail(),
            'password' => Hash::make(Str::random(32)),
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

        $tenant = $this->ensureTenantForUser($user);

        $userAgent = $request->header('User-Agent', '');
        $deviceInfo = $this->parseUserAgent($userAgent);
        $deviceName = $deviceInfo['browser'] . ' on ' . $deviceInfo['platform'];

        $token = $user->createToken($deviceName, ['*']);

        $token->accessToken->update([
            'device_name' => $deviceName,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'ip_address' => $request->ip(),
        ]);

        $result = [
            'type' => 'signup',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'access_token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'tenant' => [
                'id' => $tenant->id,
                'domain' => $tenant->domains()->first()?->domain,
                'db_pool' => $tenant->db_pool,
            ],
        ];

        return $this->sendResponse($result, 'User registered successfully via Google');
    }
}
