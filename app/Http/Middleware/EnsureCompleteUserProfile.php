<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureCompleteUserProfile
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Administrative accounts can access these screens to manage the service.
        if ($user && ! $user->hasRole('admin|super_admin') && ! $user->hasCompleteProfile()) {
            return redirect()->route('profile')->with('error', 'Lengkapi dan simpan semua data profil hingga 100% untuk mengakses fitur layanan.');
        }

        return $next($request);
    }
}
