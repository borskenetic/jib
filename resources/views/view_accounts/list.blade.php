@extends('layouts.zen')

@section('page_title', 'Users')
@section('page_subtitle', 'Manage accounts and import users')

@section('content')
<div class="card-surface" style="margin-bottom: 20px;">
    <div class="page-toolbar">
        <div class="page-toolbar-group">
            <a href="{{ route('users.create') }}" class="btn-app btn-primary-app btn-sm-app">+ Create user</a>
            <a href="{{ route('users.export', request()->only(['search', 'role', 'course'])) }}" class="btn-app btn-outline-app btn-sm-app">Export users</a>
            <a href="{{ route('users.import.template') }}" class="btn-app btn-outline-app btn-sm-app">Download CSV template</a>
            <form action="{{ route('users.import.preview') }}" method="POST" enctype="multipart/form-data" class="inline-import-form">
                @csrf
                <input type="file" name="file" class="form-control-app" accept=".csv" required>
                <button type="submit" class="btn-app btn-outline-app btn-sm-app">Import CSV</button>
            </form>
        </div>
        <a href="{{ route('pending.index') }}" class="btn-app btn-warning-app btn-sm-app">Pending approvals</a>
    </div>

    @if(session('import_errors'))
        <div class="alert-app alert-danger-app">
            <strong>Import errors:</strong>
            <ul style="margin: 8px 0 0; padding-left: 18px;">
                @foreach(session('import_errors') as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="GET" action="{{ route('users.index') }}" class="filter-bar">
        <input type="text" name="search" class="form-control-app" placeholder="Search name, email, campus..." value="{{ request('search') }}" style="flex: 1;">
        <select name="role" class="form-control-app">
            <option value="">All roles</option>
            @foreach($roles as $value => $label)
                <option value="{{ $value }}" {{ request('role') === $value ? 'selected' : '' }}>{{ $label }}</option>
            @endforeach
        </select>
        <select name="course" class="form-control-app">
            <option value="">All courses</option>
            @foreach($courses as $course)
                <option value="{{ $course }}" {{ request('course') == $course ? 'selected' : '' }}>{{ $course }}</option>
            @endforeach
        </select>
        <select name="per_page" class="form-control-app" onchange="this.form.submit()">
            @foreach([10, 25, 50, 100] as $size)
                <option value="{{ $size }}" {{ (int) request('per_page', 10) === $size ? 'selected' : '' }}>{{ $size }} per page</option>
            @endforeach
        </select>
        <button type="submit" class="btn-app btn-primary-app">Search</button>
        <a href="{{ route('users.index') }}" class="btn-app btn-outline-app">Reset</a>
    </form>
</div>

<div class="card-surface" style="padding: 0; overflow: hidden;">
    <div class="table-wrap" style="border: none;">
        <table class="table-app">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Role</th>
                    <th>Campus</th>
                    <th>Course</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($users as $user)
                    <tr>
                        <td>{{ $user->fname }} {{ $user->lname }}</td>
                        <td>{{ $user->email }}</td>
                        <td><span class="badge-app">{{ $roles[$user->role] ?? ucfirst($user->role) }}</span></td>
                        <td>{{ $user->campus ?? '—' }}</td>
                        <td>{{ $user->course ?? '—' }}</td>
                        <td style="white-space: nowrap;">
                            <a href="{{ route('users.edit', $user->id) }}" class="btn-app btn-outline-app btn-sm-app">Edit</a>
                            @if($user->id !== auth()->id())
                            <form action="{{ route('users.destroy', $user->id) }}" method="POST" style="display: inline;" onsubmit="return confirm('Delete this user?');">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-app btn-danger-app btn-sm-app">Delete</button>
                            </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 32px;">No users found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div style="padding: 16px 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
        <small style="color: var(--text-muted);">Showing {{ $users->firstItem() ?? 0 }}–{{ $users->lastItem() ?? 0 }} of {{ $users->total() }}</small>
        {{ $users->links() }}
    </div>
</div>
@endsection
