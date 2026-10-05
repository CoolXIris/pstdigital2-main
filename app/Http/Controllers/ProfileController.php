<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Validation\Rule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        return view('profile.show', ['data' => Auth::user()]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        if ((int) $id !== (int) Auth::id()) {
            abort(403);
        }

        $data = Auth::user();
        return view('profile.show', compact('data'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        abort_unless((int) $id === (int) Auth::id(), 403);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pekerjaan' => ['required', Rule::in(['Pelajar/Mahasiswa', 'Peneliti/Dosen', 'Pegawai Swasta', 'Pegawai BUMN/BUMD', 'Wiraswasta', 'ASN/TNI/Polri'])],
            'jenis_kelamin' => ['required', Rule::in(['1', '2'])],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'asal_prov' => ['required', 'string', 'max:100'],
            'asal_kab' => ['required', 'string', 'max:100'],
            'no_hp' => ['required', 'string', 'max:30'],
            'pendidikan' => ['required', 'string', 'max:100'],
        ]);

        $user = Auth::user();
        $user->fill($validated)->save();

        if (Auth::user()->hasRole('admin|super_admin')) {
            return redirect()->route('dashboard')->with('message', 'Profil berhasil diperbarui.');
        }

        return redirect('/')->with('message', 'Profil berhasil dilengkapi. Selamat menggunakan layanan PST Digital.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
