<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProfileIsComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user->hasRole(['admin', 'super_admin']) || $user->hasCompleteProfile()) {
            return $next($request);
        }

        return redirect()->route('profile')->with(
            'warning',
            'Lengkapi seluruh informasi profil, termasuk nomor telepon dan domisili, sebelum menggunakan konsultasi atau chatbot.'
        );
    }
}
