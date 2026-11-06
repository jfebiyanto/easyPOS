@if (session('success') || session('error'))
    <div class="md-snackbar {{ session('error') ? 'error' : 'success' }}">
        <span class="material-symbols-rounded">{{ session('error') ? 'error' : 'check_circle' }}</span>
        {{ session('error') ?? session('success') }}
    </div>
@endif
@if ($errors->any())
    <div class="md-snackbar error">
        <span class="material-symbols-rounded">error</span> Please review the data in the form.
    </div>
@endif
@yield('page_content')