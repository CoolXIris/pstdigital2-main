<?php

namespace App\Http\Controllers;

use App\Services\WilayahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(WilayahService $wilayah): View
    {
        return view('profile.show', [
            'data' => Auth::user(),
            'regions' => $wilayah->catalog(),
        ]);
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
    public function show(string $id, WilayahService $wilayah)
    {
        if ((int) $id !== (int) Auth::id()) {
            abort(403);
        }

        $data = Auth::user();

        return view('profile.show', [
            'data' => $data,
            'regions' => $wilayah->catalog(),
        ]);
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
    public function update(Request $request, string $id, WilayahService $wilayah)
    {
        abort_unless((int) $id === (int) Auth::id(), 403);

        $provinces = $wilayah->provinces();
        if ($provinces === []) {
            throw ValidationException::withMessages([
                'asal_prov' => 'Daftar wilayah belum tersedia. Admin perlu menjalankan perintah php artisan wilayah:sync.',
            ]);
        }
        $province = collect($provinces)->firstWhere('name', $request->input('asal_prov'));
        $regencies = $province ? $wilayah->regencies($province['code']) : [];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pekerjaan' => ['required', Rule::in(['Pelajar/Mahasiswa', 'Peneliti/Dosen', 'Pegawai Swasta', 'Pegawai BUMN/BUMD', 'Wiraswasta', 'ASN/TNI/Polri', 'Lainnya'])],
            'instansi' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'jenis_kelamin' => ['required', Rule::in(['1', '2'])],
            'tanggal_lahir' => ['required', 'date', 'before:today'],
            'asal_prov' => ['required', 'string', 'max:100', Rule::in(array_column($provinces, 'name'))],
            'asal_kab' => ['required', 'string', 'max:100', Rule::in(array_column($regencies, 'name'))],
            'no_hp' => ['required', 'string', 'max:30'],
            'pendidikan' => ['required', 'string', 'max:100'],
        ]);

        $user = Auth::user();
        $user->fill($validated)->save();

        if (Auth::user()->hasRole('admin|super_admin')) {
            return redirect()->route('dashboard')->with('message', 'Profil berhasil diperbarui.');
        }

        return redirect()->route('konsultasi.index')->with('message', 'Profil berhasil dilengkapi. Selamat menggunakan layanan PST Digital.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
