<?php


namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator as Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use Illuminate\Support\Str;

class RegisterController extends BaseController
{
    private function ensureTenantForUser(User $user): Tenant
    {
        $existing = Tenant::query()->where('user_id', $user->id)->first();
        if ($existing) {
            return $existing;
        }

        $base = Str::slug((string) $user->name);
        if ($base === '') {
            $base = 'user';
        }

        // Make tenant IDs deterministic and race-safe under concurrent registrations by
        // incorporating the already-unique user id.
        $tenantId = $base . '-' . $user->id;
        $attempts = 0;
        while (Tenant::query()->whereKey($tenantId)->exists()) {
            $attempts++;
            $tenantId = $base . '-' . $user->id . '-' . Str::lower(Str::random(6));
            if ($attempts > 10) {
                throw new \RuntimeException('Unable to allocate a unique tenant id.');
            }
        }

        $dbPool = (string) config('tenant_pools.default', 'tenant_pool_1');

        /** @var Tenant $tenant */
        $tenant = Tenant::create([
            'id' => $tenantId,
            'user_id' => $user->id,
            'db_pool' => $dbPool,
        ]);

        $baseDomain = (string) env('TENANCY_BASE_DOMAIN', 'localhost');
        $tenantDomain = "{$tenantId}.{$baseDomain}";

        // Domains are stored centrally and used for domain-based tenant identification.
        $tenant->domains()->firstOrCreate([
            'domain' => $tenantDomain,
        ]);

        return $tenant;
    }
    /**
     * Parse User-Agent string to extract device and browser info
     *
     * @param string $userAgent
     * @return array
     */
    private function parseUserAgent(string $userAgent): array
    {
        $deviceType = 'desktop';
        $browser = 'Unknown Browser';
        $platform = 'Unknown Platform';

        // Detect device type
        if (preg_match('/mobile|android|iphone|ipod|blackberry|iemobile|opera mini/i', $userAgent)) {
            $deviceType = 'mobile';
        } elseif (preg_match('/tablet|ipad|playbook|silk/i', $userAgent)) {
            $deviceType = 'tablet';
        }

        // Detect browser
        if (preg_match('/chrome/i', $userAgent) && !preg_match('/edg/i', $userAgent)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox/i', $userAgent)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $userAgent) && !preg_match('/chrome/i', $userAgent)) {
            $browser = 'Safari';
        } elseif (preg_match('/edg/i', $userAgent)) {
            $browser = 'Edge';
        } elseif (preg_match('/opera|opr/i', $userAgent)) {
            $browser = 'Opera';
        }

        // Detect platform
        if (preg_match('/windows/i', $userAgent)) {
            $platform = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $userAgent)) {
            $platform = 'macOS';
        } elseif (preg_match('/linux/i', $userAgent)) {
            $platform = 'Linux';
        } elseif (preg_match('/android/i', $userAgent)) {
            $platform = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $userAgent)) {
            $platform = 'iOS';
        }

        return [
            'device_type' => $deviceType,
            'browser' => $browser,
            'platform' => $platform,
        ];
    }
    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
            'c_password' => 'required|same:password',
        ]);

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),400);
        }

        $input = $request->all();
        $input['password'] = bcrypt($input['password']);
        $user = User::create($input);

        $tenant = $this->ensureTenantForUser($user);

        // Parse device info from User-Agent
        $userAgent = $request->header('User-Agent', '');
        $deviceInfo = $this->parseUserAgent($userAgent);
        $ipAddress = $request->ip();

        // Create device name
        $deviceName = $deviceInfo['browser'] . ' on ' . $deviceInfo['platform'];

        // Create token
        $token = $user->createToken($deviceName, ['*']);

        // Update token with device info
        $token->accessToken->update([
            'device_name' => $deviceName,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'ip_address' => $ipAddress,
        ]);

        $success['token'] = $token->plainTextToken;
        $success['name'] = $user->name;
        $success['tenant'] = [
            'id' => $tenant->id,
            'domain' => $tenant->domains()->first()?->domain,
            'db_pool' => $tenant->db_pool,
        ];

        return $this->sendResponse($success, trans('User register successfully'));
    }
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required',
        ]);

        if ($validator->fails()) {
            return $this->sendError(trans('Validation Error'), $validator->errors(), 400);
        }

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return $this->sendError(trans('Unauthorised'), ['error'=>'Unauthorised'],401);
        }


        // Parse device info from User-Agent
        $userAgent = $request->header('User-Agent', '');
        $deviceInfo = $this->parseUserAgent($userAgent);
        $ipAddress = $request->ip();

        // Create device name
        $deviceName = $deviceInfo['browser'] . ' on ' . $deviceInfo['platform'];

        // Create token
        $token = $user->createToken($deviceName, ['*']);

        // Update token with device info
        $token->accessToken->update([
            'device_name' => $deviceName,
            'device_type' => $deviceInfo['device_type'],
            'browser' => $deviceInfo['browser'],
            'platform' => $deviceInfo['platform'],
            'ip_address' => $ipAddress,
        ]);

        $success['token'] = $token->plainTextToken;
        $success['name'] = $user->name;
        $tenant = Tenant::query()->where('user_id', $user->id)->first();
        if ($tenant) {
            $success['tenant'] = [
                'id' => $tenant->id,
                'domain' => $tenant->domains()->first()?->domain,
                'db_pool' => $tenant->db_pool,
            ];
        }
        return $this->sendResponse($success, trans('User login successfully'));
    }
}
