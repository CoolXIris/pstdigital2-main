@extends('layout.admin')@section('content')
<div class="side-app">
    <div class="main-container container-fluid">
        <div class="page-header">
            <div>
                <h1 class="page-title">Users</h1>
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="javascript:void(0);">Home</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Users</li>
                </ol>
            </div>
            <div class="ms-auto pageheader-btn">
                <a href="javascript:void(0);" class="btn btn-primary btn-icon text-white me-2" data-bs-target="#tambah_modal" data-bs-toggle="modal">
                    <span> <i class="fe fe-plus"></i> </span> Add Users </a>

                <a href="{{ url('users_export') }}" target="_blank" class="btn btn-success btn-icon text-white">
                    <span>
                        <i class="fe fe-log-in"></i>
                    </span> Export
                </a>
            </div>
        </div> @include('layout.alert') <div class="row">
            <div class="col-12 col-sm-12">
                <div class="card ">
                    <div class="card-header">
                        <h3 class="card-title mb-0">Users</h3>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-bordered text-nowrap mb-0">
                                <thead class="border-top">
                                    <tr class="text-center">
                                        <th class="bg-transparent border-bottom-0 w-5">No</th>
                                        <th class="bg-transparent border-bottom-0">Name</th>
                                        <th class="bg-transparent border-bottom-0">Role</th>
                                        <th class="bg-transparent border-bottom-0">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($data as $i => $dt)
                                        <tr class="border-bottom">
                                            <td class="text-muted fs-15 fw-semibold text-center"> {{ $i + 1 }} </td>
                                            <td class="text-muted fs-15 fw-semibold"> {{ $dt->name }} </td>
                                            <td> <span class="text-xs font-weight-bold">
                                                    @foreach ($dt->getRoleNames() as $role)
                                                        <ul class="m-0">
                                                            <li>{{ $role }}</li>
                                                        </ul>
                                                    @endforeach
                                                </span> </td>
                                            <td class="text-muted fs-15 fw-semibold text-center"> <a class="text-warning btn_edit"
                                                    data-id="{{ $dt->id }}" data-name="{{ $dt->name }}" data-email="{{ $dt->email }}"
                                                    data-jabatan="{{ $dt->jabatan }}" data-instansi = "{{ $dt->instansi }}"
                                                    data-role = "{{ $role }}"> <i class="fa fa-pencil"></i> </a> <a
                                                    class="text-danger btn_hapus" data-id="{{ $dt->id }}" data-name="{{ $dt->name }}"> <i
                                                        class="fa fa-trash"></i> </a> </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table> <br /> {{ $data->links() }}
                        </div>
                    </div>
                </div>
            </div> <!-- COL END -->
        </div>
    </div>
</div>
<div class="modal fade" id="tambah_modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-content-demo">
            <div class="modal-header">
                <h6 class="modal-title">New User</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                        aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <form action="users" method="POST" id="form_tambah"> @csrf <div class="row">
                        <div class="col-12 form-group mb-2"> <label for="name_modal_tambah" class="col-form-label">Name:</label> <input type="text"
                                class="form-control" id="name_modal_tambah" name="name"> </div>
                        <div class="col-12 form-group mb-2"> <label for="email_modal_tambah" class="col-form-label">Email:</label> <input
                                type="email" class="form-control" id="email_modal_tambah" name="email"> </div>
                        <div class="col-12 form-group mb-2"> <label for="instansi_modal_tambah" class="col-form-label">Instansi:</label> <input
                                type="text" class="form-control" id="instansi_modal_tambah" name="instansi"> </div>
                        <div class="col-12 form-group mb-2"> <label for="jabatan_modal_tambah" class="col-form-label">Jabatan:</label> <input
                                type="text" class="form-control" id="jabatan_modal_tambah" name="jabatan"> </div>
                        <div class="col-12 form-group mb-2"> <label for="role_modal_tambah" class="col-form-label">Role:</label> <select
                                class="form-control select2" data-bs-placeholder="Choose One" name="role" id="role_modal_tambah" required>
                                <option label="Choose one">Choose one</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}">{{ $role->name }}</option>
                                @endforeach
                            </select> </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"> <button class="btn btn-primary" type="submit" form="form_tambah">Save changes</button> <button
                    class="btn btn-light" data-bs-dismiss="modal">Close</button> </div>
        </div>
    </div>
