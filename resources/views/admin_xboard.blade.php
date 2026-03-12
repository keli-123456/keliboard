<!DOCTYPE html>
<html lang="en">

<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{{ $title }}</title>
  @php
    $adminAssetBase = public_path('assets/admin-xboard/assets');
    $adminIndexJsVersion = is_file($adminAssetBase . '/index.js') ? filemtime($adminAssetBase . '/index.js') : time();
    $adminIndexCssVersion = is_file($adminAssetBase . '/index.css') ? filemtime($adminAssetBase . '/index.css') : time();
  @endphp
  <script>
    window.settings = {
      base_url: "/",
      title: "{{ $title }}",
      version: "{{ $version }}",
      logo: "{{ $logo }}",
      secure_path: "{{ $secure_path }}",
    };
  </script>
  <script type="module" crossorigin src="/assets/admin-xboard/assets/index.js?v={{ $adminIndexJsVersion }}"></script>
  <link rel="stylesheet" crossorigin href="/assets/admin-xboard/assets/index.css?v={{ $adminIndexCssVersion }}" />
</head>

<body>
  <div id="root"></div>
</body>

</html>
