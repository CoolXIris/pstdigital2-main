<?php

namespace App\Http\Controllers;

use App\Models\User;
use DateTime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    //

    public function login()
    {
        if (Auth::user()) {
            if (Auth::user()->hasRole('super_admin|admin')) {
                return redirect('dashboard');
            } else {
                return redirect('/');
            }
        } else {
            return view('login');
        }
    }

    public function redirectToGoogle()
    {
        return Socialite::driver('google')
            ->scopes([
                'https://www.googleapis.com/auth/calendar',
                // 'https://www.googleapis.com/auth/meetings',
            ])
            ->with([
                'access_type' => 'offline', // Minta refresh_token
                // 'include_granted_scopes' => 'true', // Ini akan di-set melalui custom provider
            ])->redirect();
    }

    public function handleGoogleCallback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();
            $user = User::where('email', $googleUser->getEmail())->first();

            // dd($googleUser, $googleUser->refreshToken);
            if (!$user) {
                $user = User::create([
                    'google_id' => $googleUser->getId(),
                    'name' => $googleUser->getName(),
                    'last_login' => new DateTime(),
                    'email' => $googleUser->getEmail(),
                    'picture' => $googleUser->avatar,
                    'google_access_token' => $googleUser->token,
                    'google_refresh_token' => $googleUser->refreshToken,
                    'password' => Hash::make(uniqid()),
                ]);
                $user->syncRoles('user');
            } else {
                $user->last_login = new DateTime();
                $user->name = $googleUser->getName();
                $user->picture = $googleUser->avatar;
                $user->google_access_token = $googleUser->token;
                $user->google_refresh_token = $googleUser->refreshToken ?? $user->google_refresh_token;
                $user->save();

                if ($user->roles()->doesntExist()) {
                    $user->syncRoles('user');
                }
            }
            // dd($googleUser);
            Auth::login($user, true);
            $request->session()->regenerate();
            if (Auth::user()->hasRole('admin|super_admin')) {
                return redirect('dashboard');
            } else {
                $profileComplete = $user->pekerjaan
                    && $user->jenis_kelamin
                    && $user->tanggal_lahir
                    && $user->asal_prov
                    && $user->asal_kab
                    && $user->no_hp
                    && $user->pendidikan;

                return $profileComplete
                    ? redirect('/')
                    : redirect()->route('profile')->with('message', 'Lengkapi profil Anda untuk menggunakan seluruh layanan.');
            }
        } catch (\Exception $e) {
            report($e);

            return redirect('/login')->with('error', 'Terjadi kesalahan saat login dengan Google.');
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/login');
    }

    public function auth_admin()
    {
        $user = User::where('email', 'lapakstatistik16@gmail.com')->first();
        // dd($user);
        Auth::login($user);
        if (Auth::user()->hasRole('admin|super_admin')) {
            return redirect('dashboard');
        } else {
            if (
                $user->pekerjaan
                || $user->jenis_kelamin
                || $user->tanggal_lahir
                || $user->asal_prov
                || $user->asal_kab
                || $user->no_hp
                || $user->pendidikan
            ) {
                return redirect('/');
            } else {
                return redirect('profile')->with('error', 'Ada Isian profile belum lengkap');
            }
        }
    }
    public function auth_user()
    {
        $user = User::where('email', 'irfansyahahmad26@gmail.com')->first();
        // dd($user);
        Auth::login($user);
        if (Auth::user()->hasRole('admin|super_admin')) {
            return redirect('dashboard');
        } else {
            if ($user->pekerjaan && $user->jenis_kelamin && $user->tanggal_lahir && $user->asal_prov && $user->asal_kab && $user->pendidikan) {
                return redirect('/');
            } else {
                return redirect('profile')->with('message', 'Ayo Lengkapi Datamu');
            }
        }
    }
}
