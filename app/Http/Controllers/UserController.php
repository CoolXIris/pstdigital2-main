<?php

namespace App\Http\Controllers;

use App\Exports\UsersExport;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $search = trim((string) $request->query('q', ''));
        $roleFilter = (string) $request->query('role', '');
        $data = User::with('roles')
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($userQuery) use ($search) {
                    $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('instansi', 'like', "%{$search}%");
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
        return view('admin.users.index', compact('data', 'roles', 'search', 'roleFilter', 'stats'));
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
