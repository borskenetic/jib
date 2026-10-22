@extends('layouts.zen')

@section('page_title', 'Import Preview')
@section('page_subtitle', 'Review users before confirming import')

@section('content')
<div class="card-surface" style="padding: 0; overflow: hidden;">
    <div style="padding: 20px 20px 0;">
        <p style="margin: 0 0 8px;">{{ $readyCount }} {{ $readyCount === 1 ? 'account is' : 'accounts are' }} ready to import. {{ $skippedCount }} {{ $skippedCount === 1 ? 'row will be skipped' : 'rows will be skipped' }}.</p>
        @if($skippedCount > 0)
            <ul style="margin: 0; padding-left: 18px; color: var(--danger); font-size: 0.9rem;">
                @foreach($data as $row)
                    @if($row['errors'])
                        <li>Row {{ $row['line'] }} ({{ $row['fname'] }} {{ $row['lname'] }}, {{ $row['email'] }}): {{ implode(', ', $row['errors']) }}</li>
                    @endif
                @endforeach
            </ul>
        @endif
    </div>
    <form action="{{ route('users.import.confirm') }}" method="POST">
        @csrf
        <div class="table-wrap" style="border: none;">
            <table class="table-app">
                <thead>
                    <tr>
                        <th>Row</th>
                        <th>Last Name</th>
                        <th>First Name</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Campus</th>
                        <th>Department</th>
                        <th>Course</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($data as $row)
                        <tr>
                            <td>{{ $row['line'] }}</td>
                            <td>{{ $row['lname'] }}</td>
                            <td>{{ $row['fname'] }}</td>
                            <td>{{ $row['email'] }}</td>
                            <td>{{ $row['role'] }}</td>
                            <td>{{ $row['campus'] }}</td>
                            <td>{{ $row['department'] ?: '—' }}</td>
                            <td>{{ $row['course'] ?: '—' }}</td>
                            <td>
                                @if($row['errors'])
                                    <span style="color: var(--danger); font-size: 0.82rem;">{{ implode(', ', $row['errors']) }}</span>
                                @else
                                    <span class="badge-app" style="background:#dcfce7;color:#166534;">Ready</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div style="padding: 20px; display: flex; gap: 12px;">
            <button type="submit" class="btn-app btn-success-app" @if($readyCount === 0) disabled @endif>Confirm import</button>
            <a href="{{ route('users.index') }}" class="btn-app btn-outline-app">Cancel</a>
        </div>
    </form>
</div>
@endsection