</div>
<div class="modal fade" id="edit_modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-content-demo">
            <div class="modal-header">
                <h6 class="modal-title">Edit User</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                        aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="form_edit"> @csrf @method('PUT') <div class="row">
                        <div class="col-12 form-group mb-2"> <label for="name_modal_edit" class="col-form-label">Name:</label> <input
                                type="text" class="form-control" id="name_modal_edit" name="name"> </div>
                        <div class="col-12 form-group mb-2"> <label for="email_modal_edit" class="col-form-label">Email:</label> <input
                                type="email" class="form-control" id="email_modal_edit" name="email" readonly> </div>
                        <div class="col-12 form-group mb-2"> <label for="instansi_modal_edit" class="col-form-label">Instansi:</label> <input
                                type="text" class="form-control" id="instansi_modal_edit" name="instansi"> </div>
                        <div class="col-12 form-group mb-2"> <label for="jabatan_modal_edit" class="col-form-label">Jabatan:</label> <input
                                type="text" class="form-control" id="jabatan_modal_edit" name="jabatan"> </div>
                        <div class="col-12 form-group mb-2"> <label for="role_modal_edit" class="col-form-label">Role:</label> <select
                                class="form-control select2" data-bs-placeholder="Choose One" name="role" id="role_modal_edit" required>
                                <option label="Choose one">Choose one</option>
                                @foreach ($roles as $role)
                                    <option value="{{ $role->name }}" @if ($dt->hasrole($role->name)) selected @endif>{{ $role->name }}
                                    </option>
                                @endforeach
                            </select> </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer"> <button class="btn btn-primary" type="submit" form="form_edit">Save changes</button> <button
                    class="btn btn-light" data-bs-dismiss="modal">Close</button> </div>
        </div>
    </div>
</div>
<div class="modal fade" id="hapus_modal">
    <div class="modal-dialog" role="document">
        <div class="modal-content modal-content-demo">
            <div class="modal-header">
                <h6 class="modal-title">Delete User</h6><button aria-label="Close" class="btn-close" data-bs-dismiss="modal"><span
                        aria-hidden="true">&times;</span></button>
            </div>
            <div class="modal-body">
                <form method="POST" id="form_hapus"> @csrf @method('DELETE') <div class="mb-3"> <label for="name_modal_hapus"
                            class="col-form-label">Name:</label> <input type="text" class="form-control" id="name_modal_hapus" name="name"
                            readonly> </div>
                </form>
            </div>
            <div class="modal-footer"> <button class="btn btn-primary" type="submit" form="form_hapus">Save changes</button> <button
                    class="btn btn-light" data-bs-dismiss="modal">Close</button> </div>
        </div>
    </div>
</div>
@endsection
@section('script')
<script>
    $(document).ready(function() {
        $('.btn_edit').click(function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#name_modal_edit').val(name);
            $('#email_modal_edit').val($(this).data('email'));
            $('#jabatan_modal_edit').val($(this).data('jabatan'));
            $('#instansi_modal_edit').val($(this).data('instansi'));
            $('#role_modal_edit').val($(this).data('role')).trigger('change');
            $('#form_edit').attr('action', 'users/' + id);
            $('#edit_modal').modal('show');
        });
        $('.btn_hapus').click(function() {
            let id = $(this).data('id');
            let name = $(this).data('name');
            $('#name_modal_hapus').val(name);
            $('#form_hapus').attr('action', 'users/' + id);
            $('#hapus_modal').modal('show');
        });
        $('#role_modal_tambah').select2({
            width: '100%',
            dropdownParent: $('#tambah_modal')
        });
        $('#role_modal_edit').select2({
            width: '100%',
            dropdownParent: $('#edit_modal')
        });
    });
</script>
@endsection
