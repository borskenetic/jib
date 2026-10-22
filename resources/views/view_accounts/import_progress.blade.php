@extends('layouts.zen')

@section('page_title', 'Importing users')
@section('page_subtitle', 'Large files are saved in batches')

@section('content')
<div class="card-surface" style="padding: 24px;">
    <form id="import-batch" action="{{ route('users.import.batch') }}" method="POST">
        @csrf
        <input type="hidden" name="import_id" value="{{ $importId }}">
        @if($waiting)
            <p style="margin: 0 0 8px;">The previous batch is still finishing. This page will continue automatically.</p>
        @else
            <p style="margin: 0 0 8px;">{{ number_format($processed) }} of {{ number_format($total) }} rows processed. {{ number_format($inserted) }} {{ $inserted === 1 ? 'account has' : 'accounts have' }} been created.</p>
            <p style="margin: 0 0 16px;">Keep this page open. Each password is hashed before it is saved, so a sheet of this size takes several minutes. It continues on its own and will not hit the server time limit.</p>
        @endif
        <button type="submit" class="btn-app btn-primary-app">Continue import</button>
    </form>
</div>
<script>
    setTimeout(function () {
        var form = document.getElementById('import-batch');
        if (!form) return;
        var button = form.querySelector('button');
        if (button) button.disabled = true;
        form.submit();
    }, 400);
</script>
@endsection
