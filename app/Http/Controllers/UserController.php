<?php

namespace App\Http\Controllers;

use App\Exports\UsersExport;
use App\Models\ConsultationStaff;
use App\Models\Meeting;
use App\Models\User;
use App\Services\WilayahService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request, WilayahService $wilayah)
    {
        $search = trim((string) $request->query('q', ''));
        $roleFilter = (string) $request->query('role', '');
        $data = User::with('roles')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('instansi', 'like', "%{$search}%")
                        ->orWhere('jabatan', 'like', "%{$search}%");
                });
            })
            ->when($roleFilter !== '', fn ($query) => $query->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', $roleFilter)))
            ->orderBy('name')
            ->paginate(12)
            ->withQueryString();
        $roles = Role::all();
        $stats = [
            'users' => User::whereHas('roles', fn ($query) => $query->where('name', 'user'))
                ->whereDoesntHave('roles', fn ($query) => $query->whereIn('name', ['admin', 'super_admin']))
                ->count(),
            'admins' => User::role(['admin', 'super_admin'])->count(),
            'total' => User::count(),
        ];
        $provinces = $wilayah->provinces();
        $consultationStaff = ConsultationStaff::query()->orderBy('sort_order')->orderBy('id')->get();

        return view('admin.users.index', compact('data', 'roles', 'search', 'roleFilter', 'stats', 'provinces', 'consultationStaff'));
    }

    public function updateConsultationStaff(Request $request)
    {
        $staffInput = $request->input('staff', []);
        $newStaffInput = $request->input('new_staff', []);

        if (is_array($staffInput)) {
            $staffInput = collect($staffInput)->map(fn ($name) => is_string($name) ? trim($name) : $name)->all();
        }
        if (is_array($newStaffInput)) {
            $newStaffInput = collect($newStaffInput)
                ->map(fn ($name) => is_string($name) ? trim($name) : $name)
                ->filter(fn ($name) => $name !== '')
                ->values()
                ->all();
        }
        $request->merge(['staff' => $staffInput, 'new_staff' => $newStaffInput]);

        $validator = Validator::make($request->all(), [
            'staff' => ['nullable', 'array', 'max:100'],
            'staff.*' => ['required', 'string', 'max:100'],
            'new_staff' => ['nullable', 'array', 'max:100'],
            'new_staff.*' => ['required', 'string', 'max:100'],
        ], [
            'staff.*.required' => 'Nama petugas tidak boleh kosong. Hapus baris jika petugas tidak digunakan lagi.',
            'staff.*.max' => 'Nama petugas maksimal 100 karakter.',
            'new_staff.*.required' => 'Nama petugas baru tidak boleh kosong.',
            'new_staff.*.max' => 'Nama petugas maksimal 100 karakter.',
        ]);

        if ($validator->fails()) {
            return back()->withErrors($validator, 'consultationStaff')->withInput();
        }

        $submittedStaff = collect($staffInput);

        $allNames = $submittedStaff->values()->merge($newStaffInput);
        if ($allNames->isEmpty()) {
            return back()->withErrors(['staff' => 'Minimal harus ada satu petugas konsultasi.'], 'consultationStaff')->withInput();
        }

        $normalisedNames = $allNames->map(fn ($name) => mb_strtolower($name));
        if ($normalisedNames->unique()->count() !== $normalisedNames->count()) {
            return back()->withErrors(['staff' => 'Nama petugas tidak boleh duplikat.'], 'consultationStaff')->withInput();
        }

        $error = DB::transaction(function () use ($submittedStaff, $newStaffInput) {
            $currentStaff = ConsultationStaff::query()->lockForUpdate()->get()->keyBy('id');
            if ($submittedStaff->keys()->diff($currentStaff->keys())->isNotEmpty()) {
                return 'Daftar petugas berubah. Muat ulang halaman lalu coba lagi.';
            }

            $submittedIds = $submittedStaff->keys()->map(fn ($id) => (string) $id);

            foreach ($currentStaff as $staffMember) {
                if ($submittedIds->contains((string) $staffMember->id)) {
                    continue;
                }
                if (Meeting::query()->where('assigned_staff_id', $staffMember->id)->where('status', 2)->exists()) {
                    return "{$staffMember->name} masih bertugas pada konsultasi terkonfirmasi. Ubah namanya atau selesaikan konsultasi sebelum menghapusnya.";
                }
            }

            foreach ($currentStaff as $staffMember) {
                if (! $submittedIds->contains((string) $staffMember->id)) {
                    $staffMember->delete();
                } elseif ($staffMember->name !== $submittedStaff->get($staffMember->id)) {
                    $staffMember->update(['name' => '__pending__'.$staffMember->id.'_'.Str::random(16)]);
                }
            }

            $sortOrder = 0;
            foreach ($submittedStaff as $id => $name) {
                $staffMember = $currentStaff->get($id);
                if (! $staffMember) {
                    continue;
                }

                $staffMember->update([
                    'name' => $name,
                    'sort_order' => $sortOrder++,
                ]);

                if ($staffMember->wasChanged('name')) {
                    Meeting::query()->where('assigned_staff_id', $staffMember->id)->update(['assigned_staff' => $name]);
                }
            }

            foreach ($newStaffInput as $name) {
                ConsultationStaff::query()->create(['name' => $name, 'sort_order' => $sortOrder++]);
            }

            return null;
        });

        if ($error !== null) {
            return back()->withErrors(['staff' => $error], 'consultationStaff')->withInput();
        }

        return redirect()->route('users.index')->with('message', 'Daftar petugas konsultasi berhasil diperbarui.');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'instansi' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'role' => ['required', Rule::exists('roles', 'name')],
        ]);
        $this->authorizeRoleAssignment($validated['role']);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'instansi' => $validated['instansi'] ?? null,
            'jabatan' => $validated['jabatan'] ?? null,
            'password' => Hash::make(Str::random(48)),
        ]);
        $user->syncRoles($validated['role']);

        return redirect()->route('users.index')->with('message', 'Pengguna berhasil ditambahkan.');
    }

    public function update(Request $request, $id)
    {
        $user = User::findOrFail($id);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'instansi' => ['nullable', 'string', 'max:255'],
            'jabatan' => ['nullable', 'string', 'max:255'],
            'pekerjaan' => ['nullable', 'string', 'max:100'],
            'jenis_kelamin' => ['nullable', Rule::in(['1', '2'])],
            'tanggal_lahir' => ['nullable', 'date', 'before:today'],
            'asal_prov' => ['nullable', 'string', 'max:100'],
            'asal_kab' => ['nullable', 'string', 'max:100'],
            'no_hp' => ['nullable', 'string', 'max:30'],
            'pendidikan' => ['nullable', 'string', 'max:100'],
            'role' => ['required', Rule::exists('roles', 'name')],
        ]);
        $this->authorizeRoleAssignment($validated['role']);

        if ($user->hasRole('super_admin') && $validated['role'] !== 'super_admin' && User::role('super_admin')->count() <= 1) {
            return back()->with('error', 'Superadmin terakhir tidak dapat diturunkan rolenya.');
        }

        $user->update([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'instansi' => $validated['instansi'] ?? null,
            'jabatan' => $validated['jabatan'] ?? null,
            'pekerjaan' => $validated['pekerjaan'] ?? null,
            'jenis_kelamin' => $validated['jenis_kelamin'] ?? null,
            'tanggal_lahir' => $validated['tanggal_lahir'] ?? null,
            'asal_prov' => $validated['asal_prov'] ?? null,
            'asal_kab' => $validated['asal_kab'] ?? null,
            'no_hp' => $validated['no_hp'] ?? null,
            'pendidikan' => $validated['pendidikan'] ?? null,
        ]);
        $user->syncRoles($validated['role']);

        return redirect()->route('users.index')->with('message', 'Data pengguna berhasil diperbarui.');
    }

    public function destroy($id)
    {
        $user = User::findOrFail($id);
        if ((int) $user->id === (int) Auth::id()) {
            return back()->with('error', 'Akun yang sedang digunakan tidak dapat dihapus.');
        }

        if ($user->hasRole('super_admin') && User::role('super_admin')->count() <= 1) {
            return back()->with('error', 'Superadmin terakhir tidak dapat dihapus.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('message', 'Pengguna berhasil dihapus.');
    }

    public function export()
    {
        return Excel::download(new UsersExport, 'users.xlsx');
    }

    private function authorizeRoleAssignment(string $role): void
    {
        abort_unless(Auth::user()->hasRole('super_admin') || $role === 'user', 403);
    }
}
