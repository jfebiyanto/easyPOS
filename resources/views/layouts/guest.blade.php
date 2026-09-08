<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'easyPOS')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0..1,0" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/md3.css') }}">
    <link rel="stylesheet" href="{{ asset('css/md3-components.css') }}">
</head>
<body>
@yield('content')
</body>
</html>