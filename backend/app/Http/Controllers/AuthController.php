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

    public function redirectToTikTokSignIn(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signin']));

        $redirectUrl = Socialite::driver('tiktok')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/tiktok/signin/callback')
            ->scopes(['user.info.basic'])
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'TikTok sign-in redirect URL');
    }

    public function handleTikTokSignInCallback(Request $request)
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->sendError('Authorization code is required.', [], 400);
        }

        try {
            $tiktokUser = Socialite::driver('tiktok')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/tiktok/signin/callback')
                ->user();
        } catch (ClientException $e) {
            return $this->sendError('Invalid credentials.', [], 422);
        } catch (\Exception $e) {
            return $this->sendError('OAuth authentication failed.', [], 500);
        }

        $email = $tiktokUser->getEmail();
        if (!$email) {
            return $this->sendError('TikTok did not provide an email address. Please use another sign-in method.', [], 422);
        }

        $user = User::where('email', $email)->first();

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

        return $this->sendResponse($result, 'TikTok sign-in successful');
    }

    public function redirectToTikTokSignUp(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signup']));

        $redirectUrl = Socialite::driver('tiktok')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/tiktok/signup/callback')
            ->scopes(['user.info.basic'])
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'TikTok sign-up redirect URL');
    }

    public function handleTikTokSignUpCallback(Request $request)
    {
        $code = $request->input('code');
        if (!$code) {
            return $this->sendError('Authorization code is required.', [], 400);
        }

        try {
            $tiktokUser = Socialite::driver('tiktok')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/tiktok/signup/callback')
                ->user();
        } catch (ClientException $e) {
            return $this->sendError('Invalid credentials.', [], 422);
        } catch (\Exception $e) {
            return $this->sendError('OAuth authentication failed.', [], 500);
        }

        $email = $tiktokUser->getEmail();
        if (!$email) {
            return $this->sendError('TikTok did not provide an email address. Please use another sign-up method.', [], 422);
        }

        $existingUser = User::where('email', $email)->first();

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

        // Split TikTok name into first/last
        $fullName = $tiktokUser->getName() ?? '';
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $user = User::create([
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
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

        return $this->sendResponse($result, 'User registered successfully via TikTok');
    }

    // ---- Apple OAuth ----
    // Apple uses response_mode=form_post, so Apple POSTs the auth code
    // to the callback URL. The callbacks here receive that POST, process
    // auth, then redirect the browser to the frontend with token data.

    public function redirectToAppleSignIn(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signin']));

        $redirectUrl = Socialite::driver('apple')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/apple/signin/callback')
            ->scopes(['name', 'email'])
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'Apple sign-in redirect URL');
    }

    public function handleAppleSignInCallback(Request $request)
    {
        $frontendBase = env('FRONTEND_URL') . '/auth/apple/signin/done';

        $code = $request->input('code');
        if (!$code) {
            return redirect($frontendBase . '?error=' . urlencode('Authorization code missing from Apple.'));
        }

        try {
            $appleUser = Socialite::driver('apple')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/apple/signin/callback')
                ->user();
        } catch (ClientException $e) {
            return redirect($frontendBase . '?error=' . urlencode('Invalid credentials.'));
        } catch (\Exception $e) {
            return redirect($frontendBase . '?error=' . urlencode('Apple sign-in failed.'));
        }

        $email = $appleUser->getEmail();
        if (!$email) {
            return redirect($frontendBase . '?error=' . urlencode('Apple did not provide an email address.'));
        }

        $user = User::where('email', $email)->first();

        if (!$user) {
            return redirect($frontendBase . '?error=' . urlencode('No account found with this email. Please sign up first.') . '&error_code=404');
        }

        $user->update([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ]);

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

        $query = http_build_query(array_filter([
            'access_token' => $token->plainTextToken,
            'tenant_domain' => $tenant?->domains()->first()?->domain,
        ]));

        return redirect($frontendBase . '?' . $query);
    }

    public function redirectToAppleSignUp(Request $request)
    {
        $state = base64_encode(json_encode(['action' => 'signup']));

        $redirectUrl = Socialite::driver('apple')
            ->stateless()
            ->redirectUrl(env('FRONTEND_URL') . '/auth/apple/signup/callback')
            ->scopes(['name', 'email'])
            ->with(['state' => $state])
            ->redirect()
            ->getTargetUrl();

        return $this->sendResponse(['url' => $redirectUrl], 'Apple sign-up redirect URL');
    }

    public function handleAppleSignUpCallback(Request $request)
    {
        $frontendBase = env('FRONTEND_URL') . '/auth/apple/signup/done';

        $code = $request->input('code');
        if (!$code) {
            return redirect($frontendBase . '?error=' . urlencode('Authorization code missing from Apple.'));
        }

        try {
            $appleUser = Socialite::driver('apple')
                ->stateless()
                ->redirectUrl(env('FRONTEND_URL') . '/auth/apple/signup/callback')
                ->user();
        } catch (ClientException $e) {
            return redirect($frontendBase . '?error=' . urlencode('Invalid credentials.'));
        } catch (\Exception $e) {
            return redirect($frontendBase . '?error=' . urlencode('Apple sign-up failed.'));
        }

        $email = $appleUser->getEmail();
        if (!$email) {
            return redirect($frontendBase . '?error=' . urlencode('Apple did not provide an email address.'));
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser) {
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

            $query = http_build_query(array_filter([
                'access_token' => $token->plainTextToken,
                'tenant_domain' => $tenant?->domains()->first()?->domain,
            ]));

            return redirect($frontendBase . '?' . $query);
        }

        // Apple only provides name on first auth — fallback to email prefix
        $fullName = $appleUser->getName() ?? '';
        if (empty(trim($fullName))) {
            $fullName = explode('@', $email)[0];
        }
        $nameParts = preg_split('/\s+/', trim($fullName), 2);
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[1] ?? '';

        $user = User::create([
            'name' => $fullName,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
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

        $query = http_build_query(array_filter([
            'access_token' => $token->plainTextToken,
            'tenant_domain' => $tenant->domains()->first()?->domain,
        ]));

        return redirect($frontendBase . '?' . $query);
    }
}
