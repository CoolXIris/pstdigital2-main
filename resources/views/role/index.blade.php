@extends('layout.admin')

@section('content')
    <div class="side-app">
        <div class="main-container container-fluid">

            <div class="page-header">
                <div>
                    <h1 class="page-title">Role</h1>
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="javascript:void(0);">Home</a></li>
                        <li class="breadcrumb-item active" aria-current="page">Roles</li>
                    </ol>
                </div>
                <div class="ms-auto pageheader-btn">
                    <a href="javascript:void(0);" class="btn btn-primary btn-icon text-white me-2"
                        data-bs-target="#tambah_modal" data-bs-toggle="modal">
                        <span>
                            <i class="fe fe-plus"></i>
                        </span> Add roles
                    </a>

                </div>
            </div>
            @include('layout.alert')

            <div class="row">
                <div class="col-12 col-sm-12">
                    <div class="card ">
                        <div class="card-header">
                            <h3 class="card-title mb-0">Roles</h3>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="data-table" class="table table-bordered text-nowrap mb-0">
                                    <thead class="border-top">
                                        <tr class="text-center">
                                            <th class="bg-transparent border-bottom-0 w-5">No</th>
                                            <th class="bg-transparent border-bottom-0">Name</th>
                                            <th class="bg-transparent border-bottom-0">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        @foreach ($data as $i => $dt)
                                            <tr class="border-bottom">
                                                <td class="text-muted fs-15 fw-semibold text-center">
                                                    {{ $i + 1 }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold">
                                                    {{ $dt->name }}
                                                </td>
                                                <td class="text-muted fs-15 fw-semibold text-center">
                                                    <a class="text-warning btn_edit" data-id="{{ $dt->id }}"
                                                        data-name="{{ $dt->name }}">
                                                        <i class="fa fa-pencil"></i>
                                                    </a>
                                                    <a class="text-danger btn_hapus" data-id="{{ $dt->id }}"
                                                        data-name="{{ $dt->name }}">
                                                        <i class="fa fa-trash"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                <!-- COL END -->
            </div>
        </div>
    </div>

    <div class="modal fade" id="tambah_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">New Role</h6><button aria-label="Close" class="btn-close"
                        data-bs-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form action="roles" method="POST" id="form_tambah">
                        @csrf
                        <div class="mb-3">
                            <label for="name_modal_tambah" class="col-form-label">Role Name:</label>
                            <input type="text" class="form-control" id="name_modal_tambah" name="name">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" form="form_tambah">Save changes</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="edit_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Edit Role</h6><button aria-label="Close" class="btn-close"
                        data-bs-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="form_edit">
                        @csrf
                        @method('PUT')
                        <div class="mb-3">
                            <label for="name_modal_edit" class="col-form-label">Role Name:</label>
                            <input type="text" class="form-control" id="name_modal_edit" name="name">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" form="form_edit">Save changes</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="hapus_modal">
        <div class="modal-dialog" role="document">
            <div class="modal-content modal-content-demo">
                <div class="modal-header">
                    <h6 class="modal-title">Delete Role</h6><button aria-label="Close" class="btn-close"
                        data-bs-dismiss="modal"><span aria-hidden="true">&times;</span></button>
                </div>
                <div class="modal-body">
                    <form method="POST" id="form_hapus">
                        @csrf
                        @method('DELETE')
                        <div class="mb-3">
                            <label for="name_modal_hapus" class="col-form-label">Role Name:</label>
                            <input type="text" class="form-control" id="name_modal_hapus" name="name" readonly>
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-primary" type="submit" form="form_hapus">Save changes</button>
                    <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
                </div>
            </div>
        </div>
    </div>
@endsection

@section('script')
    <script>
        $(document).ready(function() {
            $('.btn_edit').click(function() {
                let id = $(this).data('id'); // Ambil data-id dari tombol
                let name = $(this).data('name'); // Ambil data-name dari tombol
                $('#name_modal_edit').val(name); // Set nilai input dengan data-name
                $('#form_edit').attr('action', 'roles/' + id); // Ubah action form
                $('#edit_modal').modal('show'); // Tampilkan modal
            });
            $('.btn_hapus').click(function() {
                console.log('a')
                let id = $(this).data('id'); // Ambil data-id dari tombol
                let name = $(this).data('name'); // Ambil data-name dari tombol
                $('#name_modal_hapus').val(name); // Set nilai input dengan data-name
                $('#form_hapus').attr('action', 'roles/' + id); // Ubah action form
                $('#hapus_modal').modal('show'); // Tampilkan modal
            });
        });
    </script>
@endsection
