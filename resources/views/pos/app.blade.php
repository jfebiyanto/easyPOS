<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ $csrf }}">
    <title>POS · easyPOS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Mono:wght@400;600&family=Material+Symbols+Rounded:opsz,wght,FILL,GRAD@20..48,400,0..1,0" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/md3.css') }}">
    <link rel="stylesheet" href="{{ asset('css/md3-components.css') }}">
    <link rel="stylesheet" href="{{ asset('css/tokens.css') }}">
    <link rel="stylesheet" href="{{ asset('css/pos.css') }}">
    @vite('resources/js/pos/main.jsx')
</head>
<body>
<div id="pos-root"></div>
</body>
</html>
