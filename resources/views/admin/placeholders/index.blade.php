@extends('layouts.admin')

@section('title', $pageTitle)
@section('page_title', $pageTitle)

@section('content')
<div class="card">
    <div class="empty-state">
        <span class="material-symbols-rounded" style="font-size: 56px;">{{ $icon }}</span>
        <h2 style="margin: 12px 0 6px; font-size: 18px;">{{ $pageTitle }}</h2>
        <p style="max-width: 520px; margin: 0 auto 16px;">{{ $description }}</p>
        <span class="badge info">Fitur dalam pengembangan</span>
    </div>
</div>
@endsection