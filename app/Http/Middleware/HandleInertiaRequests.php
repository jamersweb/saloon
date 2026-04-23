<?php

namespace App\Http\Middleware;

use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        $manifestPath = public_path('build/manifest.json');

        if (File::exists($manifestPath)) {
            $hash = @md5_file($manifestPath);

            return is_string($hash) && $hash !== '' ? $hash : parent::version($request);
        }

        $hotPath = public_path('hot');

        if (File::exists($hotPath)) {
            return (string) (@filemtime($hotPath) ?: parent::version($request));
        }

        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user()?->loadMissing('role');

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'profile_image_url' => $user->profile_image_path
                        ? asset('storage/'.$user->profile_image_path)
                        : null,
                    'role' => $user->role ? [
                        'name' => $user->role->name,
                        'label' => $user->role->label,
                    ] : null,
                ] : null,
                'permissions' => $user
                    ? collect(Permissions::all())->keys()->mapWithKeys(fn (string $permission) => [
                        $permission => $user->hasPermission($permission),
                    ])->all()
                    : [],
            ],
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
                'created_tax_invoice_id' => fn () => $request->session()->get('created_tax_invoice_id'),
                'created_purchase_order_id' => fn () => $request->session()->get('created_purchase_order_id'),
            ],
            'app_timezone' => config('app.timezone'),
        ];
    }
}
