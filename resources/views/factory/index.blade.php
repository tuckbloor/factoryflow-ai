<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>FactoryFlow AI | Digital Factory</title>
    @vite('resources/js/factory.js')
</head>
<body>
    <div id="factory-app"></div>
</body>
</html>
