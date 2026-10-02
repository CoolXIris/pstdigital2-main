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
}
