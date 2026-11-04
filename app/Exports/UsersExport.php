<?php

namespace App\Exports;

use App\Models\User;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class UsersExport implements FromCollection, WithHeadings
{
    /**
     * @return \Illuminate\Support\Collection
     */
    public function collection()
    {
        $users = User::with('roles')->get();

        return $users->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'instansi' => $this->spreadsheetText($user->instansi),
                'jabatan' => $this->spreadsheetText($user->jabatan),
                'pekerjaan' => $user->pekerjaan,
                'tanggal_lahir' => $user->tanggal_lahir,
                'jenis_kelamin' => $user->jenis_kelamin,
                'asal_prov' => $user->asal_prov,
                'asal_kab' => $user->asal_kab,
                'no_hp' => $user->no_hp,
                'pendidikan' => $user->pendidikan,
                'roles' => $user->roles->pluck('name')->implode(', ')
            ];
        });
    }

    public function headings(): array
    {
        return [
            '#',
            'name',
            'email',
            'instansi',
            'jabatan',
            'pekerjaan',
            'tanggal_lahir',
            'jenis_kelamin',
            'asal_prov',
            'asal_kab',
            'no_hp',
            'pendidikan',
            'roles'
        ];
    }

    private function spreadsheetText(?string $value): string
    {
        $value ??= '';

        return preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'".$value : $value;
    }
}
