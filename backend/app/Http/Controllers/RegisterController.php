<?php


namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\BaseController as BaseController;
use App\Http\Controllers\Traits\CreatesUserTenant;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator as Validator;
use Illuminate\Support\Facades\Hash;
use App\Models\Tenant;
use Illuminate\Support\Str;

class RegisterController extends BaseController
{
    use CreatesUserTenant;

    public function register(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required',
            'email' => 'required|email',
            'password' => 'required',
            'c_password' => 'required|same:password',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
        ]);

        if($validator->fails()){
            return $this->sendError(trans('Validation Error'), $validator->errors(),400);
        }

        $input = $request->all();

        // Split name into first_name/last_name if not explicitly provided
        if (empty($input['first_name']) && empty($input['last_name'])) {
            $parts = preg_split('/\s+/', trim($input['name']), 2);
            $input['first_name'] = $parts[0] ?? '';
            $input['last_name'] = $parts[1] ?? '';
        }

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

        try {
            // Track login
            $user->update([
                'last_login_at' => now(),
                'last_login_ip' => $request->ip(),
            ]);

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
        } catch (\Exception $e) {
            \Log::error('Login token creation failed', ['error' => $e->getMessage(), 'user' => $user->id]);
            return $this->sendError('Login failed: ' . $e->getMessage(), [], 500);
        }

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
